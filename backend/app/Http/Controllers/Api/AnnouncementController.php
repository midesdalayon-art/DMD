<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;

class AnnouncementController extends Controller
{
    public function index(): JsonResponse
    {
        $now = now();

        $announcements = Announcement::query()
            ->where('status', Announcement::STATUS_PUBLISHED)
            ->whereIn('audience', [Announcement::AUDIENCE_EVERYONE, Announcement::AUDIENCE_GUESTS])
            ->where(function ($query) use ($now) {
                $query->whereNull('publish_at')->orWhere('publish_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->latest('publish_at')
            ->latest()
            ->get();

        return response()->json([
            'data' => $announcements->map(fn (Announcement $announcement) => $announcement->publicData())->values(),
        ]);
    }
}
