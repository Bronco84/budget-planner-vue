<?php

namespace App\Models;

use App\Services\PayoffPlanService;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * What is owed on the debt's account right now, as positive cents. Falls
     * back to the starting balance when the account is not available.
     */
    protected function currentBalanceCents(): Attribute
    {
        return Attribute::get(fn () => $this->account
            ? PayoffPlanService::debtBalanceCents($this->account)
            : (int) $this->starting_balance_cents);
    }

    /**
     * How much the debt has been paid down since the plan started (negative
     * when the balance has grown).
     */
    protected function paidDownCents(): Attribute
    {
        return Attribute::get(fn () => (int) $this->starting_balance_cents - $this->current_balance_cents);
    }
}
