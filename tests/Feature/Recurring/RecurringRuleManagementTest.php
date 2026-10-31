<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * RECUR-003: adding, previewing, applying, testing and re-evaluating matching
 * rules, the no-active-rules state, and authorization for every rules route.
 */

beforeEach(function () {
    $this->travelTo(Carbon\Carbon::parse('2026-03-15 10:00:00'));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Netflix',
    ]);
});

function recurRuleTransaction(Account $account, string $description, array $attributes = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'budget_id' => $account->budget_id,
        'account_id' => $account->id,
        'description' => $description,
        'amount_in_cents' => -1549,
        'date' => '2026-03-01',
    ], $attributes));
}

function recurRuleCreate(RecurringTransactionTemplate $template, array $attributes = []): RecurringTransactionRule
{
    return RecurringTransactionRule::create(array_merge([
        'recurring_transaction_template_id' => $template->id,
        'field' => 'description',
        'operator' => 'contains',
        'value' => 'netflix',
        'is_case_sensitive' => false,
        'priority' => 1,
        'is_active' => true,
    ], $attributes));
}

function recurRuleParams(Budget $budget, RecurringTransactionTemplate $template, array $extra = []): array
{
    return array_merge(['budget' => $budget, 'recurring_transaction' => $template], $extra);
}

test('an owner adds a rule, previews its matches and applies them to link the transactions', function () {
    $match = recurRuleTransaction($this->account, 'NETFLIX.COM 866');
    $miss = recurRuleTransaction($this->account, 'Hulu');
    $params = recurRuleParams($this->budget, $this->template);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.store', $params), ['field' => 'description', 'operator' => 'contains', 'value' => 'netflix'])
        ->assertSessionHas('message', 'Rule created successfully');

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.rules.preview', $params))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Rules/Preview')
            ->where('hasActiveRules', true)
            ->has('matchingTransactions', 1)
            ->where('matchingTransactions.0.transaction.id', $match->id));

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.apply', $params))
        ->assertRedirect(route('recurring-transactions.rules.index', $params))
        ->assertSessionHas('message', 'Successfully linked 1 transactions to this recurring transaction.');

    expect($match->fresh()->recurring_transaction_template_id)->toBe($this->template->id)
        ->and($miss->fresh()->recurring_transaction_template_id)->toBeNull();
});

test('testing a draft rule shows its matches without saving the rule', function () {
    $match = recurRuleTransaction($this->account, 'Netflix monthly');
    recurRuleTransaction($this->account, 'Spotify');

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.test', recurRuleParams($this->budget, $this->template)), [
            'field' => 'description', 'operator' => 'starts_with', 'value' => 'netflix',
        ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Rules/Test')
            ->where('rule.operator', 'starts_with')
            ->where('totalTested', 2)
            ->has('matchingTransactions', 1)
            ->where('matchingTransactions.0.id', $match->id));

    expect(RecurringTransactionRule::count())->toBe(0)
        ->and($match->fresh()->recurring_transaction_template_id)->toBeNull();
});

test('editing a rule unlinks transactions that no longer match and links newly matching ones', function () {
    $rule = recurRuleCreate($this->template, ['value' => 'netflix']);
    $noLongerMatching = recurRuleTransaction($this->account, 'Netflix basic', ['recurring_transaction_template_id' => $this->template->id]);
    $newlyMatching = recurRuleTransaction($this->account, 'Netflix premium');
    $params = recurRuleParams($this->budget, $this->template, ['rule' => $rule]);

    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.rules.update', $params), ['field' => 'description', 'operator' => 'contains', 'value' => 'premium'])
        ->assertSessionHas('message', 'Rule updated successfully (1 transactions linked, 1 unlinked)');

    expect($rule->fresh()->value)->toBe('premium')
        ->and($noLongerMatching->fresh()->recurring_transaction_template_id)->toBeNull()
        ->and($newlyMatching->fresh()->recurring_transaction_template_id)->toBe($this->template->id);
});

test('with no active rules preview reports none and apply links nothing', function () {
    recurRuleCreate($this->template, ['is_active' => false]);
    $transaction = recurRuleTransaction($this->account, 'Netflix');
    $params = recurRuleParams($this->budget, $this->template);

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.rules.preview', $params))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Rules/Preview')
            ->where('hasActiveRules', false)
            ->has('matchingTransactions', 0));

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.apply', $params))
        ->assertRedirect(route('recurring-transactions.rules.index', $params))
        ->assertSessionHas('warning', 'No active rules to apply');

    expect($transaction->fresh()->recurring_transaction_template_id)->toBeNull();
});

/**
 * [http method, route name, needs {rule}, payload] for every rules route.
 */
dataset('recurring_rule_routes', [
    'index' => ['get', 'recurring-transactions.rules.index', false, []],
    'store' => ['post', 'recurring-transactions.rules.store', false, ['field' => 'description', 'operator' => 'contains', 'value' => 'x']],
    'update' => ['patch', 'recurring-transactions.rules.update', true, ['field' => 'description', 'operator' => 'contains', 'value' => 'x']],
    'destroy' => ['delete', 'recurring-transactions.rules.destroy', true, []],
    'test' => ['post', 'recurring-transactions.rules.test', false, ['field' => 'description', 'operator' => 'contains', 'value' => 'x']],
    'preview' => ['get', 'recurring-transactions.rules.preview', false, []],
    'apply' => ['post', 'recurring-transactions.rules.apply', false, []],
    'unlink' => ['post', 'recurring-transactions.rules.unlink', false, ['transaction_id' => 'linked']],
    'linked' => ['get', 'recurring-transactions.rules.linked', false, []],
]);

/**
 * A template in $budget with one rule and one linked transaction, plus a
 * snapshot callback proving nothing about it changed.
 */
function recurRuleForeignFixture(Budget $budget): array
{
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $template = RecurringTransactionTemplate::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id, 'description' => 'Foreign']);
    $rule = recurRuleCreate($template, ['value' => 'foreign']);
    $linked = recurRuleTransaction($account, 'Foreign charge', ['recurring_transaction_template_id' => $template->id]);
    $unlinked = recurRuleTransaction($account, 'Foreign other');

    $snapshot = fn () => [
        $template->rules()->get(['id', 'field', 'operator', 'value', 'is_active'])->toArray(),
        Transaction::where('account_id', $account->id)->orderBy('id')->pluck('recurring_transaction_template_id', 'id')->all(),
    ];

    return [$template, $rule, $linked, $snapshot];
}

function recurRuleCall($test, string $method, string $routeName, bool $needsRule, array $payload, Budget $budget, array $fixture)
{
    [$template, $rule, $linked] = $fixture;
    $params = recurRuleParams($budget, $template, $needsRule ? ['rule' => $rule] : []);
    if (($payload['transaction_id'] ?? null) === 'linked') {
        $payload['transaction_id'] = $linked->id;
    }

    return $test->{$method}(route($routeName, $params), $payload);
}

test('rules routes for a template from another budget return 404 and change nothing', function (string $method, string $routeName, bool $needsRule, array $payload) {
    $otherBudget = Budget::factory()->create(['user_id' => $this->user->id]);
    $fixture = recurRuleForeignFixture($otherBudget);
    $before = $fixture[3]();

    $this->actingAs($this->user);
    recurRuleCall($this, $method, $routeName, $needsRule, $payload, $this->budget, $fixture)->assertNotFound();

    expect($fixture[3]())->toBe($before);
})->with('recurring_rule_routes');

test('rules routes for another users budget return 403 and change nothing', function (string $method, string $routeName, bool $needsRule, array $payload) {
    $otherBudget = Budget::factory()->create();
    $fixture = recurRuleForeignFixture($otherBudget);
    $before = $fixture[3]();

    $this->actingAs($this->user);
    recurRuleCall($this, $method, $routeName, $needsRule, $payload, $otherBudget, $fixture)->assertForbidden();

    expect($fixture[3]())->toBe($before);
})->with('recurring_rule_routes');
