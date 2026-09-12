<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends Model
{
    use HasFactory;

    public const STATUS_BOT = 'bot';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'conversation_uuid',
        'access_token',
        'access_token_hash',
        'access_token_expires_at',
        'access_token_revoked_at',
        'customer_id',
        'guest_name',
        'guest_email',
        'status',
        'assigned_to',
        'escalated_at',
        'resolved_at',
        'last_message_at',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_message_at' => 'datetime',
            'context' => 'array',
            'access_token_expires_at' => 'datetime',
            'access_token_revoked_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('created_at');
    }
}
