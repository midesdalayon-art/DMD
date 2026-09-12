<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationPayment extends Model
{
    use HasFactory;

    public const PURPOSE_FULL = 'full';
    public const PURPOSE_DEPOSIT = 'deposit';
    public const PURPOSE_BALANCE = 'balance';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'reservation_id',
        'purpose',
        'provider',
        'provider_reference',
        'checkout_session_id',
        'checkout_url',
        'payment_id',
        'amount',
        'currency',
        'status',
        'payment_method',
        'recorded_by_user_id',
        'paid_at',
        'raw_reference',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function customerData(): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservation_id,
            'booking_reference' => $this->reservation?->booking_reference,
            'purpose' => $this->purpose,
            'amount' => number_format(((int) $this->amount) / 100, 2, '.', ''),
            'currency' => strtoupper((string) ($this->currency ?: 'PHP')),
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'provider' => $this->provider,
            'paid_at' => $this->paid_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function staffData(): array
    {
        return array_merge($this->customerData(), [
            'recorded_by' => $this->recordedBy ? [
                'id' => $this->recordedBy->id,
                'name' => $this->recordedBy->name,
            ] : null,
        ]);
    }

    /**
     * @return list<string>
     */
    public static function purposes(): array
    {
        return [
            self::PURPOSE_FULL,
            self::PURPOSE_DEPOSIT,
            self::PURPOSE_BALANCE,
        ];
    }
}
