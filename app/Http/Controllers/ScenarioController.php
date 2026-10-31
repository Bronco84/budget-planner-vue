<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Scenario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ScenarioController extends Controller
{
    /**
     * Display a listing of scenarios for a budget.
     *
     * @return JsonResponse
     */
    public function index(Budget $budget)
    {
        // Ensure user has access to this budget
        $this->authorize('view', $budget);

        $scenarios = $budget->scenarios()
            ->with('adjustments.account')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'scenarios' => $scenarios,
        ]);
    }

    /**
     * Store a newly created scenario.
     *
     * @return JsonResponse
     */
    public function store(Request $request, Budget $budget)
    {
        // Ensure user has access to this budget
        $this->authorize('update', $budget);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'is_active' => 'boolean',
            'adjustments' => 'required|array|min:1',
            'adjustments.*.adjustment_type' => 'required|in:one_time_expense,recurring_expense,debt_paydown,savings_contribution,modify_existing',
            'adjustments.*.account_id' => 'required|exists:accounts,id',
            'adjustments.*.amount_in_cents' => 'required|integer',
            'adjustments.*.start_date' => 'required|date',
            'adjustments.*.end_date' => 'nullable|date|after_or_equal:adjustments.*.start_date',
            'adjustments.*.frequency' => 'nullable|in:daily,weekly,biweekly,monthly,quarterly,yearly',
            'adjustments.*.day_of_week' => 'nullable|integer|min:0|max:6',
            'adjustments.*.day_of_month' => 'nullable|integer|min:1|max:31',
            'adjustments.*.description' => 'nullable|string',
            'adjustments.*.target_recurring_template_id' => ['nullable', Rule::exists('recurring_transaction_templates', 'id')->where('budget_id', $budget->id)],
        ]);

        // Validate that all accounts belong to this budget
        $accountIds = collect($validated['adjustments'])->pluck('account_id')->unique();
        $validAccountIds = $budget->accounts()->whereIn('id', $accountIds)->pluck('id');

        if ($accountIds->count() !== $validAccountIds->count()) {
            return response()->json([
                'message' => 'One or more accounts do not belong to this budget.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Create scenario
            $scenario = $budget->scenarios()->create([
                'user_id' => auth()->id(),
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'color' => $validated['color'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            // Create adjustments
            foreach ($validated['adjustments'] as $adjustmentData) {
                $scenario->adjustments()->create($adjustmentData);
            }

            DB::commit();

            // Load relationships for response
            $scenario->load('adjustments.account');

            return response()->json([
                'message' => 'Scenario created successfully.',
                'scenario' => $scenario,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to create scenario', ['budget_id' => $budget->id, 'exception' => $e]);

            return response()->json([
                'message' => 'Failed to create scenario.',
            ], 500);
        }
    }

    /**
     * Display the specified scenario.
     *
     * @return JsonResponse
     */
    public function show(Budget $budget, Scenario $scenario)
    {
        // Ensure user has access to this budget
        $this->authorize('view', $budget);

        $scenario->load('adjustments.account');

        return response()->json([
            'scenario' => $scenario,
        ]);
    }

    /**
     * Update the specified scenario.
     *
     * @return JsonResponse
     */
    public function update(Request $request, Budget $budget, Scenario $scenario)
    {
        // Ensure user has access to this budget
        $this->authorize('update', $budget);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'sometimes|required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'is_active' => 'boolean',
            'adjustments' => 'sometimes|required|array|min:1',
            'adjustments.*.id' => 'nullable|exists:scenario_adjustments,id',
            'adjustments.*.adjustment_type' => 'required|in:one_time_expense,recurring_expense,debt_paydown,savings_contribution,modify_existing',
            'adjustments.*.account_id' => 'required|exists:accounts,id',
            'adjustments.*.amount_in_cents' => 'required|integer',
            'adjustments.*.start_date' => 'required|date',
            'adjustments.*.end_date' => 'nullable|date|after_or_equal:adjustments.*.start_date',
            'adjustments.*.frequency' => 'nullable|in:daily,weekly,biweekly,monthly,quarterly,yearly',
            'adjustments.*.day_of_week' => 'nullable|integer|min:0|max:6',
            'adjustments.*.day_of_month' => 'nullable|integer|min:1|max:31',
            'adjustments.*.description' => 'nullable|string',
            'adjustments.*.target_recurring_template_id' => ['nullable', Rule::exists('recurring_transaction_templates', 'id')->where('budget_id', $budget->id)],
        ]);

        // Validate that all accounts belong to this budget (if adjustments provided)
        if (isset($validated['adjustments'])) {
            $accountIds = collect($validated['adjustments'])->pluck('account_id')->unique();
            $validAccountIds = $budget->accounts()->whereIn('id', $accountIds)->pluck('id');

            if ($accountIds->count() !== $validAccountIds->count()) {
                return response()->json([
                    'message' => 'One or more accounts do not belong to this budget.',
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            // Update scenario
            $scenario->update([
                'name' => $validated['name'] ?? $scenario->name,
                // An explicitly sent null/empty description clears it; omitting the key keeps it.
                'description' => array_key_exists('description', $validated) ? $validated['description'] : $scenario->description,
                'color' => $validated['color'] ?? $scenario->color,
                'is_active' => $validated['is_active'] ?? $scenario->is_active,
            ]);

            // Update adjustments if provided
            if (isset($validated['adjustments'])) {
                // Delete existing adjustments
                $scenario->adjustments()->delete();

                // Create new adjustments
                foreach ($validated['adjustments'] as $adjustmentData) {
                    unset($adjustmentData['id']); // Remove id if present
                    $scenario->adjustments()->create($adjustmentData);
                }
            }

            DB::commit();

            // Load relationships for response
            $scenario->load('adjustments.account');

            return response()->json([
                'message' => 'Scenario updated successfully.',
                'scenario' => $scenario,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to update scenario', ['budget_id' => $budget->id, 'exception' => $e]);

            return response()->json([
                'message' => 'Failed to update scenario.',
            ], 500);
        }
    }

    /**
     * Remove the specified scenario.
     *
     * @return JsonResponse
     */
    public function destroy(Budget $budget, Scenario $scenario)
    {
        // Ensure user has access to this budget
        $this->authorize('update', $budget);

        try {
            $scenario->delete();

            return response()->json([
                'message' => 'Scenario deleted successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete scenario', ['budget_id' => $budget->id, 'exception' => $e]);

            return response()->json([
                'message' => 'Failed to delete scenario.',
            ], 500);
        }
    }

    /**
     * Toggle the active state of a scenario.
     *
     * @return JsonResponse
     */
    public function toggle(Budget $budget, Scenario $scenario)
    {
        // Ensure user has access to this budget
        $this->authorize('update', $budget);

        try {
            $isActive = $scenario->toggle();

            return response()->json([
                'message' => 'Scenario '.($isActive ? 'activated' : 'deactivated').' successfully.',
                'is_active' => $isActive,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to toggle scenario', ['budget_id' => $budget->id, 'exception' => $e]);

            return response()->json([
                'message' => 'Failed to toggle scenario.',
            ], 500);
        }
    }
}
