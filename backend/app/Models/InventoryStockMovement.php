<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStockMovement extends Model
{
    use HasFactory;

    public const TYPE_IN = 'stock_in';
    public const TYPE_OUT = 'stock_out';
    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'inventory_stock_item_id',
        'type',
        'quantity',
        'previous_quantity',
        'new_quantity',
        'reason',
        'reference',
        'notes',
        'performed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'previous_quantity' => 'integer',
            'new_quantity' => 'integer',
        ];
    }

    public static function types(): array
    {
        return [self::TYPE_IN, self::TYPE_OUT, self::TYPE_ADJUSTMENT];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryStockItem::class, 'inventory_stock_item_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'item' => $this->item?->publicData(),
            'type' => $this->type,
            'quantity' => $this->quantity,
            'previous_quantity' => $this->previous_quantity,
            'new_quantity' => $this->new_quantity,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'performed_by' => $this->performer ? [
                'id' => $this->performer->id,
                'name' => $this->performer->name,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

