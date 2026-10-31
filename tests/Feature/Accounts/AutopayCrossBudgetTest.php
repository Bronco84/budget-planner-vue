<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\User;

/*
 * ACCOUNT-003 other_users_account_forbidden: a card of another budget addressed
 * through the user's own budget URL is not found (scoped bindings), and its
 * autopay configuration is left untouched.
 */

test('autopay for a card from another budget under the users own budget url is not found', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $otherBudget = Budget::factory()->create(['user_id' => $user->id]);

    $connection = PlaidConnection::create([
        'budget_id' => $otherBudget->id,
        'plaid_item_id' => 'item-cross-budget',
        'institution_name' => 'Test Bank',
        'access_token' => 'access-sandbox-test',
        'status' => 'active',
    ]);
    $card = Account::factory()->creditCard()->create(['budget_id' => $otherBudget->id]);
    $checking = Account::factory()->checking()->create(['budget_id' => $otherBudget->id]);
    foreach ([[$card, 'credit', 'credit card'], [$checking, 'depository', 'checking']] as [$account, $type, $subtype]) {
        PlaidAccount::create([
            'plaid_connection_id' => $connection->id,
            'account_id' => $account->id,
            'plaid_account_id' => 'plaid-'.$account->id,
            'account_name' => $account->name,
            'account_type' => $type,
            'account_subtype' => $subtype,
            'last_statement_balance_cents' => $type === 'credit' ? 120000 : null,
        ]);
    }

    $this->actingAs($user)
        ->post(route('accounts.updateAutopay', [$budget, $card]), [
            'autopay_enabled' => true,
            'autopay_source_account_id' => $checking->id,
        ])
        ->assertNotFound();

    $fresh = $card->fresh();
    expect((bool) $fresh->autopay_enabled)->toBeFalse()
        ->and($fresh->autopay_source_account_id)->toBeNull();
});
