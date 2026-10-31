<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;

/*
 * CALENDAR-003: the month view's day contents (posted and projected items and
 * calendar events), the no-budget redirect, and events of disabled connections.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-01 09:00:00'));

    $this->user = User::factory()->create();
});

function calendarConnectionFor(User $user, string $name, bool $active): CalendarConnection
{
    return CalendarConnection::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'calendar_id' => strtolower($name),
        'calendar_name' => $name,
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'is_active' => $active,
    ]);
}

function calendarEventOn(CalendarConnection $connection, string $title, string $date): CalendarEvent
{
    return CalendarEvent::create([
        'calendar_connection_id' => $connection->id,
        'user_id' => $connection->user_id,
        'google_event_id' => 'evt-'.$title,
        'title' => $title,
        'start_date' => Carbon::parse($date),
        'all_day' => true,
        'google_updated_at' => now(),
    ]);
}

test('the calendar month shows posted transactions, projected recurring items and active calendar events', function () {
    $budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->user->setActiveBudget($budget->id);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id, 'name' => 'Everyday']);

    Transaction::factory()->create([
        'budget_id' => $budget->id,
        'account_id' => $account->id,
        'description' => 'Paycheck',
        'amount_in_cents' => 250000,
        'category' => 'Income',
        'date' => '2026-03-02',
    ]);
    RecurringTransactionTemplate::factory()->create([
        'budget_id' => $budget->id,
        'account_id' => $account->id,
        'description' => 'Rent',
        'amount_in_cents' => -120000,
        'frequency' => 'monthly',
        'start_date' => '2026-01-15',
    ]);
    calendarEventOn(calendarConnectionFor($this->user, 'Personal', true), 'Dentist', '2026-03-10');

    $this->actingAs($this->user)
        ->get(route('calendar.index', ['year' => 2026, 'month' => 3]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Calendar/Index')
            ->where('selectedBudget.id', $budget->id)
            ->where('calendarData.monthName', 'March 2026')
            ->has('calendarData.days', 31)
            // 2 March: the posted paycheck.
            ->where('calendarData.days.1.date', '2026-03-02')
            ->where('calendarData.days.1.counts.posted', 1)
            ->where('calendarData.days.1.transactions.posted.0.description', 'Paycheck')
            ->where('calendarData.days.1.transactions.posted.0.account_name', 'Everyday')
            ->where('calendarData.days.1.totals.posted_income_cents', 250000)
            // 15 March: the projected rent.
            ->where('calendarData.days.14.date', '2026-03-15')
            ->where('calendarData.days.14.counts.projected', 1)
            ->where('calendarData.days.14.transactions.projected.0.description', 'Rent')
            ->where('calendarData.days.14.totals.projected_expenses_cents', 120000)
            ->has('calendarEvents', 1)
            ->where('calendarEvents.0.title', 'Dentist')
            ->where('calendarEvents.0.start_date', '2026-03-10')
            ->where('calendarEvents.0.calendar_name', 'Personal'));
});

test('a user without a budget is sent to budget creation from the calendar', function () {
    $this->actingAs($this->user)
        ->get(route('calendar.index'))
        ->assertRedirect(route('budgets.create'))
        ->assertSessionHas('message', 'Please create a budget to use the calendar.');
});

test('events from a disabled calendar connection are not shown', function () {
    $budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->user->setActiveBudget($budget->id);

    calendarEventOn(calendarConnectionFor($this->user, 'Work', true), 'Standup', '2026-03-05');
    calendarEventOn(calendarConnectionFor($this->user, 'Old', false), 'Hidden event', '2026-03-06');

    $this->actingAs($this->user)
        ->get(route('calendar.index', ['year' => 2026, 'month' => 3]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Calendar/Index')
            ->has('calendarEvents', 1)
            ->where('calendarEvents.0.title', 'Standup'));
});
