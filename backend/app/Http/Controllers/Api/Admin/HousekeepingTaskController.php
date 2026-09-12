<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Employee;
use App\Models\HousekeepingTask;
use App\Models\HousekeepingTaskHistory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogger;

class HousekeepingTaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'accommodation_id' => ['sometimes', 'nullable', 'integer', 'exists:accommodations,id'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'assigned_staff_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', Rule::in(HousekeepingTask::statuses())],
            'priority' => ['sometimes', 'nullable', Rule::in(HousekeepingTask::priorities())],
            'task_type' => ['sometimes', 'nullable', Rule::in(HousekeepingTask::taskTypes())],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10])],
        ]);

        $query = HousekeepingTask::query()
            ->with(['accommodation', 'assignedStaff', 'creator'])
            ->latest('updated_at');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(function ($query) use ($search) {
                $query
                    ->whereRaw('LOWER(remarks) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(maintenance_notes) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('accommodation', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]))
                    ->orWhereHas('assignedStaff', fn ($query) => $query
                        ->whereRaw('LOWER(first_name) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$search}%"]));
            });
        }

        foreach (['accommodation_id', 'status', 'priority', 'task_type'] as $filter) {
            if ($value = $filters[$filter] ?? null) {
                $query->where($filter, $value);
            }
        }
        if ($value = ($filters['employee_id'] ?? $filters['assigned_to'] ?? null)) {
            $query->where('employee_id', $value);
        }

        return response()->json([
            'data' => $query->paginate((int) ($filters['per_page'] ?? 10))
                ->through(fn (HousekeepingTask $task) => $task->publicData()),
            'summary' => $this->summary(),
            'meta' => $this->meta(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validatedAttributes($request);
        $task = HousekeepingTask::create(array_merge($attributes, [
            'created_by' => $request->user()->id,
        ]))->load(['accommodation', 'assignedStaff', 'creator']);

        $this->syncAccommodationStatus($task);
        $this->recordHistory($request, $task, 'created', null, $task->publicData(), $request->input('remarks'));

        return response()->json([
            'data' => $task->fresh(['accommodation', 'assignedStaff', 'creator'])->publicData(),
            'message' => 'Housekeeping task created.',
        ], 201);
    }

    public function show(HousekeepingTask $housekeepingTask): JsonResponse
    {
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

    public function update(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        $this->ensureEditable($housekeepingTask);
        $attributes = $this->validatedAttributes($request, false);
        $old = $housekeepingTask->load(['accommodation', 'assignedStaff', 'creator'])->publicData();

        if (
            isset($attributes['status'])
            && $attributes['status'] !== $housekeepingTask->status
            && ! $housekeepingTask->canTransitionTo($attributes['status'])
        ) {
            throw ValidationException::withMessages([
                'status' => ['This housekeeping task cannot move to the selected status.'],
            ]);
        }

        $attributes = $this->withStatusTimestamps($housekeepingTask, $attributes);
        $housekeepingTask->update($attributes);
        $this->syncAccommodationStatus($housekeepingTask->fresh('accommodation'));
        $housekeepingTask->load(['accommodation', 'assignedStaff', 'creator']);

        $this->recordHistory($request, $housekeepingTask, 'updated', $old, $housekeepingTask->publicData(), $request->input('remarks'));

        return response()->json([
            'data' => $housekeepingTask->publicData(),
            'message' => 'Housekeeping task updated.',
        ]);
    }

    public function assign(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        $this->ensureEditable($housekeepingTask);
        $attributes = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id', 'required_without:assigned_to'],
            'assigned_to' => ['nullable', 'integer', 'exists:employees,id', 'required_without:employee_id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $old = ['assigned_to' => $housekeepingTask->assigned_to, 'assigned_staff' => $housekeepingTask->assignedStaff?->name];
        $housekeepingTask->forceFill([
            'employee_id' => $attributes['employee_id'] ?? $attributes['assigned_to'],
            'assigned_staff_name' => $attributes['assigned_staff_name'] ?? $housekeepingTask->assigned_staff_name ?? $housekeepingTask->assignedStaff?->name,
            'status' => $housekeepingTask->status === HousekeepingTask::STATUS_PENDING
                ? HousekeepingTask::STATUS_ASSIGNED
                : $housekeepingTask->status,
        ])->save();

        $housekeepingTask->load(['accommodation', 'assignedStaff', 'creator']);
        $this->recordHistory($request, $housekeepingTask, 'assigned', $old, [
            'assigned_to' => $housekeepingTask->assigned_to,
            'assigned_staff' => $housekeepingTask->assigned_staff_name ?? $housekeepingTask->assignedStaff?->name,
        ], $attributes['remarks'] ?? null);

        return response()->json([
            'data' => $housekeepingTask->publicData(),
            'message' => 'Housekeeping task assigned.',
        ]);
    }

    public function updateStatus(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        $attributes = $request->validate([
            'status' => ['required', Rule::in(HousekeepingTask::statuses())],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $housekeepingTask->canTransitionTo($attributes['status'])) {
            throw ValidationException::withMessages([
                'status' => ['This housekeeping task cannot move to the selected status.'],
            ]);
        }

        $old = ['status' => $housekeepingTask->status];
        $updates = ['status' => $attributes['status']];

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

        $this->recordHistory($request, $housekeepingTask, 'status_changed', $old, [
            'status' => $housekeepingTask->status,
        ], $attributes['remarks'] ?? null);

        return response()->json([
            'data' => $housekeepingTask->publicData(),
            'message' => 'Housekeeping task status updated.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withStatusTimestamps(HousekeepingTask $task, array $attributes): array
    {
        $status = $attributes['status'] ?? $task->status;

        if ($status === HousekeepingTask::STATUS_IN_PROGRESS && ! $task->started_at && empty($attributes['started_at'])) {
            $attributes['started_at'] = now();
        }

        if ($status === HousekeepingTask::STATUS_COMPLETED && ! $task->completed_at && empty($attributes['completed_at'])) {
            $attributes['completed_at'] = now();
            $attributes['started_at'] = $task->started_at ?? ($attributes['started_at'] ?? now());
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAttributes(Request $request, bool $isCreate = true): array
    {
        $rules = [
            'accommodation_id' => ['required', 'integer', 'exists:accommodations,id'],
            'assigned_to' => ['nullable', 'integer', 'exists:employees,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'assigned_staff_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'task_type' => ['required', Rule::in(HousekeepingTask::taskTypes())],
            'priority' => ['required', Rule::in(HousekeepingTask::priorities())],
            'status' => ['required', Rule::in(HousekeepingTask::statuses())],
            'scheduled_at' => ['nullable', 'date'],
            'started_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'maintenance_notes' => ['nullable', 'string', 'max:2000'],
        ];

        $attributes = $request->validate($rules);

        return $attributes;
    }

    private function assertHousekeepingStaff(?int $userId): void
    {
        if (! $userId) {
            return;
        }

        $user = User::find($userId);

    }

    private function ensureEditable(HousekeepingTask $task): void
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
            HousekeepingTask::STATUS_CANCELLED => Accommodation::HOUSEKEEPING_NEEDS_CLEANING,
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
        app(AuditLogger::class)->log($request, 'housekeeping', $action, 'Housekeeping task '.$action.'.', $task, [
            'before' => $old,
            'after' => $new,
            'remarks' => $remarks,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        return [
            'pending_tasks' => HousekeepingTask::where('status', HousekeepingTask::STATUS_PENDING)->count(),
            'in_progress' => HousekeepingTask::where('status', HousekeepingTask::STATUS_IN_PROGRESS)->count(),
            'completed_today' => HousekeepingTask::where('status', HousekeepingTask::STATUS_COMPLETED)
                ->whereDate('completed_at', today())
                ->count(),
            'needs_cleaning' => Accommodation::where('housekeeping_status', Accommodation::HOUSEKEEPING_NEEDS_CLEANING)->count(),
            'maintenance' => Accommodation::where('housekeeping_status', Accommodation::HOUSEKEEPING_MAINTENANCE)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        return [
            'task_types' => HousekeepingTask::taskTypes(),
            'priorities' => HousekeepingTask::priorities(),
            'statuses' => HousekeepingTask::statuses(),
            'housekeeping_statuses' => Accommodation::housekeepingStatuses(),
            'staff' => Employee::query()
                ->where('status', Employee::STATUS_ACTIVE)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->map(fn (Employee $employee) => $employee->publicData())
                ->values(),
        ];
    }
}
