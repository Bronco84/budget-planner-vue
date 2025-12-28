<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'budget_id',
        'name',
        'description',
        'amount',
        'color',
        'order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var list<string>
     */
    protected $appends = [
        // Removed percent_used and remaining_amount to prevent N+1 queries
        // These are calculated in controllers when needed
    ];

    /**
     * Get the budget that owns the category.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }


    /**
     * Scope a query to order categories by their order field.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('ordered', function ($query) {
            $query->orderBy('order');
        });
    }

    /**
     * Get the percentage used of the category budget.
     *
     * @return float
     */
    public function getPercentUsedAttribute(): float
    {
        if ($this->amount > 0) {
            // Calculate spent from transactions (negative amounts are expenses)
            $spent = $this->budget->transactions()
                ->where('category', $this->name)
                ->where('amount_in_cents', '<', 0)
                ->sum('amount_in_cents');
            $spentDollars = abs($spent) / 100; // Convert cents to dollars
            return ($spentDollars / $this->amount) * 100;
        }
        return 0;
    }

    /**
     * Get the remaining amount for the category.
     *
     * @return float
     */
    public function getRemainingAmountAttribute(): float
    {
        // Calculate spent from transactions (negative amounts are expenses)
        $spent = $this->budget->transactions()
            ->where('category', $this->name)
            ->where('amount_in_cents', '<', 0)
            ->sum('amount_in_cents');
        $spentDollars = abs($spent) / 100; // Convert cents to dollars
        return $this->amount - $spentDollars;
    }
}
