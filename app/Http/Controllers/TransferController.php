<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Transfer;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TransferController extends Controller
{
    public function __construct(
        protected TransferService $transferService
    ) {
        // Authorize budget ownership (BudgetPolicy view ability) for every action.
        $this->middleware('can:view,budget');
    }

    /**
     * Transfers are listed in the budget dashboard ledger; there is no standalone list page.
     */
    public function index(Budget $budget): RedirectResponse
    {
        return redirect()->route('budgets.show', $budget);
    }

    /**
     * Transfers are created from the quick-add modal available on every page, so
     * the standalone create URL sends the owner back to the budget dashboard.
     */
    public function create(Budget $budget): RedirectResponse
    {
        return redirect()->route('budgets.show', $budget);
    }

    /**
     * Store a newly created transfer in storage.
     */
    public function store(Request $request, Budget $budget): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'from_account_id' => ['required', 'different:to_account_id', Rule::exists('accounts', 'id')->where('budget_id', $budget->id)],
            'to_account_id' => ['required', Rule::exists('accounts', 'id')->where('budget_id', $budget->id)],
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date',
            'description' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        // Convert amount to cents
        $validated['amount_in_cents'] = (int) round($validated['amount'] * 100);
        $validated['budget_id'] = $budget->id;

        $transfer = $this->transferService->create($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Transfer created successfully',
                'transfer' => $transfer,
            ]);
        }

        return redirect()->back()
            ->with('message', 'Transfer created successfully');
    }

    /**
     * There is no standalone transfer page; send the owner to the edit form.
     */
    public function show(Budget $budget, Transfer $transfer): RedirectResponse
    {
        return redirect()->route('budget.transfers.edit', [$budget, $transfer]);
    }

    /**
     * Show the form for editing the specified transfer.
     */
    public function edit(Budget $budget, Transfer $transfer): Response
    {
        $transfer->load(['fromAccount', 'toAccount']);
        $accounts = $budget->accounts()->get();

        return Inertia::render('Transfers/Edit', [
            'budget' => $budget,
            'transfer' => $transfer,
            'accounts' => $accounts,
        ]);
    }

    /**
     * Update the specified transfer in storage.
     */
    public function update(Request $request, Budget $budget, Transfer $transfer): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'from_account_id' => ['required', 'different:to_account_id', Rule::exists('accounts', 'id')->where('budget_id', $budget->id)],
            'to_account_id' => ['required', Rule::exists('accounts', 'id')->where('budget_id', $budget->id)],
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date',
            'description' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        // Convert amount to cents
        $validated['amount_in_cents'] = (int) round($validated['amount'] * 100);

        $transfer = $this->transferService->update($transfer, $validated);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Transfer updated successfully',
                'transfer' => $transfer,
            ]);
        }

        return redirect()->route('budgets.show', $budget)
            ->with('message', 'Transfer updated successfully');
    }

    /**
     * Remove the specified transfer from storage.
     */
    public function destroy(Request $request, Budget $budget, Transfer $transfer): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $budget);

        $this->transferService->delete($transfer);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Transfer deleted successfully',
            ]);
        }

        return redirect()->route('budgets.show', $budget)
            ->with('message', 'Transfer deleted successfully');
    }
}
