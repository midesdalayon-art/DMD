<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Services\AuditLogger;
use App\Services\GuestBookingConfirmationService;
use App\Services\PayMongoService;
use App\Services\ReservationQrService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PaymentController extends Controller
{
    public function checkout(Request $request, PayMongoService $payMongoService): JsonResponse
    {
        return $this->createCheckout($request, $payMongoService, 'registered');
    }

    public function guestCheckout(Request $request, PayMongoService $payMongoService): JsonResponse
    {
        return $this->createCheckout($request, $payMongoService, 'temporary_guest');
    }

    public function guestBooking(Request $request): JsonResponse
    {
        $reservation = $this->resolvePermanentGuestReservation($request);
        [$reservation, $qrToken] = DB::transaction(function () use ($reservation, $request) {
            $lockedReservation = Reservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $guestAccessToken = (string) $request->header('X-Guest-Access-Token');
            $issued = app(\App\Services\ReservationQrService::class)->issueForGuestAccess($lockedReservation, $guestAccessToken);

            return [$issued['reservation'], $issued['token']];
        });
        $data = $reservation->load(['accommodation', 'payment', 'feedback'])->loadPaymentSummary()->publicData(true);
        $data['booking_qr'] = $qrToken
            ? [
                'payload' => app(\App\Services\ReservationQrService::class)->payload($qrToken),
                'issued_at' => $reservation->qr_token_issued_at?->toISOString(),
            ]
            : null;

        return response()->json([
            'data' => $data,
        ]);
    }

    public function guestBookingCheckout(Request $request, PayMongoService $payMongoService): JsonResponse
    {
        $attributes = $request->validate([
            'purpose' => ['required', Rule::in([ReservationPayment::PURPOSE_BALANCE])],
            'amount' => ['prohibited'],
        ]);
        $reservation = $this->resolvePermanentGuestReservation($request);
        $request->merge(['reservation_id' => $reservation->id, 'purpose' => $attributes['purpose']]);

        return $this->createCheckout($request, $payMongoService, 'permanent_guest');
    }

    private function createCheckout(Request $request, PayMongoService $payMongoService, string $accessMode): JsonResponse
    {
        $guestCheckoutToken = $request->header('X-Guest-Checkout-Token');
        $guestAccessToken = $request->header('X-Guest-Access-Token');

        $attributes = $request->validate([
            'reservation_id' => ['required', 'integer', 'exists:reservations,id'],
            'amount' => ['prohibited'],
            'purpose' => ['required', Rule::in([
                ReservationPayment::PURPOSE_DEPOSIT,
                ReservationPayment::PURPOSE_FULL,
                ReservationPayment::PURPOSE_BALANCE,
            ])],
        ]);

        if ($accessMode === 'temporary_guest' && (! is_string($guestCheckoutToken) || strlen($guestCheckoutToken) !== 64)) {
            abort(401, 'Guest credential is missing or invalid.');
        }

        if ($accessMode === 'permanent_guest' && (! is_string($guestAccessToken) || strlen($guestAccessToken) !== 64)) {
            abort(401, 'Guest credential is missing or invalid.');
        }

        [$reservation, $payment, $wasExisting, $wasExpired] = DB::transaction(function () use ($attributes, $request, $payMongoService, $accessMode, $guestCheckoutToken, $guestAccessToken) {
            $reservation = Reservation::query()
                ->with(['accommodation', 'user'])
                ->whereKey($attributes['reservation_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizePaymentAccess($request, $reservation, $accessMode, $guestCheckoutToken, $guestAccessToken);
            $reservation->loadPaymentSummary();

            if ($reservation->pendingHoldExpired()) {
                $reservation->forceFill(['status' => Reservation::STATUS_EXPIRED])->save();
                app(AuditLogger::class)->log($request, 'booking_management', 'reservation_expired', 'Unpaid reservation hold expired before checkout creation.', $reservation, [
                    'reservation_id' => $reservation->id,
                    'booking_reference' => $reservation->booking_reference,
                    'expired_at' => $reservation->expires_at?->toISOString(),
                ]);

                return [$reservation, null, false, true];
            }

            if ($reservation->status === Reservation::STATUS_EXPIRED) {
                return [$reservation, null, false, true];
            }

            $purpose = $attributes['purpose'];
            $hasPaidPayment = $reservation->payments()->where('status', ReservationPayment::STATUS_PAID)->exists();
            $hasValidInitialPayment = $reservation->hasValidInitialPayment();

            if ($purpose === ReservationPayment::PURPOSE_BALANCE) {
                if (
                    $reservation->status !== Reservation::STATUS_CONFIRMED
                    || ! $hasValidInitialPayment
                    || $reservation->paymentState() !== 'partially_paid'
                    || $reservation->balanceDueMinor() <= 0
                ) {
                    throw ValidationException::withMessages([
                        'reservation_id' => ['This reservation is not eligible for a balance payment.'],
                    ]);
                }
            } elseif ($accessMode === 'permanent_guest' || $reservation->status !== Reservation::STATUS_PENDING || $hasPaidPayment) {
                throw ValidationException::withMessages([
                    'reservation_id' => ['This reservation is no longer eligible for an initial payment.'],
                ]);
            }

            $pendingPayment = $reservation->payments()
                ->where('status', ReservationPayment::STATUS_PENDING)
                ->latest('id')
                ->first();

            if ($pendingPayment) {
                if (($pendingPayment->purpose ?: ReservationPayment::PURPOSE_FULL) !== $purpose) {
                    throw ValidationException::withMessages([
                        'purpose' => ['Another payment checkout is already in progress for this reservation.'],
                    ]);
                }

                if ($pendingPayment->checkout_url) {
                    return [$reservation, $pendingPayment, true, false];
                }
            }

            $expectedAmount = $reservation->expectedPaymentAmount($purpose);

            try {
                $checkout = $payMongoService->createCheckoutSession($reservation, [
                    'name' => $accessMode !== 'registered'
                        ? trim($reservation->guest_first_name.' '.$reservation->guest_last_name)
                        : trim((string) $request->user()->name),
                    'email' => $accessMode !== 'registered' ? $reservation->guest_email : $request->user()->email,
                ], $expectedAmount, $purpose);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages([
                    'reservation_id' => [$exception->getMessage()],
                ]);
            }

            $payment = ReservationPayment::create([
                'reservation_id' => $reservation->id,
                'provider' => 'paymongo',
                'purpose' => $purpose,
                'provider_reference' => $checkout['reference_number'] ?? $reservation->booking_reference,
                'checkout_session_id' => $checkout['checkout_session_id'],
                'checkout_url' => $checkout['checkout_url'],
                'amount' => $expectedAmount,
                'currency' => 'PHP',
                'status' => ReservationPayment::STATUS_PENDING,
                'payload' => $checkout['raw'] ?? null,
            ]);

            return [$reservation, $payment, false, false];
        });

        if ($wasExpired) {
            throw ValidationException::withMessages([
                'reservation_id' => ['This reservation hold has expired. Please create a new booking.'],
            ]);
        }

        app(AuditLogger::class)->log($request, 'booking_management', 'paymongo_checkout_created', 'PayMongo checkout session created.', $reservation, [
            'booking_reference' => $reservation->booking_reference,
            'checkout_session_id' => $payment->checkout_session_id,
            'payment_status' => $payment->status,
            'payment_purpose' => $payment->purpose,
        ]);

        return response()->json([
            'data' => $this->paymentData($payment),
            'message' => $wasExisting ? 'Checkout session already prepared.' : 'Checkout session created.',
        ]);
    }

    public function show(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorizeReservationOwner($request, $reservation);

        return response()->json([
            'data' => $reservation->load(['payment', 'accommodation'])->loadPaymentSummary()->publicData(true),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $payments = ReservationPayment::query()
            ->whereHas('reservation', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('reservation:id,booking_reference')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $payments->map(fn (ReservationPayment $payment) => $payment->customerData())->values(),
        ]);
    }

    public function paymentStatus(Request $request, Reservation $reservation, PayMongoService $payMongoService): JsonResponse
    {
        return $this->paymentStatusFor($request, $reservation, $payMongoService, 'registered');
    }

    public function guestPaymentStatus(Request $request, Reservation $reservation, PayMongoService $payMongoService): JsonResponse
    {
        return $this->paymentStatusFor($request, $reservation, $payMongoService, 'temporary_guest');
    }

    public function guestBookingPaymentStatus(Request $request, Reservation $reservation, PayMongoService $payMongoService): JsonResponse
    {
        return $this->paymentStatusFor($request, $reservation, $payMongoService, 'permanent_guest');
    }

    private function paymentStatusFor(Request $request, Reservation $reservation, PayMongoService $payMongoService, string $accessMode): JsonResponse
    {
        $guestCheckoutToken = $request->header('X-Guest-Checkout-Token');
        $guestAccessToken = $request->header('X-Guest-Access-Token');
        $this->authorizePaymentAccess($request, $reservation, $accessMode, $guestCheckoutToken, $guestAccessToken);

        $reservation->load(['payment', 'accommodation'])->loadPaymentSummary();

        if ($reservation->status === Reservation::STATUS_EXPIRED && (! $reservation->payment || ! $reservation->payment->checkout_session_id)) {
            return $this->paymentStatusResponse($reservation, 'expired', 'This reservation hold expired before payment was verified. Please contact the resort if PayMongo shows a successful charge.', $accessMode);
        }

        $payment = $reservation->payment;

        if ($payment && $payment->status === ReservationPayment::STATUS_PAID && $reservation->status === Reservation::STATUS_CONFIRMED) {
            return $this->paymentStatusResponse($reservation, 'local', null, $accessMode);
        }

        if (! $payment || ! $payment->checkout_session_id) {
            return $this->paymentStatusResponse($reservation, 'local', null, $accessMode);
        }

        try {
            $checkoutSession = $payMongoService->retrieveCheckoutSession($payment->checkout_session_id);
            $normalized = $payMongoService->normalizeCheckoutSessionState($checkoutSession);
        } catch (RuntimeException $exception) {
            return $this->paymentStatusResponse($reservation, 'error', $exception->getMessage(), $accessMode);
        }

        if ($normalized['reference_number'] && $normalized['reference_number'] !== $reservation->booking_reference) {
            return $this->paymentStatusResponse($reservation, 'mismatch', null, $accessMode);
        }

        if ($normalized['payment_status'] === ReservationPayment::STATUS_PAID) {
            if (! $this->verifiedAmountMatchesPayment($reservation, $payment, $normalized)) {
                return $this->paymentStatusResponse($reservation, 'mismatch', 'The verified payment amount did not match the expected amount.', $accessMode);
            }

            $verification = DB::transaction(function () use ($reservation, $payment, $normalized) {
                $lockedReservation = Reservation::query()
                    ->whereKey($reservation->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedPayment = ReservationPayment::query()
                    ->whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $this->verifiedPaymentMayCompleteHold($lockedReservation, $normalized)) {
                    return ['accepted' => false, 'reconciliation_required' => true];
                }

                $lockedPayment->forceFill([
                    'payment_id' => $normalized['payment_id'] ?? $payment->payment_id,
                    'payment_method' => $normalized['payment_method'] ?? $payment->payment_method,
                    'paid_at' => $normalized['paid_at'] ?? $lockedPayment->paid_at ?? now(),
                    'status' => ReservationPayment::STATUS_PAID,
                    'payload' => $normalized['raw'],
                ])->save();

                $lockedReservation->loadPaymentSummary();

                if ($this->paymentConfirmsReservation($lockedReservation, $lockedPayment) && $lockedReservation->status !== Reservation::STATUS_CONFIRMED) {
                    $lockedReservation->forceFill([
                        'status' => Reservation::STATUS_CONFIRMED,
                    ])->save();
                }

                return ['accepted' => true, 'reconciliation_required' => false];
            });

            $reservation->refresh()->load(['payment', 'accommodation'])->loadPaymentSummary();

            if ($verification['reconciliation_required']) {
                return $this->paymentStatusResponse($reservation, 'reconciliation_required', 'PayMongo reports a successful payment after the reservation hold expired. Please contact the resort for review.', $accessMode);
            }

            return $this->paymentStatusResponse($reservation, 'paymongo', null, $accessMode);
        }

        if ($reservation->pendingHoldExpired()) {
            $reservation = DB::transaction(function () use ($reservation, $request) {
                $lockedReservation = Reservation::query()
                    ->whereKey($reservation->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedReservation->pendingHoldExpired()) {
                    $lockedReservation->forceFill(['status' => Reservation::STATUS_EXPIRED])->save();
                    app(AuditLogger::class)->log($request, 'booking_management', 'reservation_expired', 'Unpaid reservation hold expired during payment verification.', $lockedReservation, [
                        'reservation_id' => $lockedReservation->id,
                        'booking_reference' => $lockedReservation->booking_reference,
                        'expired_at' => $lockedReservation->expires_at?->toISOString(),
                    ]);
                }

                return $lockedReservation->fresh(['payment', 'accommodation'])->loadPaymentSummary();
            });

            return $this->paymentStatusResponse($reservation, 'expired', 'This reservation hold expired before payment was verified.', $accessMode);
        }

        if (
            in_array($normalized['payment_status'], [ReservationPayment::STATUS_FAILED, ReservationPayment::STATUS_CANCELLED], true)
            && $payment->status !== ReservationPayment::STATUS_PAID
        ) {
            DB::transaction(function () use ($payment, $normalized) {
                $payment->forceFill([
                    'payment_id' => $normalized['payment_id'] ?? $payment->payment_id,
                    'payment_method' => $normalized['payment_method'] ?? $payment->payment_method,
                    'status' => $normalized['payment_status'],
                    'payload' => $normalized['raw'],
                ])->save();
            });
        }

        return $this->paymentStatusResponse($reservation->fresh(['payment', 'accommodation'])->loadPaymentSummary(), 'paymongo', null, $accessMode);
    }

    public function webhook(Request $request, PayMongoService $payMongoService): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header('Paymongo-Signature') ?? $request->header('X-Paymongo-Signature');

        if (! $payMongoService->verifyWebhookSignature($rawBody, $signature)) {
            abort(401, 'Invalid webhook signature.');
        }

        $payload = $request->json()->all();
        $normalized = $payMongoService->normalizeWebhookPayload($payload);

        if (! $normalized['event_id'] || ! $normalized['event_type']) {
            return response()->json(['message' => 'Webhook ignored.']);
        }

        $webhookResult = DB::transaction(function () use ($normalized, $payload) {
            $inserted = DB::table('paymongo_webhook_events')->insertOrIgnore([
                'event_id' => $normalized['event_id'],
                'event_type' => $normalized['event_type'],
                'resource_id' => $normalized['resource_id'],
                'payload' => json_encode($payload),
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (! $inserted) {
                return ['duplicate' => true, 'reconciliation_required' => false, 'reservation_id' => null];
            }

            $reservation = Reservation::query()
                ->where('booking_reference', $normalized['reference_number'])
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                return ['duplicate' => false, 'reconciliation_required' => false, 'reservation_id' => null];
            }

            $payment = ReservationPayment::query()
                ->where('reservation_id', $reservation->id)
                ->where('checkout_session_id', $normalized['checkout_session_id'])
                ->lockForUpdate()
                ->first();

            $webhookStatus = $this->webhookStatusFromEventType($normalized['event_type']);
            $candidatePurpose = $payment?->purpose ?: ($normalized['payment_purpose'] ?? ReservationPayment::PURPOSE_FULL);
            $purpose = in_array($candidatePurpose, [
                ReservationPayment::PURPOSE_DEPOSIT,
                ReservationPayment::PURPOSE_FULL,
                ReservationPayment::PURPOSE_BALANCE,
            ], true) ? $candidatePurpose : ReservationPayment::PURPOSE_FULL;
            $expectedAmount = $payment?->amount ?? $reservation->expectedPaymentAmount($purpose);
            $amountMatches = $payment
                ? $this->verifiedAmountMatchesPayment($reservation, $payment, $normalized)
                : $this->verifiedAmountMatchesExpected($normalized, $expectedAmount, $purpose);

            if (! $payment) {
                $payment = ReservationPayment::create([
                    'reservation_id' => $reservation->id,
                    'provider' => 'paymongo',
                    'purpose' => $purpose,
                    'provider_reference' => $normalized['reference_number'],
                    'checkout_session_id' => $normalized['checkout_session_id'],
                    'payment_id' => $normalized['payment_id'],
                    'amount' => $normalized['amount'],
                    'currency' => $normalized['currency'],
                    'status' => $webhookStatus === ReservationPayment::STATUS_PAID
                        ? ReservationPayment::STATUS_PENDING
                        : $webhookStatus,
                    'payment_method' => $normalized['payment_method'],
                    'paid_at' => null,
                    'raw_reference' => $normalized['reference_number'],
                    'payload' => $normalized['payload'],
                ]);
            }

            $reconciliationRequired = false;
            $holdMayComplete = $this->verifiedPaymentMayCompleteHold($reservation, $normalized);

            if (
                $webhookStatus === ReservationPayment::STATUS_PAID
                && $amountMatches
                && $holdMayComplete
                && $payment->status !== ReservationPayment::STATUS_PAID
            ) {
                $payment->forceFill([
                    'payment_id' => $normalized['payment_id'] ?? $payment->payment_id,
                    'payment_method' => $normalized['payment_method'] ?? $payment->payment_method,
                    'paid_at' => $normalized['paid_at'] ?? now(),
                    'status' => ReservationPayment::STATUS_PAID,
                    'payload' => $normalized['payload'],
                ])->save();
            } elseif ($webhookStatus === ReservationPayment::STATUS_PAID && $amountMatches && ! $holdMayComplete) {
                $reconciliationRequired = true;
            } elseif (
                in_array($webhookStatus, [ReservationPayment::STATUS_FAILED, ReservationPayment::STATUS_CANCELLED], true)
                && $payment->status !== ReservationPayment::STATUS_PAID
            ) {
                $payment->forceFill([
                    'payment_id' => $normalized['payment_id'] ?? $payment->payment_id,
                    'payment_method' => $normalized['payment_method'] ?? $payment->payment_method,
                    'status' => $webhookStatus,
                    'payload' => $normalized['payload'],
                ])->save();
            }

            $reservation->loadPaymentSummary();

            if (
                $webhookStatus === ReservationPayment::STATUS_PAID
                && $amountMatches
                && $this->paymentConfirmsReservation($reservation, $payment)
                && $reservation->status !== Reservation::STATUS_CONFIRMED
            ) {
                $reservation->forceFill([
                    'status' => Reservation::STATUS_CONFIRMED,
                ])->save();
            }

            return [
                'duplicate' => false,
                'reconciliation_required' => $reconciliationRequired,
                'reservation_id' => $reservation->id,
            ];
        });

        if ($webhookResult['duplicate']) {
            return response()->json(['message' => 'Webhook already processed.']);
        }

        if ($webhookResult['reconciliation_required'] && $webhookResult['reservation_id']) {
            $reservation = Reservation::find($webhookResult['reservation_id']);
            app(AuditLogger::class)->log($request, 'booking_management', 'payment_reconciliation_required', 'PayMongo reported a successful payment after the reservation hold expired.', $reservation, [
                'booking_reference' => $reservation?->booking_reference,
                'reservation_id' => $reservation?->id,
                'payment_id' => $normalized['payment_id'],
            ]);
        }

        if (! $webhookResult['reconciliation_required'] && $webhookResult['reservation_id']) {
            $this->issueGuestConfirmationFor($webhookResult['reservation_id']);
        }

        return response()->json(['message' => 'Webhook processed.']);
    }

    private function paymentData(ReservationPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'reservation_id' => $payment->reservation_id,
            'provider' => $payment->provider,
            'purpose' => $payment->purpose,
            'provider_reference' => $payment->provider_reference,
            'checkout_session_id' => $payment->checkout_session_id,
            'checkout_url' => $payment->checkout_url,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'paid_at' => $payment->paid_at?->toISOString(),
        ];
    }

    private function authorizeReservationOwner(Request $request, Reservation $reservation): void
    {
        if ($reservation->user_id !== $request->user()->id) {
            abort(404);
        }
    }

    private function authorizePaymentAccess(Request $request, Reservation $reservation, string $accessMode, ?string $guestCheckoutToken, ?string $guestAccessToken = null): void
    {
        if ($accessMode === 'registered') {
            $this->authorizeReservationOwner($request, $reservation);
            return;
        }

        $token = $accessMode === 'permanent_guest' ? $guestAccessToken : $guestCheckoutToken;
        $hash = $token ? hash('sha256', $token) : null;
        $storedHash = $accessMode === 'permanent_guest'
            ? $reservation->guest_access_token_hash
            : $reservation->guest_checkout_token_hash;
        $notRevoked = $accessMode !== 'permanent_guest' || ! $reservation->guest_access_token_revoked_at;
        $notExpired = $accessMode === 'permanent_guest'
            ? true
            : $reservation->guest_checkout_token_expires_at?->isFuture();
        $valid = $reservation->user_id === null
            && $storedHash
            && $notExpired
            && $notRevoked
            && $hash
            && hash_equals($storedHash, $hash);

        if (! $valid) {
            abort(401, 'Guest credential is missing or invalid.');
        }
    }

    private function resolvePermanentGuestReservation(Request $request): Reservation
    {
        $token = $request->header('X-Guest-Access-Token');
        if (! is_string($token) || strlen($token) !== 64) {
            abort(401, 'Guest credential is missing or invalid.');
        }

        $reservation = Reservation::query()
            ->whereNull('user_id')
            ->where('guest_access_token_hash', hash('sha256', $token))
            ->whereNull('guest_access_token_revoked_at')
            ->with(['accommodation', 'payment'])
            ->first();

        if (! $reservation) {
            abort(401, 'Guest credential is missing or invalid.');
        }

        return $reservation;
    }

    private function ensureGuestAccessToken(Reservation $reservation, bool $forConfirmation = false): array
    {
        if (
            $reservation->user_id !== null
            || $reservation->status !== Reservation::STATUS_CONFIRMED
        ) {
            return [$reservation, null];
        }

        $confirmationAlreadyHandled = $reservation->guest_confirmation_email_sent_at
            || in_array($reservation->guest_confirmation_email_status, ['sent', 'sending', 'failed'], true);

        if (
            $reservation->guest_access_token_hash
            && ! $reservation->guest_access_token_revoked_at
            && (! $forConfirmation || $confirmationAlreadyHandled)
        ) {
            return [$reservation, null];
        }

        $rawToken = Str::random(64);
        $reservation->forceFill([
            'guest_access_token_hash' => hash('sha256', $rawToken),
            'guest_access_token_issued_at' => now(),
            'guest_access_token_revoked_at' => null,
            ...($forConfirmation ? [
                'guest_confirmation_email_status' => 'sending',
                'guest_confirmation_email_sent_at' => null,
                'guest_confirmation_email_failed_at' => null,
            ] : []),
        ])->save();

        return [$reservation->fresh(['payment', 'accommodation'])->loadPaymentSummary(), $rawToken];
    }

    private function paymentStatusResponse(Reservation $reservation, string $source, ?string $message, string $accessMode): JsonResponse
    {
        $guestAccessToken = null;
        $qrToken = null;
        $guestConfirmationEmailStatus = null;

        if ($accessMode === 'temporary_guest' || $accessMode === 'permanent_guest') {
            [$reservation, $guestAccessToken, $qrToken] = DB::transaction(function () use ($reservation) {
                $lockedReservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
                [$lockedReservation, $guestAccessToken] = $this->ensureGuestAccessToken($lockedReservation, true);
                $issuedQr = $guestAccessToken
                    ? app(\App\Services\ReservationQrService::class)
                        ->issueForGuestAccess($lockedReservation, $guestAccessToken)
                    : [
                        'reservation' => $lockedReservation->fresh(['payment', 'accommodation'])->loadPaymentSummary(),
                        'token' => null,
                    ];

                return [$issuedQr['reservation'], $guestAccessToken, $issuedQr['token']];
            });

            if ($guestAccessToken) {
                $guestConfirmationEmailStatus = app(GuestBookingConfirmationService::class)
                    ->sendIfNeeded($reservation, $guestAccessToken, $qrToken);
            } else {
                $guestConfirmationEmailStatus = $reservation->guest_confirmation_email_status;
            }
        }

        if ($reservation->status === Reservation::STATUS_CONFIRMED && ! $qrToken) {
            DB::transaction(function () use ($reservation) {
                $lockedReservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
                app(\App\Services\ReservationQrService::class)->issue($lockedReservation);
            });
            $reservation->refresh()->load(['payment', 'accommodation'])->loadPaymentSummary();
        }

        $payload = [
            'data' => $this->paymentStatusData($reservation, $source, $message),
        ];

        if ($guestAccessToken) {
            $payload['guest_access_token'] = $guestAccessToken;
        }

        if ($guestConfirmationEmailStatus) {
            $payload['guest_confirmation_email_status'] = $guestConfirmationEmailStatus;
        }

        return response()->json($payload);
    }

    private function issueGuestConfirmationFor(int $reservationId): void
    {
        [$reservation, $rawToken, $qrToken] = DB::transaction(function () use ($reservationId) {
            $lockedReservation = Reservation::query()
                ->whereKey($reservationId)
                ->lockForUpdate()
                ->first();

            if (! $lockedReservation) {
                return [null, null, null];
            }

            // Registered customers already have their reservation history in
            // the authenticated account. Guest access credentials and the
            // guest confirmation email apply only to anonymous bookings.
            if ($lockedReservation->user_id !== null) {
                return [$lockedReservation, null, null];
            }

            [$reservation, $rawToken] = $this->ensureGuestAccessToken($lockedReservation, true);
            if (! $rawToken) {
                return [$reservation, null, null];
            }

            $qr = app(ReservationQrService::class)
                ->issueForGuestAccess($reservation, $rawToken);

            return [$qr['reservation'], $rawToken, $qr['token']];
        });

        if ($reservation && $rawToken) {
            app(GuestBookingConfirmationService::class)
                ->sendIfNeeded($reservation, $rawToken, $qrToken);
        }
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function verifiedAmountMatchesPayment(Reservation $reservation, ReservationPayment $payment, array $normalized): bool
    {
        if (! in_array($payment->purpose, [
            ReservationPayment::PURPOSE_DEPOSIT,
            ReservationPayment::PURPOSE_FULL,
            ReservationPayment::PURPOSE_BALANCE,
        ], true)) {
            return false;
        }

        return $payment->checkout_session_id === ($normalized['checkout_session_id'] ?? null)
            && (int) $payment->amount === $reservation->expectedPaymentAmount($payment->purpose)
            && $this->verifiedAmountMatchesExpected($normalized, $reservation->expectedPaymentAmount($payment->purpose), $payment->purpose)
            && strtoupper((string) ($payment->currency ?: 'PHP')) === strtoupper((string) ($normalized['currency'] ?? ''))
            && (! ($normalized['payment_purpose'] ?? null) || $normalized['payment_purpose'] === $payment->purpose);
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function verifiedAmountMatchesExpected(array $normalized, int $expectedAmount, string $purpose = ReservationPayment::PURPOSE_FULL): bool
    {
        return strtoupper((string) ($normalized['currency'] ?? '')) === 'PHP'
            && (int) ($normalized['amount'] ?? -1) === $expectedAmount
            && (! ($normalized['payment_purpose'] ?? null) || $normalized['payment_purpose'] === $purpose);
    }

    /**
     * A provider payment completed before the hold deadline may finish the
     * reservation even if the cleanup command won the race and marked it
     * expired first. A payment with no trustworthy provider timestamp is not
     * accepted after expiry.
     *
     * @param array<string, mixed> $normalized
     */
    private function verifiedPaymentMayCompleteHold(Reservation $reservation, array $normalized): bool
    {
        if ($reservation->status === Reservation::STATUS_CANCELLED) {
            return false;
        }

        if ($reservation->status !== Reservation::STATUS_EXPIRED && ! $reservation->pendingHoldExpired()) {
            return true;
        }

        if (! $reservation->expires_at || empty($normalized['paid_at'])) {
            return false;
        }

        try {
            return CarbonImmutable::parse((string) $normalized['paid_at'], config('app.timezone'))
                ->lte($reservation->expires_at);
        } catch (\Throwable) {
            return false;
        }
    }

    private function paymentConfirmsReservation(Reservation $reservation, ReservationPayment $payment): bool
    {
        $reservation->loadPaymentSummary();

        if ($payment->purpose === ReservationPayment::PURPOSE_DEPOSIT) {
            return $payment->amount === $reservation->expectedPaymentAmount(ReservationPayment::PURPOSE_DEPOSIT)
                && in_array($reservation->paymentState(), ['partially_paid', 'fully_paid'], true);
        }

        if ($payment->purpose === ReservationPayment::PURPOSE_BALANCE) {
            return $payment->amount > 0
                && $reservation->paymentState() === 'fully_paid';
        }

        return $payment->purpose === ReservationPayment::PURPOSE_FULL
            && $reservation->paymentState() === 'fully_paid';
    }

    private function webhookStatusFromEventType(?string $eventType): string
    {
        $eventType = strtolower((string) $eventType);

        if (str_contains($eventType, 'failed')) {
            return ReservationPayment::STATUS_FAILED;
        }

        if (str_contains($eventType, 'cancel')) {
            return ReservationPayment::STATUS_CANCELLED;
        }

        return ReservationPayment::STATUS_PAID;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentStatusData(Reservation $reservation, string $source, ?string $message = null): array
    {
        $reservation->loadMissing(['payment', 'accommodation']);

        return [
            'reservation' => $reservation->publicData(),
            'payment_status' => $reservation->payment?->status ?? ReservationPayment::STATUS_PENDING,
            'payment_purpose' => $reservation->payment?->purpose,
            'total_paid' => $reservation->paymentSummary()['total_paid'],
            'balance_due' => $reservation->paymentSummary()['balance_due'],
            'payment_state' => $reservation->paymentSummary()['payment_state'],
            'reservation_status' => $reservation->status,
            'paid_at' => $reservation->payment?->paid_at?->toISOString(),
            'source' => $source,
            'message' => $message,
        ];
    }
}
