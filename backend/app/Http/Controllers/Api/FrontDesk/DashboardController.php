<?php

namespace App\Http\Controllers\Api\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        $today = today(config('app.timezone'));

        $occupiedReservationIds = Reservation::query()
            ->whereIn('status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_CHECKED_IN])
            ->whereDate('check_in', '<=', $today)
            ->whereDate('check_out', '>', $today)
            ->pluck('accommodation_id')
            ->unique();

        $todayArrivals = Reservation::query()
            ->with(['user', 'accommodation'])
            ->withPaymentSummary()
            ->whereDate('check_in', $today)
            ->where('status', Reservation::STATUS_CONFIRMED)
            ->orderBy('check_in')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Reservation $reservation) => $this->reservationData($reservation))
            ->values();

        $todayDepartures = Reservation::query()
            ->with(['user', 'accommodation'])
            ->withPaymentSummary()
            ->whereDate('check_out', $today)
            ->where('status', Reservation::STATUS_CHECKED_IN)
            ->orderBy('check_out')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Reservation $reservation) => $this->reservationData($reservation))
            ->values();

        $recentPendingBookings = Reservation::query()
            ->with(['user', 'accommodation'])
            ->withPaymentSummary()
            ->where(function ($query) {
                $query
                    ->whereIn('status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_CHECKED_IN, Reservation::STATUS_CHECKED_OUT])
                    ->orWhere(function ($pendingQuery) {
                        Reservation::applyPendingHoldFilter($pendingQuery);
                    });
            })
            ->orderByRaw("CASE status
                WHEN 'pending' THEN 1
                WHEN 'checked_in' THEN 2
                WHEN 'confirmed' THEN 3
                WHEN 'checked_out' THEN 4
                ELSE 5 END")
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->map(fn (Reservation $reservation) => $this->reservationData($reservation))
            ->values();

        return response()->json([
            'data' => [
                'summary' => [
                    'todays_check_ins' => Reservation::whereDate('check_in', $today)
                    ->where('status', Reservation::STATUS_CONFIRMED)
                        ->count(),
                    'todays_check_outs' => Reservation::whereDate('check_out', $today)
                        ->whereIn('status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_CHECKED_IN])
                        ->count(),
                    'pending_bookings' => Reservation::applyPendingHoldFilter(Reservation::query())->count(),
                    'available_accommodations' => Accommodation::where('status', Accommodation::STATUS_AVAILABLE)
                        ->where('housekeeping_status', Accommodation::HOUSEKEEPING_READY)
                        ->whereNotIn('id', $occupiedReservationIds)
                        ->count(),
                    'occupied_accommodations' => $occupiedReservationIds->count(),
                    'guests_checked_in' => Reservation::where('status', Reservation::STATUS_CHECKED_IN)
                        ->whereDate('check_in', '<=', $today)
                        ->whereDate('check_out', '>', $today)
                        ->count(),
                ],
                'today_arrivals' => $todayArrivals,
                'today_departures' => $todayDepartures,
                'recent_pending_bookings' => $recentPendingBookings,
                'accommodation_status' => [
                    'available' => Accommodation::where('status', Accommodation::STATUS_AVAILABLE)
                        ->where('housekeeping_status', Accommodation::HOUSEKEEPING_READY)
                        ->whereNotIn('id', $occupiedReservationIds)
                        ->count(),
                    'unavailable' => Accommodation::where('status', Accommodation::STATUS_UNAVAILABLE)->count(),
                    'maintenance' => Accommodation::where('status', Accommodation::STATUS_MAINTENANCE)->count(),
                    'needs_cleaning' => Accommodation::where('housekeeping_status', Accommodation::HOUSEKEEPING_NEEDS_CLEANING)->count(),
                    'cleaning' => Accommodation::where('housekeeping_status', Accommodation::HOUSEKEEPING_CLEANING)->count(),
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reservationData(Reservation $reservation): array
    {
        $reservation->loadMissing(['user', 'accommodation']);

        return array_merge($reservation->publicData(), [
            'guest_name' => $reservation->user?->name ?? 'Guest',
            'guest_email' => $reservation->user?->email,
            'accommodation_name' => $reservation->accommodation?->name ?? 'Accommodation',
        ]);
    }
}
