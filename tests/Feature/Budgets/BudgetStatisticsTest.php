<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * REPORT-002: monthly and yearly budget statistics.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(12, 0));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
});

test('owner sees the monthly statistics page with totals for the requested month', function () {
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'amount_in_cents' => 200000,
        'date' => '2026-05-03',
    ]);
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'amount_in_cents' => -50000,
        'date' => '2026-05-10',
    ]);

    $this->actingAs($this->user)
        ->get(route('budget.statistics.monthly', [$this->budget, 5, 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Statistics/Monthly')
            ->where('budget.id', $this->budget->id)
            ->where('month', 5)
            ->where('year', 2026)
            ->where('statistics.total_income', 200000)
            ->where('statistics.total_expenses', -50000)
            ->where('statistics.prev_month_name', 'April'));
});

test('monthly statistics default to the current month and show zero totals when empty', function () {
    $this->actingAs($this->user)
        ->get(route('budget.statistics.monthly', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Statistics/Monthly')
            ->where('month', 6)
            ->where('year', 2026)
            ->where('statistics.total_income', 0)
            ->where('statistics.total_expenses', 0)
            ->has('statistics.by_category', 0));
});

test('owner sees the yearly statistics page with twelve months and prior year comparison', function () {
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'amount_in_cents' => 300000,
        'date' => '2025-03-05',
    ]);
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'amount_in_cents' => 150000,
        'date' => '2024-03-05',
    ]);

    $this->actingAs($this->user)
        ->get(route('budget.statistics.yearly', [$this->budget, 'year' => 2025]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Statistics/Yearly')
            ->where('year', 2025)
            ->has('statistics.monthly', 12)
            ->where('statistics.monthly.March.total_income', 300000)
            ->where('statistics.monthly.March.prev_year_income', 150000)
            ->where('statistics.yearly_totals.income', 300000));
});
