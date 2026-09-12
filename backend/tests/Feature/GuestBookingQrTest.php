<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\User;
use App\Services\ReservationQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestBookingQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_guest_can_issue_a_hashed_qr_credential(): void
    {
        $reservation = $this->confirmedGuestReservation();
        $issued = app(ReservationQrService::class)->issue($reservation);

        $this->assertIsString($issued['token']);
        $this->assertSame(64, strlen($issued['token']));
        $this->assertNotSame($issued['token'], $reservation->fresh()->qr_token_hash);
        $this->assertSame(hash('sha256', $issued['token']), $reservation->fresh()->qr_token_hash);
        $this->assertDatabaseMissing('reservations', ['qr_token_hash' => $issued['token']]);
    }

    public function test_qr_service_extracts_raw_relative_full_and_encoded_payloads(): void
    {
        $token = Str::random(64);
        $service = app(ReservationQrService::class);
        $payload = $service->payload($token);

        $this->assertSame($token, $service->extractToken($token));
        $this->assertSame($token, $service->extractToken("/verify-booking/{$token}\r\n"));
        $this->assertSame($token, $service->extractToken("{$payload}\n"));
        $this->assertSame($token, $service->extractToken(rawurlencode($payload)));
        $this->assertNull($service->extractToken('DMD-20260912-ZYEOVF'));
    }

    public function test_pending_unpaid_guest_does_not_receive_an_active_qr(): void
    {
        $reservation = $this->guestReservation();
        $issued = app(ReservationQrService::class)->issue($reservation);

        $this->assertNull($issued['token']);
        $this->assertNull($reservation->fresh()->qr_token_hash);
    }

    public function test_guest_booking_api_returns_qr_payload_but_not_the_hash(): void
    {
        $reservation = $this->confirmedGuestReservation();
        $accessToken = Str::random(64);
        $reservation->forceFill([
            'guest_access_token_hash' => hash('sha256', $accessToken),
            'guest_access_token_issued_at' => now(),
        ])->save();

        $response = $this->withHeader('X-Guest-Access-Token', $accessToken)
            ->getJson('/api/guest/booking')
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CONFIRMED)
            ->assertJsonMissingPath('data.qr_token_hash');

        $payload = $response->json('data.booking_qr.payload');
        $this->assertIsString($payload);
        $this->assertStringContainsString('/verify-booking/', $payload);
        $this->assertStringNotContainsString($reservation->guest_email, $payload);
        $this->assertStringNotContainsString($reservation->booking_reference, $payload);
        $this->assertNotSame($accessToken, $payload);

        $secondPayload = $this->withHeader('X-Guest-Access-Token', $accessToken)
            ->getJson('/api/guest/booking')
            ->assertOk()
            ->json('data.booking_qr.payload');
        $this->assertSame($payload, $secondPayload);
    }

    public function test_front_desk_can_verify_guest_qr_and_sees_live_payment_state(): void
    {
        $reservation = $this->confirmedGuestReservation();
        $issued = app(ReservationQrService::class)->issue($reservation);
        $staff = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);

        $this->actingAs($staff)
            ->postJson('/api/frontdesk/booking-qr/verify', [
                'payload' => app(ReservationQrService::class)->payload($issued['token']),
            ])
            ->assertOk()
            ->assertJsonPath('data.qr_valid', true)
            ->assertJsonPath('data.reservation_id', $reservation->id)
            ->assertJsonPath('data.booking_reference', $reservation->booking_reference)
            ->assertJsonPath('data.primary_guest.name', 'Mcgregor Conor')
            ->assertJsonPath('data.payment_state', 'partially_paid')
            ->assertJsonPath('data.balance_due', '7000.00')
            ->assertJsonPath('data.check_in_eligible', false);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_CONFIRMED,
        ]);
    }

    public function test_front_desk_can_verify_a_raw_qr_token_for_an_eligible_reservation(): void
    {
        $reservation = $this->confirmedGuestReservation(['total_amount' => '3000.00']);
        $issued = app(ReservationQrService::class)->issue($reservation);
        $staff = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);

        $this->actingAs($staff)
            ->postJson('/api/frontdesk/booking-qr/verify', [
                'payload' => "{$issued['token']}\r\n",
            ])
            ->assertOk()
            ->assertJsonPath('data.reservation_id', $reservation->id)
            ->assertJsonPath('data.check_in_eligible', true);
    }

    public function test_current_email_qr_token_verifies_for_a_confirmed_reservation(): void
    {
        $reservation = $this->confirmedGuestReservation(['total_amount' => '3000.00']);
        $guestAccessToken = Str::random(64);
        $issued = app(ReservationQrService::class)->issueForGuestAccess($reservation, $guestAccessToken);
        $staff = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);

        $this->assertSame(
            $reservation->fresh()->qr_token_hash,
            hash('sha256', $issued['token']),
        );

        $this->actingAs($staff)
            ->postJson('/api/frontdesk/booking-qr/verify', [
                'payload' => app(ReservationQrService::class)->payload($issued['token']),
            ])
            ->assertOk()
            ->assertJsonPath('data.qr_valid', true)
            ->assertJsonPath('data.booking_reference', $reservation->booking_reference)
            ->assertJsonPath('data.reservation_status', Reservation::STATUS_CONFIRMED);
    }

    public function test_verified_eligible_qr_can_use_existing_front_desk_check_in_action(): void
    {
        $reservation = $this->confirmedGuestReservation(['total_amount' => '3000.00']);
        $issued = app(ReservationQrService::class)->issue($reservation);
        $staff = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);

        $verification = $this->actingAs($staff)
            ->postJson('/api/frontdesk/booking-qr/verify', [
                'payload' => app(ReservationQrService::class)->payload($issued['token']),
            ])
            ->assertOk()
            ->assertJsonPath('data.reservation_id', $reservation->id)
            ->assertJsonPath('data.check_in_eligible', true);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_CONFIRMED,
        ]);

        $this->actingAs($staff)
            ->patchJson("/api/frontdesk/reservations/{$verification->json('data.reservation_id')}/status", [
                'status' => Reservation::STATUS_CHECKED_IN,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CHECKED_IN)
            ->assertJsonPath('data.checked_in_at', fn ($value) => is_string($value));

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_CHECKED_IN,
        ]);

        $this->actingAs($staff)
            ->postJson('/api/frontdesk/booking-qr/verify', [
                'payload' => app(ReservationQrService::class)->payload($issued['token']),
            ])
            ->assertOk()
            ->assertJsonPath('data.reservation_status', Reservation::STATUS_CHECKED_IN)
            ->assertJsonPath('data.check_in_eligible', false)
            ->assertJsonPath('data.check_in_block_reason', 'This reservation has already been checked in.');
    }

    public function test_invalid_qr_and_unauthorized_users_are_rejected(): void
    {
        $reservation = $this->confirmedGuestReservation();
        $issued = app(ReservationQrService::class)->issue($reservation);
        $customer = User::factory()->create(['role' => User::ROLE_GUEST]);
        $staff = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);

        $this->postJson('/api/frontdesk/booking-qr/verify', [
            'payload' => app(ReservationQrService::class)->payload($issued['token']),
        ])->assertUnauthorized();

        $this->actingAs($customer)
            ->postJson('/api/frontdesk/booking-qr/verify', [
                'payload' => app(ReservationQrService::class)->payload($issued['token']),
            ])
            ->assertForbidden();

        $this->actingAs($staff)
            ->postJson('/api/frontdesk/booking-qr/verify', [
                'payload' => app(ReservationQrService::class)->payload(Str::random(64)),
            ])
            ->assertNotFound();
    }

    public function test_cancelled_expired_and_revoked_qr_cannot_be_verified(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);

        foreach ([Reservation::STATUS_CANCELLED, Reservation::STATUS_EXPIRED] as $status) {
            $reservation = $this->confirmedGuestReservation(['status' => $status]);
            $issued = app(ReservationQrService::class)->issue($reservation);

            $this->assertNull($issued['token']);
        }

        $reservation = $this->confirmedGuestReservation();
        $issued = app(ReservationQrService::class)->issue($reservation);
        $reservation->forceFill(['qr_token_revoked_at' => now()])->save();

        $this->actingAs($staff)
            ->postJson('/api/frontdesk/booking-qr/verify', [
                'payload' => app(ReservationQrService::class)->payload($issued['token']),
            ])
            ->assertNotFound();

        $expiredReservation = $this->confirmedGuestReservation();
        $expiredIssued = app(ReservationQrService::class)->issue($expiredReservation);
        $expiredReservation->forceFill(['status' => Reservation::STATUS_EXPIRED])->save();

        $this->actingAs($staff)
            ->postJson('/api/frontdesk/booking-qr/verify', [
                'payload' => app(ReservationQrService::class)->payload($expiredIssued['token']),
            ])
            ->assertNotFound();
    }

    public function test_rotating_a_qr_replaces_the_previous_credential(): void
    {
        $reservation = $this->confirmedGuestReservation();
        $service = app(ReservationQrService::class);
        $first = $service->issue($reservation);
        $second = $service->issue($reservation->fresh(), true);
        $staff = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);

        $this->actingAs($staff)
            ->postJson('/api/frontdesk/booking-qr/verify', ['payload' => $service->payload($first['token'])])
            ->assertNotFound();

        $this->actingAs($staff)
            ->postJson('/api/frontdesk/booking-qr/verify', ['payload' => $service->payload($second['token'])])
            ->assertOk();
    }

    /** @param array<string, mixed> $overrides */
    private function confirmedGuestReservation(array $overrides = []): Reservation
    {
        $reservation = $this->guestReservation(array_merge([
            'status' => Reservation::STATUS_CONFIRMED,
        ], $overrides));

        ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            'provider' => 'paymongo',
            'amount' => 300000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        return $reservation->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function guestReservation(array $overrides = []): Reservation
    {
        $accommodation = Accommodation::create([
            'name' => 'QR Test Room',
            'slug' => 'qr-test-'.Str::lower(Str::random(8)),
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 10000,
            'description' => 'QR test accommodation.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);

        return Reservation::create(array_merge([
            'user_id' => null,
            'guest_first_name' => 'Mcgregor',
            'guest_last_name' => 'Conor',
            'guest_email' => 'midasdalayon@gmail.com',
            'guest_phone' => '09365528454',
            'accommodation_id' => $accommodation->id,
            'check_in' => '2027-01-10',
            'check_out' => '2027-01-11',
            'check_in_at' => '2027-01-10 14:00:00',
            'check_out_at' => '2027-01-11 14:00:00',
            'stay_days' => 1,
            'guests' => 3,
            'adults' => 3,
            'children' => 0,
            'infants' => 0,
            'total_amount' => '10000.00',
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-QR-'.strtoupper(Str::random(8)),
        ], $overrides));
    }
}
