<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\User;
use App\Services\PlaidService;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakePlaidApi;

/*
 * ACCOUNT-001: owner adds a manual account to a budget.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->otherBudget = Budget::factory()->create();
});

function mockAccountLinkToken(bool $fails = false): void
{
    test()->mock(PlaidService::class, function ($mock) use ($fails) {
        $expectation = $mock->shouldReceive('createLinkToken');

        $fails
            ? $expectation->andThrow(new Exception('Plaid unavailable'))
            : $expectation->andReturn('link-sandbox-account');
    });
}

test('owner can open the create or import account page', function (string $routeName) {
    mockAccountLinkToken();

    $this->actingAs($this->user)
        ->get(route($routeName, $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounts/CreateOrImport')
            ->where('budget.id', $this->budget->id)
            ->where('linkToken', 'link-sandbox-account')
            ->has('existingConnections', 0));
})->with([
    'from setup' => 'budgets.setup.manual',
    'from dashboard' => 'budgets.accounts.create',
]);

test('create account page still renders with a null link token when plaid fails', function () {
    mockAccountLinkToken(fails: true);

    $this->actingAs($this->user)
        ->get(route('budgets.accounts.create', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Accounts/CreateOrImport')
            ->where('linkToken', null));
});

test('storing a manual account persists it and redirects to the dashboard', function () {
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(9, 30));

    $this->actingAs($this->user)
        ->post(route('budgets.accounts.store', $this->budget), [
            'name' => 'Emergency Fund',
            'type' => 'savings',
            'current_balance' => 1250.50,
            'include_in_budget' => true,
        ])
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHasNoErrors();

    $account = $this->budget->accounts()->sole();

    expect($account->name)->toBe('Emergency Fund')
        ->and($account->type)->toBe('savings')
        ->and($account->current_balance_cents)->toBe(125050)
        ->and($account->include_in_budget)->toBeTrue()
        ->and($account->exclude_from_total_balance)->toBeFalse()
        ->and($account->balance_updated_at->toDateTimeString())->toBe('2026-06-15 09:30:00');
});

test('a manual account is included in the budget by default', function () {
    $this->actingAs($this->user)
        ->post(route('budgets.accounts.store', $this->budget), [
            'name' => 'Wallet',
            'type' => 'cash',
            'current_balance' => 40,
        ])
        ->assertSessionHasNoErrors();

    $account = $this->budget->accounts()->sole();

    expect($account->include_in_budget)->toBeTrue()
        ->and($account->exclude_from_total_balance)->toBeFalse();
});

test('an inactive manual account is also excluded from the total balance', function () {
    $this->actingAs($this->user)
        ->post(route('budgets.accounts.store', $this->budget), [
            'name' => 'Old Brokerage',
            'type' => 'investment',
            'current_balance' => 5000,
            'include_in_budget' => false,
        ])
        ->assertRedirect(route('budgets.show', $this->budget));

    $account = $this->budget->accounts()->sole();

    expect($account->include_in_budget)->toBeFalse()
        ->and($account->exclude_from_total_balance)->toBeTrue();
});

test('storing a manual account rejects invalid input and creates nothing', function (array $overrides, string $field) {
    $payload = array_merge([
        'name' => 'Checking',
        'type' => 'checking',
        'current_balance' => 100,
    ], $overrides);

    $this->actingAs($this->user)
        ->post(route('budgets.accounts.store', $this->budget), $payload)
        ->assertSessionHasErrors($field);

    expect(Account::count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'missing type' => [['type' => ''], 'type'],
    'type too long' => [['type' => str_repeat('x', 51)], 'type'],
    'missing balance' => [['current_balance' => ''], 'current_balance'],
    'non numeric balance' => [['current_balance' => 'lots'], 'current_balance'],
    'non boolean include flag' => [['include_in_budget' => 'maybe'], 'include_in_budget'],
]);

test('user cannot open the create account page for another users budget', function () {
    $this->actingAs($this->user)
        ->get(route('budgets.accounts.create', $this->otherBudget))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->get(route('budgets.setup.manual', $this->otherBudget))
        ->assertForbidden();
});

test('user cannot add an account to another users budget', function () {
    $this->actingAs($this->user)
        ->post(route('budgets.accounts.store', $this->otherBudget), [
            'name' => 'Sneaky',
            'type' => 'checking',
            'current_balance' => 1,
        ])
        ->assertForbidden();

    expect($this->otherBudget->accounts()->count())->toBe(0);
});

test('storing a manual account converts the balance to the exact cent amount', function (float $balance, int $expectedCents) {
    // Capture the raw value sent to the database (the integer column would mask float error).
    Account::saving(function (Account $account) {
        test()->rawCents = $account->getAttributes()['current_balance_cents'];
    });

    $this->actingAs($this->user)
        ->post(route('budgets.accounts.store', $this->budget), [
            'name' => 'Wallet',
            'type' => 'checking',
            'current_balance' => $balance,
            'include_in_budget' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($this->rawCents)->toBe($expectedCents)
        ->and($this->budget->accounts()->sole()->current_balance_cents)->toBe($expectedCents);
})->with([
    '0.29' => [0.29, 29],
    '19.99' => [19.99, 1999],
]);

test('create account page sends the user id as the Plaid client user id', function () {
    $plaid = FakePlaidApi::install()->on('/link/token/create', ['link_token' => 'link-sandbox-account']);

    $this->actingAs($this->user)
        ->get(route('budgets.accounts.create', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('linkToken', 'link-sandbox-account'));

    expect($plaid->bodyFor('/link/token/create')['user']['client_user_id'])->toBe((string) $this->user->id);
});
