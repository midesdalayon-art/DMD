<?php

return [
    'device_key' => env('IOT_DEVICE_KEY'),
    'allow_legacy_device_key' => filter_var(env('IOT_ALLOW_LEGACY_DEVICE_KEY', false), FILTER_VALIDATE_BOOL),
    'device_id' => env('IOT_DEVICE_ID', 'mega-as608-01'),
    'devices' => (static function (): array {
        $configured = json_decode((string) env('IOT_DEVICES', ''), true);

        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return [
            (string) env('IOT_DEVICE_ID', 'mega-as608-01') => (string) env('IOT_DEVICE_KEY', ''),
        ];
    })(),
    'signature_tolerance_seconds' => (int) env('IOT_SIGNATURE_TOLERANCE_SECONDS', 300),
    'replay_cache_seconds' => (int) env('IOT_REPLAY_CACHE_SECONDS', 300),
    'attendance_rate_limit' => (int) env('IOT_ATTENDANCE_RATE_LIMIT', 120),
    'timing_logging' => filter_var(env('IOT_TIMING_LOGGING', false), FILTER_VALIDATE_BOOL),
    'bridge_url' => rtrim((string) env('IOT_BRIDGE_URL', 'http://127.0.0.1:8765'), '/'),
    'bridge_control_key' => env('IOT_BRIDGE_CONTROL_KEY'),
    'bridge_http_timeout' => (int) env('IOT_BRIDGE_HTTP_TIMEOUT', 20),
    'enrollment_operation_ttl_seconds' => (int) env('IOT_ENROLLMENT_OPERATION_TTL_SECONDS', 180),
    'fingerprint_duplicate_window_seconds' => (int) env('IOT_FINGERPRINT_DUPLICATE_WINDOW_SECONDS', 60),
];
