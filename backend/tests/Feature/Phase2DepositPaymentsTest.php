<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Phase2DepositPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_checkout_uses_exactly_thirty_percent(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest);
        $this->fakeCheckout($reservation, 'cs_deposit');

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            ])
            ->assertOk()
            ->assertJsonPath('data.purpose', ReservationPayment::PURPOSE_DEPOSIT)
            ->assertJsonPath('data.amount', 300000)
            ->assertJsonPath('data.status', ReservationPayment::STATUS_PENDING);

        $this->assertDatabaseHas('reservation_payments', [
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            'amount' => 300000,
            'status' => ReservationPayment::STATUS_PENDING,
        ]);
    }

    public function test_full_checkout_uses_the_complete_reservation_total(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest);
        $this->fakeCheckout($reservation, 'cs_full', 1000000);

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_FULL,
            ])
            ->assertOk()
            ->assertJsonPath('data.purpose', ReservationPayment::PURPOSE_FULL)
            ->assertJsonPath('data.amount', 1000000);
    }

    public function test_customer_cannot_submit_an_arbitrary_amount_or_purpose(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest);

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
                'amount' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_BALANCE,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reservation_id']);
    }

    public function test_verified_deposit_confirms_reservation_but_leaves_partial_balance(): void
    {
        config()->set('services.paymongo.secret_key', 'sk_test_secret');

        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest);
        $payment = ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            'provider' => 'paymongo',
            'provider_reference' => $reservation->booking_reference,
            'checkout_session_id' => 'cs_deposit_paid',
            'amount' => 300000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PENDING,
        ]);

        Http::fake([
            'api.paymongo.com/v1/checkout_sessions/cs_deposit_paid' => Http::response([
                'data' => [
                    'id' => $payment->checkout_session_id,
                    'attributes' => [
                        'status' => 'paid',
                        'reference_number' => $reservation->booking_reference,
                        'metadata' => ['payment_purpose' => ReservationPayment::PURPOSE_DEPOSIT],
                        'payments' => [[
                            'id' => 'pay_deposit_paid',
                            'attributes' => [
                                'amount' => 300000,
                                'currency' => 'PHP',
                                'payment_method_type' => 'card',
                                'status' => 'paid',
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
            ->assertJsonPath('data.payment_state', 'partially_paid')
            ->assertJsonPath('data.reservation.total_paid', '3000.00')
            ->assertJsonPath('data.reservation.balance_due', '7000.00');

        $this->assertDatabaseHas('reservation_payments', [
            'id' => $payment->id,
            'status' => ReservationPayment::STATUS_PAID,
        ]);
    }

    public function test_underpayment_and_wrong_currency_never_confirm_reservation(): void
    {
        config()->set('services.paymongo.secret_key', 'sk_test_secret');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        foreach ([['amount' => 299999, 'currency' => 'PHP', 'session' => 'cs_under'], ['amount' => 300000, 'currency' => 'USD', 'session' => 'cs_currency']] as $case) {
            $reservation = $this->createReservation($guest);
            $payment = ReservationPayment::create([
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
                'provider' => 'paymongo',
                'provider_reference' => $reservation->booking_reference,
                'checkout_session_id' => $case['session'],
                'amount' => 300000,
                'currency' => 'PHP',
                'status' => ReservationPayment::STATUS_PENDING,
            ]);

            Http::fake([
                "api.paymongo.com/v1/checkout_sessions/{$case['session']}" => Http::response([
                    'data' => [
                        'id' => $payment->checkout_session_id,
                        'attributes' => [
                            'status' => 'paid',
                            'reference_number' => $reservation->booking_reference,
                            'metadata' => ['payment_purpose' => ReservationPayment::PURPOSE_DEPOSIT],
                            'payments' => [[
                                'id' => 'pay_bad_'.$case['session'],
                                'attributes' => [
                                    'amount' => $case['amount'],
                                    'currency' => $case['currency'],
                                    'status' => 'paid',
                                ],
                            ]],
                        ],
                    ],
                ], 200),
            ]);

            $this->actingAs($guest)
                ->getJson("/api/payments/reservations/{$reservation->id}/status")
                ->assertOk()
                ->assertJsonPath('data.source', 'mismatch')
                ->assertJsonPath('data.reservation_status', Reservation::STATUS_PENDING);
        }
    }

    public function test_pending_initial_checkout_is_reused_and_cannot_switch_purpose(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest);
        $this->fakeCheckout($reservation, 'cs_reused');

        $first = $this->actingAs($guest)->postJson('/api/payments/paymongo/checkout', [
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
        ])->assertOk();

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('message', 'Checkout session already prepared.');

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_FULL,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['purpose']);

        $this->assertDatabaseCount('reservation_payments', 1);
    }

    public function test_other_customer_cannot_create_payment_checkout(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_GUEST]);
        $otherGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($owner);

        $this->actingAs($otherGuest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            ])
            ->assertNotFound();
    }

    private function fakeCheckout(Reservation $reservation, string $sessionId, int $amount = 300000): void
    {
        config()->set('services.paymongo.secret_key', 'sk_test_secret');

        Http::fake([
            'api.paymongo.com/v2/checkout_sessions' => function ($request) use ($reservation, $sessionId, $amount) {
                $this->assertSame($amount, $request->data()['data']['attributes']['line_items'][0]['amount']);

                return Http::response([
                    'data' => [
                        'id' => $sessionId,
                        'attributes' => [
                            'checkout_url' => "https://paymongo.test/checkout/{$sessionId}",
                            'reference_number' => $reservation->booking_reference,
                        ],
                    ],
                ], 200);
            },
        ]);
    }

    private function createReservation(User $user): Reservation
    {
        $accommodation = Accommodation::create([
            'name' => 'Phase 2 Test Room',
            'slug' => 'phase-2-test-'.uniqid(),
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 5000,
            'description' => 'Phase 2 test accommodation.',
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
            'booking_reference' => 'DMD-PHASE2-'.strtoupper(substr(uniqid(), -7)),
        ]);
    }
}
