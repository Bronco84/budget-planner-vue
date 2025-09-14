<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 
        'theme', 
        'notifications_enabled', 
        'show_balance_projection', 
        'other_preferences'
    ];

    protected $casts = [
        'notifications_enabled' => 'boolean',
        'show_balance_projection' => 'boolean',
        'other_preferences' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get a preference value for a user from other_preferences JSON
     */
    public static function get(int $userId, string $key, mixed $default = null): mixed
    {
        $userPref = static::firstOrCreate(['user_id' => $userId], [
            'theme' => 'light',
            'notifications_enabled' => true,
            'show_balance_projection' => true,
            'other_preferences' => []
        ]);
        
        return $userPref->other_preferences[$key] ?? $default;
    }

    /**
     * Set a preference value for a user in other_preferences JSON
     */
    public static function set(int $userId, string $key, mixed $value): void
    {
        $userPref = static::firstOrCreate(['user_id' => $userId], [
            'theme' => 'light',
            'notifications_enabled' => true,
            'show_balance_projection' => true,
            'other_preferences' => []
        ]);
        
        $otherPrefs = $userPref->other_preferences ?? [];
        $otherPrefs[$key] = $value;
        
        $userPref->update(['other_preferences' => $otherPrefs]);
    }
}