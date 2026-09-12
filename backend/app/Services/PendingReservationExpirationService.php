<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PendingReservationExpirationService
{
    public function expire(?CarbonImmutable $now = null, int $batchSize = 100): int
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));
        $expired = 0;

        Reservation::query()
            ->where('status', Reservation::STATUS_PENDING)
            ->where('expires_at', '<=', $now)
            ->whereDoesntHave('payments', fn ($query) => $query->where('status', ReservationPayment::STATUS_PAID))
            ->orderBy('id')
            ->chunkById($batchSize, function ($reservations) use ($now, &$expired) {
                foreach ($reservations as $candidate) {
                    $didExpire = DB::transaction(function () use ($candidate, $now) {
                        $reservation = Reservation::query()
                            ->whereKey($candidate->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $reservation || ! $reservation->pendingHoldExpired($now)) {
                            return false;
                        }

                        $reservation->forceFill([
                            'status' => Reservation::STATUS_EXPIRED,
                        ])->save();

                        app(AuditLogger::class)->log(null, 'booking_management', 'reservation_expired', 'Unpaid reservation hold expired.', $reservation, [
                            'reservation_id' => $reservation->id,
                            'booking_reference' => $reservation->booking_reference,
                            'expired_at' => $reservation->expires_at?->toISOString(),
                        ]);

                        return true;
                    });

                    if ($didExpire) {
                        $expired++;
                    }
                }
            });

        return $expired;
    }
}
