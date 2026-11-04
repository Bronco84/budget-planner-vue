<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringTransactionService;

/*
 * A pending transaction the bank dropped (removed_by_bank_at) never happened:
 * like totals and reports, recurring templates must not average it into a
 * dynamic amount, link it or suggest it (Transaction::scopeCountedInTotals).
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Electric bill',
        'amount_in_cents' => -5000,
        'is_dynamic_amount' => true,
        'plaid_entity_id' => null,
        'min_amount' => null,
        'max_amount' => null,
    ]);
    $this->service = app(RecurringTransactionService::class);
});

function removedByBankRule(RecurringTransactionTemplate $template): void
{
    RecurringTransactionRule::create([
        'recurring_transaction_template_id' => $template->id,
        'field' => 'description',
        'operator' => 'contains',
        'value' => 'power co',
        'is_case_sensitive' => false,
        'priority' => 1,
        'is_active' => true,
    ]);
}

function removedByBankTransaction(array $attributes = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'budget_id' => test()->budget->id,
        'account_id' => test()->account->id,
        'description' => 'POWER CO payment',
        'amount_in_cents' => -10000,
        'date' => now()->subMonth()->toDateString(),
        'recurring_transaction_template_id' => null,
    ], $attributes));
}

test('a rule-matched transaction removed by the bank is left out of the dynamic amount', function () {
    removedByBankRule($this->template);
    removedByBankTransaction(['amount_in_cents' => -10000]);
    removedByBankTransaction(['amount_in_cents' => -12000, 'date' => now()->subMonths(2)->toDateString()]);
    removedByBankTransaction(['amount_in_cents' => -90000, 'removed_by_bank_at' => now()]);

    expect($this->service->projectedAmount($this->template->fresh()))->toBe(-11000)
        ->and($this->service->getAmountAnalysis($this->template->fresh())['projected_amount'])->toBe(-11000);
});

test('a linked transaction removed by the bank is left out of the dynamic amount', function () {
    removedByBankTransaction(['amount_in_cents' => -10000, 'recurring_transaction_template_id' => $this->template->id]);
    removedByBankTransaction(['amount_in_cents' => -90000, 'recurring_transaction_template_id' => $this->template->id, 'removed_by_bank_at' => now()]);

    expect($this->service->projectedAmount($this->template->fresh()))->toBe(-10000)
        ->and($this->service->projectedAmount($this->template->fresh()->load('transactions')))->toBe(-10000)
        ->and($this->service->getAmountAnalysis($this->template->fresh())['projected_amount'])->toBe(-10000);
});

test('rules never link a transaction removed by the bank and re-evaluation leaves its link alone', function () {
    removedByBankRule($this->template);
    $posted = removedByBankTransaction();
    $dropped = removedByBankTransaction(['removed_by_bank_at' => now()]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.rules.apply', ['budget' => $this->budget, 'recurring_transaction' => $this->template]))
        ->assertSessionHas('message', 'Successfully linked 1 transactions to this recurring transaction.');

    expect($posted->fresh()->recurring_transaction_template_id)->toBe($this->template->id)
        ->and($dropped->fresh()->recurring_transaction_template_id)->toBeNull()
        ->and($this->service->linkMatchingTransactionsByRules($this->template))->toBe(0);

    // A rule link made before the bank dropped the row is kept for the user to keep or delete.
    $dropped->update(['recurring_transaction_template_id' => $this->template->id, 'link_source' => Transaction::LINK_SOURCE_RULE]);

    expect($this->service->reevaluateTransactionLinks($this->template))->toBe(['unlinked' => 0, 'linked' => 0])
        ->and($dropped->fresh()->recurring_transaction_template_id)->toBe($this->template->id);
});

test('description matching and find matching never offer a transaction removed by the bank', function () {
    $posted = removedByBankTransaction(['description' => 'Electric bill', 'plaid_transaction_id' => 'plaid-posted']);
    $dropped = removedByBankTransaction(['description' => 'Electric bill', 'plaid_transaction_id' => 'plaid-dropped', 'removed_by_bank_at' => now()]);

    $matches = $this->service->findMatchingTransactionsForTemplates($this->budget);
    $suggested = collect($matches['templates_with_matches'])->flatMap(fn ($t) => collect($t['matching_transactions'])->pluck('id'));

    expect($suggested->all())->toBe([$posted->id]);

    expect($this->service->autoLinkTransactionsForAccount($this->account, 90)['total_linked'])->toBe(1)
        ->and($posted->fresh()->recurring_transaction_template_id)->toBe($this->template->id)
        ->and($dropped->fresh()->recurring_transaction_template_id)->toBeNull();
});
