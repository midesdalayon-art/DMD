<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class IotBridgeController extends Controller
{
    public function enroll(Request $request): JsonResponse
    {
        return $this->forward($request, '/fingerprints/enroll');
    }

    public function delete(Request $request): JsonResponse
    {
        return $this->forward($request, '/fingerprints/delete');
    }

    public function deleteForEmployee(Request $request, Employee $employee, AuditLogger $auditLogger): JsonResponse
    {
        $attributes = $request->validate([
            'fingerprint_id' => ['required', 'integer', 'between:1,127'],
        ]);
        $fingerprintId = (int) $attributes['fingerprint_id'];
        $lock = Cache::lock("iot:fingerprint-delete:employee:{$employee->id}", 30);

        if (! $lock->get()) {
            return response()->json(['message' => 'Fingerprint operation is already in progress.'], 409);
        }

        try {
            $current = $employee->fresh();
            if (! $current || (int) $current->fingerprint_id !== $fingerprintId) {
                return response()->json(['message' => 'The employee fingerprint mapping has changed. Refresh and try again.'], 409);
            }

            [$bridgeStatus, $bridgePayload] = $this->callBridge('/fingerprints/delete', $attributes);
            if ($bridgeStatus !== 200 || ! ($bridgePayload['ok'] ?? false)) {
                return response()->json(
                    $bridgePayload ?: ['message' => 'Fingerprint deletion did not complete.'],
                    $bridgeStatus >= 400 ? $bridgeStatus : 502,
                );
            }

            try {
                $result = DB::transaction(function () use ($employee, $fingerprintId) {
                    $lockedEmployee = Employee::query()->lockForUpdate()->find($employee->id);

                    if (! $lockedEmployee || (int) $lockedEmployee->fingerprint_id !== $fingerprintId) {
                        throw new FingerprintMappingChangedException();
                    }

                    $before = $lockedEmployee->publicData();
                    $lockedEmployee->forceFill(['fingerprint_id' => null])->save();

                    return [$lockedEmployee->fresh()->load('user'), $before];
                });
            } catch (Throwable $exception) {
                // Physical deletion succeeded but the mapping could not be committed. Best-effort
                // restoration prevents the sensor/database state from diverging silently.
                $this->callBridge('/fingerprints/enroll', ['fingerprint_id' => $fingerprintId]);
                report($exception);

                return response()->json([
                    'message' => 'Fingerprint deletion could not be synchronized. The physical fingerprint was restored where possible; refresh and try again.',
                ], 500);
            }

            [$updatedEmployee, $before] = $result;
            try {
                $auditLogger->log($request, 'attendance', 'fingerprint_removed', 'Fingerprint removed from employee.', $updatedEmployee, [
                    'before' => $before,
                    'after' => $updatedEmployee->publicData(),
                ]);
            } catch (Throwable $exception) {
                // Audit failure must not report a failed financial/device operation after the
                // mapping has already been safely cleared.
                report($exception);
            }

            return response()->json([
                'data' => $updatedEmployee->publicData(),
                'message' => 'Fingerprint removed.',
            ]);
        } finally {
            $lock->release();
        }
    }

    private function forward(Request $request, string $path): JsonResponse
    {
        $attributes = $request->validate([
            'fingerprint_id' => ['required', 'integer', 'between:1,127'],
        ]);
        $controlKey = (string) config('iot.bridge_control_key', '');

        if ($controlKey === '') {
            return response()->json(['message' => 'Fingerprint bridge is not configured.'], 503);
        }

        [$status, $payload] = $this->callBridge($path, $attributes);

        return response()->json($payload, $status);
    }

    private function callBridge(string $path, array $attributes): array
    {
        $controlKey = (string) config('iot.bridge_control_key', '');
        if ($controlKey === '') {
            return [503, ['message' => 'Fingerprint bridge is not configured.']];
        }

        try {
            $response = Http::connectTimeout(2)
                ->timeout((int) config('iot.bridge_http_timeout', 20))
                ->acceptJson()
                ->withHeaders(['X-Bridge-Key' => $controlKey])
                ->post(config('iot.bridge_url').$path, $attributes);
        } catch (Throwable) {
            return [503, ['message' => 'Fingerprint bridge is unavailable.']];
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return [502, ['message' => 'Fingerprint bridge returned an invalid response.']];
        }

        return [$response->status(), $payload];
    }
}

final class FingerprintMappingChangedException extends \RuntimeException
{
}
