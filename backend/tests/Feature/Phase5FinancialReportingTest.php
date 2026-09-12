<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase5FinancialReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_partially_paid_financial_summary_separates_value_collected_and_outstanding(): void
    {
        [$reservation] = $this->createPartiallyPaidReservation();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->getJson('/api/admin/reports?preset=custom&start_date=2026-09-01&end_date=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.financial.booking_value', '10000.00')
            ->assertJsonPath('data.financial.collected_revenue', '3000.00')
            ->assertJsonPath('data.financial.outstanding_balance', '7000.00')
            ->assertJsonPath('data.financial.partially_paid_bookings', 1)
            ->assertJsonPath('data.financial.fully_paid_bookings', 0)
            ->assertJsonPath('data.financial.outstanding_balances.0.booking_reference', $reservation->booking_reference)
            ->assertJsonPath('data.financial.outstanding_balances.0.balance_due', '7000.00');

        $this->assertNotNull($reservation);
    }

    public function test_paid_deposit_and_cash_balance_are_counted_once_and_split_by_source(): void
    {
        [$reservation] = $this->createPartiallyPaidReservation();
        $reservation->payments()->create([
            'purpose' => ReservationPayment::PURPOSE_BALANCE,
            'provider' => 'manual',
            'payment_method' => 'cash',
            'amount' => 700000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create(['role' => User::ROLE_MANAGER]))
            ->getJson('/api/manager/reports?preset=custom&start_date=2026-09-01&end_date=2026-09-30')
            ->assertOk();

        $response->assertJsonPath('data.financial.collected_revenue', '10000.00')
            ->assertJsonPath('data.financial.outstanding_balance', '0.00')
            ->assertJsonPath('data.financial.fully_paid_bookings', 1);

        $breakdown = collect($response->json('data.financial.payment_method_breakdown'))->keyBy('source');
        $this->assertSame('3000.00', $breakdown->get('Online / PayMongo')['amount']);
        $this->assertSame('7000.00', $breakdown->get('Cash')['amount']);
    }

    public function test_pending_failed_and_cancelled_transactions_do_not_count_as_collected(): void
    {
        [$reservation] = $this->createPartiallyPaidReservation();
        foreach ([ReservationPayment::STATUS_PENDING, ReservationPayment::STATUS_FAILED, ReservationPayment::STATUS_CANCELLED] as $status) {
            $reservation->payments()->create([
                'purpose' => ReservationPayment::PURPOSE_BALANCE,
                'provider' => 'paymongo',
                'amount' => 700000,
                'currency' => 'PHP',
                'status' => $status,
                'created_at' => now(),
            ]);
        }

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->getJson('/api/admin/reports?preset=custom&start_date=2026-09-01&end_date=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.financial.collected_revenue', '3000.00')
            ->assertJsonPath('data.financial.outstanding_balance', '7000.00');
    }

    public function test_collected_revenue_uses_paid_at_date_not_reservation_created_at(): void
    {
        [$reservation] = $this->createPartiallyPaidReservation();
        $reservation->payments()->first()->forceFill([
            'paid_at' => CarbonImmutable::parse('2026-09-10 10:00:00'),
        ])->save();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->getJson('/api/admin/reports?preset=custom&start_date=2026-09-01&end_date=2026-09-01')
            ->assertOk()
            ->assertJsonPath('data.financial.collected_revenue', '0.00');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->getJson('/api/admin/reports?preset=custom&start_date=2026-09-10&end_date=2026-09-10')
            ->assertOk()
            ->assertJsonPath('data.financial.collected_revenue', '3000.00');
    }

    public function test_admin_and_manager_can_view_sanitized_staff_payment_history(): void
    {
        [$reservation] = $this->createPartiallyPaidReservation();
        $frontDesk = User::factory()->create(['role' => User::ROLE_FRONT_DESK]);
        $reservation->payments()->create([
            'purpose' => ReservationPayment::PURPOSE_BALANCE,
            'provider' => 'manual',
            'payment_method' => 'cash',
            'amount' => 700000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'recorded_by_user_id' => $frontDesk->id,
            'paid_at' => now(),
            'payload' => ['secret' => 'never expose'],
        ]);

        foreach ([User::ROLE_ADMIN, User::ROLE_MANAGER] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson($role === User::ROLE_ADMIN
                    ? "/api/admin/reservations/{$reservation->id}"
                    : "/api/manager/reservations/{$reservation->id}")
                ->assertOk()
                ->assertJsonPath('data.payment_history.0.recorded_by.id', $frontDesk->id)
                ->assertJsonMissingPath('data.payment_history.0.payload')
                ->assertJsonMissingPath('data.payment_history.0.checkout_session_id');
        }

        $this->actingAs($reservation->user)
            ->getJson("/api/admin/reservations/{$reservation->id}")
            ->assertForbidden();
    }

    public function test_export_contains_reconciled_financial_columns(): void
    {
        [$reservation] = $this->createPartiallyPaidReservation();

        $response = $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/api/admin/reports/export?preset=custom&start_date=2026-09-01&end_date=2026-09-30')
            ->assertOk();

        $content = $response->streamedContent();
        $this->assertStringContainsString('"Booking Value","Amount Paid","Balance Due","Payment State"', $content);
        $this->assertStringContainsString('10000.00,3000.00,7000.00,partially_paid', $content);
        $this->assertNotNull($reservation);
    }

    public function test_report_exposes_processed_refunds_and_net_collected_revenue(): void
    {
        [$reservation] = $this->createPartiallyPaidReservation();
        $reservation->forceFill([
            'refund_eligible' => true,
            'eligible_down_payment_amount_minor' => 300000,
            'refunded_amount_minor' => 150000,
            'refunded_at' => CarbonImmutable::parse('2026-09-02 10:00:00'),
            'refund_status' => Reservation::REFUND_STATUS_REFUNDED,
            'refund_reference' => 'MANUAL-REPORT-1',
        ])->save();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->getJson('/api/admin/reports?preset=custom&start_date=2026-09-01&end_date=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.financial.refunds_processed', '1500.00')
            ->assertJsonPath('data.financial.net_collected_revenue', '1500.00')
            ->assertJsonPath('data.financial.refund_activity.0.booking_reference', $reservation->booking_reference)
            ->assertJsonPath('data.financial.revenue_trend.0.amount', '3000.00');
    }

    /** @return array{0: Reservation} */
    private function createPartiallyPaidReservation(): array
    {
        $customer = User::factory()->create(['role' => User::ROLE_GUEST]);
        $accommodation = Accommodation::create([
            'name' => 'Phase 5 Test Room',
            'slug' => 'phase-5-test-'.uniqid(),
            'type' => Accommodation::TYPE_ROOM,
            'capacity' => 4,
            'price_per_night' => 5000,
            'description' => 'Phase 5 test accommodation.',
            'status' => Accommodation::STATUS_AVAILABLE,
        ]);
        $reservation = Reservation::create([
            'user_id' => $customer->id,
            'accommodation_id' => $accommodation->id,
            'check_in' => '2026-09-10',
            'check_out' => '2026-09-12',
            'guests' => 2,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'total_amount' => '10000.00',
            'status' => Reservation::STATUS_CONFIRMED,
            'booking_reference' => 'DMD-PHASE5-'.strtoupper(substr(uniqid(), -7)),
            'created_at' => CarbonImmutable::parse('2026-09-01 09:00:00'),
        ]);
        $reservation->payments()->create([
            'purpose' => ReservationPayment::PURPOSE_DEPOSIT,
            'provider' => 'paymongo',
            'payment_method' => 'card',
            'amount' => 300000,
            'currency' => 'PHP',
            'status' => ReservationPayment::STATUS_PAID,
            'paid_at' => CarbonImmutable::parse('2026-09-01 10:00:00'),
        ]);

        return [$reservation->fresh()];
    }
}
