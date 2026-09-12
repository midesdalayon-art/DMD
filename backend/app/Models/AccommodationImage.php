<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\AccommodationImageOptimizer;

class AccommodationImage extends Model
{
    protected $fillable = [
        'accommodation_id',
        'image_path',
        'is_primary',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function url(): string
    {
        if (str_starts_with($this->image_path, 'http://')
            || str_starts_with($this->image_path, 'https://')
            || str_starts_with($this->image_path, '/')) {
            return $this->image_path;
        }

        $optimized = app(AccommodationImageOptimizer::class)->publicData($this->image_path);

        return $optimized['medium_url']
            ?? $optimized['large_url']
            ?? $optimized['original_url'];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicData(): array
    {
        if (str_starts_with($this->image_path, 'http://')
            || str_starts_with($this->image_path, 'https://')
            || str_starts_with($this->image_path, '/')) {
            return [
                'id' => $this->id,
                'image_path' => $this->image_path,
                'url' => $this->image_path,
                'original_url' => $this->image_path,
                'thumbnail_url' => $this->image_path,
                'medium_url' => $this->image_path,
                'large_url' => $this->image_path,
                'srcset' => null,
                'is_primary' => $this->is_primary,
                'sort_order' => $this->sort_order,
            ];
        }

        $optimized = app(AccommodationImageOptimizer::class)->publicData($this->image_path);

        return [
            'id' => $this->id,
            'image_path' => $this->image_path,
            'url' => $optimized['medium_url']
                ?? $optimized['large_url']
                ?? $optimized['original_url'],
            'original_url' => $optimized['original_url'],
            'thumbnail_url' => $optimized['thumbnail_url'],
            'medium_url' => $optimized['medium_url'],
            'large_url' => $optimized['large_url'],
            'srcset' => $optimized['srcset'],
            'is_primary' => $this->is_primary,
            'sort_order' => $this->sort_order,
        ];
    }
}
