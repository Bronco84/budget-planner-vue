<?php

use App\Models\Account;
use App\Models\Budget;
use App\Services\BudgetService;
use Carbon\Carbon;

/*
 * Tests for BudgetService money math: net-worth aggregation across asset and
 * liability accounts, and the date-range parser used by the reports/filters.
 */

afterEach(function () {
    Carbon::setTestNow();
});

test('calculateTotalBalance adds assets, subtracts liabilities, ignores excluded accounts', function () {
    $budget = Budget::factory()->create();

    Account::factory()->checking()->create([
        'budget_id' => $budget->id,
        'current_balance_cents' => 50000,
    ]);
    Account::factory()->savings()->create([
        'budget_id' => $budget->id,
        'current_balance_cents' => 30000,
    ]);
    Account::factory()->creditCard()->create([
        'budget_id' => $budget->id,
        'current_balance_cents' => 20000,
    ]);
    // Excluded from net worth entirely.
    Account::factory()->checking()->create([
        'budget_id' => $budget->id,
        'current_balance_cents' => 999999,
        'exclude_from_total_balance' => true,
    ]);

    $total = app(BudgetService::class)->calculateTotalBalance($budget->fresh());

    // 50000 + 30000 (assets) - 20000 (credit card liability) = 60000.
    expect($total)->toBe(60000);
});

test('calculateTotalBalance subtracts liabilities stored as negative balances', function () {
    $budget = Budget::factory()->create();

    Account::factory()->checking()->create([
        'budget_id' => $budget->id,
        'current_balance_cents' => 100000,
    ]);
    Account::factory()->creditCard()->create([
        'budget_id' => $budget->id,
        'current_balance_cents' => -25000,
    ]);

    $total = app(BudgetService::class)->calculateTotalBalance($budget->fresh());

    // Liabilities use -abs(), so a -25000 credit card balance still reduces net worth.
    expect($total)->toBe(75000);
});

test('parseDateRange returns the expected relative start dates', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $service = app(BudgetService::class);

    expect($service->parseDateRange('7')->toDateString())->toBe('2026-06-08')
        ->and($service->parseDateRange('30')->toDateString())->toBe('2026-05-16')
        ->and($service->parseDateRange('90')->toDateString())->toBe('2026-03-17')
        ->and($service->parseDateRange('custom', '2026-01-01')->toDateString())->toBe('2026-01-01')
        ->and($service->parseDateRange('custom')->toDateString())->toBe('2026-03-17')
        ->and($service->parseDateRange('anything-else')->toDateString())->toBe('2026-01-01');
});
