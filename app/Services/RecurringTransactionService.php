<?php

namespace App\Services;

use App\Models\RecurringTransactionTemplate;
use App\Models\RecurringTransactionRule;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class RecurringTransactionService
{
    public function projectTransactions(Account $account, Carbon $startDate, Carbon $endDate)
    {
        $projectedTransactions = collect();

        // Get all active recurring transaction templates with their transactions, linked credit cards, and rules preloaded
        // Eager loading rules prevents N+1 queries when calculating dynamic amounts
        $templates = RecurringTransactionTemplate::where('account_id', $account->id)
            ->where(function ($query) use ($startDate) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $startDate);
            })
            ->with(['transactions', 'linkedCreditCard.plaidAccount', 'rules'])
            ->get();

        foreach ($templates as $template) {
            // End at the earlier of endDate or template's end_date
            $templateEndDate = $template->end_date ? min($endDate, $template->end_date) : $endDate;
            
            // Use optimized occurrence date generation instead of day-by-day iteration
            // This changes O(days) to O(occurrences), a significant improvement for multi-month projections
            $occurrenceDates = $this->generateOccurrenceDates($template, $startDate, $templateEndDate);
            
            // Calculate dynamic amount once per template (not per occurrence) if applicable
            $dynamicAmount = null;
            if ($template->is_dynamic_amount) {
                $dynamicAmount = $this->calculateDynamicAmount($template);
            }

            foreach ($occurrenceDates as $date) {
                // Check if autopay should override this projection
                if ($template->shouldAutopayOverrideFor($date)) {
                    continue;
                }

                // Determine the amount for this transaction
                $existingTransaction = $this->getTransactionForDate($template, $date);

                if ($existingTransaction) {
                    $amount = $existingTransaction->amount_in_cents;
                } elseif ($dynamicAmount !== null) {
                    $amount = $dynamicAmount;
                } else {
                    $amount = $template->amount_in_cents;
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
        }

        return $projectedTransactions->sortBy('date')->values();
    }

    /**
     * Generate occurrence dates for a template within a date range.
     * Uses direct date calculation instead of day-by-day iteration for O(occurrences) performance.
     *
     * @param RecurringTransactionTemplate $template
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array<Carbon>
     */
    protected function generateOccurrenceDates(RecurringTransactionTemplate $template, Carbon $startDate, Carbon $endDate): array
    {
        $occurrences = [];
        $effectiveStart = max($startDate, $template->start_date)->copy()->startOfDay();

        switch ($template->frequency) {
            case RecurringTransactionTemplate::FREQUENCY_DAILY:
                // For daily, iterate by day (every day is a hit, no optimization needed)
                $date = $effectiveStart->copy();
                while ($date <= $endDate) {
                    if (!$template->hasTransactionForDate($date)) {
                        $occurrences[] = $date->copy();
                    }
                    $date->addDay();
                }
                break;

            case RecurringTransactionTemplate::FREQUENCY_WEEKLY:
                // Find the first occurrence on the correct day of week
                $date = $effectiveStart->copy();
                while ($date->dayOfWeek !== $template->day_of_week && $date <= $endDate) {
                    $date->addDay();
                }
                // Then jump by weeks
                while ($date <= $endDate) {
                    if (!$template->hasTransactionForDate($date)) {
                        $occurrences[] = $date->copy();
                    }
                    $date->addWeek();
                }
                break;

            case RecurringTransactionTemplate::FREQUENCY_BIWEEKLY:
                // Find the first occurrence on the correct day of week
                $date = $effectiveStart->copy();
                while ($date->dayOfWeek !== $template->day_of_week && $date <= $endDate) {
                    $date->addDay();
                }
                // Align to the biweekly schedule from template start
                $weeksSinceStart = (int) $template->start_date->diffInWeeks($date);
                if ($weeksSinceStart % 2 !== 0) {
                    $date->addWeek();
                }
                // Then jump by 2 weeks
                while ($date <= $endDate) {
                    if (!$template->hasTransactionForDate($date)) {
                        $occurrences[] = $date->copy();
                    }
                    $date->addWeeks(2);
                }
                break;

            case RecurringTransactionTemplate::FREQUENCY_MONTHLY:
                $targetDay = $template->day_of_month ?? $template->start_date->day;
                $date = $effectiveStart->copy()->startOfMonth();
                
                while ($date <= $endDate) {
                    // Calculate actual day for this month (handle 29th-31st in shorter months)
                    $actualDay = min($targetDay, $date->daysInMonth);
                    $occurrenceDate = $date->copy()->setDay($actualDay);
                    
                    if ($occurrenceDate >= $effectiveStart && $occurrenceDate <= $endDate) {
                        if (!$template->hasTransactionForDate($occurrenceDate)) {
                            $occurrences[] = $occurrenceDate;
                        }
                    }
                    $date->addMonth();
                }
                break;

            case RecurringTransactionTemplate::FREQUENCY_BIMONTHLY:
                // Twice per month on two specific days
                $firstDay = $template->first_day_of_month ?? 1;
                $secondDay = $template->day_of_month ?? 15;
                if ($firstDay > $secondDay) {
                    [$firstDay, $secondDay] = [$secondDay, $firstDay];
                }
                
                $date = $effectiveStart->copy()->startOfMonth();
                while ($date <= $endDate) {
                    $daysInMonth = $date->daysInMonth;
                    
                    // First occurrence of the month
                    $firstOccurrence = $date->copy()->setDay(min($firstDay, $daysInMonth));
                    if ($firstOccurrence >= $effectiveStart && $firstOccurrence <= $endDate) {
                        if (!$template->hasTransactionForDate($firstOccurrence)) {
                            $occurrences[] = $firstOccurrence;
                        }
                    }
                    
                    // Second occurrence of the month
                    $secondOccurrence = $date->copy()->setDay(min($secondDay, $daysInMonth));
                    if ($secondOccurrence >= $effectiveStart && $secondOccurrence <= $endDate) {
                        if (!$template->hasTransactionForDate($secondOccurrence)) {
                            $occurrences[] = $secondOccurrence;
                        }
                    }
                    
                    $date->addMonth();
                }
                break;

            case RecurringTransactionTemplate::FREQUENCY_QUARTERLY:
                $targetDay = $template->day_of_month ?? $template->start_date->day;
                $date = $effectiveStart->copy()->startOfMonth();
                
                // Align to quarter from template start
                $monthsSinceStart = (int) $template->start_date->diffInMonths($date);
                $monthsToNextQuarter = $monthsSinceStart % 3;
                if ($monthsToNextQuarter !== 0) {
                    $date->addMonths(3 - $monthsToNextQuarter);
                }
                
                while ($date <= $endDate) {
                    $actualDay = min($targetDay, $date->daysInMonth);
                    $occurrenceDate = $date->copy()->setDay($actualDay);
                    
                    if ($occurrenceDate >= $effectiveStart && $occurrenceDate <= $endDate) {
                        if (!$template->hasTransactionForDate($occurrenceDate)) {
                            $occurrences[] = $occurrenceDate;
                        }
                    }
                    $date->addMonths(3);
                }
                break;

            case RecurringTransactionTemplate::FREQUENCY_YEARLY:
                $targetMonth = $template->start_date->month;
                $targetDay = $template->start_date->day;
                $date = $effectiveStart->copy()->startOfYear();
                
                while ($date <= $endDate) {
                    $occurrenceDate = $date->copy()->setMonth($targetMonth);
                    $daysInMonth = $occurrenceDate->daysInMonth;
                    $actualDay = min($targetDay, $daysInMonth);
                    $occurrenceDate->setDay($actualDay);
                    
                    if ($occurrenceDate >= $effectiveStart && $occurrenceDate <= $endDate) {
                        if (!$template->hasTransactionForDate($occurrenceDate)) {
                            $occurrences[] = $occurrenceDate;
                        }
                    }
                    $date->addYear();
                }
                break;
        }

        return $occurrences;
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
     * Cache TTL for dynamic amounts in seconds (30 minutes)
     */
    protected const DYNAMIC_AMOUNT_CACHE_TTL = 1800;

    /**
     * Calculate the dynamic amount for a recurring transaction template based on rules.
     * If the template is linked to a credit card, uses the card's current balance
     * if it exceeds the calculated average (reflecting current spending trajectory).
     * Results are cached for 30 minutes.
     *
     * @param RecurringTransactionTemplate $template
     * @return int|null The calculated amount in cents or null if not calculable
     */
    protected function calculateDynamicAmount(RecurringTransactionTemplate $template): ?int
    {
        // Cache key for this template's dynamic amount
        $cacheKey = "template:{$template->id}:dynamic_amount";

        return Cache::remember($cacheKey, self::DYNAMIC_AMOUNT_CACHE_TTL, function () use ($template) {
            return $this->computeDynamicAmount($template);
        });
    }

    /**
     * Compute the dynamic amount without caching.
     *
     * Calculation priority:
     * 1. If rules exist, find matching transactions via rules and calculate average
     * 2. If no rules but has linked transactions, use those for averaging
     * 3. Fall back to stored average_amount or amount_in_cents
     *
     * @param RecurringTransactionTemplate $template
     * @return int|null
     */
    protected function computeDynamicAmount(RecurringTransactionTemplate $template): ?int
    {
        // Use pre-loaded rules if available to avoid N+1 queries
        $rules = $template->relationLoaded('rules')
            ? $template->rules->where('is_active', true)
            : $template->rules()->where('is_active', true)->get();

        $matchingTransactions = collect();

        // Priority 1: If rules exist, use rule-based matching
        if ($rules->isNotEmpty()) {
            $matchingTransactions = $this->findTransactionsMatchingAllRules($template, $rules);

            Log::debug('computeDynamicAmount: Rule-based matching', [
                'template_id' => $template->id,
                'rules_count' => $rules->count(),
                'matches_found' => $matchingTransactions->count(),
            ]);
        }

        // Priority 2: If no rules or no rule matches, check linked transactions
        if ($matchingTransactions->isEmpty()) {
            // Get linked transactions (those with recurring_transaction_template_id = this template)
            $linkedTransactions = $template->relationLoaded('transactions')
                ? $template->transactions
                : $template->transactions()->get();

            if ($linkedTransactions->isNotEmpty()) {
                $matchingTransactions = $linkedTransactions;

                Log::debug('computeDynamicAmount: Using linked transactions', [
                    'template_id' => $template->id,
                    'linked_count' => $linkedTransactions->count(),
                ]);
            }
        }

        // Priority 3: If still no transactions, fall back to stored values
        if ($matchingTransactions->isEmpty()) {
            Log::debug('computeDynamicAmount: No transactions found, using fallback', [
                'template_id' => $template->id,
                'has_average_amount' => $template->average_amount !== null,
                'average_amount' => $template->average_amount,
            ]);

            if ($template->average_amount !== null) {
                $calculatedAmount = (int)($template->average_amount * 100);
                return $this->applyLinkedCreditCardBalance($template, $calculatedAmount);
            }
            return $this->applyLinkedCreditCardBalance($template, $template->amount_in_cents);
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
            Log::debug('computeDynamicAmount: All transactions filtered out by min/max, using fallback', [
                'template_id' => $template->id,
                'original_count' => $matchingTransactions->count(),
            ]);

            if ($template->average_amount !== null) {
                $calculatedAmount = (int)($template->average_amount * 100);
                return $this->applyLinkedCreditCardBalance($template, $calculatedAmount);
            }

            return $this->applyLinkedCreditCardBalance($template, $template->amount_in_cents);
        }

        // Calculate average of only the valid transactions
        $totalAmount = $filteredTransactions->sum('amount_in_cents');
        $averageAmount = (int)($totalAmount / $filteredTransactions->count());

        Log::debug('computeDynamicAmount: Calculated average from transactions', [
            'template_id' => $template->id,
            'transaction_count' => $filteredTransactions->count(),
            'total_amount_cents' => $totalAmount,
            'average_amount_cents' => $averageAmount,
        ]);

        return $this->applyLinkedCreditCardBalance($template, $averageAmount);
    }

    /**
     * If the template is linked to a credit card, check if the card's spending since
     * the last statement exceeds the calculated amount. If so, use that instead.
     * 
     * Logic:
     * - Current balance includes: statement balance + new spending since statement
     * - Statement balance will be paid by the upcoming autopay
     * - New spending = current balance - statement balance
     * - If new spending > calculated average, use new spending for next month's projection
     *
     * @param RecurringTransactionTemplate $template
     * @param int|null $calculatedAmount
     * @return int|null
     */
    protected function applyLinkedCreditCardBalance(RecurringTransactionTemplate $template, ?int $calculatedAmount): ?int
    {
        if ($calculatedAmount === null) {
            return null;
        }

        // Only apply if template is linked to a credit card
        if (!$template->linked_credit_card_account_id) {
            return $calculatedAmount;
        }

        // Load the linked credit card with plaidAccount if not already loaded
        if (!$template->relationLoaded('linkedCreditCard')) {
            $template->load('linkedCreditCard.plaidAccount');
        }

        $creditCard = $template->linkedCreditCard;
        if (!$creditCard) {
            return $calculatedAmount;
        }

        $currentBalanceCents = $creditCard->current_balance_cents ?? 0;
        
        // Get the statement balance that will be paid by autopay
        $statementBalanceCents = $creditCard->plaidAccount?->last_statement_balance_cents ?? 0;
        
        // Calculate new spending since the statement was generated
        // New spending = current balance - statement balance
        $newSpendingSinceStatement = $currentBalanceCents - $statementBalanceCents;
        
        // Only use new spending if it's positive (there's actually new spending)
        // and if it exceeds the calculated average
        if ($newSpendingSinceStatement > 0 && abs($newSpendingSinceStatement) > abs($calculatedAmount)) {
            // Return as negative (expense) matching the sign of the calculated amount
            $sign = $calculatedAmount < 0 ? -1 : 1;
            return $sign * abs($newSpendingSinceStatement);
        }

        return $calculatedAmount;
    }



    /**
     * Find transactions that match all the provided rules for a template.
     * If the template has a plaid_entity_id, use that for matching first.
     *
     * @param RecurringTransactionTemplate $template
     * @param \Illuminate\Support\Collection $rules
     * @return \Illuminate\Support\Collection
     */
    /**
     * Find transactions that match all the provided rules for a template.
     * If the template has a plaid_entity_id, use that for matching first.
     *
     * @param RecurringTransactionTemplate $template
     * @param Collection $rules
     * @param Collection|null $preloadedTransactions Optional pre-fetched transactions to avoid N+1 queries
     * @return Collection
     */
    protected function findTransactionsMatchingAllRules(
        RecurringTransactionTemplate $template, 
        Collection $rules,
        ?Collection $preloadedTransactions = null
    ): Collection {
        // Get the budget ID from the template
        $budgetId = $template->budget_id;

        // Priority 1: If template has a plaid_entity_id, use entity-based matching
        if ($template->plaid_entity_id) {
            $entityId = $template->plaid_entity_id;
            
            Log::debug('RecurringTransactionService: Entity-based matching', [
                'template_id' => $template->id,
                'template_description' => $template->description,
                'plaid_entity_id' => $entityId,
            ]);
            
            // Use preloaded transactions if available, otherwise query
            if ($preloadedTransactions !== null) {
                $entityMatches = $preloadedTransactions->filter(function (Transaction $transaction) use ($entityId) {
                    if (!$transaction->plaidTransaction) {
                        return false;
                    }
                    $counterparties = $transaction->plaidTransaction->counterparties ?? '';
                    return is_string($counterparties) && str_contains($counterparties, $entityId);
                });
            } else {
                $entityMatches = Transaction::where('budget_id', $budgetId)
                    ->whereHas('plaidTransaction', function ($query) use ($entityId) {
                        // Simple LIKE search - entity_id is unique enough
                        $query->where('counterparties', 'like', '%' . $entityId . '%');
                    })
                    ->with('plaidTransaction')
                    ->get();
            }
            
            Log::debug('RecurringTransactionService: Entity matching results', [
                'template_id' => $template->id,
                'matches_found' => $entityMatches->count(),
                'matched_transaction_ids' => $entityMatches->pluck('id')->toArray(),
            ]);
            
            // If we found matches by entity, return them (rules are optional refinement)
            if ($entityMatches->isNotEmpty()) {
                // Still apply rules as additional filters if any
                if ($rules->isNotEmpty()) {
                    $filteredMatches = $entityMatches->filter(function (Transaction $transaction) use ($rules) {
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
                    
                    Log::debug('RecurringTransactionService: After rule filtering', [
                        'template_id' => $template->id,
                        'original_count' => $entityMatches->count(),
                        'filtered_count' => $filteredMatches->count(),
                    ]);
                    
                    return $filteredMatches;
                }
                return $entityMatches;
            }
        }

        // Priority 2: Fallback to rule-based matching
        Log::debug('RecurringTransactionService: Fallback to rule-based matching', [
            'template_id' => $template->id,
            'template_description' => $template->description,
            'rules_count' => $rules->count(),
        ]);
        
        // Use preloaded transactions if available, otherwise query
        $transactions = $preloadedTransactions ?? Transaction::where('budget_id', $budgetId)->with('plaidTransaction')->get();

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
        
        Log::debug('RecurringTransactionService: Rule-based matching results', [
            'template_id' => $template->id,
            'total_transactions_scanned' => $transactions->count(),
            'matches_found' => $matchingTransactions->count(),
        ]);

        return $matchingTransactions;
    }

    /**
     * Link existing transactions that match the template
     * This is useful for identifying historical transactions that should be considered
     * when calculating dynamic amounts
     * 
     * Matching priority:
     * 1. Match by Plaid entity_id (most reliable)
     * 2. Match by description (fallback)
     */
    public function linkMatchingTransactions(RecurringTransactionTemplate $template)
    {
        Log::info('RecurringTransactionService: Linking transactions to template', [
            'template_id' => $template->id,
            'template_description' => $template->description,
            'has_plaid_entity_id' => !empty($template->plaid_entity_id),
            'plaid_entity_id' => $template->plaid_entity_id,
            'plaid_entity_name' => $template->plaid_entity_name,
        ]);
        
        // Find transactions that match the template but aren't linked yet
        $query = Transaction::where('budget_id', $template->budget_id)
            ->where('recurring_transaction_template_id', null)
            ->with('plaidTransaction');

        // Priority 1: Match by Plaid entity_id if available
        if ($template->plaid_entity_id) {
            Log::debug('RecurringTransactionService: Using Plaid entity matching', [
                'template_id' => $template->id,
                'entity_id' => $template->plaid_entity_id,
            ]);
            
            $matchingTransactions = $this->findTransactionsByEntityId(
                $template->budget_id,
                $template->plaid_entity_id
            );
            
            Log::debug('RecurringTransactionService: Entity matching found transactions', [
                'template_id' => $template->id,
                'count' => $matchingTransactions->count(),
                'transaction_ids' => $matchingTransactions->pluck('id')->toArray(),
            ]);
        } else {
            // Priority 2: Fallback to description matching with improved algorithm
            Log::debug('RecurringTransactionService: Using description-based matching', [
                'template_id' => $template->id,
                'description' => $template->description,
                'category' => $template->category,
            ]);
            
            // Get all unlinked transactions for the budget
            $allTransactions = $query->get();
            
            // Filter using improved matching algorithm
            $matchingTransactions = $allTransactions->filter(function($transaction) use ($template) {
                return $this->matchesDescription($transaction, $template);
            });
            
            Log::debug('RecurringTransactionService: Description matching found transactions', [
                'template_id' => $template->id,
                'count' => $matchingTransactions->count(),
            ]);
        }

        // Link the matching transactions to this template
        $linkedCount = 0;
        foreach ($matchingTransactions as $transaction) {
            if ($transaction->recurring_transaction_template_id === null) {
                $transaction->recurring_transaction_template_id = $template->id;
                $transaction->save();
                $linkedCount++;
            }
        }
        
        Log::info('RecurringTransactionService: Finished linking transactions', [
            'template_id' => $template->id,
            'transactions_linked' => $linkedCount,
            'total_candidates' => $matchingTransactions->count(),
        ]);

        return $matchingTransactions->count();
    }

    /**
     * Find transactions that match a Plaid entity_id.
     * Searches the counterparties field in the related PlaidTransaction.
     * Uses simple LIKE search since the entity_id is unique.
     *
     * @param int $budgetId
     * @param string $entityId
     * @return \Illuminate\Support\Collection
     */
    protected function findTransactionsByEntityId(int $budgetId, string $entityId): Collection
    {
        return Transaction::where('budget_id', $budgetId)
            ->where('recurring_transaction_template_id', null)
            ->whereHas('plaidTransaction', function ($query) use ($entityId) {
                // Simple LIKE search - entity_id is unique enough
                $query->where('counterparties', 'like', '%' . $entityId . '%');
            })
            ->with('plaidTransaction')
            ->get();
    }
    
    /**
     * Get detailed diagnostics about how a template matches transactions.
     * Useful for debugging and displaying in the UI.
     *
     * @param RecurringTransactionTemplate $template
     * @return array
     */
    public function getMatchingDiagnostics(RecurringTransactionTemplate $template): array
    {
        $diagnostics = [
            'template_id' => $template->id,
            'description' => $template->description,
            'matching_method' => null,
            'plaid_entity_id' => $template->plaid_entity_id,
            'plaid_entity_name' => $template->plaid_entity_name,
            'linked_transactions' => [],
            'potential_matches' => [],
            'rules_summary' => [],
            'linked_credit_card' => null,
        ];
        
        // Linked credit card info
        if ($template->linked_credit_card_account_id) {
            $creditCard = $template->linkedCreditCard;
            if ($creditCard) {
                $diagnostics['linked_credit_card'] = [
                    'id' => $creditCard->id,
                    'name' => $creditCard->name,
                    'autopay_enabled' => $creditCard->autopay_enabled,
                    'current_balance' => $creditCard->current_balance_cents,
                    'statement_balance' => $creditCard->plaidAccount?->last_statement_balance_cents,
                ];
            }
        }
        
        // Get currently linked transactions
        $linkedTransactions = Transaction::where('recurring_transaction_template_id', $template->id)
            ->orderBy('date', 'desc')
            ->limit(20)
            ->get();
            
        $diagnostics['linked_transactions'] = $linkedTransactions->map(fn($t) => [
            'id' => $t->id,
            'date' => $t->date->format('Y-m-d'),
            'description' => $t->description,
            'amount' => $t->amount_in_cents / 100,
            'has_plaid' => !is_null($t->plaid_transaction_id),
        ])->toArray();
        
        // Get rules summary
        $rules = $template->rules()->get();
        $diagnostics['rules_summary'] = $rules->map(fn($r) => [
            'id' => $r->id,
            'field' => $r->field,
            'operator' => $r->operator,
            'value' => $r->value,
            'is_active' => $r->is_active,
        ])->toArray();
        
        // Determine matching method and find potential matches
        if ($template->plaid_entity_id) {
            $diagnostics['matching_method'] = 'plaid_entity_id';
            
            // Find all transactions with this entity ID (including already linked ones)
            $potentialMatches = Transaction::where('budget_id', $template->budget_id)
                ->whereHas('plaidTransaction', function ($query) use ($template) {
                    $query->where('counterparties', 'like', '%' . $template->plaid_entity_id . '%');
                })
                ->with('plaidTransaction')
                ->orderBy('date', 'desc')
                ->limit(30)
                ->get();
                
            $diagnostics['potential_matches'] = $potentialMatches->map(fn($t) => [
                'id' => $t->id,
                'date' => $t->date->format('Y-m-d'),
                'description' => $t->description,
                'amount' => $t->amount_in_cents / 100,
                'linked_to_template_id' => $t->recurring_transaction_template_id,
                'is_linked_here' => $t->recurring_transaction_template_id === $template->id,
            ])->toArray();
        } elseif ($rules->isNotEmpty()) {
            $diagnostics['matching_method'] = 'rules';
        } else {
            $diagnostics['matching_method'] = 'description';
        }
        
        return $diagnostics;
    }

    /**
     * Link existing transactions that match the template's rules
     * This is specifically for dynamic amount transactions with rules
     */
    public function linkMatchingTransactionsByRules(RecurringTransactionTemplate $template)
    {
        // Allow rule-based matching for all templates, not just dynamic amount ones
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
     * Automatically link new unlinked transactions to existing recurring templates for an account.
     * This is called after new transactions are synced from Plaid.
     * 
     * Uses a multi-strategy approach:
     * 1. Plaid entity_id matching (most reliable)
     * 2. Rule-based matching (for templates with active rules)
     * 3. Description matching (fallback)
     *
     * @param Account $account
     * @param int $daysBack Number of days back to look for unlinked transactions
     * @return array Stats about linked transactions
     */
    public function autoLinkTransactionsForAccount(Account $account, int $daysBack = 30): array
    {
        $linkedCount = 0;
        $linkedByEntity = 0;
        $linkedByRules = 0;
        $linkedByDescription = 0;
        
        // Get unlinked transactions for this account from the specified period
        $unlinkedTransactions = Transaction::where('account_id', $account->id)
            ->whereNull('recurring_transaction_template_id')
            ->where('date', '>=', now()->subDays($daysBack)->toDateString())
            ->with('plaidTransaction')
            ->get();
        
        if ($unlinkedTransactions->isEmpty()) {
            return [
                'total_linked' => 0,
                'by_entity' => 0,
                'by_rules' => 0,
                'by_description' => 0,
            ];
        }
        
        // Get all recurring templates for this account
        $templates = RecurringTransactionTemplate::where('account_id', $account->id)
            ->with('rules')
            ->get();
        
        if ($templates->isEmpty()) {
            return [
                'total_linked' => 0,
                'by_entity' => 0,
                'by_rules' => 0,
                'by_description' => 0,
            ];
        }
        
        foreach ($unlinkedTransactions as $transaction) {
            // Skip if already linked (might have been linked in a previous iteration)
            if ($transaction->recurring_transaction_template_id !== null) {
                continue;
            }
            
            $matchedTemplate = null;
            $matchMethod = null;
            
            foreach ($templates as $template) {
                // Strategy 1: Match by Plaid entity_id
                if ($template->plaid_entity_id && $transaction->plaidTransaction) {
                    $counterparties = $transaction->plaidTransaction->counterparties ?? '';
                    if (is_string($counterparties) && str_contains($counterparties, $template->plaid_entity_id)) {
                        $matchedTemplate = $template;
                        $matchMethod = 'entity';
                        break;
                    }
                }
                
                // Strategy 2: Match by rules (all rules must match - AND logic)
                $rules = $template->rules->where('is_active', true);
                if ($rules->isNotEmpty()) {
                    $matchesAllRules = true;
                    foreach ($rules as $rule) {
                        $fieldValue = $this->getTransactionFieldValue($transaction, $rule->field);
                        $ruleValue = $rule->value;
                        if (!$this->evaluateRuleCondition($fieldValue, $rule->operator, $ruleValue)) {
                            $matchesAllRules = false;
                            break;
                        }
                    }
                    
                    if ($matchesAllRules) {
                        $matchedTemplate = $template;
                        $matchMethod = 'rules';
                        break;
                    }
                }
                
                // Strategy 3: Match by description with improved algorithm
                if ($template->description && $this->matchesDescription($transaction, $template)) {
                    $matchedTemplate = $template;
                    $matchMethod = 'description';
                    break;
                }
            }
            
            // Link the transaction if a match was found
            if ($matchedTemplate) {
                $transaction->recurring_transaction_template_id = $matchedTemplate->id;
                $transaction->save();
                $linkedCount++;
                
                switch ($matchMethod) {
                    case 'entity':
                        $linkedByEntity++;
                        break;
                    case 'rules':
                        $linkedByRules++;
                        break;
                    case 'description':
                        $linkedByDescription++;
                        break;
                }
            }
        }
        
        if ($linkedCount > 0) {
            Log::info('RecurringTransactionService: Auto-linked transactions for account', [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'total_linked' => $linkedCount,
                'by_entity' => $linkedByEntity,
                'by_rules' => $linkedByRules,
                'by_description' => $linkedByDescription,
            ]);
        }
        
        return [
            'total_linked' => $linkedCount,
            'by_entity' => $linkedByEntity,
            'by_rules' => $linkedByRules,
            'by_description' => $linkedByDescription,
        ];
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
     * Find unlinked transactions that match existing recurring transaction templates.
     * Returns structured data for UI display with manual approval.
     *
     * @param Budget $budget
     * @return array Array of templates with their matching unlinked transactions
     */
    public function findMatchingTransactionsForTemplates(Budget $budget): array
    {
        $results = [];
        
        // Get all recurring templates for this budget
        $templates = RecurringTransactionTemplate::where('budget_id', $budget->id)
            ->with(['account', 'transactions'])
            ->orderBy('description')
            ->get();
        
        if ($templates->isEmpty()) {
            return [
                'templates_with_matches' => [],
                'total_templates' => 0,
                'total_matches' => 0,
            ];
        }
        
        // Get all unlinked Plaid transactions for this budget
        $unlinkedTransactions = Transaction::where('budget_id', $budget->id)
            ->whereNull('recurring_transaction_template_id')
            ->whereNotNull('plaid_transaction_id')
            ->with('plaidTransaction', 'account')
            ->orderBy('date', 'desc')
            ->get();
        
        $totalMatches = 0;
        
        foreach ($templates as $template) {
            // Find transactions that match this template
            $matchingTransactions = $unlinkedTransactions->filter(function($transaction) use ($template) {
                // First check entity ID match if available
                if ($template->plaid_entity_id && $transaction->plaidTransaction) {
                    $counterparties = $transaction->plaidTransaction->counterparties;
                    if (is_array($counterparties)) {
                        foreach ($counterparties as $counterparty) {
                            if (isset($counterparty['entity_id']) && $counterparty['entity_id'] === $template->plaid_entity_id) {
                                return true;
                            }
                        }
                    }
                    // Also check merchant_entity_id
                    if ($transaction->plaidTransaction->merchant_entity_id === $template->plaid_entity_id) {
                        return true;
                    }
                }
                
                // Fallback to description matching
                return $this->matchesDescription($transaction, $template);
            });
            
            if ($matchingTransactions->isNotEmpty()) {
                $results[] = [
                    'template' => [
                        'id' => $template->id,
                        'description' => $template->description,
                        'amount_in_cents' => $template->amount_in_cents,
                        'frequency' => $template->frequency,
                        'account_id' => $template->account_id,
                        'account_name' => $template->account?->name,
                        'linked_count' => $template->transactions->count(),
                        'plaid_entity_id' => $template->plaid_entity_id,
                        'plaid_entity_name' => $template->plaid_entity_name,
                    ],
                    'matching_transactions' => $matchingTransactions->map(function($transaction) {
                        return [
                            'id' => $transaction->id,
                            'description' => $transaction->description,
                            'plaid_description' => $transaction->plaidTransaction?->name,
                            'merchant_name' => $transaction->plaidTransaction?->merchant_name,
                            'amount_in_cents' => $transaction->amount_in_cents,
                            'date' => $transaction->date->format('Y-m-d'),
                            'account_id' => $transaction->account_id,
                            'account_name' => $transaction->account?->name,
                        ];
                    })->values()->toArray(),
                ];
                
                $totalMatches += $matchingTransactions->count();
            }
        }
        
        return [
            'templates_with_matches' => $results,
            'total_templates' => count($results),
            'total_matches' => $totalMatches,
        ];
    }

    /**
     * Link selected transactions to their matching templates.
     *
     * @param array $selections Array of ['template_id' => int, 'transaction_ids' => int[]]
     * @return array Stats about linked transactions
     */
    public function linkSelectedTransactions(array $selections): array
    {
        $linkedCount = 0;
        $errors = [];
        
        foreach ($selections as $selection) {
            $templateId = $selection['template_id'] ?? null;
            $transactionIds = $selection['transaction_ids'] ?? [];
            
            if (!$templateId || empty($transactionIds)) {
                continue;
            }
            
            $template = RecurringTransactionTemplate::find($templateId);
            if (!$template) {
                $errors[] = "Template {$templateId} not found";
                continue;
            }
            
            foreach ($transactionIds as $transactionId) {
                $transaction = Transaction::find($transactionId);
                if (!$transaction) {
                    $errors[] = "Transaction {$transactionId} not found";
                    continue;
                }
                
                // Only link if not already linked
                if ($transaction->recurring_transaction_template_id === null) {
                    $transaction->recurring_transaction_template_id = $templateId;
                    $transaction->save();
                    $linkedCount++;
                }
            }
        }
        
        return [
            'linked_count' => $linkedCount,
            'errors' => $errors,
        ];
    }

    /**
     * Check if a transaction matches a template's description using improved matching algorithm.
     * 
     * Uses the Plaid transaction's original description (name field) if available,
     * otherwise falls back to the transaction's description field. This preserves
     * user-friendly descriptions while matching against the original Plaid data.
     * 
     * Matching strategy (in order of priority):
     * 1. Exact match (case-insensitive)
     * 2. Contains match with minimum length requirement (5+ chars) + category check
     * 3. Fuzzy match with 70% similarity threshold + category check
     *
     * @param Transaction $transaction
     * @param RecurringTransactionTemplate $template
     * @return bool
     */
    protected function matchesDescription(Transaction $transaction, RecurringTransactionTemplate $template): bool
    {
        if (!$template->description) {
            return false;
        }

        $templateDesc = strtolower(trim($template->description));
        
        // Use Plaid transaction's original description if available, otherwise use transaction description
        $transactionDesc = $transaction->plaidTransaction && $transaction->plaidTransaction->name
            ? strtolower(trim($transaction->plaidTransaction->name))
            : strtolower(trim($transaction->description));

        // Strategy 1: Exact match (highest priority)
        if ($transactionDesc === $templateDesc) {
            return true;
        }

        // Strategy 2: Contains match with minimum length requirement
        // Require at least 5 characters to avoid false positives
        if (strlen($templateDesc) >= 5 && str_contains($transactionDesc, $templateDesc)) {
            // For description matching, category is optional (not enforced)
            // This is because description matching is already a fallback method
            // and enforcing category would be too restrictive
            return true;
        }

        // Strategy 3: Fuzzy match with similarity threshold (70%)
        // This helps catch variations like "ENTERGY ARKANSAS" vs "Entergy Arkansas Electric"
        similar_text($templateDesc, $transactionDesc, $percent);
        if ($percent >= 70) {
            // For description matching, category is optional (not enforced)
            return true;
        }

        return false;
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
        $daysDiff = (int) $firstDate->diffInDays($lastDate);
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
        $daysSinceNewest = (int) now()->diffInDays($newestTransaction);
        $dataSpanDays = (int) $oldestTransaction->diffInDays($newestTransaction);
        
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
     * Calculation priority:
     * 1. If rules exist, find matching transactions via rules
     * 2. If no rules but has linked transactions, use those
     * 3. Fall back to stored average_amount or amount_in_cents
     *
     * @param RecurringTransactionTemplate $template
     * @return array
     */
    protected function calculateImprovedDynamicAmount(RecurringTransactionTemplate $template): array
    {
        $rules = $template->rules()->where('is_active', true)->get();
        $matchingTransactions = collect();
        $matchMethod = 'none';
        
        // Priority 1: If rules exist, use rule-based matching
        if ($rules->isNotEmpty()) {
            $matchingTransactions = $this->findTransactionsMatchingAllRules($template, $rules);
            $matchMethod = 'rules';
        }
        
        // Priority 2: If no rules or no rule matches, check linked transactions
        if ($matchingTransactions->isEmpty()) {
            $linkedTransactions = $template->transactions()->get();
            
            if ($linkedTransactions->isNotEmpty()) {
                $matchingTransactions = $linkedTransactions;
                $matchMethod = 'linked_transactions';
            }
        }
        
        // Priority 3: If still no transactions, fall back to stored values
        if ($matchingTransactions->isEmpty()) {
            $factors = $rules->isEmpty() 
                ? ['No rules configured and no linked transactions'] 
                : ['No matching transactions found'];
            
            return [
                'amount' => $template->average_amount ? (int)($template->average_amount * 100) : $template->amount_in_cents,
                'method' => 'fallback',
                'confidence' => ['score' => 0, 'level' => 'low', 'factors' => $factors]
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
                $daysAgo = (int) now()->diffInDays($transaction->date);
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

    /**
     * Calculate the projected statement balance for the second autopay.
     * Uses current spending trajectory to project end-of-cycle balance.
     *
     * Formula: raw_spending_since_statement + (adjusted_per_day_average * remaining_days)
     *
     * The actual spending (including large purchases) is preserved in the total,
     * but large one-time purchases (over $1000) are excluded when calculating
     * the per-day average for projecting the remaining days. This is because
     * large purchases typically represent planned one-off expenses covered by
     * savings transfers, not the regular daily spending pattern.
     *
     * @param Account $creditCard
     * @param int $oneTimePurchaseThresholdCents Threshold for one-time purchases (default $1000 = 100000 cents)
     * @return array{amount: int, metadata: array}
     */
    protected function calculateProjectedStatementBalance(
        Account $creditCard,
        int $oneTimePurchaseThresholdCents = 100000
    ): array {
        $plaidAccount = $creditCard->plaidAccount;
        
        if (!$plaidAccount) {
            return [
                'amount' => $creditCard->current_balance_cents ?? 0,
                'metadata' => ['projection_method' => 'fallback_current_balance'],
            ];
        }

        $currentBalance = $creditCard->current_balance_cents ?? 0;
        $statementBalance = $plaidAccount->last_statement_balance_cents ?? 0;
        $rawSpendingSinceStatement = $currentBalance - $statementBalance;

        // Get cycle timing
        $daysSinceStatement = $plaidAccount->getDaysSinceStatement() ?? 0;
        $daysRemainingInCycle = $plaidAccount->getDaysRemainingInCycle() ?? 0;
        $cycleLength = $plaidAccount->getStatementCycleLength();

        // Handle edge cases
        if ($daysSinceStatement <= 0) {
            // Statement just closed, use historical average instead
            $historicalResult = $this->calculateWeightedHistoricalAverage($creditCard);
            return [
                'amount' => $historicalResult['amount'],
                'metadata' => array_merge($historicalResult['metadata'], [
                    'projection_method' => 'historical_average_early_cycle',
                ]),
            ];
        }

        // Handle negative spending (payments/credits exceed new charges)
        if ($rawSpendingSinceStatement <= 0) {
            // Use historical average when no net spending
            $historicalResult = $this->calculateWeightedHistoricalAverage($creditCard);
            return [
                'amount' => $historicalResult['amount'],
                'metadata' => array_merge($historicalResult['metadata'], [
                    'projection_method' => 'historical_average_negative_spending',
                    'actual_spending_since_statement_cents' => $rawSpendingSinceStatement,
                ]),
            ];
        }

        // Calculate adjustment for large one-time purchases
        // These are excluded from the daily average calculation, not from the total
        $oneTimePurchaseData = $this->calculateOneTimePurchaseAdjustment(
            $creditCard,
            $plaidAccount->last_statement_issue_date,
            $oneTimePurchaseThresholdCents
        );
        
        // Calculate "regular" spending by excluding large one-time purchases
        // This is used only for computing the per-day average, not the base total
        $regularSpendingForAverage = $rawSpendingSinceStatement - $oneTimePurchaseData['total_excluded_cents'];

        // Calculate per-day average based on regular spending patterns only
        // If regular spending is zero or negative, use a small positive value to avoid division issues
        if ($regularSpendingForAverage <= 0) {
            // All spending was one-time purchases, so assume minimal regular spending going forward
            // Use historical average for the projection instead
            $historicalResult = $this->calculateWeightedHistoricalAverage($creditCard);
            
            // But still add the actual spending so far to the historical projection rate
            // The projection is: what we've spent so far + historical average for remaining days
            $historicalDailyAverage = $historicalResult['amount'] / $cycleLength;
            $projectedRemainder = (int) round($historicalDailyAverage * $daysRemainingInCycle);
            $projectedBalance = $rawSpendingSinceStatement + $projectedRemainder;
            
            return [
                'amount' => $projectedBalance,
                'metadata' => [
                    'projection_method' => 'trajectory_with_historical_remainder',
                    'current_balance_cents' => $currentBalance,
                    'statement_balance_cents' => $statementBalance,
                    'spending_since_statement_cents' => $rawSpendingSinceStatement,
                    'regular_spending_for_average_cents' => $regularSpendingForAverage,
                    'one_time_purchases_excluded' => $oneTimePurchaseData,
                    'days_since_statement' => $daysSinceStatement,
                    'days_remaining_in_cycle' => $daysRemainingInCycle,
                    'cycle_length_days' => $cycleLength,
                    'historical_daily_average_cents' => (int) round($historicalDailyAverage),
                    'projected_remainder_cents' => $projectedRemainder,
                ],
            ];
        }

        // Calculate per-day average based on regular spending (excluding one-time purchases)
        $adjustedPerDayAverage = $regularSpendingForAverage / $daysSinceStatement;

        // Project end-of-cycle balance:
        // Actual spending so far (including one-time purchases) + projected remainder (based on regular spending rate)
        $projectedRemainder = (int) round($adjustedPerDayAverage * $daysRemainingInCycle);
        $projectedBalance = $rawSpendingSinceStatement + $projectedRemainder;

        // Ensure projection is at least as much as current spending
        $projectedBalance = max($projectedBalance, $rawSpendingSinceStatement);

        return [
            'amount' => $projectedBalance,
            'metadata' => [
                'projection_method' => 'trajectory_adjusted',
                'current_balance_cents' => $currentBalance,
                'statement_balance_cents' => $statementBalance,
                'spending_since_statement_cents' => $rawSpendingSinceStatement,
                'regular_spending_for_average_cents' => $regularSpendingForAverage,
                'one_time_purchases_excluded' => $oneTimePurchaseData,
                'days_since_statement' => $daysSinceStatement,
                'days_remaining_in_cycle' => $daysRemainingInCycle,
                'cycle_length_days' => $cycleLength,
                'adjusted_per_day_average_cents' => (int) round($adjustedPerDayAverage),
                'projected_remainder_cents' => $projectedRemainder,
            ],
        ];
    }

    /**
     * Calculate the total of large one-time purchases to exclude from spending projections.
     * 
     * These are typically planned purchases (electronics, appliances, etc.) that are
     * covered by a transfer from savings and shouldn't affect the regular monthly
     * spending projection.
     *
     * @param Account $creditCard
     * @param Carbon|string|null $statementIssueDate
     * @param int $thresholdCents Minimum amount to consider as one-time purchase
     * @return array{total_excluded_cents: int, count: int, transactions: array}
     */
    protected function calculateOneTimePurchaseAdjustment(
        Account $creditCard,
        $statementIssueDate,
        int $thresholdCents
    ): array {
        if (!$statementIssueDate) {
            return [
                'total_excluded_cents' => 0,
                'count' => 0,
                'transactions' => [],
                'threshold_cents' => $thresholdCents,
            ];
        }

        $statementDate = $statementIssueDate instanceof Carbon 
            ? $statementIssueDate 
            : Carbon::parse($statementIssueDate);

        // Query transactions on this credit card since the statement date
        // On credit cards, spending transactions are positive (increases balance)
        // We look for large positive transactions that exceed the threshold
        $largeTransactions = Transaction::where('account_id', $creditCard->id)
            ->where('date', '>', $statementDate)
            ->where('amount_in_cents', '>=', $thresholdCents)
            ->orderBy('date', 'desc')
            ->get(['id', 'description', 'amount_in_cents', 'date']);

        $totalExcluded = $largeTransactions->sum('amount_in_cents');

        return [
            'total_excluded_cents' => $totalExcluded,
            'count' => $largeTransactions->count(),
            'threshold_cents' => $thresholdCents,
            'transactions' => $largeTransactions->map(fn($t) => [
                'id' => $t->id,
                'description' => $t->description,
                'amount_cents' => $t->amount_in_cents,
                'date' => $t->date->format('Y-m-d'),
            ])->toArray(),
        ];
    }

    /**
     * Calculate weighted historical average from statement history.
     * Uses exponential decay weighting (more recent = higher weight).
     *
     * @param Account $creditCard
     * @param float $decayFactor Weight decay per month (default 0.7)
     * @param int $maxMonths Maximum months of history to consider
     * @return array{amount: int, metadata: array}
     */
    protected function calculateWeightedHistoricalAverage(
        Account $creditCard, 
        float $decayFactor = 0.7, 
        int $maxMonths = 6
    ): array {
        $plaidAccount = $creditCard->plaidAccount;
        
        if (!$plaidAccount) {
            // Fallback to current statement balance or balance
            $fallbackAmount = $plaidAccount?->last_statement_balance_cents 
                ?? $creditCard->current_balance_cents 
                ?? 0;
            return [
                'amount' => $fallbackAmount,
                'metadata' => [
                    'projection_method' => 'fallback_no_plaid_account',
                    'historical_months_used' => 0,
                ],
            ];
        }

        // Load statement history (most recent first)
        $statements = $plaidAccount->statementHistory()
            ->orderBy('statement_issue_date', 'desc')
            ->limit($maxMonths)
            ->get();

        if ($statements->isEmpty()) {
            // No history, use current statement balance
            $fallbackAmount = $plaidAccount->last_statement_balance_cents 
                ?? $creditCard->current_balance_cents 
                ?? 0;
            return [
                'amount' => $fallbackAmount,
                'metadata' => [
                    'projection_method' => 'fallback_no_history',
                    'historical_months_used' => 0,
                ],
            ];
        }

        // Calculate weighted average with exponential decay
        $weightedSum = 0;
        $totalWeight = 0;
        $weights = [];

        foreach ($statements as $index => $statement) {
            $weight = pow($decayFactor, $index); // 1.0, 0.7, 0.49, 0.34, ...
            $balance = $statement->statement_balance_cents ?? 0;
            
            $weightedSum += $balance * $weight;
            $totalWeight += $weight;
            $weights[] = [
                'month' => $statement->statement_issue_date->format('Y-m'),
                'balance_cents' => $balance,
                'weight' => round($weight, 3),
            ];
        }

        $weightedAverage = $totalWeight > 0 ? (int) round($weightedSum / $totalWeight) : 0;

        return [
            'amount' => $weightedAverage,
            'metadata' => [
                'projection_method' => 'historical_weighted_average',
                'historical_months_used' => $statements->count(),
                'decay_factor' => $decayFactor,
                'weighted_breakdown' => $weights,
            ],
        ];
    }

    /**
     * Generate autopay deduction projections for all autopay-enabled credit cards.
     *
     * @param Budget $budget
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return Collection Collection of projected transactions (not persisted)
     */
    public function generateAutopayProjections(Budget $budget, Carbon $startDate, Carbon $endDate): Collection
    {
        $projections = collect();

        // Get all accounts with active autopay in this budget
        // Eager load plaidAccount with statement history and autopaySourceAccount to avoid N+1 queries
        $autopayAccounts = $budget->accounts()
            ->with(['plaidAccount.plaidConnection', 'plaidAccount.statementHistory', 'autopaySourceAccount'])
            ->where('autopay_enabled', true)
            ->whereNotNull('autopay_source_account_id')
            ->get();

        foreach ($autopayAccounts as $creditCard) {
            if (!$creditCard->hasActiveAutopay()) {
                continue;
            }

            $firstPaymentDate = $creditCard->getNextAutopayDate();
            $firstPaymentAmount = $creditCard->getAutopayAmountCents();

            // Skip if no payment date or amount
            if (!$firstPaymentDate || !$firstPaymentAmount) {
                continue;
            }

            // Pre-calculate projection amounts for second and third+ payments
            $secondPaymentProjection = $this->calculateProjectedStatementBalance($creditCard);
            $futurePaymentProjection = $this->calculateWeightedHistoricalAverage($creditCard);

            // Generate autopay projections for all future months within the date range
            $currentPaymentDate = $firstPaymentDate->copy();
            $paymentNumber = 1;
            
            while ($currentPaymentDate <= $endDate) {
                // Only include if payment falls within projection window
                if ($currentPaymentDate >= $startDate) {
                    // Determine payment amount and metadata based on payment sequence
                    if ($paymentNumber === 1) {
                        // First payment: actual statement balance
                        $paymentAmount = $firstPaymentAmount;
                        $projectionMetadata = [
                            'projection_method' => 'actual_statement_balance',
                        ];
                    } elseif ($paymentNumber === 2) {
                        // Second payment: projected balance based on spending trajectory
                        $paymentAmount = $secondPaymentProjection['amount'];
                        $projectionMetadata = $secondPaymentProjection['metadata'];
                    } else {
                        // Third+ payments: weighted historical average
                        $paymentAmount = $futurePaymentProjection['amount'];
                        $projectionMetadata = $futurePaymentProjection['metadata'];
                    }
                    
                    // Create deduction from source account
                    $projections->push($this->createAutopayProjection(
                        $creditCard->autopaySourceAccount,
                        $creditCard,
                        $currentPaymentDate->copy(),
                        $paymentAmount,
                        $paymentNumber === 1,
                        $paymentNumber,
                        $projectionMetadata
                    ));
                }
                
                // Move to next month's payment date
                $currentPaymentDate->addMonth();
                $paymentNumber++;
            }
        }

        return $projections;
    }

    /**
     * Create a single autopay projection transaction.
     *
     * @param Account $sourceAccount
     * @param Account $creditCard
     * @param Carbon $paymentDate
     * @param int $amountCents
     * @param bool $isFirstPayment
     * @param int $paymentNumber The sequence number of this payment (1 = first, 2 = second, etc.)
     * @param array $projectionMetadata Additional metadata about how the amount was calculated
     * @return object
     */
    private function createAutopayProjection(
        Account $sourceAccount,
        Account $creditCard,
        Carbon $paymentDate,
        int $amountCents,
        bool $isFirstPayment = true,
        int $paymentNumber = 1,
        array $projectionMetadata = []
    ): object {
        $category = $this->getAutopayCategory($sourceAccount->budget);
        $description = $this->buildAutopayDescription($creditCard);
        
        // Build comprehensive metadata
        $metadata = array_merge([
            'credit_card_id' => $creditCard->id,
            'credit_card_name' => $creditCard->name,
            'projected_amount_cents' => $amountCents,
            'payment_due_date' => $paymentDate->format('Y-m-d'),
            'payment_number' => $paymentNumber,
        ], $projectionMetadata);
        
        return (object) [
            'id' => null, // Not persisted
            'account_id' => $sourceAccount->id,
            'account' => $sourceAccount,
            'source_account_id' => $creditCard->id, // Credit card account for linking to autopay settings
            'category_id' => $category?->id,
            'category' => $category?->name, // Use category name string for frontend display
            'budget_id' => $sourceAccount->budget_id,
            'date' => $paymentDate->copy(),
            'amount_in_cents' => -abs($amountCents), // Negative for deduction
            'description' => $description,
            'type' => 'debit',
            'is_projected' => true,
            'is_projection' => true,
            'projection_source' => 'autopay',
            'is_first_autopay' => $isFirstPayment, // First autopay is based on actual statement balance, subsequent are estimates
            'projection_metadata' => $metadata,
        ];
    }

    /**
     * Build a descriptive autopay description using institution and account mask.
     *
     * @param Account $creditCard
     * @return string
     */
    private function buildAutopayDescription(Account $creditCard): string
    {
        // Use just the account name - the UI will show an "Autopay" badge
        return $creditCard->name;
    }

    /**
     * Get or create "Credit Card Payment" category for autopay transactions.
     *
     * @param Budget $budget
     * @return Category|null
     */
    private function getAutopayCategory(Budget $budget): ?Category
    {
        // Try to find existing category from loaded relationship first (avoid N+1)
        if ($budget->relationLoaded('categories')) {
            $category = $budget->categories->firstWhere('name', 'Credit Card Payment');
            if ($category) {
                return $category;
            }
        }
        
        // Fallback to query if not loaded
        $category = $budget->categories()
            ->where('name', 'Credit Card Payment')
            ->first();

        if (!$category) {
            // Create if doesn't exist
            $category = $budget->categories()->create([
                'name' => 'Credit Card Payment',
                'amount' => 0, // Autopay amounts vary based on statement balance
                'color' => '#EF4444', // Red
            ]);
            
            // Add to loaded relationship if it exists
            if ($budget->relationLoaded('categories')) {
                $budget->categories->push($category);
            }
        }

        return $category;
    }

    /**
     * Clear the dynamic amount cache for a template.
     */
    public static function clearDynamicAmountCache(int $templateId): void
    {
        Cache::forget("template:{$templateId}:dynamic_amount");
    }

    /**
     * Clear all dynamic amount caches for templates belonging to an account.
     */
    public static function clearDynamicAmountCacheForAccount(int $accountId): void
    {
        // Get all template IDs for this account and clear their caches
        $templateIds = RecurringTransactionTemplate::where('account_id', $accountId)
            ->pluck('id');

        foreach ($templateIds as $templateId) {
            Cache::forget("template:{$templateId}:dynamic_amount");
        }
    }

}
