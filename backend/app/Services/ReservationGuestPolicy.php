<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ReservationGuestPolicy
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{adults: int, children: int, infants: int, occupancy: int}
     */
    public function normalize(array $attributes): array
    {
        $adults = array_key_exists('adults', $attributes)
            ? (int) $attributes['adults']
            : (int) ($attributes['guests'] ?? 1);
        $children = (int) ($attributes['children'] ?? 0);
        $infants = (int) ($attributes['infants'] ?? 0);

        if ($adults < 1) {
            throw ValidationException::withMessages([
                'adults' => ['At least one adult is required.'],
            ]);
        }

        if ($children < 0) {
            throw ValidationException::withMessages([
                'children' => ['Children cannot be negative.'],
            ]);
        }

        if ($infants < 0) {
            throw ValidationException::withMessages([
                'infants' => ['Infants cannot be negative.'],
            ]);
        }

        return [
            'adults' => $adults,
            'children' => $children,
            'infants' => $infants,
            'occupancy' => $this->occupancy($adults, $children),
        ];
    }

    public function occupancy(int $adults, int $children): int
    {
        return $adults + $children;
    }
}
