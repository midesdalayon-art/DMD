<?php

namespace App\Http\Controllers\Api\Iot;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Services\AuditLogger;
use App\Events\AttendanceUpdated;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FingerprintAttendanceController extends Controller
{
    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $timingStartedAt = microtime(true);
        $timings = [];
        $markTiming = function (string $stage) use (&$timings, $timingStartedAt): void {
            if (config('iot.timing_logging', false)) {
                $timings[$stage] = round((microtime(true) - $timingStartedAt) * 1000, 2);
            }
        };

        $attributes = $request->validate([
            'fingerprint_id' => ['required', 'integer', 'min:1'],
            'confidence' => ['required', 'integer', 'min:0', 'max:1000'],
            'device' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        if (! $request->attributes->get('iot_legacy_auth')
            && ($attributes['device'] ?? null) !== $request->attributes->get('iot_device_id')) {
            throw ValidationException::withMessages([
                'device' => ['The fingerprint device identity is invalid.'],
            ]);
        }

        $employee = Employee::query()
            ->where('fingerprint_id', $attributes['fingerprint_id'])
            ->first();
        $markTiming('employee_lookup_ms');

        if (! $employee) {
            throw ValidationException::withMessages([
                'fingerprint_id' => ['This fingerprint is not assigned to an employee.'],
            ]);
        }

        if ($employee->status !== Employee::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'fingerprint_id' => ['This employee is inactive.'],
            ]);
        }

        $now = now();
        $device = $attributes['device'] ?? null;
        $result = DB::transaction(function () use ($employee, $attributes, $device, $now) {
            $record = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $now->toDateString())
                ->lockForUpdate()
                ->first();

            if (! $record) {
                $record = AttendanceRecord::create([
                    'employee_id' => $employee->id,
                    'user_id' => $employee->user_id,
                    'attendance_date' => $now->toDateString(),
                    'time_in' => $now->format('H:i:s'),
                    'time_out' => null,
                    'status' => AttendanceRecord::STATUS_PRESENT,
                    'verification_method' => AttendanceRecord::METHOD_FINGERPRINT,
                    'device_id' => $device,
                    'remarks' => 'Fingerprint match received from sensor.',
                ]);

                $action = 'fingerprint_time_in';
                $message = 'Fingerprint attendance recorded with time in.';
            } elseif ($this->isRapidRepeat($record, $now)) {
                $action = 'fingerprint_duplicate_ignored';
                $message = 'Repeated fingerprint scan ignored.';
            } elseif ($record->time_out === null) {
                $record->forceFill([
                    'time_out' => $now->format('H:i:s'),
                    'verification_method' => AttendanceRecord::METHOD_FINGERPRINT,
                    'device_id' => $device ?: $record->device_id,
                    'status' => AttendanceRecord::STATUS_PRESENT,
                ])->save();

                $action = 'fingerprint_time_out';
                $message = 'Fingerprint attendance updated with time out.';
            } else {
                $action = 'fingerprint_duplicate_ignored';
                $message = 'Attendance for this employee is already complete for today.';
            }

            return compact('record', 'action', 'message');
        });
        $markTiming('attendance_transaction_ms');

        $record = $result['record']->load(['employee.user', 'staff', 'corrector']);
        $record->histories()->create([
            'performed_by' => null,
            'action' => $result['action'],
            'old_value' => null,
            'new_value' => $record->publicData(),
            'remarks' => 'Processed by authenticated fingerprint device.',
        ]);
        $auditLogger->log($request, 'attendance', $result['action'], 'Fingerprint attendance scan processed.', $record, [
            'fingerprint_id' => (int) $attributes['fingerprint_id'],
            'confidence' => (int) $attributes['confidence'],
            'device' => $device,
        ]);
        $markTiming('audit_ms');

        if ($result['action'] !== 'fingerprint_duplicate_ignored') {
            AttendanceUpdated::dispatch($record->id, $record->employee_id, $result['action']);
        }
        $markTiming('broadcast_dispatch_ms');

        if (config('iot.timing_logging', false)) {
            Log::info('iot.attendance.timing', [
                'action' => $result['action'],
                'employee_id' => $record->employee_id,
                'timings' => $timings,
                'total_ms' => round((microtime(true) - $timingStartedAt) * 1000, 2),
            ]);
        }

        return response()->json([
            'success' => true,
            'action' => match ($result['action']) {
                'fingerprint_time_in' => 'time_in',
                'fingerprint_time_out' => 'time_out',
                'fingerprint_duplicate_ignored' => 'ignored',
                default => $result['action'],
            },
            'employee' => [
                'id' => $record->employee_id,
                'name' => $record->employee?->publicData()['name'] ?? 'Unknown employee',
            ],
            'data' => $record->publicData(),
            'message' => $result['message'],
            'fingerprint_id' => (int) $attributes['fingerprint_id'],
            'confidence' => (int) $attributes['confidence'],
        ], 200);
    }

    private function isRapidRepeat(AttendanceRecord $record, Carbon $now): bool
    {
        if ($record->time_in === null || $record->time_out !== null) {
            return false;
        }

        $timeIn = Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $record->attendance_date->toDateString().' '.$record->time_in,
            config('app.timezone')
        );

        return $timeIn->diffInSeconds($now) < max(1, (int) config('iot.fingerprint_duplicate_window_seconds', 60));
    }
}
