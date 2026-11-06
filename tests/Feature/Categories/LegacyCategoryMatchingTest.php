<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * CATEGORY-001 / REPORT-001 (PR-083): an unlinked legacy transaction matches a
 * category by name trimmed and case-insensitively, as SyncsCategoryAssignment
 * links new writes, and every place that matches it agrees: the in-memory
 * check, the query scope, the categories page totals and the budget
 * performance report. These run on SQLite, where a plain `category = name`
 * comparison is exact, so they catch any path that relies on MySQL's
 * case-insensitive collation instead of normalising.
 */

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(12, 0));
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
});

function legacyMatchCategory(Budget $budget, string $name, int $order = 0): Category
{
    return Category::create(['budget_id' => $budget->id, 'name' => $name, 'amount' => 100, 'order' => $order]);
}

/** Insert an unlinked legacy row directly: the model would link it on save. */
function legacyMatchRow(Budget $budget, Account $account, ?string $category, int $cents): int
{
    return DB::table('transactions')->insertGetId([
        'budget_id' => $budget->id,
        'account_id' => $account->id,
        'description' => 'Legacy row',
        'category' => $category,
        'category_id' => null,
        'amount_in_cents' => $cents,
        'date' => '2026-06-10',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function legacyMatchCategoriesPage($test): array
{
    $categories = $test->actingAs($test->user)
        ->get(route('budgets.categories.index', $test->budget))
        ->assertOk()
        ->viewData('page')['props']['categories'];

    return collect($categories)->mapWithKeys(fn ($row) => [$row['name'] => $row['spent_cents']])->all();
}

function legacyMatchReport($test): array
{
    $performance = $test->actingAs($test->user)->get(route('reports.index', [
        $test->budget,
        'date_range' => 'custom',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]))->assertOk()->viewData('page')['props']['budgetPerformance'];

    return collect($performance['categories'])->mapWithKeys(fn ($row) => [$row['category'] => $row['spent']])->all();
}

test('a legacy row differing only in case or surrounding spaces counts toward the category everywhere', function () {
    $groceries = legacyMatchCategory($this->budget, 'Groceries');
    $id = legacyMatchRow($this->budget, $this->account, 'groceries ', -1250);

    $transaction = Transaction::findOrFail($id);
    expect($transaction->category_id)->toBeNull()
        ->and($transaction->isInCategory($groceries))->toBeTrue()
        ->and(Transaction::query()->inCategory($groceries)->pluck('id')->all())->toBe([$id])
        ->and(abs((int) $this->budget->transactions()->inCategory($groceries)->sum('amount_in_cents')))->toBe(1250)
        ->and(legacyMatchCategoriesPage($this))->toBe(['Groceries' => 1250])
        ->and(legacyMatchReport($this))->toBe(['Groceries' => 1250]);
});

test('legacy rows that differ only in case or spaces merge into one category total', function () {
    legacyMatchCategory($this->budget, 'Groceries');
    legacyMatchCategory($this->budget, 'Dining', 1);

    legacyMatchRow($this->budget, $this->account, 'Groceries', -100);
    legacyMatchRow($this->budget, $this->account, 'groceries ', -200);
    legacyMatchRow($this->budget, $this->account, '  GROCERIES', -400);
    legacyMatchRow($this->budget, $this->account, 'dining', -800);
    // Not the same name: neither counts.
    legacyMatchRow($this->budget, $this->account, 'Grocery', -1600);
    legacyMatchRow($this->budget, $this->account, 'Groceries & More', -3200);

    expect(legacyMatchCategoriesPage($this))->toBe(['Groceries' => 700, 'Dining' => 800])
        ->and(legacyMatchReport($this))->toBe(['Dining' => 800, 'Groceries' => 700]);
});

test('a legacy name shared by two categories counts once, toward the one new writes link to', function () {
    // Listed second (by order) but created first: findForBudgetByName, and so
    // the linking of new writes, picks the lowest id.
    $first = legacyMatchCategory($this->budget, 'food ', 2);
    $second = legacyMatchCategory($this->budget, 'Food', 1);
    expect(Category::findForBudgetByName($this->budget->id, 'FOOD')?->id)->toBe($first->id);
    $id = legacyMatchRow($this->budget, $this->account, 'FOOD', -500);

    $transaction = Transaction::findOrFail($id);

    expect($transaction->isInCategory($first))->toBeTrue()
        ->and($transaction->isInCategory($second))->toBeFalse()
        ->and(Transaction::query()->inCategory($first)->pluck('id')->all())->toBe([$id])
        ->and(Transaction::query()->inCategory($second)->pluck('id')->all())->toBe([])
        ->and(legacyMatchCategoriesPage($this))->toBe(['Food' => 0, 'food ' => 500])
        ->and(legacyMatchReport($this))->toBe(['food ' => 500, 'Food' => 0]);
});

test('renaming a category does not take legacy rows claimed by an earlier category of the same name', function () {
    $owner = legacyMatchCategory($this->budget, 'Food');
    $other = legacyMatchCategory($this->budget, 'food', 1);
    $id = legacyMatchRow($this->budget, $this->account, 'Food', -300);

    $other->update(['name' => 'Snacks']);

    expect(DB::table('transactions')->where('id', $id)->value('category_id'))->toBeNull()
        ->and(Transaction::findOrFail($id)->isInCategory($owner))->toBeTrue();
});

test('renaming a category adopts legacy rows matching its old name in any case', function () {
    $category = legacyMatchCategory($this->budget, 'Groceries');
    $id = legacyMatchRow($this->budget, $this->account, ' groceries', -300);

    $category->update(['name' => 'Food']);

    $row = DB::table('transactions')->where('id', $id)->first();
    expect($row->category_id)->toBe($category->id)
        ->and($row->category)->toBe('Food');
});

test('a new write links to a category by the legacy name rule: spaces trimmed, case ignored, accents kept', function () {
    $groceries = legacyMatchCategory($this->budget, 'Groceries');
    $cafe = legacyMatchCategory($this->budget, 'Café', 1);

    $link = fn (string $name) => Transaction::create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'New write',
        'category' => $name,
        'amount_in_cents' => -100,
        'date' => '2026-06-10',
    ])->category_id;

    expect($link('  GROCERIES '))->toBe($groceries->id)
        ->and($link('CAFÉ'))->toBe($cafe->id)
        // Accents matter (MySQL's collation would otherwise equate these).
        ->and($link('cafe'))->toBeNull()
        // Only spaces are trimmed, as when legacy rows are counted.
        ->and($link("Groceries\t"))->toBeNull();
});

test('a string links to a category exactly when it would count toward it unlinked', function () {
    $cafe = legacyMatchCategory($this->budget, 'Café');

    foreach (['café', ' CAFÉ ', 'cafe', "Café\t", 'Cafe '] as $name) {
        $linked = Category::findForBudgetByName($this->budget->id, $name) !== null;
        $id = legacyMatchRow($this->budget, $this->account, $name, -100);

        expect($linked)->toBe(Transaction::findOrFail($id)->isInCategory($cafe), $name);
    }
});
