<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SystemLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'role' => ['sometimes', 'nullable', Rule::in(User::roles())],
            'module' => ['sometimes', 'nullable', 'string', 'max:80'],
            'action' => ['sometimes', 'nullable', 'string', 'max:80'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = AuditLog::query()
            ->with('actor')
            ->latest();

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(function ($query) use ($search) {
                $query
                    ->whereRaw('LOWER(description) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(action) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(module) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('actor', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]));
            });
        }

        if ($userId = $filters['user_id'] ?? null) {
            $query->where('user_id', $userId);
        }

        if ($role = $filters['role'] ?? null) {
            $query->whereHas('actor', fn ($query) => $query->where('role', $role));
        }

        foreach (['module', 'action'] as $filter) {
            if ($value = $filters[$filter] ?? null) {
                $query->where($filter, $value);
            }
        }

        if ($startDate = $filters['start_date'] ?? null) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate = $filters['end_date'] ?? null) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        return response()->json([
            'data' => $query->paginate(20)->through(fn (AuditLog $log) => $log->publicData()),
            'summary' => $this->summary(),
            'meta' => [
                'modules' => AuditLog::query()->select('module')->distinct()->orderBy('module')->pluck('module')->values(),
                'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action')->values(),
                'roles' => User::roles(),
                'users' => User::query()->orderBy('name')->get()->map(fn (User $user) => $user->publicProfile())->values(),
            ],
        ]);
    }

    public function show(AuditLog $systemLog): JsonResponse
    {
        return response()->json([
            'data' => $systemLog->load('actor')->publicData(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        return [
            'activity_today' => AuditLog::whereDate('created_at', today())->count(),
            'user_actions' => AuditLog::where('module', 'user_management')->count(),
            'security_events' => AuditLog::where('module', 'authentication')->count(),
            'system_changes' => AuditLog::whereNotIn('module', ['authentication'])->count(),
        ];
    }
}
