<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryAsset;
use App\Models\InventoryAssetHistory;
use App\Services\InventoryAssetTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class InventoryAssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'condition' => ['sometimes', 'nullable', Rule::in(InventoryAsset::conditions())],
            'status' => ['sometimes', 'nullable', Rule::in(InventoryAsset::statuses())],
            'location_type' => ['sometimes', 'nullable', Rule::in(InventoryAsset::locationTypes())],
        ]);

        $query = InventoryAsset::query()
            ->with(['accommodation', 'locations.accommodation'])
            ->latest('updated_at');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(function ($query) use ($search) {
                $query
                    ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(asset_code) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(category) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(location_name) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('locations', fn ($query) => $query
                        ->whereRaw('LOWER(location_name) LIKE ?', ["%{$search}%"])
                        ->orWhereHas('accommodation', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])));
            });
        }

        foreach (['category', 'condition', 'status', 'location_type'] as $filter) {
            if ($value = $filters[$filter] ?? null) {
                if ($filter === 'category') {
                    $query->whereRaw('LOWER(category) = LOWER(?)', [$value]);
                    continue;
                }

                if ($filter === 'location_type') {
                    $query->where(function ($query) use ($value) {
                        $query
                            ->where('location_type', $value)
                            ->orWhereHas('locations', fn ($query) => $query->where('location_type', $value));
                    });

                    continue;
                }

                $query->where($filter, $value);
            }
        }

        $assets = $query->get();

        return response()->json([
            'data' => $assets->map(fn (InventoryAsset $asset) => $asset->publicData())->values(),
            'summary' => $this->summary(),
            'meta' => [
                'conditions' => InventoryAsset::conditions(),
                'statuses' => InventoryAsset::statuses(),
                'location_types' => InventoryAsset::locationTypes(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validatedAttributes($request);
        $asset = DB::transaction(function () use ($attributes, $request): InventoryAsset {
            $asset = InventoryAsset::create($attributes);
            app(InventoryAssetTransferService::class)->seedInitialLocation($asset);
            $asset->load(['accommodation', 'locations.accommodation']);

            $this->recordHistory($request, $asset, 'created', null, $asset->publicData(), $request->input('remarks'));

            return $asset;
        });

        return response()->json([
            'data' => $asset->publicData(),
            'message' => 'Inventory asset created.',
        ], 201);
    }

    public function show(InventoryAsset $inventoryAsset): JsonResponse
    {
        return response()->json([
            'data' => $inventoryAsset->load(['accommodation', 'locations.accommodation'])->publicData(),
            'history' => $inventoryAsset->histories()
                ->with('performer')
                ->latest()
                ->get()
                ->map(fn (InventoryAssetHistory $history) => $history->publicData())
                ->values(),
        ]);
    }

    public function update(Request $request, InventoryAsset $inventoryAsset): JsonResponse
    {
        $this->ensureNotRetired($inventoryAsset);
        $attributes = $this->validatedAttributes($request, $inventoryAsset);
        $old = $inventoryAsset->load(['accommodation', 'locations.accommodation'])->publicData();

        $inventoryAsset->update($attributes);
        $inventoryAsset->load(['accommodation', 'locations.accommodation']);

        $this->recordHistory($request, $inventoryAsset, 'updated', $old, $inventoryAsset->publicData(), $request->input('remarks'));

        return response()->json([
            'data' => $inventoryAsset->publicData(),
            'message' => 'Inventory asset updated.',
        ]);
    }

    public function assign(Request $request, InventoryAsset $inventoryAsset): JsonResponse
    {
        $this->ensureNotRetired($inventoryAsset);
        $attributes = $request->validate([
            'source_location_id' => ['required', 'integer', 'exists:inventory_asset_locations,id'],
            'transfer_quantity' => ['required', 'integer', 'min:1'],
            'destination_key' => ['required', 'string', Rule::in($this->destinationKeys($inventoryAsset))],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = app(InventoryAssetTransferService::class)->transfer($inventoryAsset, $attributes, $request);

        return response()->json([
            'data' => $result['asset']->publicData(),
            'message' => 'Inventory asset transferred.',
        ]);
    }

    public function updateCondition(Request $request, InventoryAsset $inventoryAsset): JsonResponse
    {
        $this->ensureNotRetired($inventoryAsset);
        $attributes = $request->validate([
            'condition' => ['required', Rule::in(InventoryAsset::conditions())],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $old = ['condition' => $inventoryAsset->condition];

        $inventoryAsset->forceFill(['condition' => $attributes['condition']])->save();

        $this->recordHistory($request, $inventoryAsset, 'condition_changed', $old, ['condition' => $inventoryAsset->condition], $attributes['remarks'] ?? null);

        return response()->json([
            'data' => $inventoryAsset->fresh(['accommodation', 'locations.accommodation'])->publicData(),
            'message' => 'Inventory asset condition updated.',
        ]);
    }

    public function updateStatus(Request $request, InventoryAsset $inventoryAsset): JsonResponse
    {
        $attributes = $request->validate([
            'status' => ['required', Rule::in(InventoryAsset::statuses())],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($inventoryAsset->status === InventoryAsset::STATUS_RETIRED) {
            throw ValidationException::withMessages([
                'status' => ['Retired assets cannot be reactivated.'],
            ]);
        }

        $old = ['status' => $inventoryAsset->status];
        $nextStatus = $attributes['status'];
        $action = match ($nextStatus) {
            InventoryAsset::STATUS_MAINTENANCE => 'sent_to_maintenance',
            InventoryAsset::STATUS_AVAILABLE => $inventoryAsset->status === InventoryAsset::STATUS_MAINTENANCE ? 'returned_from_maintenance' : 'status_changed',
            InventoryAsset::STATUS_RETIRED => 'retired',
            default => 'status_changed',
        };

        $inventoryAsset->forceFill(['status' => $nextStatus])->save();

        $this->recordHistory($request, $inventoryAsset, $action, $old, ['status' => $inventoryAsset->status], $attributes['remarks'] ?? null);

        return response()->json([
            'data' => $inventoryAsset->fresh(['accommodation', 'locations.accommodation'])->publicData(),
            'message' => $nextStatus === InventoryAsset::STATUS_RETIRED ? 'Inventory asset retired.' : 'Inventory asset status updated.',
        ]);
    }

    private function ensureNotRetired(InventoryAsset $asset): void
    {
        if ($asset->status === InventoryAsset::STATUS_RETIRED) {
            throw ValidationException::withMessages([
                'status' => ['Retired assets are archived and cannot be modified.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAttributes(Request $request, ?InventoryAsset $asset = null): array
    {
        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:80'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'condition' => ['required', Rule::in(InventoryAsset::conditions())],
            'status' => ['required', Rule::in(InventoryAsset::statuses())],
            'acquisition_date' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'description' => ['nullable', 'string', 'max:5000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ] + $this->locationRules());

        $this->normalizeLocation($attributes);

        return collect($attributes)->except('remarks')->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function locationRules(): array
    {
        return [
            'location_type' => ['required', Rule::in(InventoryAsset::locationTypes())],
            'accommodation_id' => ['required_if:location_type,'.InventoryAsset::LOCATION_ACCOMMODATION, 'nullable', 'integer', 'exists:accommodations,id'],
            'location_name' => ['required_unless:location_type,'.InventoryAsset::LOCATION_ACCOMMODATION, 'nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function normalizeLocation(array &$attributes): void
    {
        if (($attributes['location_type'] ?? null) === InventoryAsset::LOCATION_ACCOMMODATION) {
            $attributes['location_name'] = null;
            return;
        }

        $attributes['accommodation_id'] = null;
    }

    /**
     * @return array<int, string>
     */
    private function destinationKeys(InventoryAsset $asset): array
    {
        return array_map(
            fn (array $option) => $option['key'],
            app(InventoryAssetTransferService::class)->allowedDestinationOptions($asset),
        );
    }

    private function recordHistory(Request $request, InventoryAsset $asset, string $action, mixed $old, mixed $new, ?string $remarks): void
    {
        $asset->histories()->create([
            'performed_by' => $request->user()?->id,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'remarks' => $remarks,
        ]);
        app(AuditLogger::class)->log($request, 'inventory', $action, 'Inventory asset '.$action.'.', $asset, [
            'before' => $old,
            'after' => $new,
            'remarks' => $remarks,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        return [
            'total_assets' => InventoryAsset::count(),
            'available' => InventoryAsset::where('status', InventoryAsset::STATUS_AVAILABLE)->count(),
            'assigned' => InventoryAsset::where('status', InventoryAsset::STATUS_ASSIGNED)->count(),
            'under_maintenance' => InventoryAsset::where('status', InventoryAsset::STATUS_MAINTENANCE)->count(),
            'damaged' => InventoryAsset::where('condition', InventoryAsset::CONDITION_DAMAGED)->count(),
        ];
    }
}
