<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\RecurringTransactionTemplate;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ProjectionService
{
    /**
     * Project transactions for a budget into the future
     *
     * @param Budget $budget The budget to project transactions for
     * @param Carbon $startDate The date to start projections from
     * @param int $months The number of months to project
     * @return array An array of projected transactions grouped by month
     */
    public function projectBudgetTransactions(Budget $budget, Carbon $startDate, int $months = 6): array
    {
        $endDate = $startDate->copy()->addMonths($months);
        $projectedData = [];
        
        // Get all recurring transaction templates for this budget
        $templates = $budget->recurringTransactionTemplates()
            ->where(function ($query) use ($startDate) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $startDate);
            })
            ->get();
            
        // Project each recurring transaction template
        $projectedTransactions = $this->projectRecurringTransactions($templates, $startDate, $endDate);
        
        // Add actual future transactions that have already been created
        $actualFutureTransactions = $budget->transactions()
            ->where('date', '>=', $startDate)
            ->where('date', '<=', $endDate)
            ->get()
            ->map(function ($transaction) {
                // Mark as actual transaction
                $transaction->is_projected = false;
                return $transaction;
            });
            
        // Merge both collections and sort by date
        $allTransactions = $projectedTransactions->merge($actualFutureTransactions)
            ->sortBy('date');
            
        // Group by month and day
        $groupedTransactions = [];
        $runningBalance = $this->calculateStartingBalance($budget, $startDate);
        
        foreach ($allTransactions as $transaction) {
            $date = Carbon::parse($transaction->date);
            $monthKey = $date->format('F Y');
            $dayKey = $date->format('j');
            
            // Calculate running balance
            $runningBalance += $transaction->amount_in_cents;
            $transaction->running_balance = $runningBalance;
            
            // Create month group if it doesn't exist
            if (!isset($groupedTransactions[$monthKey])) {
                $groupedTransactions[$monthKey] = [];
            }
            
            // Create day group if it doesn't exist
            if (!isset($groupedTransactions[$monthKey][$dayKey])) {
                $groupedTransactions[$monthKey][$dayKey] = [];
            }
            
            // Add transaction to the group
            $groupedTransactions[$monthKey][$dayKey][] = $transaction;
        }
        
        // Calculate monthly totals
        $monthlyTotals = [];
        foreach ($groupedTransactions as $month => $days) {
            $income = 0;
            $expense = 0;
            
            foreach ($days as $dayTransactions) {
                foreach ($dayTransactions as $transaction) {
                    if ($transaction->amount_in_cents > 0) {
                        $income += $transaction->amount_in_cents;
                    } else {
                        $expense += $transaction->amount_in_cents;
                    }
                }
            }
            
            $monthlyTotals[$month] = [
                'income' => $income,
                'expense' => $expense,
                'net' => $income + $expense, // Expense is already negative
                'ending_balance' => end($dayTransactions)[count(end($dayTransactions))-1]->running_balance
            ];
        }
        
        return [
            'transactions' => $groupedTransactions,
            'monthly_totals' => $monthlyTotals,
            'starting_balance' => $runningBalance
        ];
    }
    
    /**
     * Project recurring transactions
     *
     * @param Collection $templates Collection of RecurringTransactionTemplate models
     * @param Carbon $startDate The date to start projections from
     * @param Carbon $endDate The date to end projections
     * @return Collection Collection of projected transactions
     */
    protected function projectRecurringTransactions(Collection $templates, Carbon $startDate, Carbon $endDate): Collection
    {
        $projectedTransactions = collect();
        
        foreach ($templates as $template) {
            $date = $startDate->copy();
            
            // If template starts in the future, use that date
            if ($template->start_date && Carbon::parse($template->start_date)->gt($date)) {
                $date = Carbon::parse($template->start_date);
            }
            
            // Calculate end date based on template end_date or our projection end date
            $templateEndDate = $template->end_date 
                ? min($endDate, Carbon::parse($template->end_date)) 
                : $endDate;
                
            // Generate transactions until the end date
            while ($date->lte($templateEndDate)) {
                if ($this->shouldGenerateForDate($template, $date)) {
                    // Create a projected transaction
                    $transaction = new Transaction();
                    $transaction->description = $template->description;
                    $transaction->amount_in_cents = $template->amount_in_cents;
                    $transaction->category = $template->category;
                    $transaction->date = $date->copy();
                    $transaction->budget_id = $template->budget_id;
                    $transaction->account_id = $template->account_id;
                    $transaction->recurring_transaction_template_id = $template->id;
                    $transaction->is_projected = true;
                    
                    $projectedTransactions->push($transaction);
                }
                
                // Move to the next date based on frequency
                $date = $this->getNextDate($template, $date);
            }
        }
        
        return $projectedTransactions;
    }
    
    /**
     * Determine if a transaction should be generated for the given date
     *
     * @param RecurringTransactionTemplate $template
     * @param Carbon $date
     * @return bool
     */
    protected function shouldGenerateForDate(RecurringTransactionTemplate $template, Carbon $date): bool
    {
        switch ($template->frequency) {
            case 'daily':
                return true;
                
            case 'weekly':
                return $date->dayOfWeek == $template->day_of_week;
                
            case 'biweekly':
                // TODO: Implement better logic for biweekly
                return $date->dayOfWeek == $template->day_of_week && $date->weekOfYear % 2 == 0;
                
            case 'monthly':
                return $date->day == ($template->day_of_month ?? 1);
                
            case 'quarterly':
                return $date->day == ($template->day_of_month ?? 1) && $date->month % 3 == 0;
                
            case 'yearly':
                // For yearly, match the month and day
                $startDate = Carbon::parse($template->start_date);
                return $date->month == $startDate->month && $date->day == $startDate->day;
                
            default:
                return false;
        }
    }
    
    /**
     * Get the next date based on the template's frequency
     *
     * @param RecurringTransactionTemplate $template
     * @param Carbon $date
     * @return Carbon
     */
    protected function getNextDate(RecurringTransactionTemplate $template, Carbon $date): Carbon
    {
        $nextDate = $date->copy();
        
        switch ($template->frequency) {
            case 'daily':
                return $nextDate->addDay();
                
            case 'weekly':
                return $nextDate->addWeek();
                
            case 'biweekly':
                return $nextDate->addWeeks(2);
                
            case 'monthly':
                return $nextDate->addMonth();
                
            case 'quarterly':
                return $nextDate->addMonths(3);
                
            case 'yearly':
                return $nextDate->addYear();
                
            default:
                return $nextDate->addMonth();
        }
    }
    
    /**
     * Calculate the starting balance for projections
     *
     * @param Budget $budget
     * @param Carbon $startDate
     * @return int The starting balance in cents
     */
    protected function calculateStartingBalance(Budget $budget, Carbon $startDate): int
    {
        // Get the total balance from all accounts
        $accountsBalance = $budget->accounts()->sum('current_balance_cents');
        
        // Get pending transactions that haven't been processed yet
        $pendingTransactions = $budget->transactions()
            ->where('date', '<', $startDate)
            ->where('is_reconciled', false)
            ->sum('amount_in_cents');
            
        return $accountsBalance + $pendingTransactions;
    }
} 