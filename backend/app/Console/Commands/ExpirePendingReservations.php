<?php

namespace App\Console\Commands;

use App\Services\PendingReservationExpirationService;
use Illuminate\Console\Command;

class ExpirePendingReservations extends Command
{
    protected $signature = 'reservations:expire-pending';

    protected $description = 'Expire unpaid pending reservation holds whose payment deadline has passed.';

    public function handle(PendingReservationExpirationService $expirationService): int
    {
        $expired = $expirationService->expire();

        $this->info("Expired {$expired} pending reservation hold(s).");

        return self::SUCCESS;
    }
}
