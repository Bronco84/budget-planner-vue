<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Property;
use App\Models\User;

/*
 * PROPERTY-001 / PROPERTY-002: creating, updating, deleting and opening a property
 * must land the owner on an existing page (the properties list or edit form).
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

function makeProperty(Budget $budget, array $overrides = []): Property
{
    return $budget->properties()->create(array_merge([
        'name' => 'Main house',
        'type' => 'property',
        'current_value_cents' => 30000000,
        'value_updated_at' => now(),
        'api_source' => 'manual',
    ], $overrides));
}

test('storing a property redirects to the properties list', function () {
    $loan = Account::factory()->create(['budget_id' => $this->budget->id, 'type' => 'mortgage']);

    $this->actingAs($this->user)
        ->post(route('budgets.properties.store', $this->budget), [
            'name' => 'Lake cabin',
            'type' => 'property',
            'current_value_cents' => 25000000,
            'linked_account_id' => $loan->id,
        ])
        ->assertRedirect(route('budgets.properties.index', $this->budget));

    $property = $this->budget->properties()->where('name', 'Lake cabin')->firstOrFail();

    expect($loan->fresh()->property_id)->toBe($property->id);
});

test('updating a property redirects to the properties list', function () {
    $property = makeProperty($this->budget);

    $this->actingAs($this->user)
        ->put(route('budgets.properties.update', [$this->budget, $property]), [
            'name' => 'Main house (renovated)',
            'type' => 'property',
            'current_value_cents' => 35000000,
        ])
        ->assertRedirect(route('budgets.properties.index', $this->budget));

    expect($property->fresh()->name)->toBe('Main house (renovated)')
        ->and($property->fresh()->current_value_cents)->toBe(35000000);
});

test('deleting a property unlinks its accounts and redirects to the properties list', function () {
    $property = makeProperty($this->budget);
    $loan = Account::factory()->create([
        'budget_id' => $this->budget->id,
        'type' => 'mortgage',
        'property_id' => $property->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('budgets.properties.destroy', [$this->budget, $property]))
        ->assertRedirect(route('budgets.properties.index', $this->budget));

    expect(Property::find($property->id))->toBeNull()
        ->and($loan->fresh()->property_id)->toBeNull();
});

test('opening a property show url redirects to its edit page', function () {
    $property = makeProperty($this->budget);

    $this->actingAs($this->user)
        ->get(route('budgets.properties.show', [$this->budget, $property]))
        ->assertRedirect(route('budgets.properties.edit', [$this->budget, $property]));
});

test('another users property show url is forbidden', function () {
    $property = makeProperty($this->budget);
    $other = User::factory()->create();

    $this->actingAs($other)
        ->get(route('budgets.properties.show', [$this->budget, $property]))
        ->assertForbidden();
});
