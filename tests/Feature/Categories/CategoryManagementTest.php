<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

/*
 * CATEGORY-001: review spending by category, create and edit categories.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

test('index renders categories with spent and remaining computed from matching expenses', function () {
    $account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    Category::create(['budget_id' => $this->budget->id, 'name' => 'Groceries', 'amount' => 200, 'order' => 1]);
    Category::create(['budget_id' => $this->budget->id, 'name' => 'Fun', 'amount' => 0, 'order' => 2]);

    $tx = ['budget_id' => $this->budget->id, 'account_id' => $account->id];
    Transaction::factory()->create($tx + ['category' => 'Groceries', 'amount_in_cents' => -5000]);
    Transaction::factory()->create($tx + ['category' => 'Groceries', 'amount_in_cents' => -2500]);
    Transaction::factory()->create($tx + ['category' => 'Groceries', 'amount_in_cents' => 1000]); // refund: not an expense
    Transaction::factory()->create($tx + ['category' => 'Dining', 'amount_in_cents' => -900]);    // other category
    Transaction::factory()->create($tx + ['category' => 'Fun', 'amount_in_cents' => -300]);

    $this->actingAs($this->user)
        ->get(route('budgets.categories.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Categories/Index')
            ->has('categories', 2)
            ->where('categories.0.name', 'Groceries')
            ->where('categories.0.spent_cents', 7500)
            ->where('categories.0.remaining_cents', 12500)
            ->where('categories.0.percent_used', fn ($v) => (float) $v === 37.5)
            ->where('categories.1.name', 'Fun')
            ->where('categories.1.spent_cents', 300)
            ->where('categories.1.percent_used', 0)
            ->where('summary.total_spent_cents', 7800)
            ->where('summary.category_count', 2)
        );
});

test('a budget with no categories renders zero totals', function () {
    $this->actingAs($this->user)
        ->get(route('budgets.categories.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Categories/Index')
            ->has('categories', 0)
            ->where('summary.total_allocated', 0)
            ->where('summary.total_spent_cents', 0)
            ->where('summary.total_remaining_cents', 0)
            ->where('summary.category_count', 0)
        );
});

test('a valid category is stored at the end of the order', function () {
    Category::create(['budget_id' => $this->budget->id, 'name' => 'Rent', 'amount' => 1000, 'order' => 4]);

    $this->actingAs($this->user)
        ->from(route('budgets.categories.index', $this->budget))
        ->post(route('budgets.categories.store', $this->budget), [
            'name' => 'Groceries',
            'amount' => 250.50,
            'color' => '#00ff00',
            'description' => 'Food',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('budgets.categories.index', $this->budget))
        ->assertSessionHas('message', 'Category created successfully.');

    $category = Category::where('budget_id', $this->budget->id)->where('name', 'Groceries')->firstOrFail();
    expect($category->order)->toBe(5)
        ->and((float) $category->amount)->toBe(250.5)
        ->and($category->color)->toBe('#00ff00');
});

test('a valid update changes the category', function () {
    $category = Category::create(['budget_id' => $this->budget->id, 'name' => 'Rent', 'amount' => 1000, 'order' => 1]);

    $this->actingAs($this->user)
        ->patch(route('budgets.categories.update', [$this->budget, $category]), [
            'name' => 'Mortgage',
            'amount' => 1500,
            'color' => '#123456',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message', 'Category updated successfully.');

    $category->refresh();
    expect($category->name)->toBe('Mortgage')
        ->and((float) $category->amount)->toBe(1500.0)
        ->and($category->color)->toBe('#123456');
});

test('a category without a name or with a negative amount is rejected and nothing is saved', function () {
    $category = Category::create(['budget_id' => $this->budget->id, 'name' => 'Rent', 'amount' => 1000, 'order' => 1]);

    $this->actingAs($this->user)
        ->post(route('budgets.categories.store', $this->budget), ['name' => '', 'amount' => -5])
        ->assertSessionHasErrors(['name', 'amount']);

    $this->actingAs($this->user)
        ->patch(route('budgets.categories.update', [$this->budget, $category]), ['name' => '', 'amount' => -5])
        ->assertSessionHasErrors(['name', 'amount']);

    expect(Category::where('budget_id', $this->budget->id)->count())->toBe(1)
        ->and($category->fresh()->name)->toBe('Rent');
});

test('category routes on another users budget are forbidden and a cross budget category is not found', function () {
    $other = User::factory()->create();
    $foreignBudget = Budget::factory()->create(['user_id' => $other->id]);
    $foreignCategory = Category::create(['budget_id' => $foreignBudget->id, 'name' => 'Theirs', 'amount' => 10, 'order' => 1]);
    $payload = ['name' => 'Hijacked', 'amount' => 1];

    $this->actingAs($this->user)->get(route('budgets.categories.index', $foreignBudget))->assertForbidden();
    $this->actingAs($this->user)->post(route('budgets.categories.store', $foreignBudget), $payload)->assertForbidden();
    $this->actingAs($this->user)->patch(route('budgets.categories.update', [$foreignBudget, $foreignCategory]), $payload)->assertForbidden();

    // Own budget in the URL, someone else's category: scoped binding 404.
    $this->actingAs($this->user)->patch(route('budgets.categories.update', [$this->budget, $foreignCategory]), $payload)->assertNotFound();

    expect($foreignCategory->fresh()->name)->toBe('Theirs')
        ->and(Category::where('budget_id', $foreignBudget->id)->count())->toBe(1);
});
