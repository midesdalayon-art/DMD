<?php

namespace App\Http\Controllers\Api\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;

class AccommodationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:160'],
            'type' => ['sometimes', 'nullable', Rule::in(array_merge(['all'], Accommodation::types()))],
            'status' => ['sometimes', 'nullable', Rule::in(array_merge(['all'], $this->statusOptions()))],
        ]);

        $query = Accommodation::query()->with(['images', 'amenities'])->orderBy('type')->orderBy('name');

        if (($filters['type'] ?? 'all') !== 'all') {
            $query->where('type', $filters['type']);
        }

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $search = mb_strtolower($search);
            $query->where(function ($query) use ($search) {
                $query->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(type) LIKE ?', ["%{$search}%"]);
            });
        }

        $accommodations = $query->get();
        $occupiedReservationIds = $this->occupiedAccommodationIds();
        $allData = $accommodations
            ->map(fn (Accommodation $accommodation) => $this->accommodationData($accommodation, $occupiedReservationIds));
        $data = $allData
            ->filter(fn (array $accommodation) => ($filters['status'] ?? 'all') === 'all'
                || $accommodation['operational_status'] === $filters['status'])
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'statuses' => $this->statusOptions(),
                'types' => Accommodation::types(),
                'summary' => [
                    'total_accommodations' => $allData->count(),
                    'available' => $allData->where('operational_status', 'available')->count(),
                    'occupied' => $allData->where('operational_status', 'occupied')->count(),
                    'maintenance' => $allData->whereIn('operational_status', ['maintenance', 'unavailable'])->count(),
                    'needs_cleaning' => $allData->where('operational_status', 'needs_cleaning')->count(),
                ],
            ],
        ]);
    }

    public function show(Accommodation $accommodation): JsonResponse
    {
        return response()->json([
            'data' => $this->accommodationData($accommodation->load(['images', 'amenities']), $this->occupiedAccommodationIds()),
        ]);
    }

    private function occupiedAccommodationIds(): Collection
    {
        return Reservation::query()
            ->whereIn('status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_CHECKED_IN])
            ->whereDate('check_in', '<=', today(config('app.timezone')))
            ->whereDate('check_out', '>', today(config('app.timezone')))
            ->pluck('accommodation_id')
            ->unique()
            ->values();
    }

    private function accommodationData(Accommodation $accommodation, ?\Illuminate\Support\Collection $occupiedReservationIds = null): array
    {
        $accommodation->loadMissing([
            'images',
            'amenities',
            'reservations.user',
            'reservations.payment',
            'reservations.payments',
        ]);

        $today = today(config('app.timezone'))->toDateString();
        $currentReservation = $accommodation->reservations
            ->whereIn('status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_CHECKED_IN])
            ->first(fn (Reservation $reservation) => $reservation->check_in?->toDateString() <= $today && $reservation->check_out?->toDateString() > $today);

        $nextReservation = $accommodation->reservations
            ->whereIn('status', [Reservation::STATUS_PENDING, Reservation::STATUS_CONFIRMED])
            ->filter(fn (Reservation $reservation) => $reservation->status !== Reservation::STATUS_PENDING || ! $reservation->pendingHoldExpired())
            ->filter(fn (Reservation $reservation) => $reservation->check_in?->toDateString() >= $today)
            ->sortBy('check_in')
            ->first();

        $operationalStatus = $this->operationalStatus(
            $accommodation,
            $occupiedReservationIds?->contains($accommodation->id) ?? false,
        );

        return array_merge($accommodation->publicData(), [
            'operational_status' => $operationalStatus,
            'reservations_count' => $accommodation->reservations->count(),
            'is_currently_occupied' => $occupiedReservationIds ? $occupiedReservationIds->contains($accommodation->id) : false,
            'current_reservation' => $currentReservation ? array_merge($currentReservation->publicData(), [
                'guest_name' => $currentReservation->user?->name ?? 'Guest',
                'guest_email' => $currentReservation->user?->email,
                'accommodation_name' => $accommodation->name,
            ]) : null,
            'next_reservation' => $nextReservation ? array_merge($nextReservation->publicData(), [
                'guest_name' => $nextReservation->user?->name ?? 'Guest',
                'guest_email' => $nextReservation->user?->email,
                'accommodation_name' => $accommodation->name,
            ]) : null,
            'amenity_names' => $accommodation->amenities->pluck('name')->values(),
            'type_label' => Accommodation::typeLabel($accommodation->type),
        ]);
    }

    private function operationalStatus(Accommodation $accommodation, bool $occupied): string
    {
        if ($accommodation->status === Accommodation::STATUS_UNAVAILABLE) {
            return 'unavailable';
        }

        if ($accommodation->status === Accommodation::STATUS_MAINTENANCE
            || $accommodation->housekeeping_status === Accommodation::HOUSEKEEPING_MAINTENANCE) {
            return 'maintenance';
        }

        if ($occupied) {
            return 'occupied';
        }

        return match ($accommodation->housekeeping_status) {
            Accommodation::HOUSEKEEPING_NEEDS_CLEANING => 'needs_cleaning',
            Accommodation::HOUSEKEEPING_CLEANING => 'cleaning',
            Accommodation::HOUSEKEEPING_READY => $accommodation->status === Accommodation::STATUS_AVAILABLE
                ? 'available'
                : 'unavailable',
            default => 'unavailable',
        };
    }

    /**
     * @return list<string>
     */
    private function statusOptions(): array
    {
        return [
            Accommodation::STATUS_AVAILABLE,
            'occupied',
            Accommodation::HOUSEKEEPING_NEEDS_CLEANING,
            Accommodation::HOUSEKEEPING_CLEANING,
            Accommodation::STATUS_UNAVAILABLE,
            Accommodation::STATUS_MAINTENANCE,
        ];
    }
}
