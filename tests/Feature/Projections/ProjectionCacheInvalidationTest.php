<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transfer;
use App\Services\BudgetService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * BUDGET-002: projected transactions are cached per account for an hour.
 * Every change that affects them must invalidate every cached window,
 * including the 0-month ("No projections") one, on the database cache store
 * production uses, where keys cannot be deleted by pattern (PR-034).
 */

beforeEach(function () {
    config(['cache.default' => 'database']);
    $this->travelTo('2026-06-10 09:00:00');

    $this->budget = Budget::factory()->create();
    $this->checking = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'current_balance_cents' => 100000,
    ]);
    $this->template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->checking->id,
        'description' => 'Gym',
        'amount_in_cents' => -5000,
        'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
        'day_of_month' => 20,
        'start_date' => '2026-01-01',
        'end_date' => null,
        'is_dynamic_amount' => false,
    ]);
});

function cachedProjectionAmounts(Account $account, int $months): array
{
    return app(BudgetService::class)
        ->getAccountTransactions($account->fresh(), Carbon::parse('2026-06-01'), now()->addMonths($months)->endOfMonth(), $months)
        ->filter(fn ($transaction) => $transaction->is_projected)
        ->map(fn ($transaction) => $transaction->date->format('Y-m-d').' '.$transaction->amount_in_cents)
        ->values()
        ->all();
}

test('a cached projection is invalidated when a template changes, for every projection window', function (int $months) {
    // The template lands on the 20th of June and of each projected month after it.
    $dates = collect(range(0, $months))->map(fn ($i) => Carbon::parse('2026-06-20')->addMonths($i)->format('Y-m-d'));

    expect(cachedProjectionAmounts($this->checking, $months))->toBe($dates->map(fn ($d) => "{$d} -5000")->all());

    $this->template->update(['amount_in_cents' => -7000]);

    expect(cachedProjectionAmounts($this->checking, $months))->toBe($dates->map(fn ($d) => "{$d} -7000")->all());
})->with([
    'no projections (0 months)' => [0],
    'one month' => [1],
    'twelve months' => [12],
    'beyond the twelve enumerated months' => [24],
]);

test('a cached projection is invalidated when a transfer is added', function () {
    $savings = Account::factory()->savings()->create(['budget_id' => $this->budget->id]);

    expect(cachedProjectionAmounts($this->checking, 0))->toBe(['2026-06-20 -5000']);

    Transfer::create([
        'budget_id' => $this->budget->id,
        'from_account_id' => $this->checking->id,
        'to_account_id' => $savings->id,
        'amount_in_cents' => 2500,
        'description' => 'Save',
        'date' => '2026-06-25',
    ]);

    expect(cachedProjectionAmounts($this->checking, 0))->toContain('2026-06-25 -2500');
});

test('invalidating one account leaves another account cached projections in place', function () {
    $other = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $other->id,
        'amount_in_cents' => -1000,
        'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
        'day_of_month' => 22,
        'start_date' => '2026-01-01',
        'end_date' => null,
        'is_dynamic_amount' => false,
    ]);

    expect(cachedProjectionAmounts($other, 0))->toBe(['2026-06-22 -1000']);

    $queries = 0;
    BudgetService::clearAccountCaches($this->checking->id);
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'recurring_transaction_templates')) {
            $queries++;
        }
    });

    cachedProjectionAmounts($other, 0);

    // Served from cache: the other account's templates are not queried again.
    expect($queries)->toBe(0);
});
