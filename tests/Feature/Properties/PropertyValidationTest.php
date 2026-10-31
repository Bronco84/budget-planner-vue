<?php

use App\Models\Budget;
use App\Models\Property;
use App\Models\User;

/*
 * PROPERTY-001 / PROPERTY-002: invalid property payloads are rejected and nothing
 * is created or changed.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

dataset('invalid property payloads', [
    'unknown type' => [['type' => 'boat'], 'type'],
    'negative value' => [['current_value_cents' => -1], 'current_value_cents'],
    'missing name' => [['name' => ''], 'name'],
    'year built too old' => [['year_built' => 1700], 'year_built'],
    'year built in the future' => [['year_built' => (int) date('Y') + 5], 'year_built'],
    'vehicle year too old' => [['type' => 'vehicle', 'vehicle_year' => 1850], 'vehicle_year'],
    'vehicle year in the future' => [['type' => 'vehicle', 'vehicle_year' => (int) date('Y') + 5], 'vehicle_year'],
]);

test('storing an invalid property is rejected', function (array $overrides, string $field) {
    $this->actingAs($this->user)
        ->post(route('budgets.properties.store', $this->budget), array_merge([
            'name' => 'Lake cabin',
            'type' => 'property',
            'current_value_cents' => 25000000,
        ], $overrides))
        ->assertSessionHasErrors($field);

    expect(Property::count())->toBe(0);
})->with('invalid property payloads');

test('updating a property with invalid fields is rejected', function (array $overrides, string $field) {
    $property = $this->budget->properties()->create([
        'name' => 'Main house',
        'type' => 'property',
        'current_value_cents' => 30000000,
        'year_built' => 1990,
        'value_updated_at' => now(),
        'api_source' => 'manual',
    ]);

    $this->actingAs($this->user)
        ->patch(route('budgets.properties.update', [$this->budget, $property]), array_merge([
            'name' => 'Renamed house',
            'type' => 'property',
            'current_value_cents' => 35000000,
        ], $overrides))
        ->assertSessionHasErrors($field);

    $fresh = $property->fresh();
    expect($fresh->name)->toBe('Main house')
        ->and($fresh->type)->toBe('property')
        ->and($fresh->current_value_cents)->toBe(30000000)
        ->and($fresh->year_built)->toBe(1990);
})->with('invalid property payloads');
