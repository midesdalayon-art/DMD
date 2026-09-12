<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminBookingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_filter_reservations(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $guest = User::factory()->create([
            'name' => 'Maria Guest',
            'first_name' => 'Maria',
            'last_name' => 'Guest',
            'email' => 'maria@example.test',
        ]);
        $accommodation = $this->createAccommodation([
            'name' => 'Garden Cottage',
            'slug' => 'garden-cottage',
            'type' => 'cottage',
        ]);
        $this->createReservation($guest, $accommodation, [
            'booking_reference' => 'DMD-20260819-BOOK01',
            'status' => Reservation::STATUS_PENDING,
            'check_in' => '2026-08-25',
        ]);
        $this->createReservation(User::factory()->create(), $this->createAccommodation(['slug' => 'pool-room']), [
            'booking_reference' => 'DMD-20260819-BOOK02',
            'status' => Reservation::STATUS_CONFIRMED,
            'check_in' => '2026-09-05',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/reservations?booking_reference=BOOK01&guest=maria&accommodation=garden&status=pending&date_from=2026-08-20&date_to=2026-08-31')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.booking_reference', 'DMD-20260819-BOOK01')
            ->assertJsonPath('data.data.0.guest.email', 'maria@example.test')
            ->assertJsonPath('data.data.0.accommodation.name', 'Garden Cottage');
    }

    public function test_admin_booking_listing_supports_server_side_pagination(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $guest = User::factory()->create();
        $accommodation = $this->createAccommodation();
        for ($index = 0; $index < 12; $index++) {
            $this->createReservation($guest, $accommodation, [
                'booking_reference' => 'DMD-20260912-PAGE'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $this->actingAs($admin)
            ->getJson('/api/admin/reservations?per_page=10&page=2')
            ->assertOk()
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.per_page', 10)
            ->assertJsonPath('data.total', 12)
            ->assertJsonCount(2, 'data.data');
    }

    public function test_admin_can_view_reservation_details(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $guest = User::factory()->create(['email' => 'guest@example.test']);
        $reservation = $this->createReservation($guest, $this->createAccommodation());

        $this->actingAs($admin)
            ->getJson("/api/admin/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $reservation->id)
            ->assertJsonPath('data.guest.email', 'guest@example.test')
            ->assertJsonPath('data.payment_status', 'pending');
    }

    public function test_admin_can_update_reservation_status_using_allowed_transition(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $reservation = $this->createReservation(User::factory()->create(), $this->createAccommodation(), [
            'status' => Reservation::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/reservations/{$reservation->id}/status", [
                'status' => Reservation::STATUS_CONFIRMED,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CONFIRMED);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_CONFIRMED,
        ]);
    }

    public function test_admin_cannot_use_unsafe_status_transition(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $reservation = $this->createReservation(User::factory()->create(), $this->createAccommodation(), [
            'status' => Reservation::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/reservations/{$reservation->id}/status", [
                'status' => Reservation::STATUS_CHECKED_OUT,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_admin_cancellation_requires_reason(): void
    {
        Carbon::setTestNow('2026-08-19');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $reservation = $this->createReservation(User::factory()->create(), $this->createAccommodation(), [
            'status' => Reservation::STATUS_CONFIRMED,
            'check_in' => '2026-08-25',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/reservations/{$reservation->id}/status", [
                'status' => Reservation::STATUS_CANCELLED,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cancellation_reason');
    }

    public function test_admin_can_cancel_eligible_reservation_with_reason(): void
    {
        Carbon::setTestNow('2026-08-19');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $reservation = $this->createReservation(User::factory()->create(), $this->createAccommodation(), [
            'status' => Reservation::STATUS_CONFIRMED,
            'check_in' => '2026-08-25',
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/admin/reservations/{$reservation->id}/status", [
                'status' => Reservation::STATUS_CANCELLED,
                'cancellation_reason' => 'Guest requested cancellation by phone.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CANCELLED)
            ->assertJsonPath('data.cancellation_reason', 'Guest requested cancellation by phone.');
    }

    public function test_admin_reservation_routes_reject_non_admin_users(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);

        $this->actingAs($guest)
            ->getJson('/api/admin/reservations')
            ->assertForbidden();
    }

    public function test_admin_reservation_routes_require_authentication(): void
    {
        $this->getJson('/api/admin/reservations')->assertUnauthorized();
    }

    public function test_guest_still_cannot_view_another_users_reservation(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $otherGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($otherGuest, $this->createAccommodation());

        $this->actingAs($guest)
            ->getJson("/api/reservations/{$reservation->id}")
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAccommodation(array $overrides = []): Accommodation
    {
        return Accommodation::create(array_merge([
            'name' => 'Demo Room',
            'slug' => 'demo-room',
            'type' => 'room',
            'capacity' => 2,
            'price_per_night' => 2500,
            'description' => 'Demo accommodation for tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createReservation(User $user, Accommodation $accommodation, array $overrides = []): Reservation
    {
        return Reservation::create(array_merge([
            'user_id' => $user->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-22',
            'guests' => 2,
            'total_amount' => 5000,
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-20260819-BKG'.str_pad((string) Reservation::count(), 3, '0', STR_PAD_LEFT),
        ], $overrides));
    }
}

