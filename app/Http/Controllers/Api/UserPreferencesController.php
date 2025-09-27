<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
}
