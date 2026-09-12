<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationPaymentFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_without_payments_is_unpaid_with_full_balance(): void
    {
        $reservation = $this->createReservation();

        $this->assertSame(0, $reservation->totalPaidMinor());
        $this->assertSame(1000000, $reservation->balanceDueMinor());
        $this->assertSame('unpaid', $reservation->paymentState());
    }

    public function test_one_full_paid_payment_fully_pays_reservation(): void
    {
        $reservation = $this->createReservation();
        $this->createPayment($reservation, 1000000, ReservationPayment::STATUS_PAID);

        $reservation->loadPaymentSummary();

        $this->assertSame(1000000, $reservation->totalPaidMinor());
        $this->assertSame(0, $reservation->balanceDueMinor());
        $this->assertSame('fully_paid', $reservation->paymentState());
    }

    public function test_future_style_deposit_is_partially_paid(): void
    {
        $reservation = $this->createReservation();
        $this->createPayment($reservation, 300000, ReservationPayment::STATUS_PAID, ReservationPayment::PURPOSE_DEPOSIT);

        $reservation->loadPaymentSummary();

        $this->assertSame(300000, $reservation->totalPaidMinor());
        $this->assertSame(700000, $reservation->balanceDueMinor());
        $this->assertSame('partially_paid', $reservation->paymentState());
    }

    public function test_multiple_paid_payments_are_aggregated(): void
    {
        $reservation = $this->createReservation();
        $this->createPayment($reservation, 300000, ReservationPayment::STATUS_PAID, ReservationPayment::PURPOSE_DEPOSIT);
        $this->createPayment($reservation, 700000, ReservationPayment::STATUS_PAID, ReservationPayment::PURPOSE_BALANCE);

        $reservation->loadPaymentSummary();

        $this->assertSame(1000000, $reservation->totalPaidMinor());
        $this->assertSame(0, $reservation->balanceDueMinor());
        $this->assertSame('fully_paid', $reservation->paymentState());
    }

    public function test_non_paid_payment_statuses_do_not_count(): void
    {
        $reservation = $this->createReservation();
        $this->createPayment($reservation, 300000, ReservationPayment::STATUS_PENDING);
        $this->createPayment($reservation, 300000, ReservationPayment::STATUS_FAILED);
        $this->createPayment($reservation, 300000, ReservationPayment::STATUS_CANCELLED);

        $reservation->loadPaymentSummary();

        $this->assertSame(0, $reservation->totalPaidMinor());
        $this->assertSame('10000.00', $reservation->paymentSummary()['balance_due']);
        $this->assertSame('unpaid', $reservation->paymentState());
    }

    public function test_reservation_serialization_exposes_calculated_payment_fields(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest);
        $this->createPayment($reservation, 300000, ReservationPayment::STATUS_PAID, ReservationPayment::PURPOSE_DEPOSIT);

        $response = $this->actingAs($guest)->getJson('/api/reservations');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.total_amount', 10000)
            ->assertJsonPath('data.0.total_paid', '3000.00')
            ->assertJsonPath('data.0.balance_due', '7000.00')
            ->assertJsonPath('data.0.payment_state', 'partially_paid');
    }

    public function test_customer_reservation_feed_is_newest_first_and_scoped_to_owner(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $otherGuest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $past = $this->createReservation($guest);
        $past->forceFill([
            'check_in' => '2026-08-01',
            'check_out' => '2026-08-02',
            'status' => Reservation::STATUS_COMPLETED,
        ])->save();

        $future = $this->createReservation($guest);
        $future->forceFill([
            'check_in' => '2026-09-17',
            'check_out' => '2026-09-18',
            'status' => Reservation::STATUS_CONFIRMED,
        ])->save();

        $this->createReservation($otherGuest);

        $response = $this->actingAs($guest)->getJson('/api/reservations')->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data);
        $this->assertSame($future->id, $data[0]['id']);
        $this->assertSame($past->id, $data[1]['id']);
        $this->assertSame('confirmed', $data[0]['status']);
        $this->assertArrayHasKey('payment_state', $data[0]);
    }

    private function createReservation(?User $user = null): Reservation
    {
        $user ??= User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = Accommodation::create([
            'name' => 'Foundation Test Room',
            'slug' => 'foundation-test-room-'.uniqid(),
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 5000,
            'description' => 'Foundation test accommodation.',
            'status' => Accommodation::STATUS_AVAILABLE,
        ]);

        return Reservation::create([
            'user_id' => $user->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-03',
            'guests' => 2,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'total_amount' => '10000.00',
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-FOUNDATION-'.strtoupper(substr(uniqid(), -6)),
        ]);
    }

    private function createPayment(
        Reservation $reservation,
        int $amount,
        string $status,
        string $purpose = ReservationPayment::PURPOSE_FULL,
    ): ReservationPayment {
        return ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => $purpose,
            'provider' => 'test',
            'provider_reference' => $reservation->booking_reference,
            'checkout_session_id' => 'cs-foundation-'.uniqid(),
            'amount' => $amount,
            'currency' => 'PHP',
            'status' => $status,
            'paid_at' => $status === ReservationPayment::STATUS_PAID ? now() : null,
        ]);
    }
}
