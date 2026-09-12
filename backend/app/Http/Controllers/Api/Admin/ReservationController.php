<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use App\Services\AuditLogger;
use App\Services\CancellationRefundService;

class ReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'booking_reference' => ['sometimes', 'nullable', 'string', 'max:40'],
            'guest' => ['sometimes', 'nullable', 'string', 'max:120'],
            'accommodation' => ['sometimes', 'nullable', 'string', 'max:160'],
            'status' => ['sometimes', 'nullable', Rule::in(Reservation::adminStatuses())],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50])],
        ]);

        $query = Reservation::query()
            ->with(['user', 'accommodation'])
            ->withPaymentSummary()
            ->latest();

        if ($reference = trim((string) ($filters['booking_reference'] ?? ''))) {
            $reference = mb_strtolower($reference);
            $query->whereRaw('LOWER(booking_reference) LIKE ?', ["%{$reference}%"]);
        }

        if ($guest = trim((string) ($filters['guest'] ?? ''))) {
            $guest = mb_strtolower($guest);
            $query->whereHas('user', function ($query) use ($guest) {
                $query
                    ->whereRaw('LOWER(name) LIKE ?', ["%{$guest}%"])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', ["%{$guest}%"])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$guest}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$guest}%"]);
            });
        }

        if ($accommodation = trim((string) ($filters['accommodation'] ?? ''))) {
            $accommodation = mb_strtolower($accommodation);
            $query->whereHas('accommodation', function ($query) use ($accommodation) {
                $query
                    ->whereRaw('LOWER(name) LIKE ?', ["%{$accommodation}%"])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$accommodation}%"])
                    ->orWhereRaw('LOWER(type) LIKE ?', ["%{$accommodation}%"]);
            });
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($dateFrom = $filters['date_from'] ?? null) {
            $query->whereDate('check_in', '>=', $dateFrom);
        }

        if ($dateTo = $filters['date_to'] ?? null) {
            $query->whereDate('check_in', '<=', $dateTo);
        }

        return response()->json([
            'data' => $query->paginate((int) ($filters['per_page'] ?? 10))
                ->through(fn (Reservation $reservation) => $this->adminData($reservation)),
            'meta' => [
                'statuses' => Reservation::adminStatuses(),
                'transitions' => Reservation::allowedAdminStatusTransitions(),
            ],
        ]);
    }

    public function show(Reservation $reservation): JsonResponse
    {
        return response()->json([
            'data' => $this->adminData($reservation->load(['user', 'accommodation'])->loadPaymentSummary(), true),
            'meta' => [
                'statuses' => Reservation::adminStatuses(),
                'transitions' => Reservation::allowedAdminStatusTransitions(),
            ],
        ]);
    }

    public function updateStatus(Request $request, Reservation $reservation, CancellationRefundService $refunds): JsonResponse
    {
        $attributes = $request->validate([
            'status' => ['required', Rule::in(Reservation::adminStatuses())],
            'cancellation_reason' => ['required_if:status,'.Reservation::STATUS_CANCELLED, 'nullable', 'string', 'min:5', 'max:1000'],
        ]);

        if (! $reservation->canTransitionTo($attributes['status'])) {
            throw ValidationException::withMessages([
                'status' => ['This reservation cannot be moved to the selected status.'],
            ]);
        }

        if ($attributes['status'] === Reservation::STATUS_CHECKED_IN) {
            $reservation->loadPaymentSummary();

            if ($reservation->balanceDueMinor() > 0 || $reservation->paymentState() !== 'fully_paid') {
                throw ValidationException::withMessages([
                    'status' => ['The remaining balance must be settled before check-in.'],
                ]);
            }
        }

        if ($attributes['status'] === Reservation::STATUS_CANCELLED && ! $reservation->canBeCancelled()) {
            throw ValidationException::withMessages([
                'status' => ['This reservation can no longer be cancelled.'],
            ]);
        }

        $old = ['status' => $reservation->status, 'cancellation_reason' => $reservation->cancellation_reason];
        $updates = [
            'status' => $attributes['status'],
        ];

        if ($attributes['status'] === Reservation::STATUS_CANCELLED) {
            $updates['cancellation_reason'] = $attributes['cancellation_reason'];
            $updates['cancelled_at'] = now();
            $updates = array_merge($updates, $refunds->cancellationAttributes($reservation, true));
        }

        $reservation->forceFill($updates)->save();
        app(AuditLogger::class)->log($request, 'booking_management', $reservation->status === Reservation::STATUS_CANCELLED ? 'booking_cancelled' : 'reservation_status_changed', 'Reservation status changed by admin.', $reservation, [
            'booking_reference' => $reservation->booking_reference,
            'before' => $old,
            'after' => ['status' => $reservation->status, 'cancellation_reason' => $reservation->cancellation_reason],
        ]);

        return response()->json([
            'data' => $this->adminData($reservation->load(['user', 'accommodation'])->loadPaymentSummary()),
            'message' => 'Reservation status updated.',
        ]);
    }

    public function recordRefund(Request $request, Reservation $reservation): JsonResponse
    {
        $attributes = $request->validate([
            'refund_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $reservation = DB::transaction(function () use ($reservation, $attributes) {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($reservation->status !== Reservation::STATUS_CANCELLED
                || $reservation->refund_status !== Reservation::REFUND_STATUS_PENDING
                || ! $reservation->refund_eligible) {
                throw ValidationException::withMessages([
                    'refund' => ['This reservation is not eligible for a refund record.'],
                ]);
            }

            return tap($reservation->forceFill([
                'refund_status' => Reservation::REFUND_STATUS_REFUNDED,
                'refunded_amount_minor' => $reservation->estimated_refund_amount_minor,
                'refunded_at' => now(),
                'refund_reference' => $attributes['refund_reference'] ?? null,
            ]), fn (Reservation $item) => $item->save());
        });

        app(AuditLogger::class)->log($request, 'booking_management', 'refund_recorded', 'Manual reservation refund recorded by admin.', $reservation, [
            'booking_reference' => $reservation->booking_reference,
            'refunded_amount_minor' => $reservation->refunded_amount_minor,
        ]);

        return response()->json([
            'data' => $this->adminData($reservation->load(['user', 'accommodation'])->loadPaymentSummary(), true),
            'message' => 'Refund recorded.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminData(Reservation $reservation, bool $includePaymentHistory = false): array
    {
        $data = array_merge($reservation->publicData(), [
            'allowed_statuses' => Reservation::allowedAdminStatusTransitions()[$reservation->status] ?? [],
            'refund_reference' => $reservation->refund_reference,
        ]);

        if ($includePaymentHistory) {
            $data['payment_history'] = $reservation->staffPaymentHistory();
        }

        return $data;
    }
}
