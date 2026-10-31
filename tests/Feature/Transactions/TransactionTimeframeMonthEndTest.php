<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

/*
 * TXN-001: relative timeframes must not overflow at month end
 * (31 October minus one month is September, not 1 October).
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-31 12:00:00'));
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $account = Account::factory()->create(['budget_id' => $this->budget->id]);

    foreach (['2026-10-15', '2026-09-15', '2026-08-01', '2026-07-01', '2026-06-30'] as $date) {
        Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $account->id, 'date' => $date]);
    }
});

test('last month on the 31st means the previous calendar month', function () {
    $this->actingAs($this->user)
        ->get(route('budget.transaction.index', ['budget' => $this->budget, 'timeframe' => 'last_month']))
        ->assertInertia(fn ($page) => $page->where('transactions.total', 1)
            ->where('transactions.data.0.date', fn ($d) => str_starts_with((string) $d, '2026-09-15')));
});

test('last three months on the 31st starts at the first of the month three months back', function () {
    $this->actingAs($this->user)
        ->get(route('budget.transaction.index', ['budget' => $this->budget, 'timeframe' => 'last_3_months']))
        ->assertInertia(fn ($page) => $page->where('transactions.total', 4));
});
