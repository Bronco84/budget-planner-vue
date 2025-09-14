<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Services\AirtableService;
use App\Services\AirtableSyncService;
use App\Services\VirtualAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class AirtableController extends Controller
{
    public function __construct(
        protected AirtableService $airtableService,
        protected AirtableSyncService $syncService
    ) {}
    
    /**
     * Show the Airtable account linking interface
     */
    public function showLinkForm(Budget $budget, Account $account): Response
    {
        // Check if account has any Airtable-imported transactions
        $hasAirtableTransactions = $account->transactions()
            ->where('is_airtable_imported', true)
            ->exists();
        
        $availableAirtableAccounts = [];
        
        try {
            if ($this->airtableService->isConfigured()) {
                $accountMapping = $this->syncService->getAccountMapping($budget);
                $availableAirtableAccounts = $accountMapping->toArray();
            }
        } catch (\Exception $e) {
            Log::error('Failed to fetch Airtable accounts', [
                'error' => $e->getMessage()
            ]);
        }
        
        return Inertia::render('Airtable/Link', [
            'budget' => $budget,
            'account' => $account,
            'hasAirtableTransactions' => $hasAirtableTransactions,
            'availableAirtableAccounts' => $availableAirtableAccounts,
            'isConfigured' => $this->airtableService->isConfigured(),
        ]);
    }
    
    /**
     * Sync transactions from Airtable for an account
     */
    public function syncTransactions(Request $request, Budget $budget, Account $account): RedirectResponse
    {
        if (!$this->airtableService->isConfigured()) {
            return redirect()->back()->with('error', 'Airtable integration is not configured.');
        }

        $validated = $request->validate([
            'airtable_account_id' => 'required|string',
        ]);
        
        try {
            $result = $this->syncService->syncTransactionsForAccount(
                $account, 
                $validated['airtable_account_id']
            );
            
            $message = "Synced {$result['imported']} new transactions and updated {$result['updated']} existing transactions.";
            
            if (!empty($result['errors'])) {
                $message .= " Encountered " . count($result['errors']) . " errors.";
            }
            
            return redirect()->route('budgets.show', $budget)
                ->with('message', $message);
                
        } catch (\Exception $e) {
            Log::error('Airtable sync failed', [
                'error' => $e->getMessage(),
                'account_id' => $account->id
            ]);
            
            return redirect()->back()->with('error', 'Failed to sync transactions: ' . $e->getMessage());
        }
    }
    
    /**
     * Get Airtable data summary for dashboard
     */
    public function summary(Budget $budget, VirtualAccountService $virtualAccountService): Response
    {
        try {
            $summary = $this->syncService->getDataSummary();
            $virtualAccounts = $virtualAccountService->getAccountsForBudget($budget);
            
            return Inertia::render('Airtable/Summary', [
                'budget' => $budget,
                'summary' => $summary,
                'accounts' => $virtualAccounts,
                'accountCount' => $virtualAccounts->count(),
                'isConfigured' => $this->airtableService->isConfigured(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get Airtable summary', [
                'error' => $e->getMessage()
            ]);
            
            return Inertia::render('Airtable/Summary', [
                'budget' => $budget,
                'summary' => ['error' => $e->getMessage()],
                'accounts' => collect([]),
                'accountCount' => 0,
                'isConfigured' => $this->airtableService->isConfigured(),
            ]);
        }
    }
    
    /**
     * Show Airtable data analysis
     */
    public function analyze(): Response
    {
        $analysis = null;
        $error = null;
        
        try {
            if ($this->airtableService->isConfigured()) {
                $analysis = $this->airtableService->analyzeDataStructure();
            } else {
                $error = 'Airtable integration is not configured. Please check your environment variables.';
            }
        } catch (\Exception $e) {
            $error = 'Failed to analyze Airtable data: ' . $e->getMessage();
            Log::error('Airtable analysis failed', ['error' => $e->getMessage()]);
        }
        
        return Inertia::render('Airtable/Analyze', [
            'analysis' => $analysis,
            'error' => $error,
            'isConfigured' => $this->airtableService->isConfigured(),
        ]);
    }
}
