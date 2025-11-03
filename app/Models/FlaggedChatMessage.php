<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlaggedChatMessage extends Model
{
    protected $fillable = [
        'user_id',
        'conversation_id',
        'message_id',
        'flag_type',
        'content',
        'metadata',
        'reviewed',
        'action_taken',
    ];

    protected $casts = [
        'metadata' => 'array',
        'reviewed' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }
}
