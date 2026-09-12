<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordHistory;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Events\AttendanceUpdated;

class AttendanceRecordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', Rule::in(AttendanceRecord::statuses())],
            'attendance_date' => ['sometimes', 'nullable', 'date'],
            'verification_method' => ['sometimes', 'nullable', Rule::in(AttendanceRecord::verificationMethods())],
            'employee_status' => ['sometimes', 'nullable', Rule::in([Employee::STATUS_ACTIVE, Employee::STATUS_INACTIVE])],
        ]);

        $query = AttendanceRecord::query()
            ->with(['employee.user', 'staff', 'corrector'])
            ->latest('attendance_date')
            ->latest('updated_at');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(function ($query) use ($search) {
                $query->whereHas('employee', function ($employeeQuery) use ($search) {
                    $employeeQuery
                        ->whereRaw('LOWER(employee_code) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(first_name) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(position) LIKE ?', ["%{$search}%"])
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery
                                ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                                ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
                        });
                });
            });
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($attendanceDate = $filters['attendance_date'] ?? null) {
            $query->whereDate('attendance_date', $attendanceDate);
        }

        if ($verificationMethod = $filters['verification_method'] ?? null) {
            $query->where('verification_method', $verificationMethod);
        }

        if ($employeeStatus = $filters['employee_status'] ?? null) {
            $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('status', $employeeStatus));
        }

        return response()->json([
            'data' => $query->get()->map(fn (AttendanceRecord $record) => $record->publicData())->values(),
            'summary' => $this->summary(),
            'meta' => $this->meta(),
        ]);
    }

    public function show(AttendanceRecord $attendanceRecord): JsonResponse
    {
        return response()->json([
            'data' => $attendanceRecord->load(['employee.user', 'staff', 'corrector'])->publicData(),
            'history' => $attendanceRecord->histories()
                ->with('performer')
                ->latest()
                ->get()
                ->map(fn (AttendanceRecordHistory $history) => $history->publicData())
                ->values(),
            'meta' => $this->meta(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validatedAttributes($request);
        $employee = $this->assertActiveEmployee((int) $attributes['employee_id']);
        $this->validateTimeRange($attributes);

        if (($attributes['verification_method'] ?? AttendanceRecord::METHOD_MANUAL) === AttendanceRecord::METHOD_MANUAL && empty($attributes['remarks'])) {
            throw ValidationException::withMessages([
                'remarks' => ['Manual attendance records require a reason.'],
            ]);
        }

        $record = DB::transaction(function () use ($attributes, $employee) {
            $existing = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $attributes['attendance_date'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'attendance_date' => ['This employee already has an attendance record for the selected date. Edit the existing record instead.'],
                ]);
            }

            return AttendanceRecord::create(array_merge($attributes, [
                'user_id' => $employee->user_id,
            ]));
        })->load(['employee.user', 'staff', 'corrector']);

        $this->recordHistory($request, $record, 'created', null, $record->publicData(), $attributes['remarks'] ?? null);
        AttendanceUpdated::dispatch($record->id, $record->employee_id, 'manual_created');

        return response()->json([
            'data' => $record->publicData(),
            'message' => 'Attendance record created.',
        ], 201);
    }

    public function update(Request $request, AttendanceRecord $attendanceRecord): JsonResponse
    {
        $attributes = $this->validatedAttributes($request, $attendanceRecord);
        $employee = $this->assertActiveEmployee((int) $attributes['employee_id']);
        $this->validateTimeRange($attributes);

        if (empty($attributes['correction_reason'])) {
            throw ValidationException::withMessages([
                'correction_reason' => ['Manual corrections require a reason.'],
            ]);
        }

        $old = $attendanceRecord->load(['employee.user', 'staff', 'corrector'])->publicData();
        $reason = $attributes['correction_reason'];
        unset($attributes['correction_reason']);

        $attendanceRecord->forceFill(array_merge($attributes, [
            'employee_id' => $employee->id,
            'user_id' => $employee->user_id,
            'corrected_by' => $request->user()->id,
            'corrected_at' => now(),
        ]))->save();

        $attendanceRecord->load(['employee.user', 'staff', 'corrector']);
        $this->recordHistory($request, $attendanceRecord, 'corrected', $old, $attendanceRecord->publicData(), $reason);
        AttendanceUpdated::dispatch($attendanceRecord->id, $attendanceRecord->employee_id, 'manual_updated');

        return response()->json([
            'data' => $attendanceRecord->publicData(),
            'message' => 'Attendance record corrected.',
        ]);
    }

    private function validatedAttributes(Request $request, ?AttendanceRecord $record = null): array
    {
        return $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where(fn ($query) => $query->where('status', Employee::STATUS_ACTIVE))],
            'attendance_date' => [
                'required',
                'date',
                Rule::unique('attendance_records', 'attendance_date')
                    ->where(fn ($query) => $query->where('employee_id', $request->integer('employee_id')))
                    ->ignore($record?->id),
            ],
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'status' => ['required', Rule::in(AttendanceRecord::statuses())],
            'verification_method' => ['required', Rule::in(AttendanceRecord::verificationMethods())],
            'device_id' => ['nullable', 'string', 'max:120'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'correction_reason' => [$record ? 'required' : 'sometimes', 'nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function validateTimeRange(array $attributes): void
    {
        if (! empty($attributes['time_in']) && ! empty($attributes['time_out']) && $attributes['time_out'] <= $attributes['time_in']) {
            throw ValidationException::withMessages([
                'time_out' => ['Time out must be later than time in.'],
            ]);
        }

        if ($attributes['status'] === AttendanceRecord::STATUS_PRESENT && empty($attributes['time_in'])) {
            throw ValidationException::withMessages([
                'time_in' => ['Present attendance requires a time in.'],
            ]);
        }
    }

    private function assertActiveEmployee(int $employeeId): Employee
    {
        $employee = Employee::query()->with('user')->find($employeeId);

        if (! $employee || $employee->status !== Employee::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'employee_id' => ['Choose an active employee.'],
            ]);
        }

        return $employee;
    }

    private function recordHistory(Request $request, AttendanceRecord $record, string $action, mixed $old, mixed $new, ?string $remarks): void
    {
        $record->histories()->create([
            'performed_by' => $request->user()?->id,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'remarks' => $remarks,
        ]);

        app(AuditLogger::class)->log($request, 'attendance', $action === 'created' ? 'manual_attendance_created' : 'attendance_corrected', 'Attendance record '.$action.'.', $record, [
            'before' => $old,
            'after' => $new,
            'remarks' => $remarks,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        return [
            'present_today' => AttendanceRecord::whereDate('attendance_date', today())
                ->where('status', AttendanceRecord::STATUS_PRESENT)
                ->count(),
            'late_today' => AttendanceRecord::whereDate('attendance_date', today())
                ->where('status', AttendanceRecord::STATUS_LATE)
                ->count(),
            'absent_today' => AttendanceRecord::whereDate('attendance_date', today())
                ->where('status', AttendanceRecord::STATUS_ABSENT)
                ->count(),
            'incomplete' => AttendanceRecord::where('status', AttendanceRecord::STATUS_INCOMPLETE)->count(),
        ];
    }

    private function meta(): array
    {
        return [
            'statuses' => AttendanceRecord::statuses(),
            'verification_methods' => AttendanceRecord::verificationMethods(),
            'employee_statuses' => [Employee::STATUS_ACTIVE, Employee::STATUS_INACTIVE],
            'employees' => Employee::query()
                ->with('user')
                ->orderBy('status')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->map(fn (Employee $employee) => $employee->publicData())
                ->values(),
            'staff_roles' => AttendanceRecord::staffRoles(),
            'staff' => User::query()
                ->whereIn('role', AttendanceRecord::staffRoles())
                ->where('is_active', true)
                ->orderBy('role')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => $user->publicProfile())
                ->values(),
        ];
    }
}
