<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Phase3BalancePaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_checkout_uses_current_server_calculated_balance(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->partiallyPaidReservation($guest);
        $this->fakeCheckout($reservation, 'cs_balance', 700000);

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_BALANCE,
            ])
            ->assertOk()
            ->assertJsonPath('data.purpose', ReservationPayment::PURPOSE_BALANCE)
            ->assertJsonPath('data.amount', 700000)
            ->assertJsonPath('data.status', ReservationPayment::STATUS_PENDING);

        $this->assertDatabaseHas('reservation_payments', [
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_BALANCE,
            'amount' => 700000,
            'status' => ReservationPayment::STATUS_PENDING,
        ]);
    }

    public function test_customer_cannot_override_balance_amount(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->partiallyPaidReservation($guest);

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_BALANCE,
                'amount' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_verified_balance_payment_fully_pays_reservation_and_keeps_it_confirmed(): void
    {
        config()->set('services.paymongo.secret_key', 'sk_test_secret');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->partiallyPaidReservation($guest);
        $payment = ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_BALANCE,
            'provider' => 'paymongo',
            'provider_reference' => $reservation->booking_reference,
            'checkout_session_id' => 'cs_balance_paid',
            'amount' => 700000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PENDING,
        ]);

        $this->fakeRetrievedCheckout($reservation, $payment, 700000, 'PHP');

        $this->actingAs($guest)
            ->getJson("/api/payments/reservations/{$reservation->id}/status")
            ->assertOk()
            ->assertJsonPath('data.reservation_status', Reservation::STATUS_CONFIRMED)
            ->assertJsonPath('data.payment_state', 'fully_paid')
            ->assertJsonPath('data.reservation.total_paid', '10000.00')
            ->assertJsonPath('data.reservation.balance_due', '0.00');

        $this->assertDatabaseHas('reservation_payments', [
            'id' => $payment->id,
            'status' => ReservationPayment::STATUS_PAID,
        ]);
    }

    public function test_failed_or_cancelled_balance_does_not_change_paid_deposit_or_balance(): void
    {
        config()->set('services.paymongo.secret_key', 'sk_test_secret');
        foreach ([ReservationPayment::STATUS_FAILED => 'failed', ReservationPayment::STATUS_CANCELLED => 'cancelled'] as $status => $eventStatus) {
            $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
            $reservation = $this->partiallyPaidReservation($guest);
            $payment = ReservationPayment::create([
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_BALANCE,
                'provider' => 'paymongo',
                'provider_reference' => $reservation->booking_reference,
                'checkout_session_id' => "cs_{$eventStatus}",
                'amount' => 700000,
                'currency' => 'PHP',
                'status' => ReservationPayment::STATUS_PENDING,
            ]);

            Http::fake([
                "api.paymongo.com/v1/checkout_sessions/{$payment->checkout_session_id}" => Http::response([
                    'data' => [
                        'id' => $payment->checkout_session_id,
                        'attributes' => [
                            'status' => $eventStatus,
                            'reference_number' => $reservation->booking_reference,
                            'metadata' => ['payment_purpose' => ReservationPayment::PURPOSE_BALANCE],
                            'payments' => [['id' => 'pay_'.$eventStatus, 'attributes' => [
                                'status' => $eventStatus,
                                'amount' => 700000,
                                'currency' => 'PHP',
                            ]]],
                        ],
                    ],
                ], 200),
            ]);

            $this->actingAs($guest)
                ->getJson("/api/payments/reservations/{$reservation->id}/status")
                ->assertOk()
                ->assertJsonPath('data.payment_state', 'partially_paid')
                ->assertJsonPath('data.reservation.balance_due', '7000.00');

            $this->assertDatabaseHas('reservation_payments', [
                'id' => $payment->id,
                'status' => $status,
            ]);
        }
    }

    public function test_fully_paid_and_unpaid_reservations_cannot_use_balance_checkout(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $unpaid = $this->createReservation($guest);
        $fullyPaid = $this->partiallyPaidReservation($guest);
        $fullyPaid->payments()->create([
            'purpose' => ReservationPayment::PURPOSE_BALANCE,
            'provider' => 'paymongo',
            'amount' => 700000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
        ]);

        foreach ([$unpaid, $fullyPaid] as $reservation) {
            $this->actingAs($guest)
                ->postJson('/api/payments/paymongo/checkout', [
                    'reservation_id' => $reservation->id,
                    'purpose' => ReservationPayment::PURPOSE_BALANCE,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['reservation_id']);
        }
    }

    public function test_other_customer_cannot_create_or_view_balance_payment_history(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_GUEST]);
        $otherGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->partiallyPaidReservation($owner);

        $this->actingAs($otherGuest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_BALANCE,
            ])
            ->assertNotFound();

        $this->actingAs($otherGuest)
            ->getJson('/api/payments')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_balance_checkout_is_reused_and_payment_history_is_sanitized(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->partiallyPaidReservation($guest);
        $this->fakeCheckout($reservation, 'cs_balance_reused', 700000);

        $first = $this->actingAs($guest)->postJson('/api/payments/paymongo/checkout', [
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_BALANCE,
        ])->assertOk();

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_BALANCE,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $reservation->payments()->create([
            'purpose' => ReservationPayment::PURPOSE_BALANCE,
            'provider' => 'paymongo',
            'amount' => 700000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'payment_method' => 'card',
            'paid_at' => Carbon::now(),
            'payload' => ['secret' => 'must-not-leak'],
        ]);

        $response = $this->actingAs($guest)->getJson("/api/reservations/{$reservation->id}");
        $response->assertOk()
            ->assertJsonPath('data.payment_history.0.purpose', ReservationPayment::PURPOSE_BALANCE)
            ->assertJsonMissingPath('data.payment_history.0.payload')
            ->assertJsonMissingPath('data.payment_history.0.checkout_session_id');

        $this->actingAs($guest)
            ->getJson('/api/payments')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_mismatched_balance_amount_and_currency_do_not_confirm(): void
    {
        config()->set('services.paymongo.secret_key', 'sk_test_secret');
        foreach ([['amount' => 699999, 'currency' => 'PHP'], ['amount' => 700000, 'currency' => 'USD']] as $case) {
            $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
            $reservation = $this->partiallyPaidReservation($guest);
            $payment = ReservationPayment::create([
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_BALANCE,
                'provider' => 'paymongo',
                'provider_reference' => $reservation->booking_reference,
                'checkout_session_id' => 'cs_bad_'.strtolower($case['currency']),
                'amount' => 700000,
                'currency' => 'PHP',
                'status' => ReservationPayment::STATUS_PENDING,
            ]);

            $this->fakeRetrievedCheckout($reservation, $payment, $case['amount'], $case['currency']);

            $this->actingAs($guest)
                ->getJson("/api/payments/reservations/{$reservation->id}/status")
                ->assertOk()
                ->assertJsonPath('data.source', 'mismatch')
                ->assertJsonPath('data.payment_state', 'partially_paid');
        }
    }

    private function fakeCheckout(Reservation $reservation, string $sessionId, int $amount): void
    {
        config()->set('services.paymongo.secret_key', 'sk_test_secret');

        Http::fake([
            'api.paymongo.com/v2/checkout_sessions' => function ($request) use ($reservation, $sessionId, $amount) {
                $this->assertSame($amount, $request->data()['data']['attributes']['line_items'][0]['amount']);

                return Http::response(['data' => ['id' => $sessionId, 'attributes' => [
                    'checkout_url' => "https://paymongo.test/checkout/{$sessionId}",
                    'reference_number' => $reservation->booking_reference,
                ]]], 200);
            },
        ]);
    }

    private function fakeRetrievedCheckout(Reservation $reservation, ReservationPayment $payment, int $amount, string $currency): void
    {
        Http::fake([
            "api.paymongo.com/v1/checkout_sessions/{$payment->checkout_session_id}" => Http::response(['data' => [
                'id' => $payment->checkout_session_id,
                'attributes' => [
                    'status' => 'paid',
                    'reference_number' => $reservation->booking_reference,
                    'metadata' => ['payment_purpose' => ReservationPayment::PURPOSE_BALANCE],
                    'payments' => [['id' => 'pay_'.$payment->id, 'attributes' => [
                        'amount' => $amount,
                        'currency' => $currency,
                        'payment_method_type' => 'card',
                        'status' => 'paid',
                    ]]],
                ],
            ]], 200),
        ]);
    }

    private function partiallyPaidReservation(User $user): Reservation
    {
        $reservation = $this->createReservation($user);
        $reservation->update(['status' => Reservation::STATUS_CONFIRMED]);
        $reservation->payments()->create([
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            'provider' => 'paymongo',
            'provider_reference' => $reservation->booking_reference,
            'amount' => 300000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'paid_at' => Carbon::now(),
        ]);

        return $reservation->fresh();
    }

    private function createReservation(User $user): Reservation
    {
        $accommodation = Accommodation::create([
            'name' => 'Phase 3 Test Room',
            'slug' => 'phase-3-test-'.uniqid(),
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 5000,
            'description' => 'Phase 3 test accommodation.',
            'status' => Accommodation::STATUS_AVAILABLE,
        ]);

        return Reservation::create([
            'user_id' => $user->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-03',
            'guests' => 2,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'total_amount' => '10000.00',
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-PHASE3-'.strtoupper(substr(uniqid(), -7)),
        ]);
    }
}
