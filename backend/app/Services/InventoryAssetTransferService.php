<?php

namespace App\Services;

use App\Models\InventoryAsset;
use App\Models\InventoryAssetHistory;
use App\Models\InventoryAssetLocation;
use App\Models\Accommodation;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryAssetTransferService
{
    /**
     * @return array<int, array{key: string, label: string, location_type: string, accommodation_id: int|null, location_name: string|null}>
     */
    public function allowedDestinationOptions(InventoryAsset $asset): array
    {
        $options = [
            [
                'key' => 'storage:main-storage',
                'label' => 'Main Storage',
                'location_type' => InventoryAsset::LOCATION_STORAGE,
                'accommodation_id' => null,
                'location_name' => 'Main Storage',
            ],
            [
                'key' => 'storage:equipment-storage',
                'label' => 'Equipment Storage',
                'location_type' => InventoryAsset::LOCATION_STORAGE,
                'accommodation_id' => null,
                'location_name' => 'Equipment Storage',
            ],
            [
                'key' => 'maintenance:area',
                'label' => 'Maintenance Area',
                'location_type' => InventoryAsset::LOCATION_OTHER,
                'accommodation_id' => null,
                'location_name' => 'Maintenance Area',
            ],
            [
                'key' => 'office:admin-office',
                'label' => 'Admin Office',
                'location_type' => InventoryAsset::LOCATION_OTHER,
                'accommodation_id' => null,
                'location_name' => 'Admin Office',
            ],
            [
                'key' => 'pool:pool-area',
                'label' => 'Pool Area',
                'location_type' => InventoryAsset::LOCATION_POOL_AREA,
                'accommodation_id' => null,
                'location_name' => 'Pool Area',
            ],
        ];

        foreach (Accommodation::query()->orderBy('name')->get() as $accommodation) {
            $options[] = [
                'key' => 'accommodation:'.$accommodation->id,
                'label' => $accommodation->name,
                'location_type' => InventoryAsset::LOCATION_ACCOMMODATION,
                'accommodation_id' => $accommodation->id,
                'location_name' => null,
            ];
        }

        return $options;
    }

    public function seedInitialLocation(InventoryAsset $asset): void
    {
        $location = $this->upsertLocation($asset, [
            'location_type' => $asset->location_type,
            'accommodation_id' => $asset->accommodation_id,
            'location_name' => $asset->location_name,
        ], $asset->quantity, true);

        $this->syncAssetPrimaryLocation($asset->fresh(['locations', 'accommodation']));

        if ($location->quantity !== $asset->quantity) {
            $location->forceFill(['quantity' => $asset->quantity])->save();
        }
    }

    /**
     * @return array{asset: InventoryAsset, source: InventoryAssetLocation, destination: InventoryAssetLocation}
     */
    public function transfer(InventoryAsset $asset, array $attributes, ?Request $request = null): array
    {
        return DB::transaction(function () use ($asset, $attributes, $request): array {
            $asset = InventoryAsset::query()
                ->with(['locations.accommodation', 'accommodation'])
                ->whereKey($asset->id)
                ->lockForUpdate()
                ->firstOrFail();

            $source = InventoryAssetLocation::query()
                ->whereKey($attributes['source_location_id'])
                ->where('inventory_asset_id', $asset->id)
                ->lockForUpdate()
                ->firstOrFail();

            $transferQuantity = (int) $attributes['transfer_quantity'];

            if ($transferQuantity < 1) {
                throw ValidationException::withMessages([
                    'transfer_quantity' => ['Transfer quantity must be at least 1.'],
                ]);
            }

            if ($source->quantity < $transferQuantity) {
                throw ValidationException::withMessages([
                    'transfer_quantity' => ['Transfer quantity exceeds the quantity available at the selected source location.'],
                ]);
            }

            $destinationAttributes = $this->destinationAttributes($asset, $attributes);
            $destinationSignature = $this->signature($destinationAttributes);

            if ($source->location_signature === $destinationSignature) {
                throw ValidationException::withMessages([
                    'location_type' => ['Source and destination locations must be different.'],
                ]);
            }

            $destination = InventoryAssetLocation::query()
                ->where('inventory_asset_id', $asset->id)
                ->where('location_signature', $destinationSignature)
                ->lockForUpdate()
                ->first();

            if (! $destination) {
                $destination = $this->upsertLocation($asset, $destinationAttributes, 0, false);
            }

            $sourceBefore = $source->quantity;
            $destinationBefore = $destination->quantity;

            $source->forceFill(['quantity' => $sourceBefore - $transferQuantity])->save();
            $destination->forceFill(['quantity' => $destinationBefore + $transferQuantity])->save();

            if ($source->quantity <= 0) {
                $source->delete();
            }

            $asset->refresh();
            $this->syncAssetPrimaryLocation($asset);
            $asset->forceFill([
                'status' => $asset->locations()
                    ->where('location_type', '!=', InventoryAsset::LOCATION_STORAGE)
                    ->exists()
                    ? InventoryAsset::STATUS_ASSIGNED
                    : InventoryAsset::STATUS_AVAILABLE,
            ])->saveQuietly();
            $asset->load(['accommodation', 'locations.accommodation']);

            $historyPayload = [
                'source_location' => [
                    'id' => $source->id,
                    'label' => $source->locationLabel(),
                    'quantity_before' => $sourceBefore,
                    'quantity_after' => max($sourceBefore - $transferQuantity, 0),
                ],
                'destination_location' => [
                    'id' => $destination->id,
                    'label' => $destination->locationLabel(),
                    'quantity_before' => $destinationBefore,
                    'quantity_after' => $destinationBefore + $transferQuantity,
                ],
                'quantity_transferred' => $transferQuantity,
            ];

            $asset->histories()->create([
                'performed_by' => $request?->user()?->id,
                'action' => 'transferred',
                'old_value' => $historyPayload['source_location'],
                'new_value' => array_merge($historyPayload['destination_location'], ['quantity_transferred' => $transferQuantity]),
                'remarks' => $attributes['remarks'] ?? null,
            ]);

            app(AuditLogger::class)->log($request, 'inventory', 'transferred', 'Inventory asset quantity transferred.', $asset, [
                'asset_code' => $asset->asset_code,
                'before' => $historyPayload['source_location'],
                'after' => $historyPayload['destination_location'],
                'quantity_transferred' => $transferQuantity,
                'remarks' => $attributes['remarks'] ?? null,
            ]);

            return [
                'asset' => $asset,
                'source' => $source->quantity > 0 ? $source : $destination,
                'destination' => $destination,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function syncAssetPrimaryLocation(InventoryAsset $asset): void
    {
        $primary = $asset->locations()
            ->orderByDesc('quantity')
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->first();

        if (! $primary) {
            return;
        }

        $asset->forceFill([
            'location_type' => $primary->location_type,
            'accommodation_id' => $primary->accommodation_id,
            'location_name' => $primary->location_name,
        ])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function destinationAttributes(InventoryAsset $asset, array $attributes): array
    {
        $destinationKey = (string) ($attributes['destination_key'] ?? '');
        $selected = collect($this->allowedDestinationOptions($asset))->firstWhere('key', $destinationKey);

        if (! $selected) {
            throw ValidationException::withMessages([
                'destination_key' => ['Please select a valid destination.'],
            ]);
        }

        return [
            'location_type' => $selected['location_type'],
            'accommodation_id' => $selected['accommodation_id'],
            'location_name' => $selected['location_name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $locationAttributes
     */
    private function upsertLocation(InventoryAsset $asset, array $locationAttributes, int $quantity, bool $throwOnDuplicate = true): InventoryAssetLocation
    {
        $signature = $this->signature($locationAttributes);

        try {
            return InventoryAssetLocation::create([
                'inventory_asset_id' => $asset->id,
                'location_signature' => $signature,
                'location_type' => $locationAttributes['location_type'],
                'accommodation_id' => $locationAttributes['accommodation_id'],
                'location_name' => $locationAttributes['location_name'],
                'quantity' => $quantity,
            ]);
        } catch (QueryException $exception) {
            if (! $throwOnDuplicate || (string) $exception->getCode() !== '23505') {
                throw $exception;
            }

            $location = InventoryAssetLocation::query()
                ->where('inventory_asset_id', $asset->id)
                ->where('location_signature', $signature)
                ->first();

            if (! $location) {
                throw $exception;
            }

            return $location;
        }
    }

    /**
     * @param  array<string, mixed>  $locationAttributes
     */
    private function signature(array $locationAttributes): string
    {
        return implode('|', [
            $locationAttributes['location_type'],
            $locationAttributes['location_type'] === InventoryAsset::LOCATION_ACCOMMODATION ? (string) $locationAttributes['accommodation_id'] : '',
            $locationAttributes['location_type'] === InventoryAsset::LOCATION_ACCOMMODATION ? '' : mb_strtolower(trim((string) $locationAttributes['location_name'])),
        ]);
    }
}
