<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\GuestFeedback;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestMoodFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function reservation(?User $user = null, string $status = Reservation::STATUS_CHECKED_OUT, array $extra = []): Reservation
    {
        $accommodation = Accommodation::create([
            'name' => 'Mood Room', 'slug' => 'mood-'.Str::lower(Str::random(8)), 'type' => 'room',
            'capacity' => 4, 'price_per_night' => 2500, 'description' => 'Mood test room', 'status' => 'available',
        ]);

        return Reservation::create(array_merge([
            'user_id' => $user?->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-02',
            'check_in_at' => '2026-09-01 14:00:00', 'check_out_at' => '2026-09-02 14:00:00',
            'scheduled_check_in_at' => '2026-09-01 14:00:00', 'guests' => 2, 'adults' => 2,
            'children' => 0, 'infants' => 0, 'total_amount' => '2500.00', 'status' => $status,
            'booking_reference' => 'DMD-MOOD-'.Str::upper(Str::random(8)),
        ], $extra));
    }

    public function test_checked_out_registered_customer_can_submit_feedback_once(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->reservation($user);

        $this->actingAs($user)->postJson("/api/reservations/{$reservation->id}/feedback", [
            'rating' => 5, 'comment' => 'The room was clean and welcoming.',
        ])->assertOk()->assertJsonPath('data.rating', 5);

        $this->assertDatabaseHas('guest_feedback', ['reservation_id' => $reservation->id, 'user_id' => $user->id, 'rating' => 5]);
        $this->actingAs($user)->postJson("/api/reservations/{$reservation->id}/feedback", ['rating' => 4])
            ->assertUnprocessable()->assertJsonValidationErrors('feedback');
    }

    public function test_guest_can_submit_feedback_with_permanent_access_token(): void
    {
        $token = Str::random(64);
        $reservation = $this->reservation(null, Reservation::STATUS_CHECKED_OUT, [
            'guest_first_name' => 'Juan', 'guest_last_name' => 'Guest', 'guest_email' => 'juan@example.test',
            'guest_access_token_hash' => hash('sha256', $token), 'guest_access_token_issued_at' => now(),
        ]);

        $this->withHeader('X-Guest-Access-Token', $token)->postJson('/api/guest/booking/feedback', ['rating' => 4])
            ->assertOk()->assertJsonPath('data.rating', 4);
        $this->assertDatabaseHas('guest_feedback', ['reservation_id' => $reservation->id, 'user_id' => null, 'rating' => 4]);
    }

    public function test_feedback_requires_checked_out_status_and_valid_authorization(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_GUEST]);
        $other = User::factory()->create(['role' => User::ROLE_GUEST]);
        foreach ([Reservation::STATUS_PENDING, Reservation::STATUS_CONFIRMED, Reservation::STATUS_CHECKED_IN, Reservation::STATUS_CANCELLED, Reservation::STATUS_EXPIRED] as $status) {
            $reservation = $this->reservation($user, $status);
            $this->actingAs($user)->postJson("/api/reservations/{$reservation->id}/feedback", ['rating' => 3])->assertUnprocessable();
        }

        $reservation = $this->reservation($other);
        $this->actingAs($user)->postJson("/api/reservations/{$reservation->id}/feedback", ['rating' => 3])->assertNotFound();
        $this->withHeader('X-Guest-Access-Token', Str::random(64))->postJson('/api/guest/booking/feedback', ['rating' => 3])->assertUnauthorized();
    }

    public function test_rating_validation_and_front_desk_cannot_submit_feedback(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_GUEST]);
        $reservation = $this->reservation($user);
        foreach ([0, 6, 'bad'] as $rating) {
            $this->actingAs($user)->postJson("/api/reservations/{$reservation->id}/feedback", ['rating' => $rating])
                ->assertUnprocessable()->assertJsonValidationErrors('rating');
        }

        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $this->actingAs($frontDesk)->postJson("/api/reservations/{$reservation->id}/feedback", ['rating' => 5])->assertForbidden();
    }

    public function test_mood_analytics_uses_checked_out_feedback_and_handles_zero_responses(): void
    {
        $guest = User::factory()->create(['role' => User::ROLE_GUEST]);
        foreach ([5, 5, 4, 4, 3] as $rating) {
            $reservation = $this->reservation($guest);
            GuestFeedback::create(['reservation_id' => $reservation->id, 'user_id' => $guest->id, 'rating' => $rating, 'submitted_at' => now()]);
        }

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->getJson('/api/admin/reports?preset=this_month')
            ->assertOk()->assertJsonPath('data.guest_mood.response_count', 5)
            ->assertJsonPath('data.guest_mood.average_rating', 4.2)
            ->assertJsonPath('data.guest_mood.mood_score', 84)
            ->assertJsonPath('data.guest_mood.mood.label', 'Good');

        $manager = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $this->actingAs($manager)->getJson('/api/manager/reports?preset=this_month')->assertOk()->assertJsonPath('data.guest_mood.response_count', 5);

        $this->assertSame(5, GuestFeedback::count());
    }

    public function test_mood_analytics_returns_safe_empty_state_without_feedback(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->getJson('/api/admin/reports?preset=this_month')
            ->assertOk()
            ->assertJsonPath('data.guest_mood.response_count', 0)
            ->assertJsonPath('data.guest_mood.average_rating', null)
            ->assertJsonPath('data.guest_mood.mood_score', null)
            ->assertJsonPath('data.guest_mood.mood', null);
    }
}
