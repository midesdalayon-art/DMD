<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestReservationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_guest_reservation_uses_snapshot_fields_without_creating_a_user(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 10:00:00');
        $accommodation = $this->accommodation('guest-foundation');
        $usersBefore = \App\Models\User::count();

        $response = $this->postJson('/api/guest/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-09-08',
            'check_out' => '2026-09-09',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '+639123456789',
        ])->assertCreated();

        $reservation = Reservation::findOrFail($response->json('data.id'));

        $this->assertNull($reservation->user_id);
        $this->assertSame($usersBefore, \App\Models\User::count());
        $this->assertSame('pending', $reservation->status);
        $this->assertNotNull($reservation->expires_at);
        $this->assertSame('Juan', $reservation->guest_first_name);
        $this->assertSame('Dela Cruz', $reservation->guest_last_name);
        $this->assertSame('juan@example.com', $reservation->guest_email);
        $this->assertSame('+639123456789', $reservation->guest_phone);
        $this->assertSame('Juan Dela Cruz', $response->json('data.guest.name'));
    }

    public function test_guest_contact_information_is_validated(): void
    {
        $accommodation = $this->accommodation('guest-validation');

        $this->postJson('/api/guest/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-09-08',
            'check_out' => '2026-09-09',
            'adults' => 1,
            'first_name' => '',
            'last_name' => 'Guest',
            'email' => 'not-an-email',
            'phone' => 'bad-phone',
        ])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'email', 'phone']);
    }

    public function test_guest_reservation_uses_the_same_active_overlap_protection(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 10:00:00');
        $accommodation = $this->accommodation('guest-overlap');
        $firstCheckIn = CarbonImmutable::now()->addDay();
        $secondCheckIn = $firstCheckIn->addDay();

        $this->postJson('/api/guest/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => $firstCheckIn->toDateString(),
            'check_out' => $firstCheckIn->addDays(2)->toDateString(),
            'adults' => 1,
            'first_name' => 'First',
            'last_name' => 'Guest',
            'email' => 'first@example.com',
            'phone' => '+639123456789',
        ])->assertCreated();

        $this->postJson('/api/guest/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => $secondCheckIn->toDateString(),
            'check_out' => $secondCheckIn->addDays(2)->toDateString(),
            'adults' => 1,
            'first_name' => 'Second',
            'last_name' => 'Guest',
            'email' => 'second@example.com',
            'phone' => '+639123456789',
        ])->assertStatus(409);
    }

    public function test_guest_and_registered_reservation_requests_share_the_same_conflict_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 10:00:00');
        $user = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_GUEST]);
        $accommodation = $this->accommodation('guest-registered-conflict');
        $checkIn = CarbonImmutable::now()->addDay()->toDateString();
        $checkOut = CarbonImmutable::now()->addDays(2)->toDateString();

        $this->actingAs($user)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])->assertCreated();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/guest/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => 1,
            'first_name' => 'Competing',
            'last_name' => 'Guest',
            'email' => 'competing@example.com',
            'phone' => '+639123456789',
        ])->assertStatus(409);

        $this->assertSame(1, Reservation::where('accommodation_id', $accommodation->id)->count());
    }

    public function test_registered_reservation_path_remains_user_owned(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 10:00:00');
        $user = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_GUEST]);
        $accommodation = $this->accommodation('registered-compatibility');
        $checkIn = CarbonImmutable::now()->addDay()->toDateString();
        $checkOut = CarbonImmutable::now()->addDays(2)->toDateString();

        $this->actingAs($user)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])->assertCreated();

        $this->assertDatabaseHas('reservations', [
            'accommodation_id' => $accommodation->id,
            'user_id' => $user->id,
            'guest_first_name' => null,
        ]);
    }

    public function test_guest_pending_reservation_participates_in_expiration(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 10:16:00');
        $accommodation = $this->accommodation('guest-expiration');

        $response = $this->postJson('/api/guest/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-09-08',
            'check_out' => '2026-09-09',
            'adults' => 1,
            'first_name' => 'Expired',
            'last_name' => 'Guest',
            'email' => 'expired@example.com',
            'phone' => '+639123456789',
        ])->assertCreated();

        $reservation = Reservation::findOrFail($response->json('data.id'));
        $reservation->forceFill(['expires_at' => CarbonImmutable::parse('2026-09-07 10:15:00')])->save();

        $this->artisan('reservations:expire-pending')->assertSuccessful();
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => Reservation::STATUS_EXPIRED]);
    }

    private function accommodation(string $slug): Accommodation
    {
        return Accommodation::create([
            'name' => $slug,
            'slug' => $slug.'-'.uniqid(),
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 5000,
            'description' => 'Guest reservation test accommodation.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
