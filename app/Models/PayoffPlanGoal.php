<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoffPlanGoal extends Model
{
    protected $fillable = [
        'payoff_plan_id',
        'name',
        'description',
        'target_amount_cents',
        'monthly_contribution_cents',
        'target_date',
        'goal_type',
    ];

    protected $casts = [
        'target_amount_cents' => 'integer',
        'monthly_contribution_cents' => 'integer',
        'target_date' => 'date',
    ];

    /**
     * Get the payoff plan that owns this goal.
     */
    public function payoffPlan(): BelongsTo
    {
        return $this->belongsTo(PayoffPlan::class);
    }
}