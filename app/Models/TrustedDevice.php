<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class TrustedDevice extends Model
{
    protected $fillable = [
        'user_id',
        'device_name',
        'device_fingerprint',
        'device_token',
        'ip_address',
        'user_agent',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the user that owns the device.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the device is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if the device is valid (not expired).
     */
    public function isValid(): bool
    {
        return !$this->isExpired();
    }

    /**
     * Update the last used timestamp.
     */
    public function markAsUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Scope to get only valid (non-expired) devices.
     */
    public function scopeValid($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope to get expired devices.
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Get a human-readable "last used" time.
     */
    public function getLastUsedHumanAttribute(): string
    {
        if (!$this->last_used_at) {
            return 'Never';
        }

        return $this->last_used_at->diffForHumans();
    }

    /**
     * Check if this is the current device.
     */
    public function isCurrentDevice(string $fingerprint): bool
    {
        return $this->device_fingerprint === $fingerprint;
    }
}

