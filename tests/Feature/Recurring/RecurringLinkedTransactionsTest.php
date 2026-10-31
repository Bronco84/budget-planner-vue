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
 * RECUR-004: the linked-transactions list, unlinking, matching diagnostics and
 * test matching, plus their failure and authorization paths.
 */

beforeEach(function () {
    $this->travelTo(Carbon\Carbon::parse('2026-03-15 10:00:00'));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Planet Fitness',
        'plaid_entity_id' => null,
    ]);
    $this->params = ['budget' => $this->budget, 'recurring_transaction' => $this->template];
});

function recurLinkedTransaction(Account $account, array $attributes = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'budget_id' => $account->budget_id,
        'account_id' => $account->id,
        'description' => 'Planet Fitness',
        'amount_in_cents' => -2499,
        'date' => '2026-03-01',
    ], $attributes));
}

function recurLinkedRule(RecurringTransactionTemplate $template, string $value): RecurringTransactionRule
{
    return RecurringTransactionRule::create([
        'recurring_transaction_template_id' => $template->id,
        'field' => 'description',
        'operator' => 'contains',
        'value' => $value,
        'is_case_sensitive' => false,
        'priority' => 1,
        'is_active' => true,
    ]);
}

test('an owner views the linked transactions and unlinks one', function () {
    $linked = recurLinkedTransaction($this->account, ['recurring_transaction_template_id' => $this->template->id]);
    $kept = recurLinkedTransaction($this->account, ['recurring_transaction_template_id' => $this->template->id, 'date' => '2026-02-01']);

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.rules.linked', $this->params))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Rules/Linked')
            ->where('recurringTransaction.id', $this->template->id)
            ->has('linkedTransactions.data', 2)
            ->where('linkedTransactions.data.0.id', $linked->id));

    $this->actingAs($this->user)
        ->from(route('recurring-transactions.rules.linked', $this->params))
        ->post(route('recurring-transactions.rules.unlink', $this->params), ['transaction_id' => $linked->id])
        ->assertRedirect(route('recurring-transactions.rules.linked', $this->params))
        ->assertSessionHas('message', 'Transaction unlinked successfully');

    expect($linked->fresh()->recurring_transaction_template_id)->toBeNull()
        ->and($kept->fresh()->recurring_transaction_template_id)->toBe($this->template->id);
});

test('diagnostics return the matching method, rules and linked transactions for the template', function () {
    $linked = recurLinkedTransaction($this->account, ['recurring_transaction_template_id' => $this->template->id]);
    $route = route('recurring-transactions.diagnostics', $this->params);

    $this->actingAs($this->user)->getJson($route)
        ->assertOk()
        ->assertJsonPath('template_id', $this->template->id)
        ->assertJsonPath('matching_method', 'description')
        ->assertJsonPath('linked_transactions.0.id', $linked->id)
        ->assertJsonPath('rules_summary', []);

    recurLinkedRule($this->template, 'fitness');
    $this->actingAs($this->user)->getJson($route)
        ->assertJsonPath('matching_method', 'rules')
        ->assertJsonPath('rules_summary.0.value', 'fitness');

    $this->template->update(['plaid_entity_id' => 'ENT-PF', 'plaid_entity_name' => 'Planet Fitness Inc']);
    $this->actingAs($this->user)->getJson($route)
        ->assertJsonPath('matching_method', 'plaid_entity_id')
        ->assertJsonPath('plaid_entity_name', 'Planet Fitness Inc');
});

test('test matching reports entity, rule and description matches with their method', function () {
    $this->template->update(['plaid_entity_id' => 'ENT-PF', 'plaid_entity_name' => 'Planet Fitness Inc']);
    recurLinkedRule($this->template, 'gym fee');

    $plaid = PlaidTransaction::create([
        'account_id' => $this->account->id,
        'plaid_transaction_id' => 'plaid-tx-pf',
        'plaid_account_id' => 'plaid-acct-1',
        'amount' => 24.99,
        'date' => '2026-03-10',
        'name' => 'PF CLUB 0042',
        'merchant_entity_id' => 'ENT-PF',
    ]);
    $byEntity = recurLinkedTransaction($this->account, ['description' => 'PF CLUB 0042', 'plaid_transaction_id' => $plaid->plaid_transaction_id, 'date' => '2026-03-10']);
    $byRules = recurLinkedTransaction($this->account, ['description' => 'GYM FEE MARCH', 'date' => '2026-03-05']);
    $byDescription = recurLinkedTransaction($this->account, ['description' => 'Planet Fitness', 'date' => '2026-03-01']);
    recurLinkedTransaction($this->account, ['description' => 'Coffee Shop', 'date' => '2026-02-20']);
    recurLinkedTransaction($this->account, ['description' => 'Planet Fitness', 'date' => '2025-10-01']); // older than 90 days

    $response = $this->actingAs($this->user)
        ->getJson(route('recurring-transactions.test-matching', $this->params))
        ->assertOk()
        ->assertJsonPath('total_tested', 4)
        ->assertJsonPath('matches_found', 3)
        ->assertJsonPath('template.has_entity_id', true)
        ->assertJsonPath('template.active_rules_count', 1);

    expect(collect($response->json('matches'))->pluck('match_method', 'transaction.id')->all())->toBe([
        $byEntity->id => 'entity_id',
        $byRules->id => 'rules',
        $byDescription->id => 'description_exact',
    ]);
});

test('unlinking a transaction that is not linked to this template returns 400', function () {
    $otherTemplate = RecurringTransactionTemplate::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);
    $elsewhere = recurLinkedTransaction($this->account, ['recurring_transaction_template_id' => $otherTemplate->id]);
    $unlinked = recurLinkedTransaction($this->account);

    foreach ([$elsewhere, $unlinked] as $transaction) {
        $this->actingAs($this->user)
            ->post(route('recurring-transactions.rules.unlink', $this->params), ['transaction_id' => $transaction->id])
            ->assertStatus(400);
    }

    expect($elsewhere->fresh()->recurring_transaction_template_id)->toBe($otherTemplate->id)
        ->and($unlinked->fresh()->recurring_transaction_template_id)->toBeNull();
});

test('a template with no linked transactions renders an empty linked list', function () {
    recurLinkedTransaction($this->account);

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.rules.linked', $this->params))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('RecurringTransactions/Rules/Linked')
            ->has('linkedTransactions.data', 0)
            ->where('linkedTransactions.total', 0));
});

test('unlinking a transaction from another budget is forbidden and leaves it linked', function () {
    $foreignBudget = Budget::factory()->create();
    $foreignAccount = Account::factory()->checking()->create(['budget_id' => $foreignBudget->id]);
    $foreign = recurLinkedTransaction($foreignAccount, ['recurring_transaction_template_id' => $this->template->id]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.unlink', $this->params), ['transaction_id' => $foreign->id])
        ->assertForbidden();

    expect($foreign->fresh()->recurring_transaction_template_id)->toBe($this->template->id);
});

test('another user cannot read diagnostics, test matching or the linked list', function (string $routeName) {
    recurLinkedTransaction($this->account, ['recurring_transaction_template_id' => $this->template->id]);

    $this->actingAs(User::factory()->create())
        ->getJson(route($routeName, $this->params))
        ->assertForbidden();
})->with([
    'recurring-transactions.diagnostics',
    'recurring-transactions.test-matching',
    'recurring-transactions.rules.linked',
]);
