<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\PayoffPlan;
use App\Services\PayoffPlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PayoffPlanController extends Controller
{
    protected PayoffPlanService $payoffPlanService;

    public function __construct(PayoffPlanService $payoffPlanService)
    {
        $this->payoffPlanService = $payoffPlanService;
    }

    /**
     * Display a listing of payoff plans for a budget.
     */
    public function index(Budget $budget): Response
    {
        $this->authorize('view', $budget);

        $plans = $budget->payoffPlans()
            ->with(['debts.account', 'goals'])
            ->orderBy('is_active', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('PayoffPlans/Index', [
            'budget' => $budget,
            'plans' => $plans,
        ]);
    }

    /**
     * Show the wizard to create a new payoff plan.
     */
    public function create(Budget $budget): Response
    {
        $this->authorize('update', $budget);

        // Get available cash flow
        $availableCashFlow = $this->payoffPlanService->calculateAvailableCashFlow($budget);

        // Get all debt accounts
        $debtAccounts = $this->payoffPlanService->getDebtAccounts($budget)->map(function ($account) {
            return [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'current_balance_cents' => PayoffPlanService::debtBalanceCents($account),
            ];
        });

        return Inertia::render('PayoffPlans/Create', [
            'budget' => $budget,
            'availableCashFlow' => $availableCashFlow,
            'debtAccounts' => $debtAccounts,
        ]);
    }

    /**
     * Store a newly created payoff plan.
     */
    public function store(Request $request, Budget $budget)
    {
        $this->authorize('update', $budget);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'strategy' => 'required|in:snowball,avalanche,custom',
            'monthly_extra_payment_cents' => 'required|integer|min:0',
            'start_date' => 'required|date',
            'debts' => 'required|array|min:1',
            'debts.*.account_id' => ['required', Rule::exists('accounts', 'id')->where('budget_id', $budget->id)],
            'debts.*.interest_rate' => 'required|numeric|min:0|max:100',
            'debts.*.minimum_payment_cents' => 'required|integer|min:0',
            'debts.*.priority' => 'nullable|integer|min:0',
            'goals' => 'nullable|array',
            'goals.*.name' => 'required|string|max:255',
            'goals.*.description' => 'nullable|string',
            'goals.*.target_amount_cents' => 'required|integer|min:0',
            'goals.*.monthly_contribution_cents' => 'required|integer|min:0',
            'goals.*.target_date' => 'nullable|date',
            'goals.*.goal_type' => 'required|in:savings,investment,purchase,other',
            'goals.*.priority' => 'nullable|integer|min:0',
        ]);

        // All-or-nothing: a failure must not leave the budget without its active plan.
        $plan = DB::transaction(function () use ($budget, $validated) {
            // Deactivate any existing active plans
            $budget->payoffPlans()->where('is_active', true)->update(['is_active' => false]);

            // Create the plan
            $plan = $budget->payoffPlans()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'strategy' => $validated['strategy'],
                'monthly_extra_payment_cents' => $validated['monthly_extra_payment_cents'],
                'start_date' => $validated['start_date'],
                'is_active' => true,
            ]);

            // Create debts
            foreach ($validated['debts'] as $debtData) {
                $account = $budget->accounts()->findOrFail($debtData['account_id']);

                $plan->debts()->create([
                    'account_id' => $account->id,
                    'starting_balance_cents' => PayoffPlanService::debtBalanceCents($account),
                    'interest_rate' => $debtData['interest_rate'],
                    'minimum_payment_cents' => $debtData['minimum_payment_cents'],
                    'priority' => $debtData['priority'] ?? 0,
                ]);
            }

            // Create goals if provided
            if (! empty($validated['goals'])) {
                foreach ($validated['goals'] as $goalData) {
                    $plan->goals()->create(array_merge($goalData, ['priority' => $goalData['priority'] ?? 0]));
                }
            }

            return $plan;
        });

        return redirect()->route('payoff-plans.show', ['budget' => $budget, 'payoff_plan' => $plan])
            ->with('message', 'Payoff plan created successfully');
    }

    /**
     * Display the specified payoff plan with projections.
     */
    public function show(Budget $budget, PayoffPlan $payoff_plan): Response
    {
        $this->authorize('view', $budget);

        $plan = $this->loadPlan($payoff_plan);
        $plan->debts->each->append(['current_balance_cents', 'paid_down_cents']);

        // Calculate projections (from the debts' live balances; goals are
        // funded once the debts are paid off)
        $debtProjection = $this->payoffPlanService->calculatePayoffProjection($plan);
        $goalProjections = $this->payoffPlanService->calculateGoalProjections($plan, $debtProjection);

        return Inertia::render('PayoffPlans/Show', [
            'budget' => $budget,
            'plan' => $plan,
            'debtProjection' => $debtProjection,
            'goalProjections' => $goalProjections,
        ]);
    }

    /**
     * Show the form for editing the specified payoff plan.
     */
    public function edit(Budget $budget, PayoffPlan $payoff_plan): Response
    {
        $this->authorize('update', $budget);

        $plan = $this->loadPlan($payoff_plan);
        $plan->debts->each->append('current_balance_cents');

        return Inertia::render('PayoffPlans/Edit', [
            'budget' => $budget,
            'plan' => $plan,
        ]);
    }

    /**
     * Update the specified payoff plan.
     */
    public function update(Request $request, Budget $budget, PayoffPlan $payoff_plan)
    {
        $this->authorize('update', $budget);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'strategy' => 'required|in:snowball,avalanche,custom',
            'monthly_extra_payment_cents' => 'required|integer|min:0',
            'is_active' => 'boolean',
            'debts' => 'sometimes|array',
            'debts.*.id' => ['required', 'integer', Rule::exists('payoff_plan_debts', 'id')->where('payoff_plan_id', $payoff_plan->id)],
            'debts.*.interest_rate' => 'required|numeric|min:0|max:100',
            'debts.*.minimum_payment_cents' => 'required|integer|min:0',
            'debts.*.priority' => 'nullable|integer|min:0',
            'goals' => 'sometimes|array',
            'goals.*.id' => ['required', 'integer', Rule::exists('payoff_plan_goals', 'id')->where('payoff_plan_id', $payoff_plan->id)],
            'goals.*.name' => 'required|string|max:255',
            'goals.*.target_amount_cents' => 'required|integer|min:0',
            'goals.*.monthly_contribution_cents' => 'required|integer|min:0',
            'goals.*.priority' => 'nullable|integer|min:0',
        ]);

        // Only one plan per budget may be active (same invariant as store).
        DB::transaction(function () use ($budget, $payoff_plan, $validated) {
            if (! empty($validated['is_active'])) {
                $budget->payoffPlans()
                    ->where('is_active', true)
                    ->whereKeyNot($payoff_plan->id)
                    ->update(['is_active' => false]);
            }

            $payoff_plan->update(Arr::except($validated, ['debts', 'goals']));

            foreach ($validated['debts'] ?? [] as $debtData) {
                $payoff_plan->debts()->whereKey($debtData['id'])->firstOrFail()->update(array_filter(
                    Arr::only($debtData, ['interest_rate', 'minimum_payment_cents', 'priority']),
                    fn ($value) => $value !== null
                ));
            }

            foreach ($validated['goals'] ?? [] as $goalData) {
                $payoff_plan->goals()->whereKey($goalData['id'])->firstOrFail()->update(array_filter(
                    Arr::only($goalData, ['name', 'target_amount_cents', 'monthly_contribution_cents', 'priority']),
                    fn ($value) => $value !== null
                ));
            }
        });

        return redirect()->route('payoff-plans.show', ['budget' => $budget, 'payoff_plan' => $payoff_plan])
            ->with('message', 'Payoff plan updated successfully');
    }

    /**
     * Remove the specified payoff plan.
     */
    public function destroy(Budget $budget, PayoffPlan $payoff_plan)
    {
        $this->authorize('update', $budget);

        $payoff_plan->delete();

        return redirect()->route('payoff-plans.index', $budget)
            ->with('message', 'Payoff plan deleted successfully');
    }

    /**
     * Load a plan with its debts (and accounts) and its goals in funding order.
     */
    protected function loadPlan(PayoffPlan $plan): PayoffPlan
    {
        return $plan->load([
            'debts.account',
            'goals' => fn ($query) => $query->orderBy('priority')->orderBy('id'),
        ]);
    }
}
