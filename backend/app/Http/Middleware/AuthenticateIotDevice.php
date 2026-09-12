<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIotDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $timingStartedAt = microtime(true);
        $deviceId = trim((string) $request->header('X-Device-Id', ''));
        $timestamp = trim((string) $request->header('X-Device-Timestamp', ''));
        $nonce = trim((string) $request->header('X-Device-Nonce', ''));
        $signature = trim((string) $request->header('X-Device-Signature', ''));
        $legacyKey = (string) $request->header('X-Device-Key', '');
        if (config('iot.allow_legacy_device_key', false)
            && $legacyKey !== ''
            && ($expectedLegacy = (string) config('iot.device_key', '')) !== ''
            && hash_equals($expectedLegacy, $legacyKey)) {
            $request->attributes->set('iot_device_id', (string) config('iot.device_id', 'mega-as608-01'));
            $request->attributes->set('iot_legacy_auth', true);
            return $next($request);
        }
        $devices = (array) config('iot.devices', []);
        $secret = (string) ($devices[$deviceId] ?? '');
        $tolerance = max(1, (int) config('iot.signature_tolerance_seconds', 300));

        if ($deviceId === '' || $timestamp === '' || $nonce === '' || $signature === '' || $secret === '' || ! ctype_digit($timestamp)) {
            return response()->json(['message' => 'Invalid IoT device credentials.'], 401);
        }

        $timestampValue = (int) $timestamp;
        if (abs(now()->timestamp - $timestampValue) > $tolerance) {
            return response()->json(['message' => 'Invalid IoT device credentials.'], 401);
        }

        $bodyHash = hash('sha256', (string) $request->getContent());
        $canonical = implode("\n", [
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            $timestamp,
            $nonce,
            $bodyHash,
        ]);
        $expectedSignature = hash_hmac('sha256', $canonical, $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json(['message' => 'Invalid IoT device credentials.'], 401);
        }

        $replayKey = 'iot:device-replay:'.hash('sha256', $deviceId.'|'.$nonce);
        if (! Cache::add($replayKey, true, max(1, (int) config('iot.replay_cache_seconds', $tolerance)))) {
            return response()->json(['message' => 'Invalid IoT device credentials.'], 401);
        }

        $request->attributes->set('iot_device_id', $deviceId);
        $response = $next($request);

        if (config('iot.timing_logging', false)) {
            Log::info('iot.attendance.middleware_timing', [
                'device_id' => $deviceId,
                'total_ms' => round((microtime(true) - $timingStartedAt) * 1000, 2),
            ]);
        }

        return $response;
    }
}
