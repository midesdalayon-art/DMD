<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogger;

class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'type' => ['sometimes', 'nullable', Rule::in(Announcement::types())],
            'audience' => ['sometimes', 'nullable', Rule::in(Announcement::audiences())],
            'status' => ['sometimes', 'nullable', Rule::in(Announcement::statuses())],
        ]);

        $query = Announcement::query()
            ->with('creator')
            ->latest('updated_at');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(function ($query) use ($search) {
                $query
                    ->whereRaw('LOWER(title) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(content) LIKE ?', ["%{$search}%"]);
            });
        }

        foreach (['type', 'audience', 'status'] as $filter) {
            if ($value = $filters[$filter] ?? null) {
                $query->where($filter, $value);
            }
        }

        return response()->json([
            'data' => $query->get()->map(fn (Announcement $announcement) => $announcement->publicData(true))->values(),
            'summary' => $this->summary(),
            'meta' => $this->meta(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $announcement = Announcement::create(array_merge($this->validatedAttributes($request), [
            'created_by' => $request->user()->id,
        ]))->load('creator');
        app(AuditLogger::class)->log($request, 'announcements', 'created', 'Announcement created.', $announcement, [
            'after' => $announcement->publicData(true),
        ]);

        return response()->json([
            'data' => $announcement->publicData(true),
            'message' => 'Announcement created.',
        ], 201);
    }

    public function show(Announcement $announcement): JsonResponse
    {
        return response()->json([
            'data' => $announcement->load('creator')->publicData(true),
            'meta' => $this->meta(),
        ]);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        if ($announcement->status === Announcement::STATUS_ARCHIVED) {
            throw ValidationException::withMessages([
                'status' => ['Archived announcements are preserved and cannot be edited.'],
            ]);
        }

        $old = $announcement->load('creator')->publicData(true);
        $announcement->update($this->validatedAttributes($request));
        app(AuditLogger::class)->log($request, 'announcements', 'edited', 'Announcement edited.', $announcement, [
            'before' => $old,
            'after' => $announcement->fresh('creator')->publicData(true),
        ]);

        return response()->json([
            'data' => $announcement->fresh('creator')->publicData(true),
            'message' => 'Announcement updated.',
        ]);
    }

    public function updateStatus(Request $request, Announcement $announcement): JsonResponse
    {
        $attributes = $request->validate([
            'status' => ['required', Rule::in(Announcement::statuses())],
        ]);

        if ($announcement->status === Announcement::STATUS_ARCHIVED && $attributes['status'] !== Announcement::STATUS_ARCHIVED) {
            throw ValidationException::withMessages([
                'status' => ['Archived announcements are preserved and cannot be republished.'],
            ]);
        }

        $old = ['status' => $announcement->status, 'publish_at' => $announcement->publish_at?->toISOString()];
        $announcement->forceFill([
            'status' => $attributes['status'],
            'publish_at' => $attributes['status'] === Announcement::STATUS_PUBLISHED && ! $announcement->publish_at
                ? now()
                : $announcement->publish_at,
        ])->save();
        $action = match ($announcement->status) {
            Announcement::STATUS_PUBLISHED => 'published',
            Announcement::STATUS_ARCHIVED => 'archived',
            default => 'unpublished',
        };
        app(AuditLogger::class)->log($request, 'announcements', $action, 'Announcement status changed.', $announcement, [
            'before' => $old,
            'after' => ['status' => $announcement->status, 'publish_at' => $announcement->publish_at?->toISOString()],
        ]);

        return response()->json([
            'data' => $announcement->fresh('creator')->publicData(true),
            'message' => match ($announcement->status) {
                Announcement::STATUS_PUBLISHED => 'Announcement published.',
                Announcement::STATUS_ARCHIVED => 'Announcement archived.',
                default => 'Announcement returned to draft.',
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAttributes(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'content' => ['required', 'string', 'max:10000'],
            'type' => ['required', Rule::in(Announcement::types())],
            'audience' => ['required', Rule::in(Announcement::audiences())],
            'status' => ['required', Rule::in(Announcement::statuses())],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:publish_at'],
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        return [
            'total_announcements' => Announcement::count(),
            'published' => Announcement::where('status', Announcement::STATUS_PUBLISHED)->count(),
            'drafts' => Announcement::where('status', Announcement::STATUS_DRAFT)->count(),
            'archived' => Announcement::where('status', Announcement::STATUS_ARCHIVED)->count(),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function meta(): array
    {
        return [
            'types' => Announcement::types(),
            'audiences' => Announcement::audiences(),
            'statuses' => Announcement::statuses(),
        ];
    }
}
