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
     * @var array<int, string>
     */
    protected $fillable = [
        'budget_id',
        'name',
        'description',
        'amount',
        'color',
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
     * Get the budget that owns the category.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the expenses for the category.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Get the percentage used of the category budget.
     */
    public function getPercentUsedAttribute()
    {
        if ($this->amount > 0) {
            $spent = $this->expenses()->sum('amount');
            return ($spent / $this->amount) * 100;
        }
        return 0;
    }

    /**
     * Get the remaining amount for the category.
     */
    public function getRemainingAmountAttribute()
    {
        $spent = $this->expenses()->sum('amount');
        return $this->amount - $spent;
    }
}
