<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\CarbonImmutable;

class Accommodation extends Model
{
    use HasFactory;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const TYPE_ROOM = 'room';

    public const TYPE_COTTAGE = 'cottage';

    public const TYPE_FUNCTION_HALL = 'function_hall';

    public const TYPE_EXCLUSIVE_RESORT = 'exclusive_resort';

    public const HOUSEKEEPING_READY = 'ready';

    public const HOUSEKEEPING_CLEAN = 'clean';

    public const HOUSEKEEPING_NEEDS_CLEANING = 'needs_cleaning';

    public const HOUSEKEEPING_CLEANING = 'cleaning';

    public const HOUSEKEEPING_MAINTENANCE = 'maintenance';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'capacity',
        'price_per_night',
        'description',
        'status',
        'housekeeping_status',
        'image_path',
    ];

    protected $attributes = [
        'housekeeping_status' => self::HOUSEKEEPING_READY,
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'price_per_night' => 'decimal:2',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(AccommodationImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class)->withTimestamps()->orderBy('name');
    }

    public function primaryImage(): HasMany
    {
        return $this->hasMany(AccommodationImage::class)->where('is_primary', true);
    }

    public function housekeepingTasks(): HasMany
    {
        return $this->hasMany(HousekeepingTask::class);
    }

    /**
     * @return list<string>
     */
    public static function housekeepingStatuses(): array
    {
        return [
            self::HOUSEKEEPING_READY,
            self::HOUSEKEEPING_NEEDS_CLEANING,
            self::HOUSEKEEPING_CLEANING,
            self::HOUSEKEEPING_MAINTENANCE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_ROOM,
            self::TYPE_COTTAGE,
            self::TYPE_FUNCTION_HALL,
            self::TYPE_EXCLUSIVE_RESORT,
        ];
    }

    public static function typeLabel(?string $type): string
    {
        return match ($type) {
            self::TYPE_ROOM => 'Room',
            self::TYPE_COTTAGE => 'Cottage',
            self::TYPE_FUNCTION_HALL => 'Function Hall',
            self::TYPE_EXCLUSIVE_RESORT => 'Exclusive Resort Rental',
            default => 'Accommodation',
        };
    }

    public function isBookable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE && $this->housekeeping_status === self::HOUSEKEEPING_READY;
    }

    public function isRoom(): bool
    {
        return $this->type === self::TYPE_ROOM;
    }

    public function hasActiveReservationOverlap(string $checkIn, string $checkOut): bool
    {
        return $this->reservations()
            ->tap(fn ($query) => Reservation::applyAvailabilityStatusFilter($query))
            ->whereDate('check_in', '<', $checkOut)
            ->whereDate('check_out', '>', $checkIn)
            ->exists();
    }

    public function hasActiveRoomReservationOverlap(string $checkInAt, string $checkOutAt): bool
    {
        return $this->reservations()
            ->tap(fn ($query) => Reservation::applyAvailabilityStatusFilter($query))
            ->whereNotNull('check_in_at')
            ->whereNotNull('check_out_at')
            ->where('check_in_at', '<', $checkOutAt)
            ->where('check_out_at', '>', $checkInAt)
            ->exists();
    }

    public function hasActiveCottageReservationOverlap(string $checkInAt, string $checkOutAt): bool
    {
        return $this->reservations()
            ->tap(fn ($query) => Reservation::applyAvailabilityStatusFilter($query))
            ->where(function ($query) use ($checkInAt, $checkOutAt) {
                $query
                    ->where(function ($dateQuery) use ($checkInAt, $checkOutAt) {
                        $dateQuery
                            ->whereNotNull('check_in_at')
                            ->whereNotNull('check_out_at')
                            ->where('check_in_at', '<', $checkOutAt)
                            ->where('check_out_at', '>', $checkInAt);
                    })
                    ->orWhere(function ($dateQuery) use ($checkInAt, $checkOutAt) {
                        $dateQuery
                            ->whereNull('check_in_at')
                            ->whereNull('check_out_at')
                            ->whereDate('check_in', '<', CarbonImmutable::parse($checkOutAt)->toDateString())
                            ->whereDate('check_out', '>', CarbonImmutable::parse($checkInAt)->toDateString());
                    });
            })
            ->exists();
    }

    public function isAvailableFor(string $checkIn, string $checkOut): bool
    {
        return $this->isBookable() && ! $this->hasActiveReservationOverlap($checkIn, $checkOut);
    }

    public function isAvailableForRoomStay(string $checkInAt, string $checkOutAt): bool
    {
        return $this->isBookable() && ! $this->hasActiveRoomReservationOverlap($checkInAt, $checkOutAt);
    }

    public function isAvailableForCottageStay(string $checkInAt, string $checkOutAt): bool
    {
        return $this->isBookable() && ! $this->hasActiveCottageReservationOverlap($checkInAt, $checkOutAt);
    }

    public function canHostOccupancy(int $occupancy): bool
    {
        return $occupancy <= $this->capacity;
    }

    public function isAvailableForOccupancy(string $checkIn, string $checkOut, int $occupancy): bool
    {
        return $this->canHostOccupancy($occupancy) && $this->isAvailableFor($checkIn, $checkOut);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicData(?string $checkIn = null, ?string $checkOut = null, ?int $occupancy = null): array
    {
        $images = $this->relationLoaded('images') ? $this->images : $this->images()->get();
        $amenities = $this->relationLoaded('amenities') ? $this->amenities : $this->amenities()->get();
        $primaryImage = $images->firstWhere('is_primary', true) ?? $images->first();
        $imageUrl = $primaryImage?->url() ?? $this->image_path;
        $galleryImages = $images->take(5)->values();

        $data = [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => self::typeLabel($this->type),
            'category' => $this->type,
            'capacity' => $this->capacity,
            'guests' => $this->capacity,
            'price_per_night' => (float) $this->price_per_night,
            'price' => 'PHP '.number_format((float) $this->price_per_night, 2).(
                in_array($this->type, [self::TYPE_FUNCTION_HALL, self::TYPE_EXCLUSIVE_RESORT], true)
                    ? ' / day'
                    : ($this->type === self::TYPE_ROOM ? ' / 24 hours' : ' / night')
            ),
            'description' => $this->description,
            'summary' => $this->description,
            'status' => $this->status,
            'housekeeping_status' => $this->housekeeping_status,
            'image_path' => $imageUrl,
            'primary_image_url' => $imageUrl,
            'images' => $galleryImages->map(fn (AccommodationImage $image) => $image->publicData())->values(),
            'gallery_images' => $galleryImages->map(fn (AccommodationImage $image) => $image->publicData())->values(),
            'context' => self::typeLabel($this->type),
            'amenities' => $amenities->map(fn (Amenity $amenity) => $amenity->publicData())->values(),
            'rules' => ['Reservation dates and guest count are validated before booking'],
        ];

        if ($checkIn && $checkOut) {
            $data['is_available'] = $occupancy === null
                ? $this->isAvailableFor($checkIn, $checkOut)
                : $this->isAvailableForOccupancy($checkIn, $checkOut, $occupancy);
        }

        return $data;
    }
}
