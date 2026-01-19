<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Transfer;
use App\Services\TransferService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class TransferController extends Controller
{
    public function __construct(
        protected TransferService $transferService
    ) {
        // Authorize all actions through budget ownership
        $this->middleware(function ($request, $next) {
            $budget = $request->route('budget');
            if ($budget) {
                $this->authorize('view', $budget);
            }
            return $next($request);
        });
    }

    /**
     * Display a listing of transfers for a budget.
     */
    public function index(Request $request, Budget $budget): Response
    {
        $transfers = $this->transferService->getTransfersForBudget($budget);

        return Inertia::render('Transfers/Index', [
            'budget' => $budget,
            'transfers' => $transfers,
            'accounts' => $budget->accounts,
        ]);
    }

    /**
     * Show the form for creating a new transfer.
     */
    public function create(Budget $budget): Response
    {
        $accounts = $budget->accounts()->get();

        return Inertia::render('Transfers/Create', [
            'budget' => $budget,
            'accounts' => $accounts,
        ]);
    }

    /**
     * Store a newly created transfer in storage.
     */
    public function store(Request $request, Budget $budget): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'from_account_id' => 'required|exists:accounts,id|different:to_account_id',
            'to_account_id' => 'required|exists:accounts,id',
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
     * Display the specified transfer.
     */
    public function show(Budget $budget, Transfer $transfer): Response
    {
        // Verify transfer belongs to budget
        if ($transfer->budget_id !== $budget->id) {
            abort(404);
        }

        $transfer->load(['fromAccount', 'toAccount', 'transactions']);

        return Inertia::render('Transfers/Show', [
            'budget' => $budget,
            'transfer' => $transfer,
        ]);
    }

    /**
     * Show the form for editing the specified transfer.
     */
    public function edit(Budget $budget, Transfer $transfer): Response
    {
        // Verify transfer belongs to budget
        if ($transfer->budget_id !== $budget->id) {
            abort(404);
        }

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
        // Verify transfer belongs to budget
        if ($transfer->budget_id !== $budget->id) {
            abort(404);
        }

        $validated = $request->validate([
            'from_account_id' => 'required|exists:accounts,id|different:to_account_id',
            'to_account_id' => 'required|exists:accounts,id',
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

        return redirect()->route('budget.transfers.index', $budget)
            ->with('message', 'Transfer updated successfully');
    }

    /**
     * Remove the specified transfer from storage.
     */
    public function destroy(Request $request, Budget $budget, Transfer $transfer): RedirectResponse|JsonResponse
    {
        // Verify transfer belongs to budget
        if ($transfer->budget_id !== $budget->id) {
            abort(404);
        }

        $this->transferService->delete($transfer);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Transfer deleted successfully',
            ]);
        }

        return redirect()->route('budget.transfers.index', $budget)
            ->with('message', 'Transfer deleted successfully');
    }
}
