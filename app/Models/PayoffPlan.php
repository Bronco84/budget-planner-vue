<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoffPlan extends Model
{
    protected $fillable = [
        'budget_id',
        'name',
        'description',
        'strategy',
        'monthly_extra_payment_cents',
        'is_active',
        'start_date',
    ];

    protected $casts = [
        'monthly_extra_payment_cents' => 'integer',
        'is_active' => 'boolean',
        'start_date' => 'date',
    ];

    /**
     * Get the budget that owns this payoff plan.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the debts included in this payoff plan.
     */
    public function debts(): HasMany
    {
        return $this->hasMany(PayoffPlanDebt::class);
    }

    /**
     * Get the financial goals for this payoff plan.
     */
    public function goals(): HasMany
    {
        return $this->hasMany(PayoffPlanGoal::class);
    }

    /**
     * Get the historical snapshots for this payoff plan.
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(PayoffPlanSnapshot::class);
    }
}