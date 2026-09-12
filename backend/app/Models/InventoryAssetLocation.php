<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAssetLocation extends Model
{
    protected $fillable = [
        'inventory_asset_id',
        'location_signature',
        'location_type',
        'accommodation_id',
        'location_name',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InventoryAsset::class, 'inventory_asset_id');
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function locationLabel(): string
    {
        if ($this->location_type === InventoryAsset::LOCATION_ACCOMMODATION && $this->accommodation) {
            return $this->accommodation->name;
        }

        return $this->location_name ?: ucfirst(str_replace('_', ' ', $this->location_type));
    }

    public function signature(): string
    {
        return implode('|', [
            $this->location_type,
            $this->location_type === InventoryAsset::LOCATION_ACCOMMODATION ? (string) $this->accommodation_id : '',
            $this->location_type === InventoryAsset::LOCATION_ACCOMMODATION ? '' : mb_strtolower(trim((string) $this->location_name)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'location_signature' => $this->location_signature,
            'location_type' => $this->location_type,
            'accommodation_id' => $this->accommodation_id,
            'location_name' => $this->location_name,
            'location_label' => $this->locationLabel(),
            'quantity' => $this->quantity,
            'accommodation' => $this->accommodation?->publicData(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
