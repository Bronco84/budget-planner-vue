<?php

namespace App\Http\Controllers;

use App\Models\UserPreference;
use App\Services\VirtualAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserPreferenceController extends Controller
{
    public function __construct(
        protected VirtualAccountService $virtualAccountService
    ) {}

    /**
     * Save account group ordering
     */
    public function saveGroupOrder(Request $request): JsonResponse
    {
        $request->validate([
            'group_order' => 'required|array',
            'group_order.*' => 'required|string'
        ]);

        $this->virtualAccountService->saveGroupOrdering(
            auth()->id(),
            $request->input('group_order')
        );

        return response()->json(['success' => true]);
    }

    /**
     * Toggle group collapsed state
     */
    public function toggleGroupCollapsed(Request $request): JsonResponse
    {
        $request->validate([
            'group_name' => 'required|string'
        ]);

        $isCollapsed = $this->virtualAccountService->toggleGroupCollapsed(
            auth()->id(),
            $request->input('group_name')
        );

        return response()->json([
            'success' => true,
            'collapsed' => $isCollapsed
        ]);
    }

    /**
     * Toggle account inclusion in total balance calculation
     */
    public function toggleAccountInclusion(Request $request): JsonResponse
    {
        $request->validate([
            'account_id' => 'required', // Allow both string and integer
            'included' => 'required|boolean'
        ]);

        $this->virtualAccountService->setAccountInclusion(
            auth()->id(),
            $request->input('account_id'),
            $request->boolean('included')
        );

        // Return the new total included balance
        $budget = \App\Models\Budget::where('user_id', auth()->id())->latest()->first();
        $totalIncludedBalance = $budget ? $this->virtualAccountService->getTotalIncludedBalance($budget, auth()->id()) : 0;

        return response()->json([
            'success' => true,
            'total_included_balance' => $totalIncludedBalance
        ]);
    }
}