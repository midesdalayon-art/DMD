<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationPayment;
use Carbon\CarbonImmutable;

class CancellationRefundService
{
    public function calculate(Reservation $reservation, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now(config('app.timezone'));
        $checkIn = $reservation->scheduledCheckInAt();
        $deadline = $checkIn?->subHours(72);
        $depositMinor = $reservation->relationLoaded('payments')
            ? (int) $reservation->payments->where('status', ReservationPayment::STATUS_PAID)->where('purpose', ReservationPayment::PURPOSE_DEPOSIT)->sum('amount')
            : (int) $reservation->payments()->where('status', ReservationPayment::STATUS_PAID)->where('purpose', ReservationPayment::PURPOSE_DEPOSIT)->sum('amount');
        $eligible = $deadline !== null && $depositMinor > 0 && $at->lte($deadline);
        $refundMinor = $eligible ? intdiv($depositMinor + 1, 2) : 0;

        return [
            'cancellation_requested_at' => $at,
            'cancellation_deadline_at' => $deadline,
            'refund_eligible' => $eligible,
            'eligible_down_payment_amount_minor' => $depositMinor,
            'estimated_refund_amount_minor' => $refundMinor,
            'estimated_retained_amount_minor' => max(0, $depositMinor - $refundMinor),
            'refund_status' => $eligible ? Reservation::REFUND_STATUS_PENDING : Reservation::REFUND_STATUS_NOT_APPLICABLE,
        ];
    }

    public function cancellationAttributes(Reservation $reservation, bool $approved = false): array
    {
        $attributes = $this->calculate($reservation);
        if ($approved) {
            $attributes['cancellation_approved_at'] = $attributes['cancellation_requested_at'];
        }

        return $attributes;
    }
}
