<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Announcement;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FrontDeskAccountAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_front_desk_can_log_in_and_receives_front_desk_dashboard_redirect(): void
    {
        User::factory()->create([
            'email' => 'frontdesk@dmdresort.test',
            'password' => Hash::make('FrontDeskPassword1'),
            'role' => User::ROLE_FRONT_DESK,
        ]);

        $this->postJson('/api/login', [
            'email' => 'frontdesk@dmdresort.test',
            'password' => 'FrontDeskPassword1',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', User::ROLE_FRONT_DESK)
            ->assertJsonPath('user.redirect_to', '/frontdesk/dashboard');
    }

    public function test_front_desk_can_access_dashboard_summary_but_non_staff_cannot(): void
    {
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = Accommodation::create([
            'name' => 'Front Desk Room',
            'slug' => 'front-desk-room',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 2,
            'price_per_night' => 2500,
            'description' => 'Room for dashboard testing.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ]);

        Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => today()->toDateString(),
            'check_out' => today()->addDay()->toDateString(),
            'guests' => 2,
            'total_amount' => 2500,
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-20260824-FD001',
        ]);

        $this->actingAs($frontDesk)
            ->getJson('/api/frontdesk/dashboard-summary')
            ->assertOk()
            ->assertJsonPath('data.summary.todays_check_ins', 0)
            ->assertJsonPath('data.summary.pending_bookings', 1);

        $this->actingAs($guest)
            ->getJson('/api/frontdesk/dashboard-summary')
            ->assertForbidden();
    }

    public function test_front_desk_dashboard_counts_only_operationally_valid_reservations_and_ready_units(): void
    {
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $today = today()->toDateString();
        $tomorrow = today()->addDay()->toDateString();

        $makeAccommodation = function (string $name, string $status = Accommodation::STATUS_AVAILABLE, string $housekeeping = Accommodation::HOUSEKEEPING_READY): Accommodation {
            return Accommodation::create([
                'name' => $name,
                'slug' => strtolower(str_replace(' ', '-', $name)),
                'type' => Accommodation::TYPE_ROOM,
                'capacity' => 2,
                'price_per_night' => 2500,
                'description' => 'Dashboard accuracy test accommodation.',
                'status' => $status,
                'housekeeping_status' => $housekeeping,
            ]);
        };

        $arrival = $makeAccommodation('Arrival Room');
        $free = $makeAccommodation('Free Room');
        $occupied = $makeAccommodation('Occupied Room');
        $notReady = $makeAccommodation('Needs Cleaning Room', Accommodation::STATUS_AVAILABLE, Accommodation::HOUSEKEEPING_NEEDS_CLEANING);
        $unavailable = $makeAccommodation('Unavailable Room', Accommodation::STATUS_UNAVAILABLE);
        $maintenance = $makeAccommodation('Maintenance Room', Accommodation::STATUS_MAINTENANCE);

        Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $arrival->id,
            'check_in' => $today,
            'check_out' => $tomorrow,
            'guests' => 1,
            'total_amount' => 2500,
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-ACCURACY-ARRIVAL',
        ]);
        Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $occupied->id,
            'check_in' => today()->subDay()->toDateString(),
            'check_out' => $tomorrow,
            'guests' => 1,
            'total_amount' => 2500,
            'status' => Reservation::STATUS_CHECKED_IN,
            'booking_reference' => 'DMD-ACCURACY-OCCUPIED',
        ]);
        Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $notReady->id,
            'check_in' => $today,
            'check_out' => $tomorrow,
            'guests' => 1,
            'total_amount' => 2500,
            'status' => Reservation::STATUS_PENDING,
            'expires_at' => now()->addHour(),
            'booking_reference' => 'DMD-ACCURACY-PENDING',
        ]);
        Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $unavailable->id,
            'check_in' => $today,
            'check_out' => $tomorrow,
            'guests' => 1,
            'total_amount' => 2500,
            'status' => Reservation::STATUS_CANCELLED,
            'booking_reference' => 'DMD-ACCURACY-CANCELLED',
        ]);
        Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $maintenance->id,
            'check_in' => today()->subDay()->toDateString(),
            'check_out' => $today,
            'guests' => 1,
            'total_amount' => 2500,
            'status' => Reservation::STATUS_CHECKED_IN,
            'booking_reference' => 'DMD-ACCURACY-DEPARTURE',
        ]);

        $this->actingAs($frontDesk)
            ->getJson('/api/frontdesk/dashboard-summary')
            ->assertOk()
            ->assertJsonPath('data.summary.todays_check_ins', 1)
            ->assertJsonPath('data.summary.todays_check_outs', 1)
            ->assertJsonPath('data.summary.pending_bookings', 1)
            ->assertJsonPath('data.summary.available_accommodations', 1)
            ->assertJsonPath('data.summary.occupied_accommodations', 2)
            ->assertJsonPath('data.summary.guests_checked_in', 1)
            ->assertJsonCount(1, 'data.today_arrivals')
            ->assertJsonCount(1, 'data.today_departures')
            ->assertJsonPath('data.accommodation_status.available', 1)
            ->assertJsonPath('data.accommodation_status.needs_cleaning', 1)
            ->assertJsonPath('data.accommodation_status.maintenance', 1)
            ->assertJsonPath('data.accommodation_status.unavailable', 1);
    }

    public function test_front_desk_can_view_and_update_reservations_but_cannot_access_manager_or_admin_booking_apis(): void
    {
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = Accommodation::create([
            'name' => 'Front Desk Suite',
            'slug' => 'front-desk-suite',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 2,
            'price_per_night' => 3200,
            'description' => 'Room for front desk booking tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ]);
        $reservation = Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => today()->toDateString(),
            'check_out' => today()->addDay()->toDateString(),
            'guests' => 2,
            'total_amount' => 3200,
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-20260824-FD002',
        ]);

        $this->actingAs($frontDesk)
            ->getJson('/api/frontdesk/reservations')
            ->assertOk()
            ->assertJsonPath('data.0.booking_reference', 'DMD-20260824-FD002');

        $this->actingAs($frontDesk)
            ->getJson("/api/frontdesk/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.booking_reference', 'DMD-20260824-FD002');

        $this->actingAs($frontDesk)
            ->patchJson("/api/frontdesk/reservations/{$reservation->id}/status", [
                'status' => Reservation::STATUS_CONFIRMED,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CONFIRMED);

        $this->actingAs($frontDesk)
            ->getJson('/api/manager/reservations')
            ->assertForbidden();

        $this->actingAs($frontDesk)
            ->getJson('/api/admin/reservations')
            ->assertForbidden();
    }

    public function test_front_desk_accommodation_statuses_and_summary_use_operational_database_state(): void
    {
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $createAccommodation = function (string $name, string $status, string $housekeepingStatus) {
            return Accommodation::create([
                'name' => $name,
                'slug' => str($name)->slug(),
                'type' => Accommodation::TYPE_ROOM,
                'capacity' => 2,
                'price_per_night' => 2500,
                'description' => 'Front Desk status test accommodation.',
                'status' => $status,
                'housekeeping_status' => $housekeepingStatus,
                'image_path' => null,
            ]);
        };

        $available = $createAccommodation('Status Available Room', Accommodation::STATUS_AVAILABLE, Accommodation::HOUSEKEEPING_READY);
        $needsCleaning = $createAccommodation('Status Needs Cleaning Room', Accommodation::STATUS_AVAILABLE, Accommodation::HOUSEKEEPING_NEEDS_CLEANING);
        $occupied = $createAccommodation('Status Occupied Room', Accommodation::STATUS_AVAILABLE, Accommodation::HOUSEKEEPING_READY);
        $createAccommodation('Status Maintenance Room', Accommodation::STATUS_MAINTENANCE, Accommodation::HOUSEKEEPING_READY);
        $createAccommodation('Status Unavailable Room', Accommodation::STATUS_UNAVAILABLE, Accommodation::HOUSEKEEPING_READY);

        Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $occupied->id,
            'check_in' => today()->toDateString(),
            'check_out' => today()->addDay()->toDateString(),
            'guests' => 2,
            'total_amount' => 2500,
            'status' => Reservation::STATUS_CHECKED_IN,
            'booking_reference' => 'DMD-STATUS-FD001',
        ]);

        $this->actingAs($frontDesk)
            ->getJson('/api/frontdesk/accommodations')
            ->assertOk()
            ->assertJsonPath('meta.summary.total_accommodations', 5)
            ->assertJsonPath('meta.summary.available', 1)
            ->assertJsonPath('meta.summary.occupied', 1)
            ->assertJsonPath('meta.summary.maintenance', 2)
            ->assertJsonPath('meta.summary.needs_cleaning', 1)
            ->assertJsonFragment(['id' => $available->id, 'operational_status' => 'available'])
            ->assertJsonFragment(['id' => $needsCleaning->id, 'operational_status' => 'needs_cleaning'])
            ->assertJsonFragment(['id' => $occupied->id, 'operational_status' => 'occupied']);

        $this->actingAs($frontDesk)
            ->getJson('/api/frontdesk/accommodations?status=needs_cleaning')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $needsCleaning->id)
            ->assertJsonPath('data.0.operational_status', 'needs_cleaning');
    }

    public function test_front_desk_reservation_list_returns_database_booking_and_payment_values(): void
    {
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $guest = User::factory()->create([
            'role' => User::ROLE_GUEST,
            'name' => 'Accurate Booking Guest',
            'email' => 'accurate-guest@dmdresort.test',
        ]);
        $accommodation = Accommodation::create([
            'name' => 'Accurate Room',
            'slug' => 'accurate-room',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 5000,
            'description' => 'Reservation list accuracy test room.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ]);
        $reservation = Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => today()->addDays(3)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(),
            'guests' => 3,
            'total_amount' => 10000,
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-ACCURATE-FD001',
        ]);
        ReservationPayment::create([
            'reservation_id' => $reservation->id,
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            'provider' => 'paymongo',
            'amount' => 300000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->actingAs($frontDesk)
            ->getJson('/api/frontdesk/reservations?status=confirmed')
            ->assertOk()
            ->assertJsonPath('data.0.id', $reservation->id)
            ->assertJsonPath('data.0.booking_reference', 'DMD-ACCURATE-FD001')
            ->assertJsonPath('data.0.guest.name', 'Accurate Booking Guest')
            ->assertJsonPath('data.0.accommodation.name', 'Accurate Room')
            ->assertJsonPath('data.0.check_in', today()->addDays(3)->toDateString())
            ->assertJsonPath('data.0.check_out', today()->addDays(5)->toDateString())
            ->assertJsonPath('data.0.guests', 3)
            ->assertJsonPath('data.0.total_amount', 10000)
            ->assertJsonPath('data.0.status', Reservation::STATUS_CONFIRMED)
            ->assertJsonPath('data.0.payment_state', 'partially_paid')
            ->assertJsonPath('data.0.total_paid', '3000.00')
            ->assertJsonPath('data.0.balance_due', '7000.00');
    }

    public function test_front_desk_can_view_accommodations_but_non_staff_cannot(): void
    {
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $housekeeping = User::factory()->create(['role' => User::ROLE_HOUSEKEEPING]);
        $accommodation = Accommodation::create([
            'name' => 'Operational Room',
            'slug' => 'operational-room',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 3,
            'price_per_night' => 2800,
            'description' => 'Room for front desk accommodation tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ]);
        Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => today()->toDateString(),
            'check_out' => today()->addDay()->toDateString(),
            'guests' => 2,
            'total_amount' => 2800,
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-20260824-FD009',
        ]);

        $this->actingAs($frontDesk)
            ->getJson('/api/frontdesk/accommodations')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Operational Room')
            ->assertJsonPath('meta.summary.total_accommodations', 1);

        $this->actingAs($frontDesk)
            ->getJson("/api/frontdesk/accommodations/{$accommodation->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Operational Room')
            ->assertJsonPath('data.current_reservation.booking_reference', 'DMD-20260824-FD009');

        $this->actingAs($guest)
            ->getJson('/api/frontdesk/accommodations')
            ->assertForbidden();

        $this->actingAs($housekeeping)
            ->getJson('/api/frontdesk/accommodations')
            ->assertForbidden();
    }

    public function test_front_desk_can_view_visible_announcements_but_non_staff_cannot(): void
    {
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $housekeeping = User::factory()->create(['role' => User::ROLE_HOUSEKEEPING]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Announcement::create([
            'title' => 'Staff Notice',
            'content' => 'Front desk staff notice.',
            'type' => Announcement::TYPE_STAFF_NOTICE,
            'audience' => Announcement::AUDIENCE_STAFF,
            'status' => Announcement::STATUS_PUBLISHED,
            'publish_at' => now()->subDay(),
            'created_by' => $admin->id,
        ]);
        Announcement::create([
            'title' => 'Everyone Notice',
            'content' => 'Everyone-facing notice.',
            'type' => Announcement::TYPE_GENERAL,
            'audience' => Announcement::AUDIENCE_EVERYONE,
            'status' => Announcement::STATUS_PUBLISHED,
            'publish_at' => now()->subDay(),
            'created_by' => $admin->id,
        ]);
        Announcement::create([
            'title' => 'Draft Notice',
            'content' => 'Draft hidden notice.',
            'type' => Announcement::TYPE_GENERAL,
            'audience' => Announcement::AUDIENCE_EVERYONE,
            'status' => Announcement::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($frontDesk)
            ->getJson('/api/frontdesk/announcements')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['title' => 'Staff Notice'])
            ->assertJsonFragment(['title' => 'Everyone Notice'])
            ->assertJsonMissing(['title' => 'Draft Notice']);

        $visibleAnnouncement = Announcement::where('title', 'Staff Notice')->firstOrFail();

        $this->actingAs($frontDesk)
            ->getJson("/api/frontdesk/announcements/{$visibleAnnouncement->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Staff Notice');

        $this->actingAs($guest)
            ->getJson('/api/frontdesk/announcements')
            ->assertForbidden();

        $this->actingAs($housekeeping)
            ->getJson('/api/frontdesk/announcements')
            ->assertForbidden();

        $this->actingAs($frontDesk)
            ->postJson('/api/admin/announcements', [
                'title' => 'Nope',
                'content' => 'Nope',
                'type' => Announcement::TYPE_GENERAL,
                'audience' => Announcement::AUDIENCE_EVERYONE,
                'status' => Announcement::STATUS_DRAFT,
            ])
            ->assertForbidden();

        $this->actingAs($frontDesk)
            ->putJson("/api/admin/announcements/{$visibleAnnouncement->id}", [
                'title' => 'Nope',
                'content' => 'Nope',
                'type' => Announcement::TYPE_GENERAL,
                'audience' => Announcement::AUDIENCE_EVERYONE,
                'status' => Announcement::STATUS_DRAFT,
            ])
            ->assertForbidden();

        $this->actingAs($frontDesk)
            ->deleteJson("/api/admin/announcements/{$visibleAnnouncement->id}")
            ->assertStatus(405);
    }

    public function test_front_desk_can_check_in_and_check_out_with_existing_transition_rules(): void
    {
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = Accommodation::create([
            'name' => 'Transition Room',
            'slug' => 'transition-room',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 2,
            'price_per_night' => 2800,
            'description' => 'Room for check in/out tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ]);
        $confirmed = Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => today()->toDateString(),
            'check_out' => today()->addDay()->toDateString(),
            'guests' => 2,
            'total_amount' => 2800,
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-20260824-FD003',
        ]);
        $checkedIn = Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => today()->toDateString(),
            'check_out' => today()->addDay()->toDateString(),
            'guests' => 2,
            'total_amount' => 2800,
            'status' => Reservation::STATUS_CHECKED_IN,
            'booking_reference' => 'DMD-20260824-FD004',
        ]);
        ReservationPayment::create([
            'reservation_id' => $confirmed->id,
            'purpose' => ReservationPayment::PURPOSE_FULL,
            'provider' => 'paymongo',
            'amount' => 280000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->actingAs($frontDesk)
            ->patchJson("/api/frontdesk/reservations/{$confirmed->id}/status", [
                'status' => Reservation::STATUS_CHECKED_IN,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CHECKED_IN);

        $this->actingAs($frontDesk)
            ->patchJson("/api/frontdesk/reservations/{$checkedIn->id}/status", [
                'status' => Reservation::STATUS_CHECKED_OUT,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CHECKED_OUT);
    }

    public function test_front_desk_checkout_records_actual_time_for_completed_today_and_manila_boundaries(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 12, 23, 30, 0, 'Asia/Manila'));

        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = Accommodation::create([
            'name' => 'Actual Checkout Room',
            'slug' => 'actual-checkout-room',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 2,
            'price_per_night' => 2800,
            'description' => 'Room for actual checkout tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
        ]);

        $scheduledDeparture = Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-09-10',
            'check_out' => '2026-09-12',
            'guests' => 2,
            'total_amount' => 2800,
            'status' => Reservation::STATUS_CHECKED_IN,
            'booking_reference' => 'DMD-20260912-FD-ACTUAL-01',
        ]);

        $earlyDeparture = Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-09-10',
            'check_out' => '2026-09-14',
            'guests' => 2,
            'total_amount' => 5600,
            'status' => Reservation::STATUS_CHECKED_IN,
            'booking_reference' => 'DMD-20260912-FD-ACTUAL-02',
        ]);

        $beforeCheckout = $this->actingAs($frontDesk)
            ->getJson('/api/frontdesk/reservations')
            ->assertOk();

        $this->assertContains(
            $scheduledDeparture->booking_reference,
            collect($beforeCheckout->json('data'))->pluck('booking_reference')->all(),
        );

        $this->actingAs($frontDesk)
            ->patchJson("/api/frontdesk/reservations/{$scheduledDeparture->id}/status", [
                'status' => Reservation::STATUS_CHECKED_OUT,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', Reservation::STATUS_CHECKED_OUT);

        $scheduledDeparture->refresh();
        $this->assertSame(Reservation::STATUS_CHECKED_OUT, $scheduledDeparture->status);
        $this->assertNotNull($scheduledDeparture->check_out_at);
        $this->assertSame('2026-09-12', $scheduledDeparture->check_out_at->setTimezone('Asia/Manila')->toDateString());

        $this->actingAs($frontDesk)
            ->patchJson("/api/frontdesk/reservations/{$earlyDeparture->id}/status", [
                'status' => Reservation::STATUS_CHECKED_OUT,
            ])
            ->assertOk();

        $earlyDeparture->refresh();
        $this->assertSame('2026-09-12', $earlyDeparture->check_out_at->setTimezone('Asia/Manila')->toDateString());
        $this->assertSame(
            Accommodation::HOUSEKEEPING_NEEDS_CLEANING,
            $accommodation->refresh()->housekeeping_status,
        );
    }

    public function test_invalid_reservations_cannot_be_checked_in_again(): void
    {
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = Accommodation::create([
            'name' => 'Blocked Room',
            'slug' => 'blocked-room',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 2,
            'price_per_night' => 2800,
            'description' => 'Room for invalid transition tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ]);

        $pending = Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => today()->toDateString(),
            'check_out' => today()->addDay()->toDateString(),
            'guests' => 2,
            'total_amount' => 2800,
            'status' => Reservation::STATUS_PENDING,
            'booking_reference' => 'DMD-20260824-FD005',
        ]);
        $cancelled = Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => today()->toDateString(),
            'check_out' => today()->addDay()->toDateString(),
            'guests' => 2,
            'total_amount' => 2800,
            'status' => Reservation::STATUS_CANCELLED,
            'booking_reference' => 'DMD-20260824-FD006',
        ]);
        $checkedOut = Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => today()->subDay()->toDateString(),
            'check_out' => today()->toDateString(),
            'guests' => 2,
            'total_amount' => 2800,
            'status' => Reservation::STATUS_CHECKED_OUT,
            'booking_reference' => 'DMD-20260824-FD007',
        ]);

        $this->actingAs($frontDesk)
            ->patchJson("/api/frontdesk/reservations/{$pending->id}/status", [
                'status' => Reservation::STATUS_CHECKED_IN,
            ])
            ->assertUnprocessable();

        $this->actingAs($frontDesk)
            ->patchJson("/api/frontdesk/reservations/{$cancelled->id}/status", [
                'status' => Reservation::STATUS_CHECKED_IN,
            ])
            ->assertUnprocessable();

        $this->actingAs($frontDesk)
            ->patchJson("/api/frontdesk/reservations/{$checkedOut->id}/status", [
                'status' => Reservation::STATUS_CHECKED_IN,
            ])
            ->assertUnprocessable();
    }

    public function test_housekeeping_staff_cannot_access_front_desk_apis(): void
    {
        $housekeeping = User::factory()->create(['role' => User::ROLE_HOUSEKEEPING]);
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = Accommodation::create([
            'name' => 'Housekeeping Blocked Room',
            'slug' => 'housekeeping-blocked-room',
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 2,
            'price_per_night' => 2800,
            'description' => 'Room for access control tests.',
            'status' => Accommodation::STATUS_AVAILABLE,
            'image_path' => null,
        ]);
        $reservation = Reservation::create([
            'user_id' => $guest->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => today()->toDateString(),
            'check_out' => today()->addDay()->toDateString(),
            'guests' => 2,
            'total_amount' => 2800,
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-20260824-FD008',
        ]);

        $this->actingAs($housekeeping)
            ->getJson('/api/frontdesk/reservations')
            ->assertForbidden();

        $this->actingAs($housekeeping)
            ->patchJson("/api/frontdesk/reservations/{$reservation->id}/status", ['status' => Reservation::STATUS_CHECKED_IN])
            ->assertForbidden();
    }
}
