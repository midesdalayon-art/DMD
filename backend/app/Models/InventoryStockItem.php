<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryStockItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'category',
        'unit',
        'current_quantity',
        'reorder_level',
        'location',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'current_quantity' => 'integer',
            'reorder_level' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $item->current_quantity ??= 0;
            $item->is_active ??= true;
        });
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryStockMovement::class);
    }

    public function stockStatus(): string
    {
        if ($this->current_quantity <= 0) {
            return 'out_of_stock';
        }

        return $this->current_quantity <= $this->reorder_level ? 'low_stock' : 'in_stock';
    }

    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'code' => $this->sku,
            'category' => $this->category,
            'unit' => $this->unit,
            'current_quantity' => $this->current_quantity,
            'reorder_level' => $this->reorder_level,
            'status' => $this->stockStatus(),
            'location' => $this->location,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
