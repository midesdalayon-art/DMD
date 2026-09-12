<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestFeedback extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'guest_feedback';

    protected $fillable = ['reservation_id', 'user_id', 'rating', 'comment', 'submitted_at'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'submitted_at' => 'datetime'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
