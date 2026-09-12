<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    private const MAX_FINGERPRINT_ID = 127;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', Rule::in([Employee::STATUS_ACTIVE, Employee::STATUS_INACTIVE])],
        ]);

        $query = Employee::query()->with('user')->orderBy('employee_code');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(function ($query) use ($search) {
                $query->whereRaw('LOWER(employee_code) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(position) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('user', function ($query) use ($search) {
                        $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                            ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
                    });
            });
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        return response()->json([
            'data' => $query->get()->map(fn (Employee $employee) => $employee->publicData())->values(),
            'meta' => [
                'statuses' => [Employee::STATUS_ACTIVE, Employee::STATUS_INACTIVE],
                'users' => User::query()
                    ->whereIn('role', Employee::loginableUserRoles())
                    ->orderBy('name')
                    ->get()
                    ->map(fn (User $user) => $user->publicProfile())
                    ->values(),
            ],
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $attributes = $this->validateInput($request);

        $employee = DB::transaction(function () use ($attributes) {
            $employee = Employee::create([
                'employee_code' => $this->nextCode(),
                // The new employee UI omits this field. Preserve explicit
                // legacy links for existing integrations and data.
                'user_id' => ! empty($attributes['user_id']) ? (int) $attributes['user_id'] : null,
                'fingerprint_id' => $attributes['fingerprint_id'] ?? null,
                'first_name' => $attributes['first_name'],
                'last_name' => $attributes['last_name'],
                'position' => $attributes['position'],
                'phone' => $attributes['phone'] ?: null,
                'date_hired' => $attributes['date_hired'] ?: null,
                'status' => $attributes['status'],
            ]);

            return $employee->load('user');
        });

        $auditLogger->log($request, 'attendance', 'employee_created', 'Employee created.', $employee, [
            'employee_code' => $employee->employee_code,
            'position' => $employee->position,
        ]);

        return response()->json([
            'data' => $employee->publicData(),
            'message' => 'Employee created.',
        ], 201);
    }

    public function update(Request $request, Employee $employee, AuditLogger $auditLogger): JsonResponse
    {
        $attributes = $this->validateInput($request, $employee);
        $old = $employee->publicData();

        $employee->forceFill([
            // Preserve a legacy account link when editing an existing linked
            // record; new employee workers cannot create one through this API.
            'user_id' => array_key_exists('user_id', $attributes)
                ? (! empty($attributes['user_id']) ? (int) $attributes['user_id'] : null)
                : $employee->user_id,
            'fingerprint_id' => $attributes['fingerprint_id'] ?? null,
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'],
            'position' => $attributes['position'],
            'phone' => $attributes['phone'] ?: null,
            'date_hired' => $attributes['date_hired'] ?: null,
            'status' => $attributes['status'],
        ])->save();

        $fresh = $employee->fresh()->load('user');
        $auditLogger->log($request, 'attendance', 'employee_updated', 'Employee updated.', $fresh, [
            'before' => $old,
            'after' => $fresh->publicData(),
        ]);

        return response()->json([
            'data' => $fresh->publicData(),
            'message' => 'Employee updated.',
        ]);
    }

    public function destroy(Request $request, Employee $employee, AuditLogger $auditLogger): JsonResponse
    {
        if ($employee->attendanceRecords()->exists()) {
            $employee->forceFill(['status' => Employee::STATUS_INACTIVE])->save();
            $auditLogger->log($request, 'attendance', 'employee_deactivated', 'Employee deactivated.', $employee, [
                'employee_code' => $employee->employee_code,
            ]);

            return response()->json([
                'data' => $employee->fresh()->load('user')->publicData(),
                'message' => 'Employee has attendance history and was deactivated instead of deleted.',
            ]);
        }

        $employee->delete();
        $auditLogger->log($request, 'attendance', 'employee_deleted', 'Employee deleted.', null, [
            'employee_code' => $employee->employee_code,
        ]);

        return response()->json([
            'message' => 'Employee deleted.',
        ]);
    }

    public function fingerprintSlot(): JsonResponse
    {
        $used = Employee::query()->whereNotNull('fingerprint_id')->pluck('fingerprint_id')->map(fn ($id) => (int) $id)->all();

        for ($slot = 1; $slot <= self::MAX_FINGERPRINT_ID; $slot++) {
            if (! in_array($slot, $used, true)) {
                return response()->json(['fingerprint_id' => $slot]);
            }
        }

        throw ValidationException::withMessages([
            'fingerprint_id' => ['No fingerprint slots are available.'],
        ]);
    }

    public function assignFingerprint(Request $request, Employee $employee, AuditLogger $auditLogger): JsonResponse
    {
        $attributes = $request->validate([
            'fingerprint_id' => [
                'required',
                'integer',
                'between:1,'.self::MAX_FINGERPRINT_ID,
                Rule::unique('employees', 'fingerprint_id')->ignore($employee->id),
            ],
        ]);

        $lock = Cache::lock("iot:fingerprint-delete:employee:{$employee->id}", 30);
        if (! $lock->get()) {
            return response()->json(['message' => 'Fingerprint operation is already in progress.'], 409);
        }

        try {
            $old = $employee->publicData();
            $employee->forceFill(['fingerprint_id' => $attributes['fingerprint_id']])->save();
            $fresh = $employee->fresh()->load('user');
        } finally {
            $lock->release();
        }

        $auditLogger->log($request, 'attendance', 'fingerprint_assigned', 'Fingerprint assigned to employee.', $fresh, [
            'before' => $old,
            'after' => $fresh->publicData(),
        ]);

        return response()->json(['data' => $fresh->publicData(), 'message' => 'Fingerprint assigned.']);
    }

    public function clearFingerprint(Request $request, Employee $employee, AuditLogger $auditLogger): JsonResponse
    {
        $old = $employee->publicData();
        $employee->forceFill(['fingerprint_id' => null])->save();
        $fresh = $employee->fresh()->load('user');

        $auditLogger->log($request, 'attendance', 'fingerprint_removed', 'Fingerprint removed from employee.', $fresh, [
            'before' => $old,
            'after' => $fresh->publicData(),
        ]);

        return response()->json(['data' => $fresh->publicData(), 'message' => 'Fingerprint removed.']);
    }

    private function validateInput(Request $request, ?Employee $employee = null): array
    {
        $attributes = $request->validate([
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', Employee::loginableUserRoles())),
                Rule::unique('employees', 'user_id')->ignore($employee?->id),
            ],
            'fingerprint_id' => [
                'sometimes',
                'nullable',
                'integer',
                'between:1,'.self::MAX_FINGERPRINT_ID,
                Rule::unique('employees', 'fingerprint_id')->ignore($employee?->id),
            ],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'position' => ['required', 'string', 'max:120', Rule::in(array_merge(Employee::positions(), ['Admin', 'Manager', 'Front Desk', 'Housekeeping Staff', 'Staff']))],
            'custom_position' => ['nullable', 'string', 'max:120', 'required_if:position,Other'],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_hired' => ['nullable', 'date'],
            'status' => ['required', Rule::in([Employee::STATUS_ACTIVE, Employee::STATUS_INACTIVE])],
        ]);

        if (($attributes['position'] ?? null) === 'Other') {
            $customPosition = trim((string) ($attributes['custom_position'] ?? ''));
            if ($customPosition === '') {
                throw ValidationException::withMessages([
                    'custom_position' => ['Enter a custom position.'],
                ]);
            }
            $attributes['position'] = $customPosition;
        }

        if (! empty($attributes['user_id']) && ! in_array($attributes['position'], Employee::loginablePositions(), true)) {
            throw ValidationException::withMessages([
                'user_id' => ['Cleaning, maintenance, and other employees cannot have a system login account.'],
            ]);
        }

        if (! empty($attributes['user_id'])) {
            $user = User::find((int) $attributes['user_id']);
            $positionRole = [
                'Admin' => User::ROLE_ADMIN,
                'Manager' => User::ROLE_MANAGER,
                'Front Desk' => User::ROLE_FRONT_DESK,
            ][$attributes['position']] ?? null;

            if ($positionRole && $user?->normalizedRole() !== $positionRole) {
                throw ValidationException::withMessages([
                    'user_id' => ['The selected system account role must match the employee position.'],
                ]);
            }
        }

        return $attributes;
    }

    private function nextCode(): string
    {
        return DB::transaction(function () {
            $sequence = DB::table('employee_code_sequences')
                ->where('prefix', 'EMP')
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                $maxExisting = (int) (Employee::query()
                    ->selectRaw("MAX(CAST(SUBSTRING(employee_code, 5) AS UNSIGNED)) as max_number")
                    ->value('max_number') ?? 0);

                try {
                    DB::table('employee_code_sequences')->insert([
                        'prefix' => 'EMP',
                        'last_number' => $maxExisting,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (QueryException $exception) {
                    if ((string) $exception->getCode() !== '23505') {
                        throw $exception;
                    }
                }

                $sequence = DB::table('employee_code_sequences')
                    ->where('prefix', 'EMP')
                    ->lockForUpdate()
                    ->first();
            }

            $nextNumber = ((int) $sequence->last_number) + 1;

            DB::table('employee_code_sequences')
                ->where('prefix', 'EMP')
                ->update([
                    'last_number' => $nextNumber,
                    'updated_at' => now(),
                ]);

            return sprintf('EMP-%04d', $nextNumber);
        });
    }
}
