<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoffPlanDebt extends Model
{
    protected $fillable = [
        'payoff_plan_id',
        'account_id',
        'starting_balance_cents',
        'interest_rate',
        'minimum_payment_cents',
        'priority',
    ];

    protected $casts = [
        'starting_balance_cents' => 'integer',
        'interest_rate' => 'decimal:2',
        'minimum_payment_cents' => 'integer',
        'priority' => 'integer',
    ];

    /**
     * Get the payoff plan that owns this debt.
     */
    public function payoffPlan(): BelongsTo
    {
        return $this->belongsTo(PayoffPlan::class);
    }

    /**
     * Get the account associated with this debt.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}