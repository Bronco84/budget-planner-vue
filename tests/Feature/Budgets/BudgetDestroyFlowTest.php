<?php

use App\Models\Budget;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePlaidApi;

/*
 * BUDGET-005: delete redirects through budgets.index to the next budget or the create form.
 */

beforeEach(function () {
    Storage::fake('s3');
    FakePlaidApi::install();
    $this->user = User::factory()->create();
});

test('owner deletes a budget and lands on the next remaining budget', function () {
    $keep = Budget::factory()->create(['user_id' => $this->user->id]);
    $doomed = Budget::factory()->create(['user_id' => $this->user->id]);
    UserPreference::setActiveBudgetId($this->user->id, $keep->id);

    $this->actingAs($this->user)
        ->delete(route('budgets.destroy', $doomed))
        ->assertRedirect(route('budgets.index'))
        ->assertSessionHas('message', 'Budget deleted successfully');

    expect(Budget::find($doomed->id))->toBeNull();

    $this->actingAs($this->user)
        ->get(route('budgets.index'))
        ->assertRedirect(route('budgets.show', $keep));
});

test('deleting the only budget ends on the create budget form', function () {
    $only = Budget::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->delete(route('budgets.destroy', $only))
        ->assertRedirect(route('budgets.index'));

    $this->actingAs($this->user)
        ->get(route('budgets.index'))
        ->assertRedirect(route('budgets.create'));

    $this->actingAs($this->user)
        ->get(route('budgets.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Budgets/Create'));
});

test('deleting the active budget clears the preference so another budget becomes active', function () {
    $other = Budget::factory()->create(['user_id' => $this->user->id]);
    $active = Budget::factory()->create(['user_id' => $this->user->id]);
    UserPreference::setActiveBudgetId($this->user->id, $active->id);

    $this->actingAs($this->user)->delete(route('budgets.destroy', $active));

    expect(UserPreference::getActiveBudgetId($this->user->id))->toBeNull();

    $this->actingAs($this->user)
        ->get(route('budgets.index'))
        ->assertRedirect(route('budgets.show', $other));
});

test('deleting another users budget is forbidden and the budget remains', function () {
    $foreign = Budget::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($this->user)
        ->delete(route('budgets.destroy', $foreign))
        ->assertForbidden();

    expect(Budget::find($foreign->id))->not->toBeNull();
});
