<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\HousekeepingTask;
use App\Models\HousekeepingTaskHistory;
use App\Models\InventoryAsset;
use App\Models\InventoryAssetHistory;
use App\Models\Reservation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CancellationRefundService;
use App\Services\FinancialReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationalController extends Controller
{
    public function dashboard(FinancialReportingService $financialReporting): JsonResponse
    {
        $today = today();
        $occupiedAccommodationIds = Reservation::query()
            ->whereIn('status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_CHECKED_IN])
            ->whereDate('check_in', '<=', $today)
            ->whereDate('check_out', '>', $today)
            ->pluck('accommodation_id')
            ->unique();

        return response()->json([
            'data' => [
                'summary' => [
                    'todays_reservations' => Reservation::whereDate('check_in', $today)->count(),
                    'pending_reservations' => Reservation::applyPendingHoldFilter(Reservation::query())->count(),
                    'occupied_accommodations' => $occupiedAccommodationIds->count(),
                    'available_accommodations' => Accommodation::where('status', Accommodation::STATUS_AVAILABLE)
                        ->whereNotIn('id', $occupiedAccommodationIds)
                        ->count(),
                    'housekeeping_tasks' => HousekeepingTask::whereIn('status', [
                        HousekeepingTask::STATUS_PENDING,
                        HousekeepingTask::STATUS_ASSIGNED,
                        HousekeepingTask::STATUS_IN_PROGRESS,
                    ])->count(),
                    'staff_attendance_today' => AttendanceRecord::whereDate('attendance_date', $today)->count(),
                    'assets_under_maintenance' => InventoryAsset::where('status', InventoryAsset::STATUS_MAINTENANCE)->sum('quantity'),
                ],
                'financial' => $financialReporting->summary(),
                'recent_reservations' => Reservation::query()
                    ->with(['user', 'accommodation'])
                    ->withPaymentSummary()
                    ->latest()
                    ->limit(6)
                    ->get()
                    ->map(fn (Reservation $reservation) => $this->reservationData($reservation))
                    ->values(),
                'accommodation_status' => [
                    'available' => Accommodation::where('status', Accommodation::STATUS_AVAILABLE)->count(),
                    'unavailable' => Accommodation::where('status', Accommodation::STATUS_UNAVAILABLE)->count(),
                    'maintenance' => Accommodation::where('status', Accommodation::STATUS_MAINTENANCE)->count(),
                ],
                'housekeeping_status' => [
                    'pending' => HousekeepingTask::where('status', HousekeepingTask::STATUS_PENDING)->count(),
                    'assigned' => HousekeepingTask::where('status', HousekeepingTask::STATUS_ASSIGNED)->count(),
                    'in_progress' => HousekeepingTask::where('status', HousekeepingTask::STATUS_IN_PROGRESS)->count(),
                    'completed_today' => HousekeepingTask::where('status', HousekeepingTask::STATUS_COMPLETED)
                        ->whereDate('completed_at', $today)
                        ->count(),
                ],
                'attendance_summary' => [
                    'present' => AttendanceRecord::whereDate('attendance_date', $today)->where('status', AttendanceRecord::STATUS_PRESENT)->count(),
                    'late' => AttendanceRecord::whereDate('attendance_date', $today)->where('status', AttendanceRecord::STATUS_LATE)->count(),
                    'absent' => AttendanceRecord::whereDate('attendance_date', $today)->where('status', AttendanceRecord::STATUS_ABSENT)->count(),
                    'incomplete' => AttendanceRecord::whereDate('attendance_date', $today)->where('status', AttendanceRecord::STATUS_INCOMPLETE)->count(),
                ],
            ],
        ]);
    }

    public function reservations(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:160'],
            'booking_reference' => ['sometimes', 'nullable', 'string', 'max:40'],
            'guest' => ['sometimes', 'nullable', 'string', 'max:120'],
            'accommodation' => ['sometimes', 'nullable', 'string', 'max:160'],
            'status' => ['sometimes', 'nullable', Rule::in(Reservation::adminStatuses())],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = Reservation::query()
            ->with(['user', 'accommodation'])
            ->withPaymentSummary()
            ->latest();
        $this->applyReservationFilters($query, $filters);

        $paginator = $query->paginate(10);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Reservation $reservation) => $this->reservationData($reservation))->values(),
            'meta' => [
                'statuses' => Reservation::adminStatuses(),
                'transitions' => Reservation::allowedAdminStatusTransitions(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    public function reservation(Reservation $reservation): JsonResponse
    {
        return response()->json([
            'data' => $this->reservationData($reservation->load(['user', 'accommodation'])->loadPaymentSummary(), true),
            'meta' => [
                'statuses' => Reservation::adminStatuses(),
                'transitions' => Reservation::allowedAdminStatusTransitions(),
            ],
        ]);
    }

    public function updateReservationStatus(Request $request, Reservation $reservation, CancellationRefundService $refunds): JsonResponse
    {
        $attributes = $request->validate([
            'status' => ['required', Rule::in(Reservation::adminStatuses())],
            'cancellation_reason' => ['required_if:status,'.Reservation::STATUS_CANCELLED, 'nullable', 'string', 'min:5', 'max:1000'],
        ]);

        if (! $reservation->canTransitionTo($attributes['status'])) {
            throw ValidationException::withMessages([
                'status' => ['This reservation cannot be moved to the selected status.'],
            ]);
        }

        if ($attributes['status'] === Reservation::STATUS_CHECKED_IN) {
            $reservation->loadPaymentSummary();

            if ($reservation->balanceDueMinor() > 0 || $reservation->paymentState() !== 'fully_paid') {
                throw ValidationException::withMessages([
                    'status' => ['The remaining balance must be settled before check-in.'],
                ]);
            }
        }

        if ($attributes['status'] === Reservation::STATUS_CANCELLED && ! $reservation->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => ['This reservation can no longer be cancelled.'],
            ]);
        }

        $old = ['status' => $reservation->status, 'cancellation_reason' => $reservation->cancellation_reason];
        $updates = ['status' => $attributes['status']];

        if ($attributes['status'] === Reservation::STATUS_CANCELLED) {
            $updates['cancellation_reason'] = $attributes['cancellation_reason'];
            $updates['cancelled_at'] = now();
            $updates = array_merge($updates, $refunds->cancellationAttributes($reservation, true));
        }

        $reservation->forceFill($updates)->save();
        app(AuditLogger::class)->log($request, 'booking_management', 'manager_reservation_status_changed', 'Reservation status changed by manager.', $reservation, [
            'booking_reference' => $reservation->booking_reference,
            'before' => $old,
            'after' => ['status' => $reservation->status, 'cancellation_reason' => $reservation->cancellation_reason],
        ]);

        return response()->json([
            'data' => $this->reservationData($reservation->load(['user', 'accommodation'])->loadPaymentSummary()),
            'message' => 'Reservation status updated.',
        ]);
    }

    public function accommodations(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'type' => ['sometimes', 'nullable', Rule::in(Accommodation::types())],
            'status' => ['sometimes', 'nullable', Rule::in($this->accommodationStatuses())],
        ]);

        $query = Accommodation::query()
            ->withCount('reservations')
            ->with([
                'reservations' => fn ($query) => $query
                    ->whereIn('status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_CHECKED_IN])
                    ->whereDate('check_in', '<=', today())
                    ->whereDate('check_out', '>', today())
                    ->latest('check_in'),
                'housekeepingTasks' => fn ($query) => $query
                    ->whereIn('status', [
                        HousekeepingTask::STATUS_PENDING,
                        HousekeepingTask::STATUS_ASSIGNED,
                        HousekeepingTask::STATUS_IN_PROGRESS,
                    ])
                    ->with('assignedStaff')
                    ->latest('updated_at'),
            ])
            ->orderBy('type')
            ->orderBy('name');
        $this->applyAccommodationFilters($query, $filters);

        return response()->json([
            'data' => $query->get()->map(fn (Accommodation $accommodation) => $this->accommodationData($accommodation))->values(),
            'meta' => [
                'statuses' => $this->accommodationStatuses(),
                'types' => Accommodation::types(),
                'housekeeping_statuses' => Accommodation::housekeepingStatuses(),
            ],
        ]);
    }

    public function accommodation(Accommodation $accommodation): JsonResponse
    {
        return response()->json([
            'data' => $this->accommodationData($accommodation->loadCount('reservations')),
            'meta' => [
                'statuses' => $this->accommodationStatuses(),
                'types' => Accommodation::types(),
                'housekeeping_statuses' => Accommodation::housekeepingStatuses(),
            ],
        ]);
    }

    public function updateAccommodationStatus(Request $request, Accommodation $accommodation): JsonResponse
    {
        $attributes = $request->validate([
            'status' => ['required', Rule::in($this->accommodationStatuses())],
        ]);

        $old = ['status' => $accommodation->status];
        $accommodation->forceFill(['status' => $attributes['status']])->save();

        app(AuditLogger::class)->log($request, 'accommodation_management', 'manager_status_changed', 'Accommodation status changed by manager.', $accommodation, [
            'before' => $old,
            'after' => ['status' => $accommodation->status],
        ]);

        return response()->json([
            'data' => $this->accommodationData($accommodation->loadCount('reservations')),
            'message' => 'Accommodation status updated.',
        ]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'condition' => ['sometimes', 'nullable', Rule::in(InventoryAsset::conditions())],
            'status' => ['sometimes', 'nullable', Rule::in(InventoryAsset::statuses())],
            'location_type' => ['sometimes', 'nullable', Rule::in(InventoryAsset::locationTypes())],
        ]);

        $query = InventoryAsset::query()->with(['accommodation', 'locations.accommodation'])->latest('updated_at');
        $this->applyInventoryFilters($query, $filters);
        $assets = $query->get();

        return response()->json([
            'data' => $assets->map(fn (InventoryAsset $asset) => $asset->publicData())->values(),
            'summary' => $this->inventorySummary(),
            'meta' => [
                'conditions' => InventoryAsset::conditions(),
                'statuses' => InventoryAsset::statuses(),
                'location_types' => InventoryAsset::locationTypes(),
            ],
        ]);
    }

    public function inventoryAsset(InventoryAsset $inventoryAsset): JsonResponse
    {
        return response()->json([
            'data' => $inventoryAsset->load(['accommodation', 'locations.accommodation'])->publicData(),
            'history' => $inventoryAsset->histories()
                ->with('performer')
                ->latest()
                ->get()
                ->map(fn (InventoryAssetHistory $history) => $history->publicData())
                ->values(),
        ]);
    }

    public function updateInventoryCondition(Request $request, InventoryAsset $inventoryAsset): JsonResponse
    {
        $this->ensureAssetNotRetired($inventoryAsset);
        $attributes = $request->validate([
            'condition' => ['required', Rule::in(InventoryAsset::conditions())],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $old = ['condition' => $inventoryAsset->condition];
        $inventoryAsset->forceFill(['condition' => $attributes['condition']])->save();
        $this->recordInventoryHistory($request, $inventoryAsset, 'condition_changed', $old, ['condition' => $inventoryAsset->condition], $attributes['remarks'] ?? null);

        return response()->json([
            'data' => $inventoryAsset->fresh(['accommodation', 'locations.accommodation'])->publicData(),
            'message' => 'Inventory asset condition updated.',
        ]);
    }

    public function updateInventoryStatus(Request $request, InventoryAsset $inventoryAsset): JsonResponse
    {
        $attributes = $request->validate([
            'status' => ['required', Rule::in([InventoryAsset::STATUS_AVAILABLE, InventoryAsset::STATUS_ASSIGNED, InventoryAsset::STATUS_MAINTENANCE])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->ensureAssetNotRetired($inventoryAsset);
        $old = ['status' => $inventoryAsset->status];
        $inventoryAsset->forceFill(['status' => $attributes['status']])->save();
        $this->recordInventoryHistory($request, $inventoryAsset, 'status_changed', $old, ['status' => $inventoryAsset->status], $attributes['remarks'] ?? null);

        return response()->json([
            'data' => $inventoryAsset->fresh(['accommodation', 'locations.accommodation'])->publicData(),
            'message' => 'Inventory asset status updated.',
        ]);
    }

    public function housekeeping(Request $request): JsonResponse
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
        ]);

        $query = HousekeepingTask::query()->with(['accommodation', 'assignedStaff', 'creator'])->latest('updated_at');
        $this->applyHousekeepingFilters($query, $filters);

        return response()->json([
            'data' => $query->get()->map(fn (HousekeepingTask $task) => $task->publicData())->values(),
            'summary' => $this->housekeepingSummary(),
            'meta' => $this->housekeepingMeta(),
        ]);
    }

    public function housekeepingTask(HousekeepingTask $housekeepingTask): JsonResponse
    {
        return response()->json([
            'data' => $housekeepingTask->load(['accommodation', 'assignedStaff', 'creator'])->publicData(),
            'history' => $housekeepingTask->histories()
                ->with('performer')
                ->latest()
                ->get()
                ->map(fn (HousekeepingTaskHistory $history) => $history->publicData())
                ->values(),
            'meta' => $this->housekeepingMeta(),
        ]);
    }

    public function createHousekeepingTask(Request $request): JsonResponse
    {
        $attributes = $this->validatedHousekeepingAttributes($request);
        $task = HousekeepingTask::create(array_merge($attributes, [
            'created_by' => $request->user()->id,
        ]))->load(['accommodation', 'assignedStaff', 'creator']);

        $this->syncAccommodationHousekeepingStatus($task);
        $this->recordHousekeepingHistory($request, $task, 'created', null, $task->publicData(), $request->input('remarks'));

        return response()->json([
            'data' => $task->fresh(['accommodation', 'assignedStaff', 'creator'])->publicData(),
            'message' => 'Housekeeping task created.',
        ], 201);
    }

    public function updateHousekeepingTask(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        $this->ensureHousekeepingEditable($housekeepingTask);
        $attributes = $this->validatedHousekeepingAttributes($request);
        $old = $housekeepingTask->load(['accommodation', 'assignedStaff', 'creator'])->publicData();

        if (isset($attributes['status']) && $attributes['status'] !== $housekeepingTask->status && ! $housekeepingTask->canTransitionTo($attributes['status'])) {
            throw ValidationException::withMessages([
                'status' => ['This housekeeping task cannot move to the selected status.'],
            ]);
        }

        $attributes = $this->withHousekeepingStatusTimestamps($housekeepingTask, $attributes);
        $housekeepingTask->update($attributes);
        $this->syncAccommodationHousekeepingStatus($housekeepingTask->fresh('accommodation'));
        $housekeepingTask->load(['accommodation', 'assignedStaff', 'creator']);
        $this->recordHousekeepingHistory($request, $housekeepingTask, 'updated', $old, $housekeepingTask->publicData(), $request->input('remarks'));

        return response()->json([
            'data' => $housekeepingTask->publicData(),
            'message' => 'Housekeeping task updated.',
        ]);
    }

    public function assignHousekeepingTask(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
    {
        $this->ensureHousekeepingEditable($housekeepingTask);
        $attributes = $request->validate([
            'employee_id' => ['nullable', 'integer', 'exists:employees,id', 'required_without:assigned_to'],
            'assigned_to' => ['nullable', 'integer', 'exists:employees,id', 'required_without:employee_id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $old = ['assigned_to' => $housekeepingTask->assigned_to, 'assigned_staff' => $housekeepingTask->assignedStaff?->name];
        $housekeepingTask->forceFill([
            'employee_id' => $attributes['employee_id'] ?? $attributes['assigned_to'],
            'assigned_staff_name' => $attributes['assigned_staff_name'] ?? $housekeepingTask->assigned_staff_name ?? $housekeepingTask->assignedStaff?->name,
            'status' => $housekeepingTask->status === HousekeepingTask::STATUS_PENDING ? HousekeepingTask::STATUS_ASSIGNED : $housekeepingTask->status,
        ])->save();

        $housekeepingTask->load(['accommodation', 'assignedStaff', 'creator']);
        $this->recordHousekeepingHistory($request, $housekeepingTask, 'assigned', $old, [
            'assigned_to' => $housekeepingTask->assigned_to,
            'assigned_staff' => $housekeepingTask->assigned_staff_name ?? $housekeepingTask->assignedStaff?->name,
        ], $attributes['remarks'] ?? null);

        return response()->json([
            'data' => $housekeepingTask->publicData(),
            'message' => 'Housekeeping task assigned.',
        ]);
    }

    public function updateHousekeepingStatus(Request $request, HousekeepingTask $housekeepingTask): JsonResponse
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
        $updates = $this->withHousekeepingStatusTimestamps($housekeepingTask, ['status' => $attributes['status']]);
        $housekeepingTask->forceFill($updates)->save();
        $this->syncAccommodationHousekeepingStatus($housekeepingTask->fresh('accommodation'));
        $housekeepingTask->load(['accommodation', 'assignedStaff', 'creator']);
        $this->recordHousekeepingHistory($request, $housekeepingTask, 'status_changed', $old, ['status' => $housekeepingTask->status], $attributes['remarks'] ?? null);

        return response()->json([
            'data' => $housekeepingTask->publicData(),
            'message' => 'Housekeeping task status updated.',
        ]);
    }

    public function attendance(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['sometimes', 'nullable', Rule::in(AttendanceRecord::staffRoles())],
            'attendance_date' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'nullable', Rule::in(AttendanceRecord::statuses())],
            'verification_method' => ['sometimes', 'nullable', Rule::in(AttendanceRecord::verificationMethods())],
        ]);

        $query = AttendanceRecord::query()->with(['staff', 'corrector'])->latest('attendance_date')->latest('updated_at');
        $this->applyAttendanceFilters($query, $filters);

        return response()->json([
            'data' => $query->get()->map(fn (AttendanceRecord $record) => $record->publicData())->values(),
            'summary' => $this->attendanceSummary(),
            'meta' => $this->attendanceMeta(),
        ]);
    }

    public function attendanceRecord(AttendanceRecord $attendanceRecord): JsonResponse
    {
        return response()->json([
            'data' => $attendanceRecord->load(['staff', 'corrector'])->publicData(),
            'meta' => $this->attendanceMeta(),
        ]);
    }

    public function announcements(Request $request): JsonResponse
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

        return response()->json([
            'data' => $query->get()->map(fn (Announcement $announcement) => $announcement->publicData(true))->values(),
            'summary' => [
                'published_staff' => (clone $query)->count(),
            ],
            'meta' => [
                'types' => Announcement::types(),
                'audiences' => [Announcement::AUDIENCE_EVERYONE, Announcement::AUDIENCE_STAFF],
            ],
        ]);
    }

    public function announcement(Announcement $announcement): JsonResponse
    {
        if (
            $announcement->status !== Announcement::STATUS_PUBLISHED
            || ! in_array($announcement->audience, [Announcement::AUDIENCE_EVERYONE, Announcement::AUDIENCE_STAFF], true)
            || ($announcement->publish_at && $announcement->publish_at->gt(now()))
            || ($announcement->expires_at && $announcement->expires_at->lte(now()))
        ) {
            abort(404);
        }

        return response()->json([
            'data' => $announcement->load('creator')->publicData(true),
            'meta' => [
                'types' => Announcement::types(),
                'audiences' => [Announcement::AUDIENCE_EVERYONE, Announcement::AUDIENCE_STAFF],
            ],
        ]);
    }

    private function applyReservationFilters($query, array $filters): void
    {
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(function ($query) use ($search) {
                $like = "%{$search}%";

                $query->whereRaw('LOWER(booking_reference) LIKE ?', [$like])
                    ->orWhereHas('user', fn ($query) => $query
                        ->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(first_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like]))
                    ->orWhereHas('accommodation', fn ($query) => $query
                        ->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(slug) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(type) LIKE ?', [$like]));
            });
        }

        if ($reference = trim((string) ($filters['booking_reference'] ?? ''))) {
            $reference = mb_strtolower($reference);
            $query->whereRaw('LOWER(booking_reference) LIKE ?', ["%{$reference}%"]);
        }

        if ($guest = trim((string) ($filters['guest'] ?? ''))) {
            $guest = mb_strtolower($guest);
            $query->whereHas('user', fn ($query) => $query
                ->whereRaw('LOWER(name) LIKE ?', ["%{$guest}%"])
                ->orWhereRaw('LOWER(first_name) LIKE ?', ["%{$guest}%"])
                ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$guest}%"])
                ->orWhereRaw('LOWER(email) LIKE ?', ["%{$guest}%"]));
        }

        if ($accommodation = trim((string) ($filters['accommodation'] ?? ''))) {
            $accommodation = mb_strtolower($accommodation);
            $query->whereHas('accommodation', fn ($query) => $query
                ->whereRaw('LOWER(name) LIKE ?', ["%{$accommodation}%"])
                ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$accommodation}%"])
                ->orWhereRaw('LOWER(type) LIKE ?', ["%{$accommodation}%"]));
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($dateFrom = $filters['date_from'] ?? null) {
            $query->whereDate('check_in', '>=', $dateFrom);
        }

        if ($dateTo = $filters['date_to'] ?? null) {
            $query->whereDate('check_in', '<=', $dateTo);
        }
    }

    private function applyAccommodationFilters($query, array $filters): void
    {
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(fn ($query) => $query
                ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(description) LIKE ?', ["%{$search}%"]));
        }

        foreach (['type', 'status'] as $filter) {
            if ($value = $filters[$filter] ?? null) {
                $query->where($filter, $value);
            }
        }
    }

    private function applyInventoryFilters($query, array $filters): void
    {
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(fn ($query) => $query
                ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(asset_code) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(category) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(location_name) LIKE ?', ["%{$search}%"])
                ->orWhereHas('locations', fn ($query) => $query
                    ->whereRaw('LOWER(location_name) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('accommodation', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]))));
        }

        foreach (['category', 'condition', 'status', 'location_type'] as $filter) {
            if ($value = $filters[$filter] ?? null) {
                if ($filter === 'category') {
                    $query->whereRaw('LOWER(category) = LOWER(?)', [$value]);
                    continue;
                }

                if ($filter === 'location_type') {
                    $query->where(fn ($query) => $query
                        ->where('location_type', $value)
                        ->orWhereHas('locations', fn ($query) => $query->where('location_type', $value)));

                    continue;
                }

                $query->where($filter, $value);
            }
        }
    }

    private function applyHousekeepingFilters($query, array $filters): void
    {
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(fn ($query) => $query
                ->whereRaw('LOWER(remarks) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(maintenance_notes) LIKE ?', ["%{$search}%"])
                ->orWhereHas('accommodation', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]))
                ->orWhereHas('assignedStaff', fn ($query) => $query
                    ->whereRaw('LOWER(first_name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$search}%"])));
        }

        foreach (['accommodation_id', 'status', 'priority', 'task_type'] as $filter) {
            if ($value = $filters[$filter] ?? null) {
                $query->where($filter, $value);
            }
        }
        if ($value = ($filters['employee_id'] ?? $filters['assigned_to'] ?? null)) {
            $query->where('employee_id', $value);
        }
    }

    private function applyAttendanceFilters($query, array $filters): void
    {
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->whereHas('staff', fn ($query) => $query
                ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(first_name) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$search}%"])
                ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]));
        }

        if ($role = $filters['role'] ?? null) {
            $query->whereHas('staff', fn ($query) => $query->where('role', $role));
        }

        foreach (['attendance_date', 'status', 'verification_method'] as $filter) {
            if ($value = $filters[$filter] ?? null) {
                $query->where($filter, $value);
            }
        }
    }

    private function reservationData(Reservation $reservation, bool $includePaymentHistory = false): array
    {
        $data = array_merge($reservation->publicData(), [
            'allowed_statuses' => Reservation::allowedAdminStatusTransitions()[$reservation->status] ?? [],
        ]);

        if ($includePaymentHistory) {
            $data['payment_history'] = $reservation->staffPaymentHistory();
        }

        return $data;
    }

    private function accommodationData(Accommodation $accommodation): array
    {
        $currentReservation = $accommodation->relationLoaded('reservations')
            ? $accommodation->reservations->first()
            : null;
        $activeHousekeepingTask = $accommodation->relationLoaded('housekeepingTasks')
            ? $accommodation->housekeepingTasks->first()
            : null;

        return array_merge($accommodation->publicData(), [
            'reservations_count' => $accommodation->reservations_count ?? $accommodation->reservations()->count(),
            'operational_status' => $accommodation->status === Accommodation::STATUS_MAINTENANCE
                ? Accommodation::STATUS_MAINTENANCE
                : ($currentReservation ? 'occupied' : $accommodation->status),
            'active_housekeeping_task' => $activeHousekeepingTask ? [
                'id' => $activeHousekeepingTask->id,
                'status' => $activeHousekeepingTask->status,
                'task_type' => $activeHousekeepingTask->task_type,
                'assigned_staff' => $activeHousekeepingTask->assignedStaff?->publicData(),
                'assigned_staff_name' => $activeHousekeepingTask->assigned_staff_name,
                'allowed_statuses' => HousekeepingTask::allowedStatusTransitions()[$activeHousekeepingTask->status] ?? [],
            ] : null,
            'created_at' => $accommodation->created_at?->toISOString(),
            'updated_at' => $accommodation->updated_at?->toISOString(),
        ]);
    }

    private function accommodationStatuses(): array
    {
        return [
            Accommodation::STATUS_AVAILABLE,
            Accommodation::STATUS_UNAVAILABLE,
            Accommodation::STATUS_MAINTENANCE,
        ];
    }

    private function inventorySummary(): array
    {
        return [
            'total_assets' => InventoryAsset::sum('quantity'),
            'available' => InventoryAsset::where('status', InventoryAsset::STATUS_AVAILABLE)->sum('quantity'),
            'assigned' => InventoryAsset::where('status', InventoryAsset::STATUS_ASSIGNED)->sum('quantity'),
            'under_maintenance' => InventoryAsset::where('status', InventoryAsset::STATUS_MAINTENANCE)->sum('quantity'),
            'damaged' => InventoryAsset::where('condition', InventoryAsset::CONDITION_DAMAGED)->sum('quantity'),
        ];
    }

    private function ensureAssetNotRetired(InventoryAsset $asset): void
    {
        if ($asset->status === InventoryAsset::STATUS_RETIRED) {
            throw ValidationException::withMessages([
                'status' => ['Retired assets are archived and cannot be modified.'],
            ]);
        }
    }

    private function recordInventoryHistory(Request $request, InventoryAsset $asset, string $action, mixed $old, mixed $new, ?string $remarks): void
    {
        $asset->histories()->create([
            'performed_by' => $request->user()?->id,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'remarks' => $remarks,
        ]);
        app(AuditLogger::class)->log($request, 'inventory', 'manager_'.$action, 'Inventory asset '.$action.' by manager.', $asset, [
            'before' => $old,
            'after' => $new,
            'remarks' => $remarks,
        ]);
    }

    private function housekeepingSummary(): array
    {
        return [
            'pending_tasks' => HousekeepingTask::where('status', HousekeepingTask::STATUS_PENDING)->count(),
            'in_progress' => HousekeepingTask::where('status', HousekeepingTask::STATUS_IN_PROGRESS)->count(),
            'completed_today' => HousekeepingTask::where('status', HousekeepingTask::STATUS_COMPLETED)->whereDate('completed_at', today())->count(),
            'needs_cleaning' => Accommodation::where('housekeeping_status', Accommodation::HOUSEKEEPING_NEEDS_CLEANING)->count(),
            'maintenance' => Accommodation::where('housekeeping_status', Accommodation::HOUSEKEEPING_MAINTENANCE)->count(),
        ];
    }

    private function housekeepingMeta(): array
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

    private function validatedHousekeepingAttributes(Request $request): array
    {
        $attributes = $request->validate([
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
        ]);

        return $attributes;
    }

    private function ensureHousekeepingEditable(HousekeepingTask $task): void
    {
        if (in_array($task->status, [HousekeepingTask::STATUS_COMPLETED, HousekeepingTask::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Completed or cancelled housekeeping tasks are preserved and cannot be modified.'],
            ]);
        }
    }

    private function withHousekeepingStatusTimestamps(HousekeepingTask $task, array $attributes): array
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

    private function syncAccommodationHousekeepingStatus(HousekeepingTask $task): void
    {
        $status = match ($task->status) {
            HousekeepingTask::STATUS_IN_PROGRESS => Accommodation::HOUSEKEEPING_CLEANING,
            HousekeepingTask::STATUS_COMPLETED => $task->task_type === HousekeepingTask::TYPE_MAINTENANCE
                ? Accommodation::HOUSEKEEPING_MAINTENANCE
                : Accommodation::HOUSEKEEPING_READY,
            HousekeepingTask::STATUS_CANCELLED => Accommodation::HOUSEKEEPING_NEEDS_CLEANING,
            default => $task->task_type === HousekeepingTask::TYPE_MAINTENANCE
                ? Accommodation::HOUSEKEEPING_MAINTENANCE
                : Accommodation::HOUSEKEEPING_NEEDS_CLEANING,
        };

        $task->accommodation?->forceFill(['housekeeping_status' => $status])->save();
    }

    private function recordHousekeepingHistory(Request $request, HousekeepingTask $task, string $action, mixed $old, mixed $new, ?string $remarks): void
    {
        $task->histories()->create([
            'performed_by' => $request->user()?->id,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'remarks' => $remarks,
        ]);
        app(AuditLogger::class)->log($request, 'housekeeping', 'manager_'.$action, 'Housekeeping task '.$action.' by manager.', $task, [
            'before' => $old,
            'after' => $new,
            'remarks' => $remarks,
        ]);
    }

    private function attendanceSummary(): array
    {
        return [
            'present_today' => AttendanceRecord::whereDate('attendance_date', today())->where('status', AttendanceRecord::STATUS_PRESENT)->count(),
            'late_today' => AttendanceRecord::whereDate('attendance_date', today())->where('status', AttendanceRecord::STATUS_LATE)->count(),
            'absent_today' => AttendanceRecord::whereDate('attendance_date', today())->where('status', AttendanceRecord::STATUS_ABSENT)->count(),
            'incomplete' => AttendanceRecord::where('status', AttendanceRecord::STATUS_INCOMPLETE)->count(),
        ];
    }

    private function attendanceMeta(): array
    {
        return [
            'statuses' => AttendanceRecord::statuses(),
            'verification_methods' => AttendanceRecord::verificationMethods(),
            'staff_roles' => AttendanceRecord::staffRoles(),
        ];
    }
}
