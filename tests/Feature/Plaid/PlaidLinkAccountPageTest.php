<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-002: the Bank Connection page (plaid.link) and the remaining plaid.store
 * paths: a brand-new connection, re-linking onto an existing active connection,
 * validation, an account missing from Plaid's response and another user's budget.
 */

beforeEach(function () {
    $this->travelTo(Carbon\Carbon::parse('2026-03-15 10:00:00'));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->plaid = FakePlaidApi::install();

    $this->plaid
        ->on('/link/token/create', ['link_token' => 'link-sandbox-page', 'expiration' => '2026-03-15T14:00:00Z'])
        ->on('/item/public_token/exchange', ['access_token' => 'access-sandbox-fresh', 'item_id' => 'item-fresh'])
        ->on('/institutions/get_by_id', ['institution' => [
            'institution_id' => 'ins_3', 'name' => 'Tartan Bank',
            'logo' => 'tartan-logo', 'url' => 'https://tartan.example',
        ]])
        ->on('/accounts/get', ['accounts' => [[
            'account_id' => 'acc-fresh', 'name' => 'Plaid Checking', 'mask' => '0000',
            'type' => 'depository', 'subtype' => 'checking',
            'balances' => ['current' => 410.25, 'available' => 400.00],
        ]]])
        ->on('/transactions/get', ['transactions' => []]);
});

function plaidLinkPagePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'public_token' => 'public-sandbox-fresh',
        'metadata' => [
            'institution' => ['name' => 'Tartan Bank', 'institution_id' => 'ins_3'],
            'item' => ['id' => 'item-fresh'],
            'account' => ['id' => 'acc-fresh'],
        ],
    ], $overrides);
}

function plaidLinkPageConnection(Budget $budget, array $attributes = []): PlaidConnection
{
    return PlaidConnection::create(array_merge([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-existing',
        'institution_id' => 'ins_3',
        'institution_name' => 'Tartan Bank',
        'access_token' => 'access-sandbox-existing',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ], $attributes));
}

function plaidLinkPageLinkAccount(PlaidConnection $connection, Account $account, string $plaidId = 'acc-old'): PlaidAccount
{
    return PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => $account->id,
        'plaid_account_id' => $plaidId,
        'account_name' => 'Checking',
        'account_type' => 'depository',
        'account_subtype' => 'checking',
        'current_balance_cents' => 0,
    ]);
}

test('the bank connection page renders a link token for an unlinked account', function () {
    $this->actingAs($this->user)
        ->get(route('plaid.link', [$this->budget, $this->account]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Plaid/Link')
            ->where('budget.id', $this->budget->id)
            ->where('account.id', $this->account->id)
            ->where('linkToken', 'link-sandbox-page')
            ->where('isLinked', false)
            ->where('plaidAccount', null)
            ->where('connectionMetadata', null));

    expect($this->plaid->bodyFor('/link/token/create'))->not->toHaveKey('access_token');
});

test('the bank connection page shows connection metadata for a linked account', function () {
    $connection = plaidLinkPageConnection($this->budget, [
        'status' => PlaidConnection::STATUS_ERROR,
        'error_message' => 'ITEM_LOGIN_REQUIRED',
        'last_sync_at' => '2026-03-14 08:30:00',
        'institution_logo' => 'tartan-logo',
        'institution_url' => 'https://tartan.example',
    ]);
    plaidLinkPageLinkAccount($connection, $this->account);

    $this->actingAs($this->user)
        ->get(route('plaid.link', [$this->budget, $this->account]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Plaid/Link')
            ->where('isLinked', true)
            ->where('plaidAccount.plaid_account_id', 'acc-old')
            ->where('connectionMetadata.status', PlaidConnection::STATUS_ERROR)
            ->where('connectionMetadata.institution_name', 'Tartan Bank')
            ->where('connectionMetadata.institution_id', 'ins_3')
            ->where('connectionMetadata.institution_logo', 'tartan-logo')
            ->where('connectionMetadata.institution_url', 'https://tartan.example')
            ->where('connectionMetadata.error_message', 'ITEM_LOGIN_REQUIRED')
            ->where('connectionMetadata.last_sync_at', fn ($value) => Carbon\Carbon::parse($value)->equalTo(Carbon\Carbon::parse('2026-03-14 08:30:00')))
            ->where('connectionMetadata.account_count', 1));
});

test('linking an account with no matching connection creates a new connection', function () {
    $this->actingAs($this->user)
        ->post(route('plaid.store', [$this->budget, $this->account]), plaidLinkPagePayload())
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionMissing('error')
        ->assertSessionHas('message', 'Account linked to Plaid successfully.');

    $connection = PlaidConnection::sole();
    expect($connection)
        ->budget_id->toBe($this->budget->id)
        ->plaid_item_id->toBe('item-fresh')
        ->institution_id->toBe('ins_3')
        ->institution_name->toBe('Tartan Bank')
        ->institution_logo->toBe('tartan-logo')
        ->access_token->toBe('access-sandbox-fresh')
        ->status->toBe(PlaidConnection::STATUS_ACTIVE);

    expect(PlaidAccount::sole())
        ->account_id->toBe($this->account->id)
        ->plaid_connection_id->toBe($connection->id)
        ->plaid_account_id->toBe('acc-fresh')
        ->current_balance_cents->toBe(41025)
        ->and($this->plaid->paths())->toContain('/transactions/get')
        ->and($this->plaid->unexpected)->toBe([]);
});

test('re-linking an account with a stale Plaid link onto an active connection reuses it', function () {
    $stale = plaidLinkPageConnection($this->budget, [
        'plaid_item_id' => 'item-stale',
        'institution_id' => null,
        'status' => PlaidConnection::STATUS_ERROR,
    ]);
    $plaidAccount = plaidLinkPageLinkAccount($stale, $this->account);
    $active = plaidLinkPageConnection($this->budget, ['plaid_item_id' => 'item-fresh']);

    $this->actingAs($this->user)
        ->post(route('plaid.store', [$this->budget, $this->account]), plaidLinkPagePayload())
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionMissing('error')
        ->assertSessionHas('message', 'Account re-linked successfully to existing Tartan Bank connection.');

    expect(PlaidConnection::count())->toBe(2)
        ->and(PlaidAccount::count())->toBe(1)
        ->and($plaidAccount->fresh())
        ->plaid_connection_id->toBe($active->id)
        ->plaid_account_id->toBe('acc-fresh')
        ->current_balance_cents->toBe(41025)
        ->and($this->plaid->paths())->not->toContain('/institutions/get_by_id');
});

test('linking requires a public token, institution name and account id', function () {
    $this->actingAs($this->user)
        ->post(route('plaid.store', [$this->budget, $this->account]), [
            'metadata' => ['institution' => ['institution_id' => 'ins_3'], 'account' => ['name' => 'x']],
        ])
        ->assertSessionHasErrors(['public_token', 'metadata.institution.name', 'metadata.account.id']);

    expect(PlaidConnection::count())->toBe(0)
        ->and(PlaidAccount::count())->toBe(0)
        ->and($this->plaid->requests)->toBe([]);
});

test('linking an account missing from the Plaid response redirects back with an error and links nothing', function () {
    $this->actingAs($this->user)
        ->from(route('plaid.link', [$this->budget, $this->account]))
        ->post(route('plaid.store', [$this->budget, $this->account]), plaidLinkPagePayload([
            'metadata' => ['account' => ['id' => 'acc-not-returned']],
        ]))
        ->assertRedirect(route('plaid.link', [$this->budget, $this->account]))
        ->assertSessionMissing('message')
        ->assertSessionHas('error', 'Failed to link account. Please try again.');

    expect(PlaidConnection::count())->toBe(0)
        ->and(PlaidAccount::count())->toBe(0);
});

test('linking an account in another users budget is forbidden and changes nothing', function () {
    $otherBudget = Budget::factory()->create();
    $otherAccount = Account::factory()->checking()->create(['budget_id' => $otherBudget->id]);

    $this->actingAs($this->user)
        ->post(route('plaid.store', [$otherBudget, $otherAccount]), plaidLinkPagePayload())
        ->assertForbidden();

    expect(PlaidConnection::count())->toBe(0)
        ->and(PlaidAccount::count())->toBe(0)
        ->and($this->plaid->requests)->toBe([]);
});
