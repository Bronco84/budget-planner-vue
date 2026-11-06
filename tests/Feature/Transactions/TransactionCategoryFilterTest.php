<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * TXN-001 (PR-093): the transaction list's category filter matches the
 * category itself: rows linked by category_id, plus unlinked legacy rows the
 * category claims by name key (spaces trimmed, case ignored, accents kept).
 * These run on SQLite, where `category = ?` is exact, so they catch a filter
 * that leans on MySQL's collation or on the denormalized string.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
});

function filterCategory(Budget $budget, string $name, int $order = 0): Category
{
    return Category::create(['budget_id' => $budget->id, 'name' => $name, 'amount' => 100, 'order' => $order]);
}

/** Insert a row directly so neither the category id nor the string is synced. */
function filterRow(Budget $budget, Account $account, ?string $category, ?int $categoryId = null): int
{
    return DB::table('transactions')->insertGetId([
        'budget_id' => $budget->id,
        'account_id' => $account->id,
        'description' => 'Row',
        'category' => $category,
        'category_id' => $categoryId,
        'amount_in_cents' => -100,
        'date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function filteredIds($test, string $category): array
{
    $page = $test->actingAs($test->user)
        ->get(route('budget.transaction.index', [$test->budget, 'category' => $category]))
        ->assertOk()
        ->viewData('page')['props'];

    return collect($page['transactions']['data'])->pluck('id')->sort()->values()->all();
}

test('filtering by a category returns rows linked by id and legacy rows matching its name key', function () {
    $groceries = filterCategory($this->budget, 'Groceries');
    $dining = filterCategory($this->budget, 'Dining', 1);

    $linked = filterRow($this->budget, $this->account, 'Groceries', $groceries->id);
    // Linked by id, its copied string stale (e.g. written before a rename).
    $stale = filterRow($this->budget, $this->account, 'Food', $groceries->id);
    $legacy = filterRow($this->budget, $this->account, 'groceries ', null);
    // Carries the name but is linked elsewhere, or is a near miss: excluded.
    filterRow($this->budget, $this->account, 'Groceries', $dining->id);
    filterRow($this->budget, $this->account, 'Grocery', null);

    $expected = collect([$linked, $stale, $legacy])->sort()->values()->all();

    expect(filteredIds($this, 'Groceries'))->toBe($expected)
        ->and(filteredIds($this, 'groceries '))->toBe($expected);
});

test('filtering by a legacy string no category has matches unlinked rows by name key, accents kept', function () {
    filterCategory($this->budget, 'Groceries');

    $a = filterRow($this->budget, $this->account, 'Café', null);
    // Only ASCII letters vary in case here: SQLite's LOWER() leaves 'É' as is.
    $b = filterRow($this->budget, $this->account, ' CAFé', null);
    filterRow($this->budget, $this->account, 'Cafe', null);

    expect(filteredIds($this, 'Café'))->toBe([$a, $b]);
});

test('the category filter options list each category once and legacy strings no category claims', function () {
    filterCategory($this->budget, 'Groceries');
    filterCategory($this->budget, 'Unused', 1);

    filterRow($this->budget, $this->account, 'groceries ', null);
    filterRow($this->budget, $this->account, 'Venmo', null);
    filterRow($this->budget, $this->account, 'venmo', null);

    $options = $this->actingAs($this->user)
        ->get(route('budget.transaction.index', $this->budget))
        ->assertOk()
        ->viewData('page')['props']['categories'];

    expect($options)->toHaveCount(3)
        ->and(array_slice($options, 0, 2))->toBe(['Groceries', 'Unused'])
        ->and(Category::nameKey($options[2]))->toBe('venmo');
});
