<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidTransaction;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * RECUR-003 / RECUR-004: rule page actions, rule previews and matching
 * diagnostics for a template, including templates whose account was deleted.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Netflix',
        'plaid_entity_id' => null,
    ]);
    $this->orphanTemplate = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => null,
        'description' => 'Orphaned gym',
    ]);
});

function routeRule(RecurringTransactionTemplate $template): RecurringTransactionRule
{
    return RecurringTransactionRule::create([
        'recurring_transaction_template_id' => $template->id,
        'field' => 'description',
        'operator' => 'contains',
        'value' => 'gym',
        'is_case_sensitive' => false,
        'priority' => 1,
        'is_active' => true,
    ]);
}

// --- RECUR-003: redirects ---

test('rule store update apply and destroy all return to the rules page', function () {
    $params = ['budget' => $this->budget, 'recurring_transaction' => $this->template];
    $rulesPage = route('recurring-transactions.rules.index', $params);
    $payload = ['field' => 'description', 'operator' => 'contains', 'value' => 'netflix'];

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.store', $params), $payload)
        ->assertRedirect($rulesPage);

    $rule = $this->template->rules()->sole();

    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.rules.update', $params + ['rule' => $rule]), $payload)
        ->assertRedirect($rulesPage);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.apply', $params))
        ->assertRedirect($rulesPage);

    $this->actingAs($this->user)
        ->delete(route('recurring-transactions.rules.destroy', $params + ['rule' => $rule]))
        ->assertRedirect($rulesPage);
});

// --- RECUR-003: template without an account ---

test('testing and previewing rules for a template without an account returns empty results', function () {
    routeRule($this->orphanTemplate);
    // An account-less transaction must not be picked up as "the template's account".
    Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => null,
        'description' => 'Gym membership',
        'date' => now()->subDays(3),
    ]);
    $params = ['budget' => $this->budget, 'recurring_transaction' => $this->orphanTemplate];

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.test', $params), [
            'field' => 'description', 'operator' => 'contains', 'value' => 'gym',
        ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Rules/Test')
            ->has('matchingTransactions', 0)
            ->where('totalTested', 0));

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.rules.preview', $params))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Rules/Preview')
            ->has('matchingTransactions', 0)
            ->where('hasActiveRules', true));

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.apply', $params))
        ->assertRedirect(route('recurring-transactions.rules.index', $params));

    expect(Transaction::whereNotNull('recurring_transaction_template_id')->count())->toBe(0);
});

// --- RECUR-004: linked list and test matching ---

test('the linked list only shows transactions from the routed budget', function () {
    $own = Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'recurring_transaction_template_id' => $this->template->id,
    ]);
    $otherBudget = Budget::factory()->create();
    Transaction::factory()->create([
        'budget_id' => $otherBudget->id,
        'account_id' => Account::factory()->create(['budget_id' => $otherBudget->id])->id,
        'recurring_transaction_template_id' => $this->template->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.rules.linked', ['budget' => $this->budget, 'recurring_transaction' => $this->template]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Rules/Linked')
            ->has('linkedTransactions.data', 1)
            ->where('linkedTransactions.data.0.id', $own->id));
});

test('test matching for a template without an account returns no matches', function () {
    $this->actingAs($this->user)
        ->getJson(route('recurring-transactions.test-matching', ['budget' => $this->budget, 'recurring_transaction' => $this->orphanTemplate]))
        ->assertOk()
        ->assertJson(['total_tested' => 0, 'matches_found' => 0, 'matches' => []]);
});

test('test matching reports plaid entity id matches from array counterparties', function () {
    $this->template->update(['plaid_entity_id' => 'ENT-123', 'plaid_entity_name' => 'Netflix Inc', 'description' => 'Streaming']);

    $plaid = PlaidTransaction::create([
        'account_id' => $this->account->id,
        'plaid_transaction_id' => 'plaid-tx-1',
        'plaid_account_id' => 'plaid-acct-1',
        'amount' => 15.99,
        'date' => now()->subDays(5)->toDateString(),
        'name' => 'Something unrelated',
        'counterparties' => [['name' => 'Netflix', 'entity_id' => 'ENT-123', 'type' => 'merchant']],
    ]);
    $transaction = Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Something unrelated',
        'plaid_transaction_id' => $plaid->plaid_transaction_id,
        'date' => now()->subDays(5),
        'recurring_transaction_template_id' => null,
    ]);

    $this->actingAs($this->user)
        ->getJson(route('recurring-transactions.test-matching', ['budget' => $this->budget, 'recurring_transaction' => $this->template]))
        ->assertOk()
        ->assertJsonPath('matches_found', 1)
        ->assertJsonPath('matches.0.transaction.id', $transaction->id)
        ->assertJsonPath('matches.0.match_method', 'entity_id');
});
