<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\AuditLog;
use App\Models\ChatConversation;
use App\Models\InventoryAsset;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        $term = trim($attributes['q']);
        $like = '%'.$term.'%';
        $limit = 5;

        $groups = [
            [
                'key' => 'bookings',
                'label' => 'Bookings',
                'results' => Reservation::query()
                    ->with(['accommodation:id,name', 'user:id,name,email'])
                    ->where(function ($query) use ($like) {
                        $query->where('booking_reference', 'like', $like)
                            ->orWhere('guest_first_name', 'like', $like)
                            ->orWhere('guest_last_name', 'like', $like)
                            ->orWhere('guest_email', 'like', $like)
                            ->orWhereHas('user', function ($query) use ($like) {
                                $query->where('name', 'like', $like)->orWhere('email', 'like', $like);
                            });
                    })
                    ->latest('created_at')
                    ->limit($limit)
                    ->get()
                    ->map(fn (Reservation $reservation) => [
                        'id' => $reservation->id,
                        'title' => $reservation->booking_reference,
                        'subtitle' => ($reservation->user?->name ?? trim($reservation->guest_first_name.' '.$reservation->guest_last_name) ?: 'Guest')
                            .' · '.($reservation->accommodation?->name ?? 'Accommodation unavailable'),
                        'path' => '/admin/bookings?reservation='.$reservation->id,
                    ])
                    ->values(),
            ],
            [
                'key' => 'accommodations',
                'label' => 'Accommodations',
                'results' => Accommodation::query()
                    ->where(function ($query) use ($like) {
                        $query->where('name', 'like', $like)
                            ->orWhere('type', 'like', $like)
                            ->orWhere('slug', 'like', $like);
                    })
                    ->latest('name')
                    ->limit($limit)
                    ->get(['id', 'name', 'type'])
                    ->map(fn (Accommodation $accommodation) => [
                        'id' => $accommodation->id,
                        'title' => $accommodation->name,
                        'subtitle' => str_replace('_', ' ', $accommodation->type),
                        'path' => '/admin/accommodations?accommodation='.$accommodation->id,
                    ])
                    ->values(),
            ],
            [
                'key' => 'users',
                'label' => 'Users',
                'results' => User::query()
                    ->where(function ($query) use ($like) {
                        $query->where('name', 'like', $like)->orWhere('email', 'like', $like);
                    })
                    ->orderBy('name')
                    ->limit($limit)
                    ->get(['id', 'name', 'email', 'role'])
                    ->map(fn (User $user) => [
                        'id' => $user->id,
                        'title' => $user->name,
                        'subtitle' => $user->email.' · '.str_replace('_', ' ', $user->role),
                        'path' => '/admin/users?user='.$user->id,
                    ])
                    ->values(),
            ],
            [
                'key' => 'inventory',
                'label' => 'Inventory',
                'results' => InventoryAsset::query()
                    ->where(function ($query) use ($like) {
                        $query->where('asset_code', 'like', $like)
                            ->orWhere('name', 'like', $like)
                            ->orWhere('category', 'like', $like);
                    })
                    ->latest('updated_at')
                    ->limit($limit)
                    ->get(['id', 'asset_code', 'name', 'category'])
                    ->map(fn (InventoryAsset $asset) => [
                        'id' => $asset->id,
                        'title' => $asset->asset_code,
                        'subtitle' => $asset->name.' · '.str_replace('_', ' ', $asset->category),
                        'path' => '/admin/inventory?asset='.$asset->id,
                    ])
                    ->values(),
            ],
            [
                'key' => 'support',
                'label' => 'Guest Support',
                'results' => ChatConversation::query()
                    ->with('customer:id,name,email')
                    ->where(function ($query) use ($like) {
                        $query->where('conversation_uuid', 'like', $like)
                            ->orWhere('guest_name', 'like', $like)
                            ->orWhere('guest_email', 'like', $like)
                            ->orWhereHas('customer', function ($query) use ($like) {
                                $query->where('name', 'like', $like)->orWhere('email', 'like', $like);
                            });
                    })
                    ->latest('last_message_at')
                    ->limit($limit)
                    ->get(['id', 'conversation_uuid', 'guest_name', 'guest_email', 'status', 'customer_id'])
                    ->map(fn (ChatConversation $conversation) => [
                        'id' => $conversation->id,
                        'title' => $conversation->guest_name ?? $conversation->customer?->name ?? 'Anonymous Visitor',
                        'subtitle' => $conversation->conversation_uuid.' · '.str_replace('_', ' ', $conversation->status),
                        'path' => '/admin/support?conversation_uuid='.$conversation->conversation_uuid,
                    ])
                    ->values(),
            ],
            [
                'key' => 'system_logs',
                'label' => 'System Logs',
                'results' => AuditLog::query()
                    ->where(function ($query) use ($like) {
                        $query->where('action', 'like', $like)
                            ->orWhere('module', 'like', $like)
                            ->orWhere('description', 'like', $like);
                    })
                    ->latest()
                    ->limit($limit)
                    ->get(['id', 'module', 'action', 'description', 'created_at'])
                    ->map(fn (AuditLog $log) => [
                        'id' => $log->id,
                        'title' => str_replace('_', ' ', $log->action),
                        'subtitle' => str_replace('_', ' ', $log->module).' · '.mb_strimwidth((string) $log->description, 0, 80, '…'),
                        'path' => '/admin/system-logs?log='.$log->id,
                    ])
                    ->values(),
            ],
        ];

        return response()->json([
            'data' => collect($groups)->filter(fn (array $group) => $group['results']->isNotEmpty())->values(),
        ]);
    }
}
