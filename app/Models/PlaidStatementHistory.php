<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaidStatementHistory extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'plaid_account_id',
        'statement_balance_cents',
        'statement_issue_date',
        'payment_due_date',
        'minimum_payment_cents',
        'apr_percentage',
        'credit_utilization_percentage',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'statement_balance_cents' => 'integer',
        'statement_issue_date' => 'date',
        'payment_due_date' => 'date',
        'minimum_payment_cents' => 'integer',
        'apr_percentage' => 'decimal:2',
        'credit_utilization_percentage' => 'decimal:2',
    ];

    /**
     * Get the PlaidAccount that this statement history belongs to.
     */
    public function plaidAccount(): BelongsTo
    {
        return $this->belongsTo(PlaidAccount::class);
    }

    /**
     * Calculate credit utilization percentage.
     *
     * @param int $statementBalanceCents
     * @param int|null $creditLimitCents
     * @return float|null
     */
    public static function calculateCreditUtilization(int $statementBalanceCents, ?int $creditLimitCents): ?float
    {
        if (!$creditLimitCents || $creditLimitCents <= 0) {
            return null;
        }

        $utilization = ($statementBalanceCents / $creditLimitCents) * 100;
        return round($utilization, 2);
    }

    /**
     * Get credit utilization percentage for this statement.
     *
     * @return float|null
     */
    public function getCreditUtilization(): ?float
    {
        return $this->credit_utilization_percentage;
    }
}
