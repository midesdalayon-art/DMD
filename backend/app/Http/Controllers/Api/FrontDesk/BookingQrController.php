<?php

namespace App\Http\Controllers\Api\FrontDesk;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\ReservationQrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingQrController extends Controller
{
    public function verify(Request $request, ReservationQrService $qrService): JsonResponse
    {
        $attributes = $request->validate([
            'payload' => ['required', 'string', 'max:500'],
        ]);

        $token = $qrService->extractToken($attributes['payload']);
        if (! $token) {
            throw ValidationException::withMessages([
                'payload' => ['This booking QR is invalid.'],
            ]);
        }

        $reservation = Reservation::query()
            ->where('qr_token_hash', hash('sha256', $token))
            ->whereNull('qr_token_revoked_at')
            ->whereIn('status', [Reservation::STATUS_CONFIRMED, Reservation::STATUS_CHECKED_IN])
            ->first();

        if (! $reservation) {
            abort(404, 'This booking QR is invalid.');
        }

        $reservation = DB::transaction(function () use ($reservation) {
            return Reservation::query()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();
        });

        return response()->json([
            'data' => $qrService->staffVerificationData($reservation),
            'message' => 'Booking QR verified.',
        ]);
    }
}
