<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;

/*
 * CATEGORY-002 / CATEGORY-003: deleting an unused category, reordering the
 * budget's own categories, and the cross-user / cross-budget guards.
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

test('reordering the budgets categories persists the new order', function () {
    $first = categoryFor($this->budget, 'First', 0);
    $second = categoryFor($this->budget, 'Second', 1);
    $third = categoryFor($this->budget, 'Third', 2);

    $this->actingAs($this->user)
        ->from(route('budgets.categories.index', $this->budget))
        ->post(route('budgets.categories.reorder', $this->budget), [
            'categories' => [
                ['id' => $first->id, 'order' => 2],
                ['id' => $second->id, 'order' => 0],
                ['id' => $third->id, 'order' => 1],
            ],
        ])
        ->assertRedirect(route('budgets.categories.index', $this->budget))
        ->assertSessionHas('message');

    expect($first->fresh()->order)->toBe(2)
        ->and($second->fresh()->order)->toBe(0)
        ->and($third->fresh()->order)->toBe(1)
        ->and($this->budget->categories()->pluck('name')->all())->toBe(['Second', 'Third', 'First']);
});

test('reordering ignores category ids from another budget', function () {
    $mine = categoryFor($this->budget, 'Mine', 0);
    $foreign = categoryFor(Budget::factory()->create(), 'Foreign', 5);

    $this->actingAs($this->user)
        ->post(route('budgets.categories.reorder', $this->budget), [
            'categories' => [
                ['id' => $mine->id, 'order' => 3],
                ['id' => $foreign->id, 'order' => 0],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($mine->fresh()->order)->toBe(3)
        ->and($foreign->fresh()->order)->toBe(5);
});
