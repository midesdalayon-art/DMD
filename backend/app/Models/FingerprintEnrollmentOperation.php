<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FingerprintEnrollmentOperation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'fingerprint_enrollment_operations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'employee_id',
        'initiated_by',
        'fingerprint_id',
        'status',
        'device_id',
        'message',
        'expires_at',
        'claimed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /** @return array<string, mixed> */
    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'fingerprint_id' => $this->fingerprint_id,
            'message' => $this->message,
            'expires_at' => $this->expires_at?->toISOString(),
            'claimed_at' => $this->claimed_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
