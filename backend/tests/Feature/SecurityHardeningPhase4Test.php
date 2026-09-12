<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Events\AttendanceUpdated;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Tests\TestCase;

class SecurityHardeningPhase4Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'iot.devices' => ['mega-as608-01' => 'test-iot-device-key'],
            'iot.signature_tolerance_seconds' => 300,
            'iot.attendance_rate_limit' => 120,
            'iot.allow_legacy_device_key' => false,
            'broadcasting.default' => 'null',
        ]);
    }

    public function test_valid_signed_scan_is_accepted_and_repeated_scan_preserves_attendance_behavior(): void
    {
        $employee = $this->fingerprintEmployee(9);
        $this->signedScan(['fingerprint_id' => 9, 'confidence' => 229, 'device' => 'mega-as608-01'])
            ->assertOk()->assertJsonPath('action', 'time_in');

        $this->signedScan(['fingerprint_id' => 9, 'confidence' => 229, 'device' => 'mega-as608-01'])
            ->assertOk()->assertJsonPath('action', 'ignored');
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', ['employee_id' => $employee->id]);
    }

    public function test_missing_wrong_and_altered_signatures_are_rejected(): void
    {
        $employee = $this->fingerprintEmployee(10);
        $payload = ['fingerprint_id' => 10, 'confidence' => 229, 'device' => 'mega-as608-01'];
        $this->postJson('/api/iot/attendance/fingerprint', $payload)->assertUnauthorized();
        $this->signedScan($payload, 'wrong-secret')->assertUnauthorized();
        $this->signedScan($payload, null, ['HTTP_X_DEVICE_ID' => 'other-device'])->assertUnauthorized();
    }

    public function test_stale_future_and_reused_nonces_are_rejected(): void
    {
        $this->fingerprintEmployee(11);
        $payload = ['fingerprint_id' => 11, 'confidence' => 229, 'device' => 'mega-as608-01'];
        $this->signedScan($payload, null, [], now()->subSeconds(301)->timestamp)->assertUnauthorized();
        $this->signedScan($payload, null, [], now()->addSeconds(301)->timestamp)->assertUnauthorized();

        $nonce = Str::uuid()->toString();
        $this->signedScan($payload, null, [], now()->timestamp, $nonce)->assertOk();
        $this->signedScan($payload, null, [], now()->timestamp, $nonce)->assertUnauthorized();
    }

    public function test_changed_body_after_signing_is_rejected(): void
    {
        $this->fingerprintEmployee(12);
        $payload = ['fingerprint_id' => 12, 'confidence' => 229, 'device' => 'mega-as608-01'];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $nonce = Str::uuid()->toString();
        $signature = $this->signature('POST', '/api/iot/attendance/fingerprint', $timestamp, $nonce, $body, 'test-iot-device-key');

        $this->call('POST', '/api/iot/attendance/fingerprint', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_DEVICE_ID' => 'mega-as608-01',
            'HTTP_X_DEVICE_TIMESTAMP' => $timestamp,
            'HTTP_X_DEVICE_NONCE' => $nonce,
            'HTTP_X_DEVICE_SIGNATURE' => $signature,
        ], json_encode(['fingerprint_id' => 12, 'confidence' => 230, 'device' => 'mega-as608-01']))
            ->assertUnauthorized();
    }

    public function test_inactive_and_unknown_fingerprints_remain_rejected(): void
    {
        $this->fingerprintEmployee(13, Employee::STATUS_INACTIVE);
        $this->signedScan(['fingerprint_id' => 13, 'confidence' => 229, 'device' => 'mega-as608-01'])
            ->assertUnprocessable();
        $this->signedScan(['fingerprint_id' => 99, 'confidence' => 229, 'device' => 'mega-as608-01'])
            ->assertUnprocessable();
    }

    public function test_dedicated_iot_rate_limit_applies(): void
    {
        config(['iot.attendance_rate_limit' => 1]);
        $this->fingerprintEmployee(14);
        $payload = ['fingerprint_id' => 14, 'confidence' => 229, 'device' => 'mega-as608-01'];
        $this->signedScan($payload)->assertOk();
        $this->signedScan($payload)->assertStatus(429);
    }

    public function test_admin_fingerprint_operations_are_proxied_without_exposing_bridge_secret(): void
    {
        Http::fake([
            'http://127.0.0.1:8765/fingerprints/enroll' => Http::response(['ok' => true, 'message' => 'ENROLL_OK:7']),
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        config(['iot.bridge_control_key' => 'bridge-secret']);

        $this->actingAs($admin)
            ->postJson('/api/admin/iot/fingerprints/enroll', ['fingerprint_id' => 7])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://127.0.0.1:8765/fingerprints/enroll'
                && $request->header('X-Bridge-Key')[0] === 'bridge-secret';
        });
    }

    public function test_attendance_updates_are_queued_instead_of_broadcast_synchronously(): void
    {
        $event = new AttendanceUpdated(1, 2, 'fingerprint_time_in');

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertNotInstanceOf(ShouldBroadcastNow::class, $event);
    }

    public function test_successful_physical_delete_clears_the_employee_mapping(): void
    {
        Http::fake([
            'http://127.0.0.1:8765/fingerprints/delete' => Http::response(['ok' => true, 'message' => 'DELETE_OK:7']),
        ]);
        config(['iot.bridge_control_key' => 'bridge-secret']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->fingerprintEmployee(7);

        $this->actingAs($admin)
            ->postJson("/api/admin/iot/fingerprints/delete-for-employee/{$employee->id}", ['fingerprint_id' => 7])
            ->assertOk()
            ->assertJsonPath('data.fingerprint_id', null);

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'fingerprint_id' => null]);
    }

    public function test_failed_or_timed_out_physical_delete_preserves_the_mapping(): void
    {
        Http::fake([
            'http://127.0.0.1:8765/fingerprints/delete' => Http::response(['ok' => false, 'error' => 'DELETE_FAILED'], 422),
        ]);
        config(['iot.bridge_control_key' => 'bridge-secret']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->fingerprintEmployee(8);

        $this->actingAs($admin)
            ->postJson("/api/admin/iot/fingerprints/delete-for-employee/{$employee->id}", ['fingerprint_id' => 8])
            ->assertUnprocessable();

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'fingerprint_id' => 8]);
    }

    public function test_bridge_timeout_preserves_the_mapping(): void
    {
        Http::fake([
            'http://127.0.0.1:8765/fingerprints/delete' => Http::response(['message' => 'Operation timed out waiting for Arduino.'], 504),
        ]);
        config(['iot.bridge_control_key' => 'bridge-secret']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->fingerprintEmployee(16);

        $this->actingAs($admin)
            ->postJson("/api/admin/iot/fingerprints/delete-for-employee/{$employee->id}", ['fingerprint_id' => 16])
            ->assertStatus(504);

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'fingerprint_id' => 16]);
    }

    public function test_repeated_delete_request_does_not_issue_a_second_physical_delete(): void
    {
        Http::fake([
            'http://127.0.0.1:8765/fingerprints/delete' => Http::response(['ok' => true, 'message' => 'DELETE_OK:15']),
        ]);
        config(['iot.bridge_control_key' => 'bridge-secret']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->fingerprintEmployee(15);

        $this->actingAs($admin)
            ->postJson("/api/admin/iot/fingerprints/delete-for-employee/{$employee->id}", ['fingerprint_id' => 15])
            ->assertOk();
        $this->actingAs($admin)
            ->postJson("/api/admin/iot/fingerprints/delete-for-employee/{$employee->id}", ['fingerprint_id' => 15])
            ->assertStatus(409);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'fingerprint_id' => null]);
    }

    private function signedScan(array $payload, ?string $secret = null, array $overrides = [], ?int $timestamp = null, ?string $nonce = null)
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) ($timestamp ?? now()->timestamp);
        $nonce ??= Str::uuid()->toString();
        $secret ??= 'test-iot-device-key';
        $signature = $this->signature('POST', '/api/iot/attendance/fingerprint', $timestamp, $nonce, $body, $secret);

        return $this->call('POST', '/api/iot/attendance/fingerprint', [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_DEVICE_ID' => 'mega-as608-01',
            'HTTP_X_DEVICE_TIMESTAMP' => $timestamp,
            'HTTP_X_DEVICE_NONCE' => $nonce,
            'HTTP_X_DEVICE_SIGNATURE' => $signature,
        ], $overrides), $body);
    }

    private function signature(string $method, string $path, string $timestamp, string $nonce, string $body, string $secret): string
    {
        $canonical = implode("\n", [$method, $path, $timestamp, $nonce, hash('sha256', $body)]);

        return hash_hmac('sha256', $canonical, $secret);
    }

    private function fingerprintEmployee(int $fingerprintId, string $status = Employee::STATUS_ACTIVE): Employee
    {
        return Employee::create([
            'employee_code' => sprintf('SEC-%04d', $fingerprintId),
            'fingerprint_id' => $fingerprintId,
            'first_name' => 'Security',
            'last_name' => 'Employee '.$fingerprintId,
            'position' => 'Staff',
            'status' => $status,
        ]);
    }
}
