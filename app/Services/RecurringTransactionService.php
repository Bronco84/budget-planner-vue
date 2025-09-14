<?php

namespace App\Services;

use App\Models\RecurringTransactionTemplate;
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

            // For weekly transactions, move to the next occurrence of the specified day
            if ($template->frequency === 'weekly') {
                $originalDate = $date->copy();
                while ($date->dayOfWeek !== $template->day_of_week) {
                    $date->addDay();
                }
            }

            // Generate all occurrences
            while ($date <= $templateEndDate) {
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
        Log::debug('Evaluating condition', [
            'field_value' => $fieldValue,
            'operator' => $operator,
            'rule_value' => $ruleValue
        ]);

        switch ($operator) {
            case '=':
                return $fieldValue == $ruleValue;
            case '!=':
                return $fieldValue != $ruleValue;
            case '>':
                return is_numeric($fieldValue) && is_numeric($ruleValue) && $fieldValue > $ruleValue;
            case '<':
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
            default:
                return false;
        }
    }
}
