<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\FingerprintEnrollmentOperation;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FingerprintEnrollmentController extends Controller
{
    private const MAX_FINGERPRINT_ID = 127;

    public function start(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $attributes = $request->validate([
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->where('status', Employee::STATUS_ACTIVE)),
            ],
        ]);

        $lock = Cache::lock('iot:fingerprint-enrollment:start', 10);
        if (! $lock->get()) {
            return response()->json(['message' => 'Fingerprint enrollment is already being prepared.'], 409);
        }

        try {
            $operation = DB::transaction(function () use ($attributes, $request) {
                FingerprintEnrollmentOperation::query()
                    ->whereIn('status', [FingerprintEnrollmentOperation::STATUS_PENDING, FingerprintEnrollmentOperation::STATUS_CLAIMED])
                    ->where('expires_at', '<=', now())
                    ->update([
                        'status' => FingerprintEnrollmentOperation::STATUS_FAILED,
                        'message' => 'Fingerprint enrollment expired.',
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);

                if (FingerprintEnrollmentOperation::query()
                    ->whereIn('status', [FingerprintEnrollmentOperation::STATUS_PENDING, FingerprintEnrollmentOperation::STATUS_CLAIMED])
                    ->where('expires_at', '>', now())
                    ->exists()) {
                    abort(409, 'Another fingerprint enrollment is already in progress.');
                }

                $employee = Employee::query()->lockForUpdate()->findOrFail((int) $attributes['employee_id']);
                if ($employee->status !== Employee::STATUS_ACTIVE) {
                    abort(422, 'Only active employees can be enrolled.');
                }
                if ($employee->fingerprint_id !== null) {
                    abort(422, 'This employee already has a fingerprint enrolled.');
                }

                $used = Employee::query()
                    ->whereNotNull('fingerprint_id')
                    ->pluck('fingerprint_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $slot = collect(range(1, self::MAX_FINGERPRINT_ID))
                    ->first(fn (int $candidate) => ! in_array($candidate, $used, true));

                if ($slot === null) {
                    abort(422, 'No fingerprint slots are available.');
                }

                return FingerprintEnrollmentOperation::create([
                    'id' => (string) Str::uuid(),
                    'employee_id' => $employee->id,
                    'initiated_by' => $request->user()?->id,
                    'fingerprint_id' => $slot,
                    'status' => FingerprintEnrollmentOperation::STATUS_PENDING,
                    'message' => 'Waiting for the local fingerprint bridge.',
                    'expires_at' => now()->addSeconds(max(30, (int) config('iot.enrollment_operation_ttl_seconds', 180))),
                ]);
            });
        } finally {
            $lock->release();
        }

        $auditLogger->log($request, 'attendance', 'fingerprint_enrollment_started', 'Fingerprint enrollment started.', $operation, [
            'employee_id' => $operation->employee_id,
            'fingerprint_id' => $operation->fingerprint_id,
        ]);

        return response()->json([
            'data' => $operation->publicData(),
            'message' => 'Enrollment request created. Start the fingerprint scan when prompted.',
        ], 202);
    }

    public function status(FingerprintEnrollmentOperation $operation): JsonResponse
    {
        $this->expireIfNeeded($operation);

        return response()->json(['data' => $operation->fresh()->publicData()]);
    }

    public function cancel(FingerprintEnrollmentOperation $operation): JsonResponse
    {
        $operation->refresh();

        if ($operation->status === FingerprintEnrollmentOperation::STATUS_PENDING) {
            $operation->forceFill([
                'status' => FingerprintEnrollmentOperation::STATUS_CANCELLED,
                'message' => 'Fingerprint enrollment cancelled.',
                'completed_at' => now(),
            ])->save();
        } elseif ($operation->status === FingerprintEnrollmentOperation::STATUS_CLAIMED) {
            return response()->json(['message' => 'The bridge has started this enrollment and it cannot be cancelled now.'], 409);
        }

        return response()->json([
            'data' => $operation->fresh()->publicData(),
            'message' => 'Fingerprint enrollment cancelled.',
        ]);
    }

    public function claim(Request $request): JsonResponse
    {
        $deviceId = (string) $request->attributes->get('iot_device_id');
        $operation = DB::transaction(function () use ($deviceId) {
            FingerprintEnrollmentOperation::query()
                ->whereIn('status', [FingerprintEnrollmentOperation::STATUS_PENDING, FingerprintEnrollmentOperation::STATUS_CLAIMED])
                ->where('expires_at', '<=', now())
                ->update([
                    'status' => FingerprintEnrollmentOperation::STATUS_FAILED,
                    'message' => 'Fingerprint enrollment expired.',
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

            $operation = FingerprintEnrollmentOperation::query()
                ->where('status', FingerprintEnrollmentOperation::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (! $operation) {
                return null;
            }

            $operation->forceFill([
                'status' => FingerprintEnrollmentOperation::STATUS_CLAIMED,
                'device_id' => $deviceId,
                'claimed_at' => now(),
                'message' => 'Local bridge claimed the enrollment. Follow the sensor prompts.',
            ])->save();

            return $operation;
        });

        if (! $operation) {
            return response()->json([], 204);
        }

        return response()->json([
            'data' => [
                'id' => $operation->id,
                'fingerprint_id' => $operation->fingerprint_id,
                'expires_at' => $operation->expires_at?->toISOString(),
            ],
        ]);
    }

    public function complete(Request $request, FingerprintEnrollmentOperation $operation): JsonResponse
    {
        $attributes = $request->validate([
            'ok' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        $deviceId = (string) $request->attributes->get('iot_device_id');

        $result = DB::transaction(function () use ($attributes, $deviceId, $operation) {
            $lockedOperation = FingerprintEnrollmentOperation::query()->lockForUpdate()->find($operation->id);
            if (! $lockedOperation) {
                return ['status' => 404, 'message' => 'Fingerprint enrollment was not found.'];
            }

            if (in_array($lockedOperation->status, [
                FingerprintEnrollmentOperation::STATUS_SUCCEEDED,
                FingerprintEnrollmentOperation::STATUS_FAILED,
                FingerprintEnrollmentOperation::STATUS_CANCELLED,
            ], true)) {
                return ['status' => 200, 'data' => $lockedOperation->publicData()];
            }

            if ($lockedOperation->status !== FingerprintEnrollmentOperation::STATUS_CLAIMED
                || $lockedOperation->device_id !== $deviceId) {
                return ['status' => 409, 'message' => 'Fingerprint enrollment is not owned by this bridge.'];
            }

            if ($lockedOperation->expires_at?->isPast()) {
                $lockedOperation->forceFill([
                    'status' => FingerprintEnrollmentOperation::STATUS_FAILED,
                    'message' => 'Fingerprint enrollment expired.',
                    'completed_at' => now(),
                ])->save();

                return ['status' => 410, 'message' => 'Fingerprint enrollment expired.'];
            }

            if (! $attributes['ok']) {
                $lockedOperation->forceFill([
                    'status' => FingerprintEnrollmentOperation::STATUS_FAILED,
                    'message' => $attributes['message'] ?? 'The fingerprint scanner did not complete enrollment.',
                    'completed_at' => now(),
                ])->save();

                return ['status' => 200, 'data' => $lockedOperation->fresh()->publicData()];
            }

            $employee = Employee::query()->lockForUpdate()->find($lockedOperation->employee_id);
            $conflict = Employee::query()
                ->where('fingerprint_id', $lockedOperation->fingerprint_id)
                ->where('id', '<>', $lockedOperation->employee_id)
                ->exists();

            if (! $employee || $employee->status !== Employee::STATUS_ACTIVE || $conflict
                || ($employee->fingerprint_id !== null && (int) $employee->fingerprint_id !== (int) $lockedOperation->fingerprint_id)) {
                $lockedOperation->forceFill([
                    'status' => FingerprintEnrollmentOperation::STATUS_FAILED,
                    'message' => 'Fingerprint mapping could not be saved because the employee or fingerprint slot changed.',
                    'completed_at' => now(),
                ])->save();

                return ['status' => 409, 'message' => $lockedOperation->message];
            }

            $employee->forceFill(['fingerprint_id' => $lockedOperation->fingerprint_id])->save();
            $lockedOperation->forceFill([
                'status' => FingerprintEnrollmentOperation::STATUS_SUCCEEDED,
                'message' => $attributes['message'] ?? 'Fingerprint enrolled successfully.',
                'completed_at' => now(),
            ])->save();

            return ['status' => 200, 'data' => $lockedOperation->fresh()->publicData()];
        });

        return response()->json(
            isset($result['data']) ? ['data' => $result['data']] : ['message' => $result['message']],
            $result['status'],
        );
    }

    private function expireIfNeeded(FingerprintEnrollmentOperation $operation): void
    {
        if (in_array($operation->status, [FingerprintEnrollmentOperation::STATUS_PENDING, FingerprintEnrollmentOperation::STATUS_CLAIMED], true)
            && $operation->expires_at?->isPast()) {
            $operation->forceFill([
                'status' => FingerprintEnrollmentOperation::STATUS_FAILED,
                'message' => 'Fingerprint enrollment timed out. Confirm the bridge and Arduino are connected, then try again.',
                'completed_at' => now(),
            ])->save();
        }
    }
}
