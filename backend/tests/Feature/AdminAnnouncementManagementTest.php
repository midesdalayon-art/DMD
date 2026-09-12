<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAnnouncementManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_announcement(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/announcements', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.title', 'Pool Schedule Advisory')
            ->assertJsonPath('data.status', Announcement::STATUS_DRAFT)
            ->assertJsonPath('data.created_by.id', $admin->id);

        $this->assertDatabaseHas('announcements', [
            'title' => 'Pool Schedule Advisory',
            'created_by' => $admin->id,
            'status' => Announcement::STATUS_DRAFT,
        ]);
    }

    public function test_non_admin_cannot_manage_announcements(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($manager)
            ->getJson('/api/admin/announcements')
            ->assertForbidden();
    }

    public function test_publish_and_unpublish_works(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $announcement = $this->createAnnouncement($admin);

        $this->actingAs($admin)
            ->patchJson("/api/admin/announcements/{$announcement->id}/status", [
                'status' => Announcement::STATUS_PUBLISHED,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Announcement::STATUS_PUBLISHED);

        $this->assertNotNull($announcement->fresh()->publish_at);

        $this->actingAs($admin)
            ->patchJson("/api/admin/announcements/{$announcement->id}/status", [
                'status' => Announcement::STATUS_DRAFT,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Announcement::STATUS_DRAFT);
    }

    public function test_invalid_dates_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/announcements', array_merge($this->validPayload(), [
                'publish_at' => '2026-08-20 12:00:00',
                'expires_at' => '2026-08-20 08:00:00',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires_at');
    }

    public function test_public_endpoint_only_returns_published_guest_or_everyone_announcements(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $guestAnnouncement = $this->createAnnouncement($admin, [
            'title' => 'Guest Notice',
            'audience' => Announcement::AUDIENCE_GUESTS,
            'status' => Announcement::STATUS_PUBLISHED,
            'publish_at' => now()->subHour(),
        ]);
        $everyoneAnnouncement = $this->createAnnouncement($admin, [
            'title' => 'Everyone Notice',
            'audience' => Announcement::AUDIENCE_EVERYONE,
            'status' => Announcement::STATUS_PUBLISHED,
            'publish_at' => now()->subHour(),
        ]);
        $this->createAnnouncement($admin, [
            'title' => 'Draft Notice',
            'audience' => Announcement::AUDIENCE_GUESTS,
            'status' => Announcement::STATUS_DRAFT,
        ]);

        $this->getJson('/api/announcements')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['title' => $guestAnnouncement->title])
            ->assertJsonFragment(['title' => $everyoneAnnouncement->title])
            ->assertJsonMissing(['title' => 'Draft Notice']);
    }

    public function test_staff_only_announcements_are_not_exposed_publicly(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->createAnnouncement($admin, [
            'title' => 'Staff Only Notice',
            'audience' => Announcement::AUDIENCE_STAFF,
            'status' => Announcement::STATUS_PUBLISHED,
            'publish_at' => now()->subHour(),
        ]);

        $this->getJson('/api/announcements')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing(['title' => 'Staff Only Notice']);
    }

    public function test_archived_announcements_remain_preserved(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $announcement = $this->createAnnouncement($admin, [
            'status' => Announcement::STATUS_ARCHIVED,
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/announcements/{$announcement->id}")
            ->assertMethodNotAllowed();

        $this->actingAs($admin)
            ->patchJson("/api/admin/announcements/{$announcement->id}/status", [
                'status' => Announcement::STATUS_PUBLISHED,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'status' => Announcement::STATUS_ARCHIVED,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'title' => 'Pool Schedule Advisory',
            'content' => 'The pool schedule has been adjusted for resort maintenance.',
            'type' => Announcement::TYPE_FACILITY_NOTICE,
            'audience' => Announcement::AUDIENCE_GUESTS,
            'status' => Announcement::STATUS_DRAFT,
            'publish_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'expires_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAnnouncement(User $admin, array $overrides = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'Pool Schedule Advisory',
            'content' => 'The pool schedule has been adjusted for resort maintenance.',
            'type' => Announcement::TYPE_FACILITY_NOTICE,
            'audience' => Announcement::AUDIENCE_GUESTS,
            'status' => Announcement::STATUS_DRAFT,
            'publish_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'expires_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'created_by' => $admin->id,
        ], $overrides));
    }
}
