<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * PROPERTY-001 / PROPERTY-002: the properties list (populated and empty),
 * linking an account from another budget, and cross-user / cross-budget access.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

function propertyIn(Budget $budget, string $name = 'Main house'): Property
{
    return $budget->properties()->create([
        'name' => $name,
        'type' => 'property',
        'current_value_cents' => 30000000,
        'value_updated_at' => now(),
        'api_source' => 'manual',
    ]);
}

test('the properties page lists the budgets properties with their linked accounts', function () {
    $house = propertyIn($this->budget, 'Main house');
    $mortgage = Account::factory()->create([
        'budget_id' => $this->budget->id,
        'type' => 'mortgage',
        'property_id' => $house->id,
    ]);
    propertyIn(Budget::factory()->create(), 'Someone elses house');

    $this->actingAs($this->user)
        ->get(route('budgets.properties.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Properties/Index')
            ->where('budget.id', $this->budget->id)
            ->has('properties', 1)
            ->where('properties.0.id', $house->id)
            ->where('properties.0.name', 'Main house')
            ->has('properties.0.linked_accounts', 1)
            ->where('properties.0.linked_accounts.0.id', $mortgage->id));
});

test('a budget without properties renders an empty properties list', function () {
    $this->actingAs($this->user)
        ->get(route('budgets.properties.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Properties/Index')
            ->has('properties', 0));
});

test('linking an account from another budget when storing a property is ignored', function () {
    $foreignLoan = Account::factory()->create([
        'budget_id' => Budget::factory()->create(['user_id' => $this->user->id])->id,
        'type' => 'mortgage',
    ]);

    $this->actingAs($this->user)
        ->post(route('budgets.properties.store', $this->budget), [
            'name' => 'Lake cabin',
            'type' => 'property',
            'current_value_cents' => 25000000,
            'linked_account_id' => $foreignLoan->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('budgets.properties.index', $this->budget));

    expect($this->budget->properties()->where('name', 'Lake cabin')->exists())->toBeTrue()
        ->and($foreignLoan->fresh()->property_id)->toBeNull();
});

test('another users budget properties cannot be listed, created or stored', function () {
    $foreignBudget = Budget::factory()->create();

    $this->actingAs($this->user)
        ->get(route('budgets.properties.index', $foreignBudget))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->get(route('budgets.properties.create', $foreignBudget))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->post(route('budgets.properties.store', $foreignBudget), [
            'name' => 'Intruder house',
            'type' => 'property',
            'current_value_cents' => 100,
        ])
        ->assertForbidden();

    expect(Property::where('name', 'Intruder house')->exists())->toBeFalse();
});

test('another users property cannot be edited, updated or deleted and a cross budget property is not found', function () {
    $foreignBudget = Budget::factory()->create();
    $foreign = propertyIn($foreignBudget, 'Foreign house');
    $payload = ['name' => 'Hijacked', 'type' => 'property', 'current_value_cents' => 1];

    $this->actingAs($this->user)
        ->get(route('budgets.properties.edit', [$foreignBudget, $foreign]))
        ->assertForbidden();
    $this->actingAs($this->user)
        ->put(route('budgets.properties.update', [$foreignBudget, $foreign]), $payload)
        ->assertForbidden();
    $this->actingAs($this->user)
        ->delete(route('budgets.properties.destroy', [$foreignBudget, $foreign]))
        ->assertForbidden();

    // Addressed through the user's own budget, scoped bindings return 404.
    $this->actingAs($this->user)
        ->get(route('budgets.properties.edit', [$this->budget, $foreign]))
        ->assertNotFound();
    $this->actingAs($this->user)
        ->put(route('budgets.properties.update', [$this->budget, $foreign]), $payload)
        ->assertNotFound();
    $this->actingAs($this->user)
        ->delete(route('budgets.properties.destroy', [$this->budget, $foreign]))
        ->assertNotFound();

    $fresh = $foreign->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->name)->toBe('Foreign house')
        ->and($fresh->current_value_cents)->toBe(30000000);
});
