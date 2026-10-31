<?php

use App\Models\Budget;
use App\Models\User;

/*
 * BUDGET-004: an empty name or a malformed colour is rejected and the budget is
 * left unchanged.
 */

test('updating a budget with an empty name or bad colour is rejected', function (array $payload, string $field) {
    $user = User::factory()->create();
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'name' => 'Household',
        'description' => 'Original description',
        'color' => '#112233',
    ]);

    $this->actingAs($user)
        ->patch(route('budgets.update', $budget), array_merge([
            'name' => 'Renamed',
            'description' => 'Changed description',
            'color' => '#445566',
        ], $payload))
        ->assertSessionHasErrors($field);

    $fresh = $budget->fresh();
    expect($fresh->name)->toBe('Household')
        ->and($fresh->description)->toBe('Original description')
        ->and($fresh->color)->toBe('#112233');
})->with([
    'empty name' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'colour without hash' => [['color' => '445566'], 'color'],
    'short colour' => [['color' => '#abc'], 'color'],
    'colour name' => [['color' => 'red'], 'color'],
]);
