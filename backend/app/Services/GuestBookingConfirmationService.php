<?php

namespace App\Services;

use App\Mail\GuestBookingConfirmationMail;
use App\Models\Reservation;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class GuestBookingConfirmationService
{
    public function resendForPaidGuest(Reservation $reservation): string
    {
        [$reservation, $guestAccessToken, $qrToken, $status] = DB::transaction(function () use ($reservation) {
            $lockedReservation = Reservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedReservation->user_id !== null
                || $lockedReservation->status !== Reservation::STATUS_CONFIRMED
                || ! $lockedReservation->guest_email
            ) {
                return [$lockedReservation, null, null, 'not_sent'];
            }

            if ($lockedReservation->guest_confirmation_email_status === 'sent'
                || $lockedReservation->guest_confirmation_email_sent_at) {
                return [$lockedReservation, null, null, 'sent'];
            }

            // A concurrent sender owns this delivery attempt. Do not create a
            // second email while its synchronous SMTP call is in progress.
            if ($lockedReservation->guest_confirmation_email_status === 'sending') {
                return [$lockedReservation, null, null, 'sending'];
            }

            $lockedReservation->loadPaymentSummary();
            if ($lockedReservation->paymentState() === 'unpaid') {
                return [$lockedReservation, null, null, 'not_sent'];
            }

            $guestAccessToken = Str::random(64);
            $lockedReservation->forceFill([
                'guest_access_token_hash' => hash('sha256', $guestAccessToken),
                'guest_access_token_issued_at' => now(),
                'guest_access_token_revoked_at' => null,
                'guest_confirmation_email_status' => 'sending',
                'guest_confirmation_email_sent_at' => null,
                'guest_confirmation_email_failed_at' => null,
            ])->save();

            $issuedQr = app(ReservationQrService::class)
                ->issueForGuestAccess($lockedReservation, $guestAccessToken);

            return [$issuedQr['reservation'], $guestAccessToken, $issuedQr['token'], null];
        });

        if (! $guestAccessToken) {
            return $status;
        }

        return $this->sendIfNeeded($reservation, $guestAccessToken, $qrToken);
    }

    public function sendIfNeeded(Reservation $reservation, string $guestAccessToken, ?string $qrToken = null): string
    {
        if (
            $reservation->user_id !== null
            || $reservation->status !== Reservation::STATUS_CONFIRMED
            || ! $reservation->guest_email
        ) {
            return 'not_sent';
        }

        if ($reservation->guest_confirmation_email_status === 'sent' || $reservation->guest_confirmation_email_sent_at) {
            return 'sent';
        }

        $reservation->loadMissing('accommodation');
        $reservation->loadPaymentSummary();

        // Keep the email QR synchronized with the token used by the guest
        // booking page. The QR credential remains opaque and only its hash is
        // persisted; callers cannot accidentally send a stale random token.
        $issuedQr = app(ReservationQrService::class)->issueForGuestAccess($reservation, $guestAccessToken);
        $reservation = $issuedQr['reservation'];
        $qrToken = $issuedQr['token'];

        $booking = $this->bookingData($reservation);
        $bookingUrl = rtrim((string) config('app.frontend_url'), '/').'/guest/booking/'.$guestAccessToken;
        $booking['qr_image'] = $this->bookingQrImage($qrToken);

        try {
            // Send after the payment transaction has committed. The raw token is
            // available only in memory here and is never persisted or logged.
            Mail::to($reservation->guest_email)->send(new GuestBookingConfirmationMail($booking, $bookingUrl));
        } catch (Throwable $exception) {
            $reservation->forceFill([
                'guest_confirmation_email_status' => 'failed',
                'guest_confirmation_email_failed_at' => now(),
            ])->save();

            Log::error('Guest booking confirmation delivery failed.', [
                'reservation_id' => $reservation->id,
                'booking_reference' => $reservation->booking_reference,
                'exception' => $exception::class,
            ]);

            return 'failed';
        }

        $reservation->forceFill([
            'guest_confirmation_email_status' => 'sent',
            'guest_confirmation_email_sent_at' => now(),
            'guest_confirmation_email_failed_at' => null,
        ])->save();

        return 'sent';
    }

    /**
     * @return array<string, string|int|null>
     */
    private function bookingData(Reservation $reservation): array
    {
        $timezone = config('app.timezone');
        $paymentState = $reservation->paymentState();

        return [
            'first_name' => $reservation->guest_first_name,
            'booking_reference' => $reservation->booking_reference,
            'accommodation' => $reservation->accommodation?->name ?? 'DMD Family Resort accommodation',
            'check_in' => $reservation->check_in_at?->copy()->timezone($timezone)->format('F j, Y — g:i A')
                ?? $reservation->check_in?->format('F j, Y'),
            'check_out' => $reservation->check_out_at?->copy()->timezone($timezone)->format('F j, Y — g:i A')
                ?? $reservation->check_out?->format('F j, Y'),
            'guests' => (int) ($reservation->guests ?? 0),
            'total' => '₱'.Reservation::minorUnitsToCurrency($reservation->totalAmountMinor()),
            'paid' => '₱'.Reservation::minorUnitsToCurrency($reservation->totalPaidMinor()),
            'balance' => '₱'.Reservation::minorUnitsToCurrency($reservation->balanceDueMinor()),
            'payment_status' => match ($paymentState) {
                'partially_paid' => 'Partially Paid',
                'fully_paid' => 'Fully Paid',
                default => 'Unpaid',
            },
            'payment_message' => $paymentState === 'partially_paid'
                ? 'Your reservation is confirmed. You may pay the remaining balance online through your secure booking page or at the Front Desk.'
                : 'Your reservation is fully paid.',
        ];
    }

    private function bookingQrImage(?string $qrToken): string
    {
        if (! $qrToken) {
            throw new \RuntimeException('A QR token is required before sending a booking confirmation.');
        }

        $payload = app(ReservationQrService::class)->payload($qrToken);

        return (new Builder(
            writer: new PngWriter(),
            data: $payload,
            encoding: new Encoding('ISO-8859-1'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 320,
            margin: 16,
        ))->build()->getString();
    }
}
