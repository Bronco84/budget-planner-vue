<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Scenario;
use App\Models\ScenarioAdjustment;
use App\Models\Transaction;
use App\Services\RecurringTransactionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ProjectionsController extends Controller
{
    /**
     * The recurring transaction service.
     *
     * @var RecurringTransactionService
     */
    protected $recurringTransactionService;

    /**
     * Create a new controller instance.
     */
    public function __construct(RecurringTransactionService $recurringTransactionService)
    {
        $this->recurringTransactionService = $recurringTransactionService;

        // Authorize budget ownership (BudgetPolicy view ability) for every action.
        $this->middleware('can:view,budget');
    }

    /**
     * Show the budget projections page: every projected recurring transaction across
     * the budget's accounts (excluding accounts left out of the total balance) for the
     * next N months, grouped by month and day with running net-worth balances.
     */
    public function showBudgetProjections(Budget $budget, Request $request): Response
    {
        $validated = $request->validate([
            'months' => 'nullable|integer|min:1|max:24',
        ]);
        $monthsAhead = (int) ($validated['months'] ?? 6);
        $startDate = Carbon::today();
        $endDate = Carbon::today()->addMonths($monthsAhead)->endOfDay();

        $accounts = $budget->accounts->reject(fn (Account $account) => $account->exclude_from_total_balance);

        // Net worth of the included accounts: liabilities (positive debt) count negative.
        $startingBalance = (int) $accounts->sum(fn (Account $account) => $account->isLiability()
            ? -abs($account->current_balance_cents)
            : $account->current_balance_cents);

        $projected = $accounts->flatMap(fn (Account $account) => $this->recurringTransactionService
            ->projectTransactions($account, $startDate, $endDate)
            ->map(fn (array $transaction) => array_merge($transaction, [
                'account' => ['id' => $account->id, 'name' => $account->name],
            ])))
            ->sortBy(fn (array $transaction) => Carbon::parse($transaction['date'])->format('Y-m-d'))
            ->values();

        // Every transaction amount moves net worth by its signed amount: deposits and
        // card payments raise it, spending and card purchases lower it.
        $runningBalance = $startingBalance;
        $monthlyTotals = [];
        $transactionsByMonth = [];

        foreach ($projected as $transaction) {
            $date = Carbon::parse($transaction['date']);
            $monthKey = $date->format('F Y');
            $amount = (int) $transaction['amount_in_cents'];
            $runningBalance += $amount;

            $monthlyTotals[$monthKey] ??= ['income' => 0, 'expense' => 0, 'net' => 0, 'ending_balance' => 0];
            if ($amount > 0) {
                $monthlyTotals[$monthKey]['income'] += $amount;
            } else {
                $monthlyTotals[$monthKey]['expense'] += abs($amount);
            }
            $monthlyTotals[$monthKey]['net'] += $amount;
            $monthlyTotals[$monthKey]['ending_balance'] = $runningBalance;

            $transactionsByMonth[$monthKey][$date->format('D j')][] = array_merge($transaction, [
                'date' => $date->format('Y-m-d'),
                'running_balance' => $runningBalance,
            ]);
        }

        return Inertia::render('Budgets/Projections', [
            'budget' => $budget,
            'projections' => [
                'starting_balance' => $startingBalance,
                'monthly_totals' => $monthlyTotals,
                'transactions' => $transactionsByMonth,
            ],
            'params' => [
                'months' => $monthsAhead,
                'start_date' => $startDate->format('Y-m-d'),
            ],
        ]);
    }

    /**
     * Show the account projections page.
     *
     * @return Response
     */
    public function showAccountProjections(Budget $budget, Account $account, Request $request)
    {
        $validated = $request->validate([
            'months' => 'nullable|integer|min:1|max:60',
        ]);
        $monthsAhead = (int) ($validated['months'] ?? 3);
        $startDate = Carbon::today();
        $endDate = Carbon::today()->addMonths($monthsAhead)->endOfDay();

        // Get projected transactions for this account (from recurring templates)
        $projectedTransactions = $this->recurringTransactionService->projectTransactions(
            $account,
            $startDate,
            $endDate
        );

        // Ensure projectedTransactions is always a collection
        if (! $projectedTransactions instanceof Collection) {
            $projectedTransactions = collect($projectedTransactions);
        }

        // Get future manual transactions (not from recurring templates)
        $futureManualTransactions = $this->getFutureManualTransactions($account, $startDate, $endDate);

        // Merge manual transactions with recurring projections
        $projectedTransactions = $projectedTransactions->merge($futureManualTransactions);

        // Generate autopay projections and filter for this account
        $autopayProjections = $this->recurringTransactionService->generateAutopayProjections(
            $budget,
            $startDate,
            $endDate
        )->filter(fn ($p) => $p->account_id === $account->id)
            ->map(fn ($p) => (array) $p);

        // Merge autopay projections
        $projectedTransactions = $projectedTransactions->merge($autopayProjections)->sortBy('date')->values();

        // Project daily balance for the account
        // Use different calculation for liabilities vs assets
        $initialBalance = $account->current_balance_cents;
        $balanceProjection = $this->projectDailyBalance(
            $projectedTransactions,
            $initialBalance,
            $startDate,
            $monthsAhead,
            $account->isLiability()
        );

        // Ensure balanceProjection has the expected structure
        if (! isset($balanceProjection['days'])) {
            $balanceProjection = ['days' => []];
        }

        return Inertia::render('Accounts/Projections', [
            'budget' => $budget,
            'account' => $account,
            'projectedTransactions' => $projectedTransactions,
            'balanceProjection' => $balanceProjection,
            'monthsAhead' => $monthsAhead,
        ]);
    }

    /**
     * Project daily account balance over time based on projected transactions.
     *
     * For asset accounts: balance += transaction amount
     * For liability accounts: balance -= transaction amount
     *
     * @param  Collection  $projectedTransactions
     * @param  int  $initialBalance
     * @param  Carbon  $startDate
     * @param  int  $monthsAhead
     * @param  bool  $isLiability  Whether this is a liability account (credit card, loan, etc.)
     * @return array
     */
    protected function projectDailyBalance($projectedTransactions, $initialBalance, $startDate, $monthsAhead, bool $isLiability = false)
    {
        $today = $startDate->copy()->startOfDay();
        $endDate = $today->copy()->addMonths($monthsAhead);
        $weeklyProjection = [];
        $runningBalance = $initialBalance;

        // Group transactions by date
        $transactionsByDate = $projectedTransactions->groupBy(function ($transaction) {
            return Carbon::parse($transaction['date'])->format('Y-m-d');
        });

        // The series starts with a point for today, then one point per week ending on the
        // week's last day (the first week covers only tomorrow onwards, never days before
        // today). The final week may extend past $endDate but only applies transactions up to it.
        $weekEndDay = $today->copy()->startOfWeek()->addDays(6)->dayOfWeek;
        $lastDay = $endDate->copy();
        while ($lastDay->dayOfWeek !== $weekEndDay) {
            $lastDay->addDay();
        }

        $income = 0;
        $expense = 0;

        for ($day = $today->copy(); $day->lte($lastDay); $day->addDay()) {
            $dateKey = $day->format('Y-m-d');

            if ($day->lte($endDate) && $transactionsByDate->has($dateKey)) {
                foreach ($transactionsByDate->get($dateKey) as $transaction) {
                    $amount = $transaction['amount_in_cents'];

                    // For liabilities, subtract (spending increases debt, payments decrease debt)
                    // For assets, add (spending decreases balance, deposits increase balance)
                    $runningBalance += $isLiability ? -$amount : $amount;

                    if ($amount > 0) {
                        $income += $amount;
                    } else {
                        $expense += abs($amount);
                    }
                }
            }

            if ($day->equalTo($today) || $day->dayOfWeek === $weekEndDay) {
                $weeklyProjection[] = [
                    'date' => $dateKey,
                    'balance' => $runningBalance,
                    'income' => $income,
                    'expense' => $expense,
                ];
                $income = 0;
                $expense = 0;
            }
        }

        // Wrap the weekly projection data in an object with a 'days' property to match frontend expectations
        return ['days' => $weeklyProjection];
    }

    /**
     * Show the detailed balance projection page for an account.
     *
     * @return Response
     */
    public function showBalanceProjection(Budget $budget, Account $account, Request $request)
    {
        $validated = $request->validate([
            'months' => 'nullable|integer|min:1|max:60',
        ]);
        $monthsAhead = (int) ($validated['months'] ?? 12);

        $startDate = Carbon::today();
        $endDate = Carbon::today()->addMonths($monthsAhead)->endOfDay();

        // Get projected transactions for this account (from recurring templates)
        $projectedTransactions = $this->recurringTransactionService->projectTransactions(
            $account,
            $startDate,
            $endDate
        );

        // Ensure projectedTransactions is always a collection
        if (! $projectedTransactions instanceof Collection) {
            $projectedTransactions = collect($projectedTransactions);
        }

        // Get future manual transactions (not from recurring templates)
        $futureManualTransactions = $this->getFutureManualTransactions($account, $startDate, $endDate);

        // Merge manual transactions with recurring projections
        $projectedTransactions = $projectedTransactions->merge($futureManualTransactions);

        // Generate autopay projections and filter for this account
        $autopayProjections = $this->recurringTransactionService->generateAutopayProjections(
            $budget,
            $startDate,
            $endDate
        )->filter(fn ($p) => $p->account_id === $account->id)
            ->map(fn ($p) => (array) $p);

        // Merge autopay projections
        $projectedTransactions = $projectedTransactions->merge($autopayProjections)->sortBy('date')->values();

        // Project daily balance for the account
        // Use different calculation for liabilities vs assets
        $initialBalance = $account->current_balance_cents;
        $balanceProjection = $this->projectDailyBalance(
            $projectedTransactions,
            $initialBalance,
            $startDate,
            $monthsAhead,
            $account->isLiability()
        );

        // Ensure balanceProjection has the expected structure
        if (! isset($balanceProjection['days'])) {
            $balanceProjection = ['days' => []];
        }

        return Inertia::render('Accounts/BalanceProjection', [
            'budget' => $budget,
            'account' => $account,
            'projectedTransactions' => $projectedTransactions,
            'balanceProjection' => $balanceProjection,
            'monthsAhead' => $monthsAhead,
        ]);
    }

    /**
     * Show the multi-account projection page with scenarios.
     *
     * @return Response
     */
    public function showMultiAccountProjection(Budget $budget, Request $request)
    {
        // Validate input
        $validated = $request->validate([
            'account_ids' => 'nullable|array',
            'account_ids.*' => 'exists:accounts,id',
            'scenario_ids' => 'nullable|array',
            'scenario_ids.*' => 'exists:scenarios,id',
            'months' => 'nullable|integer|min:1|max:60',
        ]);

        $accountIds = $validated['account_ids'] ?? [];
        $scenarioIds = $validated['scenario_ids'] ?? [];
        $monthsAhead = intval($validated['months'] ?? 12);

        // If no accounts specified, use all budget accounts
        if (empty($accountIds)) {
            $accounts = $budget->accounts;
        } else {
            // Validate that all accounts belong to this budget
            $accounts = $budget->accounts()->whereIn('id', $accountIds)->get();
            if ($accounts->count() !== count($accountIds)) {
                abort(422, 'One or more accounts do not belong to this budget');
            }
        }

        $startDate = Carbon::today();
        $endDate = Carbon::today()->addMonths($monthsAhead)->endOfDay();

        // Get all scenarios for this budget
        $allScenarios = $budget->scenarios()->with('adjustments.account')->get();

        // Get active scenarios (either from request or from database)
        if (! empty($scenarioIds)) {
            $activeScenarios = $allScenarios->whereIn('id', $scenarioIds);
        } else {
            $activeScenarios = $allScenarios->where('is_active', true);
        }

        // Generate autopay projections for the entire budget
        $autopayProjections = $this->recurringTransactionService->generateAutopayProjections(
            $budget,
            $startDate,
            $endDate
        );

        // Convert autopay projections from objects to arrays for consistency
        $autopayProjections = $autopayProjections->map(function ($projection) {
            return (array) $projection;
        });

        // Group autopay projections by account
        $autopayByAccount = $autopayProjections->groupBy('account_id');

        // Calculate base projections for each account. The base projected
        // transactions are built once per account and reused for every scenario.
        $baseProjections = [];
        $baseTransactionsByAccount = [];
        foreach ($accounts as $account) {
            $projectedTransactions = $this->buildBaseProjectedTransactions($account, $autopayByAccount, $startDate, $endDate);
            $baseTransactionsByAccount[$account->id] = $projectedTransactions;

            $balanceProjection = $this->projectDailyBalance(
                $projectedTransactions,
                $account->current_balance_cents,
                $startDate,
                $monthsAhead,
                $account->isLiability()
            );

            $baseProjections[$account->id] = $balanceProjection;
        }

        // Calculate scenario projections
        $scenarioProjections = [];
        foreach ($activeScenarios as $scenario) {
            $scenarioProjections[$scenario->id] = [];

            foreach ($accounts as $account) {
                // Reuse the base projected transactions computed above
                $projectedTransactions = $baseTransactionsByAccount[$account->id];

                // Apply scenario adjustments
                $adjustedTransactions = $this->applyScenarioAdjustments(
                    $projectedTransactions->toArray(),
                    $scenario,
                    $account,
                    $startDate,
                    $endDate
                );

                // Calculate balance projection with scenario
                $scenarioBalanceProjection = $this->projectDailyBalance(
                    collect($adjustedTransactions),
                    $account->current_balance_cents,
                    $startDate,
                    $monthsAhead,
                    $account->isLiability()
                );

                $scenarioProjections[$scenario->id][$account->id] = $scenarioBalanceProjection;
            }
        }

        return Inertia::render('Budgets/MultiAccountProjection', [
            'budget' => $budget,
            'accounts' => $accounts,
            'baseProjections' => $baseProjections,
            'scenarioProjections' => $scenarioProjections,
            'scenarios' => $allScenarios,
            'activeScenarios' => $activeScenarios->values(),
            // Templates a modify_existing adjustment can target.
            'recurringTemplates' => $budget->recurringTransactionTemplates()
                ->orderBy('description')
                ->get(['id', 'description', 'friendly_label', 'account_id', 'amount_in_cents', 'is_dynamic_amount']),
            'monthsAhead' => $monthsAhead,
            'breadcrumbs' => \Breadcrumbs::generate('budget.projections.multi-account', $budget),
        ]);
    }

    /**
     * Base projected transactions for an account: recurring projections, plus
     * the account's autopay projections, plus future manual transactions.
     */
    private function buildBaseProjectedTransactions(Account $account, Collection $autopayByAccount, Carbon $startDate, Carbon $endDate): Collection
    {
        $projectedTransactions = $this->recurringTransactionService->projectTransactions(
            $account,
            $startDate,
            $endDate
        );

        if (! $projectedTransactions instanceof Collection) {
            $projectedTransactions = collect($projectedTransactions);
        }

        // Add autopay projections for this account
        if ($autopayByAccount->has($account->id)) {
            $projectedTransactions = $projectedTransactions->merge($autopayByAccount->get($account->id));
        }

        // Add future manual transactions for this account
        return $projectedTransactions->merge($this->getFutureManualTransactions($account, $startDate, $endDate));
    }

    /**
     * Apply scenario adjustments to projected transactions.
     */
    protected function applyScenarioAdjustments(
        array $baseTransactions,
        Scenario $scenario,
        Account $account,
        Carbon $startDate,
        Carbon $endDate
    ): array {
        $adjustments = $scenario->relationLoaded('adjustments')
            ? $scenario->adjustments
            : $scenario->adjustments()->get();

        // modify_existing adjustments change their target template's occurrences
        // (which only appear in that template's account's projection); the other
        // types add transactions to their own account.
        $modifications = $adjustments->where('adjustment_type', ScenarioAdjustment::TYPE_MODIFY_EXISTING);
        $additions = $adjustments
            ->where('adjustment_type', '!=', ScenarioAdjustment::TYPE_MODIFY_EXISTING)
            ->where('account_id', $account->id);

        if ($modifications->isEmpty() && $additions->isEmpty()) {
            return $baseTransactions;
        }

        foreach ($modifications as $modification) {
            $baseTransactions = array_map(
                fn (array $transaction) => $modification->applyToExistingTransaction($transaction),
                $baseTransactions
            );
        }

        // Generate additional transactions from adjustments
        $additionalTransactions = [];
        foreach ($additions as $adjustment) {
            $adjustmentTransactions = $adjustment->generateProjectedTransactions($startDate, $endDate);
            $additionalTransactions = array_merge($additionalTransactions, $adjustmentTransactions);
        }

        // Merge with base transactions
        $allTransactions = array_merge($baseTransactions, $additionalTransactions);

        // Sort by date
        usort($allTransactions, function ($a, $b) {
            return strcmp($a['date'], $b['date']);
        });

        return $allTransactions;
    }

    /**
     * Get future manual transactions for an account.
     *
     * These are one-time transactions created by the user with a future date,
     * that are not linked to recurring transaction templates or transfers.
     */
    protected function getFutureManualTransactions(Account $account, Carbon $startDate, Carbon $endDate): Collection
    {
        return Transaction::where('account_id', $account->id)
            ->where('date', '>=', $startDate->format('Y-m-d'))
            ->where('date', '<=', $endDate->format('Y-m-d'))
            ->whereNull('recurring_transaction_template_id') // Exclude recurring transactions
            ->whereNull('transfer_id') // Exclude transfers (handled separately if needed)
            ->get()
            ->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'budget_id' => $transaction->budget_id,
                    'account_id' => $transaction->account_id,
                    'description' => $transaction->description,
                    'amount_in_cents' => $transaction->amount_in_cents,
                    'category' => $transaction->category,
                    'date' => $transaction->date,
                    'is_projected' => $transaction->date->isFuture(),
                    'is_manual' => true,
                    'recurring_transaction_template_id' => null,
                ];
            });
    }
}
