<?php

use App\Models\Budget;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * BUDGET-004: the owner can edit and update a budget; another user can neither
 * open the edit page nor change it.
 */

test('the owner can open the edit page and update their budget', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'name' => 'Household',
        'description' => 'Original',
        'color' => '#112233',
    ]);

    $this->actingAs($user)
        ->get(route('budgets.edit', $budget))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Budgets/Edit')
            ->where('budget.id', $budget->id)
            ->where('budget.name', 'Household'));

    $this->actingAs($user)
        ->patch(route('budgets.update', $budget), [
            'name' => 'Family',
            'description' => 'Updated description',
            'color' => '#AABBCC',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('budgets.show', $budget))
        ->assertSessionHas('message', 'Budget updated successfully');

    $fresh = $budget->fresh();
    expect($fresh->name)->toBe('Family')
        ->and($fresh->description)->toBe('Updated description')
        ->and($fresh->color)->toBe('#AABBCC');
});

test('another user cannot edit or update a budget they do not own', function () {
    $owner = User::factory()->create();
    $budget = Budget::factory()->create([
        'user_id' => $owner->id,
        'name' => 'Household',
        'description' => 'Original',
        'color' => '#112233',
    ]);
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->get(route('budgets.edit', $budget))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->patch(route('budgets.update', $budget), [
            'name' => 'Hijacked',
            'description' => 'Changed',
            'color' => '#000000',
        ])
        ->assertForbidden();

    $fresh = $budget->fresh();
    expect($fresh->name)->toBe('Household')
        ->and($fresh->description)->toBe('Original')
        ->and($fresh->color)->toBe('#112233');
});
