<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_roles_receive_staff_and_everyone_announcements_but_guests_do_not(): void
    {
        $creator = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->createAnnouncement($creator, 'Staff', Announcement::AUDIENCE_STAFF);
        $this->createAnnouncement($creator, 'Everyone', Announcement::AUDIENCE_EVERYONE);
        $this->createAnnouncement($creator, 'Guests', Announcement::AUDIENCE_GUESTS);
        $this->createAnnouncement($creator, 'Draft', Announcement::AUDIENCE_STAFF, [
            'status' => Announcement::STATUS_DRAFT,
        ]);
        $this->createAnnouncement($creator, 'Expired', Announcement::AUDIENCE_EVERYONE, [
            'expires_at' => now()->subMinute(),
        ]);

        foreach ([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_FRONT_DESK] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->getJson('/api/notifications/announcements')
                ->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonFragment(['title' => 'Staff'])
                ->assertJsonFragment(['title' => 'Everyone'])
                ->assertJsonMissing(['title' => 'Guests'])
                ->assertJsonPath('unread_count', 2);
        }

        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($guest)
            ->getJson('/api/notifications/announcements')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['title' => 'Everyone'])
            ->assertJsonFragment(['title' => 'Guests'])
            ->assertJsonMissing(['title' => 'Staff'])
            ->assertJsonPath('unread_count', 2);
    }

    public function test_notification_read_state_and_mark_all_are_scoped_to_user(): void
    {
        $creator = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $otherManager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $this->createAnnouncement($creator, 'Staff Notice', Announcement::AUDIENCE_STAFF);
        $everyone = $this->createAnnouncement($creator, 'Everyone Notice', Announcement::AUDIENCE_EVERYONE);

        $this->actingAs($manager)
            ->getJson('/api/notifications/announcements')
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('data.0.is_read', false);

        $this->actingAs($manager)
            ->postJson("/api/notifications/announcements/{$everyone->id}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $this->assertDatabaseHas('announcement_reads', [
            'announcement_id' => $everyone->id,
            'user_id' => $manager->id,
        ]);

        $this->actingAs($otherManager)
            ->getJson('/api/notifications/announcements')
            ->assertJsonPath('unread_count', 2);

        $this->actingAs($manager)
            ->postJson('/api/notifications/announcements/read-all')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(2, AnnouncementRead::where('user_id', $manager->id)->count());
    }

    private function createAnnouncement(User $creator, string $title, string $audience, array $overrides = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => $title,
            'content' => $title.' content.',
            'type' => Announcement::TYPE_GENERAL,
            'audience' => $audience,
            'status' => Announcement::STATUS_PUBLISHED,
            'publish_at' => now()->subMinute(),
            'created_by' => $creator->id,
        ], $overrides));
    }
}

