<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransactionController;
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
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    Route::resource('budgets', BudgetController::class);
    
    Route::get('budget/{budget}/statistics/yearly', [BudgetController::class, 'yearlyStatistics'])
        ->name('budget.statistics.yearly');
    Route::get('budget/{budget}/statistics/monthly/{month?}/{year?}', [BudgetController::class, 'monthlyStatistics'])
        ->name('budget.statistics.monthly');
        
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
});

require __DIR__.'/auth.php';
