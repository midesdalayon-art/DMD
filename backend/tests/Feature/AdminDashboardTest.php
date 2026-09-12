<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\HousekeepingTask;
use App\Models\InventoryAsset;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_dashboard_summary(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $staff = User::factory()->create(['role' => User::ROLE_HOUSEKEEPING]);
        $available = $this->createAccommodation(['slug' => 'available-room']);
        $maintenance = $this->createAccommodation([
            'slug' => 'maintenance-room',
            'status' => Accommodation::STATUS_MAINTENANCE,
        ]);

        Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $available->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-22',
            'guests' => 2,
            'total_amount' => 5000,
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-20260819-ADM001',
        ]);

        Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $maintenance->id,
            'check_in' => '2026-08-24',
            'check_out' => '2026-08-26',
            'guests' => 2,
            'total_amount' => 5000,
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-20260819-ADM002',
        ]);

        HousekeepingTask::create([
            'accommodation_id' => $available->id,
            'assigned_to' => $staff->id,
            'created_by' => $admin->id,
            'task_type' => HousekeepingTask::TYPE_CLEANING,
            'priority' => HousekeepingTask::PRIORITY_NORMAL,
            'status' => HousekeepingTask::STATUS_IN_PROGRESS,
        ]);

        AttendanceRecord::create([
            'user_id' => $staff->id,
            'attendance_date' => today()->toDateString(),
            'time_in' => '08:00',
            'status' => AttendanceRecord::STATUS_PRESENT,
            'verification_method' => AttendanceRecord::METHOD_MANUAL,
        ]);

        InventoryAsset::create([
            'name' => 'Demo Air Conditioner',
            'category' => 'Appliances',
            'asset_code' => 'DMD-ASSET-001',
            'quantity' => 3,
            'condition' => InventoryAsset::CONDITION_UNDER_REPAIR,
            'status' => InventoryAsset::STATUS_MAINTENANCE,
            'location_type' => InventoryAsset::LOCATION_STORAGE,
        ]);

        Announcement::create([
            'title' => 'Demo staff notice',
            'content' => 'Demo announcement content.',
            'type' => Announcement::TYPE_STAFF_NOTICE,
            'audience' => Announcement::AUDIENCE_STAFF,
            'status' => Announcement::STATUS_PUBLISHED,
            'publish_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/dashboard-summary')
            ->assertOk()
            ->assertJsonPath('data.total_accommodations', 2)
            ->assertJsonPath('data.total_reservations', 2)
            ->assertJsonPath('data.pending_reservations', 1)
            ->assertJsonPath('data.available_accommodations', 1)
            ->assertJsonPath('data.housekeeping_tasks', 1)
            ->assertJsonPath('data.attendance_today', 1)
            ->assertJsonPath('data.assets_in_maintenance', 3)
            ->assertJsonPath('data.active_announcements', 1);
    }

    public function test_admin_dashboard_summary_requires_authentication(): void
    {
        $this->getJson('/api/admin/dashboard-summary')->assertUnauthorized();
    }

    public function test_admin_dashboard_summary_rejects_non_admin_users(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($guest)
            ->getJson('/api/admin/dashboard-summary')
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAccommodation(array $overrides = []): Accommodation
    {
        return Accommodation::create(array_merge([
            'name' => 'Demo Room',
            'slug' => 'demo-room',
            'type' => 'room',
            'capacity' => 2,
            'price_per_night' => 2500,
            'description' => 'Demo accommodation for tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ], $overrides));
    }
}

