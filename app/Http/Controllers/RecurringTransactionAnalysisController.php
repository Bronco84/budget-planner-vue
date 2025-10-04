<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Account;
use App\Services\RecurringTransactionAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class RecurringTransactionAnalysisController extends Controller
{
    protected RecurringTransactionAnalysisService $analysisService;

    public function __construct(RecurringTransactionAnalysisService $analysisService)
    {
        $this->analysisService = $analysisService;
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
            'account_id' => 'required|exists:accounts,id',
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
                    'analysis_summary' => $result['analysis_summary']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Recurring transaction analysis failed', [
                'error' => $e->getMessage(),
                'budget_id' => $budget->id,
                'account_id' => $account->id,
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->back()->with('error', 'Analysis failed: ' . $e->getMessage());
        }
    }

    /**
     * Create recurring transaction templates from analysis results.
     */
    public function createTemplates(Request $request, Budget $budget)
    {
        $validated = $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'selected_patterns' => 'required|array',
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

            if (!empty($result['errors'])) {
                $response['errors'] = $result['errors'];
                $response['message'] = 'Some templates could not be created. See errors for details.';
            } else {
                $response['message'] = 'Successfully created ' . $result['created_templates'] . ' recurring transaction templates.';
            }

            return redirect()->route('recurring-transactions.index', $budget->id)
                ->with('success', $response['message'])
                ->with('template_creation_result', $response);

        } catch (\Exception $e) {
            Log::error('Template creation failed', [
                'error' => $e->getMessage(),
                'budget_id' => $budget->id,
                'account_id' => $account->id,
            ]);

            return redirect()->back()->with('error', 'Template creation failed: ' . $e->getMessage());
        }
    }

}