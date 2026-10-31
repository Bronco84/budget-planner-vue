<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\PlaidService;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * BUDGET-001: create a budget and land on connection-first setup.
 */

beforeEach(function () {
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
});

function fakePlaidLinkToken(?string $token = 'link-sandbox-test-token'): void
{
    test()->mock(PlaidService::class, function ($mock) use ($token) {
        $expectation = $mock->shouldReceive('createLinkToken');

        $token === null
            ? $expectation->andThrow(new Exception('Plaid unavailable'))
            : $expectation->andReturn($token);
    });
}

test('authenticated user can open the create budget form', function () {
    $this->actingAs($this->user)
        ->get(route('budgets.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Budgets/Create'));
});

test('guest is redirected to login from the create budget form', function () {
    $this->get(route('budgets.create'))
        ->assertRedirect(route('login'));
});

test('storing a budget persists it for the user and redirects to setup', function () {
    $response = $this->actingAs($this->user)
        ->post(route('budgets.store'), [
            'name' => 'Household',
            'description' => 'Family spending',
            'color' => '#10B981',
        ]);

    $budget = $this->user->budgets()->sole();

    expect($budget->name)->toBe('Household')
        ->and($budget->description)->toBe('Family spending')
        ->and($budget->color)->toBe('#10B981');

    $response->assertRedirect(route('budgets.setup', $budget))
        ->assertSessionHasNoErrors();
});

test('storing a budget without a colour applies the default colour', function () {
    $this->actingAs($this->user)
        ->post(route('budgets.store'), [
            'name' => 'No Colour',
            'description' => null,
        ])
        ->assertSessionHasNoErrors();

    expect($this->user->budgets()->sole()->color)->toBe('#6366f1');
});

test('a users first budget becomes their active budget', function () {
    $this->actingAs($this->user)
        ->post(route('budgets.store'), ['name' => 'First', 'description' => null]);

    $budget = $this->user->budgets()->sole();

    expect($this->user->fresh()->getActiveBudget()?->id)->toBe($budget->id);
});

test('a users first budget is stored as their active budget preference', function () {
    $this->actingAs($this->user)
        ->post(route('budgets.store'), ['name' => 'First', 'description' => null]);

    $budget = $this->user->budgets()->sole();

    expect(UserPreference::getActiveBudgetId($this->user->id))->toBe($budget->id);
});

test('creating another budget without a stored preference does not store the new budget as active', function () {
    $existing = Budget::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->post(route('budgets.store'), ['name' => 'Second', 'description' => null]);

    expect(UserPreference::getActiveBudgetId($this->user->id))->toBeNull()
        ->and($this->user->fresh()->getActiveBudget()?->id)->toBe($existing->id);
});

test('creating another budget keeps the existing active budget', function () {
    $existing = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->user->setActiveBudget($existing->id);

    $this->actingAs($this->user)
        ->post(route('budgets.store'), ['name' => 'Second', 'description' => null]);

    expect($this->user->budgets()->count())->toBe(2)
        ->and($this->user->fresh()->getActiveBudget()?->id)->toBe($existing->id);
});

test('storing a budget rejects invalid input and creates nothing', function (array $payload, string $field) {
    $this->actingAs($this->user)
        ->from(route('budgets.create'))
        ->post(route('budgets.store'), $payload)
        ->assertRedirect(route('budgets.create'))
        ->assertSessionHasErrors($field);

    expect(Budget::count())->toBe(0);
})->with([
    'missing name' => [['name' => '', 'description' => null], 'name'],
    'name too long' => [['name' => str_repeat('a', 256), 'description' => null], 'name'],
    'colour without hash' => [['name' => 'Valid', 'description' => null, 'color' => '10B981'], 'color'],
    'colour not hex' => [['name' => 'Valid', 'description' => null, 'color' => '#GGGGGG'], 'color'],
    'short colour' => [['name' => 'Valid', 'description' => null, 'color' => '#FFF'], 'color'],
]);

test('setup page renders for a budget without accounts with a plaid link token', function () {
    fakePlaidLinkToken('link-sandbox-abc');
    $budget = Budget::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->get(route('budgets.setup', $budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Setup')
            ->where('budget.id', $budget->id)
            ->where('linkToken', 'link-sandbox-abc'));
});

test('setup page still renders with a null link token when plaid fails', function () {
    fakePlaidLinkToken(null);
    $budget = Budget::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->get(route('budgets.setup', $budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Setup')
            ->where('budget.id', $budget->id)
            ->where('linkToken', null));
});

test('setup redirects to the dashboard when the budget already has accounts', function () {
    $budget = Budget::factory()->create(['user_id' => $this->user->id]);
    Account::factory()->checking()->create(['budget_id' => $budget->id]);

    $this->actingAs($this->user)
        ->get(route('budgets.setup', $budget))
        ->assertRedirect(route('budgets.show', $budget));
});

test('user cannot open setup for another users budget', function () {
    $otherBudget = Budget::factory()->create();

    $this->actingAs($this->user)
        ->get(route('budgets.setup', $otherBudget))
        ->assertForbidden();
});

test('storing a budget without a description field succeeds with a null description', function () {
    $response = $this->actingAs($this->user)
        ->post(route('budgets.store'), ['name' => 'No Description']);

    $budget = $this->user->budgets()->sole();

    expect($budget->description)->toBeNull();
    $response->assertRedirect(route('budgets.setup', $budget))
        ->assertSessionHasNoErrors();
});
