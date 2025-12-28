<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class CalendarEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'calendar_connection_id',
        'user_id',
        'google_event_id',
        'ical_uid',
        'title',
        'description',
        'start_date',
        'end_date',
        'all_day',
        'location',
        'color_id',
        'google_updated_at',
        'metadata',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'all_day' => 'boolean',
        'google_updated_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get the calendar connection that owns the event.
     */
    public function calendarConnection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class);
    }

    /**
     * Get the user that owns the event.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to get events within a date range.
     */
    public function scopeInDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->where(function ($q) use ($startDate, $endDate) {
            $q->whereBetween('start_date', [$startDate, $endDate])
              ->orWhereBetween('end_date', [$startDate, $endDate])
              ->orWhere(function ($q2) use ($startDate, $endDate) {
                  $q2->where('start_date', '<=', $startDate)
                     ->where('end_date', '>=', $endDate);
              });
        });
    }

    /**
     * Scope to get upcoming events.
     */
    public function scopeUpcoming($query, int $days = 30)
    {
        return $query->where('start_date', '>=', now())
                     ->where('start_date', '<=', now()->addDays($days))
                     ->orderBy('start_date');
    }

    /**
     * Check if the event is happening today.
     */
    public function isToday(): bool
    {
        return $this->start_date->isToday();
    }

    /**
     * Check if the event is in the past.
     */
    public function isPast(): bool
    {
        return $this->end_date ? $this->end_date->isPast() : $this->start_date->isPast();
    }

    /**
     * Get days until the event.
     */
    public function daysUntil(): ?int
    {
        if ($this->isPast()) {
            return null;
        }

        return (int) now()->diffInDays($this->start_date, false);
    }
}
