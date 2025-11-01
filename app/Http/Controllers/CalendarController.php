<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\RecurringTransactionTemplate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        // Get the current month or the requested month
        $year = $request->input('year', now()->year);
        $month = $request->input('month', now()->month);

        // Get user's budgets
        $budgets = auth()->user()->budgets;
        $selectedBudgetId = $request->input('budget_id', $budgets->first()?->id);

        if (!$selectedBudgetId) {
            return Inertia::render('Calendar/Index', [
                'budgets' => $budgets,
                'selectedBudget' => null,
                'calendarData' => null,
                'currentMonth' => now()->format('Y-m'),
            ]);
        }

        $budget = Budget::findOrFail($selectedBudgetId);

        // Generate calendar data
        $calendarData = $this->generateCalendarData($budget, $year, $month);

        return Inertia::render('Calendar/Index', [
            'budgets' => $budgets,
            'selectedBudget' => $budget,
            'calendarData' => $calendarData,
            'currentMonth' => Carbon::create($year, $month, 1)->format('Y-m'),
            'filters' => [
                'budget_id' => $selectedBudgetId,
                'year' => $year,
                'month' => $month,
            ]
        ]);
    }

    private function generateCalendarData(Budget $budget, int $year, int $month)
    {
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();

        // Get historical transactions for this month
        $historicalTransactions = Transaction::where('budget_id', $budget->id)
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->with('account')
            ->get()
            ->groupBy(function($transaction) {
                return Carbon::parse($transaction->date)->format('Y-m-d');
            });

        // Get recurring transactions and project them
        $recurringTransactions = RecurringTransactionTemplate::where('budget_id', $budget->id)
            ->get();

        $projectedTransactions = $this->projectRecurringTransactions(
            $recurringTransactions,
            $startOfMonth,
            $endOfMonth
        );

        // Build calendar days
        $days = [];
        $currentDate = $startOfMonth->copy();

        while ($currentDate->lte($endOfMonth)) {
            $dateKey = $currentDate->format('Y-m-d');
            $posted = $historicalTransactions->get($dateKey, collect());
            $projected = $projectedTransactions->get($dateKey, collect());

            // Calculate totals
            $postedIncome = $posted->where('amount_in_cents', '>=', 0)->sum('amount_in_cents');
            $postedExpenses = abs($posted->where('amount_in_cents', '<', 0)->sum('amount_in_cents'));

            $projectedIncome = $projected->where('expected_amount_in_cents', '>=', 0)->sum('expected_amount_in_cents');
            $projectedExpenses = abs($projected->where('expected_amount_in_cents', '<', 0)->sum('expected_amount_in_cents'));

            $days[] = [
                'date' => $dateKey,
                'day' => $currentDate->day,
                'isToday' => $currentDate->isToday(),
                'isWeekend' => $currentDate->isWeekend(),
                'transactions' => [
                    'posted' => $posted->map(function($t) {
                        return [
                            'id' => $t->id,
                            'description' => $t->description,
                            'amount_in_cents' => $t->amount_in_cents,
                            'category' => $t->category,
                            'account_name' => $t->account->name ?? 'N/A',
                        ];
                    })->values(),
                    'projected' => $projected->values(),
                ],
                'totals' => [
                    'posted_income_cents' => $postedIncome,
                    'posted_expenses_cents' => $postedExpenses,
                    'projected_income_cents' => $projectedIncome,
                    'projected_expenses_cents' => $projectedExpenses,
                ],
                'counts' => [
                    'posted' => $posted->count(),
                    'projected' => $projected->count(),
                ],
            ];

            $currentDate->addDay();
        }

        return [
            'month' => $month,
            'year' => $year,
            'monthName' => $startOfMonth->format('F Y'),
            'days' => $days,
            'startDayOfWeek' => $startOfMonth->dayOfWeek, // 0 = Sunday
        ];
    }

    private function projectRecurringTransactions($recurringTransactions, Carbon $startDate, Carbon $endDate)
    {
        $projected = collect();

        foreach ($recurringTransactions as $recurring) {
            $occurrences = $this->calculateOccurrences($recurring, $startDate, $endDate);

            foreach ($occurrences as $date) {
                $dateKey = $date->format('Y-m-d');

                if (!$projected->has($dateKey)) {
                    $projected->put($dateKey, collect());
                }

                $projected->get($dateKey)->push([
                    'recurring_id' => $recurring->id,
                    'description' => $recurring->description,
                    'expected_amount_in_cents' => $recurring->is_variable
                        ? ($recurring->estimated_amount_cents ?? 0)
                        : $recurring->amount_in_cents,
                    'is_variable' => $recurring->is_variable,
                    'frequency' => $recurring->frequency,
                    'category' => $recurring->category,
                ]);
            }
        }

        return $projected;
    }

    private function calculateOccurrences(RecurringTransactionTemplate $recurring, Carbon $startDate, Carbon $endDate)
    {
        $occurrences = [];
        $currentDate = Carbon::parse($recurring->start_date);

        // Don't project if start date is after the period we're looking at
        if ($currentDate->gt($endDate)) {
            return $occurrences;
        }

        // Start from the beginning of our range if recurring started before
        if ($currentDate->lt($startDate)) {
            $currentDate = $startDate->copy();
        }

        while ($currentDate->lte($endDate)) {
            // Check if we've passed the end date (if set)
            if ($recurring->end_date && $currentDate->gt(Carbon::parse($recurring->end_date))) {
                break;
            }

            // Add this occurrence
            $occurrences[] = $currentDate->copy();

            // Calculate next occurrence based on frequency
            switch ($recurring->frequency) {
                case 'daily':
                    $currentDate->addDays($recurring->frequency_value ?? 1);
                    break;
                case 'weekly':
                    $currentDate->addWeeks($recurring->frequency_value ?? 1);
                    break;
                case 'monthly':
                    $currentDate->addMonths($recurring->frequency_value ?? 1);
                    break;
                case 'yearly':
                    $currentDate->addYears($recurring->frequency_value ?? 1);
                    break;
                default:
                    // Unknown frequency, break to avoid infinite loop
                    break 2;
            }
        }

        return $occurrences;
    }
}
