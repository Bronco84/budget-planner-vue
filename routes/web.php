<?php

use App\Http\Controllers\AccountController;
use App\Models\Budget;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\RecurringTransactionController;
use App\Http\Controllers\RecurringTransactionRuleController;
use App\Http\Controllers\PlaidController;
use App\Http\Controllers\PlaidStatementHistoryController;
use App\Http\Controllers\PlaidTransactionController;
use App\Http\Controllers\ProjectionsController;
use App\Http\Controllers\RecurringTransactionAnalysisController;
use App\Http\Controllers\PayoffPlanController;
use App\Http\Controllers\ReportsController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    // Redirect to the budget index, which will redirect to the active budget
    return redirect()->route('budgets.index');
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Calendar routes
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');

    // Transactions redirect route - redirects to active budget's transactions
    Route::get('/transactions', function () {
        $activeBudget = auth()->user()->getActiveBudget();
        if (!$activeBudget) {
            return redirect()->route('budgets.create');
        }
        return redirect()->route('budget.transaction.index', $activeBudget->id);
    })->name('transactions.index');

    // Recurring transactions redirect route - redirects to active budget's recurring transactions
    Route::get('/recurring-transactions', function () {
        $activeBudget = auth()->user()->getActiveBudget();
        if (!$activeBudget) {
            return redirect()->route('budgets.create');
        }
        return redirect()->route('recurring-transactions.index', $activeBudget->id);
    })->name('recurring-transactions.redirect');

    Route::resource('budgets', BudgetController::class);

    // Budget setup routes
    Route::get('budgets/{budget}/setup', [BudgetController::class, 'setup'])
        ->name('budgets.setup');
    Route::get('budgets/{budget}/setup/connect', [PlaidController::class, 'discover'])
        ->name('budgets.setup.connect');
    Route::post('budgets/{budget}/setup/connect', [PlaidController::class, 'import'])
        ->name('budgets.setup.import');
    Route::get('budgets/{budget}/setup/manual', [AccountController::class, 'create'])
        ->name('budgets.setup.manual');

    Route::get('budget/{budget}/statistics/yearly', [BudgetController::class, 'yearlyStatistics'])
        ->name('budget.statistics.yearly');
    Route::get('budget/{budget}/statistics/monthly/{month?}/{year?}', [BudgetController::class, 'monthlyStatistics'])
        ->name('budget.statistics.monthly');
    Route::get('budget/{budget}/projections', [BudgetController::class, 'projections'])
        ->name('budget.projections');
    Route::get('budget/{budget}/account/{account}/projections', [ProjectionsController::class, 'showAccountProjections'])
        ->name('budget.account.projections');
    Route::get('budget/{budget}/account/{account}/balance-projection', [ProjectionsController::class, 'showBalanceProjection'])
        ->name('budget.account.balance-projection');

    // Reports route
    Route::get('budget/{budget}/reports', [ReportsController::class, 'index'])
        ->name('reports.index');

    Route::resource('budgets.accounts', AccountController::class);
    Route::post('budgets/{budget}/accounts/{account}/autopay', [AccountController::class, 'updateAutopay'])
        ->name('accounts.updateAutopay');

    // Property routes
    Route::resource('budgets.properties', App\Http\Controllers\PropertyController::class);

    Route::resource('budgets.categories', CategoryController::class);
    Route::post('budgets/{budget}/categories/reorder', [CategoryController::class, 'reorder'])
        ->name('budgets.categories.reorder');

    Route::resource('budgets.categories.expenses', ExpenseController::class);
    
    Route::get('budget/{budget}/transactions', [TransactionController::class, 'index'])
        ->name('budget.transaction.index');
    Route::get('budget/{budget}/transactions/create', [TransactionController::class, 'create'])
        ->name('budget.transaction.create');
    Route::post('budget/{budget}/transactions', [TransactionController::class, 'store'])
        ->name('budget.transaction.store');
    Route::get('budget/{budget}/transactions/{transaction}/edit', [TransactionController::class, 'edit'])
        ->name('budget.transaction.edit');
    Route::patch('budget/{budget}/transactions/{transaction}', [TransactionController::class, 'update'])
        ->name('budget.transaction.update');
    Route::delete('budget/{budget}/transactions/{transaction}', [TransactionController::class, 'destroy'])
        ->name('budget.transaction.destroy');
    Route::get('budget/{budget}/transactions/{transaction}/activity-log', [TransactionController::class, 'getActivityLog'])
        ->name('budget.transaction.activity-log');
        
    // Routes for recurring transactions
    Route::get('budget/{budget}/recurring-transactions', [RecurringTransactionController::class, 'index'])
        ->name('recurring-transactions.index');
    Route::get('budget/{budget}/recurring-transactions/create', [RecurringTransactionController::class, 'create'])
        ->name('recurring-transactions.create');
    Route::post('budget/{budget}/recurring-transactions', [RecurringTransactionController::class, 'store'])
        ->name('recurring-transactions.store');
    Route::get('budget/{budget}/recurring-transactions/{recurring_transaction}', [RecurringTransactionController::class, 'edit'])
        ->name('recurring-transactions.edit');
    Route::patch('budget/{budget}/recurring-transactions/{recurring_transaction}', [RecurringTransactionController::class, 'update'])
        ->name('recurring-transactions.update');
    Route::delete('budget/{budget}/recurring-transactions/{recurring_transaction}', [RecurringTransactionController::class, 'destroy'])
        ->name('recurring-transactions.destroy');
    Route::post('budget/{budget}/recurring-transactions/{recurring_transaction}/duplicate', [RecurringTransactionController::class, 'duplicate'])
        ->name('recurring-transactions.duplicate');
    Route::get('budget/{budget}/recurring-transactions/{recurring_transaction}/diagnostics', [RecurringTransactionController::class, 'diagnostics'])
        ->name('recurring-transactions.diagnostics');
    
    // Routes for recurring transaction rules
    Route::get('budget/{budget}/recurring-transactions/{recurring_transaction}/rules', [RecurringTransactionRuleController::class, 'index'])
        ->name('recurring-transactions.rules.index');
    Route::post('budget/{budget}/recurring-transactions/{recurring_transaction}/rules', [RecurringTransactionRuleController::class, 'store'])
        ->name('recurring-transactions.rules.store');
    Route::patch('budget/{budget}/recurring-transactions/{recurring_transaction}/rules/{rule}', [RecurringTransactionRuleController::class, 'update'])
        ->name('recurring-transactions.rules.update');
    Route::delete('budget/{budget}/recurring-transactions/{recurring_transaction}/rules/{rule}', [RecurringTransactionRuleController::class, 'destroy'])
        ->name('recurring-transactions.rules.destroy');
    Route::post('budget/{budget}/recurring-transactions/{recurring_transaction}/rules/test', [RecurringTransactionRuleController::class, 'test'])
        ->name('recurring-transactions.rules.test');
    Route::get('budget/{budget}/recurring-transactions/{recurring_transaction}/rules/preview', [RecurringTransactionRuleController::class, 'preview'])
        ->name('recurring-transactions.rules.preview');
    Route::post('budget/{budget}/recurring-transactions/{recurring_transaction}/rules/apply', [RecurringTransactionRuleController::class, 'apply'])
        ->name('recurring-transactions.rules.apply');
    Route::post('budget/{budget}/recurring-transactions/{recurring_transaction}/rules/unlink', [RecurringTransactionRuleController::class, 'unlink'])
        ->name('recurring-transactions.rules.unlink');
    Route::get('budget/{budget}/recurring-transactions/{recurring_transaction}/linked', [RecurringTransactionRuleController::class, 'linked'])
        ->name('recurring-transactions.rules.linked');

    // Routes for recurring transaction analysis
    Route::get('budget/{budget}/recurring-transactions-analysis', [RecurringTransactionAnalysisController::class, 'show'])
        ->name('recurring-transactions.analysis');
    Route::post('budget/{budget}/recurring-transactions-analysis/analyze', [RecurringTransactionAnalysisController::class, 'analyze'])
        ->name('recurring-transactions.analysis.analyze');
    // Fallback GET route for analyze - redirect to main analysis page
    Route::get('budget/{budget}/recurring-transactions-analysis/analyze', function (Budget $budget) {
        return redirect()->route('recurring-transactions.analysis', $budget->id);
    });
    Route::post('budget/{budget}/recurring-transactions-analysis/create-templates', [RecurringTransactionAnalysisController::class, 'createTemplates'])
        ->name('recurring-transactions.analysis.create-templates');
    // Fallback GET route for create-templates - redirect to main analysis page
    Route::get('budget/{budget}/recurring-transactions-analysis/create-templates', function (Budget $budget) {
        return redirect()->route('recurring-transactions.analysis', $budget->id);
    });

    // Routes for payoff plans
    Route::get('budget/{budget}/payoff-plans', [PayoffPlanController::class, 'index'])
        ->name('payoff-plans.index');
    Route::get('budget/{budget}/payoff-plans/create', [PayoffPlanController::class, 'create'])
        ->name('payoff-plans.create');
    Route::post('budget/{budget}/payoff-plans', [PayoffPlanController::class, 'store'])
        ->name('payoff-plans.store');
    Route::get('budget/{budget}/payoff-plans/{payoff_plan}', [PayoffPlanController::class, 'show'])
        ->name('payoff-plans.show');
    Route::get('budget/{budget}/payoff-plans/{payoff_plan}/edit', [PayoffPlanController::class, 'edit'])
        ->name('payoff-plans.edit');
    Route::patch('budget/{budget}/payoff-plans/{payoff_plan}', [PayoffPlanController::class, 'update'])
        ->name('payoff-plans.update');
    Route::delete('budget/{budget}/payoff-plans/{payoff_plan}', [PayoffPlanController::class, 'destroy'])
        ->name('payoff-plans.destroy');
    Route::post('budget/{budget}/payoff-plans/preview', [PayoffPlanController::class, 'preview'])
        ->name('payoff-plans.preview');

    // Plaid integration routes
    Route::get('budget/{budget}/plaid/discover', [PlaidController::class, 'discover'])
        ->name('plaid.discover');
    Route::post('budget/{budget}/plaid/import', [PlaidController::class, 'import'])
        ->name('plaid.import');
    Route::get('budget/{budget}/account/{account}/plaid/link', [PlaidController::class, 'showLinkForm'])
        ->name('plaid.link');
    Route::post('budget/{budget}/account/{account}/plaid/store', [PlaidController::class, 'store'])
        ->name('plaid.store');
    Route::post('budget/{budget}/account/{account}/plaid/sync', [PlaidController::class, 'syncTransactions'])
        ->name('plaid.sync');
    Route::post('budget/{budget}/account/{account}/plaid/balance', [PlaidController::class, 'updateBalance'])
        ->name('plaid.balance');
    Route::post('budget/{budget}/account/{account}/plaid/liabilities', [PlaidController::class, 'updateLiabilities'])
        ->name('plaid.liabilities');
    Route::get('budget/{budget}/account/{account}/plaid/statement-history', [PlaidStatementHistoryController::class, 'index'])
        ->name('plaid.statement-history');
    Route::delete('budget/{budget}/account/{account}/plaid', [PlaidController::class, 'destroy'])
        ->name('plaid.destroy');
    
    // New route for syncing all Plaid accounts in a budget
    Route::post('budget/{budget}/plaid/sync-all', [PlaidController::class, 'syncAllTransactions'])
        ->name('plaid.sync-all');
    
    // Plaid transactions routes
    Route::get('budget/{budget}/account/{account}/plaid-transactions', [PlaidTransactionController::class, 'index'])
        ->name('plaid-transactions.index');
    Route::get('budget/{budget}/account/{account}/plaid-transactions/api', [PlaidTransactionController::class, 'getTransactions'])
        ->name('plaid-transactions.api');
    Route::get('budget/{budget}/account/{account}/plaid-transactions/{plaidTransactionId}', [PlaidTransactionController::class, 'show'])
        ->name('plaid-transactions.show');
    
    // File attachment routes
    Route::post('transactions/{transaction}/files', [FileController::class, 'uploadToTransaction'])
        ->name('transactions.files.upload');
    Route::get('transactions/{transaction}/files', [FileController::class, 'getTransactionAttachments'])
        ->name('transactions.files.index');
    Route::post('budgets/{budget}/files', [FileController::class, 'uploadToBudget'])
        ->name('budgets.files.upload');
    Route::get('budgets/{budget}/files', [FileController::class, 'getBudgetAttachments'])
        ->name('budgets.files.index');
    Route::get('files/{attachment}/download', [FileController::class, 'download'])
        ->name('files.download');
    Route::delete('files/{attachment}', [FileController::class, 'delete'])
        ->name('files.delete');

    // User preferences routes (JSON API but accessible from frontend)
    Route::get('/api/preferences/account-type-order', [App\Http\Controllers\Api\UserPreferencesController::class, 'getAccountTypeOrder'])
        ->name('preferences.account-type-order.get');
    Route::post('/api/preferences/account-type-order', [App\Http\Controllers\Api\UserPreferencesController::class, 'updateAccountTypeOrder'])
        ->name('preferences.account-type-order.update');
    Route::get('/api/preferences/active-budget', [App\Http\Controllers\Api\UserPreferencesController::class, 'getActiveBudget'])
        ->name('preferences.active-budget.get');
    Route::post('/api/preferences/active-budget', [App\Http\Controllers\Api\UserPreferencesController::class, 'setActiveBudget'])
        ->name('preferences.active-budget.set');
    Route::get('/api/preferences/{key}', [App\Http\Controllers\Api\UserPreferencesController::class, 'show'])
        ->name('preferences.show');
    Route::post('/api/preferences/{key}', [App\Http\Controllers\Api\UserPreferencesController::class, 'update'])
        ->name('preferences.update');

    // Chat routes with rate limiting
    Route::prefix('chat')->name('chat.')->middleware('throttle:60,1')->group(function () {
        Route::post('/message', [ChatController::class, 'send'])->middleware('throttle:30,1')->name('send');
        Route::get('/stream', [ChatController::class, 'stream'])->middleware('throttle:30,1')->name('stream');
        Route::post('/stream/complete', [ChatController::class, 'streamComplete'])->name('stream.complete');
        Route::get('/conversations', [ChatController::class, 'conversations'])->name('conversations');
        Route::get('/conversations/{id}', [ChatController::class, 'show'])->name('conversations.show');
        Route::delete('/conversations/{id}', [ChatController::class, 'destroy'])->name('conversations.destroy');
        Route::post('/conversations/bulk-delete', [ChatController::class, 'bulkDestroy'])->name('conversations.bulk-destroy');
    });
});

require __DIR__.'/auth.php';


// Add the debug route at the end of the file
Route::get('/debug/rules', function () {
    return [
        'rules_count' => DB::table('recurring_transaction_rules')->count(),
        'rules' => DB::table('recurring_transaction_rules')->get(),
        'templates_count' => DB::table('recurring_transaction_templates')->count(),
        'templates' => DB::table('recurring_transaction_templates')->get(),
    ];
});
require __DIR__.'/debug.php';
