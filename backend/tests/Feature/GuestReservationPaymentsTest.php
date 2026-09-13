<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Services\GuestBookingConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuestReservationPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.brevo.api_key', 'test-brevo-key');
    }

    public function test_guest_can_create_deposit_checkout_without_an_account_or_client_amount(): void
    {
        [$reservation, $token] = $this->createGuestReservation();
        config()->set('services.paymongo.secret_key', 'sk_test_secret');

        Http::fake([
            'api.paymongo.com/v2/checkout_sessions' => function ($request) use ($reservation) {
                $this->assertSame(150000, $request->data()['data']['attributes']['line_items'][0]['amount']);
                return Http::response(['data' => [
                    'id' => 'guest-cs-deposit',
                    'attributes' => [
                        'checkout_url' => 'https://paymongo.test/guest-cs-deposit',
                        'reference_number' => $reservation->booking_reference,
                    ],
                ]]);
            },
        ]);

        $this->withHeader('X-Guest-Checkout-Token', $token)
            ->postJson('/api/guest/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
                'amount' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $response = $this->withHeader('X-Guest-Checkout-Token', $token)
            ->postJson('/api/guest/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            ])
            ->assertOk()
            ->assertJsonPath('data.purpose', ReservationPayment::PURPOSE_DEPOSIT)
            ->assertJsonPath('data.amount', 150000);

        $this->assertSame('guest-cs-deposit', $response->json('data.checkout_session_id'));
        $this->assertDatabaseHas('reservation_payments', [
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            'amount' => 150000,
            'status' => ReservationPayment::STATUS_PENDING,
        ]);
    }

    public function test_guest_can_create_full_checkout_and_pending_checkout_is_reused(): void
    {
        [$reservation, $token] = $this->createGuestReservation();
        config()->set('services.paymongo.secret_key', 'sk_test_secret');

        Http::fake([
            'api.paymongo.com/v2/checkout_sessions' => Http::response(['data' => [
                'id' => 'guest-cs-full',
                'attributes' => [
                    'checkout_url' => 'https://paymongo.test/guest-cs-full',
                    'reference_number' => $reservation->booking_reference,
                ],
            ]]),
        ]);

        $this->withHeader('X-Guest-Checkout-Token', $token)
            ->postJson('/api/guest/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_FULL,
            ])
            ->assertOk()
            ->assertJsonPath('data.amount', 500000);

        $this->withHeader('X-Guest-Checkout-Token', $token)
            ->postJson('/api/guest/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_FULL,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Checkout session already prepared.');

        $this->assertDatabaseCount('reservation_payments', 1);
    }

    public function test_guest_checkout_token_is_required_and_scoped_to_one_reservation(): void
    {
        [$reservation, $token] = $this->createGuestReservation();
        [, $otherToken] = $this->createGuestReservation();

        $this->postJson('/api/guest/payments/paymongo/checkout', [
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_FULL,
        ])->assertUnauthorized();

        $this->withHeader('X-Guest-Checkout-Token', $otherToken)
            ->postJson('/api/guest/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_FULL,
            ])->assertUnauthorized();

        $this->assertDatabaseCount('reservation_payments', 0);
    }

    public function test_guest_can_abandon_own_unpaid_pending_reservation_and_release_its_hold(): void
    {
        [$reservation, $token] = $this->createGuestReservation();
        $payment = ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_FULL,
            'provider' => 'paymongo',
            'provider_reference' => $reservation->booking_reference,
            'checkout_session_id' => 'guest-abandon-session',
            'amount' => 500000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PENDING,
        ]);

        $this->withHeader('X-Guest-Checkout-Token', $token)
            ->postJson("/api/guest/reservations/{$reservation->id}/abandon")
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CANCELLED);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_CANCELLED,
            'guest_checkout_token_hash' => null,
        ]);
        $this->assertDatabaseHas('reservation_payments', [
            'id' => $payment->id,
            'status' => ReservationPayment::STATUS_CANCELLED,
        ]);
    }

    public function test_guest_cannot_abandon_another_guests_reservation(): void
    {
        [$reservation] = $this->createGuestReservation();
        [, $otherToken] = $this->createGuestReservation();

        $this->withHeader('X-Guest-Checkout-Token', $otherToken)
            ->postJson("/api/guest/reservations/{$reservation->id}/abandon")
            ->assertUnauthorized();

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_PENDING,
        ]);
    }

    public function test_guest_cannot_abandon_a_paid_or_confirmed_reservation(): void
    {
        [$reservation, $token] = $this->createGuestReservation();
        ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_FULL,
            'provider' => 'paymongo',
            'provider_reference' => $reservation->booking_reference,
            'checkout_session_id' => 'guest-paid-session',
            'amount' => 500000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);
        $reservation->forceFill(['status' => Reservation::STATUS_CONFIRMED])->save();

        $this->withHeader('X-Guest-Checkout-Token', $token)
            ->postJson("/api/guest/reservations/{$reservation->id}/abandon")
            ->assertUnprocessable();

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_CONFIRMED,
        ]);
    }

    public function test_verified_guest_deposit_confirms_reservation_and_is_available_without_account(): void
    {
        [$reservation, $token] = $this->createGuestReservation();
        $payment = ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            'provider' => 'paymongo',
            'provider_reference' => $reservation->booking_reference,
            'checkout_session_id' => 'guest-cs-paid',
            'amount' => 150000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PENDING,
        ]);

        config()->set('services.paymongo.secret_key', 'sk_test_secret');
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions/guest-cs-paid' => Http::response(['data' => [
                'id' => $payment->checkout_session_id,
                'attributes' => [
                    'status' => 'paid',
                    'reference_number' => $reservation->booking_reference,
                    'metadata' => ['payment_purpose' => ReservationPayment::PURPOSE_DEPOSIT],
                    'payments' => [[
                        'id' => 'guest-pay-deposit',
                        'attributes' => [
                            'amount' => 150000,
                            'currency' => 'PHP',
                            'payment_method_type' => 'card',
                            'status' => 'paid',
                        ],
                    ]],
                ],
            ]]),
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-deposit-message'], 201),
        ]);

        $status = $this->withHeader('X-Guest-Checkout-Token', $token)
            ->getJson("/api/guest/payments/reservations/{$reservation->id}/status")
            ->assertOk()
            ->assertJsonPath('data.reservation_status', Reservation::STATUS_CONFIRMED)
            ->assertJsonPath('data.payment_state', 'partially_paid')
            ->assertJsonPath('data.reservation.total_paid', '1500.00')
            ->assertJsonPath('data.reservation.balance_due', '3500.00')
            ->assertJsonPath('guest_confirmation_email_status', 'sent');

        $accessToken = $status->json('guest_access_token');
        $this->assertIsString($accessToken);
        $this->assertSame(64, strlen($accessToken));
        $this->assertNotSame($accessToken, $reservation->fresh()->guest_access_token_hash);
        $this->assertNotNull($reservation->fresh()->qr_token_hash);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'guest_confirmation_email_status' => 'sent',
        ]);
        Http::assertSent(function (Request $request) use ($reservation, $accessToken) {
            $payload = $request->data();
            $qrAttachment = $payload['attachment'][0] ?? [];
            $qrContent = base64_decode((string) ($qrAttachment['content'] ?? ''), true);

            return str_contains($request->url(), 'api.brevo.com/v3/smtp/email')
                && $payload['to'][0]['email'] === 'juan@example.com'
                && str_contains($payload['htmlContent'], $reservation->booking_reference)
                && str_contains($payload['htmlContent'], '/guest/booking/'.$accessToken)
                && str_contains($payload['htmlContent'], 'data:image/png;base64,')
                && $qrAttachment['name'] === 'booking-qr.png'
                && is_string($qrContent)
                && strlen($qrContent) > 0
                && isset($payload['headers']['idempotencyKey']);
        });

        $this->withHeader('X-Guest-Access-Token', $accessToken)
            ->getJson('/api/guest/booking')
            ->assertOk()
            ->assertJsonPath('data.id', $reservation->id)
            ->assertJsonPath('data.payment_state', 'partially_paid')
            ->assertJsonMissingPath('data.guest_access_token_hash');
    }

    public function test_guest_checkout_credentials_are_accepted_only_from_the_dedicated_header(): void
    {
        [$reservation, $token] = $this->createGuestReservation();
        $payload = [
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_FULL,
        ];

        $this->postJson('/api/guest/payments/paymongo/checkout', $payload + [
            'guest_checkout_token' => $token,
        ])->assertUnauthorized();

        $this->getJson('/api/guest/payments/reservations/'.$reservation->id.'/status?guest_checkout_token='.$token)
            ->assertUnauthorized();
    }

    public function test_guest_access_credentials_are_accepted_only_from_the_dedicated_header(): void
    {
        [$reservation] = $this->createGuestReservation();
        $token = str_repeat('a', 64);
        $reservation->forceFill([
            'status' => Reservation::STATUS_CONFIRMED,
            'guest_access_token_hash' => hash('sha256', $token),
            'guest_access_token_issued_at' => now(),
        ])->save();

        $this->getJson('/api/guest/booking?guest_access_token='.$token)
            ->assertUnauthorized();

        $this->postJson('/api/guest/booking/payments/paymongo/checkout', [
            'purpose' => ReservationPayment::PURPOSE_BALANCE,
            'guest_access_token' => $token,
        ])->assertUnauthorized();
    }

    public function test_permanent_guest_token_can_start_balance_checkout_without_reservation_id_or_amount(): void
    {
        [$reservation] = $this->createGuestReservation();
        $accessToken = str_repeat('a', 64);
        $reservation->forceFill([
            'status' => Reservation::STATUS_CONFIRMED,
            'guest_access_token_hash' => hash('sha256', $accessToken),
            'guest_access_token_issued_at' => now(),
        ])->save();
        ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            'provider' => 'paymongo',
            'amount' => 150000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->getJson('/api/guest/booking')->assertUnauthorized();
        $this->withHeader('X-Guest-Access-Token', str_repeat('b', 64))
            ->getJson('/api/guest/booking')
            ->assertUnauthorized();

        config()->set('services.paymongo.secret_key', 'sk_test_secret');
        Http::fake([
            'api.paymongo.com/v2/checkout_sessions' => function ($request) use ($reservation) {
                $this->assertSame(350000, $request->data()['data']['attributes']['line_items'][0]['amount']);
                return Http::response(['data' => [
                    'id' => 'guest-cs-balance',
                    'attributes' => [
                        'checkout_url' => 'https://paymongo.test/guest-cs-balance',
                        'reference_number' => $reservation->booking_reference,
                    ],
                ]]);
            },
        ]);

        $this->withHeader('X-Guest-Access-Token', $accessToken)
            ->postJson('/api/guest/booking/payments/paymongo/checkout', [
                'purpose' => ReservationPayment::PURPOSE_BALANCE,
                'amount' => 1,
            ])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->withHeader('X-Guest-Access-Token', $accessToken)
            ->postJson('/api/guest/booking/payments/paymongo/checkout', [
                'purpose' => ReservationPayment::PURPOSE_BALANCE,
            ])
            ->assertOk()
            ->assertJsonPath('data.purpose', ReservationPayment::PURPOSE_BALANCE)
            ->assertJsonPath('data.amount', 350000);
    }

    public function test_verified_guest_full_payment_confirms_reservation_as_fully_paid(): void
    {
        [$reservation, $token] = $this->createGuestReservation();
        $payment = ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_FULL,
            'provider' => 'paymongo',
            'provider_reference' => $reservation->booking_reference,
            'checkout_session_id' => 'guest-cs-full-paid',
            'amount' => 500000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PENDING,
        ]);

        config()->set('services.paymongo.secret_key', 'sk_test_secret');
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions/guest-cs-full-paid' => Http::response(['data' => [
                'id' => $payment->checkout_session_id,
                'attributes' => [
                    'status' => 'paid',
                    'reference_number' => $reservation->booking_reference,
                    'metadata' => ['payment_purpose' => ReservationPayment::PURPOSE_FULL],
                    'payments' => [[
                        'id' => 'guest-pay-full',
                        'attributes' => [
                            'amount' => 500000,
                            'currency' => 'PHP',
                            'payment_method_type' => 'card',
                            'status' => 'paid',
                        ],
                    ]],
                ],
            ]]),
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-full-message'], 201),
        ]);

        $this->withHeader('X-Guest-Checkout-Token', $token)
            ->getJson("/api/guest/payments/reservations/{$reservation->id}/status")
            ->assertOk()
            ->assertJsonPath('data.reservation_status', Reservation::STATUS_CONFIRMED)
            ->assertJsonPath('data.payment_state', 'fully_paid')
            ->assertJsonPath('data.reservation.total_paid', '5000.00')
            ->assertJsonPath('data.reservation.balance_due', '0.00');

        $this->assertNotNull($reservation->fresh()->qr_token_hash);

        $this->withHeader('X-Guest-Checkout-Token', $token)
            ->getJson("/api/guest/payments/reservations/{$reservation->id}/status")
            ->assertOk();

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'api.brevo.com/v3/smtp/email')
                && $request->data()['attachment'][0]['name'] === 'booking-qr.png';
        });
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'guest_confirmation_email_status' => 'sent',
        ]);
    }

    public function test_paid_guest_with_existing_access_token_still_sends_pending_confirmation(): void
    {
        [$reservation, $checkoutToken] = $this->createGuestReservation();
        $existingAccessToken = str_repeat('a', 64);

        $reservation->forceFill([
            'status' => Reservation::STATUS_CONFIRMED,
            'guest_access_token_hash' => hash('sha256', $existingAccessToken),
            'guest_access_token_issued_at' => now(),
            'guest_confirmation_email_status' => null,
        ])->save();

        ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_FULL,
            'provider' => 'paymongo',
            'amount' => 500000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-existing-token-message'], 201),
        ]);

        $response = $this->withHeader('X-Guest-Checkout-Token', $checkoutToken)
            ->getJson("/api/guest/payments/reservations/{$reservation->id}/status")
            ->assertOk()
            ->assertJsonPath('guest_confirmation_email_status', 'sent');

        $newAccessToken = $response->json('guest_access_token');
        $this->assertIsString($newAccessToken);
        $this->assertSame(64, strlen($newAccessToken));
        $this->assertNotSame($existingAccessToken, $newAccessToken);
        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'api.brevo.com/v3/smtp/email')
                && $request->data()['attachment'][0]['name'] === 'booking-qr.png';
        });
    }

    public function test_paid_guest_confirmation_resend_is_idempotent(): void
    {
        [$reservation] = $this->createGuestReservation();

        $reservation->forceFill(['status' => Reservation::STATUS_CONFIRMED])->save();
        ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_FULL,
            'provider' => 'paymongo',
            'amount' => 500000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'brevo-resend-message'], 201),
        ]);

        $confirmations = app(GuestBookingConfirmationService::class);

        $this->assertSame('sent', $confirmations->resendForPaidGuest($reservation));
        $this->assertSame('sent', $confirmations->resendForPaidGuest($reservation->fresh()));
        Http::assertSentCount(1);
    }

    public function test_mail_failure_does_not_undo_verified_guest_payment(): void
    {
        [$reservation, $token] = $this->createGuestReservation();
        $payment = ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_FULL,
            'provider' => 'paymongo',
            'provider_reference' => $reservation->booking_reference,
            'checkout_session_id' => 'guest-cs-mail-failure',
            'amount' => 500000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PENDING,
        ]);

        config()->set('services.paymongo.secret_key', 'sk_test_secret');
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions/guest-cs-mail-failure' => Http::response(['data' => [
                'id' => $payment->checkout_session_id,
                'attributes' => [
                    'status' => 'paid',
                    'reference_number' => $reservation->booking_reference,
                    'metadata' => ['payment_purpose' => ReservationPayment::PURPOSE_FULL],
                    'payments' => [[
                        'id' => 'guest-pay-mail-failure',
                        'attributes' => [
                            'amount' => 500000,
                            'currency' => 'PHP',
                            'payment_method_type' => 'card',
                            'status' => 'paid',
                        ],
                    ]],
                ],
            ]]),
            'api.brevo.com/v3/smtp/email' => Http::response(['message' => 'sender rejected'], 400),
        ]);

        $this->withHeader('X-Guest-Checkout-Token', $token)
            ->getJson("/api/guest/payments/reservations/{$reservation->id}/status")
            ->assertOk()
            ->assertJsonPath('data.reservation_status', Reservation::STATUS_CONFIRMED)
            ->assertJsonPath('guest_confirmation_email_status', 'failed');

        $this->assertDatabaseHas('reservation_payments', [
            'id' => $payment->id,
            'status' => ReservationPayment::STATUS_PAID,
        ]);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_CONFIRMED,
            'guest_confirmation_email_status' => 'failed',
        ]);
    }

    public function test_expired_guest_token_cannot_initiate_or_check_payment(): void
    {
        [$reservation, $token] = $this->createGuestReservation();
        $reservation->forceFill(['guest_checkout_token_expires_at' => now()->subMinute()])->save();

        $this->withHeader('X-Guest-Checkout-Token', $token)
            ->postJson('/api/guest/payments/paymongo/checkout', [
                'reservation_id' => $reservation->id,
                'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            ])->assertUnauthorized();

        $this->withHeader('X-Guest-Checkout-Token', $token)
            ->getJson("/api/guest/payments/reservations/{$reservation->id}/status")
            ->assertUnauthorized();
    }

    /** @return array{0: Reservation, 1: string} */
    private function createGuestReservation(): array
    {
        $accommodation = Accommodation::create([
            'name' => 'Guest Payment Room',
            'slug' => 'guest-payment-'.uniqid(),
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 5000,
            'description' => 'Guest payment test accommodation.',
            'status' => Accommodation::STATUS_AVAILABLE,
        ]);

        $response = $this->postJson('/api/guest/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2027-01-10',
            'check_in_time' => '14:00',
            'stay_days' => 1,
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'first_name' => 'Juan',
            'last_name' => 'Guest',
            'email' => 'juan@example.com',
            'phone' => '+639123456789',
        ])->assertCreated();

        return [
            Reservation::findOrFail($response->json('data.id')),
            $response->json('guest_checkout_token'),
        ];
    }
}
