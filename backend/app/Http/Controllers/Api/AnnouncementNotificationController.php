<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $this->visibleQuery($user)->with([
            'reads' => fn ($query) => $query->where('user_id', $user->id),
        ])->latest('publish_at')->latest('updated_at');

        $paginator = $query->paginate(20);
        $unreadCount = $this->visibleQuery($user)
            ->whereDoesntHave('reads', fn ($query) => $query->where('user_id', $user->id))
            ->count();

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Announcement $announcement) => $this->notificationData($announcement))->values(),
            'unread_count' => $unreadCount,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function markRead(Request $request, Announcement $announcement): JsonResponse
    {
        $this->visibleQuery($request->user())->whereKey($announcement->id)->firstOrFail();

        AnnouncementRead::updateOrCreate(
            [
                'announcement_id' => $announcement->id,
                'user_id' => $request->user()->id,
            ],
            ['read_at' => now()],
        );

        return response()->json([
            'message' => 'Announcement marked as read.',
            'unread_count' => $this->unreadCount($request->user()),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $announcementIds = $this->visibleQuery($user)->pluck('id');
        $now = now();

        AnnouncementRead::upsert(
            $announcementIds->map(fn ($id) => [
                'announcement_id' => $id,
                'user_id' => $user->id,
                'read_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['announcement_id', 'user_id'],
            ['read_at', 'updated_at'],
        );

        return response()->json([
            'message' => 'Announcements marked as read.',
            'unread_count' => 0,
        ]);
    }

    private function unreadCount(User $user): int
    {
        return $this->visibleQuery($user)
            ->whereDoesntHave('reads', fn ($query) => $query->where('user_id', $user->id))
            ->count();
    }

    private function visibleQuery(User $user)
    {
        $audiences = [Announcement::AUDIENCE_EVERYONE];

        if (in_array($user->normalizedRole(), [
            User::ROLE_ADMIN,
            User::ROLE_MANAGER,
            User::ROLE_FRONT_DESK,
        ], true)) {
            $audiences[] = Announcement::AUDIENCE_STAFF;
        } elseif ($user->normalizedRole() === User::ROLE_GUEST) {
            $audiences[] = Announcement::AUDIENCE_GUESTS;
        }

        return Announcement::query()
            ->where('status', Announcement::STATUS_PUBLISHED)
            ->whereIn('audience', $audiences)
            ->where(fn ($query) => $query->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    private function notificationData(Announcement $announcement): array
    {
        $read = $announcement->reads->first();

        return array_merge($announcement->publicData(), [
            'is_read' => (bool) $read,
            'read_at' => $read?->read_at?->toISOString(),
        ]);
    }
}

