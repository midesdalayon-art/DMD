<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        ?Request $request,
        string $module,
        string $action,
        string $description,
        ?object $entity = null,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        try {
            $actor ??= $request?->user();

            AuditLog::create([
                'user_id' => $actor?->id,
                'action' => $action,
                'module' => $module,
                'description' => $description,
                'entity_type' => $entity ? $entity::class : null,
                'entity_id' => $entity?->id ?? null,
                'ip_address' => $request?->ip(),
                'request_method' => $request?->method(),
                'metadata' => $this->sanitize($metadata),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Audit log write failed.', [
                'module' => $module,
                'action' => $action,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitize(array $metadata): array
    {
        $blockedKeys = [
            'password',
            'password_confirmation',
            'password_hash',
            'remember_token',
            'token',
            'access_token',
            'refresh_token',
            'csrf',
            'xsrf',
            'cookie',
            'secret',
            'authorization',
        ];

        $clean = [];

        foreach ($metadata as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            $isSensitive = collect($blockedKeys)->contains(fn (string $blocked) => str_contains($normalizedKey, $blocked));

            if ($isSensitive) {
                $clean[$key] = '[redacted]';
                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }
}
