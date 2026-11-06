<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Property;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
 * PR-095: Nova's relation dropdowns offer every budget's records, so an admin
 * could save a transaction whose account (or recurring template) belongs to a
 * different budget, and the row then counted in one budget's lists and another
 * budget's balances. The app only ever pairs records of one budget; Nova now
 * refuses a pairing across budgets the same way.
 */

function novaCrossBudgetAdmin(): User
{
    $admin = User::factory()->create();
    // is_admin is deliberately not mass-assignable.
    $admin->is_admin = true;
    $admin->save();

    return $admin;
}

/**
 * The payload Nova's edit form would send for the record as it stands, built
 * from the resource's own update fields, with the given fields changed.
 */
function novaCrossBudgetUpdatePayload(TestResponse $updateFields, array $overrides = []): array
{
    $payload = [];

    foreach ($updateFields->json('fields') as $field) {
        if (str_ends_with($field['component'] ?? '', 'has-many-field') || ($field['attribute'] ?? null) === 'id') {
            continue;
        }

        $value = array_key_exists('belongsToId', $field) ? $field['belongsToId'] : $field['value'];
        $payload[$field['attribute']] = match (true) {
            is_bool($value) => (int) $value,
            $value === null => '',
            default => $value,
        };
    }

    return array_merge($payload, $overrides);
}

function novaCrossBudgetPut(User $admin, string $resource, int $id, array $overrides): TestResponse
{
    $fields = test()->actingAs($admin)->getJson("/nova-api/{$resource}/{$id}/update-fields")->assertOk();

    return test()->actingAs($admin)->putJson("/nova-api/{$resource}/{$id}", novaCrossBudgetUpdatePayload($fields, $overrides));
}

/**
 * @return array{0: Budget, 1: Account, 2: Budget, 3: Account}
 */
function novaCrossBudgetTwoBudgets(User $admin): array
{
    $budget = Budget::factory()->create(['user_id' => $admin->id]);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $otherBudget = Budget::factory()->create();
    $otherAccount = Account::factory()->checking()->create(['budget_id' => $otherBudget->id]);

    return [$budget, $account, $otherBudget, $otherAccount];
}

function novaCrossBudgetCreateTransactionPayload(Budget $budget, Account $account, array $overrides = []): array
{
    return array_merge([
        'budget' => $budget->id,
        'account' => $account->id,
        'description' => 'Groceries',
        'category' => '',
        'amount_in_cents' => -4200,
        'date' => now()->toDateString(),
        'import_source' => 'manual',
        'is_plaid_imported' => 0,
        'is_reconciled' => 0,
        'notes' => '',
        'plaidTransaction' => '',
        'recurringTemplate' => '',
    ], $overrides);
}

test('nova refuses to create a transaction with an account from another budget', function () {
    $admin = novaCrossBudgetAdmin();
    [$budget, , , $otherAccount] = novaCrossBudgetTwoBudgets($admin);

    $this->actingAs($admin)
        ->postJson('/nova-api/transactions', novaCrossBudgetCreateTransactionPayload($budget, $otherAccount))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account');

    expect(Transaction::count())->toBe(0);
});

test('nova creates a transaction with an account from the same budget', function () {
    $admin = novaCrossBudgetAdmin();
    [$budget, $account] = novaCrossBudgetTwoBudgets($admin);

    $this->actingAs($admin)
        ->postJson('/nova-api/transactions', novaCrossBudgetCreateTransactionPayload($budget, $account))
        ->assertCreated();

    expect(Transaction::sole()->only('budget_id', 'account_id'))
        ->toBe(['budget_id' => $budget->id, 'account_id' => $account->id]);
});

test('nova refuses to move a transaction to an account from another budget and changes nothing', function () {
    $admin = novaCrossBudgetAdmin();
    [$budget, $account, , $otherAccount] = novaCrossBudgetTwoBudgets($admin);
    $transaction = Transaction::factory()->create([
        'budget_id' => $budget->id,
        'account_id' => $account->id,
        'amount_in_cents' => -1200,
    ]);

    novaCrossBudgetPut($admin, 'transactions', $transaction->id, ['account' => $otherAccount->id, 'amount_in_cents' => -9900])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account');

    expect($transaction->fresh()->only('budget_id', 'account_id', 'amount_in_cents'))
        ->toBe(['budget_id' => $budget->id, 'account_id' => $account->id, 'amount_in_cents' => -1200]);
});

test('nova refuses to move a transaction to another budget while keeping its account', function () {
    $admin = novaCrossBudgetAdmin();
    [$budget, $account, $otherBudget] = novaCrossBudgetTwoBudgets($admin);
    $transaction = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);

    novaCrossBudgetPut($admin, 'transactions', $transaction->id, ['budget' => $otherBudget->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account');

    expect($transaction->fresh()->budget_id)->toBe($budget->id);
});

test('nova moves a transaction to another account in the same budget', function () {
    $admin = novaCrossBudgetAdmin();
    [$budget, $account] = novaCrossBudgetTwoBudgets($admin);
    $savings = Account::factory()->savings()->create(['budget_id' => $budget->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);

    novaCrossBudgetPut($admin, 'transactions', $transaction->id, ['account' => $savings->id])
        ->assertOk();

    expect($transaction->fresh()->account_id)->toBe($savings->id);
});

test('nova refuses a transaction recurring template from another budget', function () {
    $admin = novaCrossBudgetAdmin();
    [$budget, $account, $otherBudget, $otherAccount] = novaCrossBudgetTwoBudgets($admin);
    $otherTemplate = RecurringTransactionTemplate::factory()->create(['budget_id' => $otherBudget->id, 'account_id' => $otherAccount->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);

    novaCrossBudgetPut($admin, 'transactions', $transaction->id, ['recurringTemplate' => $otherTemplate->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recurringTemplate');

    expect($transaction->fresh()->recurring_transaction_template_id)->toBeNull();
});

test('nova refuses a recurring template account from another budget', function () {
    $admin = novaCrossBudgetAdmin();
    [$budget, $account, , $otherAccount] = novaCrossBudgetTwoBudgets($admin);
    $template = RecurringTransactionTemplate::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);

    novaCrossBudgetPut($admin, 'recurring-transaction-templates', $template->id, ['account' => $otherAccount->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account');

    expect($template->fresh()->account_id)->toBe($account->id);
});

test('nova refuses an account autopay source or property from another budget', function () {
    $admin = novaCrossBudgetAdmin();
    [$budget, $account, $otherBudget, $otherAccount] = novaCrossBudgetTwoBudgets($admin);
    $card = Account::factory()->create(['budget_id' => $budget->id]);
    $otherProperty = Property::create(['budget_id' => $otherBudget->id, 'name' => 'Lake house', 'type' => 'property', 'current_value_cents' => 25000000]);

    novaCrossBudgetPut($admin, 'accounts', $card->id, ['autopaySourceAccount' => $otherAccount->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('autopaySourceAccount');

    novaCrossBudgetPut($admin, 'accounts', $card->id, ['property' => $otherProperty->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('property');

    novaCrossBudgetPut($admin, 'accounts', $card->id, ['autopaySourceAccount' => $account->id])
        ->assertOk();

    expect($card->fresh()->only('autopay_source_account_id', 'property_id'))
        ->toBe(['autopay_source_account_id' => $account->id, 'property_id' => null]);
});

test('nova refuses a budget starting balance account from another budget', function () {
    $admin = novaCrossBudgetAdmin();
    [$budget, $account, , $otherAccount] = novaCrossBudgetTwoBudgets($admin);

    novaCrossBudgetPut($admin, 'budgets', $budget->id, ['startingBalanceAccount' => $otherAccount->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('startingBalanceAccount');

    novaCrossBudgetPut($admin, 'budgets', $budget->id, ['startingBalanceAccount' => $account->id])
        ->assertOk();

    expect($budget->fresh()->starting_balance_account_id)->toBe($account->id);
});
