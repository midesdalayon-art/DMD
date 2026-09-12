<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Announcement extends Model
{
    public const TYPE_GENERAL = 'general';
    public const TYPE_PROMOTION = 'promotion';
    public const TYPE_MAINTENANCE = 'maintenance';
    public const TYPE_FACILITY_NOTICE = 'facility_notice';
    public const TYPE_BOOKING_ADVISORY = 'booking_advisory';
    public const TYPE_HOLIDAY_NOTICE = 'holiday_notice';
    public const TYPE_STAFF_NOTICE = 'staff_notice';

    public const AUDIENCE_EVERYONE = 'everyone';
    public const AUDIENCE_GUESTS = 'guests';
    public const AUDIENCE_STAFF = 'staff';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'title',
        'content',
        'type',
        'audience',
        'status',
        'publish_at',
        'expires_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'publish_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_GENERAL,
            self::TYPE_PROMOTION,
            self::TYPE_MAINTENANCE,
            self::TYPE_FACILITY_NOTICE,
            self::TYPE_BOOKING_ADVISORY,
            self::TYPE_HOLIDAY_NOTICE,
            self::TYPE_STAFF_NOTICE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function audiences(): array
    {
        return [
            self::AUDIENCE_EVERYONE,
            self::AUDIENCE_GUESTS,
            self::AUDIENCE_STAFF,
        ];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_PUBLISHED,
            self::STATUS_ARCHIVED,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    public function isPubliclyVisible(): bool
    {
        $now = now();

        return $this->status === self::STATUS_PUBLISHED
            && in_array($this->audience, [self::AUDIENCE_EVERYONE, self::AUDIENCE_GUESTS], true)
            && (! $this->publish_at || $this->publish_at->lte($now))
            && (! $this->expires_at || $this->expires_at->gt($now));
    }

    /**
     * @return array<string, mixed>
     */
    public function publicData(bool $includeAdminFields = false): array
    {
        $data = [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'type' => $this->type,
            'audience' => $this->audience,
            'publish_at' => $this->publish_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        if ($includeAdminFields) {
            $data['status'] = $this->status;
            $data['created_by'] = $this->creator?->publicProfile();
        }

        return $data;
    }
}
