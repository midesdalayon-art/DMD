<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GuestFeedback;
use App\Models\Reservation;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuestFeedbackController extends Controller
{
    public function store(Request $request, Reservation $reservation): JsonResponse
    {
        $this->authorizeRegistered($request, $reservation);
        return $this->save($request, $reservation, $request->user()->id);
    }

    public function storeGuest(Request $request): JsonResponse
    {
        $token = $request->header('X-Guest-Access-Token');
        if (! is_string($token) || strlen($token) !== 64) abort(401, 'Guest credential is missing or invalid.');
        $reservation = Reservation::whereNull('user_id')->where('guest_access_token_hash', hash('sha256', $token))->whereNull('guest_access_token_revoked_at')->first();
        if (! $reservation) abort(401, 'Guest credential is missing or invalid.');
        return $this->save($request, $reservation, null);
    }

    private function save(Request $request, Reservation $reservation, ?int $userId): JsonResponse
    {
        $attributes = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        try {
            $feedback = DB::transaction(function () use ($reservation, $userId, $attributes) {
                $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== Reservation::STATUS_CHECKED_OUT) {
                    throw ValidationException::withMessages(['reservation' => ['Feedback is available after checkout.']]);
                }
                if ($locked->feedback()->exists()) {
                    throw ValidationException::withMessages(['feedback' => ['Feedback has already been submitted for this reservation.']]);
                }
                return GuestFeedback::create([
                    'reservation_id' => $locked->id,
                    'user_id' => $userId,
                    'rating' => $attributes['rating'],
                    'comment' => $attributes['comment'] ?? null,
                    'submitted_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23505', '23000'], true)) {
                throw ValidationException::withMessages(['feedback' => ['Feedback has already been submitted for this reservation.']]);
            }
            throw $exception;
        }

        return response()->json(['data' => ['rating' => $feedback->rating, 'comment' => $feedback->comment, 'submitted_at' => $feedback->submitted_at?->toISOString()], 'message' => 'Thanks for your feedback!']);
    }

    private function authorizeRegistered(Request $request, Reservation $reservation): void
    {
        if ($reservation->user_id !== $request->user()->id) abort(404);
    }
}
