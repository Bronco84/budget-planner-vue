<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionRule;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringTransactionService;
use Illuminate\Support\Facades\DB;

/*
 * RECUR-003: rule-based matching (dynamic amounts, applying and re-evaluating
 * rules) only considers the template's account within the last
 * RULE_MATCH_LOOKBACK_MONTHS months, loaded without the raw Plaid rows, so it
 * stays bounded as Plaid history grows.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->otherAccount = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Electric bill',
        'amount_in_cents' => -5000,
        'is_dynamic_amount' => true,
        'plaid_entity_id' => null,
    ]);
    RecurringTransactionRule::create([
        'recurring_transaction_template_id' => $this->template->id,
        'field' => 'description',
        'operator' => 'contains',
        'value' => 'power co',
        'is_case_sensitive' => false,
        'priority' => 1,
        'is_active' => true,
    ]);
    $this->service = app(RecurringTransactionService::class);
});

function windowTransaction(array $attributes): Transaction
{
    return Transaction::factory()->create(array_merge([
        'budget_id' => test()->budget->id,
        'account_id' => test()->account->id,
        'description' => 'POWER CO payment',
        'date' => now()->subMonth()->toDateString(),
        'recurring_transaction_template_id' => null,
    ], $attributes));
}

test('dynamic amounts average only in-window matches on the template account', function () {
    windowTransaction(['amount_in_cents' => -10000, 'date' => now()->subMonths(2)->toDateString()]);
    windowTransaction(['amount_in_cents' => -12000, 'date' => now()->subMonth()->toDateString()]);
    // Older than the lookback window, and a match on another account: not considered.
    windowTransaction(['amount_in_cents' => -90000, 'date' => now()->subMonths(13)->toDateString()]);
    windowTransaction(['amount_in_cents' => -80000, 'account_id' => $this->otherAccount->id]);

    expect($this->service->projectedAmount($this->template->fresh()))->toBe(-11000);
});

test('rule matching does not load raw plaid transactions', function () {
    windowTransaction(['amount_in_cents' => -10000, 'plaid_transaction_id' => 'plaid-txn-window-1']);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->service->projectedAmount($this->template->fresh());

    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'plaid_transactions')))->toBeEmpty();
});

test('applying rules links only in-window matches on the template account', function () {
    $recent = windowTransaction(['amount_in_cents' => -10000]);
    $old = windowTransaction(['amount_in_cents' => -10000, 'date' => now()->subMonths(13)->toDateString()]);
    $elsewhere = windowTransaction(['amount_in_cents' => -10000, 'account_id' => $this->otherAccount->id]);

    expect($this->service->linkMatchingTransactionsByRules($this->template))->toBe(1)
        ->and($recent->fresh()->recurring_transaction_template_id)->toBe($this->template->id)
        ->and($recent->fresh()->link_source)->toBe(Transaction::LINK_SOURCE_RULE)
        ->and($old->fresh()->recurring_transaction_template_id)->toBeNull()
        ->and($elsewhere->fresh()->recurring_transaction_template_id)->toBeNull();
});

test('re-evaluation keeps rule links outside the window and still unlinks in-window non-matches', function () {
    $linked = fn (array $attributes) => windowTransaction(array_merge([
        'amount_in_cents' => -10000,
        'recurring_transaction_template_id' => $this->template->id,
        'link_source' => Transaction::LINK_SOURCE_RULE,
    ], $attributes));

    $old = $linked(['date' => now()->subMonths(13)->toDateString()]);
    $elsewhere = $linked(['account_id' => $this->otherAccount->id]);
    $stale = $linked(['description' => 'Water utility']);
    $match = $linked([]);

    expect($this->service->reevaluateTransactionLinks($this->template))->toBe(['unlinked' => 1, 'linked' => 0])
        ->and($old->fresh()->recurring_transaction_template_id)->toBe($this->template->id)
        ->and($elsewhere->fresh()->recurring_transaction_template_id)->toBe($this->template->id)
        ->and($match->fresh()->recurring_transaction_template_id)->toBe($this->template->id)
        ->and($stale->fresh()->recurring_transaction_template_id)->toBeNull();
});
