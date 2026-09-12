<?php

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index()->after('status');
        });

        $holdMinutes = (int) config('reservations.payment_hold_minutes', 15);
        $timezone = config('app.timezone');

        DB::table('reservations')
            ->where('status', Reservation::STATUS_PENDING)
            ->whereNull('expires_at')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('reservation_payments')
                    ->whereColumn('reservation_payments.reservation_id', 'reservations.id')
                    ->where('reservation_payments.status', 'paid');
            })
            ->orderBy('id')
            ->get(['id', 'created_at'])
            ->each(function (object $reservation) use ($holdMinutes, $timezone) {
                $createdAt = CarbonImmutable::parse($reservation->created_at, $timezone);

                DB::table('reservations')
                    ->where('id', $reservation->id)
                    ->update(['expires_at' => $createdAt->addMinutes($holdMinutes)]);
            });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
