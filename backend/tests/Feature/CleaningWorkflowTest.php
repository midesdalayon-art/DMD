<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\HousekeepingTask;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleaningWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_marks_accommodation_needs_cleaning_until_manager_marks_ready(): void
    {
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $customer = User::factory()->create(['role' => User::ROLE_GUEST]);

        $accommodation = Accommodation::create([
            'name' => 'Room 1',
            'slug' => 'room-1',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 2,
            'price_per_night' => 2500,
            'description' => 'Test room',
            'status' => Accommodation::STATUS_AVAILABLE,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $reservation = Reservation::create([
            'user_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => today()->subDays(2)->toDateString(),
            'check_out' => today()->addDay()->toDateString(),
            'guests' => 2,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'total_amount' => 5000,
            'status' => Reservation::STATUS_CHECKED_IN,
            'booking_reference' => 'DMD-'.today()->format('Ymd').'-FLOW01',
        ]);

        $this->actingAs($frontDesk)
            ->patchJson("/api/frontdesk/reservations/{$reservation->id}/status", [
                'status' => Reservation::STATUS_CHECKED_OUT,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CHECKED_OUT);

        $this->assertDatabaseHas('accommodations', [
            'id' => $accommodation->id,
            'status' => Accommodation::STATUS_AVAILABLE,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_NEEDS_CLEANING,
        ]);

        $this->actingAs($customer)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => today()->addDays(2)->toDateString(),
            'check_out' => today()->addDays(4)->toDateString(),
            'guests' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors(['accommodation_id']);

        $task = $this->actingAs($manager)
            ->postJson('/api/manager/housekeeping-tasks', [
                'accommodation_id' => $accommodation->id,
                'assigned_staff_name' => 'Juan Dela Cruz',
                'task_type' => HousekeepingTask::TYPE_CLEANING,
                'priority' => HousekeepingTask::PRIORITY_NORMAL,
                'status' => HousekeepingTask::STATUS_PENDING,
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($manager)
            ->patchJson("/api/manager/housekeeping-tasks/{$task['id']}/status", [
                'status' => HousekeepingTask::STATUS_IN_PROGRESS,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', HousekeepingTask::STATUS_IN_PROGRESS);

        $this->actingAs($manager)
            ->patchJson("/api/manager/housekeeping-tasks/{$task['id']}/status", [
                'status' => HousekeepingTask::STATUS_COMPLETED,
                'remarks' => 'Verified by manager after walkie-talkie update.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', HousekeepingTask::STATUS_COMPLETED);

        $this->assertDatabaseHas('accommodations', [
            'id' => $accommodation->id,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        $this->actingAs($customer)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => today()->addDays(6)->toDateString(),
            'check_out' => today()->addDays(8)->toDateString(),
            'guests' => 2,
        ])->assertCreated();
    }

    public function test_customer_cannot_access_cleaning_management(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($customer)->getJson('/api/manager/housekeeping-tasks')->assertForbidden();
        $this->actingAs($customer)->getJson('/api/admin/housekeeping-tasks')->assertForbidden();
    }
}
