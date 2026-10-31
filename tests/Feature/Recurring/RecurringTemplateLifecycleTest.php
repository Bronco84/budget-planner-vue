<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/*
 * RECUR-001 / RECUR-002: listing, duplicating and editing templates.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Gym',
        'frequency' => 'monthly',
        'day_of_month' => 5,
        'amount_in_cents' => -4000,
        'is_dynamic_amount' => false,
    ]);
});

function lifecycleRule(RecurringTransactionTemplate $template, array $attributes = []): RecurringTransactionRule
{
    return RecurringTransactionRule::create(array_merge([
        'recurring_transaction_template_id' => $template->id,
        'field' => 'description',
        'operator' => 'contains',
        'value' => 'gym',
        'is_case_sensitive' => false,
        'priority' => 1,
        'is_active' => true,
    ], $attributes));
}

test('the recurring index does not write account records to the log', function () {
    Log::spy();

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.index', $this->budget))
        ->assertOk();

    Log::shouldNotHaveReceived('info');
});

test('duplicating a template copies its matching rules', function () {
    lifecycleRule($this->template, ['value' => 'gym', 'priority' => 1]);
    lifecycleRule($this->template, ['field' => 'amount', 'operator' => 'equals', 'value' => '40', 'priority' => 2, 'is_active' => false]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.duplicate', ['budget' => $this->budget, 'recurring_transaction' => $this->template]))
        ->assertRedirect(route('recurring-transactions.index', $this->budget));

    $copy = RecurringTransactionTemplate::where('description', 'Copy of Gym')->sole();
    $columns = ['field', 'operator', 'value', 'is_case_sensitive', 'priority', 'is_active'];

    expect($copy->rules()->orderBy('priority')->get($columns)->toArray())
        ->toBe($this->template->rules()->orderBy('priority')->get($columns)->toArray())
        ->and($this->template->rules()->count())->toBe(2);
});

test('rules saved through the update form get priorities in submitted order', function () {
    $existing = lifecycleRule($this->template, ['value' => 'gym', 'priority' => 7]);

    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.update', ['budget' => $this->budget, 'recurring_transaction' => $this->template]), [
            'description' => 'Gym',
            'amount' => -40,
            'account_id' => $this->account->id,
            'category' => 'Health',
            'frequency' => 'monthly',
            'start_date' => '2026-01-01',
            'day_of_month' => 5,
            'is_dynamic_amount' => false,
            'rules' => [
                ['field' => 'description', 'operator' => 'starts_with', 'value' => 'planet'],
                ['id' => $existing->id, 'field' => 'description', 'operator' => 'contains', 'value' => 'gym'],
            ],
        ])
        ->assertRedirect(route('recurring-transactions.index', $this->budget));

    expect($this->template->rules()->orderBy('priority')->get(['value', 'priority'])->toArray())
        ->toBe([
            ['value' => 'planet', 'priority' => 1],
            ['value' => 'gym', 'priority' => 2],
        ]);
});
