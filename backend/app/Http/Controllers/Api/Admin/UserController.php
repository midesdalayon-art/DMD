<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogger;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['sometimes', 'nullable', Rule::in(User::roles())],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50])],
        ]);

        $query = User::query()
            ->withCount('reservations')
            ->orderBy('role')
            ->orderBy('name');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(function ($query) use ($search) {
                $query
                    ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($name = trim((string) ($filters['name'] ?? ''))) {
            $name = mb_strtolower($name);
            $query->where(function ($query) use ($name) {
                $query
                    ->whereRaw('LOWER(name) LIKE ?', ["%{$name}%"])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', ["%{$name}%"])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$name}%"]);
            });
        }

        if ($email = trim((string) ($filters['email'] ?? ''))) {
            $email = mb_strtolower($email);
            $query->whereRaw('LOWER(email) LIKE ?', ["%{$email}%"]);
        }

        if ($role = $filters['role'] ?? null) {
            $query->where('role', $role);
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('is_active', $status === 'active');
        }

        return response()->json([
            'data' => $query->paginate((int) ($filters['per_page'] ?? 10))->through(fn (User $user) => $this->adminData($user)),
            'meta' => [
                'roles' => User::roles(),
                'statuses' => ['active', 'inactive'],
                'summary' => [
                    'total_users' => User::count(),
                    'staff' => User::where('role', '!=', User::ROLE_GUEST)->count(),
                    'guests' => User::where('role', User::ROLE_GUEST)->count(),
                    'active_accounts' => User::where('is_active', true)->count(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = $request->validate($this->rules(), $this->messages());
        if ($attributes['role'] === User::ROLE_GUEST) {
            throw ValidationException::withMessages([
                'role' => ['Use public registration for guest accounts.'],
            ]);
        }

        $user = User::create($this->payload($attributes));
        app(AuditLogger::class)->log($request, 'user_management', 'staff_created', 'Staff account created.', $user, [
            'role' => $user->normalizedRole(),
            'email' => $user->email,
        ]);

        return response()->json([
            'data' => $this->adminData($user->loadCount('reservations')),
            'message' => 'Staff account created.',
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'data' => $this->adminData($user->loadCount('reservations')),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $attributes = $request->validate($this->rules($user, false), $this->messages());
        $this->guardLastActiveAdmin($request->user(), $user, $attributes['role'], (bool) $attributes['is_active']);
        $old = $this->adminData($user->loadCount('reservations'));

        $user->forceFill($this->payload($attributes, false))->save();
        $fresh = $user->fresh()->loadCount('reservations')->load('employee');
        $action = $old['role'] !== $fresh->normalizedRole() ? 'role_changed' : 'profile_updated';
        app(AuditLogger::class)->log($request, 'user_management', $action, 'User account updated.', $fresh, [
            'before' => $old,
            'after' => $this->adminData($fresh),
        ]);

        return response()->json([
            'data' => $this->adminData($fresh),
            'message' => 'User account updated.',
        ]);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $attributes = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $this->guardLastActiveAdmin($request->user(), $user, $user->normalizedRole(), (bool) $attributes['is_active']);
        $oldStatus = $user->is_active ? 'active' : 'inactive';

        $user->forceFill([
            'is_active' => $attributes['is_active'],
        ])->save();
        $fresh = $user->fresh()->loadCount('reservations');
        app(AuditLogger::class)->log($request, 'user_management', $fresh->is_active ? 'account_activated' : 'account_deactivated', 'User account status changed.', $fresh, [
            'before' => ['status' => $oldStatus],
            'after' => ['status' => $fresh->is_active ? 'active' : 'inactive'],
        ]);

        return response()->json([
            'data' => $this->adminData($fresh),
            'message' => $user->is_active ? 'User activated.' : 'User deactivated.',
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $attributes = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        $user->forceFill([
            'password' => Hash::make($attributes['password']),
        ])->save();
        app(AuditLogger::class)->log($request, 'user_management', 'password_reset_performed', 'Password reset performed by admin.', $user, [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
        ]);

        return response()->json([
            'data' => $this->adminData($user->fresh()->loadCount('reservations')),
            'message' => 'Password reset.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?User $user = null, bool $requiresPassword = true): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'contact_number' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s().-]{7,20}$/'],
            'role' => ['required', Rule::in(User::roles())],
            'is_active' => ['required', 'boolean'],
            'password' => [$requiresPassword ? 'required' : 'sometimes', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'contact_number.regex' => 'Enter a valid contact number.',
            'password.confirmed' => 'Password confirmation does not match.',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function payload(array $attributes, bool $includePassword = true): array
    {
        $payload = [
            'name' => trim($attributes['first_name'].' '.$attributes['last_name']),
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'],
            'email' => $attributes['email'],
            'contact_number' => $attributes['contact_number'] ?? null,
            'role' => $attributes['role'],
            'is_active' => $attributes['is_active'],
        ];

        if ($includePassword && isset($attributes['password'])) {
            $payload['password'] = Hash::make($attributes['password']);
        }

        return $payload;
    }

    private function guardLastActiveAdmin(User $actor, User $target, string $nextRole, bool $nextActive): void
    {
        $targetIsActiveAdmin = $target->normalizedRole() === User::ROLE_ADMIN && $target->is_active;
        $removesActiveAdmin = $targetIsActiveAdmin && ($nextRole !== User::ROLE_ADMIN || ! $nextActive);

        if (! $removesActiveAdmin) {
            return;
        }

        $activeAdminCount = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->count();

        if ($activeAdminCount <= 1) {
            throw ValidationException::withMessages([
                'role' => ['At least one active admin account is required.'],
            ]);
        }

        if ($actor->id === $target->id) {
            throw ValidationException::withMessages([
                'role' => ['You cannot remove your own admin access while signed in.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function adminData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'contact_number' => $user->contact_number,
            'role' => $user->normalizedRole(),
            'is_active' => $user->is_active,
            'status' => $user->is_active ? 'active' : 'inactive',
            'reservations_count' => $user->reservations_count ?? $user->reservations()->count(),
            'employee_id' => $user->employee?->id,
            'employee' => $user->employee?->publicData(),
            'created_at' => $user->created_at?->toISOString(),
            'updated_at' => $user->updated_at?->toISOString(),
        ];
    }
}
