<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserPreferencesController extends Controller
{
    /**
     * Update user preferences.
     */
    public function update(Request $request, string $key): JsonResponse
    {
        $user = Auth::user();

        // Only whitelisted keys may be written here; everything else (e.g. the
        // active budget or projection months) has a dedicated, validated path.
        $rules = $this->writablePreferenceRules()[$key] ?? null;

        if ($rules === null) {
            throw ValidationException::withMessages([
                'key' => 'This preference cannot be updated.',
            ]);
        }

        $validated = $request->validate($rules);

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
            'order.*' => ['required', 'string', Rule::in($this->allowedAccountTypes($user))],
        ]);

        $user->setAccountTypeOrder($validated['order']);

        return response()->json([
            'order' => $validated['order'],
            'message' => 'Account type order updated successfully',
        ]);
    }

    /**
     * Preferences writable through the generic endpoint, with the validation
     * rules for their value. No frontend code currently uses this endpoint.
     *
     * @return array<string, array<string, array<int, mixed>>>
     */
    private function writablePreferenceRules(): array
    {
        return [
            'account_type_order' => [
                'value' => ['required', 'array'],
                'value.*' => ['required', 'string', 'distinct', Rule::in($this->allowedAccountTypes(Auth::user()))],
            ],
        ];
    }

    /**
     * Account types that may appear in the saved order: the defaults plus any
     * (free-text) type used by an account in a budget the user can access.
     *
     * @return array<int, string>
     */
    private function allowedAccountTypes(User $user): array
    {
        $budgetIds = $user->accessibleBudgets()->pluck('id');

        $usedTypes = Account::whereIn('budget_id', $budgetIds)
            ->whereNotNull('type')
            ->distinct()
            ->pluck('type')
            ->all();

        return array_values(array_unique(array_merge(User::ACCOUNT_TYPES, $usedTypes)));
    }

    /**
     * Get the user's active budget.
     */
    public function getActiveBudget(): JsonResponse
    {
        $user = Auth::user();
        $activeBudget = $user->getActiveBudget();

        if (! $activeBudget) {
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
                    if ($budget && ! $user->hasBudget($budget)) {
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
