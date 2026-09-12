<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Phase4FrontDeskPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_front_desk_can_record_full_cash_balance_and_preserve_deposit(): void
    {
        [$frontDesk, $reservation, $deposit] = $this->partiallyPaidReservation();

        $response = $this->actingAs($frontDesk)
            ->postJson("/api/frontdesk/reservations/{$reservation->id}/payments", [
                'amount' => '7000.00',
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment.purpose', ReservationPayment::PURPOSE_BALANCE)
            ->assertJsonPath('data.payment.amount', '7000.00')
            ->assertJsonPath('data.payment.status', ReservationPayment::STATUS_PAID)
            ->assertJsonPath('data.payment.payment_method', 'cash')
            ->assertJsonPath('data.payment.recorded_by.id', $frontDesk->id)
            ->assertJsonPath('data.reservation.payment_state', 'fully_paid')
            ->assertJsonPath('data.reservation.balance_due', '0.00');

        $this->assertDatabaseHas('reservation_payments', [
            'id' => $deposit->id,
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            'amount' => 300000,
            'status' => ReservationPayment::STATUS_PAID,
        ]);
        $this->assertDatabaseHas('reservation_payments', [
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_BALANCE,
            'amount' => 700000,
            'payment_method' => 'cash',
            'recorded_by_user_id' => $frontDesk->id,
            'status' => ReservationPayment::STATUS_PAID,
        ]);
        $this->assertSame(2, count($response->json('data.reservation.payment_history')));
    }

    public function test_front_desk_can_record_partial_cash_balance(): void
    {
        [$frontDesk, $reservation] = $this->partiallyPaidReservation();

        $this->actingAs($frontDesk)
            ->postJson("/api/frontdesk/reservations/{$reservation->id}/payments", [
                'amount' => '4000.00',
                'payment_method' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('data.reservation.total_paid', '7000.00')
            ->assertJsonPath('data.reservation.balance_due', '3000.00')
            ->assertJsonPath('data.reservation.payment_state', 'partially_paid');
    }

    public function test_cash_amount_validation_rejects_zero_negative_and_overpayment(): void
    {
        foreach (['0.00', '-1.00', '7000.01'] as $amount) {
            [$frontDesk, $reservation] = $this->partiallyPaidReservation();

            $this->actingAs($frontDesk)
                ->postJson("/api/frontdesk/reservations/{$reservation->id}/payments", [
                    'amount' => $amount,
                    'payment_method' => 'cash',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['amount']);
        }
    }

    public function test_fully_paid_pending_cancelled_and_checked_out_reservations_reject_cash_balance(): void
    {
        [$frontDesk, $fullyPaid] = $this->partiallyPaidReservation();
        $fullyPaid->payments()->create([
            'purpose' => ReservationPayment::PURPOSE_BALANCE,
            'provider' => 'manual',
            'amount' => 700000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'payment_method' => 'cash',
        ]);

        $pending = $this->createReservation($fullyPaid->user);
        $cancelled = $this->createReservation($fullyPaid->user, Reservation::STATUS_CANCELLED);
        $checkedOut = $this->createReservation($fullyPaid->user, Reservation::STATUS_CHECKED_OUT);

        foreach ([$fullyPaid, $pending, $cancelled, $checkedOut] as $reservation) {
            $this->actingAs($frontDesk)
                ->postJson("/api/frontdesk/reservations/{$reservation->id}/payments", [
                    'amount' => '1.00',
                    'payment_method' => 'cash',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['reservation']);
        }
    }

    public function test_customer_and_non_front_desk_staff_cannot_record_cash_payment(): void
    {
        [$frontDesk, $reservation] = $this->partiallyPaidReservation();
        $customer = $reservation->user;
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);

        foreach ([$customer, $manager] as $actor) {
            $this->actingAs($actor)
                ->postJson("/api/frontdesk/reservations/{$reservation->id}/payments", [
                    'amount' => '7000.00',
                    'payment_method' => 'cash',
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('reservation_payments', 1);
        $this->assertNotNull($frontDesk);
    }

    public function test_cash_payment_is_visible_to_customer_and_audit_does_not_expose_payload(): void
    {
        [$frontDesk, $reservation] = $this->partiallyPaidReservation();

        $this->actingAs($frontDesk)->postJson("/api/frontdesk/reservations/{$reservation->id}/payments", [
            'amount' => '7000.00',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->actingAs($reservation->user)
            ->getJson('/api/payments')
            ->assertOk()
            ->assertJsonPath('data.0.purpose', ReservationPayment::PURPOSE_BALANCE)
            ->assertJsonPath('data.0.payment_method', 'cash')
            ->assertJsonMissingPath('data.0.payload')
            ->assertJsonMissingPath('data.0.recorded_by');

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'booking_management',
            'action' => 'front_desk_payment_recorded',
            'user_id' => $frontDesk->id,
        ]);
    }

    public function test_partially_paid_reservation_cannot_check_in_but_fully_paid_can(): void
    {
        [$frontDesk, $reservation] = $this->partiallyPaidReservation();

        $this->actingAs($frontDesk)
            ->patchJson("/api/frontdesk/reservations/{$reservation->id}/status", [
                'status' => Reservation::STATUS_CHECKED_IN,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($frontDesk)->postJson("/api/frontdesk/reservations/{$reservation->id}/payments", [
            'amount' => '7000.00',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->actingAs($frontDesk)
            ->patchJson("/api/frontdesk/reservations/{$reservation->id}/status", [
                'status' => Reservation::STATUS_CHECKED_IN,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CHECKED_IN);
    }

    public function test_second_cash_submission_cannot_overpay_after_balance_is_settled(): void
    {
        [$frontDesk, $reservation] = $this->partiallyPaidReservation();

        $this->actingAs($frontDesk)->postJson("/api/frontdesk/reservations/{$reservation->id}/payments", [
            'amount' => '7000.00',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->actingAs($frontDesk)
            ->postJson("/api/frontdesk/reservations/{$reservation->id}/payments", [
                'amount' => '1.00',
                'payment_method' => 'cash',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reservation']);

        $this->assertDatabaseCount('reservation_payments', 2);
    }

    /** @return array{0: User, 1: Reservation, 2: ReservationPayment} */
    private function partiallyPaidReservation(): array
    {
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $customer = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($customer, Reservation::STATUS_CONFIRMED);
        $deposit = ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            'provider' => 'paymongo',
            'amount' => 300000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'payment_method' => 'card',
            'paid_at' => Carbon::now(),
        ]);

        return [$frontDesk, $reservation->fresh(), $deposit];
    }

    private function createReservation(User $user, string $status = Reservation::STATUS_PENDING): Reservation
    {
        $accommodation = Accommodation::create([
            'name' => 'Phase 4 Test Room',
            'slug' => 'phase-4-test-'.uniqid(),
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 5000,
            'description' => 'Phase 4 test accommodation.',
            'status' => Accommodation::STATUS_AVAILABLE,
        ]);

        return Reservation::create([
            'user_id' => $user->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-12-01',
            'check_out' => '2026-12-03',
            'guests' => 2,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'total_amount' => '10000.00',
            'status' => $status,
            'booking_reference' => 'DMD-PHASE4-'.strtoupper(substr(uniqid(), -7)),
        ]);
    }
}
