<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\RecurringTransactionController;
use App\Http\Controllers\PlaidController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    Route::resource('budgets', BudgetController::class);
    
    Route::get('budget/{budget}/statistics/yearly', [BudgetController::class, 'yearlyStatistics'])
        ->name('budget.statistics.yearly');
    Route::get('budget/{budget}/statistics/monthly/{month?}/{year?}', [BudgetController::class, 'monthlyStatistics'])
        ->name('budget.statistics.monthly');
    Route::get('budget/{budget}/projections', [BudgetController::class, 'projections'])
        ->name('budget.projections');
        
    Route::resource('budgets.accounts', AccountController::class);
    
    Route::resource('budgets.categories', CategoryController::class);
    
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
        
    // Plaid integration routes
    Route::get('budget/{budget}/account/{account}/plaid/link', [PlaidController::class, 'showLinkForm'])
        ->name('plaid.link');
    Route::post('budget/{budget}/account/{account}/plaid/store', [PlaidController::class, 'store'])
        ->name('plaid.store');
    Route::post('budget/{budget}/account/{account}/plaid/sync', [PlaidController::class, 'syncTransactions'])
        ->name('plaid.sync');
    Route::post('budget/{budget}/account/{account}/plaid/balance', [PlaidController::class, 'updateBalance'])
        ->name('plaid.balance');
    Route::delete('budget/{budget}/account/{account}/plaid', [PlaidController::class, 'destroy'])
        ->name('plaid.destroy');
    
    // New route for syncing all Plaid accounts in a budget
    Route::post('budget/{budget}/plaid/sync-all', [PlaidController::class, 'syncAllTransactions'])
        ->name('plaid.sync-all');
});

require __DIR__.'/auth.php';
