<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_register_successfully(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.com',
            'contact_number' => '09171234567',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.first_name', 'Maria')
            ->assertJsonPath('user.last_name', 'Santos')
            ->assertJsonPath('user.email', 'maria@example.com')
            ->assertJsonPath('user.role', User::ROLE_GUEST)
            ->assertJsonPath('user.redirect_to', '/account');

        $this->assertGuest();
        $this->assertDatabaseHas('users', [
            'email' => 'maria@example.com',
            'email_verified_at' => null,
        ]);
    }

    public function test_registered_user_receives_guest_role(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Guest',
            'last_name' => 'User',
            'email' => 'guest-register@example.com',
            'contact_number' => '+63 917 123 4567',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'guest-register@example.com',
            'role' => User::ROLE_GUEST,
        ]);
    }

    public function test_public_registration_cannot_create_admin(): void
    {
        $response = $this->postJson('/api/register', [
            'first_name' => 'Role',
            'last_name' => 'Tamper',
            'email' => 'tamper@example.com',
            'contact_number' => '09171234567',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'role' => User::ROLE_ADMIN,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.role', User::ROLE_GUEST);

        $this->assertDatabaseHas('users', [
            'email' => 'tamper@example.com',
            'role' => User::ROLE_GUEST,
        ]);
    }

    public function test_duplicate_registration_email_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'duplicate@example.com',
        ]);

        $this->postJson('/api/register', [
            'first_name' => 'Duplicate',
            'last_name' => 'Guest',
            'email' => 'duplicate@example.com',
            'contact_number' => '09171234567',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_invalid_registration_email_is_rejected(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Invalid',
            'last_name' => 'Email',
            'email' => 'not-an-email',
            'contact_number' => '09171234567',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_password_confirmation_is_required_for_registration(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Missing',
            'last_name' => 'Confirmation',
            'email' => 'missing-confirmation@example.com',
            'contact_number' => '09171234567',
            'password' => 'Password1',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_registration_password_is_hashed(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Hashed',
            'last_name' => 'Password',
            'email' => 'hashed@example.com',
            'contact_number' => '09171234567',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $user = User::where('email', 'hashed@example.com')->firstOrFail();

        $this->assertNotSame('Password1', $user->password);
        $this->assertTrue(Hash::check('Password1', $user->password));
    }

    public function test_newly_registered_user_is_not_authenticated(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'Authenticated',
            'last_name' => 'Guest',
            'email' => 'authenticated@example.com',
            'contact_number' => '09171234567',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ])->assertCreated();

        $this->assertGuest();
        $this->assertDatabaseHas('users', [
            'email' => 'authenticated@example.com',
            'email_verified_at' => null,
        ]);
    }

    public function test_login_requires_valid_email_and_password(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'not-an-email',
            'password' => '',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_rejects_incorrect_credentials(): void
    {
        User::factory()->create([
            'email' => 'guest@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'guest@example.com',
            'password' => 'wrong-password',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_returns_authenticated_user_profile_and_redirect(): void
    {
        User::factory()->create([
            'name' => 'Front Desk User',
            'email' => 'frontdesk@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_FRONT_DESK,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'frontdesk@example.com',
            'password' => 'password',
            'remember' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('user.email', 'frontdesk@example.com')
            ->assertJsonPath('user.role', User::ROLE_FRONT_DESK)
            ->assertJsonPath('user.redirect_to', '/frontdesk/dashboard');

        $this->assertAuthenticated();
    }

    public function test_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_authenticated_user_can_be_retrieved_and_logged_out(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_MANAGER,
        ]);

        $this->actingAs($user)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.role', User::ROLE_MANAGER)
            ->assertJsonPath('user.redirect_to', '/manager/dashboard');

        $this->postJson('/api/logout')->assertOk();

        Auth::forgetGuards();

        $this->assertGuest();
    }

    public function test_unknown_roles_do_not_receive_guest_permissions(): void
    {
        $unknown = User::factory()->create(['role' => 'contractor']);

        $this->assertSame(User::ROLE_UNKNOWN, $unknown->normalizedRole());
        $this->assertSame('/', $unknown->dashboardPath());

        $this->actingAs($unknown)
            ->getJson('/api/reservations')
            ->assertForbidden();
    }

    public function test_supported_legacy_role_aliases_still_authorize_their_role(): void
    {
        $administrator = User::factory()->create(['role' => 'administrator']);
        $frontDesk = User::factory()->create(['role' => 'frontdesk']);

        $this->assertSame(User::ROLE_ADMIN, $administrator->normalizedRole());
        $this->assertSame(User::ROLE_FRONT_DESK, $frontDesk->normalizedRole());

        $this->actingAs($administrator)
            ->getJson('/api/admin/dashboard-summary')
            ->assertOk();

        $this->actingAs($frontDesk)
            ->getJson('/api/frontdesk/dashboard-summary')
            ->assertOk();
    }

    public function test_guest_can_update_own_profile(): void
    {
        $user = User::factory()->create([
            'email' => 'profile@example.com',
            'role' => User::ROLE_GUEST,
        ]);

        $this->actingAs($user)
            ->putJson('/api/user/profile', [
                'first_name' => 'Updated',
                'last_name' => 'Guest',
                'contact_number' => '09175551234',
            ])
            ->assertOk()
            ->assertJsonPath('user.first_name', 'Updated')
            ->assertJsonPath('user.last_name', 'Guest')
            ->assertJsonPath('user.contact_number', '09175551234')
            ->assertJsonPath('user.email', 'profile@example.com')
            ->assertJsonPath('user.role', User::ROLE_GUEST);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Guest',
            'first_name' => 'Updated',
            'last_name' => 'Guest',
            'contact_number' => '09175551234',
        ]);
    }

    public function test_profile_update_requires_authentication(): void
    {
        $this->putJson('/api/user/profile', [
            'first_name' => 'Guest',
            'last_name' => 'User',
            'contact_number' => '09175551234',
        ])->assertUnauthorized();
    }

    public function test_profile_update_is_guest_only(): void
    {
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
        ]);

        $this->actingAs($manager)
            ->putJson('/api/user/profile', [
                'first_name' => 'Manager',
                'last_name' => 'Blocked',
                'contact_number' => '09175551234',
            ])
            ->assertForbidden();
    }

    public function test_profile_update_cannot_change_email_password_role_or_another_user(): void
    {
        $guest = User::factory()->create([
            'email' => 'locked@example.com',
            'password' => Hash::make('Original1'),
            'role' => User::ROLE_GUEST,
        ]);
        $otherGuest = User::factory()->create([
            'first_name' => 'Other',
            'email' => 'other@example.com',
            'role' => User::ROLE_GUEST,
        ]);

        $this->actingAs($guest)
            ->putJson('/api/user/profile', [
                'id' => $otherGuest->id,
                'first_name' => 'Safe',
                'last_name' => 'Guest',
                'contact_number' => '09175551234',
                'email' => 'changed@example.com',
                'password' => 'ChangedPassword1',
                'role' => User::ROLE_ADMIN,
            ])
            ->assertOk()
            ->assertJsonPath('user.email', 'locked@example.com')
            ->assertJsonPath('user.role', User::ROLE_GUEST);

        $guest->refresh();
        $otherGuest->refresh();

        $this->assertSame('locked@example.com', $guest->email);
        $this->assertSame(User::ROLE_GUEST, $guest->role);
        $this->assertTrue(Hash::check('Original1', $guest->password));
        $this->assertSame('Other', $otherGuest->first_name);
        $this->assertSame('other@example.com', $otherGuest->email);
    }

    public function test_profile_update_validates_contact_number(): void
    {
        $guest = User::factory()->create([
            'role' => User::ROLE_GUEST,
        ]);

        $this->actingAs($guest)
            ->putJson('/api/user/profile', [
                'first_name' => 'Invalid',
                'last_name' => 'Contact',
                'contact_number' => 'abc',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contact_number']);
    }

    public function test_guest_can_update_password(): void
    {
        $guest = User::factory()->create([
            'role' => User::ROLE_GUEST,
            'password' => Hash::make('Original1'),
        ]);

        $this->actingAs($guest)
            ->patchJson('/api/user/password', [
                'current_password' => 'Original1',
                'password' => 'NewPassword1',
                'password_confirmation' => 'NewPassword1',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Password updated.');

        $guest->refresh();

        $this->assertTrue(Hash::check('NewPassword1', $guest->password));
    }

    public function test_password_update_requires_current_password(): void
    {
        $guest = User::factory()->create([
            'role' => User::ROLE_GUEST,
            'password' => Hash::make('Original1'),
        ]);

        $this->actingAs($guest)
            ->patchJson('/api/user/password', [
                'current_password' => 'Wrong1',
                'password' => 'NewPassword1',
                'password_confirmation' => 'NewPassword1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }
}
