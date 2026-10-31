<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidTransaction;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringTransactionService;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * RECUR-001/002/003/005/006: every way a transaction gets linked to a recurring
 * template records its link_source (manual, rule, entity, description,
 * analysis); rule re-evaluation only unlinks 'rule' links; deleting a template
 * reports how many transactions lose their link.
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
        'plaid_entity_id' => null,
    ]);
});

function linkSourceTransaction(string $description, array $attributes = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'budget_id' => test()->budget->id,
        'account_id' => test()->account->id,
        'description' => $description,
        'amount_in_cents' => -1549,
        'date' => '2026-03-01',
        'recurring_transaction_template_id' => null,
    ], $attributes));
}

function linkSourceRule(array $attributes = []): RecurringTransactionRule
{
    return RecurringTransactionRule::create(array_merge([
        'recurring_transaction_template_id' => test()->template->id,
        'field' => 'description',
        'operator' => 'contains',
        'value' => 'netflix',
        'is_case_sensitive' => false,
        'priority' => 1,
        'is_active' => true,
    ], $attributes));
}

test('a manual link survives rule re-evaluation while a stale rule link is unlinked', function () {
    $rule = linkSourceRule(['value' => 'netflix']);
    $linked = ['recurring_transaction_template_id' => $this->template->id];
    $manual = linkSourceTransaction('Netflix gift card', $linked + ['link_source' => Transaction::LINK_SOURCE_MANUAL]);
    $entity = linkSourceTransaction('Netflix basic', $linked + ['link_source' => Transaction::LINK_SOURCE_ENTITY]);
    $unknown = linkSourceTransaction('Netflix legacy', $linked + ['link_source' => null]);
    $ruleLinked = linkSourceTransaction('Netflix standard', $linked + ['link_source' => Transaction::LINK_SOURCE_RULE]);
    $newlyMatching = linkSourceTransaction('Netflix premium');

    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.rules.update', [
            'budget' => $this->budget, 'recurring_transaction' => $this->template, 'rule' => $rule,
        ]), ['field' => 'description', 'operator' => 'contains', 'value' => 'premium'])
        ->assertSessionHas('message', 'Rule updated successfully (1 transactions linked, 1 unlinked)');

    expect($manual->fresh()->recurring_transaction_template_id)->toBe($this->template->id)
        ->and($manual->fresh()->link_source)->toBe('manual')
        ->and($entity->fresh()->recurring_transaction_template_id)->toBe($this->template->id)
        ->and($unknown->fresh()->recurring_transaction_template_id)->toBe($this->template->id)
        ->and($ruleLinked->fresh()->recurring_transaction_template_id)->toBeNull()
        ->and($ruleLinked->fresh()->link_source)->toBeNull()
        ->and($newlyMatching->fresh()->recurring_transaction_template_id)->toBe($this->template->id)
        ->and($newlyMatching->fresh()->link_source)->toBe('rule');
});

test('applying rules records rule as the link source', function () {
    linkSourceRule();
    $match = linkSourceTransaction('NETFLIX.COM');

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.apply', ['budget' => $this->budget, 'recurring_transaction' => $this->template]))
        ->assertRedirect();

    expect($match->fresh())
        ->recurring_transaction_template_id->toBe($this->template->id)
        ->link_source->toBe('rule');
});

test('linking the source transaction of make recurring records a manual link', function () {
    $source = linkSourceTransaction('Gym');

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), [
            'description' => 'Gym',
            'amount' => -40,
            'account_id' => $this->account->id,
            'category' => 'Health',
            'frequency' => 'monthly',
            'start_date' => '2026-03-01',
            'day_of_month' => 1,
            'is_dynamic_amount' => false,
            'from_transaction_id' => $source->id,
        ])
        ->assertSessionHasNoErrors();

    expect($source->fresh()->link_source)->toBe('manual')
        ->and($source->fresh()->recurring_transaction_template_id)->not->toBeNull();
});

test('inline rules on a new template link existing matches with the rule source', function () {
    $match = linkSourceTransaction('SPOTIFY USA');

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), [
            'description' => 'Spotify',
            'amount' => -11.99,
            'account_id' => $this->account->id,
            'category' => 'Entertainment',
            'frequency' => 'monthly',
            'start_date' => '2026-03-01',
            'day_of_month' => 1,
            'is_dynamic_amount' => false,
            'rules' => [['field' => 'description', 'operator' => 'contains', 'value' => 'spotify']],
        ])
        ->assertSessionHasNoErrors();

    expect($match->fresh()->link_source)->toBe('rule');
});

test('bulk linking selected suggestions records a manual link', function () {
    $transaction = linkSourceTransaction('Netflix', ['is_plaid_imported' => true]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.link-transactions', $this->budget), [
            'selections' => [['template_id' => $this->template->id, 'transaction_ids' => [$transaction->id]]],
        ])
        ->assertRedirect();

    expect($transaction->fresh()->link_source)->toBe('manual');
});

test('templates created from analysis link their pattern transactions with the analysis source', function () {
    $transaction = linkSourceTransaction('Gym membership');

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.analysis.create-templates', $this->budget), [
            'account_id' => $this->account->id,
            'selected_patterns' => [[
                'description' => 'Gym membership',
                'category' => 'Health',
                'amount_type' => 'static',
                'amount' => -40,
                'frequency' => 'monthly',
                'day_of_month' => 10,
                'start_date' => '2026-01-10',
                'transaction_ids' => [$transaction->id],
            ]],
        ])
        ->assertSessionHas('message');

    expect($transaction->fresh()->link_source)->toBe('analysis');
});

test('plaid auto-linking records whether the entity, a rule or the description matched', function () {
    $entityTemplate = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Zzzz no description match',
        'plaid_entity_id' => 'ENT-777',
    ]);
    $ruleTemplate = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Qqqq no description match',
        'plaid_entity_id' => null,
    ]);
    RecurringTransactionRule::create([
        'recurring_transaction_template_id' => $ruleTemplate->id,
        'field' => 'description', 'operator' => 'contains', 'value' => 'water bill',
        'is_case_sensitive' => false, 'priority' => 1, 'is_active' => true,
    ]);

    PlaidTransaction::create([
        'account_id' => $this->account->id,
        'plaid_transaction_id' => 'plaid-entity-1',
        'plaid_account_id' => 'plaid-acct-1',
        'amount' => 9.99,
        'date' => '2026-03-10',
        'name' => 'Unrelated merchant',
        'counterparties' => [['name' => 'Merchant', 'entity_id' => 'ENT-777', 'type' => 'merchant']],
    ]);
    $byEntity = linkSourceTransaction('Unrelated merchant', ['plaid_transaction_id' => 'plaid-entity-1', 'date' => '2026-03-10']);
    $byRule = linkSourceTransaction('City water bill', ['date' => '2026-03-10']);
    $byDescription = linkSourceTransaction('Netflix monthly', ['date' => '2026-03-10']);

    app(RecurringTransactionService::class)->autoLinkTransactionsForAccount($this->account);

    expect($byEntity->fresh()->recurring_transaction_template_id)->toBe($entityTemplate->id)
        ->and($byEntity->fresh()->link_source)->toBe('entity')
        ->and($byRule->fresh()->recurring_transaction_template_id)->toBe($ruleTemplate->id)
        ->and($byRule->fresh()->link_source)->toBe('rule')
        ->and($byDescription->fresh()->recurring_transaction_template_id)->toBe($this->template->id)
        ->and($byDescription->fresh()->link_source)->toBe('description');
});

test('unlinking a transaction clears its link source', function () {
    $transaction = linkSourceTransaction('Netflix', [
        'recurring_transaction_template_id' => $this->template->id,
        'link_source' => Transaction::LINK_SOURCE_MANUAL,
    ]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.unlink', ['budget' => $this->budget, 'recurring_transaction' => $this->template]), [
            'transaction_id' => $transaction->id,
        ])
        ->assertRedirect();

    expect($transaction->fresh())
        ->recurring_transaction_template_id->toBeNull()
        ->link_source->toBeNull();
});

test('the delete confirmation knows how many transactions will lose their link', function () {
    linkSourceTransaction('Netflix one', ['recurring_transaction_template_id' => $this->template->id, 'link_source' => 'rule']);
    linkSourceTransaction('Netflix two', ['recurring_transaction_template_id' => $this->template->id, 'link_source' => 'manual']);
    linkSourceTransaction('Unrelated');

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.index', $this->budget))
        ->assertInertia(fn (Assert $page) => $page
            ->where('recurringTransactions.0.id', $this->template->id)
            ->where('recurringTransactions.0.transactions_count', 2));

    $this->actingAs($this->user)
        ->get(route('recurring-transactions.edit', ['budget' => $this->budget, 'recurring_transaction' => $this->template]))
        ->assertInertia(fn (Assert $page) => $page->where('recurringTransaction.transactions_count', 2));
});

test('deleting a template unlinks its transactions and clears their link source', function () {
    $linked = linkSourceTransaction('Netflix one', ['recurring_transaction_template_id' => $this->template->id, 'link_source' => 'rule']);

    $this->actingAs($this->user)
        ->delete(route('recurring-transactions.destroy', ['budget' => $this->budget, 'recurring_transaction' => $this->template]))
        ->assertRedirect(route('recurring-transactions.index', $this->budget));

    expect($linked->fresh())
        ->not->toBeNull()
        ->recurring_transaction_template_id->toBeNull()
        ->link_source->toBeNull();
});

test('choosing a template on the transaction form records a manual link and keeps it while unchanged', function () {
    $transaction = linkSourceTransaction('Netflix');
    $payload = [
        'account_id' => $this->account->id,
        'description' => 'Netflix',
        'amount' => -15.49,
        'date' => '2026-03-01',
        'category' => 'Entertainment',
        'recurring_transaction_template_id' => $this->template->id,
    ];

    $this->actingAs($this->user)
        ->patch(route('budget.transaction.update', [$this->budget, $transaction]), $payload)
        ->assertSessionHasNoErrors();

    expect($transaction->fresh()->link_source)->toBe('manual');

    // Re-saving with the same template keeps an existing source.
    $transaction->forceFill(['link_source' => Transaction::LINK_SOURCE_RULE])->saveQuietly();
    $this->actingAs($this->user)
        ->patch(route('budget.transaction.update', [$this->budget, $transaction]), array_merge($payload, ['description' => 'Netflix renamed']))
        ->assertSessionHasNoErrors();

    expect($transaction->fresh()->link_source)->toBe('rule');

    $this->actingAs($this->user)
        ->patch(route('budget.transaction.update', [$this->budget, $transaction]), array_merge($payload, ['recurring_transaction_template_id' => null]))
        ->assertSessionHasNoErrors();

    expect($transaction->fresh())
        ->recurring_transaction_template_id->toBeNull()
        ->link_source->toBeNull();
});
