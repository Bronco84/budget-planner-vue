<?php

namespace App\Services;

use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RecurringTransactionService
{
    public function projectTransactions(Account $account, Carbon $startDate, Carbon $endDate)
    {
        $projectedTransactions = collect();

        // Get all active recurring transaction templates
        $templates = RecurringTransactionTemplate::where('account_id', $account->id)
            ->where(function ($query) use ($startDate) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $startDate);
            })
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

                    $amount = $template->is_dynamic_amount ? $template->calculateDynamicAmount($date) : $template->amount_in_cents;

                    $projectedTransactions->push([
                        'account' => $account,
                        'budget_id' => $template->budget_id,
                        'description' => $template->description,
                        'amount_in_cents' => $amount,
                        'category' => $template->category,
                        'account_id' => $template->account_id,
                        'date' => $date->copy(),
                        'recurring_transaction_template_id' => $template->id,
                        'created_by' => $template->created_by,
                        'is_projected' => true
                    ]);
                }

                $date->addDay();
            }
        }

        return $projectedTransactions->sortBy('date')->values();
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

    public function getNextDate(RecurringTransactionTemplate $template, Carbon $fromDate): ?Carbon
    {
        // If the from date is before the template start date, use the start date instead
        if ($fromDate->lt($template->start_date)) {
            $fromDate = Carbon::parse($template->start_date);
        }

        // If we've reached the end date already, return null
        if ($template->end_date && $fromDate->gte($template->end_date)) {
            return null;
        }

        $nextDate = null;

        switch ($template->frequency) {
            case RecurringTransactionTemplate::FREQUENCY_DAILY:
                $nextDate = $fromDate->copy()->addDay();
                break;
            case RecurringTransactionTemplate::FREQUENCY_WEEKLY:
                $dayOfWeek = $template->day_of_week ?? $fromDate->dayOfWeek;
                $nextDate = $fromDate->copy()->next($dayOfWeek);
                break;
            case RecurringTransactionTemplate::FREQUENCY_BIWEEKLY:
                $dayOfWeek = $template->day_of_week ?? $fromDate->dayOfWeek;
                $nextDate = $fromDate->copy()->next($dayOfWeek)->addWeek();
                break;
            case RecurringTransactionTemplate::FREQUENCY_MONTHLY:
                $dayOfMonth = $template->day_of_month ?? $fromDate->day;
                $nextDate = $fromDate->copy()->addMonth()->day($dayOfMonth);

                // Handle cases where the day doesn't exist in the next month
                while ($nextDate->lt($fromDate)) {
                    $nextDate->addMonth();
                }
                break;
            case RecurringTransactionTemplate::FREQUENCY_BIMONTHLY:
                $firstDay = $template->first_day_of_month ?? 1;
                $secondDay = $template->day_of_month ?? 15;

                // Ensure firstDay is before secondDay
                if ($firstDay > $secondDay) {
                    $temp = $firstDay;
                    $firstDay = $secondDay;
                    $secondDay = $temp;
                }

                $currentDay = $fromDate->day;
                $nextDate = $fromDate->copy();

                if ($currentDay < $firstDay) {
                    // Next date is the first day of this month
                    $nextDate->day($firstDay);
                } else if ($currentDay < $secondDay) {
                    // Next date is the second day of this month
                    $nextDate->day($secondDay);
                } else {
                    // Next date is the first day of next month
                    $nextDate->addMonth()->day($firstDay);
                }

                // Handle cases where the day doesn't exist in the month
                while ($nextDate->lt($fromDate)) {
                    if ($currentDay < $secondDay) {
                        $nextDate = $fromDate->copy()->day($secondDay);
                    } else {
                        $nextDate = $fromDate->copy()->addMonth()->day($firstDay);
                    }
                }
                break;
            case RecurringTransactionTemplate::FREQUENCY_QUARTERLY:
                $dayOfMonth = $template->day_of_month ?? $fromDate->day;
                $nextDate = $fromDate->copy()->addMonths(3)->day($dayOfMonth);

                // Handle cases where the day doesn't exist in the next quarter
                while ($nextDate->lt($fromDate)) {
                    $nextDate->addMonth();
                }
                break;
            case RecurringTransactionTemplate::FREQUENCY_YEARLY:
                $nextDate = $fromDate->copy()->addYear();
                break;
            default:
                return null;
        }

        // Check if we've gone past the end date
        if ($template->end_date && $nextDate->gt($template->end_date)) {
            return null;
        }

        return $nextDate;
    }
}
