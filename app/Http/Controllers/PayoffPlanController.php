<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\PayoffPlan;
use App\Services\PayoffPlanService;
use Illuminate\Http\Request;
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
                'current_balance_cents' => $account->current_balance_cents,
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
            'debts.*.account_id' => 'required|exists:accounts,id',
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
        ]);

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
                'starting_balance_cents' => $account->current_balance_cents,
                'interest_rate' => $debtData['interest_rate'],
                'minimum_payment_cents' => $debtData['minimum_payment_cents'],
                'priority' => $debtData['priority'] ?? 0,
            ]);
        }

        // Create goals if provided
        if (!empty($validated['goals'])) {
            foreach ($validated['goals'] as $goalData) {
                $plan->goals()->create($goalData);
            }
        }

        return redirect()->route('payoff-plans.show', ['budget' => $budget, 'payoff_plan' => $plan])
            ->with('message', 'Payoff plan created successfully');
    }

    /**
     * Display the specified payoff plan with projections.
     */
    public function show(Budget $budget, PayoffPlan $payoff_plan): Response
    {
        $this->authorize('view', $budget);

        $plan = $payoff_plan->load(['debts.account', 'goals']);

        // Calculate projections
        $debtProjection = $this->payoffPlanService->calculatePayoffProjection($plan);
        $goalProjections = $this->payoffPlanService->calculateGoalProjections($plan);

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

        $plan = $payoff_plan->load(['debts.account', 'goals']);

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
        ]);

        $payoff_plan->update($validated);

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
     * Preview strategy comparisons before creating a plan.
     */
    public function preview(Request $request, Budget $budget)
    {
        $this->authorize('view', $budget);

        $validated = $request->validate([
            'debts' => 'required|array|min:1',
            'debts.*.account_id' => 'required|exists:accounts,id',
            'debts.*.balance' => 'required|integer|min:0',
            'debts.*.interest_rate' => 'required|numeric|min:0|max:100',
            'debts.*.minimum_payment' => 'required|integer|min:0',
            'monthly_extra_payment' => 'required|integer|min:0',
        ]);

        $comparisons = $this->payoffPlanService->compareStrategies(
            $budget,
            $validated['debts'],
            $validated['monthly_extra_payment']
        );

        return response()->json([
            'comparisons' => $comparisons,
        ]);
    }
}