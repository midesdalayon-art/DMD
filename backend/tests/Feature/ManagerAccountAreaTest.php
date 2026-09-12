<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\HousekeepingTask;
use App\Models\InventoryAsset;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ManagerAccountAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_log_in_and_receives_manager_dashboard_redirect(): void
    {
        User::factory()->create([
            'email' => 'manager@dmdresort.test',
            'password' => Hash::make('ManagerPassword1'),
            'role' => User::ROLE_MANAGER,
        ]);

        $this->postJson('/api/login', [
            'email' => 'manager@dmdresort.test',
            'password' => 'ManagerPassword1',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', User::ROLE_MANAGER)
            ->assertJsonPath('user.redirect_to', '/manager/dashboard');
    }

    public function test_manager_can_access_allowed_operational_apis(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($manager)->getJson('/api/manager/dashboard-summary')->assertOk();
        $this->actingAs($manager)->getJson('/api/manager/reservations')->assertOk();
        $this->actingAs($manager)->getJson('/api/manager/accommodations')->assertOk();
        $this->actingAs($manager)->getJson('/api/manager/inventory-assets')->assertOk();
        $this->actingAs($manager)->getJson('/api/manager/housekeeping-tasks')->assertOk();
        $this->actingAs($manager)->getJson('/api/manager/attendance-records')->assertOk();
        $this->actingAs($manager)->getJson('/api/manager/reports')->assertOk();
        $this->actingAs($manager)->getJson('/api/manager/announcements')->assertOk();
    }

    public function test_manager_cannot_access_admin_only_security_apis(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($manager)->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($manager)->getJson('/api/admin/system-logs')->assertForbidden();
        $this->actingAs($manager)->getJson('/api/admin/settings')->assertForbidden();
    }

    public function test_guest_and_staff_users_cannot_access_manager_apis(): void
    {
        foreach ([User::ROLE_GUEST, User::ROLE_FRONT_DESK, User::ROLE_HOUSEKEEPING] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->getJson('/api/manager/dashboard-summary')
                ->assertForbidden();
        }
    }

    public function test_manager_can_view_and_update_reservation_using_existing_rules(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation();
        $reservation = Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(7)->toDateString(),
            'guests' => 2,
            'total_amount' => 5000,
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-20260821-MGR001',
        ]);

        $this->actingAs($manager)
            ->getJson('/api/manager/reservations')
            ->assertOk()
            ->assertJsonPath('data.0.booking_reference', 'DMD-20260821-MGR001');

        $this->actingAs($manager)
            ->patchJson("/api/manager/reservations/{$reservation->id}/status", [
                'status' => Reservation::STATUS_CONFIRMED,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CONFIRMED);
    }

    public function test_manager_can_update_accommodation_operational_status_but_cannot_use_admin_delete(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $accommodation = $this->createAccommodation();

        $this->actingAs($manager)
            ->patchJson("/api/manager/accommodations/{$accommodation->id}/status", [
                'status' => Accommodation::STATUS_MAINTENANCE,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Accommodation::STATUS_MAINTENANCE);

        $this->actingAs($manager)
            ->deleteJson("/api/admin/accommodations/{$accommodation->id}")
            ->assertForbidden();
    }

    public function test_manager_can_monitor_housekeeping_and_create_task_with_valid_staff(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $accommodation = $this->createAccommodation();

        $this->actingAs($manager)
            ->postJson('/api/manager/housekeeping-tasks', [
                'accommodation_id' => $accommodation->id,
                'assigned_staff_name' => 'Juan Dela Cruz',
                'task_type' => HousekeepingTask::TYPE_CLEANING,
                'priority' => HousekeepingTask::PRIORITY_NORMAL,
                'status' => HousekeepingTask::STATUS_PENDING,
                'remarks' => 'Prepare room for arrival.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.assigned_staff_name', 'Juan Dela Cruz');

        $this->actingAs($manager)
            ->postJson('/api/manager/housekeeping-tasks', [
                'accommodation_id' => $accommodation->id,
                'assigned_staff_name' => 'Juan Dela Cruz',
                'task_type' => HousekeepingTask::TYPE_CLEANING,
                'priority' => HousekeepingTask::PRIORITY_NORMAL,
                'status' => HousekeepingTask::STATUS_PENDING,
            ])
            ->assertCreated();
    }

    public function test_manager_can_create_a_cleaning_task_for_an_employee(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $accommodation = $this->createAccommodation(['name' => 'Room 1']);
        $employee = Employee::create([
            'employee_code' => 'EMP-ROOM-001',
            'user_id' => null,
            'first_name' => 'Mila',
            'last_name' => 'Santos',
            'position' => 'Cleaning Staff',
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->actingAs($manager)
            ->postJson('/api/manager/housekeeping-tasks', [
                'accommodation_id' => $accommodation->id,
                'employee_id' => $employee->id,
                'task_type' => HousekeepingTask::TYPE_CLEANING,
                'priority' => HousekeepingTask::PRIORITY_NORMAL,
                'status' => HousekeepingTask::STATUS_PENDING,
                'scheduled_at' => '2026-09-12 10:00:00',
                'remarks' => 'Prepare Room 1.',
                'maintenance_notes' => null,
            ])
            ->assertCreated()
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.assigned_staff.name', 'Mila Santos');

        $this->assertDatabaseHas('housekeeping_tasks', [
            'accommodation_id' => $accommodation->id,
            'employee_id' => $employee->id,
            'status' => HousekeepingTask::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('accommodations', [
            'id' => $accommodation->id,
            'housekeeping_status' => 'needs_cleaning',
        ]);
    }

    public function test_manager_can_view_attendance_but_cannot_correct_attendance(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $staff = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $record = AttendanceRecord::create([
            'user_id' => $staff->id,
            'attendance_date' => today()->toDateString(),
            'time_in' => '08:00',
            'status' => AttendanceRecord::STATUS_PRESENT,
            'verification_method' => AttendanceRecord::METHOD_MANUAL,
            'remarks' => 'Seeded for manager monitoring.',
        ]);

        $this->actingAs($manager)
            ->getJson('/api/manager/attendance-records')
            ->assertOk()
            ->assertJsonPath('data.0.id', $record->id);

        $this->actingAs($manager)
            ->putJson("/api/admin/attendance-records/{$record->id}", [
                'user_id' => $staff->id,
                'attendance_date' => today()->toDateString(),
                'time_in' => '08:30',
                'status' => AttendanceRecord::STATUS_LATE,
                'verification_method' => AttendanceRecord::METHOD_MANUAL,
                'correction_reason' => 'Manager should not access admin correction endpoint.',
            ])
            ->assertForbidden();
    }

    public function test_manager_announcements_only_include_published_staff_or_everyone(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Announcement::create([
            'title' => 'Staff Notice',
            'content' => 'Published staff message.',
            'type' => Announcement::TYPE_STAFF_NOTICE,
            'audience' => Announcement::AUDIENCE_STAFF,
            'status' => Announcement::STATUS_PUBLISHED,
            'publish_at' => now()->subHour(),
            'created_by' => $admin->id,
        ]);
        Announcement::create([
            'title' => 'Guest Only',
            'content' => 'Guest message.',
            'type' => Announcement::TYPE_GENERAL,
            'audience' => Announcement::AUDIENCE_GUESTS,
            'status' => Announcement::STATUS_PUBLISHED,
            'publish_at' => now()->subHour(),
            'created_by' => $admin->id,
        ]);
        Announcement::create([
            'title' => 'Draft Staff',
            'content' => 'Draft message.',
            'type' => Announcement::TYPE_STAFF_NOTICE,
            'audience' => Announcement::AUDIENCE_STAFF,
            'status' => Announcement::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($manager)
            ->getJson('/api/manager/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Staff Notice');
    }

    public function test_admin_security_remains_unchanged(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/system-logs')->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/settings')->assertOk();
        $this->actingAs($admin)->getJson('/api/manager/dashboard-summary')->assertForbidden();
    }

    public function test_manager_can_view_reports_and_inventory_operations(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $asset = InventoryAsset::create([
            'name' => 'Air Conditioner',
            'category' => 'Appliance',
            'asset_code' => 'AC-001',
            'quantity' => 1,
            'condition' => InventoryAsset::CONDITION_GOOD,
            'status' => InventoryAsset::STATUS_AVAILABLE,
            'location_type' => InventoryAsset::LOCATION_STORAGE,
            'location_name' => 'Storage',
        ]);

        $this->actingAs($manager)->getJson('/api/manager/reports')->assertOk();
        $this->actingAs($manager)
            ->patchJson("/api/manager/inventory-assets/{$asset->id}/status", [
                'status' => InventoryAsset::STATUS_MAINTENANCE,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InventoryAsset::STATUS_MAINTENANCE);
    }

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

