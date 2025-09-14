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
}