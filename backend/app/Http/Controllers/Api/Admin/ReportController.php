<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\AttendanceRecord;
use App\Models\HousekeepingTask;
use App\Models\InventoryAsset;
use App\Models\GuestFeedback;
use App\Models\Reservation;
use App\Models\User;
use App\Services\FinancialReportingService;
use App\Services\GuestMoodService;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function summary(Request $request, FinancialReportingService $financialReporting, GuestMoodService $guestMood): JsonResponse
    {
        $data = $this->reportData($request, $financialReporting, $guestMood);
        unset($data['reservation_rows'], $data['feedback_rows']);

        return response()->json(['data' => $data]);
    }

    public function export(Request $request, FinancialReportingService $financialReporting, GuestMoodService $guestMood, AuditLogger $auditLogger): StreamedResponse
    {
        $data = $this->reportData($request, $financialReporting, $guestMood);
        $reservations = $data['reservation_rows'];
        $this->logExport($auditLogger, $request, 'csv', $data['period']);

        return response()->streamDownload(function () use ($reservations) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Booking Reference', 'Guest', 'Accommodation', 'Check In', 'Check Out', 'Guests', 'Adults', 'Children', 'Infants', 'Booking Value', 'Amount Paid', 'Balance Due', 'Payment State', 'Status', 'Created']);

            foreach ($reservations as $reservation) {
                fputcsv($handle, [
                    $reservation->booking_reference,
                    $reservation->user?->name ?: trim($reservation->guest_first_name.' '.$reservation->guest_last_name),
                    $reservation->accommodation?->name,
                    $reservation->check_in?->format('Y-m-d'),
                    $reservation->check_out?->format('Y-m-d'),
                    $reservation->guests,
                    $reservation->adults,
                    $reservation->children,
                    $reservation->infants,
                    Reservation::minorUnitsToCurrency($reservation->totalAmountMinor()),
                    Reservation::minorUnitsToCurrency($reservation->totalPaidMinor()),
                    Reservation::minorUnitsToCurrency($reservation->balanceDueMinor()),
                    $reservation->paymentState(),
                    $reservation->status,
                    $reservation->created_at?->toISOString(),
                ]);
            }

            fclose($handle);
        }, $this->filename($data['period'], 'csv'), [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportPdf(Request $request, FinancialReportingService $financialReporting, GuestMoodService $guestMood, AuditLogger $auditLogger)
    {
        $data = $this->reportData($request, $financialReporting, $guestMood);
        $this->logExport($auditLogger, $request, 'pdf', $data['period']);
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('reports.management', [
            'data' => $data,
            'generatedBy' => $request->user()?->name ?? 'Administrator',
            'generatedAt' => now(config('app.timezone')),
        ])->render());
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($data['period'], 'pdf').'"',
        ]);
    }

    public function exportExcel(Request $request, FinancialReportingService $financialReporting, GuestMoodService $guestMood, AuditLogger $auditLogger)
    {
        $data = $this->reportData($request, $financialReporting, $guestMood);
        $this->logExport($auditLogger, $request, 'excel', $data['period']);
        $spreadsheet = new Spreadsheet();
        $this->fillSummarySheet($spreadsheet->getActiveSheet(), $data, $request->user()?->name ?? 'Administrator');
        $this->fillReservationsSheet($spreadsheet->createSheet(), $data['reservation_rows']);
        $this->fillPaymentsSheet($spreadsheet->createSheet(), $data['financial']['payment_transactions']);
        $this->fillRefundsSheet($spreadsheet->createSheet(), $data['financial']['refund_activity']);
        $this->fillOutstandingSheet($spreadsheet->createSheet(), $data['financial']['outstanding_balances']);
        $this->fillFeedbackSheet($spreadsheet->createSheet(), $data['feedback_rows']);
        $this->fillOperationsSheet($spreadsheet->createSheet(), $data);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'dmd-report-');
        (new Xlsx($spreadsheet))->save($temporaryPath);

        return response()->download($temporaryPath, $this->filename($data['period'], 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** @return array<string, mixed> */
    private function reportData(Request $request, FinancialReportingService $financialReporting, GuestMoodService $guestMood): array
    {
        [$startDate, $endDate, $preset] = $this->period($request);
        $reservations = $this->reservationRows($startDate, $endDate);
        $data = [
            'period' => [
                'preset' => $preset,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'label' => $this->periodLabel($preset, $startDate, $endDate),
            ],
            'reservations' => $this->reservationAnalytics($startDate, $endDate),
            'financial' => array_merge($financialReporting->summary($startDate, $endDate), [
                'payment_transactions' => $financialReporting->paymentTransactions($startDate, $endDate),
            ]),
            'accommodations' => $this->accommodationAnalytics($startDate, $endDate),
            'attendance' => $this->attendanceAnalytics($startDate, $endDate),
            'housekeeping' => $this->housekeepingAnalytics($startDate, $endDate),
            'inventory' => $this->inventoryAnalytics(),
            'guest_mood' => $guestMood->summary($startDate, $endDate),
            'reservation_rows' => $reservations,
            'feedback_rows' => $this->feedbackRows($startDate, $endDate),
        ];

        return $data;
    }

    private function reservationRows(CarbonImmutable $startDate, CarbonImmutable $endDate): Collection
    {
        return Reservation::query()->with(['user', 'accommodation'])
            ->withPaymentSummary()
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->latest('created_at')->get();
    }

    /** @return list<array<string, mixed>> */
    private function feedbackRows(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        return GuestFeedback::query()->with(['reservation.accommodation', 'reservation.user'])
            ->whereBetween('submitted_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->latest('submitted_at')->limit(500)->get()->map(fn (GuestFeedback $feedback) => [
                'submitted_at' => $feedback->submitted_at?->toISOString(),
                'booking_reference' => $feedback->reservation?->booking_reference,
                'accommodation' => $feedback->reservation?->accommodation?->name,
                'rating' => (int) $feedback->rating,
                'mood_label' => GuestMoodService::MOODS[$feedback->rating]['label'] ?? null,
                'comment' => $feedback->comment,
            ])->values()->all();
    }

    private function filename(array $period, string $extension): string
    {
        $range = $period['start_date'] === $period['end_date'] ? $period['start_date'] : $period['start_date'].'_to_'.$period['end_date'];
        return 'DMD_Resort_Report_'.$range.'.'.$extension;
    }

    private function logExport(AuditLogger $auditLogger, Request $request, string $format, array $period): void
    {
        $auditLogger->log($request, 'reports', 'report_exported', 'Management report exported.', null, [
            'format' => $format,
            'preset' => $period['preset'],
            'start_date' => $period['start_date'],
            'end_date' => $period['end_date'],
        ]);
    }

    private function fillSummarySheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $data, string $generatedBy): void
    {
        $sheet->setTitle('Summary');
        $sheet->fromArray([
            ['DMD FAMILY RESORT'],
            ['Management Report'],
            ['Reporting Period', $data['period']['label']],
            ['Generated At', now(config('app.timezone'))->format('F j, Y g:i A')],
            ['Generated By', $generatedBy],
            [],
            ['Metric', 'Value'],
            ['Total Reservations', $data['reservations']['summary']['total'] ?? 0],
            ['Occupancy', ($data['accommodations']['occupancy_rate'] ?? 0) / 100],
            ['Booking Value', $this->currencyNumber($data['financial']['booking_value'] ?? 0)],
            ['Collected Revenue', $this->currencyNumber($data['financial']['collected_revenue'] ?? 0)],
            ['Outstanding Balance', $this->currencyNumber($data['financial']['outstanding_balance'] ?? 0)],
            ['Refunds Processed', $this->currencyNumber($data['financial']['refunds_processed'] ?? 0)],
            ['Net Collected Revenue', $this->currencyNumber($data['financial']['net_collected_revenue'] ?? 0)],
            ['Guest Mood Score', $data['guest_mood']['mood_score'] === null ? 'No guest feedback yet' : ($data['guest_mood']['mood_score'] / 100)],
            ['Mood Responses', $data['guest_mood']['response_count'] ?? 0],
            [],
            ['Payment Source', 'Amount'],
        ], null, 'A1');
        $row = 19;
        foreach ($data['financial']['payment_method_breakdown'] as $source) {
            $sheet->fromArray([[$source['source'], $this->currencyNumber($source['amount'])]], null, 'A'.$row++);
        }
        $sheet->fromArray([[], ['Reservation Status', 'Count']], null, 'A'.$row++);
        foreach (Reservation::adminStatuses() as $status) {
            $sheet->fromArray([[ucwords(str_replace('_', ' ', $status)), $data['reservations']['summary'][$status] ?? 0]], null, 'A'.$row++);
        }
        $this->styleSheet($sheet, ['A1:B1', 'A7:B7', 'A18:B18', 'A'.$row.':B'.$row]);
        $sheet->getStyle('B9')->getNumberFormat()->setFormatCode('0.0%');
        $sheet->getStyle('B10:B14')->getNumberFormat()->setFormatCode('₱#,##0.00');
        if ($data['guest_mood']['mood_score'] !== null) $sheet->getStyle('B15')->getNumberFormat()->setFormatCode('0.0%');
    }

    private function fillReservationsSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, Collection $rows): void
    {
        $sheet->setTitle('Reservations');
        $sheet->fromArray([['Booking Reference', 'Guest', 'Accommodation', 'Type', 'Check-in', 'Check-out', 'Status', 'Booking Total', 'Payment Status']], null, 'A1');
        $data = $rows->map(fn (Reservation $reservation) => [$reservation->booking_reference, $reservation->user?->name ?: trim($reservation->guest_first_name.' '.$reservation->guest_last_name), $reservation->accommodation?->name, $reservation->accommodation?->type, $reservation->check_in?->format('Y-m-d'), $reservation->check_out?->format('Y-m-d'), $reservation->status, $this->currencyNumber(Reservation::minorUnitsToCurrency($reservation->totalAmountMinor())), $reservation->paymentState()])->all();
        if ($data) $sheet->fromArray($data, null, 'A2');
        $this->styleSheet($sheet, ['A1:I1']);
        $sheet->getStyle('H2:H'.max(2, count($data) + 1))->getNumberFormat()->setFormatCode('₱#,##0.00');
    }

    private function fillPaymentsSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $rows): void
    {
        $sheet->setTitle('Payments');
        $sheet->fromArray([['Date', 'Booking Reference', 'Purpose', 'Source', 'Amount', 'Status', 'Reference']], null, 'A1');
        $data = array_map(fn (array $row) => [$row['payment_date'] ?? $row['created_at'], $row['booking_reference'], $this->paymentPurpose($row['purpose']), $row['source'], $this->currencyNumber($row['amount']), $row['status'], $row['id']], $rows);
        if ($data) $sheet->fromArray($data, null, 'A2');
        $this->styleSheet($sheet, ['A1:G1']);
        $sheet->getStyle('E2:E'.max(2, count($data) + 1))->getNumberFormat()->setFormatCode('₱#,##0.00');
    }

    private function fillRefundsSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $rows): void
    {
        $sheet->setTitle('Refunds');
        $sheet->fromArray([['Date', 'Booking', 'Eligible Deposit', 'Refund Amount', 'Status', 'Reference']], null, 'A1');
        $data = array_map(fn (array $row) => [$row['date'], $row['booking_reference'], $this->currencyNumber($row['eligible_deposit']), $this->currencyNumber($row['refund_amount']), $row['status'], $row['reference']], $rows);
        if ($data) $sheet->fromArray($data, null, 'A2');
        $this->styleSheet($sheet, ['A1:F1']);
        $sheet->getStyle('C2:D'.max(2, count($data) + 1))->getNumberFormat()->setFormatCode('₱#,##0.00');
    }

    private function fillOutstandingSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $rows): void
    {
        $sheet->setTitle('Outstanding Balances');
        $sheet->fromArray([['Booking', 'Guest', 'Accommodation', 'Booking Total', 'Paid Amount', 'Outstanding Balance', 'Reservation Status']], null, 'A1');
        $data = array_map(fn (array $row) => [$row['booking_reference'], $row['customer'] ?? 'Guest', $row['accommodation'], $this->currencyNumber($row['booking_value']), $this->currencyNumber($row['amount_paid']), $this->currencyNumber($row['balance_due']), $row['reservation_status']], $rows);
        if ($data) $sheet->fromArray($data, null, 'A2');
        $this->styleSheet($sheet, ['A1:G1']);
        $sheet->getStyle('D2:F'.max(2, count($data) + 1))->getNumberFormat()->setFormatCode('₱#,##0.00');
    }

    private function fillFeedbackSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $rows): void
    {
        $sheet->setTitle('Guest Feedback');
        $sheet->fromArray([['Submitted At', 'Booking', 'Accommodation', 'Rating', 'Mood Label', 'Comment']], null, 'A1');
        if ($rows) $sheet->fromArray(array_map(fn (array $row) => [$row['submitted_at'], $row['booking_reference'], $row['accommodation'], $row['rating'], $row['mood_label'], $row['comment']], $rows), null, 'A2');
        $this->styleSheet($sheet, ['A1:F1']);
        $sheet->getStyle('F:F')->getAlignment()->setWrapText(true);
    }

    private function fillOperationsSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $data): void
    {
        $sheet->setTitle('Operations');
        $sheet->fromArray([['Metric', 'Value'], ['Total Assets', $data['inventory']['summary']['total_assets'] ?? 0], ['Cleaning Required', $data['accommodations']['cleaning_required'] ?? 0], ['Maintenance Accommodations', $data['accommodations']['maintenance'] ?? 0], ['Available Accommodations', $data['accommodations']['available'] ?? 0]], null, 'A1');
        $this->styleSheet($sheet, ['A1:B1']);
    }

    private function styleSheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headerRanges): void
    {
        foreach ($headerRanges as $range) $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        foreach ($headerRanges as $range) $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('245140');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        foreach (range('A', 'I') as $column) $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    private function currencyNumber(string $value): float { return (float) str_replace([',', '₱', ' '], '', $value); }
    private function paymentPurpose(?string $purpose): string { return ['deposit' => 'Deposit', 'full' => 'Full Payment', 'balance' => 'Balance Payment'][$purpose] ?? ucwords(str_replace('_', ' ', (string) $purpose)); }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function period(Request $request): array
    {
        $attributes = $request->validate([
            'preset' => ['sometimes', 'nullable', Rule::in(['today', 'this_week', 'this_month', 'custom'])],
            'start_date' => ['required_if:preset,custom', 'nullable', 'date'],
            'end_date' => ['required_if:preset,custom', 'nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $preset = $attributes['preset'] ?? 'this_month';
        $today = CarbonImmutable::today();

        [$startDate, $endDate] = match ($preset) {
            'today' => [$today, $today],
            'this_week' => [$today->startOfWeek(), $today->endOfWeek()],
            'custom' => [
                CarbonImmutable::parse($attributes['start_date']),
                CarbonImmutable::parse($attributes['end_date']),
            ],
            default => [$today->startOfMonth(), $today->endOfMonth()],
        };

        return [$startDate, $endDate, $preset];
    }

    private function periodLabel(string $preset, CarbonImmutable $startDate, CarbonImmutable $endDate): string
    {
        return match ($preset) {
            'today' => 'Today',
            'this_week' => 'This Week',
            'custom' => $startDate->format('M d, Y').' - '.$endDate->format('M d, Y'),
            default => 'This Month',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function reservationAnalytics(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $reservations = Reservation::query()
            ->with('accommodation')
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->get();

        return [
            'summary' => $this->countsByStatuses($reservations, Reservation::adminStatuses()),
            'trend' => $this->trend($reservations, $startDate, $endDate, 'created_at', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_PENDING, Reservation::STATUS_EXPIRED, Reservation::STATUS_CANCELLED]),
            'top_accommodations' => $this->topAccommodations($startDate, $endDate),
            'by_type' => $reservations
                ->groupBy(fn (Reservation $reservation) => $reservation->accommodation?->type ?: 'unknown')
                ->map(fn (Collection $items, string $type) => [
                    'type' => $type,
                    'count' => $items->count(),
                ])
                ->sortByDesc('count')
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accommodationAnalytics(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $totalNights = max(1, $startDate->diffInDays($endDate) + 1);
        $accommodationCount = max(1, Accommodation::count());
        $bookedNights = Reservation::query()
            ->where(function ($query) {
                Reservation::applyAvailabilityStatusFilter($query);
            })
            ->whereDate('check_in', '<=', $endDate->toDateString())
            ->whereDate('check_out', '>=', $startDate->toDateString())
            ->get()
            ->sum(function (Reservation $reservation) use ($startDate, $endDate) {
                $checkIn = CarbonImmutable::parse($reservation->check_in)->max($startDate);
                $checkOut = CarbonImmutable::parse($reservation->check_out)->min($endDate->addDay());

                return max(0, $checkIn->diffInDays($checkOut));
            });

        return [
            'available' => Accommodation::where('status', Accommodation::STATUS_AVAILABLE)->count(),
            'unavailable' => Accommodation::where('status', Accommodation::STATUS_UNAVAILABLE)->count(),
            'maintenance' => Accommodation::where('status', Accommodation::STATUS_MAINTENANCE)->count(),
            'cleaning_required' => Accommodation::whereIn('housekeeping_status', [
                Accommodation::HOUSEKEEPING_NEEDS_CLEANING,
                Accommodation::HOUSEKEEPING_CLEANING,
            ])->count(),
            'occupancy_rate' => round(($bookedNights / ($accommodationCount * $totalNights)) * 100, 2),
            'booked_nights' => $bookedNights,
            'period_days' => $totalNights,
            'accommodation_count' => $accommodationCount,
            'capacity_nights' => $accommodationCount * $totalNights,
            'housekeeping_summary' => [
                'ready' => Accommodation::where('housekeeping_status', Accommodation::HOUSEKEEPING_READY)->count(),
                'needs_cleaning' => Accommodation::where('housekeeping_status', Accommodation::HOUSEKEEPING_NEEDS_CLEANING)->count(),
                'cleaning' => Accommodation::where('housekeeping_status', Accommodation::HOUSEKEEPING_CLEANING)->count(),
                'maintenance' => Accommodation::where('housekeeping_status', Accommodation::HOUSEKEEPING_MAINTENANCE)->count(),
            ],
            'occupancy_definition' => [
                'numerator' => 'Overlapping booked nights from confirmed, checked-in, and unexpired pending reservations.',
                'denominator' => 'Accommodation count multiplied by the number of calendar days in the selected period.',
                'includes_maintenance' => true,
                'duration_aware' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendanceAnalytics(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $records = AttendanceRecord::query()
            ->with('staff')
            ->whereBetween('attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->get();

        return [
            'summary' => $this->countsByStatuses($records, AttendanceRecord::statuses()),
            'trend' => $this->trend($records, $startDate, $endDate, 'attendance_date', [AttendanceRecord::STATUS_PRESENT, AttendanceRecord::STATUS_LATE, AttendanceRecord::STATUS_ABSENT]),
            'by_role' => collect([
                User::ROLE_ADMIN,
                User::ROLE_MANAGER,
                User::ROLE_FRONT_DESK,
            ])->map(fn (string $role) => [
                    'role' => $role,
                    'present' => $records->filter(fn (AttendanceRecord $record) => $record->staff?->normalizedRole() === $role && $record->status === AttendanceRecord::STATUS_PRESENT)->count(),
                    'late' => $records->filter(fn (AttendanceRecord $record) => $record->staff?->normalizedRole() === $role && $record->status === AttendanceRecord::STATUS_LATE)->count(),
                    'absent' => $records->filter(fn (AttendanceRecord $record) => $record->staff?->normalizedRole() === $role && $record->status === AttendanceRecord::STATUS_ABSENT)->count(),
                    'incomplete' => $records->filter(fn (AttendanceRecord $record) => $record->staff?->normalizedRole() === $role && $record->status === AttendanceRecord::STATUS_INCOMPLETE)->count(),
                ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function housekeepingAnalytics(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $tasks = HousekeepingTask::query()
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->get();

        return [
            'summary' => array_merge(
                $this->countsByStatuses($tasks, HousekeepingTask::statuses()),
                ['maintenance_related' => $tasks->where('task_type', HousekeepingTask::TYPE_MAINTENANCE)->count()]
            ),
            'completion_trend' => $this->trend(
                HousekeepingTask::query()
                    ->whereBetween('completed_at', [$startDate->startOfDay(), $endDate->endOfDay()])
                    ->get(),
                $startDate,
                $endDate,
                'completed_at',
                [HousekeepingTask::STATUS_COMPLETED]
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryAnalytics(): array
    {
        $assets = InventoryAsset::query()->get();

        return [
            'summary' => [
                'total_assets' => $assets->sum('quantity'),
                'available' => $assets->where('status', InventoryAsset::STATUS_AVAILABLE)->sum('quantity'),
                'assigned' => $assets->where('status', InventoryAsset::STATUS_ASSIGNED)->sum('quantity'),
                'maintenance' => $assets->where('status', InventoryAsset::STATUS_MAINTENANCE)->sum('quantity'),
                'damaged' => $assets->where('condition', InventoryAsset::CONDITION_DAMAGED)->sum('quantity'),
                'retired' => $assets->where('status', InventoryAsset::STATUS_RETIRED)->sum('quantity'),
            ],
            'by_category' => $assets
                ->groupBy('category')
                ->map(fn (Collection $items, string $category) => [
                    'category' => $category,
                    'quantity' => $items->sum('quantity'),
                ])
                ->values(),
            'by_location' => $assets
                ->groupBy('location_type')
                ->map(fn (Collection $items, string $locationType) => [
                    'location_type' => $locationType,
                    'quantity' => $items->sum('quantity'),
                ])
                ->values(),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    private function countsByStatuses(Collection $items, array $statuses): array
    {
        $counts = ['total' => $items->count()];

        foreach ($statuses as $status) {
            $counts[$status] = $items->where('status', $status)->count();
        }

        return $counts;
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @param  list<string>  $statuses
     * @return list<array<string, mixed>>
     */
    private function trend(Collection $items, CarbonImmutable $startDate, CarbonImmutable $endDate, string $dateColumn, array $statuses): array
    {
        $days = [];
        for ($date = $startDate; $date->lte($endDate); $date = $date->addDay()) {
            $dateKey = $date->toDateString();
            $point = [
                'date' => $dateKey,
                'label' => $date->format('M d'),
            ];

            foreach ($statuses as $status) {
                $point[$status] = $items->filter(function ($item) use ($dateColumn, $dateKey, $status) {
                    $value = $item->{$dateColumn};

                    return $value && CarbonImmutable::parse($value)->toDateString() === $dateKey && $item->status === $status;
                })->count();
            }

            $days[] = $point;
        }

        return $days;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topAccommodations(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        return Accommodation::query()
            ->withCount(['reservations as reservations_count' => function (Builder $query) use ($startDate, $endDate) {
                $query->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()]);
            }])
            ->orderByDesc('reservations_count')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (Accommodation $accommodation) => [
                'id' => $accommodation->id,
                'name' => $accommodation->name,
                'type' => $accommodation->type,
                'reservations_count' => $accommodation->reservations_count,
            ])
            ->values()
            ->all();
    }
}
