<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use App\Models\InventoryAssetLocation;

class InventoryAsset extends Model
{
    use HasFactory;

    public const CONDITION_GOOD = 'good';
    public const CONDITION_FAIR = 'fair';
    public const CONDITION_DAMAGED = 'damaged';
    public const CONDITION_UNDER_REPAIR = 'under_repair';
    public const CONDITION_UNUSABLE = 'unusable';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_RETIRED = 'retired';

    public const LOCATION_ACCOMMODATION = 'accommodation';
    public const LOCATION_FUNCTION_HALL = 'function_hall';
    public const LOCATION_POOL_AREA = 'pool_area';
    public const LOCATION_RECEPTION = 'reception';
    public const LOCATION_STORAGE = 'storage';
    public const LOCATION_OTHER = 'other';

    protected $fillable = [
        'name',
        'category',
        'asset_code',
        'quantity',
        'condition',
        'status',
        'location_type',
        'accommodation_id',
        'location_name',
        'acquisition_date',
        'purchase_cost',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'acquisition_date' => 'date:Y-m-d',
            'purchase_cost' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $asset): void {
            $asset->asset_code = app(\App\Services\InventoryAssetCodeGenerator::class)->generate($asset->category);
        });
    }

    /**
     * @return list<string>
     */
    public static function conditions(): array
    {
        return [
            self::CONDITION_GOOD,
            self::CONDITION_FAIR,
            self::CONDITION_DAMAGED,
            self::CONDITION_UNDER_REPAIR,
            self::CONDITION_UNUSABLE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_AVAILABLE,
            self::STATUS_ASSIGNED,
            self::STATUS_MAINTENANCE,
            self::STATUS_RETIRED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function locationTypes(): array
    {
        return [
            self::LOCATION_ACCOMMODATION,
            self::LOCATION_FUNCTION_HALL,
            self::LOCATION_POOL_AREA,
            self::LOCATION_RECEPTION,
            self::LOCATION_STORAGE,
            self::LOCATION_OTHER,
        ];
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(InventoryAssetLocation::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(InventoryAssetHistory::class);
    }

    public function locationLabel(): string
    {
        if ($this->relationLoaded('locations') || $this->locations()->exists()) {
            $locations = $this->relationLoaded('locations') ? $this->locations : $this->locations()->get();

            if ($locations->count() === 1) {
                return $locations->first()->locationLabel();
            }

            $primary = $locations->sortByDesc('quantity')->sortByDesc('updated_at')->first();

            return $primary
                ? sprintf('%s + %d more', $primary->locationLabel(), max($locations->count() - 1, 0))
                : 'Multiple locations';
        }

        if ($this->location_type === self::LOCATION_ACCOMMODATION && $this->accommodation) {
            return $this->accommodation->name;
        }

        return $this->location_name ?: ucfirst(str_replace('_', ' ', $this->location_type));
    }

    public function locationAllocationsSummary(): array
    {
        $locations = $this->relationLoaded('locations') ? $this->locations : $this->locations()->with('accommodation')->get();

        return $locations->map(fn (InventoryAssetLocation $location) => $location->publicData())->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'asset_code' => $this->asset_code,
            'quantity' => $this->quantity,
            'condition' => $this->condition,
            'status' => $this->status,
            'location_type' => $this->location_type,
            'accommodation_id' => $this->accommodation_id,
            'location_name' => $this->location_name,
            'location_label' => $this->locationLabel(),
            'location_allocations' => $this->locationAllocationsSummary(),
            'accommodation' => $this->accommodation?->publicData(),
            'acquisition_date' => $this->acquisition_date?->format('Y-m-d'),
            'purchase_cost' => $this->purchase_cost !== null ? (float) $this->purchase_cost : null,
            'description' => $this->description,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
