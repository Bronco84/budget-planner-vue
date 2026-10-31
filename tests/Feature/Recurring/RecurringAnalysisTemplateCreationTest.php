<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringTransactionAnalysisService;
use Illuminate\Support\Facades\Gate;

/*
 * RECUR-006: creating templates from patterns confirmed in the analysis modal.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
});

function confirmedPattern(array $overrides = []): array
{
    // Shape sent by RecurringTransactionConfirmationModal.
    return array_replace([
        'description' => 'Gym membership',
        'category' => 'Health',
        'amount_type' => 'static',
        'amount' => -40,
        'min_amount' => null,
        'max_amount' => null,
        'frequency' => 'monthly',
        'day_of_week' => null,
        'day_of_month' => 10,
        'bimonthly_first_day' => null,
        'bimonthly_second_day' => null,
        'start_date' => '2026-01-10',
        'end_date' => null,
        'transaction_ids' => [],
    ], $overrides);
}

test('creating templates from analysis requires the budget update ability', function () {
    Gate::before(fn ($user, string $ability) => $ability === 'update' ? false : null);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.analysis.create-templates', $this->budget), [
            'account_id' => $this->account->id,
            'selected_patterns' => [confirmedPattern()],
        ])
        ->assertForbidden();

    expect(RecurringTransactionTemplate::count())->toBe(0);
});

test('templates confirmed in the modal link the budgets transactions that formed the pattern', function () {
    $own = Transaction::factory()->count(2)->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'recurring_transaction_template_id' => null,
    ]);
    $otherBudget = Budget::factory()->create();
    $foreign = Transaction::factory()->create([
        'budget_id' => $otherBudget->id,
        'account_id' => Account::factory()->create(['budget_id' => $otherBudget->id])->id,
        'recurring_transaction_template_id' => null,
    ]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.analysis.create-templates', $this->budget), [
            'account_id' => $this->account->id,
            'selected_patterns' => [confirmedPattern([
                'transaction_ids' => [...$own->pluck('id')->all(), $foreign->id],
            ])],
        ])
        ->assertRedirect(route('recurring-transactions.index', $this->budget->id))
        ->assertSessionHas('message');

    $template = RecurringTransactionTemplate::where('budget_id', $this->budget->id)->sole();

    expect($template->transactions()->pluck('id')->sort()->values()->all())
        ->toBe($own->pluck('id')->sort()->values()->all())
        ->and($foreign->fresh()->recurring_transaction_template_id)->toBeNull();
});

test('selected pattern items with missing or invalid fields are rejected and nothing is created', function (array $overrides, string $errorKey) {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.analysis.create-templates', $this->budget), [
            'account_id' => $this->account->id,
            'selected_patterns' => [confirmedPattern($overrides)],
        ])
        ->assertSessionHasErrors($errorKey);

    expect(RecurringTransactionTemplate::count())->toBe(0);
})->with([
    'missing description' => [['description' => null], 'selected_patterns.0.description'],
    'unknown frequency' => [['frequency' => 'fortnightly'], 'selected_patterns.0.frequency'],
    'invalid start date' => [['start_date' => 'not-a-date'], 'selected_patterns.0.start_date'],
    'non-numeric amount' => [['amount' => 'lots'], 'selected_patterns.0.amount'],
    'day of month out of range' => [['day_of_month' => 32], 'selected_patterns.0.day_of_month'],
]);

test('partial template creation failures use the error flash instead of the success flash', function () {
    $this->mock(RecurringTransactionAnalysisService::class)
        ->shouldReceive('createTemplatesFromPatterns')
        ->andReturn([
            'success' => true,
            'created_templates' => 1,
            'total_requested' => 2,
            'templates' => [],
            'errors' => [['index' => 1, 'description' => 'Broken', 'error' => 'boom']],
        ]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.analysis.create-templates', $this->budget), [
            'account_id' => $this->account->id,
            'selected_patterns' => [confirmedPattern(), confirmedPattern(['description' => 'Broken'])],
        ])
        ->assertRedirect(route('recurring-transactions.index', $this->budget->id))
        ->assertSessionHas('error', 'Created 1 of 2 templates. 1 could not be created.')
        ->assertSessionMissing('message')
        ->assertSessionMissing('success');
});
