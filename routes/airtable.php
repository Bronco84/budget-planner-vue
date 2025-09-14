<?php

use App\Http\Controllers\AirtableController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('airtable')->name('airtable.')->group(function () {
    // Analysis and summary routes
    Route::get('/analyze', [AirtableController::class, 'analyze'])->name('analyze');
    
    // Budget-specific routes
    Route::prefix('budgets/{budget}')->group(function () {
        Route::get('/summary', [AirtableController::class, 'summary'])->name('summary');
        
        // Account-specific routes
        Route::prefix('accounts/{account}')->group(function () {
            Route::get('/link', [AirtableController::class, 'showLinkForm'])->name('link');
            Route::post('/sync', [AirtableController::class, 'syncTransactions'])->name('sync');
        });
    });
});
