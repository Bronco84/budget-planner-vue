<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CalendarConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'provider',
        'calendar_id',
        'calendar_name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'last_synced_at',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * Get the user that owns the calendar connection.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the events for this calendar connection.
     */
    public function events(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    /**
     * Check if the access token is expired.
     */
    public function isTokenExpired(): bool
    {
        if (!$this->token_expires_at) {
            return false;
        }

        return $this->token_expires_at->isPast();
    }

    /**
     * Mark the connection as synced.
     */
    public function markSynced(): void
    {
        $this->update(['last_synced_at' => now()]);
    }
}
