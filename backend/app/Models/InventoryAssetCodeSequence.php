<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAssetCodeSequence extends Model
{
    protected $fillable = [
        'prefix',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'last_number' => 'integer',
        ];
    }
}
