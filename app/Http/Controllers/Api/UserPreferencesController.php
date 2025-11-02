<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UserPreferencesController extends Controller
{
    /**
     * Get user preferences for a specific key.
     */
    public function show(string $key): JsonResponse
    {
        $user = Auth::user();
        $preference = $user->getPreference($key);

        return response()->json([
            'key' => $key,
            'value' => $preference,
        ]);
    }

    /**
     * Update user preferences.
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $user = Auth::user();

        // Validate based on preference key
        $validationRules = $this->getValidationRulesForKey($key);
        $validated = $request->validate([
            'value' => $validationRules,
        ]);

        $user->setPreference($key, $validated['value']);

        return response()->json([
            'key' => $key,
            'value' => $validated['value'],
            'message' => 'Preference updated successfully',
        ]);
    }

    /**
     * Update account type order specifically.
     */
    public function updateAccountTypeOrder(Request $request): JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'order' => 'required|array',
            'order.*' => 'required|string|in:' . implode(',', User::ACCOUNT_TYPES),
        ]);

        $user->setAccountTypeOrder($validated['order']);

        return response()->json([
            'order' => $validated['order'],
            'message' => 'Account type order updated successfully',
        ]);
    }

    /**
     * Get account type order.
     */
    public function getAccountTypeOrder(): JsonResponse
    {
        $user = Auth::user();
        $order = $user->getAccountTypeOrder();

        return response()->json([
            'order' => $order,
        ]);
    }

    /**
     * Get validation rules for specific preference keys.
     */
    private function getValidationRulesForKey(string $key): array
    {
        return match ($key) {
            'account_type_order' => [
                'required',
                'array',
                Rule::in(User::ACCOUNT_TYPES)
            ],
            default => ['nullable'],
        };
    }

    /**
     * Get the user's active budget.
     */
    public function getActiveBudget(): JsonResponse
    {
        $user = Auth::user();
        $activeBudget = $user->getActiveBudget();

        if (!$activeBudget) {
            return response()->json([
                'active_budget' => null,
                'message' => 'No active budget set',
            ]);
        }

        return response()->json([
            'active_budget' => [
                'id' => $activeBudget->id,
                'name' => $activeBudget->name,
                'description' => $activeBudget->description,
                'starting_balance' => $activeBudget->starting_balance,
            ],
        ]);
    }

    /**
     * Set the user's active budget.
     */
    public function setActiveBudget(Request $request): JsonResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'budget_id' => [
                'required',
                'integer',
                'exists:budgets,id',
                function ($attribute, $value, $fail) use ($user) {
                    $budget = Budget::find($value);
                    if ($budget && !$user->hasBudget($budget)) {
                        $fail('You do not have access to this budget.');
                    }
                },
            ],
        ]);

        $user->setActiveBudget($validated['budget_id']);
        $activeBudget = $user->getActiveBudget();

        return response()->json([
            'active_budget' => [
                'id' => $activeBudget->id,
                'name' => $activeBudget->name,
                'description' => $activeBudget->description,
                'starting_balance' => $activeBudget->starting_balance,
            ],
            'message' => 'Active budget updated successfully',
        ]);
    }
}
