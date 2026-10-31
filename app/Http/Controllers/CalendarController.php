<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Services\RecurringTransactionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        // Get the current month or the requested month
        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'between:1900,2100'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
        ]);
        $year = (int) ($validated['year'] ?? now()->year);
        $month = (int) ($validated['month'] ?? now()->month);

        // Get user's active budget
        $activeBudget = auth()->user()->getActiveBudget();

        // If no budgets exist, redirect to create page
        if (! $activeBudget) {
            return redirect()->route('budgets.create')
                ->with('message', 'Please create a budget to use the calendar.');
        }

        // Use active budget (can be overridden by query param for future multi-budget support)
        $selectedBudgetId = $request->input('budget_id', $activeBudget->id);
        $budget = Budget::findOrFail($selectedBudgetId);
        $this->authorize('view', $budget);

        // Get all user's budgets for potential budget switcher in calendar
        $budgets = auth()->user()->accessibleBudgets();

        // Generate calendar data
        $calendarData = $this->generateCalendarData($budget, $year, $month);

        // Get calendar events for this month
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();

        $calendarEvents = auth()->user()->calendarEvents()
            ->with('calendarConnection')
            ->whereHas('calendarConnection', function ($query) {
                $query->where('is_active', true);
            })
            ->inDateRange($startOfMonth, $endOfMonth)
            ->orderBy('start_date')
            ->get()
            ->map(function ($event) {
                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'description' => $event->description,
                    'start_date' => $event->start_date->format('Y-m-d'),
                    'end_date' => $event->end_date?->format('Y-m-d'),
                    'all_day' => $event->all_day,
                    'calendar_name' => $event->calendarConnection->calendar_name,
                    'days_until' => $event->daysUntil(),
                ];
            });

        return Inertia::render('Calendar/Index', [
            'budgets' => $budgets,
            'selectedBudget' => $budget,
            'calendarData' => $calendarData,
            'calendarEvents' => $calendarEvents,
            'currentMonth' => Carbon::create($year, $month, 1)->format('Y-m'),
            'filters' => [
                'budget_id' => $selectedBudgetId,
                'year' => $year,
                'month' => $month,
            ],
        ]);
    }

    private function generateCalendarData(Budget $budget, int $year, int $month)
    {
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();

        // Get historical transactions for this month
        $historicalTransactions = Transaction::where('budget_id', $budget->id)
            ->whereDate('date', '>=', $startOfMonth)
            ->whereDate('date', '<=', $endOfMonth)
            ->with('account')
            ->get()
            ->groupBy(function ($transaction) {
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
                    'posted' => $posted->map(function ($t) {
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
        $today = Carbon::today();

        foreach ($recurringTransactions as $recurring) {
            $occurrences = $this->calculateOccurrences($recurring, $startDate, $endDate);

            foreach ($occurrences as $date) {
                // Only include projections for today or future dates
                if ($date->lt($today)) {
                    continue;
                }

                $dateKey = $date->format('Y-m-d');

                if (! $projected->has($dateKey)) {
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

    /**
     * Occurrences within the range, using the same schedule rules as the
     * projections (e.g. bimonthly = twice a month on two days).
     *
     * @return array<Carbon>
     */
    private function calculateOccurrences(RecurringTransactionTemplate $recurring, Carbon $startDate, Carbon $endDate)
    {
        $templateEnd = $recurring->end_date ? min($endDate, Carbon::parse($recurring->end_date)) : $endDate;
        if ($templateEnd->lt($startDate)) {
            return [];
        }

        return app(RecurringTransactionService::class)->generateOccurrenceDates($recurring, $startDate, $templateEnd);
    }
}
