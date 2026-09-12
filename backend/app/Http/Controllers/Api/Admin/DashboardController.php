<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\HousekeepingTask;
use App\Models\InventoryAsset;
use App\Models\Reservation;
use App\Services\FinancialReportingService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function summary(FinancialReportingService $financialReporting): JsonResponse
    {
        $now = now();

        return response()->json([
            'data' => [
                'total_accommodations' => Accommodation::count(),
                'total_reservations' => Reservation::count(),
                'pending_reservations' => Reservation::applyPendingHoldFilter(Reservation::query())->count(),
                'available_accommodations' => Accommodation::where('status', Accommodation::STATUS_AVAILABLE)->count(),
                'housekeeping_tasks' => HousekeepingTask::whereIn('status', [
                    HousekeepingTask::STATUS_PENDING,
                    HousekeepingTask::STATUS_ASSIGNED,
                    HousekeepingTask::STATUS_IN_PROGRESS,
                ])->count(),
                'attendance_today' => AttendanceRecord::whereDate('attendance_date', today())->count(),
                'assets_in_maintenance' => InventoryAsset::where('status', InventoryAsset::STATUS_MAINTENANCE)->sum('quantity'),
                'active_announcements' => Announcement::where('status', Announcement::STATUS_PUBLISHED)
                    ->where(function ($query) use ($now) {
                        $query->whereNull('publish_at')->orWhere('publish_at', '<=', $now);
                    })
                    ->where(function ($query) use ($now) {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                    })
                    ->count(),
                'financial' => $financialReporting->summary(),
            ],
        ]);
    }
}
