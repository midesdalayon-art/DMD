<?php

namespace App\Http\Controllers\Api\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\AuditLogger;
use App\Services\CancellationRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        ]);

        $query = Reservation::query()
            ->with(['user', 'accommodation'])
            ->withPaymentSummary()
            ->latest();
        $this->applyReservationFilters($query, $filters);

        return response()->json([
            'data' => $query->get()->map(fn (Reservation $reservation) => $this->reservationData($reservation))->values(),
            'meta' => [
                'statuses' => Reservation::adminStatuses(),
                'transitions' => Reservation::allowedAdminStatusTransitions(),
            ],
        ]);
    }

    public function show(Reservation $reservation): JsonResponse
    {
        return response()->json([
            'data' => $this->reservationData($reservation->load(['user', 'accommodation'])->loadPaymentSummary(), true),
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

        [$reservation, $old] = DB::transaction(function () use ($attributes, $reservation) {
            $reservation = Reservation::query()
                ->whereKey($reservation->id)
                ->with(['user', 'accommodation'])
                ->lockForUpdate()
                ->firstOrFail();

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
            $updates = ['status' => $attributes['status']];

            if (
                $attributes['status'] === Reservation::STATUS_CHECKED_OUT
                && $reservation->status !== Reservation::STATUS_CHECKED_OUT
            ) {
                $updates['check_out_at'] = now(config('app.timezone'));
            }

            if ($attributes['status'] === Reservation::STATUS_CANCELLED) {
                $updates['cancellation_reason'] = $attributes['cancellation_reason'];
                $updates['cancelled_at'] = now();
                $updates = array_merge($updates, $refunds->cancellationAttributes($reservation, true));
            }

            $reservation->forceFill($updates)->save();

            if ($attributes['status'] === Reservation::STATUS_CHECKED_OUT) {
                $reservation->accommodation?->forceFill([
                    'status' => \App\Models\Accommodation::STATUS_AVAILABLE,
                    'housekeeping_status' => \App\Models\Accommodation::HOUSEKEEPING_NEEDS_CLEANING,
                ])->save();
            }

            return [$reservation, $old];
        });

        app(AuditLogger::class)->log($request, 'booking_management', 'front_desk_reservation_status_changed', 'Reservation status changed by front desk.', $reservation, [
            'booking_reference' => $reservation->booking_reference,
            'before' => $old,
            'after' => ['status' => $reservation->status, 'cancellation_reason' => $reservation->cancellation_reason],
        ]);

        $data = $this->reservationData($reservation->load(['user', 'accommodation'])->loadPaymentSummary());
        if ($reservation->status === Reservation::STATUS_CHECKED_IN) {
            $data['checked_in_at'] = $reservation->updated_at?->toISOString();
        }

        return response()->json([
            'data' => $data,
            'message' => 'Reservation status updated.',
        ]);
    }

    private function applyReservationFilters($query, array $filters): void
    {
        if ($reference = trim((string) ($filters['booking_reference'] ?? ''))) {
            $reference = mb_strtolower($reference);
            $query->whereRaw('LOWER(booking_reference) LIKE ?', ["%{$reference}%"]);
        }

        if ($guest = trim((string) ($filters['guest'] ?? ''))) {
            $guest = mb_strtolower($guest);
            $query->whereHas('user', fn ($query) => $query
                ->whereRaw('LOWER(name) LIKE ?', ["%{$guest}%"])
                ->orWhereRaw('LOWER(first_name) LIKE ?', ["%{$guest}%"])
                ->orWhereRaw('LOWER(last_name) LIKE ?', ["%{$guest}%"])
                ->orWhereRaw('LOWER(email) LIKE ?', ["%{$guest}%"]));
        }

        if ($accommodation = trim((string) ($filters['accommodation'] ?? ''))) {
            $accommodation = mb_strtolower($accommodation);
            $query->whereHas('accommodation', fn ($query) => $query
                ->whereRaw('LOWER(name) LIKE ?', ["%{$accommodation}%"])
                ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$accommodation}%"])
                ->orWhereRaw('LOWER(type) LIKE ?', ["%{$accommodation}%"]));
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
    }

    /**
     * @return array<string, mixed>
     */
    private function reservationData(Reservation $reservation, bool $includePaymentHistory = false): array
    {
        $data = array_merge($reservation->publicData(), [
            'allowed_statuses' => Reservation::allowedAdminStatusTransitions()[$reservation->status] ?? [],
        ]);

        if ($includePaymentHistory) {
            $data['payment_history'] = $reservation->staffPaymentHistory();
        }

        return $data;
    }
}
