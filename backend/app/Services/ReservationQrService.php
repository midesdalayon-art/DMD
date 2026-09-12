<?php

namespace App\Services;

use App\Models\Reservation;
use Illuminate\Support\Str;

class ReservationQrService
{
    /**
     * Issue or rotate the opaque QR credential.
     *
     * The raw value is returned only to the caller that needs to construct
     * the verification payload. PostgreSQL stores only its SHA-256 hash.
     *
     * @return array{reservation: Reservation, token: string|null}
     */
    public function issue(Reservation $reservation, bool $rotate = false): array
    {
        if ($reservation->status !== Reservation::STATUS_CONFIRMED) {
            return ['reservation' => $reservation, 'token' => null];
        }

        if (
            ! $rotate
            && $reservation->qr_token_hash
            && ! $reservation->qr_token_revoked_at
        ) {
            return ['reservation' => $reservation, 'token' => null];
        }

        $token = Str::random(64);
        $reservation->forceFill([
            'qr_token_hash' => hash('sha256', $token),
            'qr_token_issued_at' => now(),
            'qr_token_revoked_at' => null,
        ])->save();

        return ['reservation' => $reservation->fresh(['accommodation', 'payment'])->loadPaymentSummary(), 'token' => $token];
    }

    /**
     * Return the stable QR credential for a guest management session.
     *
     * The management credential is never placed in the QR payload. HMAC
     * derivation produces a separate opaque value, while only its hash is
     * persisted for staff verification.
     *
     * @return array{reservation: Reservation, token: string|null}
     */
    public function issueForGuestAccess(Reservation $reservation, string $guestAccessToken): array
    {
        if ($reservation->status !== Reservation::STATUS_CONFIRMED) {
            return ['reservation' => $reservation, 'token' => null];
        }

        $token = hash_hmac('sha256', $guestAccessToken, (string) config('app.key'));
        $tokenHash = hash('sha256', $token);

        if ($reservation->qr_token_hash !== $tokenHash || $reservation->qr_token_revoked_at) {
            $reservation->forceFill([
                'qr_token_hash' => $tokenHash,
                'qr_token_issued_at' => now(),
                'qr_token_revoked_at' => null,
            ])->save();
        }

        return [
            'reservation' => $reservation->fresh(['accommodation', 'payment'])->loadPaymentSummary(),
            'token' => $token,
        ];
    }

    public function payload(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/verify-booking/'.$token;
    }

    public function extractToken(string $payload): ?string
    {
        $payload = trim($payload);

        if (str_contains($payload, '%')) {
            $payload = rawurldecode($payload);
        }

        if (preg_match('/^[A-Za-z0-9]{64}$/', $payload) === 1) {
            return $payload;
        }

        if (preg_match('~^/?verify-booking/([A-Za-z0-9]{64})/?$~', $payload, $matches) === 1) {
            return $matches[1];
        }

        $url = filter_var($payload, FILTER_VALIDATE_URL);
        if (! $url) {
            return null;
        }

        $path = trim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/');
        $parts = explode('/', $path);

        if (count($parts) < 2 || $parts[count($parts) - 2] !== 'verify-booking') {
            return null;
        }

        $token = end($parts);

        return is_string($token) && preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1
            ? $token
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function staffVerificationData(Reservation $reservation): array
    {
        $reservation->loadMissing(['user', 'accommodation'])->loadPaymentSummary();
        $guest = $reservation->user
            ? [
                'name' => $reservation->user->name,
                'email' => $reservation->user->email,
            ]
            : [
                'name' => trim($reservation->guest_first_name.' '.$reservation->guest_last_name),
                'email' => $reservation->guest_email,
            ];

        $paymentState = $reservation->paymentState();
        $checkInEligible = $reservation->status === Reservation::STATUS_CONFIRMED
            && $paymentState === 'fully_paid'
            && $reservation->balanceDueMinor() <= 0;

        return [
            'qr_valid' => true,
            'reservation_id' => $reservation->id,
            'booking_reference' => $reservation->booking_reference,
            'primary_guest' => $guest,
            'accommodation' => [
                'name' => $reservation->accommodation?->name,
                'type' => $reservation->accommodation?->type,
            ],
            'guests' => $reservation->guests,
            'check_in' => $reservation->check_in?->format('Y-m-d'),
            'check_out' => $reservation->check_out?->format('Y-m-d'),
            'check_in_at' => $reservation->check_in_at?->toISOString(),
            'check_out_at' => $reservation->check_out_at?->toISOString(),
            'reservation_status' => $reservation->status,
            'payment_state' => $paymentState,
            'total_amount' => $reservation->publicData()['total_amount'],
            'total_paid' => $reservation->paymentSummary()['total_paid'],
            'balance_due' => $reservation->paymentSummary()['balance_due'],
            'check_in_eligible' => $checkInEligible,
            'check_in_block_reason' => $this->checkInBlockReason($reservation, $checkInEligible),
            'checked_in_at' => $reservation->status === Reservation::STATUS_CHECKED_IN
                ? $reservation->updated_at?->toISOString()
                : null,
        ];
    }

    private function checkInBlockReason(Reservation $reservation, bool $eligible): ?string
    {
        if ($eligible) {
            return null;
        }

        return match ($reservation->status) {
            Reservation::STATUS_CHECKED_IN => 'This reservation has already been checked in.',
            Reservation::STATUS_CANCELLED => 'Cancelled reservations cannot be checked in.',
            Reservation::STATUS_EXPIRED => 'Expired reservations cannot be checked in.',
            Reservation::STATUS_CONFIRMED => $reservation->balanceDueMinor() > 0
                ? 'The remaining balance must be settled before check-in.'
                : 'This reservation is not eligible for check-in.',
            default => 'This reservation is not eligible for check-in.',
        };
    }
}
