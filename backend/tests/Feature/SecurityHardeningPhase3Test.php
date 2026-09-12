<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityHardeningPhase3Test extends TestCase
{
    use RefreshDatabase;

    public function test_password_change_keeps_current_session_and_removes_other_database_sessions(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_GUEST]);
        $this->actingAs($user);
        config(['session.driver' => 'database']);
        $otherSessionId = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $otherSessionId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('other-session'),
            'last_activity' => now()->timestamp,
        ]);

        $this->withSession(['marker' => 'current'])->patchJson('/api/user/password', [
            'current_password' => 'password',
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword1', $user->fresh()->password));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('sessions', ['id' => $otherSessionId]);
    }
}
