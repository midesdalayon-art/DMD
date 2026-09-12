<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogger;
use App\Services\ReservationGuestPolicy;
use App\Services\SystemSettings;
use App\Services\CancellationRefundService;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reservations = $request->user()
            ->reservations()
            ->with('accommodation')
            ->withPaymentSummary()
            ->latest('created_at')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $reservations->map(fn (Reservation $reservation) => $reservation->publicData())->values(),
        ]);
    }

    public function store(Request $request, SystemSettings $settings): JsonResponse
    {
        return $this->createReservation($request, false, $settings);
    }

    public function guestStore(Request $request, SystemSettings $settings): JsonResponse
    {
        if ($request->user()) {
            abort(403, 'Guest reservations are only available to unauthenticated visitors.');
        }

        return $this->createReservation($request, true, $settings);
    }

    public function guestAbandon(Request $request, Reservation $reservation): JsonResponse
    {
        if ($request->user()) {
            abort(403, 'Guest reservation abandonment is only available to unauthenticated visitors.');
        }

        $token = $request->header('X-Guest-Checkout-Token');
        if (! is_string($token) || strlen($token) !== 64) {
            abort(401, 'Guest credential is missing or invalid.');
        }

        $abandoned = DB::transaction(function () use ($reservation, $token) {
            $lockedReservation = Reservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $storedHash = $lockedReservation->guest_checkout_token_hash;
            if (
                $lockedReservation->user_id !== null
                || ! is_string($storedHash)
                || ! hash_equals($storedHash, hash('sha256', $token))
                || ! $lockedReservation->guest_checkout_token_expires_at?->isFuture()
            ) {
                abort(401, 'Guest credential is missing or invalid.');
            }

            if ($lockedReservation->pendingHoldExpired()) {
                throw ValidationException::withMessages([
                    'reservation' => ['This reservation hold has already expired.'],
                ]);
            }

            if ($lockedReservation->status !== Reservation::STATUS_PENDING || $lockedReservation->paymentState() !== 'unpaid') {
                throw ValidationException::withMessages([
                    'reservation' => ['This reservation is no longer an unpaid pending guest reservation.'],
                ]);
            }

            $lockedReservation->payments()
                ->where('status', ReservationPayment::STATUS_PENDING)
                ->update(['status' => ReservationPayment::STATUS_CANCELLED]);

            $lockedReservation->forceFill([
                'status' => Reservation::STATUS_CANCELLED,
                'cancellation_reason' => 'Guest abandoned the pending reservation before payment.',
                'cancelled_at' => now(),
                'guest_checkout_token_hash' => null,
                'guest_checkout_token_expires_at' => null,
            ])->save();

            return $lockedReservation->fresh();
        });

        app(AuditLogger::class)->log($request, 'booking_management', 'guest_reservation_abandoned', 'Guest abandoned an unpaid pending reservation.', $abandoned, [
            'booking_reference' => $abandoned->booking_reference,
        ]);

        return response()->json([
            'data' => $abandoned->load('accommodation')->loadPaymentSummary()->publicData(true),
            'message' => 'Pending reservation abandoned.',
        ]);
    }

    private function createReservation(Request $request, bool $isGuest, SystemSettings $settings): JsonResponse
    {
        $attributes = $request->validate(array_merge([
            'accommodation_id' => ['required', 'integer', 'exists:accommodations,id'],
            'check_in' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'check_out' => ['sometimes', 'nullable', 'date'],
            'check_in_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'event_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'stay_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cottage_period_type' => ['sometimes', 'nullable', Rule::in(['day_use', 'overnight'])],
            'cottage_period_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'guests' => ['sometimes', 'integer', 'min:1'],
            'adults' => ['sometimes', 'integer', 'min:1'],
            'children' => ['sometimes', 'integer', 'min:0'],
            'infants' => ['sometimes', 'integer', 'min:0'],
            'preferred_arrival_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'expected_arrival_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'expected_departure_time' => ['sometimes', 'nullable', 'date_format:H:i'],
        ], $isGuest ? [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9\s().-]{7,20}$/'],
        ] : []));
        $bookingSettings = $settings->get()['booking'];
        [$reservation, $guestCheckoutToken] = DB::transaction(function () use ($attributes, $request, $bookingSettings, $isGuest) {
            // Lock the requested accommodation and every exclusive-resort
            // parent in one deterministic order. This serializes bookings
            // for the same accommodation and also protects the resort-wide
            // exclusivity rule without lock-order deadlocks.
            $lockedAccommodations = Accommodation::query()
                ->where(function ($query) use ($attributes) {
                    $query
                        ->whereKey($attributes['accommodation_id'])
                        ->orWhere('type', Accommodation::TYPE_EXCLUSIVE_RESORT);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $accommodation = $lockedAccommodations->firstWhere('id', $attributes['accommodation_id']);

            abort_if(! $accommodation, 404);
            $guestBreakdown = app(ReservationGuestPolicy::class)->normalize($attributes);
            $isRoom = $accommodation->isRoom();
            $isCottage = $accommodation->type === Accommodation::TYPE_COTTAGE
                && ! empty($attributes['cottage_period_type'])
                && ! empty($attributes['cottage_period_count']);
            $isFunctionHall = $accommodation->type === Accommodation::TYPE_FUNCTION_HALL;
            $isExclusiveResort = $accommodation->type === Accommodation::TYPE_EXCLUSIVE_RESORT;

            $hasRoomStayTime = $isRoom && ! empty($attributes['check_in_time']);
            $roomStayDays = $isRoom
                ? (
                    $hasRoomStayTime
                        ? max(1, (int) ($attributes['stay_days'] ?? 1))
                        : max(1, CarbonImmutable::parse($attributes['check_in'])->diffInDays(CarbonImmutable::parse($attributes['check_out'] ?? $attributes['check_in'])))
                )
                : null;
            $stayDays = $isRoom ? $roomStayDays : 1;
            $functionHallDays = ($isFunctionHall || $isExclusiveResort) ? max(1, (int) ($attributes['stay_days'] ?? 1)) : null;
            $cottagePeriodType = $isCottage ? ($attributes['cottage_period_type'] ?? null) : null;
            $cottagePeriodCount = $isCottage ? max(1, (int) ($attributes['cottage_period_count'] ?? 1)) : null;

            if ($isFunctionHall || $isExclusiveResort) {
                $eventDate = $attributes['event_date'] ?? $attributes['check_in'] ?? null;

                if (! $eventDate) {
                    throw ValidationException::withMessages([
                        $isExclusiveResort ? 'event_date' : 'event_date' => [$isExclusiveResort ? 'Start date is required for the Exclusive Resort Rental.' : 'Event date is required for the Function Hall.'],
                    ]);
                }

                $checkInDate = CarbonImmutable::parse($eventDate, config('app.timezone'))->startOfDay();

                if ($checkInDate->isPast()) {
                    throw ValidationException::withMessages([
                        'event_date' => ['Start date must not be in the past.'],
                    ]);
                }

                $checkIn = $checkInDate->toDateString();
                $checkOut = $checkInDate->addDays($functionHallDays)->toDateString();
                $checkInAt = null;
                $checkOutAt = null;
                $expectedArrivalTime = null;
                $expectedDepartureTime = null;
                $preferredArrivalTime = null;
            } elseif ($isRoom) {
                if (empty($attributes['check_in'])) {
                    throw ValidationException::withMessages([
                        'check_in' => ['Check-in date is required for a Room reservation.'],
                    ]);
                }

                if (! empty($attributes['check_in_time'])) {
                    $checkInAt = CarbonImmutable::createFromFormat(
                        'Y-m-d H:i',
                        sprintf('%s %s', $attributes['check_in'], $attributes['check_in_time']),
                        config('app.timezone')
                    );

                    if ($checkInAt === false) {
                        throw ValidationException::withMessages([
                            'check_in_time' => ['Check-in time must be a valid time value.'],
                        ]);
                    }

                    $checkOutAt = $checkInAt->addHours(Reservation::ROOM_STAY_HOURS * $stayDays);
                    $checkIn = $checkInAt->toDateString();
                    $checkOut = $checkOutAt->toDateString();
                    $expectedArrivalTime = null;
                    $expectedDepartureTime = null;
                    $preferredArrivalTime = null;
                } else {
                    $checkInAt = null;
                    $checkOutAt = null;
                    $checkIn = $attributes['check_in'];
                    $checkOut = $attributes['check_out'] ?? CarbonImmutable::parse($attributes['check_in'])->addDays($stayDays)->toDateString();
                    $expectedArrivalTime = $attributes['expected_arrival_time'] ?? $attributes['preferred_arrival_time'] ?? null;
                    $expectedDepartureTime = $attributes['expected_departure_time'] ?? null;
                    $preferredArrivalTime = $expectedArrivalTime;
                }
            } elseif ($isCottage) {
                if (empty($attributes['check_in']) || empty($cottagePeriodType) || empty($cottagePeriodCount)) {
                    throw ValidationException::withMessages([
                        'check_in' => ['Cottage date, period type, and period count are required.'],
                    ]);
                }

                $checkInAt = $this->calculateCottageStart($attributes['check_in'], $cottagePeriodType);

                if ($checkInAt->isPast()) {
                    throw ValidationException::withMessages([
                        'check_in' => ['Selected Cottage period has already started. Please choose another date.'],
                    ]);
                }

                $checkOutAt = $checkInAt->addHours(12 * $cottagePeriodCount);
                $checkIn = $checkInAt->toDateString();
                $checkOut = $checkOutAt->toDateString();
                $expectedArrivalTime = null;
                $expectedDepartureTime = null;
                $preferredArrivalTime = null;
            } else {
                if (empty($attributes['check_in']) || empty($attributes['check_out'])) {
                    throw ValidationException::withMessages([
                        'check_in' => ['Check-in and check-out dates are required.'],
                    ]);
                }

                $checkIn = $attributes['check_in'];
                $checkOut = $attributes['check_out'];
                $expectedArrivalTime = $attributes['expected_arrival_time'] ?? $attributes['preferred_arrival_time'] ?? null;
                $expectedDepartureTime = $attributes['expected_departure_time'] ?? null;
                $preferredArrivalTime = $expectedArrivalTime;
            }

            if ($expectedArrivalTime !== null && $expectedArrivalTime !== '' && (! $isRoom || ! $hasRoomStayTime)) {
                $arrivalWindowStart = CarbonImmutable::createFromFormat('H:i', (string) ($bookingSettings['house_rules_check_in_time'] ?? '14:00'));
                $arrivalWindowEnd = CarbonImmutable::createFromFormat('H:i', (string) ($bookingSettings['house_rules_quiet_hours_start'] ?? '22:00'));
                $preferredTime = CarbonImmutable::createFromFormat('H:i', $expectedArrivalTime);

                if ($arrivalWindowStart === false || $arrivalWindowEnd === false || $preferredTime === false) {
                    throw ValidationException::withMessages([
                        'expected_arrival_time' => ['Expected arrival time must be a valid time value.'],
                    ]);
                }

                if ($arrivalWindowEnd->lessThanOrEqualTo($arrivalWindowStart)) {
                    $arrivalWindowEnd = $arrivalWindowStart->addHours(8);
                }

                if ($preferredTime->lessThan($arrivalWindowStart) || $preferredTime->greaterThan($arrivalWindowEnd)) {
                    throw ValidationException::withMessages([
                        'expected_arrival_time' => ['Expected arrival time must be within the resort arrival window.'],
                    ]);
                }
            }

            if ($expectedDepartureTime !== null && $expectedDepartureTime !== '' && (! $isRoom || ! $hasRoomStayTime)) {
                $departureWindowStart = CarbonImmutable::createFromFormat('H:i', (string) ($bookingSettings['house_rules_quiet_hours_end'] ?? '07:00'));
                $departureWindowEnd = CarbonImmutable::createFromFormat('H:i', (string) ($bookingSettings['house_rules_check_out_time'] ?? '12:00'));
                $preferredTime = CarbonImmutable::createFromFormat('H:i', $expectedDepartureTime);

                if ($departureWindowStart === false || $departureWindowEnd === false || $preferredTime === false) {
                    throw ValidationException::withMessages([
                        'expected_departure_time' => ['Expected departure time must be a valid time value.'],
                    ]);
                }
            }

            if (! $accommodation->isBookable()) {
                throw ValidationException::withMessages([
                    'accommodation_id' => ['This accommodation is not currently available for booking.'],
                ]);
            }

            if (! $accommodation->canHostOccupancy($guestBreakdown['occupancy'])) {
                throw ValidationException::withMessages([
                    'guests' => ["This accommodation allows a maximum of {$accommodation->capacity} guests."],
                ]);
            }

            $requestedStart = CarbonImmutable::parse($checkIn, config('app.timezone'))->startOfDay();
            $requestedEnd = CarbonImmutable::parse($checkOut, config('app.timezone'))->startOfDay();

            if ($isExclusiveResort) {
                if (Reservation::hasActiveDateRangeOverlap($requestedStart, $requestedEnd)) {
                    $this->throwReservationConflict('The resort is not fully available for your selected dates. Please choose different dates.');
                }
            } elseif (
                Reservation::hasActiveDateRangeOverlap($requestedStart, $requestedEnd, Accommodation::TYPE_EXCLUSIVE_RESORT)
            ) {
                $this->throwReservationConflict('The resort is not fully available for your selected dates. Please choose different dates.');
            }

            if ($isRoom) {
                $hasOverlap = $checkInAt && $checkOutAt
                    ? $accommodation->hasActiveRoomReservationOverlap(
                        $checkInAt->format('Y-m-d H:i:s'),
                        $checkOutAt->format('Y-m-d H:i:s'),
                    )
                    : $accommodation->hasActiveReservationOverlap($checkIn, $checkOut);

                if ($hasOverlap) {
                    $this->throwReservationConflict('This room is unavailable for the selected stay length. Please choose another check-in time.');
                }
            } elseif ($isCottage) {
                if ($accommodation->hasActiveCottageReservationOverlap(
                    $checkInAt->format('Y-m-d H:i:s'),
                    $checkOutAt->format('Y-m-d H:i:s'),
                )) {
                    $this->throwReservationConflict('This Cottage is already reserved during part of your selected stay.');
                }
            } elseif (
                $accommodation->hasActiveReservationOverlap($checkIn, $checkOut)
            ) {
                $this->throwReservationConflict('This accommodation is already reserved for the selected dates.');
            }

            $nights = $isRoom
                ? $roomStayDays
                : ($isCottage
                    ? $cottagePeriodCount
                    : (($isFunctionHall || $isExclusiveResort)
                        ? $functionHallDays
                        : CarbonImmutable::parse($checkIn)->diffInDays(CarbonImmutable::parse($checkOut))));

            $guestCheckoutToken = $isGuest ? Str::random(64) : null;

            $scheduledCheckInAt = $checkInAt;
            if (! $scheduledCheckInAt) {
                $scheduledTime = $expectedArrivalTime
                    ?? ($isCottage ? ($cottagePeriodType === 'overnight' ? '18:00' : '06:00') : ($bookingSettings['default_check_in_time'] ?? '14:00'));
                $scheduledCheckInAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $checkIn.' '.$scheduledTime, config('app.timezone'));
            }

            $reservation = Reservation::create([
                'user_id' => $isGuest ? null : $request->user()->id,
                'guest_first_name' => $isGuest ? $attributes['first_name'] : null,
                'guest_last_name' => $isGuest ? $attributes['last_name'] : null,
                'guest_email' => $isGuest ? $attributes['email'] : null,
                'guest_phone' => $isGuest ? $attributes['phone'] : null,
                'guest_checkout_token_hash' => $guestCheckoutToken ? hash('sha256', $guestCheckoutToken) : null,
                'guest_checkout_token_expires_at' => $guestCheckoutToken
                    ? CarbonImmutable::now(config('app.timezone'))->addMinutes((int) config('reservations.payment_hold_minutes', 15))
                    : null,
                'accommodation_id' => $accommodation->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'check_in_at' => $isRoom && $checkInAt ? $checkInAt->format('Y-m-d H:i:s') : null,
                'check_out_at' => $isRoom && $checkOutAt ? $checkOutAt->format('Y-m-d H:i:s') : null,
                'scheduled_check_in_at' => $scheduledCheckInAt?->format('Y-m-d H:i:s'),
                'stay_days' => $isRoom ? $roomStayDays : (($isFunctionHall || $isExclusiveResort) ? $functionHallDays : null),
                'cottage_period_type' => $isCottage ? $cottagePeriodType : null,
                'cottage_period_count' => $isCottage ? $cottagePeriodCount : null,
                'guests' => $guestBreakdown['occupancy'],
                'adults' => $guestBreakdown['adults'],
                'children' => $guestBreakdown['children'],
                'infants' => $guestBreakdown['infants'],
                'preferred_arrival_time' => $preferredArrivalTime,
                'expected_arrival_time' => $expectedArrivalTime,
                'expected_departure_time' => $expectedDepartureTime,
                'total_amount' => number_format((float) $accommodation->price_per_night * $nights, 2, '.', ''),
                'status' => Reservation::STATUS_PENDING,
                'expires_at' => CarbonImmutable::now(config('app.timezone'))
                    ->addMinutes((int) config('reservations.payment_hold_minutes', 15)),
                'booking_reference' => $this->generateBookingReference(),
            ])->load('accommodation');

            return [$reservation, $guestCheckoutToken];
        });
        app(AuditLogger::class)->log($request, 'booking_management', 'reservation_created', 'Guest reservation request submitted.', $reservation, [
            'booking_reference' => $reservation->booking_reference,
            'status' => $reservation->status,
        ]);

        return response()->json([
            'data' => $reservation->publicData(),
            'message' => 'Reservation request submitted.',
            'guest_checkout_token' => $guestCheckoutToken,
        ], 201);
    }

    public function show(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorizeReservationOwner($request, $reservation);

        return response()->json([
            'data' => $reservation->load(['accommodation', 'feedback'])->loadPaymentSummary()->publicData(true),
        ]);
    }

    public function cancel(Request $request, Reservation $reservation, CancellationRefundService $refunds): JsonResponse
    {
        $this->authorizeReservationOwner($request, $reservation);

        $attributes = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $reservation = DB::transaction(function () use ($reservation, $attributes, $refunds) {
            $reservation = Reservation::query()->whereKey($reservation->id)->with('accommodation')->lockForUpdate()->firstOrFail();
            if (! $reservation->canBeCancelled()) {
                throw ValidationException::withMessages([
                    'reservation' => ['This reservation can no longer be cancelled.'],
                ]);
            }

            return tap($reservation->forceFill(array_merge([
                'status' => Reservation::STATUS_CANCELLED,
                'cancellation_reason' => $attributes['reason'],
                'cancelled_at' => now(),
            ], $refunds->cancellationAttributes($reservation))), fn (Reservation $item) => $item->save());
        });
        $this->authorizeReservationOwner($request, $reservation);
        app(AuditLogger::class)->log($request, 'booking_management', 'booking_cancelled', 'Guest cancelled reservation.', $reservation, [
            'booking_reference' => $reservation->booking_reference,
            'reason' => $attributes['reason'],
            'refund_eligible' => $reservation->refund_eligible,
            'estimated_refund_amount_minor' => $reservation->estimated_refund_amount_minor,
        ]);

        return response()->json([
            'data' => $reservation->load('accommodation')->loadPaymentSummary()->publicData(true),
            'message' => 'Reservation cancelled.',
        ]);
    }

    private function generateBookingReference(): string
    {
        do {
            $reference = 'DMD-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (Reservation::where('booking_reference', $reference)->exists());

        return $reference;
    }

    private function throwReservationConflict(string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'That time was just booked by another guest. Please choose another check-in time or date.',
            'errors' => [
                'check_in' => [$message],
            ],
        ], 409));
    }

    private function authorizeReservationOwner(Request $request, Reservation $reservation): void
    {
        if ($reservation->user_id !== $request->user()->id) {
            abort(404);
        }
    }

    private function calculateCottageStart(string $date, string $periodType): CarbonImmutable
    {
        $startTime = $periodType === 'overnight' ? '18:00' : '06:00';

        $checkInAt = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            sprintf('%s %s', $date, $startTime),
            config('app.timezone')
        );

        if ($checkInAt === false) {
            throw ValidationException::withMessages([
                'check_in' => ['Cottage date must be a valid date.'],
            ]);
        }

        return $checkInAt;
    }
}
