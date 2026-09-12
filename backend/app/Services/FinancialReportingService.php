<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinancialReportingService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(?CarbonImmutable $startDate = null, ?CarbonImmutable $endDate = null): array
    {
        $paidPayments = DB::table('reservation_payments')
            ->select('reservation_id')
            ->selectRaw('COALESCE(SUM(amount), 0) AS total_paid_minor')
            ->where('status', ReservationPayment::STATUS_PAID)
            ->groupBy('reservation_id');

        $reservations = DB::table('reservations')
            ->leftJoinSub($paidPayments, 'paid_totals', 'paid_totals.reservation_id', '=', 'reservations.id')
            ->where('reservations.status', '!=', Reservation::STATUS_EXPIRED)
            ->when($startDate && $endDate, fn ($query) => $query->whereBetween('reservations.created_at', [
                $startDate->startOfDay(),
                $endDate->endOfDay(),
            ]));

        $stateCounts = (clone $reservations)
            ->selectRaw("COUNT(*) AS total, SUM(CASE WHEN COALESCE(paid_totals.total_paid_minor, 0) <= 0 THEN 1 ELSE 0 END) AS unpaid, SUM(CASE WHEN COALESCE(paid_totals.total_paid_minor, 0) > 0 AND COALESCE(paid_totals.total_paid_minor, 0) < reservations.total_amount * 100 THEN 1 ELSE 0 END) AS partially_paid, SUM(CASE WHEN COALESCE(paid_totals.total_paid_minor, 0) >= reservations.total_amount * 100 THEN 1 ELSE 0 END) AS fully_paid")
            ->first();

        $bookingValue = (clone $reservations)->sum('reservations.total_amount');

        $outstandingMinor = (clone $reservations)
            ->where(function ($query) {
                $query
                    ->whereIn('reservations.status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_CHECKED_IN])
                    ->orWhere(function ($pendingQuery) {
                        $pendingQuery
                            ->where('reservations.status', Reservation::STATUS_PENDING)
                            ->where(function ($expiryQuery) {
                                $expiryQuery->whereNull('reservations.expires_at')->orWhere('reservations.expires_at', '>', now());
                            });
                    });
            })
            ->selectRaw('COALESCE(SUM(CASE WHEN reservations.total_amount * 100 > COALESCE(paid_totals.total_paid_minor, 0) THEN reservations.total_amount * 100 - COALESCE(paid_totals.total_paid_minor, 0) ELSE 0 END), 0) AS outstanding_minor')
            ->value('outstanding_minor');

        $paidQuery = DB::table('reservation_payments')
            ->where('status', ReservationPayment::STATUS_PAID)
            ->when($startDate && $endDate, fn ($query) => $query->whereBetween('paid_at', [
                $startDate->startOfDay(),
                $endDate->endOfDay(),
            ]));

        $collectedMinor = $paidQuery->sum('amount');

        $refundsMinor = DB::table('reservations')
            ->where('refunded_amount_minor', '>', 0)
            ->when($startDate && $endDate, fn ($query) => $query->whereBetween('refunded_at', [
                $startDate->startOfDay(),
                $endDate->endOfDay(),
            ]))
            ->sum('refunded_amount_minor');

        $methodBreakdown = (clone $paidQuery)
            ->select('provider', 'payment_method')
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount_minor')
            ->groupBy('provider', 'payment_method')
            ->get()
            ->groupBy(fn ($row) => $this->collectionSource($row->provider, $row->payment_method))
            ->map(fn ($rows, $source) => [
                'source' => $source,
                'amount' => Reservation::minorUnitsToCurrency((int) $rows->sum('amount_minor')),
            ])
            ->values()
            ->all();

        return [
            'booking_value' => Reservation::minorUnitsToCurrency(Reservation::currencyToMinorUnits((string) $bookingValue)),
            'collected_revenue' => Reservation::minorUnitsToCurrency((int) $collectedMinor),
            'refunds_processed' => Reservation::minorUnitsToCurrency((int) $refundsMinor),
            'net_collected_revenue' => Reservation::minorUnitsToCurrency(max(0, (int) $collectedMinor - (int) $refundsMinor)),
            'outstanding_balance' => Reservation::minorUnitsToCurrency((int) $outstandingMinor),
            'fully_paid_bookings' => (int) ($stateCounts->fully_paid ?? 0),
            'partially_paid_bookings' => (int) ($stateCounts->partially_paid ?? 0),
            'unpaid_bookings' => (int) ($stateCounts->unpaid ?? 0),
            'payment_method_breakdown' => $methodBreakdown,
            'outstanding_balances' => $this->outstandingBalances($startDate, $endDate),
            'revenue_trend' => $this->revenueTrend($startDate, $endDate),
            'refund_activity' => $this->refundActivity($startDate, $endDate),
        ];
    }

    /**
     * Collected revenue is grouped by the local business date on which a
     * verified payment was recorded. The source rows are the same paid rows
     * used by summary(), so the chart cannot drift from the KPI.
     *
     * @return list<array{date: string, label: string, amount: string}>
     */
    private function revenueTrend(?CarbonImmutable $startDate, ?CarbonImmutable $endDate): array
    {
        if (! $startDate || ! $endDate) {
            return [];
        }

        $rows = DB::table('reservation_payments')
            ->where('status', ReservationPayment::STATUS_PAID)
            ->whereBetween('paid_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->selectRaw("DATE(paid_at) AS payment_date, COALESCE(SUM(amount), 0) AS amount_minor")
            ->groupByRaw('DATE(paid_at)')
            ->pluck('amount_minor', 'payment_date');

        $trend = [];
        for ($date = $startDate; $date->lte($endDate); $date = $date->addDay()) {
            $key = $date->toDateString();
            $trend[] = [
                'date' => $key,
                'label' => $date->format('M d'),
                'amount' => Reservation::minorUnitsToCurrency((int) ($rows[$key] ?? 0)),
            ];
        }

        return $trend;
    }

    /**
     * Refund activity reflects only refunds actually recorded by authorized
     * staff. Eligibility/estimates remain reservation-level policy data and
     * are intentionally not counted as processed refunds.
     *
     * @return list<array<string, mixed>>
     */
    private function refundActivity(?CarbonImmutable $startDate, ?CarbonImmutable $endDate): array
    {
        return Reservation::query()
            ->with('accommodation:id,name')
            ->where('refunded_amount_minor', '>', 0)
            ->when($startDate && $endDate, fn ($query) => $query->whereBetween('refunded_at', [
                $startDate->startOfDay(),
                $endDate->endOfDay(),
            ]))
            ->latest('refunded_at')
            ->limit(100)
            ->get()
            ->map(fn (Reservation $reservation) => [
                'date' => $reservation->refunded_at?->toISOString(),
                'booking_reference' => $reservation->booking_reference,
                'accommodation' => $reservation->accommodation?->name,
                'eligible_deposit' => Reservation::minorUnitsToCurrency((int) $reservation->eligible_down_payment_amount_minor),
                'refund_amount' => Reservation::minorUnitsToCurrency((int) $reservation->refunded_amount_minor),
                'status' => $reservation->refund_status,
                'reference' => $reservation->refund_reference,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function outstandingBalances(?CarbonImmutable $startDate, ?CarbonImmutable $endDate): array
    {
        $paidPayments = DB::table('reservation_payments')
            ->select('reservation_id')
            ->selectRaw('COALESCE(SUM(amount), 0) AS total_paid_minor')
            ->where('status', ReservationPayment::STATUS_PAID)
            ->groupBy('reservation_id');

        return DB::table('reservations')
            ->leftJoinSub($paidPayments, 'paid_totals', 'paid_totals.reservation_id', '=', 'reservations.id')
            ->leftJoin('users', 'users.id', '=', 'reservations.user_id')
            ->leftJoin('accommodations', 'accommodations.id', '=', 'reservations.accommodation_id')
            ->where(function ($query) {
                $query
                    ->whereIn('reservations.status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_CHECKED_IN])
                    ->orWhere(function ($pendingQuery) {
                        $pendingQuery
                            ->where('reservations.status', Reservation::STATUS_PENDING)
                            ->where(function ($expiryQuery) {
                                $expiryQuery->whereNull('reservations.expires_at')->orWhere('reservations.expires_at', '>', now());
                            });
                    });
            })
            ->when($startDate && $endDate, fn ($query) => $query->whereBetween('reservations.created_at', [
                $startDate->startOfDay(),
                $endDate->endOfDay(),
            ]))
            ->whereRaw('reservations.total_amount * 100 > COALESCE(paid_totals.total_paid_minor, 0)')
            ->select([
                'reservations.id',
                'reservations.booking_reference',
                'reservations.status',
                'reservations.total_amount',
                'users.name AS customer',
                'accommodations.name AS accommodation',
            ])
            ->selectRaw('COALESCE(paid_totals.total_paid_minor, 0) AS total_paid_minor')
            ->selectRaw('(reservations.total_amount * 100) - COALESCE(paid_totals.total_paid_minor, 0) AS balance_due_minor')
            ->orderByDesc('balance_due_minor')
            ->orderBy('reservations.booking_reference')
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'booking_reference' => $row->booking_reference,
                'customer' => $row->customer,
                'accommodation' => $row->accommodation,
                'reservation_status' => $row->status,
                'booking_value' => Reservation::minorUnitsToCurrency(Reservation::currencyToMinorUnits((string) $row->total_amount)),
                'amount_paid' => Reservation::minorUnitsToCurrency((int) $row->total_paid_minor),
                'balance_due' => Reservation::minorUnitsToCurrency((int) $row->balance_due_minor),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function paymentTransactions(?CarbonImmutable $startDate = null, ?CarbonImmutable $endDate = null): array
    {
        return ReservationPayment::query()
            ->with(['reservation:id,booking_reference,user_id', 'reservation.user:id,name'])
            ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
                $query->where(function ($dateQuery) use ($startDate, $endDate) {
                    $dateQuery
                        ->where(function ($paidQuery) use ($startDate, $endDate) {
                            $paidQuery->where('status', ReservationPayment::STATUS_PAID)
                                ->whereBetween('paid_at', [$startDate->startOfDay(), $endDate->endOfDay()]);
                        })
                        ->orWhere(function ($createdQuery) use ($startDate, $endDate) {
                            $createdQuery->where('status', '!=', ReservationPayment::STATUS_PAID)
                                ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()]);
                        });
                });
            })
            ->latest('id')
            ->limit(500)
            ->get()
            ->map(fn (ReservationPayment $payment) => [
                'id' => $payment->id,
                'booking_reference' => $payment->reservation?->booking_reference,
                'customer' => $payment->reservation?->user?->name,
                'purpose' => $payment->purpose,
                'source' => $this->collectionSource($payment->provider, $payment->payment_method),
                'amount' => Reservation::minorUnitsToCurrency((int) $payment->amount),
                'currency' => strtoupper((string) ($payment->currency ?: 'PHP')),
                'status' => $payment->status,
                'payment_date' => $payment->paid_at?->toISOString(),
                'created_at' => $payment->created_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

    private function collectionSource(?string $provider, ?string $paymentMethod): string
    {
        if ($provider === 'manual' || $paymentMethod === 'cash') {
            return 'Cash';
        }

        if ($provider === 'paymongo') {
            return 'Online / PayMongo';
        }

        return $provider ? ucfirst($provider) : 'Other';
    }
}
