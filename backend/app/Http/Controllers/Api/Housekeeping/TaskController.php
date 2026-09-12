<?php

namespace App\Http\Controllers\Api\Housekeeping;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\HousekeepingTask;
use App\Models\HousekeepingTaskHistory;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class TaskController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $employeeId = $request->user()->employee?->id;
        $today = today();

        $todayTasks = $this->baseQuery($employeeId)
            ->where(function (Builder $query) use ($today) {
                $query
                    ->whereDate('scheduled_at', $today)
                    ->orWhereIn('status', [
                        HousekeepingTask::STATUS_PENDING,
                        HousekeepingTask::STATUS_ASSIGNED,
                        HousekeepingTask::STATUS_IN_PROGRESS,
                    ]);
            })
            ->orderByRaw('scheduled_at IS NULL')
            ->orderBy('scheduled_at')
            ->get();

        return response()->json([
            'data' => [
                'summary' => $this->summary($employeeId),
                'today_tasks' => $todayTasks->map(fn (HousekeepingTask $task) => $task->publicData())->values(),
                'meta' => $this->meta(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', Rule::in(HousekeepingTask::statuses())],
            'priority' => ['sometimes', 'nullable', Rule::in(HousekeepingTask::priorities())],
            'task_type' => ['sometimes', 'nullable', Rule::in(HousekeepingTask::taskTypes())],
        ]);

        $query = $this->baseQuery($request->user()->employee?->id)->latest('updated_at');
        $this->applyFilters($query, $filters);

        return response()->json([
            'data' => $query->get()->map(fn (HousekeepingTask $task) => $task->publicData())->values(),
            'summary' => $this->summary($request->user()->employee?->id),
            'meta' => $this->meta(),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', Rule::in([
                HousekeepingTask::STATUS_COMPLETED,
                HousekeepingTask::STATUS_CANCELLED,
            ])],
            'priority' => ['sometimes', 'nullable', Rule::in(HousekeepingTask::priorities())],
            'task_type' => ['sometimes', 'nullable', Rule::in(HousekeepingTask::taskTypes())],
        ]);

        $query = $this->baseQuery($request->user()->employee?->id)
            ->whereIn('status', [HousekeepingTask::STATUS_COMPLETED, HousekeepingTask::STATUS_CANCELLED])
            ->latest('updated_at');
        $this->applyFilters($query, $filters);

        return response()->json([
            'data' => $query->get()->map(fn (HousekeepingTask $task) => $task->publicData())->values(),
            'summary' => $this->summary($request->user()->employee?->id),
            'meta' => $this->meta(),
        ]);
    }

    public function show(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        $this->authorizeAssignedTask($request, $housekeepingTask);

        return response()->json([
            'data' => $housekeepingTask->load(['accommodation', 'assignedStaff', 'creator'])->publicData(),
            'history' => $housekeepingTask->histories()
                ->with('performer')
                ->latest()
                ->get()
                ->map(fn (HousekeepingTaskHistory $history) => $history->publicData())
                ->values(),
            'meta' => $this->meta(),
        ]);
    }

    public function updateStatus(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        $this->authorizeAssignedTask($request, $housekeepingTask);
        $this->ensureStaffEditable($housekeepingTask);

        $attributes = $request->validate([
            'status' => ['required', Rule::in([
                HousekeepingTask::STATUS_IN_PROGRESS,
                HousekeepingTask::STATUS_COMPLETED,
            ])],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'maintenance_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if (! $housekeepingTask->canTransitionTo($attributes['status'])) {
            throw ValidationException::withMessages([
                'status' => ['This housekeeping task cannot move to the selected status.'],
            ]);
        }

        $old = [
            'status' => $housekeepingTask->status,
            'remarks' => $housekeepingTask->remarks,
            'maintenance_notes' => $housekeepingTask->maintenance_notes,
        ];

        $updates = [
            'status' => $attributes['status'],
        ];

        if (array_key_exists('remarks', $attributes)) {
            $updates['remarks'] = $attributes['remarks'];
        }

        if (array_key_exists('maintenance_notes', $attributes)) {
            $updates['maintenance_notes'] = $attributes['maintenance_notes'];
        }

        if ($attributes['status'] === HousekeepingTask::STATUS_IN_PROGRESS && ! $housekeepingTask->started_at) {
            $updates['started_at'] = now();
        }

        if ($attributes['status'] === HousekeepingTask::STATUS_COMPLETED && ! $housekeepingTask->completed_at) {
            $updates['completed_at'] = now();
            $updates['started_at'] = $housekeepingTask->started_at ?? now();
        }

        $housekeepingTask->forceFill($updates)->save();
        $this->syncAccommodationStatus($housekeepingTask->fresh('accommodation'));
        $housekeepingTask->load(['accommodation', 'assignedStaff', 'creator']);

        $this->recordHistory($request, $housekeepingTask, 'staff_status_changed', $old, [
            'status' => $housekeepingTask->status,
            'remarks' => $housekeepingTask->remarks,
            'maintenance_notes' => $housekeepingTask->maintenance_notes,
        ], $attributes['remarks'] ?? null);

        return response()->json([
            'data' => $housekeepingTask->publicData(),
            'message' => 'Task updated.',
        ]);
    }

    public function updateNotes(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        $this->authorizeAssignedTask($request, $housekeepingTask);
        $this->ensureStaffEditable($housekeepingTask);

        $attributes = $request->validate([
            'remarks' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'maintenance_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $old = [
            'remarks' => $housekeepingTask->remarks,
            'maintenance_notes' => $housekeepingTask->maintenance_notes,
        ];

        $housekeepingTask->forceFill($attributes)->save();
        $housekeepingTask->load(['accommodation', 'assignedStaff', 'creator']);

        $this->recordHistory($request, $housekeepingTask, 'staff_notes_updated', $old, [
            'remarks' => $housekeepingTask->remarks,
            'maintenance_notes' => $housekeepingTask->maintenance_notes,
        ], $attributes['remarks'] ?? null);

        return response()->json([
            'data' => $housekeepingTask->publicData(),
            'message' => 'Task notes updated.',
        ]);
    }

    private function baseQuery(?int $employeeId): Builder
    {
        return HousekeepingTask::query()
            ->where('employee_id', $employeeId ?? 0)
            ->with(['accommodation', 'assignedStaff', 'creator']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(function (Builder $query) use ($search) {
                $query
                    ->whereRaw('LOWER(remarks) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(maintenance_notes) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('accommodation', fn (Builder $query) => $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]));
            });
        }

        foreach (['status', 'priority', 'task_type'] as $filter) {
            if ($value = $filters[$filter] ?? null) {
                $query->where($filter, $value);
            }
        }
    }

    private function authorizeAssignedTask(Request $request, HousekeepingTask $task): void
    {
        if ((int) $task->employee_id !== (int) ($request->user()->employee?->id ?? 0)) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }

    private function ensureStaffEditable(HousekeepingTask $task): void
    {
        if (in_array($task->status, [HousekeepingTask::STATUS_COMPLETED, HousekeepingTask::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Completed or cancelled housekeeping tasks are preserved and cannot be modified.'],
            ]);
        }
    }

    private function syncAccommodationStatus(HousekeepingTask $task): void
    {
        $status = match ($task->status) {
            HousekeepingTask::STATUS_IN_PROGRESS => Accommodation::HOUSEKEEPING_CLEANING,
            HousekeepingTask::STATUS_COMPLETED => $task->task_type === HousekeepingTask::TYPE_MAINTENANCE
                ? Accommodation::HOUSEKEEPING_MAINTENANCE
                : Accommodation::HOUSEKEEPING_CLEAN,
            default => $task->task_type === HousekeepingTask::TYPE_MAINTENANCE
                ? Accommodation::HOUSEKEEPING_MAINTENANCE
                : Accommodation::HOUSEKEEPING_NEEDS_CLEANING,
        };

        $task->accommodation?->forceFill([
            'housekeeping_status' => $status,
        ])->save();
    }

    private function recordHistory(Request $request, HousekeepingTask $task, string $action, mixed $old, mixed $new, ?string $remarks): void
    {
        $task->histories()->create([
            'performed_by' => $request->user()?->id,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'remarks' => $remarks,
        ]);

        app(AuditLogger::class)->log($request, 'housekeeping', $action, 'Housekeeping staff updated assigned task.', $task, [
            'before' => $old,
            'after' => $new,
            'remarks' => $remarks,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function summary(?int $employeeId): array
    {
        return [
            'tasks_today' => HousekeepingTask::where('employee_id', $employeeId ?? 0)->whereDate('scheduled_at', today())->count(),
            'pending' => HousekeepingTask::where('employee_id', $employeeId ?? 0)
                ->whereIn('status', [HousekeepingTask::STATUS_PENDING, HousekeepingTask::STATUS_ASSIGNED])
                ->count(),
            'in_progress' => HousekeepingTask::where('employee_id', $employeeId ?? 0)
                ->where('status', HousekeepingTask::STATUS_IN_PROGRESS)
                ->count(),
            'completed_today' => HousekeepingTask::where('employee_id', $employeeId ?? 0)
                ->where('status', HousekeepingTask::STATUS_COMPLETED)
                ->whereDate('completed_at', today())
                ->count(),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function meta(): array
    {
        return [
            'task_types' => HousekeepingTask::taskTypes(),
            'priorities' => HousekeepingTask::priorities(),
            'statuses' => HousekeepingTask::statuses(),
            'staff_action_statuses' => [
                HousekeepingTask::STATUS_IN_PROGRESS,
                HousekeepingTask::STATUS_COMPLETED,
            ],
        ];
    }
}
