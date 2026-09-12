<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use App\Events\AttendanceUpdated;
use Tests\TestCase;

class AdminAttendanceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['iot.device_key' => 'test-iot-device-key']);
        config(['iot.allow_legacy_device_key' => true]);
        config(['broadcasting.default' => 'null']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_fingerprint_scan_records_time_in_for_mapped_employee(): void
    {
        Event::fake([AttendanceUpdated::class]);
        Carbon::setTestNow('2026-09-01 07:58:00');
        $employee = $this->fingerprintEmployee(1);

        $this->postJson('/api/iot/attendance/fingerprint', [
            'fingerprint_id' => 1,
            'confidence' => 229,
            'device' => 'mega-as608-01',
        ], ['X-Device-Key' => 'test-iot-device-key'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'time_in')
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.verification_method', AttendanceRecord::METHOD_FINGERPRINT)
            ->assertJsonPath('data.device_id', 'mega-as608-01')
            ->assertJsonPath('confidence', 229);

        Event::assertDispatched(AttendanceUpdated::class, function (AttendanceUpdated $event) use ($employee) {
            return $event->employeeId === $employee->id && $event->action === 'fingerprint_time_in';
        });

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'time_in' => '07:58:00',
            'time_out' => null,
            'verification_method' => AttendanceRecord::METHOD_FINGERPRINT,
        ]);
    }

    public function test_admin_can_allocate_and_assign_unique_fingerprint_slot(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $first = $this->createEmployee($admin);
        $second = $this->createEmployee($admin, ['first_name' => 'Ana', 'last_name' => 'Two']);

        $first->update(['fingerprint_id' => 1]);

        $this->actingAs($admin)
            ->getJson('/api/admin/employees/fingerprint/available-slot')
            ->assertOk()
            ->assertJsonPath('fingerprint_id', 2);

        $this->actingAs($admin)
            ->postJson("/api/admin/employees/{$second->id}/fingerprint", ['fingerprint_id' => 2])
            ->assertOk()
            ->assertJsonPath('data.fingerprint_id', 2);

        $this->actingAs($admin)
            ->postJson("/api/admin/employees/{$second->id}/fingerprint", ['fingerprint_id' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint_id');
    }

    public function test_admin_can_clear_fingerprint_mapping_after_external_sensor_removal(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->createEmployee($admin, ['fingerprint_id' => 7]);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/employees/{$employee->id}/fingerprint")
            ->assertOk()
            ->assertJsonPath('data.fingerprint_id', null);
    }

    public function test_second_fingerprint_scan_updates_time_out_on_same_record(): void
    {
        $employee = $this->fingerprintEmployee(2);

        Carbon::setTestNow('2026-09-01 07:58:00');
        $this->fingerprintScan(2);
        $recordId = AttendanceRecord::query()->soleValue('id');

        Carbon::setTestNow('2026-09-01 17:03:00');
        $this->fingerprintScan(2)
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'time_out')
            ->assertJsonPath('message', 'Fingerprint attendance updated with time out.')
            ->assertJsonPath('data.id', $recordId);

        $this->assertSame(1, AttendanceRecord::query()->count());
        $this->assertDatabaseHas('attendance_records', [
            'id' => $recordId,
            'time_out' => '17:03:00',
        ]);
    }

    public function test_unknown_fingerprint_id_is_rejected(): void
    {
        $this->postJson('/api/iot/attendance/fingerprint', [
            'fingerprint_id' => 404,
            'confidence' => 229,
        ], ['X-Device-Key' => 'test-iot-device-key'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint_id');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_invalid_iot_device_key_is_rejected(): void
    {
        $this->postJson('/api/iot/attendance/fingerprint', [
            'fingerprint_id' => 1,
            'confidence' => 229,
        ], ['X-Device-Key' => 'wrong-key'])->assertUnauthorized();
    }

    public function test_inactive_employee_fingerprint_is_rejected(): void
    {
        $this->fingerprintEmployee(3, Employee::STATUS_INACTIVE);

        $this->postJson('/api/iot/attendance/fingerprint', [
            'fingerprint_id' => 3,
            'confidence' => 229,
        ], ['X-Device-Key' => 'test-iot-device-key'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint_id');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_rapid_repeated_fingerprint_scan_does_not_create_duplicate(): void
    {
        $this->fingerprintEmployee(4);

        Carbon::setTestNow('2026-09-01 08:00:00');
        $this->fingerprintScan(4);
        Carbon::setTestNow('2026-09-01 08:00:05');
        $this->fingerprintScan(4)
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'ignored')
            ->assertJsonPath('message', 'Repeated fingerprint scan ignored.');

        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_admin_can_create_employee_without_user_account(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/employees', [
                'user_id' => null,
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'position' => 'Cleaning Staff',
                'phone' => null,
                'date_hired' => null,
                'status' => Employee::STATUS_ACTIVE,
            ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', null)
            ->assertJsonPath('data.position', 'Cleaning Staff')
            ->assertJsonPath('data.employee_code', 'EMP-0001');

        $this->assertDatabaseHas('employees', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'position' => 'Cleaning Staff',
            'status' => Employee::STATUS_ACTIVE,
            'user_id' => null,
        ]);
    }

    public function test_employee_create_and_update_enforce_fingerprint_hardware_range(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/employees', [
                'user_id' => null,
                'fingerprint_id' => 1,
                'first_name' => 'Lower',
                'last_name' => 'Boundary',
                'position' => 'Front Desk',
                'phone' => null,
                'date_hired' => null,
                'status' => Employee::STATUS_ACTIVE,
            ])
            ->assertCreated()
            ->assertJsonPath('data.fingerprint_id', 1);

        $this->actingAs($admin)
            ->postJson('/api/admin/employees', [
                'user_id' => null,
                'fingerprint_id' => 127,
                'first_name' => 'Upper',
                'last_name' => 'Boundary',
                'position' => 'Front Desk',
                'phone' => null,
                'date_hired' => null,
                'status' => Employee::STATUS_ACTIVE,
            ])
            ->assertCreated()
            ->assertJsonPath('data.fingerprint_id', 127);

        $this->actingAs($admin)
            ->postJson('/api/admin/employees', [
                'user_id' => null,
                'fingerprint_id' => 0,
                'first_name' => 'Invalid',
                'last_name' => 'Lower',
                'position' => 'Front Desk',
                'status' => Employee::STATUS_ACTIVE,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint_id');

        $this->actingAs($admin)
            ->postJson('/api/admin/employees', [
                'user_id' => null,
                'fingerprint_id' => 128,
                'first_name' => 'Invalid',
                'last_name' => 'Upper',
                'position' => 'Front Desk',
                'status' => Employee::STATUS_ACTIVE,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint_id');

        $employee = Employee::query()->where('fingerprint_id', 1)->firstOrFail();

        $this->actingAs($admin)
            ->putJson("/api/admin/employees/{$employee->id}", [
                'user_id' => null,
                'fingerprint_id' => 128,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'position' => $employee->position,
                'phone' => $employee->phone,
                'date_hired' => null,
                'status' => $employee->status,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint_id');

        $this->actingAs($admin)
            ->putJson("/api/admin/employees/{$employee->id}", [
                'user_id' => null,
                'fingerprint_id' => 0,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'position' => $employee->position,
                'phone' => $employee->phone,
                'date_hired' => null,
                'status' => $employee->status,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fingerprint_id');

        $this->actingAs($admin)
            ->putJson("/api/admin/employees/{$employee->id}", [
                'user_id' => null,
                'fingerprint_id' => 1,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'position' => $employee->position,
                'phone' => $employee->phone,
                'date_hired' => null,
                'status' => $employee->status,
            ])
            ->assertOk()
            ->assertJsonPath('data.fingerprint_id', 1);
    }

    public function test_employee_code_is_generated_uniquely(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $first = $this->createEmployee($admin, ['first_name' => 'Ana', 'last_name' => 'One']);
        $second = $this->createEmployee($admin, ['first_name' => 'Ben', 'last_name' => 'Two']);

        $this->assertNotSame($first->employee_code, $second->employee_code);
        $this->assertMatchesRegularExpression('/^EMP-\d{4}$/', $second->employee_code);
    }

    public function test_non_login_employee_can_be_selected_for_manual_attendance(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->createEmployee($admin, [
            'user_id' => null,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'position' => 'Cleaning Staff',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/attendance-records', $this->attendancePayload($employee, [
                'remarks' => 'Manual entry for employee without login.',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.user_id', null);
    }

    public function test_manual_attendance_can_save_time_in_without_time_out(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->createEmployee($admin);

        $this->actingAs($admin)
            ->postJson('/api/admin/attendance-records', $this->attendancePayload($employee, [
                'time_out' => null,
                'remarks' => 'Time in only.',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.time_out', null);
    }

    public function test_time_out_can_update_the_same_attendance_record_later(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->createEmployee($admin);

        $record = $this->createAttendanceRecord($employee, [
            'time_out' => null,
            'remarks' => 'Morning time in.',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/admin/attendance-records/{$record->id}", array_merge($this->attendancePayload($employee), [
                'time_out' => '17:03',
                'correction_reason' => 'Recorded time out later.',
            ]))
            ->assertOk()
            ->assertJsonPath('data.time_out', '17:03');

        $this->assertDatabaseHas('attendance_records', [
            'id' => $record->id,
            'time_out' => '17:03',
        ]);
    }

    public function test_duplicate_daily_attendance_is_prevented(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->createEmployee($admin);

        $this->createAttendanceRecord($employee);

        $this->actingAs($admin)
            ->postJson('/api/admin/attendance-records', $this->attendancePayload($employee))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attendance_date');
    }

    public function test_existing_linked_staff_users_still_work(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $staff = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $employee = $this->createEmployee($admin, ['user_id' => $staff->id, 'position' => 'Front Desk']);

        $this->actingAs($admin)
            ->postJson('/api/admin/attendance-records', $this->attendancePayload($employee))
            ->assertCreated()
            ->assertJsonPath('data.employee.user_id', $staff->id)
            ->assertJsonPath('data.user_id', $staff->id);
    }

    public function test_customer_users_are_not_automatically_treated_as_employees(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->assertDatabaseMissing('employees', [
            'user_id' => $customer->id,
        ]);
    }

    public function test_deactivated_employees_cannot_normally_receive_new_attendance(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->createEmployee($admin, ['status' => Employee::STATUS_INACTIVE]);

        $this->actingAs($admin)
            ->postJson('/api/admin/attendance-records', $this->attendancePayload($employee))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    }

    public function test_unauthorized_users_cannot_manage_employees(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->actingAs($manager)
            ->getJson('/api/admin/employees')
            ->assertForbidden();
    }

    public function test_employee_without_user_account_has_no_authentication_access(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->createEmployee($admin, ['user_id' => null]);

        $this->assertNull($employee->user_id);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'user_id' => null,
        ]);
    }

    public function test_audit_events_are_recorded_for_employee_and_attendance_actions(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->createEmployee($admin);

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'attendance',
            'action' => 'employee_created',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/attendance-records', $this->attendancePayload($employee))
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'attendance',
            'action' => 'manual_attendance_created',
        ]);
    }

    private function createEmployee(User $admin, array $overrides = []): Employee
    {
        $this->actingAs($admin)
            ->postJson('/api/admin/employees', array_merge([
                'user_id' => null,
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'position' => 'Cleaning Staff',
                'phone' => null,
                'date_hired' => null,
                'status' => Employee::STATUS_ACTIVE,
            ], $overrides))
            ->assertCreated();

        return Employee::query()->latest('id')->firstOrFail();
    }

    private function attendancePayload(Employee $employee, array $overrides = []): array
    {
        return array_merge([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-08-31',
            'time_in' => '08:00',
            'time_out' => '17:00',
            'status' => AttendanceRecord::STATUS_PRESENT,
            'verification_method' => AttendanceRecord::METHOD_MANUAL,
            'device_id' => 'manual-admin-entry',
            'remarks' => 'Manual attendance record.',
        ], $overrides);
    }

    private function createAttendanceRecord(Employee $employee, array $overrides = []): AttendanceRecord
    {
        return AttendanceRecord::create(array_merge([
            'employee_id' => $employee->id,
            'user_id' => $employee->user_id,
            'attendance_date' => '2026-08-31',
            'time_in' => '08:00',
            'time_out' => '17:00',
            'status' => AttendanceRecord::STATUS_PRESENT,
            'verification_method' => AttendanceRecord::METHOD_MANUAL,
            'device_id' => 'manual-admin-entry',
            'remarks' => 'Manual attendance record.',
        ], $overrides));
    }

    private function fingerprintScan(int $fingerprintId)
    {
        return $this->postJson('/api/iot/attendance/fingerprint', [
            'fingerprint_id' => $fingerprintId,
            'confidence' => 229,
            'device' => 'mega-as608-01',
        ], ['X-Device-Key' => 'test-iot-device-key'])->assertOk();
    }

    private function fingerprintEmployee(int $fingerprintId, string $status = Employee::STATUS_ACTIVE): Employee
    {
        return Employee::create([
            'employee_code' => sprintf('FP-%04d', $fingerprintId),
            'fingerprint_id' => $fingerprintId,
            'first_name' => 'Fingerprint',
            'last_name' => 'Employee '.$fingerprintId,
            'position' => 'Cleaning Staff',
            'status' => $status,
        ]);
    }
}
