<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\HousekeepingTask;
use App\Models\HousekeepingTaskHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HousekeepingStaffAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_housekeeping_users_remain_preserved_but_inactive_after_login(): void
    {
        $user = User::factory()->create([
            'email' => 'housekeeping@example.test',
            'password' => Hash::make('HousekeepingPassword1'),
            'role' => User::ROLE_HOUSEKEEPING,
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'housekeeping@example.test',
            'password' => 'HousekeepingPassword1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => User::ROLE_HOUSEKEEPING,
            'is_active' => false,
        ]);
    }

    public function test_cleaning_history_remains_readable_for_manager_and_admin_records(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $accommodation = $this->createAccommodation();

        $task = HousekeepingTask::create([
            'accommodation_id' => $accommodation->id,
            'assigned_to' => null,
            'assigned_staff_name' => 'Juan Dela Cruz',
            'created_by' => $admin->id,
            'task_type' => HousekeepingTask::TYPE_CLEANING,
            'priority' => HousekeepingTask::PRIORITY_NORMAL,
            'status' => HousekeepingTask::STATUS_IN_PROGRESS,
            'scheduled_at' => now(),
            'started_at' => now(),
            'remarks' => 'Historical cleaning record.',
            'maintenance_notes' => null,
        ]);

        HousekeepingTaskHistory::create([
            'housekeeping_task_id' => $task->id,
            'performed_by' => $manager->id,
            'action' => 'status_changed',
            'old_value' => ['status' => HousekeepingTask::STATUS_PENDING],
            'new_value' => ['status' => HousekeepingTask::STATUS_IN_PROGRESS],
            'remarks' => 'Started outside legacy portal.',
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/housekeeping-tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.assigned_staff_name', 'Juan Dela Cruz')
            ->assertJsonPath('history.0.action', 'status_changed');

        $this->actingAs($manager)
            ->getJson("/api/manager/housekeeping-tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.assigned_staff_name', 'Juan Dela Cruz');
    }

    public function test_history_preserves_legacy_assigned_to_relationships_when_present(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $legacyStaff = User::factory()->create([
            'role' => User::ROLE_HOUSEKEEPING,
            'is_active' => false,
        ]);
        $accommodation = $this->createAccommodation();

        $task = HousekeepingTask::create([
            'accommodation_id' => $accommodation->id,
            'assigned_to' => $legacyStaff->id,
            'assigned_staff_name' => $legacyStaff->name,
            'created_by' => $admin->id,
            'task_type' => HousekeepingTask::TYPE_CLEANING,
            'priority' => HousekeepingTask::PRIORITY_NORMAL,
            'status' => HousekeepingTask::STATUS_COMPLETED,
            'scheduled_at' => now()->subDay(),
            'started_at' => now()->subDay()->addHours(1),
            'completed_at' => now()->subDay()->addHours(2),
            'remarks' => 'Legacy record preserved.',
            'maintenance_notes' => null,
        ]);

        $this->assertDatabaseHas('housekeeping_tasks', [
            'id' => $task->id,
            'employee_id' => $task->employee_id,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/housekeeping-tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.employee_id', $task->employee_id)
            ->assertJsonPath('data.assigned_to', $task->employee_id)
            ->assertJsonPath('data.assigned_staff_name', $legacyStaff->name);
    }

    private function createAccommodation(): Accommodation
    {
        return Accommodation::create([
            'name' => 'Demo Cleaning Room',
            'slug' => 'demo-cleaning-room-'.uniqid(),
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 2,
            'price_per_night' => 2500,
            'description' => 'Demo accommodation for cleaning history tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
            'image_path' => null,
        ]);
    }
}
