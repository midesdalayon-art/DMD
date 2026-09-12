<?php

namespace App\Http\Controllers\Api\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'type' => ['sometimes', 'nullable', Rule::in(Announcement::types())],
        ]);

        $query = Announcement::query()
            ->with('creator')
            ->where('status', Announcement::STATUS_PUBLISHED)
            ->whereIn('audience', [Announcement::AUDIENCE_EVERYONE, Announcement::AUDIENCE_STAFF])
            ->where(fn ($query) => $query->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('publish_at')
            ->latest('updated_at');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(fn ($query) => $query
                ->whereRaw('LOWER(title) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(content) LIKE ?', ["%{$search}%"]));
        }

        if ($type = $filters['type'] ?? null) {
            $query->where('type', $type);
        }

        $announcements = $query->get();
        $visibleCount = $announcements->count();
        $recentCount = $announcements->filter(fn (Announcement $announcement) => $announcement->publish_at && $announcement->publish_at->gte(now()->subDays(7)))->count();

        return response()->json([
            'data' => $announcements->map(fn (Announcement $announcement) => $this->announcementData($announcement))->values(),
            'summary' => [
                'active_announcements' => $visibleCount,
                'recent_announcements' => $recentCount,
                'staff_notices' => $announcements->where('type', Announcement::TYPE_STAFF_NOTICE)->count(),
            ],
            'meta' => [
                'types' => Announcement::types(),
                'statuses' => [Announcement::STATUS_PUBLISHED],
                'audiences' => [Announcement::AUDIENCE_EVERYONE, Announcement::AUDIENCE_STAFF],
            ],
        ]);
    }

    public function show(Announcement $announcement): JsonResponse
    {
        abort_unless($this->isVisibleToFrontDesk($announcement), 404);

        return response()->json([
            'data' => $this->announcementData($announcement->load('creator')),
            'meta' => [
                'types' => Announcement::types(),
                'audiences' => [Announcement::AUDIENCE_EVERYONE, Announcement::AUDIENCE_STAFF],
            ],
        ]);
    }

    private function announcementData(Announcement $announcement): array
    {
        return array_merge($announcement->loadMissing('creator')->publicData(true), [
            'created_by_name' => $announcement->creator?->name,
            'preview' => str($announcement->content)->limit(180),
            'is_visible_to_front_desk' => $this->isVisibleToFrontDesk($announcement),
        ]);
    }

    private function isVisibleToFrontDesk(Announcement $announcement): bool
    {
        return $announcement->status === Announcement::STATUS_PUBLISHED
            && in_array($announcement->audience, [Announcement::AUDIENCE_EVERYONE, Announcement::AUDIENCE_STAFF], true)
            && (! $announcement->publish_at || $announcement->publish_at->lte(now()))
            && (! $announcement->expires_at || $announcement->expires_at->gt(now()));
    }
}
