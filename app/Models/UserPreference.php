<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'theme',
        'notifications_enabled',
        'show_balance_projection',
        'other_preferences',
    ];

    protected $casts = [
        'notifications_enabled' => 'boolean',
        'show_balance_projection' => 'boolean',
        'other_preferences' => 'array',
    ];

    /**
     * Get the user that owns the preference.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get a user's preference value from the other_preferences JSON column.
     */
    public static function getUserPreference(int $userId, string $key, $default = null)
    {
        $preference = static::where('user_id', $userId)->first();

        if (!$preference) {
            return $default;
        }

        // Check built-in preference columns first
        if (in_array($key, ['theme', 'notifications_enabled', 'show_balance_projection'])) {
            return $preference->{$key};
        }

        // Check other_preferences JSON column
        $otherPrefs = $preference->other_preferences ?? [];
        return $otherPrefs[$key] ?? $default;
    }

    /**
     * Set a user's preference value.
     */
    public static function setUserPreference(int $userId, string $key, $value): void
    {
        $preference = static::firstOrCreate(['user_id' => $userId]);

        // Check if it's a built-in preference column
        if (in_array($key, ['theme', 'notifications_enabled', 'show_balance_projection'])) {
            $preference->{$key} = $value;
            $preference->save();
            return;
        }

        // Store in other_preferences JSON column
        $otherPrefs = $preference->other_preferences ?? [];
        $otherPrefs[$key] = $value;
        $preference->other_preferences = $otherPrefs;
        $preference->save();
    }
}
