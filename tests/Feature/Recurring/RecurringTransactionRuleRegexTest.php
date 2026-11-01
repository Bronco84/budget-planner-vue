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

function regexTemplatePayload(array $rules): array
{
    return [
        'description' => 'Netflix',
        'amount' => -15.99,
        'account_id' => test()->account->id,
        'frequency' => 'monthly',
        'start_date' => '2026-01-01',
        'day_of_month' => 5,
        'is_dynamic_amount' => false,
        'rules' => $rules,
    ];
}

test('creating a template with a malformed regex rule is rejected', function (string $pattern) {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), regexTemplatePayload([
            ['field' => 'description', 'operator' => 'contains', 'value' => 'netflix('],
            ['field' => 'description', 'operator' => 'regex', 'value' => $pattern],
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('rules.1.value')
        ->assertSessionDoesntHaveErrors('rules.0.value');

    expect(RecurringTransactionTemplate::where('description', 'Netflix')->exists())->toBeFalse();
})->with([
    'unbalanced group' => ['/netflix(/'],
    'no delimiters' => ['netflix'],
]);

test('creating a template with a valid regex rule succeeds', function () {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), regexTemplatePayload([
            ['field' => 'description', 'operator' => 'regex', 'value' => '/^netflix\.com/'],
        ]))
        ->assertSessionHasNoErrors();

    $template = RecurringTransactionTemplate::where('description', 'Netflix')->sole();
    expect($template->rules()->pluck('value')->all())->toBe(['/^netflix\.com/']);
});

test('updating a template with a malformed regex rule is rejected', function () {
    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.update', regexRuleParams()), regexTemplatePayload([
            ['field' => 'description', 'operator' => 'regex', 'value' => '/[unterminated/'],
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('rules.0.value');

    expect($this->template->rules()->count())->toBe(0);
});

test('case insensitive regex rules keep escape classes as written', function (string $pattern, string $matching, string $other) {
    $rule = new RecurringTransactionRule([
        'field' => 'description',
        'operator' => 'regex',
        'value' => $pattern,
        'is_case_sensitive' => false,
        'is_active' => true,
    ]);

    expect($rule->matchesTransaction(new Transaction(['description' => $matching])))->toBeTrue()
        ->and($rule->matchesTransaction(new Transaction(['description' => $other])))->toBeFalse();
})->with([
    'non-space \S' => ['/^AMZN\S+/', 'amzn*mktp 123', 'AMZN MKTP'],
    'non-digit \D' => ['/^ref\D{3}$/', 'REF-AB', 'REF123'],
    'non-word \W' => ['/^pay\W/', 'PAY*NETFLIX', 'PAYPAL'],
    'mixed case pattern' => ['/^NetFlix\.com/', 'NETFLIX.COM 1234', 'NETFLIXCOM'],
]);

test('case sensitive regex rules match case exactly', function () {
    $rule = new RecurringTransactionRule([
        'field' => 'description',
        'operator' => 'regex',
        'value' => '/^NETFLIX/',
        'is_case_sensitive' => true,
        'is_active' => true,
    ]);

    expect($rule->matchesTransaction(new Transaction(['description' => 'NETFLIX.COM'])))->toBeTrue()
        ->and($rule->matchesTransaction(new Transaction(['description' => 'netflix.com'])))->toBeFalse();
});

test('a case insensitive regex rule with escape classes is accepted and links what it describes', function () {
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'AMZN*MKTP US',
        'date' => now()->subDays(5)->toDateString(),
    ]);
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'AMZN MKTP US',
        'date' => now()->subDays(4)->toDateString(),
    ]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.store', regexRuleParams()), [
            'field' => 'description',
            'operator' => 'regex',
            'value' => '/^AMZN\S+/',
            'is_case_sensitive' => false,
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.rules.preview', regexRuleParams()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('matchingTransactions', 1)
            ->where('matchingTransactions.0.transaction.description', 'AMZN*MKTP US'));
});
