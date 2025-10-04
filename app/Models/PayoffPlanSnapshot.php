<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoffPlanSnapshot extends Model
{
    protected $fillable = [
        'payoff_plan_id',
        'snapshot_date',
        'debt_balances',
        'goal_progress',
        'total_paid_cents',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'debt_balances' => 'array',
        'goal_progress' => 'array',
        'total_paid_cents' => 'integer',
    ];

    /**
     * Get the payoff plan that owns this snapshot.
     */
    public function payoffPlan(): BelongsTo
    {
        return $this->belongsTo(PayoffPlan::class);
    }
}