<?php

namespace App\Http\Controllers\Api\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function store(Request $request, Reservation $reservation): JsonResponse
    {
        $attributes = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'payment_method' => ['required', Rule::in(['cash'])],
        ]);

        [$reservation, $payment] = DB::transaction(function () use ($attributes, $request, $reservation) {
            $reservation = Reservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $reservation->loadPaymentSummary();

            if (
                $reservation->status !== Reservation::STATUS_CONFIRMED
                || ! $reservation->hasValidInitialPayment()
                || $reservation->paymentState() !== 'partially_paid'
                || $reservation->balanceDueMinor() <= 0
            ) {
                throw ValidationException::withMessages([
                    'reservation' => ['This reservation is not eligible for a Front Desk balance payment.'],
                ]);
            }

            $amountMinor = Reservation::currencyToMinorUnits($attributes['amount']);
            $balanceMinor = $reservation->balanceDueMinor();

            if ($amountMinor <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['The payment amount must be greater than zero.'],
                ]);
            }

            if ($amountMinor > $balanceMinor) {
                throw ValidationException::withMessages([
                    'amount' => ['The payment amount cannot exceed the current remaining balance.'],
                ]);
            }

            $payment = ReservationPayment::create([
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_BALANCE,
                'provider' => 'manual',
                'provider_reference' => 'frontdesk-'.$reservation->booking_reference.'-'.now()->format('YmdHisv'),
                'amount' => $amountMinor,
                'currency' => 'PHP',
                'status' => ReservationPayment::STATUS_PAID,
                'payment_method' => $attributes['payment_method'],
                'recorded_by_user_id' => $request->user()->id,
                'paid_at' => now(),
            ]);

            return [$reservation->fresh(['user', 'accommodation']), $payment->fresh(['recordedBy', 'reservation'])];
        });

        app(AuditLogger::class)->log($request, 'booking_management', 'front_desk_payment_recorded', 'Front Desk recorded an in-person reservation payment.', $payment, [
            'booking_reference' => $reservation->booking_reference,
            'reservation_id' => $reservation->id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'payment_method' => $payment->payment_method,
        ]);

        $reservation->loadPaymentSummary();

        return response()->json([
            'data' => [
                'payment' => $payment->staffData(),
                'reservation' => array_merge($reservation->publicData(), [
                    'payment_history' => $reservation->staffPaymentHistory(),
                ]),
            ],
            'message' => 'Payment recorded successfully.',
        ], 201);
    }
}
