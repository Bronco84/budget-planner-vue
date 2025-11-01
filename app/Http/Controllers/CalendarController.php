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
                'breadcrumbs' => [
                    ['title' => 'Dashboard', 'url' => route('dashboard')],
                    ['title' => 'Calendar', 'url' => null],
                ],
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
            ],
            'breadcrumbs' => [
                ['title' => 'Dashboard', 'url' => route('dashboard')],
                ['title' => 'Calendar', 'url' => null],
            ],
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
        $recurringStart = Carbon::parse($recurring->start_date);

        // Don't project if start date is after the period we're looking at
        if ($recurringStart->gt($endDate)) {
            return $occurrences;
        }

        // Calculate the first occurrence within our date range
        $currentDate = $recurringStart->copy();

        // If the recurring transaction started before our range,
        // fast-forward to find the first occurrence in our range
        if ($currentDate->lt($startDate)) {
            // Calculate how many periods to skip
            $daysDiff = $currentDate->diffInDays($startDate);

            switch ($recurring->frequency) {
                case 'daily':
                    $periodsToSkip = $daysDiff;
                    $currentDate->addDays($periodsToSkip);
                    break;
                case 'weekly':
                    $periodsToSkip = floor($daysDiff / 7);
                    $currentDate->addWeeks($periodsToSkip);
                    break;
                case 'biweekly':
                    $periodsToSkip = floor($daysDiff / 14);
                    $currentDate->addWeeks($periodsToSkip * 2);
                    break;
                case 'monthly':
                    $periodsToSkip = $currentDate->diffInMonths($startDate);
                    $currentDate->addMonths($periodsToSkip);
                    break;
                case 'bimonthly':
                    $periodsToSkip = floor($currentDate->diffInMonths($startDate) / 2);
                    $currentDate->addMonths($periodsToSkip * 2);
                    break;
                case 'yearly':
                    $periodsToSkip = $currentDate->diffInYears($startDate);
                    $currentDate->addYears($periodsToSkip);
                    break;
            }

            // Make sure we're not before the range after fast-forwarding
            while ($currentDate->lt($startDate)) {
                $this->addPeriod($currentDate, $recurring->frequency);
            }
        }

        // Now generate occurrences within the date range
        while ($currentDate->lte($endDate)) {
            // Check if we've passed the end date (if set)
            if ($recurring->end_date && $currentDate->gt(Carbon::parse($recurring->end_date))) {
                break;
            }

            // Add this occurrence
            $occurrences[] = $currentDate->copy();

            // Move to next occurrence
            $this->addPeriod($currentDate, $recurring->frequency);
        }

        return $occurrences;
    }

    private function addPeriod(Carbon $date, string $frequency)
    {
        switch ($frequency) {
            case 'daily':
                $date->addDay();
                break;
            case 'weekly':
                $date->addWeek();
                break;
            case 'biweekly':
                $date->addWeeks(2);
                break;
            case 'monthly':
                $date->addMonth();
                break;
            case 'bimonthly':
                $date->addMonths(2);
                break;
            case 'yearly':
                $date->addYear();
                break;
            default:
                // Unknown frequency
                $date->addCentury(); // Effectively stop the loop
                break;
        }
    }
}
