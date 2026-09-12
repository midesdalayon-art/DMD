<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        User::factory()->create([
            'name' => 'Front Desk User',
            'first_name' => 'Front',
            'last_name' => 'Desk',
            'email' => 'frontdesk@example.test',
            'role' => User::ROLE_FRONT_DESK,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/users?name=front&email=frontdesk&role=front_desk_staff&status=active')
            ->assertOk()
            ->assertJsonPath('data.data.0.email', 'frontdesk@example.test')
            ->assertJsonPath('data.data.0.role', User::ROLE_FRONT_DESK)
            ->assertJsonMissingPath('data.data.0.password');
    }

    public function test_admin_user_listing_supports_server_side_pagination(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        User::factory()->count(12)->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($admin)
            ->getJson('/api/admin/users?per_page=10&page=2')
            ->assertOk()
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.per_page', 10)
            ->assertJsonPath('data.total', 13)
            ->assertJsonCount(3, 'data.data');
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($guest)
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_admin_can_create_staff_account(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/users', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.email', 'manager@example.test')
            ->assertJsonPath('data.role', User::ROLE_MANAGER)
            ->assertJsonMissingPath('data.password');

        $user = User::where('email', 'manager@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('StaffPass1', $user->password));
    }

    public function test_role_changes_are_enforced(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $staff = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$staff->id}", array_merge($this->validPayload(false), [
                'email' => $staff->email,
                'role' => User::ROLE_HOUSEKEEPING,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$staff->id}", array_merge($this->validPayload(false), [
                'email' => $staff->email,
                'role' => 'owner',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_guest_cannot_create_staff(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($guest)
            ->postJson('/api/admin/users', $this->validPayload())
            ->assertForbidden();
    }

    public function test_last_active_admin_cannot_be_removed_or_deactivated(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => true]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$admin->id}/status", [
                'is_active' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$admin->id}", array_merge($this->validPayload(false), [
                'email' => $admin->email,
                'role' => User::ROLE_MANAGER,
                'is_active' => true,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_password_hashes_are_never_returned(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->getJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_deactivation_works_safely(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST, 'is_active' => true]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$guest->id}/status", [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('users', [
            'id' => $guest->id,
            'is_active' => false,
        ]);
    }

    public function test_password_can_be_reset_safely(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $staff = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$staff->id}/password", [
                'password' => 'NewStaffPass1',
                'password_confirmation' => 'NewStaffPass1',
            ])
            ->assertOk()
            ->assertJsonMissingPath('data.password');

        $this->assertTrue(Hash::check('NewStaffPass1', $staff->fresh()->password));
    }

    public function test_users_with_history_are_not_deleted_by_admin_api(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$guest->id}")
            ->assertMethodNotAllowed();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(bool $withPassword = true): array
    {
        $payload = [
            'first_name' => 'Development',
            'last_name' => 'Manager',
            'email' => 'manager@example.test',
            'contact_number' => '09171234567',
            'role' => User::ROLE_MANAGER,
            'is_active' => true,
        ];

        if ($withPassword) {
            $payload['password'] = 'StaffPass1';
            $payload['password_confirmation'] = 'StaffPass1';
        }

        return $payload;
    }
}
