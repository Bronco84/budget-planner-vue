<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * REPORT-001: reports dashboard custom date range validation.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(12, 0));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
});

test('reports accept a valid custom date range', function () {
    $this->actingAs($this->user)
        ->get(route('reports.index', [
            $this->budget,
            'date_range' => 'custom',
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->where('dateRange.start', '2026-01-01')
            ->where('dateRange.end', '2026-03-31')
            ->where('dateRange.preset', 'custom'));
});

test('reports reject an unparsable or inverted custom date range', function (array $query, string $field) {
    $this->actingAs($this->user)
        ->get(route('reports.index', array_merge([$this->budget, 'date_range' => 'custom'], $query)))
        ->assertRedirect()
        ->assertSessionHasErrors($field);
})->with([
    'unparsable start' => [['start_date' => 'not-a-date', 'end_date' => '2026-03-31'], 'start_date'],
    'unparsable end' => [['start_date' => '2026-01-01', 'end_date' => 'garbage'], 'end_date'],
    'start after end' => [['start_date' => '2026-05-01', 'end_date' => '2026-01-01'], 'end_date'],
]);
