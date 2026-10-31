<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\User;
use Tests\Support\FakePlaidApi;

/*
 * Bank connections on shared budgets: connecting, re-authenticating, unlinking
 * and the liabilities admin actions are owner-only; editors may sync; viewers
 * may do neither. Plaid is faked at the Guzzle boundary.
 */

beforeEach(function () {
    $this->plaid = FakePlaidApi::install();
    $this->owner = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->owner->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);

    $this->connection = PlaidConnection::create([
        'budget_id' => $this->budget->id,
        'plaid_item_id' => 'item-shared',
        'institution_name' => 'Shared Bank',
        'access_token' => 'access-sandbox-shared',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
    PlaidAccount::create([
        'plaid_connection_id' => $this->connection->id,
        'account_id' => $this->account->id,
        'plaid_account_id' => 'acc-shared',
        'account_name' => 'Checking',
        'account_type' => 'credit',
        'account_subtype' => 'credit card',
        'current_balance_cents' => 0,
    ]);
});

function sharedPlaidMember(Budget $budget, string $role): User
{
    $user = User::factory()->create();
    $budget->connected_users()->attach($user->id, ['access_level' => $role]);

    return $user;
}

dataset('owner_only_plaid_actions', [
    'discover' => ['get', fn () => route('plaid.discover', $this->budget), []],
    'import' => ['post', fn () => route('plaid.import', $this->budget), ['public_token' => 'public-sandbox-x', 'metadata' => ['institution' => ['name' => 'Bank']], 'selected_accounts' => ['a']]],
    'setup connect' => ['get', fn () => route('budgets.setup.connect', $this->budget), []],
    'link form' => ['get', fn () => route('plaid.link', [$this->budget, $this->account]), []],
    'store' => ['post', fn () => route('plaid.store', [$this->budget, $this->account]), ['public_token' => 'public-sandbox-x', 'metadata' => ['institution' => ['name' => 'Bank']]]],
    'upgrade link token' => ['post', fn () => route('plaid.upgrade-link-token', [$this->budget, $this->account]), []],
    'update connection' => ['post', fn () => route('plaid.update-connection', [$this->budget, $this->account]), ['public_token' => 'public-sandbox-x', 'metadata' => ['institution' => ['name' => 'Bank']]]],
    'unlink' => ['delete', fn () => route('plaid.destroy', [$this->budget, $this->account]), []],
]);

test('editors and viewers cannot connect, re-authenticate or unlink bank connections', function (string $method, string $url, array $payload) {
    foreach (['editor', 'viewer'] as $role) {
        $member = sharedPlaidMember($this->budget, $role);

        $this->actingAs($member)->{$method}($url, $payload)->assertForbidden();
    }

    expect($this->plaid->requests)->toBe([])
        ->and(PlaidAccount::count())->toBe(1)
        ->and($this->connection->fresh()->access_token)->toBe('access-sandbox-shared');
})->with('owner_only_plaid_actions');

test('a co-owner may manage bank connections', function () {
    $coOwner = sharedPlaidMember($this->budget, 'owner');

    $this->actingAs($coOwner)
        ->delete(route('plaid.destroy', [$this->budget, $this->account]))
        ->assertSessionHas('message');

    expect(PlaidAccount::count())->toBe(0);
});

test('an editor may sync a linked account and the whole budget', function () {
    $editor = sharedPlaidMember($this->budget, 'editor');
    $this->plaid->on('/transactions/get', ['transactions' => []]);

    $this->actingAs($editor)
        ->post(route('plaid.sync', [$this->budget, $this->account]))
        ->assertRedirect();
    $this->actingAs($editor)
        ->post(route('plaid.sync-all', $this->budget))
        ->assertRedirect();

    expect($this->plaid->paths())->toContain('/transactions/get');
});

test('a viewer cannot sync', function () {
    $viewer = sharedPlaidMember($this->budget, 'viewer');

    $this->actingAs($viewer)->post(route('plaid.sync', [$this->budget, $this->account]))->assertForbidden();
    $this->actingAs($viewer)->post(route('plaid.sync-all', $this->budget))->assertForbidden();

    expect($this->plaid->requests)->toBe([]);
});

test('the liabilities admin page shows and refreshes connections only for budgets the user owns', function () {
    $editor = sharedPlaidMember($this->budget, 'editor');
    $coOwner = sharedPlaidMember($this->budget, 'owner');

    $this->actingAs($editor)
        ->get(route('admin.plaid-liabilities.index'))
        ->assertInertia(fn ($page) => $page->where('connections', []));
    $this->actingAs($editor)
        ->post(route('admin.plaid-liabilities.update-connection', $this->connection))
        ->assertForbidden();

    $this->actingAs($coOwner)
        ->get(route('admin.plaid-liabilities.index'))
        ->assertInertia(fn ($page) => $page->has('connections', 1)->where('connections.0.id', $this->connection->id));

    expect($this->plaid->requests)->toBe([]);
});
