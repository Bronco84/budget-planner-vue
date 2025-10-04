<?php

namespace App\Services;

use App\Models\RecurringTransactionTemplate;
use App\Models\RecurringTransactionRule;
use App\Models\Transaction;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class RecurringTransactionService
{
    public function projectTransactions(Account $account, Carbon $startDate, Carbon $endDate)
    {
        $projectedTransactions = collect();

        // Get all active recurring transaction templates with their transactions preloaded
        $templates = RecurringTransactionTemplate::where('account_id', $account->id)
            ->where(function ($query) use ($startDate) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $startDate);
            })
            ->with('transactions')
            ->get();

        foreach ($templates as $template) {

            // Start from today or template's start date, whichever is later
            $date = max($startDate, $template->start_date)->copy()->startOfDay();

            // End at the earlier of endDate or template's end_date
            $templateEndDate = $template->end_date ? min($endDate, $template->end_date) : $endDate;

            // For weekly and biweekly transactions, move to the next occurrence of the specified day
            if ($template->frequency === 'weekly' || $template->frequency === 'biweekly') {
                while ($date->dayOfWeek !== $template->day_of_week) {
                    $date->addDay();
                }
            }

            // Generate all occurrences
            while ($date <= $templateEndDate)
            {
                if ($template->shouldGenerateForDate($date)) {
                    // Determine the amount for this transaction
                    $existingTransaction = $this->getTransactionForDate($template, $date);

                    if ($existingTransaction) {
                        // If there are future transactions, use the existing amount
                        $amount = $existingTransaction->amount_in_cents;
                    } else {
                        // If this is a dynamic amount template, calculate it based on rules
                        if ($template->is_dynamic_amount) {
                            $calculatedAmount = $this->calculateDynamicAmount($template);

                            // If we have a valid calculated amount, use it
                            if ($calculatedAmount !== null) {
                                $amount = $calculatedAmount;
                            }
                        } else {
                            $amount = $template->amount_in_cents;
                        }
                    }


                    $projectedTransactions->push([
                        'account' => $account,
                        'budget_id' => $template->budget_id,
                        'description' => $template->description,
                        'amount_in_cents' => $amount,
                        'category' => $template->category,
                        'account_id' => $template->account_id,
                        'date' => $date->copy(),
                        'recurring_transaction_template_id' => $template->id,
                        'is_dynamic_amount' => $template->is_dynamic_amount,
                        'created_by' => $template->created_by ?? null,
                        'is_projected' => true
                    ]);
                }

                $date->addDay();
            }
        }

        return $projectedTransactions->sortBy('date')->values();
    }

    /**
     * Get transaction for a specific date from template's preloaded transactions.
     *
     * @param RecurringTransactionTemplate $template
     * @param Carbon $date
     * @return Transaction|null
     */
    protected function getTransactionForDate(RecurringTransactionTemplate $template, Carbon $date): ?Transaction
    {
        $dateString = $date->copy()->format('Y-m-d');

        // If transactions are loaded, use the collection
        if ($template->relationLoaded('transactions')) {
            return $template->transactions->first(function ($transaction) use ($dateString) {
                return $transaction->date && $transaction->date->format('Y-m-d') === $dateString;
            });
        }

        // Fallback to database query if relationship not loaded
        return $template->transactions()->where('date', $dateString)->first();
    }

    /**
     * Calculate the dynamic amount for a recurring transaction template based on rules.
     *
     * @param RecurringTransactionTemplate $template
     * @return int|null The calculated amount in cents or null if not calculable
     */
    protected function calculateDynamicAmount(RecurringTransactionTemplate $template): ?int
    {
        $rules = $template->rules()->where('is_active', true)->get();

        if ($rules->isEmpty()) {
            if ($template->average_amount !== null) {
                return (int)($template->average_amount * 100);
            }
            return $template->amount_in_cents;
        }

        $matchingTransactions = $this->findTransactionsMatchingAllRules($template, $rules);

        Log::debug($rules);

        Log::debug($matchingTransactions);

        if ($matchingTransactions->isEmpty()) {
            if ($template->average_amount !== null) {
                $amount = (int)($template->average_amount * 100);
                return $amount;
            }

            return $template->amount_in_cents;
        }

        // Filter transactions based on min/max logic BEFORE averaging
        $filteredTransactions = $matchingTransactions->filter(function ($transaction) use ($template) {
            $amount = $transaction->amount_in_cents;
            $absAmount = abs($amount); // Use absolute value for comparison

            // Check against min amount (convert to absolute for comparison)
            if ($template->min_amount !== null) {
                $minAmount = abs($template->min_amount);
                if ($absAmount < $minAmount) {
                    return false; // Exclude if lower than allowed min
                }
            }

            // Check against max amount (convert to absolute for comparison)
            if ($template->max_amount !== null) {
                $maxAmount = abs($template->max_amount);
                if ($absAmount > $maxAmount) {
                    return false; // Exclude if higher than allowed max
                }
            }

            return true; // Include if passes min/max checks
        });

        if ($filteredTransactions->isEmpty()) {
            // If no transactions left after filtering, fallback
            if ($template->average_amount !== null) {
                $amount = (int)($template->average_amount * 100);
                return $amount;
            }

            return $template->amount_in_cents;
        }

        // Calculate average of only the valid transactions
        $totalAmount = $filteredTransactions->sum('amount_in_cents');
        $averageAmount = (int)($totalAmount / $filteredTransactions->count());

        return $averageAmount;
    }



    /**
     * Find transactions that match all the provided rules for a template.
     *
     * @param RecurringTransactionTemplate $template
     * @param \Illuminate\Support\Collection $rules
     * @return \Illuminate\Support\Collection
     */
    protected function findTransactionsMatchingAllRules(RecurringTransactionTemplate $template, Collection $rules): Collection
    {
        // Get the budget ID from the template
        $budgetId = $template->budget_id;

        // Get transactions for this budget
        $transactions = Transaction::where('budget_id', $budgetId)->get();

        // Filter transactions that match all rules
        $matchingTransactions = $transactions->filter(function (Transaction $transaction) use ($rules) {
            // Transaction must match ALL rules (AND logic)
            foreach ($rules as $rule) {
                $fieldValue = $this->getTransactionFieldValue($transaction, $rule->field);
                $ruleValue = $rule->value;
                $matches = $this->evaluateRuleCondition($fieldValue, $rule->operator, $ruleValue);

                if (!$matches) {
                    return false;
                }
            }

            return true;
        });

        return $matchingTransactions;
    }

    /**
     * Link existing transactions that match the template
     * This is useful for identifying historical transactions that should be considered
     * when calculating dynamic amounts
     */
    public function linkMatchingTransactions(RecurringTransactionTemplate $template)
    {
        // Find transactions that match the template but aren't linked yet
        $matchingTransactions = Transaction::where('budget_id', $template->budget_id)
            ->where('recurring_transaction_template_id', null)
            ->where(function($query) use ($template) {
                // Match by description (exact match or contains)
                $query->where('description', $template->description)
                    ->orWhere('description', 'like', '%' . $template->description . '%');

                // If category exists, also match by that
                if ($template->category) {
                    $query->where('category', $template->category);
                }
            })
            ->get();

        // Link the matching transactions to this template
        foreach ($matchingTransactions as $transaction) {
            $transaction->recurring_transaction_template_id = $template->id;
            $transaction->save();
        }

        return $matchingTransactions->count();
    }

    /**
     * Link existing transactions that match the template's rules
     * This is specifically for dynamic amount transactions with rules
     */
    public function linkMatchingTransactionsByRules(RecurringTransactionTemplate $template)
    {
        if (!$template->is_dynamic_amount) {
            return 0;
        }

        $rules = $template->rules()->where('is_active', true)->get();
        if ($rules->isEmpty()) {
            return 0;
        }

        // Find transactions that match all rules
        $matchingTransactions = $this->findTransactionsMatchingAllRules($template, $rules);

        // Filter out transactions that are already linked to other templates
        $unlinkedTransactions = $matchingTransactions->filter(function ($transaction) {
            return $transaction->recurring_transaction_template_id === null;
        });

        // Link the matching transactions to this template
        $linkedCount = 0;
        foreach ($unlinkedTransactions as $transaction) {
            $transaction->recurring_transaction_template_id = $template->id;
            $transaction->save();
            $linkedCount++;
        }

        return $linkedCount;
    }

    /**
     * Re-evaluate and update all transaction links for a template based on current rules
     * This will unlink transactions that no longer match and link new ones that do
     */
    public function reevaluateTransactionLinks(RecurringTransactionTemplate $template)
    {
        $rules = $template->rules()->where('is_active', true)->get();

        // If no rules, unlink all transactions
        if ($rules->isEmpty()) {
            $unlinkedCount = Transaction::where('recurring_transaction_template_id', $template->id)
                ->update(['recurring_transaction_template_id' => null]);
            return ['unlinked' => $unlinkedCount, 'linked' => 0];
        }

        // Find all transactions that currently match the rules
        $matchingTransactions = $this->findTransactionsMatchingAllRules($template, $rules);
        $matchingIds = $matchingTransactions->pluck('id')->toArray();

        // Get currently linked transactions
        $currentlyLinked = Transaction::where('recurring_transaction_template_id', $template->id)->get();

        // Unlink transactions that no longer match
        $unlinkedCount = 0;
        foreach ($currentlyLinked as $transaction) {
            if (!in_array($transaction->id, $matchingIds)) {
                $transaction->recurring_transaction_template_id = null;
                $transaction->save();
                $unlinkedCount++;
            }
        }

        // Link transactions that match but aren't linked yet
        $linkedCount = 0;
        foreach ($matchingTransactions as $transaction) {
            // Only link if not already linked to any template
            if ($transaction->recurring_transaction_template_id === null) {
                $transaction->recurring_transaction_template_id = $template->id;
                $transaction->save();
                $linkedCount++;
            }
        }

        return ['unlinked' => $unlinkedCount, 'linked' => $linkedCount];
    }

    /**
     * Get the value of a transaction field for comparison with a rule
     *
     * @param Transaction $transaction
     * @param string $field
     * @return mixed
     */
    protected function getTransactionFieldValue(Transaction $transaction, string $field)
    {
        switch ($field) {
            case 'description':
                return $transaction->description;
            case 'amount':
                return $transaction->amount_in_cents;
            case 'category':
                return $transaction->category;
            case 'date':
                return $transaction->date ? $transaction->date->format('Y-m-d') : null;
            case 'account_id':
                return $transaction->account_id;
            default:
                return null;
        }
    }

    /**
     * Evaluate if a transaction field value matches a rule condition
     *
     * @param mixed $fieldValue
     * @param string $operator
     * @param mixed $ruleValue
     * @return bool
     */
    protected function evaluateRuleCondition($fieldValue, string $operator, $ruleValue): bool
    {
        switch ($operator) {
            case '=':
            case 'equals':
                return $fieldValue == $ruleValue;
            case '!=':
                return $fieldValue != $ruleValue;
            case '>':
            case 'greater_than':
                return is_numeric($fieldValue) && is_numeric($ruleValue) && $fieldValue > $ruleValue;
            case '<':
            case 'less_than':
                return is_numeric($fieldValue) && is_numeric($ruleValue) && $fieldValue < $ruleValue;
            case '>=':
                return is_numeric($fieldValue) && is_numeric($ruleValue) && $fieldValue >= $ruleValue;
            case '<=':
                return is_numeric($fieldValue) && is_numeric($ruleValue) && $fieldValue <= $ruleValue;
            case 'contains':
                return is_string($fieldValue) && is_string($ruleValue) &&
                    stripos($fieldValue, $ruleValue) !== false;
            case 'not_contains':
                return is_string($fieldValue) && is_string($ruleValue) &&
                    stripos($fieldValue, $ruleValue) === false;
            case 'starts_with':
                return is_string($fieldValue) && is_string($ruleValue) &&
                    stripos($fieldValue, $ruleValue) === 0;
            case 'ends_with':
                return is_string($fieldValue) && is_string($ruleValue) &&
                    stripos($fieldValue, $ruleValue, max(0, strlen($fieldValue) - strlen($ruleValue))) !== false;
            case 'regex':
                if (!is_string($fieldValue) || !is_string($ruleValue)) {
                    return false;
                }
                try {
                    return preg_match('/' . $ruleValue . '/i', $fieldValue) === 1;
                } catch (\Exception $e) {
                    Log::warning('Invalid regex pattern in rule evaluation', [
                        'pattern' => $ruleValue,
                        'error' => $e->getMessage()
                    ]);
                    return false;
                }
            default:
                return false;
        }
    }

    /**
     * Find a recurring transaction template by ID
     *
     * @param int $id
     * @return RecurringTransactionTemplate|null
     */
    public function find(int $id): ?RecurringTransactionTemplate
    {
        return RecurringTransactionTemplate::with(['rules', 'account', 'budget'])->find($id);
    }

    /**
     * Get active rules for a recurring transaction template
     *
     * @param RecurringTransactionTemplate $template
     * @return Collection
     */
    public function getRules(RecurringTransactionTemplate $template): Collection
    {
        return $template->rules()->where('is_active', true)->orderBy('priority', 'desc')->get();
    }

    /**
     * Evaluate a single rule against sample transactions and return detailed results
     *
     * @param RecurringTransactionTemplate $template
     * @param RecurringTransactionRule $rule
     * @return array
     */
    public function evaluateRule(RecurringTransactionTemplate $template, RecurringTransactionRule $rule): array
    {
        $budgetId = $template->budget_id;
        
        // Get sample transactions from the budget
        $transactions = Transaction::where('budget_id', $budgetId)
            ->orderBy('date', 'desc')
            ->limit(100) // Limit to recent transactions for performance
            ->get();

        $matchingTransactions = collect();
        $totalTransactions = $transactions->count();
        $matchCount = 0;

        foreach ($transactions as $transaction) {
            $fieldValue = $this->getTransactionFieldValue($transaction, $rule->field);
            $matches = $this->evaluateRuleCondition($fieldValue, $rule->operator, $rule->value);
            
            if ($matches) {
                $matchingTransactions->push($transaction);
                $matchCount++;
            }
        }

        // Generate human-readable description
        $description = $this->generateRuleDescription($rule);
        
        // Calculate statistics
        $matchPercentage = $totalTransactions > 0 ? round(($matchCount / $totalTransactions) * 100, 1) : 0;
        
        $details = [];
        $details[] = "Tested against {$totalTransactions} recent transactions";
        $details[] = "Found {$matchCount} matching transactions ({$matchPercentage}%)";
        
        $amountStats = null;
        if ($matchingTransactions->isNotEmpty()) {
            $amounts = $matchingTransactions->pluck('amount_in_cents');
            $avgAmount = $amounts->avg();
            $minAmount = $amounts->min();
            $maxAmount = $amounts->max();
            $medianAmount = $amounts->median();
            $stdDev = $this->calculateStandardDeviation($amounts->toArray());
            
            $amountStats = [
                'average' => $avgAmount,
                'median' => $medianAmount,
                'min' => $minAmount,
                'max' => $maxAmount,
                'std_dev' => $stdDev,
                'count' => $matchCount
            ];
            
            $details[] = "Amount range: $" . number_format($minAmount / 100, 2) . " to $" . number_format($maxAmount / 100, 2);
            $details[] = "Average amount: $" . number_format($avgAmount / 100, 2);
            $details[] = "Median amount: $" . number_format($medianAmount / 100, 2);
            $details[] = "Standard deviation: $" . number_format($stdDev / 100, 2);
            
            // Show a few example matches
            $examples = $matchingTransactions->take(3);
            $details[] = "Recent matches:";
            foreach ($examples as $example) {
                $details[] = "  - {$example->description} ($" . number_format($example->amount_in_cents / 100, 2) . ") on {$example->date->format('Y-m-d')}";
            }
        }

        return [
            'passed' => $matchCount > 0,
            'description' => $description,
            'match_count' => $matchCount,
            'total_transactions' => $totalTransactions,
            'match_percentage' => $matchPercentage,
            'matching_transactions' => $matchingTransactions,
            'amount_stats' => $amountStats,
            'details' => implode("\n", $details)
        ];
    }

    /**
     * Generate a human-readable description of a rule
     *
     * @param RecurringTransactionRule $rule
     * @return string
     */
    protected function generateRuleDescription(RecurringTransactionRule $rule): string
    {
        $field = ucfirst($rule->field);
        $operator = $this->getOperatorDescription($rule->operator);
        $value = $rule->value;
        
        // Format value based on field type
        if ($rule->field === 'amount') {
            $value = '$' . number_format($value / 100, 2);
        }
        
        return "{$field} {$operator} '{$value}'";
    }

    /**
     * Get human-readable operator description
     *
     * @param string $operator
     * @return string
     */
    protected function getOperatorDescription(string $operator): string
    {
        $descriptions = [
            '=' => 'equals',
            'equals' => 'equals',
            '!=' => 'does not equal',
            '>' => 'is greater than',
            'greater_than' => 'is greater than',
            '<' => 'is less than', 
            'less_than' => 'is less than',
            '>=' => 'is greater than or equal to',
            '<=' => 'is less than or equal to',
            'contains' => 'contains',
            'not_contains' => 'does not contain',
            'starts_with' => 'starts with',
            'ends_with' => 'ends with',
            'regex' => 'matches pattern'
        ];
        
        return $descriptions[$operator] ?? $operator;
    }

    /**
     * Calculate the standard deviation of an array of values
     *
     * @param array $values
     * @return float
     */
    protected function calculateStandardDeviation(array $values): float
    {
        if (count($values) <= 1) {
            return 0;
        }
        
        $mean = array_sum($values) / count($values);
        $squaredDifferences = array_map(function($value) use ($mean) {
            return pow($value - $mean, 2);
        }, $values);
        
        $variance = array_sum($squaredDifferences) / count($values);
        return sqrt($variance);
    }

    /**
     * Detect outliers in transaction amounts using IQR method
     *
     * @param Collection $amounts
     * @return array
     */
    protected function detectOutliers(Collection $amounts): array
    {
        if ($amounts->count() < 4) {
            return ['outliers' => [], 'clean_amounts' => $amounts];
        }
        
        $sorted = $amounts->sort()->values();
        $count = $sorted->count();
        
        // Calculate quartiles
        $q1Index = (int)floor(($count - 1) * 0.25);
        $q3Index = (int)floor(($count - 1) * 0.75);
        
        $q1 = $sorted[$q1Index];
        $q3 = $sorted[$q3Index];
        $iqr = $q3 - $q1;
        
        // Define outlier bounds
        $lowerBound = $q1 - (1.5 * $iqr);
        $upperBound = $q3 + (1.5 * $iqr);
        
        $outliers = [];
        $cleanAmounts = collect();
        
        foreach ($amounts as $amount) {
            if ($amount < $lowerBound || $amount > $upperBound) {
                $outliers[] = $amount;
            } else {
                $cleanAmounts->push($amount);
            }
        }
        
        return [
            'outliers' => $outliers,
            'clean_amounts' => $cleanAmounts,
            'outlier_bounds' => ['lower' => $lowerBound, 'upper' => $upperBound]
        ];
    }
    
    /**
     * Calculate trend analysis for transaction amounts over time
     *
     * @param Collection $transactions
     * @return array
     */
    protected function calculateTrendAnalysis(Collection $transactions): array
    {
        if ($transactions->count() < 3) {
            return [
                'trend' => 'insufficient_data',
                'trend_direction' => 'stable',
                'trend_strength' => 0,
                'monthly_change_percent' => 0
            ];
        }
        
        // Sort by date ascending
        $sortedTransactions = $transactions->sortBy('date');
        $amounts = $sortedTransactions->pluck('amount_in_cents')->values();
        $dates = $sortedTransactions->pluck('date')->values();
        
        // Simple linear regression to detect trend
        $n = $amounts->count();
        $x = collect(range(1, $n)); // Time indices
        $y = $amounts;
        
        $sumX = $x->sum();
        $sumY = $y->sum();
        $sumXY = $x->zip($y)->map(function ($pair) {
            return $pair[0] * $pair[1];
        })->sum();
        $sumX2 = $x->map(function ($val) {
            return $val * $val;
        })->sum();
        
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;
        
        // Calculate correlation coefficient for trend strength
        $meanX = $sumX / $n;
        $meanY = $sumY / $n;
        
        $numerator = $x->zip($y)->map(function ($pair) use ($meanX, $meanY) {
            return ($pair[0] - $meanX) * ($pair[1] - $meanY);
        })->sum();
        
        $denomX = sqrt($x->map(function ($val) use ($meanX) {
            return pow($val - $meanX, 2);
        })->sum());
        
        $denomY = sqrt($y->map(function ($val) use ($meanY) {
            return pow($val - $meanY, 2);
        })->sum());
        
        $correlation = $denomX > 0 && $denomY > 0 ? $numerator / ($denomX * $denomY) : 0;
        
        // Determine trend direction and strength
        $trendDirection = 'stable';
        if (abs($slope) > abs($meanY) * 0.01) { // If slope is more than 1% of mean
            $trendDirection = $slope > 0 ? 'increasing' : 'decreasing';
        }
        
        // Calculate monthly change percentage
        $firstDate = $dates->first();
        $lastDate = $dates->last();
        $daysDiff = $firstDate->diffInDays($lastDate);
        $monthlyChangePercent = 0;
        
        if ($daysDiff > 0) {
            $totalChangePercent = (($amounts->last() - $amounts->first()) / abs($amounts->first())) * 100;
            $monthlyChangePercent = ($totalChangePercent / $daysDiff) * 30; // Approximate monthly change
        }
        
        return [
            'trend' => $trendDirection,
            'trend_direction' => $trendDirection,
            'trend_strength' => abs($correlation),
            'slope' => $slope,
            'correlation' => $correlation,
            'monthly_change_percent' => $monthlyChangePercent
        ];
    }
    
    /**
     * Calculate confidence score for amount prediction
     *
     * @param Collection $transactions
     * @param array $outlierAnalysis
     * @param array $trendAnalysis
     * @return array
     */
    protected function calculateConfidenceScore(Collection $transactions, array $outlierAnalysis, array $trendAnalysis): array
    {
        $score = 100; // Start with perfect confidence
        $factors = [];
        
        // Factor 1: Sample size
        $sampleSize = $transactions->count();
        if ($sampleSize < 3) {
            $score -= 50;
            $factors[] = 'Very small sample size (' . $sampleSize . ' transactions)';
        } elseif ($sampleSize < 6) {
            $score -= 25;
            $factors[] = 'Small sample size (' . $sampleSize . ' transactions)';
        } elseif ($sampleSize < 12) {
            $score -= 10;
            $factors[] = 'Moderate sample size (' . $sampleSize . ' transactions)';
        }
        
        // Factor 2: Data age (transactions older than 6 months get lower confidence)
        $oldestTransaction = $transactions->min('date');
        $newestTransaction = $transactions->max('date');
        $daysSinceNewest = now()->diffInDays($newestTransaction);
        $dataSpanDays = $oldestTransaction->diffInDays($newestTransaction);
        
        if ($daysSinceNewest > 90) {
            $score -= 20;
            $factors[] = 'Data is outdated (newest transaction is ' . $daysSinceNewest . ' days old)';
        } elseif ($daysSinceNewest > 30) {
            $score -= 10;
            $factors[] = 'Somewhat outdated data (newest transaction is ' . $daysSinceNewest . ' days old)';
        }
        
        // Factor 3: Outliers
        $outlierCount = count($outlierAnalysis['outliers']);
        $outlierPercent = $sampleSize > 0 ? ($outlierCount / $sampleSize) * 100 : 0;
        if ($outlierPercent > 20) {
            $score -= 25;
            $factors[] = 'High outlier rate (' . round($outlierPercent, 1) . '%)';
        } elseif ($outlierPercent > 10) {
            $score -= 15;
            $factors[] = 'Moderate outlier rate (' . round($outlierPercent, 1) . '%)';
        }
        
        // Factor 4: Trend volatility
        if ($trendAnalysis['trend_strength'] > 0.7) {
            if ($trendAnalysis['trend_direction'] !== 'stable') {
                $score -= 15;
                $factors[] = 'Strong ' . $trendAnalysis['trend_direction'] . ' trend detected';
            }
        }
        
        // Factor 5: Data consistency (coefficient of variation)
        $amounts = $transactions->pluck('amount_in_cents');
        $mean = $amounts->avg();
        $stdDev = $this->calculateStandardDeviation($amounts->toArray());
        $coefficientOfVariation = $mean != 0 ? ($stdDev / abs($mean)) * 100 : 0;
        
        if ($coefficientOfVariation > 50) {
            $score -= 20;
            $factors[] = 'High amount variability (CV: ' . round($coefficientOfVariation, 1) . '%)';
        } elseif ($coefficientOfVariation > 25) {
            $score -= 10;
            $factors[] = 'Moderate amount variability (CV: ' . round($coefficientOfVariation, 1) . '%)';
        }
        
        $score = max(0, min(100, $score)); // Clamp between 0-100
        
        return [
            'score' => $score,
            'level' => $score >= 80 ? 'high' : ($score >= 60 ? 'medium' : 'low'),
            'factors' => $factors,
            'metrics' => [
                'sample_size' => $sampleSize,
                'data_age_days' => $daysSinceNewest,
                'outlier_percentage' => $outlierPercent,
                'coefficient_of_variation' => $coefficientOfVariation,
                'trend_strength' => $trendAnalysis['trend_strength']
            ]
        ];
    }
    
    /**
     * Calculate improved dynamic amount using advanced analytics
     *
     * @param RecurringTransactionTemplate $template
     * @return array
     */
    protected function calculateImprovedDynamicAmount(RecurringTransactionTemplate $template): array
    {
        $rules = $template->rules()->where('is_active', true)->get();
        
        if ($rules->isEmpty()) {
            return [
                'amount' => $template->average_amount ? (int)($template->average_amount * 100) : $template->amount_in_cents,
                'method' => 'fallback',
                'confidence' => ['score' => 0, 'level' => 'low', 'factors' => ['No rules configured']]
            ];
        }
        
        $matchingTransactions = $this->findTransactionsMatchingAllRules($template, $rules);
        
        if ($matchingTransactions->isEmpty()) {
            return [
                'amount' => $template->average_amount ? (int)($template->average_amount * 100) : $template->amount_in_cents,
                'method' => 'fallback',
                'confidence' => ['score' => 0, 'level' => 'low', 'factors' => ['No matching transactions found']]
            ];
        }
        
        // Apply min/max filtering
        $filteredTransactions = $matchingTransactions->filter(function ($transaction) use ($template) {
            $amount = $transaction->amount_in_cents;
            $absAmount = abs($amount);
            
            if ($template->min_amount !== null && $absAmount < abs($template->min_amount)) {
                return false;
            }
            
            if ($template->max_amount !== null && $absAmount > abs($template->max_amount)) {
                return false;
            }
            
            return true;
        });
        
        if ($filteredTransactions->isEmpty()) {
            return [
                'amount' => $template->average_amount ? (int)($template->average_amount * 100) : $template->amount_in_cents,
                'method' => 'fallback',
                'confidence' => ['score' => 0, 'level' => 'low', 'factors' => ['All transactions filtered out by constraints']]
            ];
        }
        
        $amounts = $filteredTransactions->pluck('amount_in_cents');
        
        // Advanced analytics
        $outlierAnalysis = $this->detectOutliers($amounts);
        $trendAnalysis = $this->calculateTrendAnalysis($filteredTransactions);
        $confidenceAnalysis = $this->calculateConfidenceScore($filteredTransactions, $outlierAnalysis, $trendAnalysis);
        
        // Choose calculation method based on data quality
        $cleanAmounts = $outlierAnalysis['clean_amounts'];
        $projectedAmount = 0;
        $method = 'basic_average';
        
        if ($cleanAmounts->count() >= 3 && $confidenceAnalysis['score'] >= 60) {
            // Use weighted average favoring recent transactions
            $weightedSum = 0;
            $weightSum = 0;
            
            foreach ($filteredTransactions as $index => $transaction) {
                $amount = $transaction->amount_in_cents;
                
                // Skip outliers for weighted calculation
                if (in_array($amount, $outlierAnalysis['outliers'])) {
                    continue;
                }
                
                // Weight more recent transactions higher
                $daysAgo = now()->diffInDays($transaction->date);
                $weight = 1 / (1 + $daysAgo / 30); // Exponential decay over months
                
                $weightedSum += $amount * $weight;
                $weightSum += $weight;
            }
            
            $projectedAmount = $weightSum > 0 ? (int)($weightedSum / $weightSum) : $cleanAmounts->avg();
            $method = 'weighted_average_no_outliers';
            
            // Apply trend adjustment if trend is strong
            if ($trendAnalysis['trend_strength'] > 0.6 && abs($trendAnalysis['monthly_change_percent']) > 5) {
                $trendAdjustment = $projectedAmount * ($trendAnalysis['monthly_change_percent'] / 100);
                $projectedAmount += $trendAdjustment;
                $method = 'trend_adjusted_weighted_average';
            }
        } else {
            // Fall back to simple average of clean data
            $projectedAmount = $cleanAmounts->isEmpty() ? $amounts->avg() : $cleanAmounts->avg();
            $method = $cleanAmounts->isEmpty() ? 'basic_average' : 'outlier_filtered_average';
        }
        
        return [
            'amount' => (int)$projectedAmount,
            'method' => $method,
            'confidence' => $confidenceAnalysis,
            'outlier_analysis' => $outlierAnalysis,
            'trend_analysis' => $trendAnalysis,
            'sample_size' => $filteredTransactions->count(),
            'clean_sample_size' => $cleanAmounts->count()
        ];
    }
    
    /**
     * Get comprehensive amount analysis for a template based on all its rules
     *
     * @param RecurringTransactionTemplate $template
     * @return array
     */
    public function getAmountAnalysis(RecurringTransactionTemplate $template): array
    {
        if (!$template->is_dynamic_amount) {
            return [
                'is_dynamic' => false,
                'fixed_amount' => $template->amount_in_cents,
                'projected_amount' => $template->amount_in_cents
            ];
        }
        
        // Use the improved calculation method
        $improvedCalculation = $this->calculateImprovedDynamicAmount($template);
        $rules = $this->getRules($template);
        
        $baseResult = [
            'is_dynamic' => true,
            'has_rules' => $rules->isNotEmpty(),
            'rules_count' => $rules->count(),
            'projected_amount' => $improvedCalculation['amount'],
            'calculation_method' => $improvedCalculation['method'],
            'confidence' => $improvedCalculation['confidence'],
            'fallback_used' => $improvedCalculation['method'] === 'fallback'
        ];
        
        if ($improvedCalculation['method'] === 'fallback') {
            $baseResult['fallback_type'] = $template->average_amount ? 'stored_average' : 'template_default';
            return $baseResult;
        }
        
        // Add detailed analytics for successful calculations
        $matchingTransactions = $this->findTransactionsMatchingAllRules($template, $rules);
        $filteredTransactions = $matchingTransactions->filter(function ($transaction) use ($template) {
            $amount = $transaction->amount_in_cents;
            $absAmount = abs($amount);
            
            if ($template->min_amount !== null && $absAmount < abs($template->min_amount)) {
                return false;
            }
            
            if ($template->max_amount !== null && $absAmount > abs($template->max_amount)) {
                return false;
            }
            
            return true;
        });
        
        $amounts = $filteredTransactions->pluck('amount_in_cents');
        
        return array_merge($baseResult, [
            'matching_transactions_count' => $matchingTransactions->count(),
            'filtered_transactions_count' => $filteredTransactions->count(),
            'outlier_analysis' => $improvedCalculation['outlier_analysis'] ?? null,
            'trend_analysis' => $improvedCalculation['trend_analysis'] ?? null,
            'amount_stats' => [
                'average' => $amounts->avg(),
                'median' => $amounts->median(),
                'min' => $amounts->min(),
                'max' => $amounts->max(),
                'std_dev' => $this->calculateStandardDeviation($amounts->toArray()),
                'count' => $amounts->count()
            ],
            'constraints' => [
                'min_amount' => $template->min_amount,
                'max_amount' => $template->max_amount,
                'stored_average' => $template->average_amount
            ]
        ]);
    }
    
    /**
     * Get the next occurrence date for a recurring transaction template
     *
     * @param RecurringTransactionTemplate $template
     * @return Carbon|null
     */
    public function getNextOccurrenceDate(RecurringTransactionTemplate $template): ?Carbon
    {
        return $template->calculateNextOccurrence();
    }

    /**
     * Calculate projected monthly cash flow for an account based on recurring transactions
     *
     * @param Account $account
     * @return int Cash flow in cents (positive = net income, negative = net expense)
     */
    public function calculateMonthlyProjectedCashFlow(Account $account): int
    {
        // Project transactions for the next 30 days
        $startDate = Carbon::now();
        $endDate = Carbon::now()->addDays(30);

        $projectedTransactions = $this->projectTransactions($account, $startDate, $endDate);

        // Calculate net cash flow (income - expenses)
        $netCashFlow = $projectedTransactions->sum('amount_in_cents');

        return $netCashFlow;
    }

}
