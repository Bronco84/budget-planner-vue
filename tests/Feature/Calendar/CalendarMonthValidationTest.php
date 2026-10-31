<?php

use App\Models\Budget;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * CALENDAR-003: the year/month query params are validated before they reach
 * Carbon::create, so they cannot roll over silently or raise a 500.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->user->setActiveBudget($budget->id);
});

test('the calendar renders the requested month', function () {
    $this->actingAs($this->user)
        ->get(route('calendar.index', ['year' => 2026, 'month' => 3]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Calendar/Index')
            ->where('currentMonth', '2026-03'));
});

test('the calendar rejects an out of range or non-numeric year or month', function (array $query, string $field) {
    $this->actingAs($this->user)
        ->getJson(route('calendar.index', $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'month 13' => [['year' => 2026, 'month' => 13], 'month'],
    'month 0' => [['year' => 2026, 'month' => 0], 'month'],
    'non-numeric month' => [['year' => 2026, 'month' => 'abc'], 'month'],
    'non-numeric year' => [['year' => 'abc', 'month' => 3], 'year'],
    'year too far out' => [['year' => 99999, 'month' => 3], 'year'],
]);
