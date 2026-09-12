<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccommodationReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_can_list_accommodations(): void
    {
        $accommodation = $this->createAccommodation();

        $this->getJson('/api/accommodations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $accommodation->id)
            ->assertJsonPath('data.0.name', 'Demo Room');
    }

    public function test_public_can_view_one_accommodation(): void
    {
        $accommodation = $this->createAccommodation();

        $this->getJson("/api/accommodations/{$accommodation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $accommodation->id)
            ->assertJsonPath('data.capacity', 2);
    }

    public function test_availability_detects_open_dates(): void
    {
        Carbon::setTestNow('2026-08-19');
        $accommodation = $this->createAccommodation();

        $this->getJson("/api/accommodations/{$accommodation->id}/availability?check_in=2026-08-20&check_out=2026-08-22&guests=2")
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('nights', 2)
            ->assertJsonPath('total_amount', 5000);
    }

    public function test_availability_requires_at_least_one_adult(): void
    {
        Carbon::setTestNow('2026-08-19');
        $accommodation = $this->createAccommodation();

        $this->getJson("/api/accommodations/{$accommodation->id}/availability?check_in=2026-08-20&check_out=2026-08-22&adults=0&children=1&infants=0")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['adults']);
    }

    public function test_children_and_infants_cannot_be_negative(): void
    {
        Carbon::setTestNow('2026-08-19');
        $accommodation = $this->createAccommodation();

        $this->getJson("/api/accommodations/{$accommodation->id}/availability?check_in=2026-08-20&check_out=2026-08-22&adults=1&children=-1&infants=-1")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['children', 'infants']);
    }

    public function test_capacity_uses_adults_plus_children_and_excludes_infants(): void
    {
        Carbon::setTestNow('2026-08-19');
        $accommodation = $this->createAccommodation(['capacity' => 2]);

        $this->getJson("/api/accommodations/{$accommodation->id}/availability?check_in=2026-08-20&check_out=2026-08-22&adults=1&children=1&infants=2")
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('occupancy', 2)
            ->assertJsonPath('guest_breakdown.infants', 2);

        $this->getJson("/api/accommodations/{$accommodation->id}/availability?check_in=2026-08-20&check_out=2026-08-22&adults=1&children=2&infants=0")
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('occupancy', 3);
    }

    public function test_guest_can_create_reservation_with_server_calculated_total(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation(['capacity' => 3, 'price_per_night' => 2750]);

        $response = $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-23',
            'adults' => 2,
            'children' => 1,
            'infants' => 1,
            'total_amount' => 1,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 8250)
            ->assertJsonPath('data.guests', 3)
            ->assertJsonPath('data.adults', 2)
            ->assertJsonPath('data.children', 1)
            ->assertJsonPath('data.infants', 1)
            ->assertJsonPath('data.status', Reservation::STATUS_PENDING);

        $this->assertMatchesRegularExpression('/^DMD-20260819-[A-Z0-9]{6}$/', $response->json('data.booking_reference'));
        $this->assertDatabaseHas('reservations', [
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'guests' => 3,
            'adults' => 2,
            'children' => 1,
            'infants' => 1,
            'total_amount' => '8250.00',
        ]);
    }

    public function test_guest_can_store_preferred_arrival_time_with_reservation(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation();

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-23',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'preferred_arrival_time' => '15:30',
        ])
            ->assertCreated()
            ->assertJsonPath('data.preferred_arrival_time', '15:30');

        $this->assertDatabaseHas('reservations', [
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'preferred_arrival_time' => '15:30',
        ]);
    }

    public function test_room_reservation_calculates_fixed_twenty_four_hour_checkout(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $room = $this->createAccommodation(['type' => Accommodation::TYPE_ROOM, 'capacity' => 4, 'price_per_night' => 2500]);

        $response = $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-30',
            'check_in_time' => '19:00',
            'adults' => 2,
            'children' => 1,
            'infants' => 0,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.check_in_at', '2026-08-30T11:00:00.000000Z')
            ->assertJsonPath('data.check_out_at', '2026-08-31T11:00:00.000000Z')
            ->assertJsonPath('data.total_amount', 2500);

        $this->assertDatabaseHas('reservations', [
            'user_id' => $guest->id,
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-30',
            'check_out' => '2026-08-31',
            'check_in_at' => '2026-08-30 19:00:00',
            'check_out_at' => '2026-08-31 19:00:00',
            'total_amount' => '2500.00',
        ]);
    }

    public function test_room_back_to_back_reservations_are_allowed(): void
    {
        Carbon::setTestNow('2026-08-19');
        $firstGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $secondGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $room = $this->createAccommodation(['type' => Accommodation::TYPE_ROOM, 'capacity' => 4]);

        Reservation::create([
            'user_id' => $firstGuest->id,
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-30',
            'check_out' => '2026-08-31',
            'check_in_at' => '2026-08-30 07:00:00',
            'check_out_at' => '2026-08-30 19:00:00',
            'guests' => 2,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'total_amount' => 2500,
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-20260819-RM001',
        ]);

        $this->actingAs($secondGuest)->postJson('/api/reservations', [
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-30',
            'check_in_time' => '19:00',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])
            ->assertCreated();
    }

    public function test_room_overlapping_reservations_are_rejected(): void
    {
        Carbon::setTestNow('2026-08-19');
        $firstGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $secondGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $room = $this->createAccommodation(['type' => Accommodation::TYPE_ROOM, 'capacity' => 4]);

        Reservation::create([
            'user_id' => $firstGuest->id,
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-30',
            'check_out' => '2026-08-31',
            'check_in_at' => '2026-08-30 07:00:00',
            'check_out_at' => '2026-08-30 19:00:00',
            'guests' => 2,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'total_amount' => 2500,
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-20260819-RM002',
        ]);

        $this->actingAs($secondGuest)->postJson('/api/reservations', [
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-30',
            'check_in_time' => '18:00',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])
            ->assertStatus(409)
            ->assertJsonValidationErrors(['check_in'])
            ->assertJsonPath('errors.check_in.0', 'This room is unavailable for the selected stay length. Please choose another check-in time.');
    }

    public function test_room_checkout_is_recalculated_even_if_request_contains_extra_checkout_data(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $room = $this->createAccommodation(['type' => Accommodation::TYPE_ROOM, 'capacity' => 4]);

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-30',
            'check_in_time' => '10:00',
            'check_out' => '2026-09-30',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])
            ->assertCreated()
            ->assertJsonPath('data.check_out_at', '2026-08-31T02:00:00.000000Z');
    }

    public function test_room_multiday_stay_two_days_calculates_checkout_and_total(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $room = $this->createAccommodation(['type' => Accommodation::TYPE_ROOM, 'capacity' => 4, 'price_per_night' => 2500]);

        $response = $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-29',
            'check_in_time' => '10:00',
            'stay_days' => 2,
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.stay_days', 2)
            ->assertJsonPath('data.check_out_at', '2026-08-31T02:00:00.000000Z')
            ->assertJsonPath('data.total_amount', 5000);

        $this->assertDatabaseHas('reservations', [
            'user_id' => $guest->id,
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-29',
            'check_out' => '2026-08-31',
            'check_in_at' => '2026-08-29 10:00:00',
            'check_out_at' => '2026-08-31 10:00:00',
            'stay_days' => 2,
            'total_amount' => '5000.00',
        ]);
    }

    public function test_room_multiday_stay_three_days_crosses_month_boundary(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $room = $this->createAccommodation(['type' => Accommodation::TYPE_ROOM, 'capacity' => 4, 'price_per_night' => 2500]);

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-29',
            'check_in_time' => '19:00',
            'stay_days' => 3,
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])
            ->assertCreated()
            ->assertJsonPath('data.check_out_at', '2026-09-01T11:00:00.000000Z')
            ->assertJsonPath('data.total_amount', 7500);
    }

    public function test_room_multiday_overlap_is_rejected_when_existing_reservation_is_inside_requested_stay(): void
    {
        Carbon::setTestNow('2026-08-19');
        $firstGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $secondGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $room = $this->createAccommodation(['type' => Accommodation::TYPE_ROOM, 'capacity' => 4]);

        Reservation::create([
            'user_id' => $firstGuest->id,
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-30',
            'check_out' => '2026-08-31',
            'check_in_at' => '2026-08-30 15:00:00',
            'check_out_at' => '2026-08-31 15:00:00',
            'stay_days' => 1,
            'guests' => 2,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'total_amount' => 2500,
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-20260819-RM003',
        ]);

        $this->actingAs($secondGuest)->postJson('/api/reservations', [
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-29',
            'check_in_time' => '10:00',
            'stay_days' => 3,
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])
            ->assertStatus(409)
            ->assertJsonValidationErrors(['check_in']);
    }

    public function test_guest_cannot_store_arrival_time_outside_resort_window(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation();

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-23',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'preferred_arrival_time' => '23:30',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_arrival_time']);
    }

    public function test_reservation_requires_guest_authentication(): void
    {
        Carbon::setTestNow('2026-08-19');
        $accommodation = $this->createAccommodation();

        $this->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-22',
            'guests' => 2,
        ])->assertUnauthorized();
    }

    public function test_non_guest_cannot_create_reservation(): void
    {
        Carbon::setTestNow('2026-08-19');
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $accommodation = $this->createAccommodation();

        $this->actingAs($manager)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-22',
            'guests' => 2,
        ])->assertForbidden();
    }

    public function test_reservation_validates_capacity_and_dates(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation(['capacity' => 2]);

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-20',
            'guests' => 3,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guests']);

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-22',
            'guests' => 3,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guests']);
    }

    public function test_reservation_rejects_manipulated_over_capacity_request(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation(['capacity' => 50]);

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-22',
            'guests' => 999999,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guests'])
            ->assertJsonPath('errors.guests.0', 'This accommodation allows a maximum of 50 guests.');
    }

    public function test_reservation_prevents_overlapping_active_reservations(): void
    {
        Carbon::setTestNow('2026-08-19');
        $firstGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $secondGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation();

        Reservation::create([
            'user_id' => $firstGuest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-23',
            'guests' => 2,
            'total_amount' => 7500,
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-20260819-ABC123',
        ]);

        $this->actingAs($secondGuest)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-22',
            'check_out' => '2026-08-24',
            'guests' => 2,
        ])
            ->assertStatus(409)
            ->assertJsonValidationErrors(['check_in']);
    }

    public function test_competing_reservation_attempts_leave_only_one_active_hold(): void
    {
        Carbon::setTestNow('2026-08-19 10:00:00');
        $firstGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $secondGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation(['slug' => 'locked-room']);

        $first = $this->actingAs($firstGuest)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_in_time' => '10:00',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])->assertCreated();

        $this->actingAs($secondGuest)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_in_time' => '10:00',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'That time was just booked by another guest. Please choose another check-in time or date.');

        $this->assertSame(1, Reservation::where('accommodation_id', $accommodation->id)->count());
        $this->assertDatabaseHas('reservations', ['id' => $first->json('data.id')]);
    }

    public function test_expired_pending_hold_does_not_block_new_reservation_creation(): void
    {
        Carbon::setTestNow('2026-08-19 10:00:00');
        $firstGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $secondGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation(['slug' => 'expired-hold-room']);

        $this->createReservation($firstGuest, $accommodation, [
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-21',
            'expires_at' => '2026-08-19 09:59:00',
            'status' => Reservation::STATUS_PENDING,
        ]);

        $this->actingAs($secondGuest)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-21',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])->assertCreated();

        $this->assertSame(2, Reservation::where('accommodation_id', $accommodation->id)->count());
    }

    public function test_cancelled_reservation_does_not_block_new_reservation_creation(): void
    {
        Carbon::setTestNow('2026-08-19 10:00:00');
        $firstGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $secondGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation(['slug' => 'cancelled-room']);

        $this->createReservation($firstGuest, $accommodation, [
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-21',
            'status' => Reservation::STATUS_CANCELLED,
        ]);

        $this->actingAs($secondGuest)->postJson('/api/reservations', [
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-21',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])->assertCreated();
    }

    public function test_search_excludes_overlapping_active_reservations(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $booked = $this->createAccommodation(['slug' => 'booked-room']);
        $open = $this->createAccommodation(['slug' => 'open-room']);
        $this->createReservation($guest, $booked, [
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-23',
            'status' => Reservation::STATUS_CONFIRMED,
        ]);

        $this->getJson('/api/accommodations?check_in=2026-08-21&check_out=2026-08-22&adults=1&children=1&infants=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $open->id);
    }

    public function test_non_overlapping_bookings_remain_available_in_search(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation();
        $this->createReservation($guest, $accommodation, [
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-22',
            'status' => Reservation::STATUS_CONFIRMED,
        ]);

        $this->getJson('/api/accommodations?check_in=2026-08-22&check_out=2026-08-24&adults=1&children=1&infants=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $accommodation->id);
    }

    public function test_guest_can_only_view_own_reservations(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $otherGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation();

        $ownReservation = Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-22',
            'guests' => 2,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'total_amount' => 5000,
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-20260819-OWN001',
        ]);

        Reservation::create([
            'user_id' => $otherGuest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-08-24',
            'check_out' => '2026-08-26',
            'guests' => 2,
            'total_amount' => 5000,
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-20260819-OTH001',
        ]);

        $this->actingAs($guest)->getJson('/api/reservations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownReservation->id)
            ->assertJsonPath('data.0.booking_reference', 'DMD-20260819-OWN001');
    }

    public function test_guest_can_view_own_reservation(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = $this->createAccommodation();
        $reservation = $this->createReservation($guest, $accommodation, [
            'booking_reference' => 'DMD-20260819-VIEW01',
        ]);

        $this->actingAs($guest)->getJson("/api/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $reservation->id)
            ->assertJsonPath('data.booking_reference', 'DMD-20260819-VIEW01')
            ->assertJsonPath('data.accommodation.name', 'Demo Room')
            ->assertJsonPath('data.payment_status', 'pending');
    }

    public function test_guest_cannot_view_another_users_reservation(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $otherGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($otherGuest, $this->createAccommodation());

        $this->actingAs($guest)
            ->getJson("/api/reservations/{$reservation->id}")
            ->assertNotFound();
    }

    public function test_eligible_reservation_can_be_cancelled(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest, $this->createAccommodation(), [
            'check_in' => '2026-08-21',
            'check_out' => '2026-08-23',
            'status' => Reservation::STATUS_CONFIRMED,
        ]);

        $this->actingAs($guest)
            ->postJson("/api/reservations/{$reservation->id}/cancel", [
                'reason' => 'Travel plans changed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CANCELLED)
            ->assertJsonPath('data.cancellation_reason', 'Travel plans changed.');

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_CANCELLED,
            'cancellation_reason' => 'Travel plans changed.',
        ]);
        $this->assertNotNull($reservation->fresh()->cancelled_at);
    }

    public function test_cancellation_reason_is_required(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest, $this->createAccommodation(), [
            'check_in' => '2026-08-21',
        ]);

        $this->actingAs($guest)
            ->postJson("/api/reservations/{$reservation->id}/cancel", [
                'reason' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_checked_in_or_checked_out_reservation_cannot_be_cancelled(): void
    {
        Carbon::setTestNow('2026-08-22');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $checkedIn = $this->createReservation($guest, $this->createAccommodation(), [
            'check_in' => '2026-08-21',
            'check_out' => '2026-08-23',
            'booking_reference' => 'DMD-20260819-IN0001',
        ]);
        $checkedOut = $this->createReservation($guest, $this->createAccommodation(['slug' => 'demo-room-2']), [
            'check_in' => '2026-08-18',
            'check_out' => '2026-08-20',
            'booking_reference' => 'DMD-20260819-OUT001',
        ]);

        $this->actingAs($guest)
            ->postJson("/api/reservations/{$checkedIn->id}/cancel", [
                'reason' => 'Cannot make it.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reservation']);

        $this->actingAs($guest)
            ->postJson("/api/reservations/{$checkedOut->id}/cancel", [
                'reason' => 'Cannot make it.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reservation']);
    }

    public function test_cancelled_reservation_remains_in_history(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->createReservation($guest, $this->createAccommodation(), [
            'check_in' => '2026-08-21',
        ]);

        $this->actingAs($guest)
            ->postJson("/api/reservations/{$reservation->id}/cancel", [
                'reason' => 'Schedule conflict.',
            ])
            ->assertOk();

        $this->actingAs($guest)
            ->getJson('/api/reservations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reservation->id)
            ->assertJsonPath('data.0.status', Reservation::STATUS_CANCELLED)
            ->assertJsonPath('data.0.cancellation_reason', 'Schedule conflict.');
    }

    public function test_function_hall_can_be_booked_for_an_available_date_and_uses_whole_day_rate(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $functionHall = $this->createAccommodation([
            'type' => Accommodation::TYPE_FUNCTION_HALL,
            'name' => 'Demo Function Hall',
            'slug' => 'demo-function-hall',
            'price_per_night' => 12000,
        ]);

        $response = $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $functionHall->id,
            'check_in' => '2026-08-30',
            'check_out' => '2026-08-31',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 12000)
            ->assertJsonPath('data.accommodation.type', Accommodation::TYPE_FUNCTION_HALL);

        $this->assertDatabaseHas('reservations', [
            'user_id' => $guest->id,
            'accommodation_id' => $functionHall->id,
            'check_in' => '2026-08-30',
            'check_out' => '2026-08-31',
            'total_amount' => '12000.00',
        ]);
    }

    public function test_function_hall_cannot_be_booked_twice_for_the_same_day(): void
    {
        Carbon::setTestNow('2026-08-19');
        $firstGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $secondGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $functionHall = $this->createAccommodation([
            'type' => Accommodation::TYPE_FUNCTION_HALL,
            'name' => 'Demo Function Hall',
            'slug' => 'demo-function-hall',
            'price_per_night' => 12000,
        ]);

        Reservation::create([
            'user_id' => $firstGuest->id,
            'accommodation_id' => $functionHall->id,
            'check_in' => '2026-08-30',
            'check_out' => '2026-08-31',
            'guests' => 10,
            'total_amount' => 12000,
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-20260819-HALL01',
        ]);

        $this->actingAs($secondGuest)->postJson('/api/reservations', [
            'accommodation_id' => $functionHall->id,
            'check_in' => '2026-08-30',
            'check_out' => '2026-08-31',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])
            ->assertStatus(409)
            ->assertJsonValidationErrors(['check_in']);

        $this->actingAs($secondGuest)->postJson('/api/reservations', [
            'accommodation_id' => $functionHall->id,
            'check_in' => '2026-08-31',
            'check_out' => '2026-09-01',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])
            ->assertCreated();
    }

    public function test_function_hall_booking_does_not_require_hourly_fields(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $functionHall = $this->createAccommodation([
            'type' => Accommodation::TYPE_FUNCTION_HALL,
            'name' => 'Demo Function Hall',
            'slug' => 'demo-function-hall',
            'price_per_night' => 12000,
        ]);

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $functionHall->id,
            'check_in' => '2026-08-30',
            'check_out' => '2026-08-31',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])
            ->assertCreated()
            ->assertJsonMissingValidationErrors(['preferred_arrival_time']);
    }

    public function test_function_hall_supports_multi_day_reservations_and_authoritative_total(): void
    {
        Carbon::setTestNow('2026-08-19');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $functionHall = $this->createAccommodation([
            'type' => Accommodation::TYPE_FUNCTION_HALL,
            'name' => 'Demo Function Hall',
            'slug' => 'demo-function-hall',
            'capacity' => 100,
            'price_per_night' => 7899,
        ]);

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $functionHall->id,
            'event_date' => '2026-08-30',
            'stay_days' => 3,
            'guests' => 50,
        ])
            ->assertCreated()
            ->assertJsonPath('data.check_in', '2026-08-30')
            ->assertJsonPath('data.check_out', '2026-09-02')
            ->assertJsonPath('data.stay_days', 3)
            ->assertJsonPath('data.total_amount', 23697);
    }

    public function test_function_hall_rejects_overlapping_dates_in_multi_day_range(): void
    {
        Carbon::setTestNow('2026-08-19');
        $firstGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $secondGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $functionHall = $this->createAccommodation([
            'type' => Accommodation::TYPE_FUNCTION_HALL,
            'name' => 'Demo Function Hall',
            'slug' => 'demo-function-hall',
            'capacity' => 100,
            'price_per_night' => 7899,
        ]);

        Reservation::create([
            'user_id' => $firstGuest->id,
            'accommodation_id' => $functionHall->id,
            'check_in' => '2026-08-31',
            'check_out' => '2026-09-01',
            'stay_days' => 1,
            'guests' => 50,
            'total_amount' => 7899,
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-20260819-HALL02',
        ]);

        $this->actingAs($secondGuest)->postJson('/api/reservations', [
            'accommodation_id' => $functionHall->id,
            'event_date' => '2026-08-30',
            'stay_days' => 3,
            'guests' => 50,
        ])
            ->assertStatus(409)
            ->assertJsonValidationErrors(['check_in']);
    }

    public function test_function_hall_rejects_invalid_or_past_day_counts(): void
    {
        Carbon::setTestNow('2026-08-29');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $functionHall = $this->createAccommodation([
            'type' => Accommodation::TYPE_FUNCTION_HALL,
            'name' => 'Demo Function Hall',
            'slug' => 'demo-function-hall',
            'capacity' => 100,
            'price_per_night' => 7899,
        ]);

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $functionHall->id,
            'event_date' => '2026-08-30',
            'stay_days' => 0,
            'guests' => 50,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stay_days']);

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $functionHall->id,
            'event_date' => '2026-08-28',
            'stay_days' => 2,
            'guests' => 50,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['event_date']);
    }

    public function test_exclusive_resort_supports_multi_day_reservations_and_blocks_other_bookings(): void
    {
        Carbon::setTestNow('2026-08-30');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $exclusiveResort = $this->createAccommodation([
            'type' => Accommodation::TYPE_EXCLUSIVE_RESORT,
            'name' => 'Exclusive Resort Rental',
            'slug' => 'exclusive-resort-rental',
            'capacity' => 120,
            'price_per_night' => 20000,
        ]);
        $room = $this->createAccommodation([
            'type' => Accommodation::TYPE_ROOM,
            'slug' => 'demo-room-2',
        ]);

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $exclusiveResort->id,
            'event_date' => '2026-08-30',
            'stay_days' => 3,
            'guests' => 50,
        ])
            ->assertCreated()
            ->assertJsonPath('data.check_in', '2026-08-30')
            ->assertJsonPath('data.check_out', '2026-09-02')
            ->assertJsonPath('data.stay_days', 3)
            ->assertJsonPath('data.total_amount', 60000);

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-31',
            'check_in_time' => '10:00',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])
            ->assertStatus(409)
            ->assertJsonValidationErrors(['check_in']);
    }

    public function test_room_booking_is_blocked_when_exclusive_resort_is_reserved(): void
    {
        Carbon::setTestNow('2026-08-30');
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $room = $this->createAccommodation([
            'type' => Accommodation::TYPE_ROOM,
            'slug' => 'demo-room-3',
        ]);
        $exclusiveResort = $this->createAccommodation([
            'type' => Accommodation::TYPE_EXCLUSIVE_RESORT,
            'name' => 'Exclusive Resort Rental',
            'slug' => 'exclusive-resort-rental-2',
            'capacity' => 120,
            'price_per_night' => 20000,
        ]);

        Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $exclusiveResort->id,
            'check_in' => '2026-08-30',
            'check_out' => '2026-09-02',
            'guests' => 50,
            'total_amount' => 60000,
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-20260830-EXR001',
        ]);

        $this->actingAs($guest)->postJson('/api/reservations', [
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-31',
            'check_in_time' => '10:00',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])
            ->assertStatus(409)
            ->assertJsonValidationErrors(['check_in']);
    }

    public function test_room_expected_time_fields_do_not_affect_function_hall_booking(): void
    {
        Carbon::setTestNow('2026-08-19');
        $roomGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $hallGuest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $room = $this->createAccommodation([
            'type' => Accommodation::TYPE_ROOM,
            'name' => 'Demo Room',
            'slug' => 'demo-room',
        ]);
        $functionHall = $this->createAccommodation([
            'type' => Accommodation::TYPE_FUNCTION_HALL,
            'name' => 'Demo Function Hall',
            'slug' => 'demo-function-hall',
            'price_per_night' => 12000,
        ]);

        $this->actingAs($roomGuest)->postJson('/api/reservations', [
            'accommodation_id' => $room->id,
            'check_in' => '2026-08-20',
            'check_in_time' => '15:30',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])
            ->assertCreated();

        $this->actingAs($hallGuest)->postJson('/api/reservations', [
            'accommodation_id' => $functionHall->id,
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-21',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
        ])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 12000);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAccommodation(array $overrides = []): Accommodation
    {
        return Accommodation::create(array_merge([
            'name' => 'Demo Room',
            'slug' => 'demo-room',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 2,
            'price_per_night' => 2500,
            'description' => 'Demo accommodation for tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'housekeeping_status' => Accommodation::HOUSEKEEPING_READY,
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
            'booking_reference' => 'DMD-20260819-TST'.str_pad((string) Reservation::count(), 3, '0', STR_PAD_LEFT),
        ], $overrides));
    }
}

