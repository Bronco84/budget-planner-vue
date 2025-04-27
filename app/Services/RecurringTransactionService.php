<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\PlaidTransaction;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecurringTransactionService
{
    /**
     * Generate upcoming transactions from all templates associated with a budget.
     *
     * @param Budget $budget The budget containing the templates
     * @param int $daysAhead Number of days ahead to generate transactions for
     * @return array<string, int> Result with counts of generated transactions and errors
     */
    public function generateUpcomingTransactions(Budget $budget, int $daysAhead = 30): array
    {
        $result = [
            'generated' => 0,
            'errors' => 0,
        ];
        
        /** @var Collection<int, RecurringTransactionTemplate> $templates */
        $templates = $budget->recurringTransactionTemplates()
            ->where('auto_generate', true)
            ->get();
            
        foreach ($templates as $template) {
            try {
                $generated = $this->generateTransactionsFromTemplate($template, $daysAhead);
                $result['generated'] += $generated;
            } catch (\Exception $e) {
                Log::error('Error generating transactions from template: ' . $e->getMessage(), [
                    'template_id' => $template->id,
                    'exception' => $e,
                ]);
                $result['errors']++;
            }
        }
        
        return $result;
    }
    
    /**
     * Generate transactions from a template.
     *
     * @param RecurringTransactionTemplate $template The template to generate from
     * @param int $daysAhead Number of days ahead to generate transactions for
     * @return int The number of transactions generated
     */
    public function generateTransactionsFromTemplate(RecurringTransactionTemplate $template, int $daysAhead = 30): int
    {
        // If this is a dynamic amount template, try to update the amount
        if ($template->is_dynamic_amount) {
            $this->updateDynamicAmount($template);
        }
        
        $startDate = now()->startOfDay();
        $endDate = now()->addDays($daysAhead)->endOfDay();
        $templateStartDate = Carbon::parse($template->start_date)->startOfDay();
        $templateEndDate = $template->end_date ? Carbon::parse($template->end_date)->endOfDay() : null;
        
        // Adjust start date if template starts in future
        if ($templateStartDate->isAfter($startDate)) {
            $startDate = $templateStartDate;
        }
        
        // Adjust end date if template ends before our default end date
        if ($templateEndDate && $templateEndDate->isBefore($endDate)) {
            $endDate = $templateEndDate;
        }
        
        // Return if start date is after end date (template expired)
        if ($startDate->isAfter($endDate)) {
            return 0;
        }
        
        $generatedCount = 0;
        $currentDate = $startDate->copy();
        
        // Generate transactions based on frequency
        while ($currentDate->lte($endDate)) {
            $transactionDate = null;
            
            switch ($template->frequency) {
                case 'daily':
                    $transactionDate = $currentDate->copy();
                    $currentDate->addDay();
                    break;
                    
                case 'weekly':
                    if ($template->day_of_week !== null && $currentDate->dayOfWeek !== $template->day_of_week) {
                        // If it's a future day this week
                        if ($currentDate->dayOfWeek < $template->day_of_week) {
                            $daysToAdd = $template->day_of_week - $currentDate->dayOfWeek;
                            $transactionDate = $currentDate->copy()->addDays($daysToAdd);
                        } else {
                            // It's a past day this week, so use next week
                            $daysToAdd = 7 - ($currentDate->dayOfWeek - $template->day_of_week);
                            $transactionDate = $currentDate->copy()->addDays($daysToAdd);
                        }
                        $currentDate = $transactionDate->copy()->addDay();
                    } else {
                        $transactionDate = $currentDate->copy();
                        $currentDate->addWeek();
                    }
                    break;
                    
                case 'monthly':
                    if ($template->day_of_month !== null) {
                        // If we're past the day of month, go to next month
                        if ($currentDate->day > $template->day_of_month) {
                            $currentDate->addMonthNoOverflow()->startOfMonth();
                        }
                        
                        // Set to the day of month or last day of month if beyond
                        $maxDay = $currentDate->copy()->endOfMonth()->day;
                        $day = min($template->day_of_month, $maxDay);
                        $transactionDate = $currentDate->copy()->day($day);
                        
                        // Move to next month
                        $currentDate->addMonthNoOverflow();
                    } else {
                        $transactionDate = $currentDate->copy();
                        $currentDate->addMonthNoOverflow();
                    }
                    break;
                    
                default:
                    // Skip unknown frequencies
                    $currentDate->addDay();
                    continue 2;
            }
            
            // Skip if transaction date is outside our range
            if ($transactionDate->lt($startDate) || $transactionDate->gt($endDate)) {
                continue;
            }
            
            // Check if transaction already exists for this date
            $existingTransaction = Transaction::where('budget_id', $template->budget_id)
                ->where('is_recurring', true)
                ->where('recurring_template_id', $template->id)
                ->whereDate('date', $transactionDate->format('Y-m-d'))
                ->exists();
                
            if (!$existingTransaction) {
                // Create the transaction
                Transaction::create([
                    'budget_id' => $template->budget_id,
                    'account_id' => $template->account_id,
                    'description' => $template->description,
                    'category' => $template->category,
                    'amount_in_cents' => $template->amount_in_cents,
                    'date' => $transactionDate->format('Y-m-d'),
                    'is_cleared' => false,
                    'is_recurring' => true,
                    'recurring_template_id' => $template->id,
                    'notes' => $template->notes,
                ]);
                
                $generatedCount++;
            }
        }
        
        return $generatedCount;
    }
    
    /**
     * Update the dynamic amount for a template based on matching transactions.
     *
     * @param RecurringTransactionTemplate $template The template to update
     * @return bool True if amount was updated, false otherwise
     */
    public function updateDynamicAmount(RecurringTransactionTemplate $template): bool
    {
        if (!$template->is_dynamic_amount) {
            return false;
        }
        
        // Find matching transactions based on the rules
        /** @var Collection<int, RecurringTransactionRule> $rules */
        $rules = $template->rules()->where('is_active', true)->get();
        if ($rules->isEmpty()) {
            return false;
        }
        
        $matchingTransactions = $this->findMatchingTransactions($template->budget_id, $rules);
        if ($matchingTransactions->isEmpty()) {
            return false;
        }
        
        // Calculate average amount
        $sum = 0;
        $count = 0;
        
        foreach ($matchingTransactions as $transaction) {
            $sum += abs($transaction->amount_in_cents);
            $count++;
        }
        
        $average = $count > 0 ? (int)round($sum / $count) : 0;
        
        // Apply min/max limits if set
        if ($template->min_amount !== null) {
            $minCents = (int)($template->min_amount * 100);
            $average = max($average, $minCents);
        }
        
        if ($template->max_amount !== null) {
            $maxCents = (int)($template->max_amount * 100);
            $average = min($average, $maxCents);
        }
        
        // Update the template amount if different
        if ($average !== $template->amount_in_cents) {
            $template->amount_in_cents = $average;
            $template->save();
            return true;
        }
        
        return false;
    }
    
    /**
     * Find transactions that match the rules.
     *
     * @param int $budgetId The budget ID to search in
     * @param Collection<int, RecurringTransactionRule> $rules The rules to match
     * @param int $months How many months back to search
     * @return Collection<int, Transaction> The matching transactions
     */
    public function findMatchingTransactions(int $budgetId, Collection $rules, int $months = 3): Collection
    {
        $startDate = now()->subMonths($months)->startOfMonth();
        
        // Start with all transactions in the date range
        $query = Transaction::where('budget_id', $budgetId)
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', now());
            
        // Don't include recurring transactions
        $query->where('is_recurring', false);
        
        // Get transactions and filter with rules
        $transactions = $query->get();
        
        return $transactions->filter(function ($transaction) use ($rules) {
            return $this->transactionMatchesRules($transaction, $rules);
        });
    }
    
    /**
     * Check if a transaction matches all active rules.
     *
     * @param Transaction $transaction The transaction to check
     * @param Collection<int, RecurringTransactionRule> $rules The rules to match against
     * @return bool True if matches all rules, false otherwise
     */
    protected function transactionMatchesRules($transaction, Collection $rules): bool
    {
        foreach ($rules as $rule) {
            if (!$rule->matchesTransaction($transaction)) {
                return false;
            }
        }
        
        return true;
    }
} 