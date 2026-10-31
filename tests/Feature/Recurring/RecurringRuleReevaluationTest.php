<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringTransactionService;

/*
 * RECUR-003: re-evaluating a template's links after a rule is updated or
 * deleted. Rule matching must use RecurringTransactionRule::matchesTransaction
 * (regex values are full patterns with delimiters), and deleting the last rule
 * must not wipe links made by entity, description or manual matching.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'plaid_entity_id' => null,
    ]);
});

function reevaluationTransaction(string $description, ?int $templateId = null): Transaction
{
    return Transaction::factory()->create([
        'budget_id' => test()->budget->id,
        'account_id' => test()->account->id,
        'description' => $description,
        'amount_in_cents' => -1599,
        'recurring_transaction_template_id' => $templateId,
    ]);
}

function reevaluationRule(array $attributes): RecurringTransactionRule
{
    return RecurringTransactionRule::create(array_merge([
        'recurring_transaction_template_id' => test()->template->id,
        'field' => 'description',
        'is_case_sensitive' => false,
        'priority' => 1,
        'is_active' => true,
    ], $attributes));
}

test('deleting the last rule keeps transactions linked by other means', function () {
    $manual = reevaluationTransaction('Gym membership', $this->template->id);
    $rule = reevaluationRule(['operator' => 'contains', 'value' => 'gym']);

    $this->actingAs($this->user)
        ->delete(route('recurring-transactions.rules.destroy', [
            'budget' => $this->budget,
            'recurring_transaction' => $this->template,
            'rule' => $rule,
        ]))
        ->assertRedirect();

    expect($this->template->rules()->count())->toBe(0)
        ->and($manual->fresh()->recurring_transaction_template_id)->toBe($this->template->id);
});

test('updating a rule to a delimited regex links matching transactions', function () {
    $netflix = reevaluationTransaction('NETFLIX.COM 1234');
    $other = reevaluationTransaction('Spotify');
    $rule = reevaluationRule(['operator' => 'contains', 'value' => 'nothing-matches']);

    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.rules.update', [
            'budget' => $this->budget,
            'recurring_transaction' => $this->template,
            'rule' => $rule,
        ]), [
            'field' => 'description',
            'operator' => 'regex',
            'value' => '/^netflix/',
            'is_case_sensitive' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($netflix->fresh()->recurring_transaction_template_id)->toBe($this->template->id)
        ->and($other->fresh()->recurring_transaction_template_id)->toBeNull();
});

test('re-evaluation matches amount rules the same way the rule preview does', function () {
    // The model compares amount rules against the absolute dollar amount ($15.99).
    $charge = reevaluationTransaction('Streaming');
    reevaluationRule(['field' => 'amount', 'operator' => 'greater_than', 'value' => '10']);

    $result = app(RecurringTransactionService::class)->reevaluateTransactionLinks($this->template);

    expect($result['linked'])->toBe(1)
        ->and($charge->fresh()->recurring_transaction_template_id)->toBe($this->template->id);
});

test('a stored regex rule that does not compile matches nothing instead of erroring', function () {
    $netflix = reevaluationTransaction('Netflix');
    reevaluationRule(['operator' => 'regex', 'value' => 'netflix(']);

    $result = app(RecurringTransactionService::class)->reevaluateTransactionLinks($this->template);

    expect($result['linked'])->toBe(0)
        ->and($netflix->fresh()->recurring_transaction_template_id)->toBeNull();
});
