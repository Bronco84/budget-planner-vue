<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;

/*
 * CALENDAR-003: the calendar must project recurring items with the same
 * schedule as the rest of the app, and include items dated the 1st.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-01 09:00:00'));
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->user->setActiveBudget($this->budget->id);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
});

test('a bimonthly recurring item appears twice in the month on its two days', function () {
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Paycheck',
        'amount_in_cents' => 200000,
        'frequency' => 'bimonthly',
        'first_day_of_month' => 5,
        'day_of_month' => 20,
        'start_date' => '2026-01-01',
    ]);

    $this->actingAs($this->user)
        ->get(route('calendar.index', ['year' => 2026, 'month' => 3]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('calendarData.days.4.date', '2026-03-05')
            ->where('calendarData.days.4.counts.projected', 1)
            ->where('calendarData.days.19.date', '2026-03-20')
            ->where('calendarData.days.19.counts.projected', 1));
});

test('a transaction dated the first of the month is shown on day one', function () {
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'First-of-month fee',
        'amount_in_cents' => -500,
        'date' => '2026-03-01',
    ]);

    $this->actingAs($this->user)
        ->get(route('calendar.index', ['year' => 2026, 'month' => 3]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('calendarData.days.0.date', '2026-03-01')
            ->where('calendarData.days.0.counts.posted', 1));
});
