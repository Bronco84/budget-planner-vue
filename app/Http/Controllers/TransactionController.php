<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    /**
     * Create the controller instance.
     */
    public function __construct()
    {
        // Authorize budget ownership (BudgetPolicy view ability) for every action.
        $this->middleware('can:view,budget');
    }

    /**
     * Display a listing of the transactions for a budget.
     */
    public function index(Request $request, Budget $budget): Response
    {
        // file_attachments_count lets the list show a receipts indicator
        // without loading the attachments themselves.
        $query = $budget->transactions()
            ->with(['account', 'recurringTemplate'])
            ->withCount('fileAttachments');

        // Apply search filter
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        // Apply account filter
        if ($request->filled('account_id')) {
            $query->where('account_id', $request->input('account_id'));
        }

        // Apply category filter
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        // Apply timeframe filter
        if ($request->filled('timeframe')) {
            $timeframe = $request->input('timeframe');

            switch ($timeframe) {
                case 'this_month':
                    $query->whereMonth('date', now()->month)
                        ->whereYear('date', now()->year);
                    break;

                case 'last_month':
                    $lastMonth = now()->subMonthNoOverflow();
                    $query->whereMonth('date', $lastMonth->month)
                        ->whereYear('date', $lastMonth->year);
                    break;

                case 'last_3_months':
                    $query->whereDate('date', '>=', now()->subMonthsNoOverflow(3)->startOfMonth());
                    break;

                case 'this_year':
                    $query->whereYear('date', now()->year);
                    break;
            }
        }

        $transactions = $query->orderByDesc('date')->paginate(20);

        $accounts = $budget->accounts()->get();
        $categories = $budget->transactions()
            ->select('category')
            ->distinct()
            ->whereNotNull('category')
            ->pluck('category');

        return Inertia::render('Transactions/Index', [
            'budget' => $budget,
            'transactions' => $transactions,
            'accounts' => $accounts,
            'categories' => $categories,
            'filters' => [
                'search' => $request->input('search', ''),
                'account_id' => $request->filled('account_id') ? (int) $request->input('account_id') : '',
                'category' => $request->input('category', ''),
                'timeframe' => $request->input('timeframe', ''),
            ],
        ]);
    }

    /**
     * Show the form for creating a new transaction.
     */
    public function create(Request $request, Budget $budget): Response
    {
        $accounts = $budget->accounts()->get();
        $recurringTemplates = $budget->recurringTransactionTemplates()
            ->with('account')
            ->get();

        // Get pre-fill data from query parameters. A category name (e.g. from a
        // recurring projection) is mapped onto the budget's matching category.
        $prefillCategory = $request->filled('category_id')
            ? $budget->categories()->whereKey((int) $request->query('category_id'))->first()
            : Category::findForBudgetByName($budget->id, $request->query('category'));

        $prefillData = [
            'description' => $request->query('description', ''),
            'account_id' => $request->query('account_id', ''),
            'category' => $prefillCategory?->name ?? $request->query('category', ''),
            'category_id' => $prefillCategory?->id ?? '',
            'date' => $request->query('date', ''),
            'amount' => $request->query('amount', ''),
            'recurring_transaction_template_id' => $request->query('recurring_transaction_template_id', ''),
        ];

        return Inertia::render('Transactions/Create', [
            'budget' => $budget,
            'accounts' => $accounts,
            'categories' => Category::optionsForBudget($budget->id),
            'recurringTemplates' => $recurringTemplates,
            'prefillData' => $prefillData,
        ]);
    }

    /**
     * Store a newly created transaction in storage.
     */
    public function store(Request $request, Budget $budget): RedirectResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('budget_id', $budget->id)],
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric',
            'date' => 'required|date',
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('budget_id', $budget->id)],
            'category' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'recurring_transaction_template_id' => ['nullable', Rule::exists('recurring_transaction_templates', 'id')->where('budget_id', $budget->id)],
        ]);

        // Convert amount to cents and handle negative values for expenses
        $amountInCents = (int) round($validated['amount'] * 100);

        /** @var Transaction $transaction */
        $transaction = $budget->transactions()->create([
            'account_id' => $validated['account_id'],
            'description' => $validated['description'],
            'amount_in_cents' => $amountInCents,
            'date' => $validated['date'],
            ...Category::resolveAssignment($budget->id, $validated['category_id'] ?? null, $validated['category'] ?? null),
            'notes' => $validated['notes'] ?? null,
            'recurring_transaction_template_id' => $validated['recurring_transaction_template_id'] ?? null,
            'link_source' => empty($validated['recurring_transaction_template_id']) ? null : Transaction::LINK_SOURCE_MANUAL,
        ]);

        return redirect()->route('budget.transaction.edit', [$budget, $transaction])
            ->with('message', 'Transaction created successfully! You can now add file attachments.');
    }

    /**
     * Show the form for editing the specified transaction.
     */
    public function edit(Budget $budget, Transaction $transaction): Response
    {
        $this->authorize('update', $transaction);

        $accounts = $budget->accounts()->get();
        $recurringTemplates = $budget->recurringTransactionTemplates()
            ->with('account')
            ->get();

        // Check if this is a recurring transaction
        $recurringTemplate = null;
        $rules = [];
        if ($transaction->recurring_transaction_template_id) {
            $recurringTemplate = $transaction->recurringTemplate;
            if ($recurringTemplate) {
                $rules = $recurringTemplate->rules()->get();
            }
        }

        return Inertia::render('Transactions/Edit', [
            'budget' => $budget,
            'transaction' => $transaction,
            'accounts' => $accounts,
            'categories' => Category::optionsForBudget($budget->id),
            'recurringTemplates' => $recurringTemplates,
            'recurringTemplate' => $recurringTemplate,
            'rules' => $rules,
        ]);
    }

    /**
     * Update the specified transaction in storage.
     */
    public function update(Request $request, Budget $budget, Transaction $transaction): RedirectResponse
    {
        $this->authorize('update', $transaction);

        if ($transaction->transfer_id) {
            return $this->redirectToTransfer($budget, $transaction);
        }

        $validated = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('budget_id', $budget->id)],
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric',
            'date' => 'required|date',
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('budget_id', $budget->id)],
            'category' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'recurring_transaction_template_id' => ['nullable', Rule::exists('recurring_transaction_templates', 'id')->where('budget_id', $budget->id)],
        ]);

        // Choosing a (different) template here is a manual link; an unchanged
        // link keeps the source it already had.
        $templateId = $validated['recurring_transaction_template_id'] ?? null;
        $linkSource = match (true) {
            empty($templateId) => null,
            (int) $templateId === $transaction->recurring_transaction_template_id => $transaction->link_source,
            default => Transaction::LINK_SOURCE_MANUAL,
        };

        // Update transaction
        $transaction->update([
            'account_id' => $validated['account_id'],
            'description' => $validated['description'],
            'amount_in_cents' => (int) round($validated['amount'] * 100),
            'date' => $validated['date'],
            ...Category::resolveAssignment($budget->id, $validated['category_id'] ?? null, $validated['category'] ?? null),
            'notes' => $validated['notes'] ?? null,
            'recurring_transaction_template_id' => $templateId,
            'link_source' => $linkSource,
        ]);

        return redirect()->route('budget.transaction.index', $budget)
            ->with('message', 'Transaction updated successfully');
    }

    /**
     * Remove the specified transaction from storage.
     */
    public function destroy(Budget $budget, Transaction $transaction): RedirectResponse
    {
        $this->authorize('delete', $transaction);

        if ($transaction->transfer_id) {
            return $this->redirectToTransfer($budget, $transaction);
        }

        $transaction->delete();

        return redirect()->route('budget.transaction.index', $budget)
            ->with('message', 'Transaction deleted successfully');
    }

    /**
     * Transfer legs are kept in sync by TransferService, so they can only be
     * changed through their transfer.
     */
    private function redirectToTransfer(Budget $budget, Transaction $transaction): RedirectResponse
    {
        return redirect()->route('budget.transfers.edit', [$budget, $transaction->transfer_id])
            ->with('error', 'This transaction is part of a transfer. Edit or delete the transfer instead.');
    }

    /**
     * Get activity log for a transaction.
     */
    public function getActivityLog(Budget $budget, Transaction $transaction)
    {
        $this->authorize('view', $transaction);

        $activityLog = $transaction->getActivityLogFormatted();

        return response()->json([
            'activities' => $activityLog,
        ]);
    }
}
