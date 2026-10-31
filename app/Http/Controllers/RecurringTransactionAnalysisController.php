<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use App\Services\RecurringTransactionAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RecurringTransactionAnalysisController extends Controller
{
    protected RecurringTransactionAnalysisService $analysisService;

    public function __construct(RecurringTransactionAnalysisService $analysisService)
    {
        $this->analysisService = $analysisService;

        // Authorize budget ownership (BudgetPolicy view ability) for every action.
        $this->middleware('can:view,budget');
    }

    /**
     * Show the recurring transaction analysis page.
     */
    public function show(Budget $budget): Response
    {
        $accounts = $budget->accounts()
            ->where('include_in_budget', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('RecurringTransactions/Analysis', [
            'budget' => $budget,
            'accounts' => $accounts,
        ]);
    }

    /**
     * Analyze transactions for recurring patterns.
     */
    public function analyze(Request $request, Budget $budget)
    {
        $validated = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('budget_id', $budget->id)],
            'analysis_period_months' => 'integer|min:3|max:24',
            'min_occurrences' => 'integer|min:2|max:10',
            'confidence_threshold' => 'numeric|min:0|max:1',
        ]);

        $account = Account::findOrFail($validated['account_id']);

        // Verify the account belongs to this budget
        if ($account->budget_id !== $budget->id) {
            return response()->json(['error' => 'Account does not belong to this budget'], 403);
        }

        $analysisPeriodMonths = $validated['analysis_period_months'] ?? 6;
        $minOccurrences = $validated['min_occurrences'] ?? 3;
        $confidenceThreshold = $validated['confidence_threshold'] ?? 0.6;

        try {
            $result = $this->analysisService->analyzeAccount(
                $account,
                $analysisPeriodMonths,
                $minOccurrences,
                $confidenceThreshold
            );

            $accounts = $budget->accounts()
                ->where('include_in_budget', true)
                ->orderBy('name')
                ->get();

            return Inertia::render('RecurringTransactions/Analysis', [
                'budget' => $budget,
                'accounts' => $accounts,
                'analysisResult' => [
                    'success' => $result['success'],
                    'message' => $result['message'] ?? null,
                    'patterns' => $result['patterns'],
                    'account' => $account,
                    'analysis_summary' => $result['analysis_summary'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Recurring transaction analysis failed', [
                'exception' => $e,
                'budget_id' => $budget->id,
                'account_id' => $account->id,
            ]);

            return redirect()->back()->with('error', 'Analysis failed. Please try again.');
        }
    }

    /**
     * Create recurring transaction templates from analysis results.
     */
    public function createTemplates(Request $request, Budget $budget)
    {
        // Creating templates writes to the budget; the controller middleware only checks view.
        $this->authorize('update', $budget);

        $frequencies = 'in:daily,weekly,biweekly,monthly,bimonthly,quarterly,yearly';

        $validated = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('budget_id', $budget->id)],
            'selected_patterns' => 'required|array',
            'selected_patterns.*' => 'array',
            'selected_patterns.*.description' => 'required|string|max:255',
            'selected_patterns.*.category' => 'nullable|string|max:255',
            'selected_patterns.*.frequency' => 'required|string|'.$frequencies,
            // Modal confirmations send start_date; raw analysis patterns send suggested_start_date.
            'selected_patterns.*.start_date' => 'nullable|required_without:selected_patterns.*.suggested_start_date|date',
            'selected_patterns.*.suggested_start_date' => 'nullable|date',
            'selected_patterns.*.end_date' => 'nullable|date',
            'selected_patterns.*.amount' => 'nullable|numeric',
            'selected_patterns.*.amount_in_cents' => 'nullable|integer',
            'selected_patterns.*.amount_type' => 'nullable|in:static,dynamic',
            'selected_patterns.*.min_amount' => 'nullable|numeric',
            'selected_patterns.*.max_amount' => 'nullable|numeric',
            'selected_patterns.*.day_of_week' => 'nullable|integer|min:0|max:6',
            'selected_patterns.*.day_of_month' => 'nullable|integer|min:1|max:31',
            'selected_patterns.*.first_day_of_month' => 'nullable|integer|min:1|max:31',
            'selected_patterns.*.bimonthly_first_day' => 'nullable|integer|min:1|max:31',
            'selected_patterns.*.bimonthly_second_day' => 'nullable|integer|min:1|max:31',
            'selected_patterns.*.plaid_entity_id' => 'nullable|string|max:255',
            'selected_patterns.*.plaid_entity_name' => 'nullable|string|max:255',
            'selected_patterns.*.transaction_ids' => 'nullable|array',
            'selected_patterns.*.transaction_ids.*' => 'integer',
            'selected_patterns.*.transactions' => 'nullable|array',
            'selected_patterns.*.transactions.*.id' => 'integer',
            // Raw analysis patterns (legacy path)
            'selected_patterns.*.is_dynamic_amount' => 'nullable|boolean',
            'selected_patterns.*.confidence_score' => 'nullable|numeric',
            'selected_patterns.*.amount_stats' => 'nullable|array',
            'selected_patterns.*.amount_stats.*' => 'nullable|numeric',
        ]);

        $account = Account::findOrFail($validated['account_id']);

        // Verify the account belongs to this budget
        if ($account->budget_id !== $budget->id) {
            return response()->json(['error' => 'Account does not belong to this budget'], 403);
        }

        try {
            $result = $this->analysisService->createTemplatesFromPatterns(
                $budget,
                $account,
                $validated['selected_patterns']
            );

            $response = [
                'success' => $result['success'],
                'created_templates' => $result['created_templates'],
                'total_requested' => $result['total_requested'],
                'templates' => $result['templates'],
            ];

            $redirect = redirect()->route('recurring-transactions.index', $budget->id);

            if (! empty($result['errors'])) {
                $response['errors'] = $result['errors'];
                $response['message'] = sprintf(
                    'Created %d of %d templates. %d could not be created.',
                    $result['created_templates'],
                    $result['total_requested'],
                    count($result['errors'])
                );

                return $redirect->with('error', $response['message'])
                    ->with('template_creation_result', $response);
            }

            $response['message'] = 'Successfully created '.$result['created_templates'].' recurring transaction templates.';

            return $redirect->with('message', $response['message'])
                ->with('template_creation_result', $response);

        } catch (\Exception $e) {
            Log::error('Template creation failed', [
                'exception' => $e,
                'budget_id' => $budget->id,
                'account_id' => $account->id,
            ]);

            return redirect()->back()->with('error', 'Template creation failed. Please try again.');
        }
    }
}
