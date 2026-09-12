<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AttendanceRecord;
use App\Models\HousekeepingTask;
use App\Models\InventoryAsset;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReportsAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_report_endpoints(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->getJson('/api/admin/reports?preset=this_month')
            ->assertOk()
            ->assertJsonStructure(['data' => ['period', 'reservations', 'accommodations', 'attendance', 'housekeeping', 'inventory']]);
    }

    public function test_non_admin_cannot_access_reports(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($guest)
            ->getJson('/api/admin/reports')
            ->assertForbidden();
    }

    public function test_summary_counts_and_reservation_aggregations_are_correct(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $room = $this->createAccommodation(['name' => 'Demo Room', 'slug' => 'demo-room']);
        $cottage = $this->createAccommodation(['name' => 'Demo Cottage', 'slug' => 'demo-cottage', 'status' => Accommodation::STATUS_MAINTENANCE]);

        $this->createReservation($guest, $room, Reservation::STATUS_PENDING, '2026-08-05');
        $this->createReservation($guest, $room, Reservation::STATUS_CONFIRMED, '2026-08-06');
        $this->createReservation($guest, $cottage, Reservation::STATUS_CANCELLED, '2026-08-07');

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/reports?preset=custom&start_date=2026-08-01&end_date=2026-08-31')
            ->assertOk();

        $response->assertJsonPath('data.reservations.summary.total', 3)
            ->assertJsonPath('data.reservations.summary.pending', 1)
            ->assertJsonPath('data.reservations.summary.confirmed', 1)
            ->assertJsonPath('data.reservations.summary.cancelled', 1)
            ->assertJsonPath('data.reservations.top_accommodations.0.name', 'Demo Room')
            ->assertJsonPath('data.accommodations.available', 1)
            ->assertJsonPath('data.accommodations.maintenance', 1)
            ->assertJsonPath('data.accommodations.period_days', 31)
            ->assertJsonPath('data.accommodations.accommodation_count', 2)
            ->assertJsonPath('data.accommodations.capacity_nights', 62);
    }

    public function test_date_filters_work(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $room = $this->createAccommodation();

        $this->createReservation($guest, $room, Reservation::STATUS_PENDING, '2026-08-05');
        $this->createReservation($guest, $room, Reservation::STATUS_CONFIRMED, '2026-09-05');

        $this->actingAs($admin)
            ->getJson('/api/admin/reports?preset=custom&start_date=2026-08-01&end_date=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.reservations.summary.total', 1)
            ->assertJsonPath('data.reservations.summary.pending', 1)
            ->assertJsonPath('data.reservations.summary.confirmed', 0);
    }

    public function test_attendance_aggregations_are_correct(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);

        $this->createAttendance($manager, AttendanceRecord::STATUS_PRESENT);
        $this->createAttendance($frontDesk, AttendanceRecord::STATUS_LATE);

        $this->actingAs($admin)
            ->getJson('/api/admin/reports?preset=custom&start_date=2026-08-01&end_date=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.attendance.summary.total', 2)
            ->assertJsonPath('data.attendance.summary.present', 1)
            ->assertJsonPath('data.attendance.summary.late', 1)
            ->assertJsonCount(3, 'data.attendance.by_role')
            ->assertJsonPath('data.attendance.by_role.0.role', User::ROLE_ADMIN)
            ->assertJsonPath('data.attendance.by_role.1.role', User::ROLE_MANAGER)
            ->assertJsonPath('data.attendance.by_role.2.role', User::ROLE_FRONT_DESK);
    }

    public function test_housekeeping_aggregations_are_correct(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $staff = User::factory()->create(['role' => User::ROLE_HOUSEKEEPING]);
        $room = $this->createAccommodation();

        $maintenanceTask = HousekeepingTask::create([
            'accommodation_id' => $room->id,
            'assigned_to' => $staff->id,
            'created_by' => $admin->id,
            'task_type' => HousekeepingTask::TYPE_MAINTENANCE,
            'priority' => HousekeepingTask::PRIORITY_HIGH,
            'status' => HousekeepingTask::STATUS_IN_PROGRESS,
            'created_at' => '2026-08-12 09:00:00',
        ]);
        $maintenanceTask->forceFill([
            'created_at' => '2026-08-12 09:00:00',
            'updated_at' => '2026-08-12 09:00:00',
        ])->save();

        $cleaningTask = HousekeepingTask::create([
            'accommodation_id' => $room->id,
            'assigned_to' => $staff->id,
            'created_by' => $admin->id,
            'task_type' => HousekeepingTask::TYPE_CLEANING,
            'priority' => HousekeepingTask::PRIORITY_NORMAL,
            'status' => HousekeepingTask::STATUS_COMPLETED,
            'completed_at' => '2026-08-12 12:00:00',
            'created_at' => '2026-08-12 10:00:00',
        ]);
        $cleaningTask->forceFill([
            'created_at' => '2026-08-12 10:00:00',
            'updated_at' => '2026-08-12 12:00:00',
        ])->save();

        $this->actingAs($admin)
            ->getJson('/api/admin/reports?preset=custom&start_date=2026-08-01&end_date=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.housekeeping.summary.total', 2)
            ->assertJsonPath('data.housekeeping.summary.in_progress', 1)
            ->assertJsonPath('data.housekeeping.summary.completed', 1)
            ->assertJsonPath('data.housekeeping.summary.maintenance_related', 1);
    }

    public function test_inventory_aggregations_are_correct(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->createAsset(['category' => 'Furniture', 'quantity' => 2, 'status' => InventoryAsset::STATUS_AVAILABLE]);
        $this->createAsset(['asset_code' => 'INV-TV-001', 'category' => 'Appliance', 'quantity' => 1, 'status' => InventoryAsset::STATUS_MAINTENANCE, 'condition' => InventoryAsset::CONDITION_DAMAGED]);

        $this->actingAs($admin)
            ->getJson('/api/admin/reports?preset=custom&start_date=2026-08-01&end_date=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.inventory.summary.total_assets', 3)
            ->assertJsonPath('data.inventory.summary.available', 2)
            ->assertJsonPath('data.inventory.summary.maintenance', 1)
            ->assertJsonPath('data.inventory.summary.damaged', 1);
    }

    public function test_report_export_returns_csv(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get('/api/admin/reports/export?preset=custom&start_date=2026-08-01&end_date=2026-08-31')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_admin_can_export_pdf_and_excel_for_selected_period(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get('/api/admin/reports/export/pdf?preset=custom&start_date=2026-08-01&end_date=2026-08-31')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename="DMD_Resort_Report_2026-08-01_to_2026-08-31.pdf"');

        $excel = $this->actingAs($admin)
            ->get('/api/admin/reports/export/excel?preset=custom&start_date=2026-08-01&end_date=2026-08-31')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('content-disposition', 'attachment; filename=DMD_Resort_Report_2026-08-01_to_2026-08-31.xlsx');

        $this->assertStringStartsWith('PK', substr($excel->streamedContent(), 0, 2));
    }

    public function test_non_admin_cannot_export_management_reports(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($guest)
            ->get('/api/admin/reports/export/pdf?preset=this_month')
            ->assertForbidden();

        $this->actingAs($guest)
            ->get('/api/admin/reports/export/excel?preset=this_month')
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAccommodation(array $overrides = []): Accommodation
    {
        return Accommodation::create(array_merge([
            'name' => 'Demo Report Room',
            'slug' => 'demo-report-room-'.uniqid(),
            'type' => 'room',
            'capacity' => 2,
            'price_per_night' => 2500,
            'description' => 'Demo accommodation for report tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ], $overrides));
    }

    private function createReservation(User $guest, Accommodation $accommodation, string $status, string $createdAt): Reservation
    {
        $reservation = Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-22',
            'guests' => 2,
            'total_amount' => 5000,
            'status' => $status,
            'booking_reference' => 'DMD-'.uniqid(),
        ]);

        $reservation->forceFill([
            'created_at' => $createdAt.' 09:00:00',
            'updated_at' => $createdAt.' 09:00:00',
        ])->save();

        return $reservation;
    }

    private function createAttendance(User $staff, string $status): AttendanceRecord
    {
        return AttendanceRecord::create([
            'user_id' => $staff->id,
            'attendance_date' => '2026-08-12',
            'time_in' => '08:00',
            'time_out' => '17:00',
            'status' => $status,
            'verification_method' => AttendanceRecord::METHOD_MANUAL,
            'remarks' => 'Manual report test record.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAsset(array $overrides = []): InventoryAsset
    {
        return InventoryAsset::create(array_merge([
            'name' => 'Report Chair',
            'category' => 'Furniture',
            'asset_code' => 'INV-CHAIR-'.uniqid(),
            'quantity' => 1,
            'condition' => InventoryAsset::CONDITION_GOOD,
            'status' => InventoryAsset::STATUS_AVAILABLE,
            'location_type' => InventoryAsset::LOCATION_STORAGE,
            'location_name' => 'Main storage',
        ], $overrides));
    }
}

