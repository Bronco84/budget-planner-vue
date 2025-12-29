<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Scenario extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'budget_id',
        'user_id',
        'name',
        'description',
        'color',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the budget that owns the scenario.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Get the user that created the scenario.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the adjustments for this scenario.
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(ScenarioAdjustment::class);
    }

    /**
     * Get array of account IDs that have adjustments in this scenario.
     *
     * @return array<int>
     */
    public function getAffectedAccountIds(): array
    {
        return $this->adjustments()
            ->distinct()
            ->pluck('account_id')
            ->toArray();
    }

    /**
     * Calculate projection for a specific account with this scenario applied.
     *
     * @param Account $account
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param array $baseProjectedTransactions Base transactions without scenario
     * @return array Projection data with 'days' key
     */
    public function calculateProjectionForAccount(
        Account $account,
        Carbon $startDate,
        Carbon $endDate,
        array $baseProjectedTransactions
    ): array {
        // Get adjustments for this account
        $accountAdjustments = $this->adjustments()
            ->where('account_id', $account->id)
            ->get();

        if ($accountAdjustments->isEmpty()) {
            // No adjustments for this account, return base projection
            return $baseProjectedTransactions;
        }

        // Generate additional transactions from adjustments
        $additionalTransactions = [];
        foreach ($accountAdjustments as $adjustment) {
            $adjustmentTransactions = $adjustment->generateProjectedTransactions($startDate, $endDate);
            $additionalTransactions = array_merge($additionalTransactions, $adjustmentTransactions);
        }

        // Merge with base transactions
        $allTransactions = array_merge($baseProjectedTransactions, $additionalTransactions);

        // Sort by date
        usort($allTransactions, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        return $allTransactions;
    }

    /**
     * Toggle the active state of this scenario.
     *
     * @return bool New active state
     */
    public function toggle(): bool
    {
        $this->is_active = !$this->is_active;
        $this->save();
        return $this->is_active;
    }
}
