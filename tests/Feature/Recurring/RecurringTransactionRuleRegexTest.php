<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * RECUR-003: regex matching rules must carry a valid pattern. Rule values are
 * full PCRE patterns including delimiters (RecurringTransactionRule::matchesTransaction
 * passes them to preg_match as-is).
 */

beforeEach(function () {
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
    ]);
});

function regexRuleParams(array $extra = []): array
{
    return array_merge([
        'budget' => test()->budget,
        'recurring_transaction' => test()->template,
    ], $extra);
}

test('storing a regex rule with a malformed pattern is rejected', function (string $pattern) {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.store', regexRuleParams()), [
            'field' => 'description',
            'operator' => 'regex',
            'value' => $pattern,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('value');

    expect($this->template->rules()->count())->toBe(0);
})->with([
    'unbalanced group' => ['/netflix(/'],
    'no delimiters' => ['netflix'],
]);

test('storing a regex rule with a valid pattern succeeds and previews matches', function () {
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'NETFLIX.COM 1234',
        'date' => now()->subDays(5)->toDateString(),
    ]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.store', regexRuleParams()), [
            'field' => 'description',
            'operator' => 'regex',
            'value' => '/^netflix\.com/',
        ])
        ->assertSessionHasNoErrors();

    expect($this->template->rules()->count())->toBe(1);

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.rules.preview', regexRuleParams()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Rules/Preview')
            ->where('hasActiveRules', true)
            ->has('matchingTransactions', 1));
});

test('updating a rule to a malformed regex pattern is rejected', function () {
    $rule = $this->template->rules()->create([
        'field' => 'description',
        'operator' => RecurringTransactionRule::OPERATOR_CONTAINS,
        'value' => 'netflix',
        'priority' => 1,
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.rules.update', regexRuleParams(['rule' => $rule])), [
            'field' => 'description',
            'operator' => 'regex',
            'value' => '/[unterminated/',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('value');

    expect($rule->fresh()->operator)->toBe(RecurringTransactionRule::OPERATOR_CONTAINS);
});

test('testing a draft rule with a malformed regex pattern is rejected', function () {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.test', regexRuleParams()), [
            'field' => 'description',
            'operator' => 'regex',
            'value' => '/(/',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('value');
});

test('non regex rules are not subject to pattern validation', function () {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.store', regexRuleParams()), [
            'field' => 'description',
            'operator' => 'contains',
            'value' => 'netflix(',
        ])
        ->assertSessionHasNoErrors();

    expect($this->template->rules()->count())->toBe(1);
});
