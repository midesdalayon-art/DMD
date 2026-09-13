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

class PayMongoPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_customer_can_create_checkout_for_own_reservation(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        config()->set('app.frontend_url', 'http://localhost:5173');
        config()->set('services.paymongo.secret_key', 'sk_test_secret');
        config()->set('services.paymongo.payment_method_types', 'card');

        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest, 2500);

        Http::fake([
            'api.paymongo.com/v2/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_test_123',
                    'attributes' => [
                        'checkout_url' => 'https://paymongo.test/checkout/cs_test_123',
                        'reference_number' => $reservation->booking_reference,
                        'payment_method_types' => ['card'],
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_FULL,
            ])
            ->assertOk()
            ->assertJsonPath('data.checkout_session_id', 'cs_test_123')
            ->assertJsonPath('data.checkout_url', 'https://paymongo.test/checkout/cs_test_123')
            ->assertJsonPath('data.status', ReservationPayment::STATUS_PENDING);

        $this->assertDatabaseHas('reservation_payments', [
            'reservation_id' => $reservation->id,
            'provider' => 'paymongo',
            'checkout_session_id' => 'cs_test_123',
            'status' => ReservationPayment::STATUS_PENDING,
            'amount' => 500000,
            'currency' => 'PHP',
        ]);
    }

    public function test_customer_cannot_pay_another_customers_reservation(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_GUEST]);
        $otherGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($owner);

        $this->actingAs($otherGuest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_FULL,
            ])
            ->assertNotFound();
    }

    public function test_already_paid_reservation_cannot_be_paid_again(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest);
        $this->createPayment($reservation, ReservationPayment::STATUS_PAID);

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_FULL,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reservation_id']);
    }

    public function test_checkout_amount_is_calculated_from_backend_total(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        config()->set('app.frontend_url', 'http://localhost:5173');
        config()->set('services.paymongo.secret_key', 'sk_test_secret');

        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest, 3333);

        Http::fake([
            'api.paymongo.com/v2/checkout_sessions' => function ($request) use ($reservation) {
                $payload = $request->data();
                $this->assertSame(666600, $payload['data']['attributes']['line_items'][0]['amount']);
                $this->assertSame('PHP', $payload['data']['attributes']['line_items'][0]['currency']);
                $this->assertSame($reservation->booking_reference, $payload['data']['attributes']['reference_number']);

                return Http::response([
                    'data' => [
                        'id' => 'cs_test_amount',
                        'attributes' => [
                            'checkout_url' => 'https://paymongo.test/checkout/cs_test_amount',
                            'reference_number' => $reservation->booking_reference,
                            'payment_method_types' => ['card'],
                        ],
                    ],
                ], 200);
            },
        ]);

        $this->actingAs($guest)
            ->postJson('/api/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_FULL,
            ])
            ->assertOk();
    }

    public function test_webhook_marks_payment_paid_and_confirms_reservation(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        config()->set('services.paymongo.webhook_secret', 'whsec_test_secret');

        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest);
        $payment = $this->createPayment($reservation, ReservationPayment::STATUS_PENDING, [
            'checkout_session_id' => 'cs_test_webhook',
        ]);

        $payload = [
            'id' => 'evt_test_paid',
            'type' => 'event',
            'data' => [
                'type' => 'checkout_session.payment.paid',
                'data' => [
                    'id' => 'cs_test_webhook',
                    'attributes' => [
                        'reference_number' => $reservation->booking_reference,
                        'payments' => [
                            [
                                'id' => 'pay_test_123',
                                'attributes' => [
                                    'amount' => 500000,
                                    'currency' => 'PHP',
                                    'payment_method_type' => 'card',
                                    'paid_at' => '2026-08-25T10:00:00+08:00',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test_secret');

        $this->postJson('/api/webhooks/paymongo', $payload, [
            'Paymongo-Signature' => "t={$timestamp},te={$signature}",
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Webhook processed.');

        $this->assertDatabaseHas('reservation_payments', [
            'id' => $payment->id,
            'status' => ReservationPayment::STATUS_PAID,
            'payment_id' => 'pay_test_123',
        ]);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_CONFIRMED,
        ]);
    }

    public function test_webhook_accepts_paymongo_event_envelope_and_uses_payload_mode_for_signature(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        config()->set('services.paymongo.webhook_secret', 'whsec_test_secret');

        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest);
        $payment = $this->createPayment($reservation, ReservationPayment::STATUS_PENDING, [
            'checkout_session_id' => 'cs_test_current_envelope',
        ]);

        $payload = [
            'data' => [
                'id' => 'evt_test_current_envelope',
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'livemode' => true,
                    'data' => [
                        'id' => 'cs_test_current_envelope',
                        'type' => 'checkout_session',
                        'attributes' => [
                            'reference_number' => $reservation->booking_reference,
                            'payments' => [[
                                'id' => 'pay_test_current_envelope',
                                'attributes' => [
                                    'amount' => 500000,
                                    'currency' => 'PHP',
                                    'payment_method_type' => 'card',
                                    'paid_at' => '2026-08-25T10:00:00+08:00',
                                    'status' => 'paid',
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test_secret');

        $this->postJson('/api/webhooks/paymongo', $payload, [
            'Paymongo-Signature' => "t={$timestamp},li={$signature}",
        ])->assertOk();

        $this->assertDatabaseHas('reservation_payments', [
            'id' => $payment->id,
            'status' => ReservationPayment::STATUS_PAID,
            'payment_id' => 'pay_test_current_envelope',
        ]);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('paymongo_webhook_events', [
            'event_id' => 'evt_test_current_envelope',
        ]);
    }

    public function test_duplicate_webhook_is_idempotent(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        config()->set('services.paymongo.webhook_secret', 'whsec_test_secret');

        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest);

        $payload = [
            'id' => 'evt_test_duplicate',
            'type' => 'event',
            'data' => [
                'type' => 'checkout_session.payment.paid',
                'data' => [
                    'id' => 'cs_test_duplicate',
                    'attributes' => [
                        'reference_number' => $reservation->booking_reference,
                        'payments' => [
                            [
                                'id' => 'pay_test_duplicate',
                                'attributes' => [
                                    'amount' => 500000,
                                    'currency' => 'PHP',
                                    'payment_method_type' => 'card',
                                    'paid_at' => '2026-08-25T10:00:00+08:00',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test_secret');
        $headers = [
            'Paymongo-Signature' => "t={$timestamp},te={$signature}",
        ];

        $this->postJson('/api/webhooks/paymongo', $payload, $headers)->assertOk();
        $this->postJson('/api/webhooks/paymongo', $payload, $headers)->assertOk();

        $this->assertDatabaseCount('paymongo_webhook_events', 1);
        $this->assertDatabaseCount('reservation_payments', 1);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_CONFIRMED,
        ]);
    }

    public function test_stale_signed_webhook_is_rejected(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        config()->set('services.paymongo.webhook_secret', 'whsec_test_secret');
        config()->set('services.paymongo.webhook_signature_tolerance', 300);

        $payload = ['id' => 'evt_test_stale', 'data' => ['type' => 'checkout_session.payment.failed']];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->subSeconds(301)->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test_secret');

        $this->postJson('/api/webhooks/paymongo', $payload, [
            'Paymongo-Signature' => "t={$timestamp},te={$signature}",
        ])->assertUnauthorized();
    }

    public function test_webhook_rate_limit_is_applied_separately_from_signature_validation(): void
    {
        config()->set('services.paymongo.webhook_secret', 'whsec_test_secret');
        config()->set('services.paymongo.webhook_rate_limit', 1);

        $payload = ['id' => 'evt_test_rate_limit', 'data' => ['type' => 'checkout_session.payment.failed']];
        $headers = ['Paymongo-Signature' => 't=not-a-timestamp,te=invalid'];

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->postJson('/api/webhooks/paymongo', $payload, $headers)
            ->assertUnauthorized();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->postJson('/api/webhooks/paymongo', $payload, $headers)
            ->assertStatus(429);
    }

    public function test_failed_payment_does_not_confirm_reservation(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        config()->set('services.paymongo.webhook_secret', 'whsec_test_secret');

        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest);

        $payload = [
            'id' => 'evt_test_failed',
            'type' => 'event',
            'data' => [
                'type' => 'checkout_session.payment.failed',
                'data' => [
                    'id' => 'cs_test_failed',
                    'attributes' => [
                        'reference_number' => $reservation->booking_reference,
                        'payments' => [],
                    ],
                ],
            ],
        ];

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test_secret');

        $this->postJson('/api/webhooks/paymongo', $payload, [
            'Paymongo-Signature' => "t={$timestamp},te={$signature}",
        ])->assertOk();

        $this->assertDatabaseHas('reservation_payments', [
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_FULL,
            'status' => ReservationPayment::STATUS_FAILED,
        ]);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_PENDING,
        ]);
    }

    public function test_payment_status_endpoint_confirms_paid_checkout_session_server_side(): void
    {
        Carbon::setTestNow('2026-08-25 10:00:00');
        config()->set('services.paymongo.secret_key', 'sk_test_secret');

        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest);
        $payment = $this->createPayment($reservation, ReservationPayment::STATUS_PENDING, [
            'checkout_session_id' => 'cs_test_status',
            'paid_at' => null,
        ]);

        Http::fake([
            'api.paymongo.com/v1/checkout_sessions/cs_test_status' => Http::response([
                'data' => [
                    'id' => 'cs_test_status',
                    'attributes' => [
                        'status' => 'paid',
                        'reference_number' => $reservation->booking_reference,
                        'payments' => [
                            [
                                'id' => 'pay_test_status',
                                'attributes' => [
                                    'amount' => 500000,
                                    'currency' => 'PHP',
                                    'payment_method_type' => 'card',
                                    'paid_at' => '2026-08-25T10:00:00+08:00',
                                    'status' => 'paid',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($guest)
            ->getJson("/api/payments/reservations/{$reservation->id}/status")
            ->assertOk()
            ->assertJsonPath('data.payment_status', ReservationPayment::STATUS_PAID)
            ->assertJsonPath('data.reservation_status', Reservation::STATUS_CONFIRMED)
            ->assertJsonPath('data.source', 'paymongo')
            ->assertJsonMissingPath('data.checkout_session_id')
            ->assertJsonMissingPath('data.payment_id')
            ->assertJsonMissingPath('data.provider_reference');

        $this->assertDatabaseHas('reservation_payments', [
            'id' => $payment->id,
            'status' => ReservationPayment::STATUS_PAID,
            'payment_id' => 'pay_test_status',
        ]);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_CONFIRMED,
        ]);
    }

    public function test_payment_status_rejects_mismatched_paymongo_amount(): void
    {
        config()->set('services.paymongo.secret_key', 'sk_test_secret');

        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest);
        $payment = $this->createPayment($reservation, ReservationPayment::STATUS_PENDING, [
            'checkout_session_id' => 'cs_test_mismatch',
        ]);

        Http::fake([
            'api.paymongo.com/v1/checkout_sessions/cs_test_mismatch' => Http::response([
                'data' => [
                    'id' => 'cs_test_mismatch',
                    'attributes' => [
                        'status' => 'paid',
                        'reference_number' => $reservation->booking_reference,
                        'payments' => [[
                            'id' => 'pay_test_mismatch',
                            'attributes' => [
                                'amount' => ((int) $payment->amount) - 1,
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
            ->assertJsonPath('data.source', 'mismatch')
            ->assertJsonPath('data.reservation_status', Reservation::STATUS_PENDING);

        $this->assertDatabaseHas('reservation_payments', [
            'id' => $payment->id,
            'status' => ReservationPayment::STATUS_PENDING,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createReservation(User $user, int $pricePerNight = 2500, array $overrides = []): Reservation
    {
        $accommodation = Accommodation::create([
            'name' => 'Test Room',
            'slug' => 'test-room-'.Reservation::count(),
            'type' => 'room',
            'capacity' => 4,
            'price_per_night' => $pricePerNight,
            'description' => 'Test accommodation.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ]);

        return Reservation::create(array_merge([
            'user_id' => $user->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-26',
            'check_out' => '2026-08-28',
            'guests' => 2,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'total_amount' => $pricePerNight * 2,
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-20260825-'.strtoupper(substr(md5((string) Reservation::count()), 0, 6)),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createPayment(Reservation $reservation, string $status, array $overrides = []): ReservationPayment
    {
        return ReservationPayment::create(array_merge([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_FULL,
            'provider' => 'paymongo',
            'provider_reference' => $reservation->booking_reference,
            'checkout_session_id' => 'cs_'.ReservationPayment::count(),
            'checkout_url' => 'https://paymongo.test/checkout/'.ReservationPayment::count(),
            'payment_id' => null,
            'amount' => (int) round(((float) $reservation->total_amount) * 100),
            'currency' => 'PHP',
            'status' => $status,
            'payment_method' => 'card',
            'paid_at' => $status === ReservationPayment::STATUS_PAID ? now() : null,
            'raw_reference' => $reservation->booking_reference,
            'payload' => null,
        ], $overrides));
    }
}
