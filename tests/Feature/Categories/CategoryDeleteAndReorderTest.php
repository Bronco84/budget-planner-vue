<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;

/*
 * CATEGORY-002: deleting an unused category and the cross-user /
 * cross-budget guards. (The CATEGORY-003 reorder endpoint was removed.)
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

function categoryFor(Budget $budget, string $name, int $order = 0): Category
{
    return Category::create([
        'budget_id' => $budget->id,
        'name' => $name,
        'amount' => 100,
        'order' => $order,
    ]);
}

test('deleting a category no transaction uses removes it with a success flash', function () {
    $category = categoryFor($this->budget, 'Hobbies');

    $this->actingAs($this->user)
        ->from(route('budgets.categories.index', $this->budget))
        ->delete(route('budgets.categories.destroy', [$this->budget, $category]))
        ->assertRedirect(route('budgets.categories.index', $this->budget))
        ->assertSessionHas('message', 'Category deleted successfully.');

    expect(Category::find($category->id))->toBeNull();
});

test('another users category cannot be deleted and a cross budget category is not found', function () {
    $other = User::factory()->create();
    $otherBudget = Budget::factory()->create(['user_id' => $other->id]);
    $foreign = categoryFor($otherBudget, 'Foreign');

    // Another user's budget: 403.
    $this->actingAs($this->user)
        ->delete(route('budgets.categories.destroy', [$otherBudget, $foreign]))
        ->assertForbidden();

    // A category of another budget addressed through the user's own budget: 404.
    $this->actingAs($this->user)
        ->delete(route('budgets.categories.destroy', [$this->budget, $foreign]))
        ->assertNotFound();

    expect(Category::find($foreign->id))->not->toBeNull();
});
