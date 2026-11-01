<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\Chat\ContextBuilders\ProjectionsContextBuilder;
use App\Services\RecurringTransactionService;
use Illuminate\Support\Facades\Log;

/*
 * A context builder that skips a failing piece must log it and tell the
 * assistant the result is partial, not hand over an incomplete sum as whole.
 */

test('a failing account is logged and the cash-flow total is marked partial', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $broken = Account::factory()->create(['budget_id' => $budget->id, 'name' => 'Broken Checking']);
    Account::factory()->create(['budget_id' => $budget->id, 'name' => 'Savings']);

    $this->mock(BudgetService::class, function ($mock) use ($broken) {
        $mock->shouldReceive('calculateMonthlyProjectedCashFlow')
            ->andReturnUsing(fn (Account $account) => $account->is($broken)
                ? throw new RuntimeException('boom')
                : 50000);
    });
    Log::spy();

    $context = app(ProjectionsContextBuilder::class)->build($user, $budget);

    expect($context['monthly_cash_flow'])
        ->total_monthly_cash_flow->toEqual(500)
        ->is_complete->toBeFalse()
        ->excluded_accounts->toBe(['Broken Checking'])
        ->note->toContain('Partial');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => str_contains($message, 'monthly cash flow failed')
            && $context['account_id'] === $broken->id
            && $context['budget_id'] === $budget->id)
        ->once();
});

test('a complete cash-flow total is marked complete', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    Account::factory()->create(['budget_id' => $budget->id]);

    $this->mock(BudgetService::class, fn ($mock) => $mock->shouldReceive('calculateMonthlyProjectedCashFlow')->andReturn(-1000));

    $context = app(ProjectionsContextBuilder::class)->build($user, $budget);

    expect($context['monthly_cash_flow'])
        ->is_complete->toBeTrue()
        ->not->toHaveKey('excluded_accounts');
});

test('failed autopay projections are logged and reported as unavailable, not empty', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    $this->partialMock(RecurringTransactionService::class, function ($mock) {
        $mock->shouldReceive('generateAutopayProjections')->andThrow(new RuntimeException('boom'));
    });
    Log::spy();

    $context = app(ProjectionsContextBuilder::class)->build($user, $budget);

    expect($context['autopay_projections'])->toBe([])
        ->and($context)->toHaveKey('autopay_projections_unavailable');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => str_contains($message, 'autopay projections failed')
            && $context['budget_id'] === $budget->id)
        ->once();
});
