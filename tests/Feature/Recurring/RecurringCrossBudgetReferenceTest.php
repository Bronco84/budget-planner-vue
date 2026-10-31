<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringTransactionAnalysisService;

/*
 * RECUR-001/002/005/006: ids in request payloads (accounts, transactions) must
 * belong to the budget in the route, otherwise a user could attach their
 * templates to another user's accounts or link another user's transactions.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->create(['budget_id' => $this->budget->id]);

    $this->otherUser = User::factory()->create();
    $this->otherBudget = Budget::factory()->create(['user_id' => $this->otherUser->id]);
    $this->foreignAccount = Account::factory()->create(['budget_id' => $this->otherBudget->id]);
});

function recurringTemplatePayload(array $overrides = []): array
{
    return array_replace([
        'description' => 'Netflix',
        'amount' => -15.99,
        'category' => 'Entertainment',
        'frequency' => 'monthly',
        'start_date' => '2026-01-01',
        'day_of_month' => 5,
        'is_dynamic_amount' => false,
        'rules' => [],
    ], $overrides);
}

// --- RECUR-001: store ---

test('storing a template with an account from another budget is rejected and nothing is saved', function () {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), recurringTemplatePayload([
            'account_id' => $this->foreignAccount->id,
        ]))
        ->assertSessionHasErrors('account_id');

    expect(RecurringTransactionTemplate::count())->toBe(0);
});

test('storing a template with an autopay card from another budget is rejected and nothing is saved', function () {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), recurringTemplatePayload([
            'account_id' => $this->account->id,
            'linked_credit_card_account_id' => $this->foreignAccount->id,
        ]))
        ->assertSessionHasErrors('linked_credit_card_account_id');

    expect(RecurringTransactionTemplate::count())->toBe(0);
});

// --- RECUR-002: update ---

test('updating a template to reference accounts from another budget is rejected and the template is unchanged', function () {
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'linked_credit_card_account_id' => null,
    ]);

    $this->actingAs($this->user)
        ->patch(route('recurring-transactions.update', [$this->budget, $template]), recurringTemplatePayload([
            'account_id' => $this->foreignAccount->id,
            'linked_credit_card_account_id' => $this->foreignAccount->id,
        ]))
        ->assertSessionHasErrors(['account_id', 'linked_credit_card_account_id']);

    $template->refresh();
    expect($template->account_id)->toBe($this->account->id)
        ->and($template->linked_credit_card_account_id)->toBeNull();
});

// --- RECUR-005: bulk link ---

test('bulk linking rejects transaction ids from another budget and links nothing', function () {
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
    ]);
    $own = Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);
    $foreign = Transaction::factory()->create(['budget_id' => $this->otherBudget->id, 'account_id' => $this->foreignAccount->id]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.link-transactions', $this->budget), [
            'selections' => [
                ['template_id' => $template->id, 'transaction_ids' => [$own->id, $foreign->id]],
            ],
        ])
        ->assertSessionHasErrors('selections.0.transaction_ids.1');

    expect($foreign->fresh()->recurring_transaction_template_id)->toBeNull()
        ->and($own->fresh()->recurring_transaction_template_id)->toBeNull();
});

test('bulk linking rejects a template from another budget and links nothing', function () {
    $foreignTemplate = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->otherBudget->id,
        'account_id' => $this->foreignAccount->id,
    ]);
    $own = Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.link-transactions', $this->budget), [
            'selections' => [
                ['template_id' => $foreignTemplate->id, 'transaction_ids' => [$own->id]],
            ],
        ])
        ->assertSessionHasErrors('selections.0.template_id');

    expect($own->fresh()->recurring_transaction_template_id)->toBeNull();
});

test('bulk linking links the selected transactions of the budget', function () {
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
    ]);
    $own = Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.link-transactions', $this->budget), [
            'selections' => [
                ['template_id' => $template->id, 'transaction_ids' => [$own->id]],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Successfully linked 1 transaction to recurring templates.');

    expect($own->fresh()->recurring_transaction_template_id)->toBe($template->id);
});

// --- RECUR-006: analysis / create templates ---

function legacyAnalysisPattern(array $transactionIds): array
{
    return [
        'description' => 'Gym membership',
        'category' => 'Health',
        'amount_in_cents' => -4000,
        'frequency' => 'monthly',
        'suggested_start_date' => '2026-01-10',
        'day_of_month' => 10,
        'day_of_week' => null,
        'first_day_of_month' => null,
        'is_dynamic_amount' => false,
        'confidence_score' => 0.9,
        'transactions' => array_map(fn ($id) => ['id' => $id], $transactionIds),
    ];
}

test('creating templates from analysis does not link transactions from another budget', function () {
    $own = Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);
    $foreign = Transaction::factory()->create(['budget_id' => $this->otherBudget->id, 'account_id' => $this->foreignAccount->id]);

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.analysis.create-templates', $this->budget), [
            'account_id' => $this->account->id,
            'selected_patterns' => [legacyAnalysisPattern([$own->id, $foreign->id])],
        ])
        ->assertRedirect(route('recurring-transactions.index', $this->budget->id));

    $template = RecurringTransactionTemplate::where('budget_id', $this->budget->id)->sole();

    expect($own->fresh()->recurring_transaction_template_id)->toBe($template->id)
        ->and($foreign->fresh()->recurring_transaction_template_id)->toBeNull();
});

test('creating templates from analysis with an account from another budget is rejected and nothing is created', function () {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.analysis.create-templates', $this->budget), [
            'account_id' => $this->foreignAccount->id,
            'selected_patterns' => [legacyAnalysisPattern([])],
        ])
        ->assertSessionHasErrors('account_id');

    expect(RecurringTransactionTemplate::count())->toBe(0);
});

test('analyzing an account from another budget is rejected with a validation error', function () {
    $this->actingAs($this->user)
        ->post(route('recurring-transactions.analysis.analyze', $this->budget), [
            'account_id' => $this->foreignAccount->id,
        ])
        ->assertSessionHasErrors('account_id');
});

test('analysis failures show a generic message instead of the exception text', function () {
    $this->mock(RecurringTransactionAnalysisService::class)
        ->shouldReceive('analyzeAccount')
        ->andThrow(new RuntimeException('SQLSTATE[42S22] secret internals'));

    $this->actingAs($this->user)
        ->from(route('recurring-transactions.analysis', $this->budget))
        ->post(route('recurring-transactions.analysis.analyze', $this->budget), [
            'account_id' => $this->account->id,
        ])
        ->assertSessionHas('error', 'Analysis failed. Please try again.');
});

test('template creation failures show a generic message instead of the exception text', function () {
    $this->mock(RecurringTransactionAnalysisService::class)
        ->shouldReceive('createTemplatesFromPatterns')
        ->andThrow(new RuntimeException('SQLSTATE[42S22] secret internals'));

    $this->actingAs($this->user)
        ->from(route('recurring-transactions.analysis', $this->budget))
        ->post(route('recurring-transactions.analysis.create-templates', $this->budget), [
            'account_id' => $this->account->id,
            'selected_patterns' => [legacyAnalysisPattern([])],
        ])
        ->assertSessionHas('error', 'Template creation failed. Please try again.');
});
