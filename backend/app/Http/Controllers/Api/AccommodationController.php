<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Reservation;
use App\Services\ReservationGuestPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccommodationController extends Controller
{
    public function index(Request $request, ReservationGuestPolicy $guestPolicy): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['sometimes', Rule::in(array_merge(['all'], Accommodation::types()))],
            'guests' => ['sometimes', 'integer', 'min:1'],
            'adults' => ['sometimes', 'integer', 'min:1'],
            'children' => ['sometimes', 'integer', 'min:0'],
            'infants' => ['sometimes', 'integer', 'min:0'],
            'check_in' => ['sometimes', 'date', 'after_or_equal:today'],
            'check_out' => ['required_with:check_in', 'date', 'after:check_in'],
        ]);

        $query = Accommodation::query()->with(['images', 'amenities'])->orderBy('type')->orderBy('name');
        $hasSearchCriteria = isset($filters['check_in'])
            || isset($filters['check_out'])
            || isset($filters['guests'])
            || isset($filters['adults'])
            || isset($filters['children'])
            || isset($filters['infants']);
        $guestBreakdown = $hasSearchCriteria ? $guestPolicy->normalize($filters) : null;

        if (($filters['type'] ?? 'all') !== 'all') {
            $query->where('type', $filters['type']);
        }

        $query->where('status', Accommodation::STATUS_AVAILABLE)
            ->where('housekeeping_status', Accommodation::HOUSEKEEPING_READY);

        if ($guestBreakdown) {
            $query->where('capacity', '>=', $guestBreakdown['occupancy']);
        }

        $checkIn = $filters['check_in'] ?? null;
        $checkOut = $filters['check_out'] ?? null;

        if ($checkIn && $checkOut) {
            $rangeStart = CarbonImmutable::parse($checkIn, config('app.timezone'))->startOfDay();
            $rangeEnd = CarbonImmutable::parse($checkOut, config('app.timezone'))->startOfDay();
            $hasExclusiveConflict = Reservation::hasActiveDateRangeOverlap($rangeStart, $rangeEnd, Accommodation::TYPE_EXCLUSIVE_RESORT);

            if ($hasExclusiveConflict) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereDoesntHave('reservations', function ($query) use ($checkIn, $checkOut) {
                    $query
                    ->tap(fn ($query) => Reservation::applyAvailabilityStatusFilter($query))
                        ->whereDate('check_in', '<', $checkOut)
                        ->whereDate('check_out', '>', $checkIn);
                });
            }
        }

        $accommodations = $query->get();

        return response()->json([
            'data' => $accommodations
                ->map(fn (Accommodation $accommodation) => $accommodation->publicData($checkIn, $checkOut, $guestBreakdown['occupancy'] ?? null))
                ->values(),
        ]);
    }

    public function show(Accommodation $accommodation): JsonResponse
    {
        return response()->json([
            'data' => $accommodation->load(['images', 'amenities'])->publicData(),
        ]);
    }

    public function availability(Request $request, Accommodation $accommodation, ReservationGuestPolicy $guestPolicy): JsonResponse
    {
        $attributes = $request->validate([
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['sometimes', 'nullable', 'date', 'after:check_in'],
            'check_in_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'cottage_period_type' => ['sometimes', 'nullable', Rule::in(['day_use', 'overnight'])],
            'cottage_period_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'stay_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'guests' => ['sometimes', 'integer', 'min:1'],
            'adults' => ['sometimes', 'integer', 'min:1'],
            'children' => ['sometimes', 'integer', 'min:0'],
            'infants' => ['sometimes', 'integer', 'min:0'],
        ]);
        $guestBreakdown = $guestPolicy->normalize($attributes);

        $isRoom = $accommodation->isRoom();
        $isCottage = $accommodation->type === Accommodation::TYPE_COTTAGE;
        $isExclusiveResort = $accommodation->type === Accommodation::TYPE_EXCLUSIVE_RESORT;
        $stayDays = $isRoom
            ? max(1, (int) ($attributes['stay_days'] ?? CarbonImmutable::parse($attributes['check_in'])->diffInDays(CarbonImmutable::parse($attributes['check_out'] ?? $attributes['check_in'] ?? $attributes['check_in']))))
            : null;
        $cottagePeriodType = $isCottage ? ($attributes['cottage_period_type'] ?? null) : null;
        $cottagePeriodCount = $isCottage ? max(1, (int) ($attributes['cottage_period_count'] ?? 1)) : null;
        $wholeDayDays = $isExclusiveResort ? max(1, (int) ($attributes['stay_days'] ?? 1)) : null;
        $nights = $isRoom
            ? $stayDays
            : ($isCottage
                ? $cottagePeriodCount
                : ($isExclusiveResort
                    ? $wholeDayDays
                    : CarbonImmutable::parse($attributes['check_in'])->diffInDays(
                    CarbonImmutable::parse($attributes['check_out'])
                )));
        $capacityAllows = $accommodation->canHostOccupancy($guestBreakdown['occupancy']);
        $requestedStart = CarbonImmutable::parse($attributes['check_in'], config('app.timezone'))->startOfDay();
        $requestedEnd = CarbonImmutable::parse($attributes['check_out'] ?? $attributes['check_in'], config('app.timezone'))->startOfDay();
        $available = $capacityAllows && (
            $isRoom
                ? (
                    ! empty($attributes['check_in_time'])
                        ? (
                            ($checkInAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $attributes['check_in'].' '.$attributes['check_in_time'], config('app.timezone')))
                            && $accommodation->isAvailableForRoomStay(
                                $checkInAt->format('Y-m-d H:i:s'),
                                $checkInAt->addHours(Reservation::ROOM_STAY_HOURS * $stayDays)->format('Y-m-d H:i:s'),
                            )
                        )
                        : $accommodation->isAvailableFor($attributes['check_in'], $attributes['check_out'] ?? CarbonImmutable::parse($attributes['check_in'])->addDays($stayDays)->toDateString())
                )
                : ($isCottage
                    ? ! empty($cottagePeriodType)
                        && ($checkInAt = $this->calculateCottageStart($attributes['check_in'], $cottagePeriodType))
                        && $checkInAt->isFuture()
                        && $accommodation->isAvailableForCottageStay(
                            $checkInAt->format('Y-m-d H:i:s'),
                            $checkInAt->addHours(12 * $cottagePeriodCount)->format('Y-m-d H:i:s'),
                        )
                    : ($isExclusiveResort
                        ? ! Reservation::hasActiveDateRangeOverlap($requestedStart, $requestedEnd)
                        : $accommodation->isAvailableFor($attributes['check_in'], $attributes['check_out'])))
        );

        return response()->json([
            'available' => $available,
            'nights' => $nights,
            'occupancy' => $guestBreakdown['occupancy'],
            'guest_breakdown' => $guestBreakdown,
            'total_amount' => $available ? (float) number_format((float) $accommodation->price_per_night * $nights, 2, '.', '') : null,
            'message' => $available ? 'Accommodation is available.' : 'Accommodation is not available for those dates or guest count.',
        ]);
    }

    private function calculateCottageStart(string $date, string $periodType): CarbonImmutable
    {
        $startTime = $periodType === 'overnight' ? '18:00' : '06:00';
        $checkInAt = CarbonImmutable::createFromFormat('Y-m-d H:i', sprintf('%s %s', $date, $startTime), config('app.timezone'));

        if ($checkInAt === false) {
            abort(422, 'Invalid Cottage check-in date.');
        }

        return $checkInAt;
    }
}
