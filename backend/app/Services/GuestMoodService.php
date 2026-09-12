<?php

namespace App\Services;

use App\Models\GuestFeedback;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

class GuestMoodService
{
    public const MOODS = [
        1 => ['emoji' => '😡', 'label' => 'Awful'],
        2 => ['emoji' => '🙁', 'label' => 'Bad'],
        3 => ['emoji' => '😐', 'label' => 'Okay'],
        4 => ['emoji' => '🙂', 'label' => 'Good'],
        5 => ['emoji' => '😍', 'label' => 'Amazing'],
    ];

    public function moodForAverage(?float $average): ?array
    {
        if ($average === null) return null;
        $rating = match (true) {
            $average < 1.5 => 1,
            $average < 2.5 => 2,
            $average < 3.5 => 3,
            $average < 4.5 => 4,
            default => 5,
        };

        return self::MOODS[$rating] + ['rating' => $rating];
    }

    public function summary($startDate = null, $endDate = null, ?int $accommodationId = null): array
    {
        $query = GuestFeedback::query()
            ->join('reservations', 'reservations.id', '=', 'guest_feedback.reservation_id')
            ->where('reservations.status', Reservation::STATUS_CHECKED_OUT)
            ->when($startDate && $endDate, fn ($q) => $q->whereBetween('guest_feedback.submitted_at', [$startDate->startOfDay(), $endDate->endOfDay()]))
            ->when($accommodationId, fn ($q) => $q->where('reservations.accommodation_id', $accommodationId));

        $aggregate = (clone $query)->selectRaw('COUNT(*) AS total, AVG(guest_feedback.rating) AS average_rating')->first();
        $total = (int) ($aggregate->total ?? 0);
        $averageRaw = $total > 0 ? (float) $aggregate->average_rating : null;
        $average = $averageRaw === null ? null : round($averageRaw, 2);
        $distribution = array_fill_keys(array_keys(self::MOODS), 0);
        (clone $query)->select('guest_feedback.rating')->selectRaw('COUNT(*) AS count')->groupBy('guest_feedback.rating')->get()->each(function ($row) use (&$distribution) {
            $distribution[(int) $row->rating] = (int) $row->count;
        });

        $comments = GuestFeedback::query()
            ->with(['reservation:id,booking_reference,accommodation_id,user_id,guest_first_name,guest_last_name', 'reservation.accommodation:id,name', 'reservation.user:id,name'])
            ->whereHas('reservation', fn ($q) => $q->where('status', Reservation::STATUS_CHECKED_OUT)->when($accommodationId, fn ($q) => $q->where('accommodation_id', $accommodationId)))
            ->when($startDate && $endDate, fn ($q) => $q->whereBetween('submitted_at', [$startDate->startOfDay(), $endDate->endOfDay()]))
            ->whereNotNull('comment')->where('comment', '!=', '')
            ->latest('submitted_at')->limit(200)->get()
            ->map(fn (GuestFeedback $feedback) => [
                'id' => $feedback->id,
                'booking_reference' => $feedback->reservation?->booking_reference,
                'guest' => $feedback->reservation?->user?->name ?: trim(($feedback->reservation?->guest_first_name ?? '').' '.($feedback->reservation?->guest_last_name ?? '')),
                'accommodation' => $feedback->reservation?->accommodation?->name,
                'rating' => $feedback->rating,
                'mood' => self::MOODS[$feedback->rating] ?? null,
                'comment' => $feedback->comment,
                'submitted_at' => $feedback->submitted_at?->toISOString(),
            ])->values()->all();

        return [
            'response_count' => $total,
            'average_rating' => $average,
            'mood_score' => $averageRaw === null ? null : round(($averageRaw / 5) * 100, 1),
            'mood' => $this->moodForAverage($averageRaw),
            'distribution' => collect(self::MOODS)->map(fn ($mood, $rating) => $mood + ['rating' => $rating, 'count' => $distribution[$rating]])->values()->all(),
            'comments' => $comments,
        ];
    }
}
