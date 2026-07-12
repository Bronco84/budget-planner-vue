<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Services\RecurringTransactionAnalysisService;
use Carbon\Carbon;

/*
 * Characterization tests for recurring-pattern detection. The analyzer groups
 * unlinked transactions by description, infers a frequency from the spacing
 * between them, and only surfaces groups that meet the minimum-occurrence and
 * confidence thresholds.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function subscriptionAccount(): Account
{
    $budget = Budget::factory()->create();

    return Account::factory()->checking()->create(['budget_id' => $budget->id]);
}

function seedMonthlyCharges(Account $account, array $dates, int $amountCents = -1599): void
{
    foreach ($dates as $date) {
        Transaction::factory()->create([
            'budget_id' => $account->budget_id,
            'account_id' => $account->id,
            'description' => 'Netflix',
            'category' => 'Subscriptions',
            'amount_in_cents' => $amountCents,
            'date' => $date,
        ]);
    }
}

test('detects a monthly recurring charge', function () {
    $account = subscriptionAccount();
    seedMonthlyCharges($account, [
        '2026-01-15',
        '2026-02-15',
        '2026-03-15',
        '2026-04-15',
        '2026-05-15',
    ]);

    $result = app(RecurringTransactionAnalysisService::class)
        ->analyzeAccount($account, 6, 3, 0.6);

    expect($result['success'])->toBeTrue()
        ->and($result['patterns'])->not->toBeEmpty();

    $pattern = $result['patterns'][0];
    expect($pattern['frequency'])->toBe('monthly')
        ->and($pattern['occurrences'])->toBe(5)
        ->and($pattern['day_of_month'])->toBe(15)
        ->and($pattern['amount_in_cents'])->toBe(-1599)
        ->and($pattern['confidence_score'])->toBeGreaterThanOrEqual(0.6);
});

test('ignores groups below the minimum occurrence threshold', function () {
    $account = subscriptionAccount();
    // Only two occurrences, but the threshold is three.
    seedMonthlyCharges($account, ['2026-04-15', '2026-05-15']);

    $result = app(RecurringTransactionAnalysisService::class)
        ->analyzeAccount($account, 6, 3, 0.6);

    expect($result['success'])->toBeTrue()
        ->and($result['patterns'])->toBeEmpty();
});

test('reports no patterns when the account has no transactions', function () {
    $account = subscriptionAccount();

    $result = app(RecurringTransactionAnalysisService::class)
        ->analyzeAccount($account, 6, 3, 0.6);

    expect($result['success'])->toBeFalse()
        ->and($result['patterns'])->toBeEmpty()
        ->and($result['analysis_summary']['total_transactions'])->toBe(0);
});
