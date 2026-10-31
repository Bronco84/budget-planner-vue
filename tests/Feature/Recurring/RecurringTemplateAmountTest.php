<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\User;

/*
 * RECUR-001/RECUR-002: dollar amounts on recurring templates are converted to
 * exact integer cents (no float truncation, e.g. 19.99 must not become 1998).
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->create(['budget_id' => $this->budget->id]);
});

function recurringAmountPayload(array $overrides = []): array
{
    return array_replace([
        'description' => 'Streaming',
        'amount' => -15.99,
        'account_id' => test()->account->id,
        'category' => 'Entertainment',
        'frequency' => 'monthly',
        'start_date' => '2026-01-01',
        'day_of_month' => 5,
        'is_dynamic_amount' => false,
        'rules' => [],
    ], $overrides);
}

test('storing a recurring template converts amounts to the exact cent value', function (float $amount, int $expectedCents) {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), recurringAmountPayload([
            'amount' => $amount,
            'min_amount' => $amount,
            'max_amount' => $amount,
        ]))
        ->assertSessionHasNoErrors();

    $template = RecurringTransactionTemplate::sole();

    expect($template->amount_in_cents)->toBe($expectedCents)
        ->and((int) $template->min_amount)->toBe($expectedCents)
        ->and((int) $template->max_amount)->toBe($expectedCents);
})->with([
    '0.29' => [0.29, 29],
    '19.99' => [19.99, 1999],
    '-19.99' => [-19.99, -1999],
]);

test('updating a recurring template converts amounts to the exact cent value', function (float $amount, int $expectedCents) {
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'amount_in_cents' => -1000,
    ]);

    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.update', [$this->budget, $template]), recurringAmountPayload([
            'amount' => $amount,
        ]))
        ->assertSessionHasNoErrors();

    expect($template->fresh()->amount_in_cents)->toBe($expectedCents);
})->with([
    '0.29' => [0.29, 29],
    '19.99' => [19.99, 1999],
]);
