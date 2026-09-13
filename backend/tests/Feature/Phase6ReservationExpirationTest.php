<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Phase6ReservationExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_reservation_receives_configured_payment_hold_deadline(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 10:00:00');
        config()->set('reservations.payment_hold_minutes', 15);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->accommodation('phase6-new');

        $response = $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-09-08',
            'check_out' => '2026-09-09',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])->assertCreated();

        $this->assertSame('2026-09-07T02:15:00.000000Z', $response->json('data.expires_at'));
        $this->assertDatabaseHas('reservations', [
            'id' => $response->json('data.id'),
            'status' => Reservation::STATUS_PENDING,
        ]);
    }

    public function test_expired_pending_reservation_no_longer_blocks_availability_before_cleanup(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 10:16:00');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->accommodation('phase6-availability');
        $this->reservation($guest, $accommodation, Reservation::STATUS_PENDING, CarbonImmutable::parse('2026-09-07 10:15:00'));

        $this->getJson("/api/accommodations/{$accommodation->id}/availability?check_in=2026-09-08&check_out=2026-09-09&adults=1")
            ->assertOk()
            ->assertJsonPath('available', true);
    }

    public function test_expiration_command_is_idempotent_and_audits_the_expired_hold(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 10:16:00');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->accommodation('phase6-command');
        $reservation = $this->reservation($guest, $accommodation, Reservation::STATUS_PENDING, CarbonImmutable::parse('2026-09-07 10:15:00'));

        $this->artisan('reservations:expire-pending')->expectsOutput('Expired 1 pending reservation hold(s).')->assertSuccessful();
        $this->artisan('reservations:expire-pending')->expectsOutput('Expired 0 pending reservation hold(s).')->assertSuccessful();

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => Reservation::STATUS_EXPIRED]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'reservation_expired',
            'module' => 'booking_management',
            'entity_id' => $reservation->id,
        ]);
    }

    public function test_confirmed_and_partially_paid_reservations_are_never_expired(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 10:16:00');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $confirmed = $this->reservation($guest, $this->accommodation('phase6-confirmed'), Reservation::STATUS_CONFIRMED, CarbonImmutable::parse('2026-09-07 10:00:00'));
        $partial = $this->reservation($guest, $this->accommodation('phase6-partial'), Reservation::STATUS_CONFIRMED, CarbonImmutable::parse('2026-09-07 10:00:00'));
        $partial->payments()->create([
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            'provider' => 'paymongo',
            'amount' => 300000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'paid_at' => CarbonImmutable::now(),
        ]);

        $this->artisan('reservations:expire-pending')->assertSuccessful();

        $this->assertDatabaseHas('reservations', ['id' => $confirmed->id, 'status' => Reservation::STATUS_CONFIRMED]);
        $this->assertDatabaseHas('reservations', ['id' => $partial->id, 'status' => Reservation::STATUS_CONFIRMED]);
    }

    public function test_failed_and_cancelled_attempts_do_not_prevent_expiration_or_create_financial_balance(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 10:16:00');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $failed = $this->reservation($guest, $this->accommodation('phase6-failed'), Reservation::STATUS_PENDING, CarbonImmutable::parse('2026-09-07 10:15:00'));
        $cancelled = $this->reservation($guest, $this->accommodation('phase6-cancelled'), Reservation::STATUS_PENDING, CarbonImmutable::parse('2026-09-07 10:15:00'));
        foreach ([[$failed, ReservationPayment::STATUS_FAILED], [$cancelled, ReservationPayment::STATUS_CANCELLED]] as [$reservation, $status]) {
            ReservationPayment::create([
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_FULL,
                'provider' => 'paymongo',
                'amount' => 1000000,
                'currency' => 'PHP',
                'status' => $status,
            ]);
        }

        $this->artisan('reservations:expire-pending')->assertSuccessful();

        $this->assertSame(2, Reservation::where('status', Reservation::STATUS_EXPIRED)->count());
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->getJson('/api/admin/reports?preset=custom&start_date=2026-09-07&end_date=2026-09-07')
            ->assertOk()
            ->assertJsonPath('data.financial.booking_value', '0.00')
            ->assertJsonPath('data.financial.collected_revenue', '0.00')
            ->assertJsonPath('data.financial.outstanding_balance', '0.00');
    }

    public function test_expired_reservation_cannot_create_checkout_or_front_desk_payment(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 10:16:00');
        config()->set('services.paymongo.secret_key', 'sk_test_secret');
        Http::fake();
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $reservation = $this->reservation($guest, $this->accommodation('phase6-expired'), Reservation::STATUS_EXPIRED, CarbonImmutable::parse('2026-09-07 10:15:00'));

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_FULL,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reservation_id']);

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_BALANCE,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reservation_id']);

        $this->actingAs($frontDesk)
            ->postJson("/api/frontdesk/reservations/{$reservation->id}/payments", [
                'amount' => '1.00',
                'payment_method' => 'cash',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reservation']);

        $this->actingAs($frontDesk)
            ->patchJson("/api/frontdesk/reservations/{$reservation->id}/status", [
                'status' => Reservation::STATUS_CHECKED_IN,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_provider_payment_completed_before_expiry_can_finish_a_hold_race(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 10:20:00');
        config()->set('services.paymongo.secret_key', 'sk_test_secret');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->reservation($guest, $this->accommodation('phase6-race-before'), Reservation::STATUS_EXPIRED, CarbonImmutable::parse('2026-09-07 10:15:00'));
        $payment = $this->pendingPayment($reservation, 'cs-race-before');

        Http::fake([
            'api.paymongo.com/v1/checkout_sessions/cs-race-before' => Http::response([
                'data' => [
                    'id' => $payment->checkout_session_id,
                    'attributes' => [
                        'status' => 'active',
                        'reference_number' => $reservation->booking_reference,
                        'metadata' => ['payment_purpose' => ReservationPayment::PURPOSE_FULL],
                        'payments' => [[
                            'id' => 'pay-race-before',
                            'attributes' => [
                                'status' => 'paid',
                                'amount' => 1000000,
                                'currency' => 'PHP',
                                'payment_method_type' => 'card',
                                'paid_at' => CarbonImmutable::parse('2026-09-07T10:14:00+08:00')->timestamp,
                            ],
                        ]],
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($guest)
            ->getJson("/api/payments/reservations/{$reservation->id}/status")
            ->assertOk()
            ->assertJsonPath('data.reservation_status', Reservation::STATUS_CONFIRMED)
            ->assertJsonPath('data.payment_status', ReservationPayment::STATUS_PAID);
    }

    public function test_provider_payment_completed_after_expiry_requires_reconciliation(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 10:20:00');
        config()->set('services.paymongo.secret_key', 'sk_test_secret');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->reservation($guest, $this->accommodation('phase6-race-after'), Reservation::STATUS_EXPIRED, CarbonImmutable::parse('2026-09-07 10:15:00'));
        $payment = $this->pendingPayment($reservation, 'cs-race-after');

        Http::fake([
            'api.paymongo.com/v1/checkout_sessions/cs-race-after' => Http::response([
                'data' => [
                    'id' => $payment->checkout_session_id,
                    'attributes' => [
                        'status' => 'active',
                        'reference_number' => $reservation->booking_reference,
                        'metadata' => ['payment_purpose' => ReservationPayment::PURPOSE_FULL],
                        'payments' => [[
                            'id' => 'pay-race-after',
                            'attributes' => [
                                'status' => 'paid',
                                'amount' => 1000000,
                                'currency' => 'PHP',
                                'payment_method_type' => 'card',
                                'paid_at' => '2026-09-07T10:16:00+08:00',
                            ],
                        ]],
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($guest)
            ->getJson("/api/payments/reservations/{$reservation->id}/status")
            ->assertOk()
            ->assertJsonPath('data.source', 'reconciliation_required')
            ->assertJsonPath('data.reservation_status', Reservation::STATUS_EXPIRED);

        $this->assertDatabaseHas('reservation_payments', [
            'id' => $payment->id,
            'status' => ReservationPayment::STATUS_PENDING,
        ]);
    }

    private function accommodation(string $slug): Accommodation
    {
        return Accommodation::create([
            'name' => $slug,
            'slug' => $slug.'-'.uniqid(),
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 5000,
            'description' => 'Phase 6 test accommodation.',
            'status' => Accommodation::STATUS_AVAILABLE,
        ]);
    }

    private function reservation(User $guest, Accommodation $accommodation, string $status, CarbonImmutable $expiresAt): Reservation
    {
        return Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-09-08',
            'check_out' => '2026-09-09',
            'guests' => 1,
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'total_amount' => '10000.00',
            'status' => $status,
            'expires_at' => $expiresAt,
            'booking_reference' => 'DMD-PHASE6-'.strtoupper(substr(uniqid(), -7)),
        ]);
    }

    private function pendingPayment(Reservation $reservation, string $checkoutSessionId): ReservationPayment
    {
        return $reservation->payments()->create([
            'purpose' => ReservationPayment::PURPOSE_FULL,
            'provider' => 'paymongo',
            'provider_reference' => $reservation->booking_reference,
            'checkout_session_id' => $checkoutSessionId,
            'checkout_url' => 'https://paymongo.test/'.$checkoutSessionId,
            'amount' => 1000000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PENDING,
        ]);
    }
}
