<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * RECUR-006: the analysis page, running an analysis through the controller,
 * settings validation and the GET refresh fallbacks.
 */

beforeEach(function () {
    $this->travelTo(Carbon\Carbon::parse('2026-06-15 12:00:00'));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id, 'name' => 'Everyday', 'include_in_budget' => true,
    ]);
});

test('an owner opens the analysis page and runs an analysis', function () {
    Account::factory()->checking()->create(['budget_id' => $this->budget->id, 'include_in_budget' => false]);
    foreach (['2026-01-15', '2026-02-15', '2026-03-15', '2026-04-15', '2026-05-15'] as $date) {
        Transaction::factory()->create([
            'budget_id' => $this->budget->id, 'account_id' => $this->account->id,
            'description' => 'Netflix', 'category' => 'Subscriptions',
            'amount_in_cents' => -1599, 'date' => $date,
        ]);
    }

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.analysis', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Analysis')
            ->where('budget.id', $this->budget->id)
            ->has('accounts', 1)
            ->where('accounts.0.id', $this->account->id)
            ->missing('analysisResult'));

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.analysis.analyze', $this->budget), ['account_id' => $this->account->id])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Analysis')
            ->where('analysisResult.success', true)
            ->where('analysisResult.account.id', $this->account->id)
            ->where('analysisResult.patterns', fn ($patterns) => collect($patterns)->contains(
                fn ($pattern) => $pattern['frequency'] === 'monthly' && (int) $pattern['day_of_month'] === 15
            ))
            ->has('analysisResult.analysis_summary'));
});

test('analysis settings outside their allowed ranges are rejected', function (array $settings, string $field) {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.analysis.analyze', $this->budget), array_merge(['account_id' => $this->account->id], $settings))
        ->assertSessionHasErrors($field);
})->with([
    'period below 3 months' => [['analysis_period_months' => 2], 'analysis_period_months'],
    'period above 24 months' => [['analysis_period_months' => 25], 'analysis_period_months'],
    'occurrences below 2' => [['min_occurrences' => 1], 'min_occurrences'],
    'occurrences above 10' => [['min_occurrences' => 11], 'min_occurrences'],
    'confidence below 0' => [['confidence_threshold' => -0.1], 'confidence_threshold'],
    'confidence above 1' => [['confidence_threshold' => 1.5], 'confidence_threshold'],
    'missing account' => [['account_id' => null], 'account_id'],
]);

test('refreshing the analyze or create templates URL redirects to the analysis page', function (string $routeName) {
    $this->actingAs($this->user)
        ->get(route($routeName, $this->budget))
        ->assertRedirect(route('recurring-transactions.analysis', $this->budget->id));
})->with([
    'recurring-transactions.analysis.analyze',
    'recurring-transactions.analysis.create-templates',
]);
