<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Employee;
use App\Models\HousekeepingTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminHousekeepingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_a_cleaning_employee_without_a_login_account(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $accommodation = $this->createAccommodation();
        $employee = Employee::create([
            'employee_code' => 'EMP-CLEAN-001',
            'user_id' => null,
            'first_name' => 'Mila',
            'last_name' => 'Santos',
            'position' => 'Cleaning Staff',
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/housekeeping-tasks', array_merge($this->validPayload($accommodation, ''), [
                'employee_id' => $employee->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.assigned_staff.name', 'Mila Santos');

        $this->assertDatabaseHas('housekeeping_tasks', [
            'accommodation_id' => $accommodation->id,
            'employee_id' => $employee->id,
        ]);
    }

    public function test_admin_can_create_housekeeping_task_with_staff_name(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $accommodation = $this->createAccommodation();

        $this->actingAs($admin)
            ->postJson('/api/admin/housekeeping-tasks', $this->validPayload($accommodation, 'Juan Dela Cruz'))
            ->assertCreated()
            ->assertJsonPath('data.accommodation_id', $accommodation->id)
            ->assertJsonPath('data.assigned_staff_name', 'Juan Dela Cruz')
            ->assertJsonPath('data.created_by.id', $admin->id);

        $this->assertDatabaseHas('housekeeping_tasks', [
            'accommodation_id' => $accommodation->id,
            'assigned_staff_name' => 'Juan Dela Cruz',
            'created_by' => $admin->id,
            'status' => HousekeepingTask::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('housekeeping_task_histories', [
            'performed_by' => $admin->id,
            'action' => 'created',
        ]);
    }

    public function test_admin_housekeeping_listing_supports_server_side_pagination(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        for ($index = 0; $index < 12; $index++) {
            $this->createTask(['created_by_user' => $admin]);
        }

        $this->actingAs($admin)
            ->getJson('/api/admin/housekeeping-tasks?per_page=10&page=2')
            ->assertOk()
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.per_page', 10)
            ->assertJsonPath('data.total', 12)
            ->assertJsonCount(2, 'data.data');
    }

    public function test_non_admin_cannot_access_admin_housekeeping_apis(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($guest)
            ->getJson('/api/admin/housekeeping-tasks')
            ->assertForbidden();
    }

    public function test_assigning_a_cleaning_staff_name_is_supported(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $accommodation = $this->createAccommodation();

        $this->actingAs($admin)
            ->postJson('/api/admin/housekeeping-tasks', $this->validPayload($accommodation, 'Juan Dela Cruz'))
            ->assertCreated();
    }

    public function test_task_status_updates_work(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $task = $this->createTask([
            'status' => HousekeepingTask::STATUS_PENDING,
            'assigned_staff_name' => 'Juan Dela Cruz',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/housekeeping-tasks/{$task->id}/status", [
                'status' => HousekeepingTask::STATUS_IN_PROGRESS,
                'remarks' => 'Manager confirmed cleaning started.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', HousekeepingTask::STATUS_IN_PROGRESS);

        $this->assertNotNull($task->fresh()->started_at);
        $this->assertDatabaseHas('housekeeping_task_histories', [
            'housekeeping_task_id' => $task->id,
            'action' => 'status_changed',
        ]);
    }

    public function test_completed_tasks_record_completion_time(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $task = $this->createTask([
            'status' => HousekeepingTask::STATUS_IN_PROGRESS,
            'started_at' => now()->subHour(),
            'assigned_staff_name' => 'Juan Dela Cruz',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/housekeeping-tasks/{$task->id}/status", [
                'status' => HousekeepingTask::STATUS_COMPLETED,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', HousekeepingTask::STATUS_COMPLETED);

        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_manager_can_mark_ready_after_cleaning_is_reported_complete(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $accommodation = $this->createAccommodation([
            'housekeeping_status' => Accommodation::HOUSEKEEPING_NEEDS_CLEANING,
        ]);
        $task = $this->createTask([
            'accommodation_model' => $accommodation,
            'status' => HousekeepingTask::STATUS_IN_PROGRESS,
            'started_at' => now()->subHour(),
            'assigned_staff_name' => 'Juan Dela Cruz',
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/manager/housekeeping-tasks/{$task->id}/status", [
                'status' => HousekeepingTask::STATUS_COMPLETED,
                'remarks' => 'Cleaner confirmed completion outside the system.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', HousekeepingTask::STATUS_COMPLETED);

        $this->assertDatabaseHas('accommodations', [
            'id' => $accommodation->id,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);
    }

    public function test_completed_history_tasks_are_preserved(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $task = $this->createTask([
            'status' => HousekeepingTask::STATUS_COMPLETED,
            'completed_at' => now(),
            'assigned_staff_name' => 'Juan Dela Cruz',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/admin/housekeeping-tasks/{$task->id}", array_merge($this->validPayload($task->accommodation, 'Juan Dela Cruz'), [
                'status' => HousekeepingTask::STATUS_COMPLETED,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($admin)
            ->deleteJson("/api/admin/housekeeping-tasks/{$task->id}")
            ->assertMethodNotAllowed();

        $this->assertDatabaseHas('housekeeping_tasks', [
            'id' => $task->id,
            'status' => HousekeepingTask::STATUS_COMPLETED,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(Accommodation $accommodation, string $staffName): array
    {
        return [
            'accommodation_id' => $accommodation->id,
            'assigned_staff_name' => $staffName,
            'task_type' => HousekeepingTask::TYPE_CLEANING,
            'priority' => HousekeepingTask::PRIORITY_NORMAL,
            'status' => HousekeepingTask::STATUS_PENDING,
            'scheduled_at' => '2026-08-20 10:00:00',
            'started_at' => null,
            'completed_at' => null,
            'remarks' => 'Prepare room for incoming guest.',
            'maintenance_notes' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTask(array $overrides = []): HousekeepingTask
    {
        $admin = $overrides['created_by_user'] ?? User::factory()->create(['role' => User::ROLE_ADMIN]);
        $staffName = $overrides['assigned_staff_name'] ?? 'Juan Dela Cruz';
        $accommodation = $overrides['accommodation_model'] ?? $this->createAccommodation();

        unset($overrides['created_by_user'], $overrides['assigned_staff_name'], $overrides['accommodation_model']);

        return HousekeepingTask::create(array_merge([
            'accommodation_id' => $accommodation->id,
            'assigned_to' => null,
            'assigned_staff_name' => $staffName,
            'created_by' => $admin->id,
            'task_type' => HousekeepingTask::TYPE_CLEANING,
            'priority' => HousekeepingTask::PRIORITY_NORMAL,
            'status' => HousekeepingTask::STATUS_PENDING,
            'scheduled_at' => now()->addDay(),
            'remarks' => 'Test housekeeping task.',
            'maintenance_notes' => null,
        ], $overrides));
    }

    private function createAccommodation(array $overrides = []): Accommodation
    {
        return Accommodation::create(array_merge([
            'name' => 'Demo Housekeeping Room '.uniqid(),
            'slug' => 'demo-housekeeping-room-'.uniqid(),
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 2,
            'price_per_night' => 2500,
            'description' => 'Demo accommodation for housekeeping tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
            'image_path' => null,
        ], $overrides));
    }
}
