<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class Reservation extends Model
{
    use HasFactory;

    public const ROOM_STAY_HOURS = 24;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_CHECKED_OUT = 'checked_out';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const REFUND_STATUS_PENDING = 'pending';
    public const REFUND_STATUS_PROCESSING = 'processing';
    public const REFUND_STATUS_REFUNDED = 'refunded';
    public const REFUND_STATUS_FAILED = 'failed';
    public const REFUND_STATUS_NOT_APPLICABLE = 'not_applicable';

    protected $fillable = [
        'user_id',
        'guest_first_name',
        'guest_last_name',
        'guest_email',
        'guest_phone',
        'guest_checkout_token_hash',
        'guest_checkout_token_expires_at',
        'guest_access_token_hash',
        'guest_access_token_issued_at',
        'guest_access_token_revoked_at',
        'guest_confirmation_email_status',
        'guest_confirmation_email_sent_at',
        'guest_confirmation_email_failed_at',
        'qr_token_hash',
        'qr_token_issued_at',
        'qr_token_revoked_at',
        'accommodation_id',
        'check_in',
        'check_out',
        'check_in_at',
        'check_out_at',
        'scheduled_check_in_at',
        'stay_days',
        'cottage_period_type',
        'cottage_period_count',
        'guests',
        'adults',
        'children',
        'infants',
        'preferred_arrival_time',
        'expected_arrival_time',
        'expected_departure_time',
        'total_amount',
        'status',
        'expires_at',
        'booking_reference',
        'cancellation_reason',
        'cancelled_at',
        'cancellation_requested_at',
        'cancellation_approved_at',
        'cancellation_deadline_at',
        'refund_eligible',
        'eligible_down_payment_amount_minor',
        'estimated_refund_amount_minor',
        'estimated_retained_amount_minor',
        'refund_status',
        'refunded_amount_minor',
        'refunded_at',
        'refund_reference',
    ];

    protected function casts(): array
    {
        return [
            'check_in' => 'date:Y-m-d',
            'check_out' => 'date:Y-m-d',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'scheduled_check_in_at' => 'datetime',
            'stay_days' => 'integer',
            'cottage_period_type' => 'string',
            'cottage_period_count' => 'integer',
            'guests' => 'integer',
            'adults' => 'integer',
            'children' => 'integer',
            'infants' => 'integer',
            'preferred_arrival_time' => 'string',
            'expected_arrival_time' => 'string',
            'expected_departure_time' => 'string',
            'total_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'cancellation_approved_at' => 'datetime',
            'cancellation_deadline_at' => 'datetime',
            'refund_eligible' => 'boolean',
            'eligible_down_payment_amount_minor' => 'integer',
            'estimated_refund_amount_minor' => 'integer',
            'estimated_retained_amount_minor' => 'integer',
            'refunded_amount_minor' => 'integer',
            'refunded_at' => 'datetime',
            'guest_checkout_token_expires_at' => 'datetime',
            'guest_access_token_issued_at' => 'datetime',
            'guest_access_token_revoked_at' => 'datetime',
            'guest_confirmation_email_sent_at' => 'datetime',
            'guest_confirmation_email_failed_at' => 'datetime',
            'qr_token_issued_at' => 'datetime',
            'qr_token_revoked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function activeStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_CONFIRMED,
            self::STATUS_CHECKED_IN,
        ];
    }

    /**
     * @return list<string>
     */
    public static function adminStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_CONFIRMED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
            self::STATUS_CHECKED_IN,
            self::STATUS_CHECKED_OUT,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function allowedAdminStatusTransitions(): array
    {
        return [
            self::STATUS_PENDING => [
                self::STATUS_CONFIRMED,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_CONFIRMED => [
                self::STATUS_CHECKED_IN,
                self::STATUS_CANCELLED,
            ],
            self::STATUS_CHECKED_IN => [
                self::STATUS_CHECKED_OUT,
            ],
            self::STATUS_CHECKED_OUT => [],
            self::STATUS_CANCELLED => [],
            self::STATUS_EXPIRED => [],
        ];
    }

    public static function applyAvailabilityStatusFilter(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));

        return $query->where(function (Builder $statusQuery) use ($now) {
            $statusQuery
                ->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_CHECKED_IN])
                ->orWhere(function (Builder $pendingQuery) use ($now) {
                    $pendingQuery
                        ->where('status', self::STATUS_PENDING)
                        ->where(function (Builder $expiryQuery) use ($now) {
                            $expiryQuery
                                ->whereNull('expires_at')
                                ->orWhere('expires_at', '>', $now);
                        });
                });
        });
    }

    public static function applyPendingHoldFilter(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));

        return $query
            ->where('status', self::STATUS_PENDING)
            ->where(function (Builder $expiryQuery) use ($now) {
                $expiryQuery
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            });
    }

    public function pendingHoldExpired(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));

        return $this->status === self::STATUS_PENDING
            && $this->paymentState() === 'unpaid'
            && $this->expires_at
            && $this->expires_at->lte($now);
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(ReservationPayment::class)->latestOfMany();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ReservationPayment::class);
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(GuestFeedback::class);
    }

    public function scopeWithPaymentSummary(Builder $query): Builder
    {
        return $query
            ->with('payment')
            ->withSum([
                'payments as paid_amount_minor' => fn (Builder $paymentQuery) => $paymentQuery
                    ->where('status', ReservationPayment::STATUS_PAID),
            ], 'amount');
    }

    public function loadPaymentSummary(): static
    {
        $this->loadMissing('payment');
        $this->setAttribute('paid_amount_minor', $this->payments()
            ->where('status', ReservationPayment::STATUS_PAID)
            ->sum('amount'));
        $this->syncOriginalAttribute('paid_amount_minor');

        return $this;
    }

    public function totalAmountMinor(): int
    {
        return self::currencyToMinorUnits((string) $this->total_amount);
    }

    public static function currencyToMinorUnits(string $amount): int
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
        $minor = ((int) ($whole ?: 0)) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$minor : $minor;
    }

    public function totalPaidMinor(): int
    {
        if ($this->getAttribute('paid_amount_minor') !== null) {
            return max(0, (int) $this->getAttribute('paid_amount_minor'));
        }

        if ($this->relationLoaded('payments')) {
            return max(0, (int) $this->payments
                ->where('status', ReservationPayment::STATUS_PAID)
                ->sum(fn (ReservationPayment $payment) => (int) $payment->amount));
        }

        return max(0, (int) $this->payments()
            ->where('status', ReservationPayment::STATUS_PAID)
            ->sum('amount'));
    }

    public function balanceDueMinor(): int
    {
        return max($this->totalAmountMinor() - $this->totalPaidMinor(), 0);
    }

    public function expectedPaymentAmount(string $purpose): int
    {
        return match ($purpose) {
            ReservationPayment::PURPOSE_DEPOSIT => $this->depositAmountMinor(),
            ReservationPayment::PURPOSE_FULL => $this->totalAmountMinor(),
            ReservationPayment::PURPOSE_BALANCE => $this->balanceDueMinor(),
            default => throw new InvalidArgumentException('Unsupported payment purpose.'),
        };
    }

    public function depositAmountMinor(): int
    {
        // Round half up in minor units without converting through floating point.
        return intdiv(($this->totalAmountMinor() * 30) + 50, 100);
    }

    public function paymentState(): string
    {
        $totalPaid = $this->totalPaidMinor();
        $total = $this->totalAmountMinor();

        if ($totalPaid <= 0) {
            return 'unpaid';
        }

        return $totalPaid < $total ? 'partially_paid' : 'fully_paid';
    }

    public function hasValidInitialPayment(): bool
    {
        return $this->payments()
            ->where('status', ReservationPayment::STATUS_PAID)
            ->where(function ($query) {
                $query
                    ->where(function ($depositQuery) {
                        $depositQuery
                            ->where('purpose', ReservationPayment::PURPOSE_DEPOSIT)
                            ->where('amount', $this->expectedPaymentAmount(ReservationPayment::PURPOSE_DEPOSIT));
                    })
                    ->orWhere(function ($fullQuery) {
                        $fullQuery
                            ->where('purpose', ReservationPayment::PURPOSE_FULL)
                            ->where('amount', $this->expectedPaymentAmount(ReservationPayment::PURPOSE_FULL));
                    });
            })
            ->exists();
    }

    /**
     * @return array{total_paid: string, balance_due: string, payment_state: string}
     */
    public function paymentSummary(): array
    {
        return [
            'total_paid' => self::minorUnitsToCurrency($this->totalPaidMinor()),
            'balance_due' => self::minorUnitsToCurrency($this->balanceDueMinor()),
            'payment_state' => $this->paymentState(),
        ];
    }

    public static function minorUnitsToCurrency(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    public static function hasActiveDateRangeOverlap(
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOutExclusive,
        ?string $accommodationType = null
    ): bool {
        return static::query()
            ->tap(fn ($query) => self::applyAvailabilityStatusFilter($query))
            ->when($accommodationType, function ($query) use ($accommodationType) {
                $query->whereHas('accommodation', function ($accommodationQuery) use ($accommodationType) {
                    $accommodationQuery->where('type', $accommodationType);
                });
            })
            ->where(function ($query) use ($checkIn, $checkOutExclusive) {
                $query
                    ->where(function ($dateQuery) use ($checkIn, $checkOutExclusive) {
                        $dateQuery
                            ->whereNotNull('check_in_at')
                            ->whereNotNull('check_out_at')
                            ->where('check_in_at', '<', $checkOutExclusive->toDateTimeString())
                            ->where('check_out_at', '>', $checkIn->toDateTimeString());
                    })
                    ->orWhere(function ($dateQuery) use ($checkIn, $checkOutExclusive) {
                        $dateQuery
                            ->whereNull('check_in_at')
                            ->whereNull('check_out_at')
                            ->whereDate('check_in', '<', $checkOutExclusive->toDateString())
                            ->whereDate('check_out', '>', $checkIn->toDateString());
                    });
            })
            ->exists();
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_CONFIRMED], true)
            && ($checkIn = $this->scheduledCheckInAt()) !== null
            && $checkIn->isFuture();
    }

    public function scheduledCheckInAt(): ?CarbonImmutable
    {
        if ($this->scheduled_check_in_at) {
            return CarbonImmutable::instance($this->scheduled_check_in_at)->setTimezone(config('app.timezone'));
        }
        if ($this->check_in_at) {
            return CarbonImmutable::instance($this->check_in_at)->setTimezone(config('app.timezone'));
        }
        if (! $this->check_in) {
            return null;
        }

        $time = $this->expected_arrival_time ?? $this->preferred_arrival_time;
        if (! $time && $this->cottage_period_type) {
            $time = $this->cottage_period_type === 'overnight' ? '18:00' : '06:00';
        }
        $time ??= '14:00';

        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $this->check_in->format('Y-m-d').' '.$time,
            config('app.timezone')
        ) ?: null;
    }

    public function canTransitionTo(string $status): bool
    {
        if ($this->status === $status) {
            return true;
        }

        return in_array($status, self::allowedAdminStatusTransitions()[$this->status] ?? [], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicData(bool $includePaymentHistory = false, bool $includeProviderIdentifiers = false): array
    {
        $paymentSummary = $this->paymentSummary();
        $depositAmount = self::minorUnitsToCurrency($this->depositAmountMinor());
        $depositBalance = self::minorUnitsToCurrency(max($this->totalAmountMinor() - $this->depositAmountMinor(), 0));

        $data = [
            'id' => $this->id,
            'booking_reference' => $this->booking_reference,
            'check_in' => $this->check_in?->format('Y-m-d'),
            'check_out' => $this->check_out?->format('Y-m-d'),
            'check_in_at' => $this->check_in_at?->toISOString(),
            'check_out_at' => $this->check_out_at?->toISOString(),
            'scheduled_check_in_at' => $this->scheduledCheckInAt()?->toISOString(),
            'stay_days' => $this->stay_days,
            'cottage_period_type' => $this->cottage_period_type,
            'cottage_period_count' => $this->cottage_period_count,
            'guests' => $this->guests,
            'adults' => $this->adults,
            'children' => $this->children,
            'infants' => $this->infants,
            'preferred_arrival_time' => $this->preferred_arrival_time,
            'expected_arrival_time' => $this->expected_arrival_time ?? $this->preferred_arrival_time,
            'expected_departure_time' => $this->expected_departure_time,
            'guest_breakdown' => [
                'adults' => $this->adults,
                'children' => $this->children,
                'infants' => $this->infants,
                'occupancy' => $this->guests,
            ],
            'total_amount' => (float) $this->total_amount,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toISOString(),
            'payment_status' => $this->payment?->status ?? 'pending',
            'payment_purpose' => $this->payment?->purpose,
            'payment_provider' => $this->payment?->provider,
            'payment_paid_at' => $this->payment?->paid_at?->toISOString(),
            'total_paid' => $paymentSummary['total_paid'],
            'balance_due' => $paymentSummary['balance_due'],
            'payment_state' => $paymentSummary['payment_state'],
            'deposit_amount' => $depositAmount,
            'deposit_balance_due' => $depositBalance,
            'payment_history' => $includePaymentHistory ? $this->customerPaymentHistory() : null,
            'feedback' => $this->relationLoaded('feedback') && $this->feedback ? [
                'rating' => $this->feedback->rating,
                'comment' => $this->feedback->comment,
                'submitted_at' => $this->feedback->submitted_at?->toISOString(),
            ] : null,
            'duration_hours' => $this->check_in_at && $this->check_out_at
                ? $this->check_in_at->diffInHours($this->check_out_at)
                : ($this->check_in && $this->check_out
                    ? match ($this->accommodation?->type) {
                        Accommodation::TYPE_ROOM => (($this->stay_days ?: 1) * self::ROOM_STAY_HOURS),
                        Accommodation::TYPE_COTTAGE => $this->check_in->diffInHours($this->check_out),
                        default => $this->check_in->diffInDays($this->check_out) * 24,
                    }
                    : null),
            'can_cancel' => $this->canBeCancelled(),
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_requested_at' => $this->cancellation_requested_at?->toISOString(),
            'cancellation_approved_at' => $this->cancellation_approved_at?->toISOString(),
            'cancellation_deadline_at' => ($this->cancellation_deadline_at ?? $this->scheduledCheckInAt()?->subHours(72))?->toISOString(),
            'refund_eligible' => $this->refund_eligible,
            'eligible_down_payment_amount' => self::minorUnitsToCurrency((int) $this->eligible_down_payment_amount_minor),
            'estimated_refund_amount' => self::minorUnitsToCurrency((int) $this->estimated_refund_amount_minor),
            'estimated_retained_amount' => self::minorUnitsToCurrency((int) $this->estimated_retained_amount_minor),
            'refund_status' => $this->refund_status ?? self::REFUND_STATUS_NOT_APPLICABLE,
            'refunded_amount' => self::minorUnitsToCurrency((int) $this->refunded_amount_minor),
            'refunded_at' => $this->refunded_at?->toISOString(),
            'accommodation' => $this->accommodation?->publicData(),
            'guest' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'first_name' => $this->user->first_name,
                'last_name' => $this->user->last_name,
                'email' => $this->user->email,
                'phone' => $this->user->contact_number,
            ] : ($this->user_id === null ? [
                'id' => null,
                'name' => trim($this->guest_first_name.' '.$this->guest_last_name),
                'first_name' => $this->guest_first_name,
                'last_name' => $this->guest_last_name,
                'email' => $this->guest_email,
                'phone' => $this->guest_phone,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];

        if ($includeProviderIdentifiers) {
            $data['payment_reference'] = $this->payment?->provider_reference;
            $data['payment_checkout_session_id'] = $this->payment?->checkout_session_id;
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function customerPaymentHistory(): array
    {
        return $this->payments()
            ->latest('id')
            ->get()
            ->each(fn (ReservationPayment $payment) => $payment->setRelation('reservation', $this))
            ->map(fn (ReservationPayment $payment) => $payment->customerData())
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function staffPaymentHistory(): array
    {
        return $this->payments()
            ->with('recordedBy:id,name')
            ->latest('id')
            ->get()
            ->each(fn (ReservationPayment $payment) => $payment->setRelation('reservation', $this))
            ->map(fn (ReservationPayment $payment) => $payment->staffData())
            ->values()
            ->all();
    }
}
