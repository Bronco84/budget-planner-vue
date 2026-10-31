<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePlaidApi;

/*
 * PLAID-001: connect bank accounts during setup (discover -> Plaid Link -> import).
 * Plaid is faked at the Guzzle boundary by Tests\Support\FakePlaidApi.
 */

beforeEach(function () {
    $this->travelTo(Carbon\Carbon::parse('2026-03-15 10:00:00'));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->plaid = FakePlaidApi::install();
});

/**
 * Three accounts as returned by /accounts/get for the exchanged item.
 */
function plaidImportAccountsFixture(): array
{
    return [
        'accounts' => [
            [
                'account_id' => 'acc-checking', 'name' => 'Everyday Checking', 'mask' => '0001',
                'type' => 'depository', 'subtype' => 'checking',
                'balances' => ['current' => 1234.56, 'available' => 1200.00, 'limit' => null],
            ],
            [
                'account_id' => 'acc-savings', 'name' => 'Rainy Day Savings', 'mask' => '0002',
                'type' => 'depository', 'subtype' => 'savings',
                'balances' => ['current' => 5000.00, 'available' => 5000.00, 'limit' => null],
            ],
            [
                'account_id' => 'acc-card', 'name' => 'Rewards Card', 'mask' => '0003',
                'type' => 'credit', 'subtype' => 'credit card',
                'balances' => ['current' => 250.10, 'available' => 4749.90, 'limit' => 5000.00],
            ],
        ],
    ];
}

function plaidImportPayload(array $overrides = []): array
{
    return array_replace([
        'public_token' => 'public-sandbox-abc',
        'metadata' => [
            'institution' => ['name' => 'First Platypus Bank', 'institution_id' => 'ins_109508'],
            'item' => ['id' => 'item-platypus-1'],
        ],
        'selected_accounts' => ['acc-checking', 'acc-savings'],
    ], $overrides);
}

function stubSuccessfulPlaidImport(FakePlaidApi $plaid): void
{
    $plaid->on('/item/public_token/exchange', ['access_token' => 'access-sandbox-xyz', 'item_id' => 'item-platypus-1'])
        ->on('/accounts/get', plaidImportAccountsFixture())
        ->on('/institutions/search', ['institutions' => [
            ['institution_id' => 'ins_109508', 'name' => 'First Platypus Bank', 'logo' => 'base64-logo'],
        ]])
        ->on('/institutions/get_by_id', ['institution' => [
            'institution_id' => 'ins_109508', 'name' => 'First Platypus Bank',
            'logo' => 'base64-logo', 'url' => 'https://platypus.example', 'primary_color' => '#123456',
        ]])
        ->on('/transactions/get', ['transactions' => [
            [
                'transaction_id' => 'tx-coffee', 'account_id' => 'acc-checking', 'amount' => 4.75,
                'date' => '2026-03-10', 'name' => 'Blue Bottle Coffee', 'pending' => false,
                'category' => ['Food and Drink'],
            ],
            [
                'transaction_id' => 'tx-interest', 'account_id' => 'acc-savings', 'amount' => -1.25,
                'date' => '2026-03-01', 'name' => 'Interest Payment', 'pending' => false,
                'category' => ['Transfer'],
            ],
            [
                'transaction_id' => 'tx-card', 'account_id' => 'acc-card', 'amount' => 60.00,
                'date' => '2026-03-05', 'name' => 'Grocery Store', 'pending' => false,
                'category' => ['Shops'],
            ],
        ]]);
}

test('setup page renders for an empty budget with a Plaid link token', function () {
    $this->plaid->on('/link/token/create', ['link_token' => 'link-sandbox-setup']);

    $this->actingAs($this->user)
        ->get(route('budgets.setup', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Setup')
            ->where('budget.id', $this->budget->id)
            ->where('linkToken', 'link-sandbox-setup'));

    expect($this->plaid->bodyFor('/link/token/create')['user']['client_user_id'])->toBe((string) $this->user->id);
});

test('setup redirects to the budget when it already has accounts', function () {
    Account::factory()->checking()->create(['budget_id' => $this->budget->id]);

    $this->actingAs($this->user)
        ->get(route('budgets.setup', $this->budget))
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('message', 'Budget already has accounts configured.');

    expect($this->plaid->requests)->toBe([]);
});

test('discover page lists the budgets active connections with their accounts', function () {
    $this->plaid->on('/link/token/create', ['link_token' => 'link-sandbox-discover']);

    $active = PlaidConnection::create([
        'budget_id' => $this->budget->id, 'plaid_item_id' => 'item-active',
        'institution_name' => 'Active Bank', 'access_token' => 'access-a', 'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
    $account = Account::factory()->checking()->create(['budget_id' => $this->budget->id, 'name' => 'Linked Checking']);
    PlaidAccount::create([
        'plaid_connection_id' => $active->id, 'account_id' => $account->id,
        'plaid_account_id' => 'pa-1', 'account_name' => 'CHK', 'account_type' => 'depository', 'account_subtype' => 'checking',
    ]);
    PlaidConnection::create([
        'budget_id' => $this->budget->id, 'plaid_item_id' => 'item-broken',
        'institution_name' => 'Broken Bank', 'access_token' => 'access-b', 'status' => PlaidConnection::STATUS_ERROR,
    ]);

    foreach (['plaid.discover', 'budgets.setup.connect'] as $routeName) {
        $this->actingAs($this->user)
            ->get(route($routeName, $this->budget))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plaid/Discover')
                ->where('linkToken', 'link-sandbox-discover')
                ->has('existingConnections', 1)
                ->where('existingConnections.0.institution_name', 'Active Bank')
                ->where('existingConnections.0.account_count', 1)
                ->where('existingConnections.0.accounts.0.name', 'Linked Checking')
                ->where('existingConnections.0.accounts.0.type', 'checking'));
    }
});

test('importing creates local accounts for only the selected Plaid accounts under one connection', function () {
    stubSuccessfulPlaidImport($this->plaid);

    $this->actingAs($this->user)
        ->from(route('plaid.discover', $this->budget))
        ->post(route('plaid.import', $this->budget), plaidImportPayload())
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('message', 'Successfully imported 2 accounts from Plaid.');

    expect($this->plaid->bodyFor('/item/public_token/exchange'))->toBe(['public_token' => 'public-sandbox-abc'])
        ->and($this->plaid->unexpected)->toBe([]);

    // One connection holding the access token and institution metadata.
    $connection = PlaidConnection::where('budget_id', $this->budget->id)->sole();
    expect($connection->access_token)->toBe('access-sandbox-xyz')
        ->and($connection->status)->toBe(PlaidConnection::STATUS_ACTIVE)
        ->and($connection->institution_id)->toBe('ins_109508')
        ->and($connection->institution_name)->toBe('First Platypus Bank')
        ->and($connection->institution_logo)->toBe('base64-logo')
        ->and($connection->institution_url)->toBe('https://platypus.example');

    // Only the two selected accounts were imported; the credit card was not.
    $accounts = $this->budget->accounts()->orderBy('name')->get();
    expect($accounts->pluck('name')->all())->toBe(['Everyday Checking', 'Rainy Day Savings'])
        ->and($accounts->pluck('type')->all())->toBe(['checking', 'savings'])
        ->and($accounts->pluck('current_balance_cents')->all())->toBe([123456, 500000]);

    $plaidAccounts = PlaidAccount::where('plaid_connection_id', $connection->id)->orderBy('plaid_account_id')->get();
    expect($plaidAccounts->pluck('plaid_account_id')->all())->toBe(['acc-checking', 'acc-savings'])
        ->and($plaidAccounts->pluck('account_id')->sort()->values()->all())->toBe($accounts->pluck('id')->sort()->values()->all());

    // The initial sync imported each account's own transactions only.
    $checking = $accounts->firstWhere('name', 'Everyday Checking');
    $savings = $accounts->firstWhere('name', 'Rainy Day Savings');
    expect(Transaction::where('plaid_transaction_id', 'tx-coffee')->sole())
        ->account_id->toBe($checking->id)
        ->amount_in_cents->toBe(-475)
        ->is_plaid_imported->toBeTrue()
        ->and(Transaction::where('plaid_transaction_id', 'tx-interest')->sole()->account_id)->toBe($savings->id)
        ->and(Transaction::where('plaid_transaction_id', 'tx-card')->exists())->toBeFalse()
        ->and($connection->fresh()->last_sync_at)->not->toBeNull();
});

test('the setup import route imports accounts the same way', function () {
    stubSuccessfulPlaidImport($this->plaid);

    $this->actingAs($this->user)
        ->post(route('budgets.setup.import', $this->budget), plaidImportPayload(['selected_accounts' => ['acc-checking']]))
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('message', 'Successfully imported 1 accounts from Plaid.');

    expect($this->budget->accounts()->pluck('name')->all())->toBe(['Everyday Checking'])
        ->and(PlaidConnection::where('budget_id', $this->budget->id)->count())->toBe(1);
});

test('import requires a public token, institution name and selected accounts', function () {
    $this->actingAs($this->user)
        ->from(route('plaid.discover', $this->budget))
        ->post(route('plaid.import', $this->budget), [
            'metadata' => ['institution' => ['institution_id' => 'ins_1']],
        ])
        ->assertRedirect(route('plaid.discover', $this->budget))
        ->assertSessionHasErrors(['public_token', 'metadata.institution.name', 'selected_accounts']);

    expect($this->plaid->requests)->toBe([])
        ->and(PlaidConnection::count())->toBe(0)
        ->and($this->budget->accounts()->count())->toBe(0);
});

test('import with a selection matching no Plaid account reports zero imported', function () {
    stubSuccessfulPlaidImport($this->plaid);

    $this->actingAs($this->user)
        ->post(route('plaid.import', $this->budget), plaidImportPayload(['selected_accounts' => ['acc-does-not-exist']]))
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('message', 'Successfully imported 0 accounts from Plaid.');

    $connection = PlaidConnection::where('budget_id', $this->budget->id)->sole();
    expect($connection->plaidAccounts()->count())->toBe(0)
        ->and($this->budget->accounts()->count())->toBe(0);
});

test('import falls back to an institution name search when no institution id is given', function () {
    stubSuccessfulPlaidImport($this->plaid);
    $this->plaid
        ->on('/institutions/search', ['institutions' => [
            ['institution_id' => 'ins_found', 'name' => 'First Platypus Bank', 'logo' => 'search-logo'],
        ]])
        ->on('/institutions/get_by_id', fn (array $body) => ['institution' => [
            'institution_id' => $body['institution_id'], 'name' => 'First Platypus Bank',
            'logo' => 'detail-logo', 'url' => 'https://found.example',
        ]]);

    $payload = plaidImportPayload();
    unset($payload['metadata']['institution']['institution_id']);

    $this->actingAs($this->user)
        ->post(route('plaid.import', $this->budget), $payload)
        ->assertRedirect(route('budgets.show', $this->budget));

    expect($this->plaid->bodyFor('/institutions/search')['query'])->toBe('First Platypus Bank')
        ->and($this->plaid->bodyFor('/institutions/get_by_id')['institution_id'])->toBe('ins_found');

    $connection = PlaidConnection::where('budget_id', $this->budget->id)->sole();
    expect($connection->institution_id)->toBe('ins_found')
        ->and($connection->institution_logo)->toBe('detail-logo')
        ->and($connection->institution_url)->toBe('https://found.example');
});

test('import still succeeds with no logo or url when the institution cannot be found', function () {
    stubSuccessfulPlaidImport($this->plaid);
    $this->plaid
        ->on('/institutions/get_by_id', FakePlaidApi::error('INVALID_INSTITUTION', 'not found'))
        ->on('/institutions/search', ['institutions' => []]);

    $this->actingAs($this->user)
        ->post(route('plaid.import', $this->budget), plaidImportPayload())
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('message', 'Successfully imported 2 accounts from Plaid.');

    $connection = PlaidConnection::where('budget_id', $this->budget->id)->sole();
    expect($connection->institution_logo)->toBeNull()
        ->and($connection->institution_url)->toBeNull()
        ->and($connection->plaidAccounts()->count())->toBe(2);
});

test('a failed public token exchange redirects back with an error and creates nothing', function () {
    $this->plaid->on('/item/public_token/exchange', FakePlaidApi::error('INVALID_PUBLIC_TOKEN', 'provided public token is expired'));

    $this->actingAs($this->user)
        ->from(route('plaid.discover', $this->budget))
        ->post(route('plaid.import', $this->budget), plaidImportPayload())
        ->assertRedirect(route('plaid.discover', $this->budget))
        ->assertSessionHas('error', fn (string $error) => str_starts_with($error, 'Failed to import accounts'));

    expect($this->plaid->paths())->toBe(['/item/public_token/exchange'])
        ->and(PlaidConnection::count())->toBe(0)
        ->and($this->budget->accounts()->count())->toBe(0);
});

test('another users budget cannot be discovered, set up or imported into', function () {
    $intruder = User::factory()->create();

    $this->actingAs($intruder)->get(route('budgets.setup', $this->budget))->assertForbidden();
    $this->actingAs($intruder)->get(route('plaid.discover', $this->budget))->assertForbidden();
    $this->actingAs($intruder)->get(route('budgets.setup.connect', $this->budget))->assertForbidden();
    $this->actingAs($intruder)->post(route('plaid.import', $this->budget), plaidImportPayload())->assertForbidden();
    $this->actingAs($intruder)->post(route('budgets.setup.import', $this->budget), plaidImportPayload())->assertForbidden();

    expect($this->plaid->requests)->toBe([])
        ->and(PlaidConnection::count())->toBe(0);
});
