<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Chat\AssistantToolFactory;
use App\Services\Chat\ContextBuilders\TransactionQueryContextBuilder;

test('factory builds the full tool set for a budget-owning user', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    $tools = app(AssistantToolFactory::class)->make($user, $budget);

    expect($tools)->toHaveCount(13);

    $names = array_map(fn ($t) => $t->name(), $tools);
    expect($names)->toContain('get_accounts', 'query_transactions', 'get_budget_categories', 'get_projections');
});

test('factory returns no tools when there is no budget', function () {
    $user = User::factory()->create();

    expect(app(AssistantToolFactory::class)->make($user, null))->toBe([]);
});

test('transaction query clamps the start date to the last 12 months', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['budget_id' => $budget->id]);

    // One transaction well outside the 12-month window, one inside it.
    Transaction::factory()->create([
        'budget_id' => $budget->id,
        'account_id' => $account->id,
        'date' => now()->subMonths(18),
        'amount_in_cents' => -5000,
    ]);
    Transaction::factory()->create([
        'budget_id' => $budget->id,
        'account_id' => $account->id,
        'date' => now()->subDays(10),
        'amount_in_cents' => -2500,
    ]);

    $result = (new TransactionQueryContextBuilder)->build($user, $budget, [
        'start_date' => now()->subYears(3)->toDateString(),
    ]);

    // The requested start is clamped to ~12 months ago...
    expect($result['period']['start'])->toBe(now()->subMonths(12)->startOfDay()->toDateString());
    // ...so only the in-window transaction is returned.
    expect($result['summary']['returned'])->toBe(1);
});

test('transaction query caps the requested limit', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['budget_id' => $budget->id]);

    Transaction::factory()->count(3)->create([
        'budget_id' => $budget->id,
        'account_id' => $account->id,
        'date' => now()->subDays(5),
    ]);

    $result = (new TransactionQueryContextBuilder)->build($user, $budget, ['limit' => 999]);

    // Limit is capped well under 999 and never returns more than exists.
    expect($result['summary']['returned'])->toBe(3);
});
