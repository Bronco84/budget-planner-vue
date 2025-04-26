<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Budget extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'total_amount',
        'start_date',
        'end_date',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    /**
     * Get the user that owns the budget.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get users connected to this budget.
     */
    public function connected_users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Get the categories for the budget.
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * Get the transactions for the budget.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the remaining amount for the budget.
     */
    public function getRemainingAmountAttribute()
    {
        $spent = $this->transactions()->sum('amount');
        return $this->total_amount - $spent;
    }

    /**
     * Get the percentage used of the budget.
     */
    public function getPercentUsedAttribute()
    {
        if ($this->total_amount > 0) {
            $spent = $this->transactions()->sum('amount');
            return ($spent / $this->total_amount) * 100;
        }
        return 0;
    }
}
