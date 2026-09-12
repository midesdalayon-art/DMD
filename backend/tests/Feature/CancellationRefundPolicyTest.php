<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\User;
use App\Services\CancellationRefundService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancellationRefundPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function reservation(array $overrides = []): Reservation
    {
        $user = User::factory()->create();
        $accommodation = Accommodation::create([
            'name' => 'Policy Room', 'slug' => 'policy-room-'.uniqid(), 'type' => 'room',
            'capacity' => 4, 'price_per_night' => 10000, 'description' => 'Test room', 'status' => 'available',
        ]);

        return Reservation::create(array_merge([
            'user_id' => $user->id, 'accommodation_id' => $accommodation->id,
            'check_in' => '2026-09-10', 'check_out' => '2026-09-11',
            'check_in_at' => '2026-09-10 13:00:00', 'check_out_at' => '2026-09-11 13:00:00',
            'scheduled_check_in_at' => '2026-09-10 13:00:00', 'guests' => 1,
            'total_amount' => '10000.00', 'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-POLICY-'.uniqid(),
        ], $overrides));
    }

    private function deposit(Reservation $reservation, int $amount = 300000, string $status = ReservationPayment::STATUS_PAID): void
    {
        $reservation->payments()->create([
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT, 'provider' => 'paymongo',
            'amount' => $amount, 'currency' => 'PHP', 'status' => $status,
            'payment_method' => 'card', 'paid_at' => $status === ReservationPayment::STATUS_PAID ? now() : null,
        ]);
    }

    public function test_exactly_72_hours_is_eligible_and_refunds_half_paid_deposit(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 13:00:00', config('app.timezone'));
        $reservation = $this->reservation();
        $this->deposit($reservation);

        $result = app(CancellationRefundService::class)->calculate($reservation->fresh());

        $this->assertTrue($result['refund_eligible']);
        $this->assertSame(150000, $result['estimated_refund_amount_minor']);
        $this->assertSame(150000, $result['estimated_retained_amount_minor']);
    }

    public function test_one_second_after_deadline_is_not_eligible(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 13:00:01', config('app.timezone'));
        $reservation = $this->reservation();
        $this->deposit($reservation);

        $result = app(CancellationRefundService::class)->calculate($reservation->fresh());

        $this->assertFalse($result['refund_eligible']);
        $this->assertSame(0, $result['estimated_refund_amount_minor']);
        $this->assertSame(300000, $result['estimated_retained_amount_minor']);
    }

    public function test_only_paid_deposit_transactions_are_used_and_payment_history_is_preserved(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 12:59:00', config('app.timezone'));
        $reservation = $this->reservation();
        $this->deposit($reservation, 300000, ReservationPayment::STATUS_PENDING);

        $result = app(CancellationRefundService::class)->calculate($reservation->fresh());

        $this->assertFalse($result['refund_eligible']);
        $this->assertSame(0, $result['eligible_down_payment_amount_minor']);
        $this->assertDatabaseCount('reservation_payments', 1);
    }

    public function test_client_supplied_refund_values_are_not_used(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 13:00:00', config('app.timezone'));
        $reservation = $this->reservation();
        $this->deposit($reservation);

        $this->actingAs($reservation->user)->postJson("/api/reservations/{$reservation->id}/cancel", [
            'reason' => 'Travel plans changed.',
            'refundable_amount' => '999999.99',
            'refund_percentage' => 100,
            'cancellation_deadline' => '2099-01-01 00:00:00',
        ])->assertOk();

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'estimated_refund_amount_minor' => 150000,
            'estimated_retained_amount_minor' => 150000,
            'refund_status' => Reservation::REFUND_STATUS_PENDING,
        ]);
    }
}
