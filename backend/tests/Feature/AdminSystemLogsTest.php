<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSystemLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_system_logs(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->createLog($admin, ['module' => 'authentication', 'action' => 'login']);

        $this->actingAs($admin)
            ->getJson('/api/admin/system-logs')
            ->assertOk()
            ->assertJsonPath('data.data.0.module', 'authentication')
            ->assertJsonPath('summary.security_events', 1);
    }

    public function test_non_admin_cannot_access_system_logs(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($guest)
            ->getJson('/api/admin/system-logs')
            ->assertForbidden();
    }

    public function test_important_create_update_status_actions_generate_audit_records(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/announcements', $this->announcementPayload())
            ->assertCreated();

        $announcementId = $response->json('data.id');

        $this->actingAs($admin)
            ->putJson("/api/admin/announcements/{$announcementId}", array_merge($this->announcementPayload(), [
                'title' => 'Updated Advisory',
            ]))
            ->assertOk();

        $this->actingAs($admin)
            ->patchJson("/api/admin/announcements/{$announcementId}/status", [
                'status' => Announcement::STATUS_PUBLISHED,
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['module' => 'announcements', 'action' => 'created']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'announcements', 'action' => 'edited']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'announcements', 'action' => 'published']);
    }

    public function test_actor_is_taken_from_authenticated_user(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/announcements', $this->announcementPayload())
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'module' => 'announcements',
            'action' => 'created',
        ]);
    }

    public function test_logs_cannot_be_edited_or_deleted_through_normal_apis(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $log = $this->createLog($admin);

        $this->actingAs($admin)
            ->putJson("/api/admin/system-logs/{$log->id}", ['description' => 'Changed'])
            ->assertMethodNotAllowed();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/system-logs/{$log->id}")
            ->assertMethodNotAllowed();
    }

    public function test_sensitive_values_are_not_stored(): void
    {
        $this->postJson('/api/login', [
            'email' => 'missing@example.test',
            'password' => 'SecretPassword1',
        ])->assertUnprocessable();

        $log = AuditLog::where('action', 'failed_login')->firstOrFail();

        $this->assertStringNotContainsString('SecretPassword1', json_encode($log->metadata));
    }

    public function test_filtering_works(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $this->createLog($admin, ['module' => 'announcements', 'action' => 'published', 'description' => 'Published guest advisory.']);
        $this->createLog($manager, ['module' => 'inventory', 'action' => 'retired', 'description' => 'Retired chair asset.']);

        $this->actingAs($admin)
            ->getJson('/api/admin/system-logs?search=guest&role=admin&module=announcements&action=published')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.description', 'Published guest advisory.');
    }

    public function test_pagination_works(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        for ($index = 0; $index < 25; $index++) {
            $this->createLog($admin, ['description' => "Log {$index}"]);
        }

        $this->actingAs($admin)
            ->getJson('/api/admin/system-logs?page=2')
            ->assertOk()
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.total', 25)
            ->assertJsonCount(5, 'data.data');
    }

    public function test_admin_global_search_returns_limited_system_log_results(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->createLog($admin, [
            'module' => 'settings',
            'action' => 'settings_updated',
            'description' => 'Settings updated safely.',
            'metadata' => ['api_key' => 'must-not-be-returned'],
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/global-search?q=settings')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'system_logs')
            ->assertJsonPath('data.0.results.0.title', 'settings updated')
            ->assertDontSee('must-not-be-returned');
    }

    public function test_non_admin_cannot_use_global_search(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($manager)
            ->getJson('/api/admin/global-search?q=guest')
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLog(User $actor, array $overrides = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'user_id' => $actor->id,
            'action' => 'created',
            'module' => 'announcements',
            'description' => 'Announcement created.',
            'entity_type' => Announcement::class,
            'entity_id' => 1,
            'ip_address' => '127.0.0.1',
            'request_method' => 'POST',
            'metadata' => ['after' => ['title' => 'Advisory']],
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function announcementPayload(): array
    {
        return [
            'title' => 'Pool Schedule Advisory',
            'content' => 'The pool schedule has been adjusted for resort maintenance.',
            'type' => Announcement::TYPE_FACILITY_NOTICE,
            'audience' => Announcement::AUDIENCE_GUESTS,
            'status' => Announcement::STATUS_DRAFT,
            'publish_at' => '2026-08-20 08:00:00',
            'expires_at' => '2026-08-27 08:00:00',
        ];
    }
}
