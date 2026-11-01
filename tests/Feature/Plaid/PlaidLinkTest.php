<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\User;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-002: link an existing budget account to Plaid (plaid.store), including the
 * re-link path for an account whose PlaidAccount points at a stale connection.
 */

beforeEach(function () {
    $this->travelTo(Carbon\Carbon::parse('2026-03-15 10:00:00'));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->plaid = FakePlaidApi::install();

    $this->plaid
        ->on('/item/public_token/exchange', ['access_token' => 'access-sandbox-new', 'item_id' => 'item-new'])
        ->on('/institutions/get_by_id', ['institution' => [
            'institution_id' => 'ins_109508', 'name' => 'First Platypus Bank',
            'logo' => 'base64-logo', 'url' => 'https://platypus.example',
        ]])
        ->on('/accounts/get', ['accounts' => [[
            'account_id' => 'acc-checking-new', 'name' => 'Everyday Checking', 'mask' => '0001',
            'type' => 'depository', 'subtype' => 'checking',
            'balances' => ['current' => 1234.56, 'available' => 1200.00],
        ]]])
        ->on('/transactions/get', ['transactions' => []]);
});

function plaidLinkPayload(): array
{
    return [
        'public_token' => 'public-sandbox-abc',
        'metadata' => [
            'institution' => ['name' => 'First Platypus Bank', 'institution_id' => 'ins_109508'],
            'item' => ['id' => 'item-new'],
            'account' => ['id' => 'acc-checking-new'],
        ],
    ];
}

test('re-linking an account with a stale Plaid link creates a connection from the Link metadata', function () {
    $stale = PlaidConnection::create([
        'budget_id' => $this->budget->id,
        'plaid_item_id' => 'item-old',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-sandbox-old',
        'status' => PlaidConnection::STATUS_ERROR,
    ]);
    $plaidAccount = PlaidAccount::create([
        'plaid_connection_id' => $stale->id,
        'account_id' => $this->account->id,
        'plaid_account_id' => 'acc-checking-old',
        'account_name' => 'Checking',
        'account_type' => 'depository',
        'account_subtype' => 'checking',
        'current_balance_cents' => 0,
    ]);

    $this->actingAs($this->user)
        ->post(route('plaid.store', [$this->budget, $this->account]), plaidLinkPayload())
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionMissing('error')
        ->assertSessionHas('message', 'Account re-linked to Plaid successfully.');

    $connection = PlaidConnection::where('plaid_item_id', 'item-new')->sole();
    expect($connection)
        ->budget_id->toBe($this->budget->id)
        ->institution_id->toBe('ins_109508')
        ->access_token->toBe('access-sandbox-new')
        ->status->toBe(PlaidConnection::STATUS_ACTIVE)
        ->and($plaidAccount->fresh())
        ->plaid_connection_id->toBe($connection->id)
        ->plaid_account_id->toBe('acc-checking-new')
        ->current_balance_cents->toBe(123456)
        ->and(PlaidAccount::count())->toBe(1);
});

test('linking an account reuses the active connection for the same Plaid item', function () {
    $existing = PlaidConnection::create([
        'budget_id' => $this->budget->id,
        'plaid_item_id' => 'item-new',
        'institution_id' => 'ins_109508',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-sandbox-new',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);

    $this->actingAs($this->user)
        ->post(route('plaid.store', [$this->budget, $this->account]), plaidLinkPayload())
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('message', 'Account linked successfully to existing First Platypus Bank connection.');

    expect(PlaidConnection::count())->toBe(1)
        ->and(PlaidAccount::where('account_id', $this->account->id)->sole()->plaid_connection_id)->toBe($existing->id);
});

test('linking a new Plaid item at an institution already connected stores it as its own connection', function () {
    $older = PlaidConnection::create([
        'budget_id' => $this->budget->id,
        'plaid_item_id' => 'item-old',
        'institution_id' => 'ins_109508',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-sandbox-old',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);

    $this->actingAs($this->user)
        ->post(route('plaid.store', [$this->budget, $this->account]), plaidLinkPayload())
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionMissing('error')
        ->assertSessionHas('message', 'Account linked to Plaid successfully.');

    $connection = PlaidConnection::where('plaid_item_id', 'item-new')->sole();
    expect($connection->id)->not->toBe($older->id)
        ->and($connection->access_token)->toBe('access-sandbox-new')
        ->and($older->fresh()->access_token)->toBe('access-sandbox-old')
        ->and($older->plaidAccounts()->count())->toBe(0)
        ->and(PlaidAccount::where('account_id', $this->account->id)->sole()->plaid_connection_id)->toBe($connection->id)
        ->and($this->plaid->paths())->not->toContain('/item/remove');
});

test('a link with no Plaid item id never joins another connection at the same institution', function () {
    $older = PlaidConnection::create([
        'budget_id' => $this->budget->id,
        'plaid_item_id' => 'item-old',
        'institution_id' => 'ins_109508',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-sandbox-old',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
    $payload = plaidLinkPayload();
    $payload['metadata']['item'] = ['id' => null];

    $this->actingAs($this->user)
        ->post(route('plaid.store', [$this->budget, $this->account]), $payload)
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('message', 'Account linked to Plaid successfully.');

    $connection = PlaidAccount::where('account_id', $this->account->id)->sole()->plaidConnection;
    expect($connection->id)->not->toBe($older->id)
        ->and($connection->access_token)->toBe('access-sandbox-new');
});

test('re-linking a stale account at an institution connected under another item creates its own connection', function () {
    $other = PlaidConnection::create([
        'budget_id' => $this->budget->id,
        'plaid_item_id' => 'item-other',
        'institution_id' => 'ins_109508',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-sandbox-other',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
    $stale = PlaidConnection::create([
        'budget_id' => $this->budget->id,
        'plaid_item_id' => 'item-old',
        'institution_id' => 'ins_109508',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-sandbox-old',
        'status' => PlaidConnection::STATUS_ERROR,
    ]);
    $plaidAccount = PlaidAccount::create([
        'plaid_connection_id' => $stale->id,
        'account_id' => $this->account->id,
        'plaid_account_id' => 'acc-checking-old',
        'account_name' => 'Checking',
        'account_type' => 'depository',
        'account_subtype' => 'checking',
        'current_balance_cents' => 0,
    ]);

    $this->actingAs($this->user)
        ->post(route('plaid.store', [$this->budget, $this->account]), plaidLinkPayload())
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('message', 'Account re-linked to Plaid successfully.');

    $connection = PlaidConnection::where('plaid_item_id', 'item-new')->sole();
    expect($plaidAccount->fresh()->plaid_connection_id)->toBe($connection->id)
        ->and($connection->access_token)->toBe('access-sandbox-new')
        ->and($other->fresh()->access_token)->toBe('access-sandbox-other');
});

test('a link that fails before the new item is stored removes that item at Plaid', function () {
    $this->plaid
        ->on('/accounts/get', ['accounts' => []])
        ->on('/item/remove', ['request_id' => 'req-remove']);

    $this->actingAs($this->user)
        ->from(route('plaid.link', [$this->budget, $this->account]))
        ->post(route('plaid.store', [$this->budget, $this->account]), plaidLinkPayload())
        ->assertRedirect(route('plaid.link', [$this->budget, $this->account]))
        ->assertSessionHas('error');

    expect(PlaidConnection::count())->toBe(0)
        ->and($this->plaid->bodyFor('/item/remove'))->toBe(['access_token' => 'access-sandbox-new']);
});

test('a failed link for an item an existing connection holds leaves that item in place', function () {
    PlaidConnection::create([
        'budget_id' => $this->budget->id,
        'plaid_item_id' => 'item-new',
        'institution_id' => 'ins_109508',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-sandbox-new',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
    $this->plaid->on('/accounts/get', ['accounts' => []]);

    $this->actingAs($this->user)
        ->from(route('plaid.link', [$this->budget, $this->account]))
        ->post(route('plaid.store', [$this->budget, $this->account]), plaidLinkPayload())
        ->assertSessionHas('error');

    expect($this->plaid->paths())->not->toContain('/item/remove')
        ->and(PlaidConnection::sole()->status)->toBe(PlaidConnection::STATUS_ACTIVE);
});
