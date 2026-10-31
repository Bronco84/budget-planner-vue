<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * CATEGORY-001 / CATEGORY-002 / TXN-002 / TXN-003: transactions reference their
 * budget category by id (category_id), keeping the legacy `category` string as
 * a denormalized copy and as the fallback for unmatched legacy rows.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
});

function linkingCategory(Budget $budget, string $name, int $order = 0): Category
{
    return Category::create(['budget_id' => $budget->id, 'name' => $name, 'amount' => 100, 'order' => $order]);
}

function rawTransaction(Budget $budget, Account $account, ?string $category, int $cents = -1000): int
{
    return DB::table('transactions')->insertGetId([
        'budget_id' => $budget->id,
        'account_id' => $account->id,
        'description' => 'Legacy row',
        'category' => $category,
        'amount_in_cents' => $cents,
        'date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('the category id migration backfills rows by trimmed case insensitive name within the same budget', function () {
    $migration = require database_path('migrations/2026_10_31_000000_add_category_id_to_transactions_and_recurring_templates.php');
    $migration->down();

    $groceries = linkingCategory($this->budget, 'Groceries');
    $otherBudget = Budget::factory()->create();
    $otherAccount = Account::factory()->checking()->create(['budget_id' => $otherBudget->id]);
    linkingCategory($otherBudget, 'Rent');

    $matched = rawTransaction($this->budget, $this->account, '  groceries ');
    $unmatched = rawTransaction($this->budget, $this->account, 'Misc');
    $empty = rawTransaction($this->budget, $this->account, null);
    // "Rent" exists only in another budget, so this row stays unlinked.
    $crossBudget = rawTransaction($this->budget, $this->account, 'Rent');
    $otherBudgetRent = rawTransaction($otherBudget, $otherAccount, 'RENT');

    $templateId = DB::table('recurring_transaction_templates')->insertGetId([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Weekly shop',
        'category' => 'GROCERIES',
        'amount_in_cents' => -5000,
        'start_date' => now()->toDateString(),
        'frequency' => 'weekly',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    $categoryId = fn (int $id) => DB::table('transactions')->where('id', $id)->value('category_id');

    expect($categoryId($matched))->toBe($groceries->id)
        ->and($categoryId($unmatched))->toBeNull()
        ->and($categoryId($empty))->toBeNull()
        ->and($categoryId($crossBudget))->toBeNull()
        ->and($categoryId($otherBudgetRent))->toBe(Category::where('budget_id', $otherBudget->id)->value('id'))
        ->and(DB::table('recurring_transaction_templates')->where('id', $templateId)->value('category_id'))->toBe($groceries->id)
        // The legacy string is left untouched.
        ->and(DB::table('transactions')->where('id', $matched)->value('category'))->toBe('  groceries ')
        ->and(DB::table('transactions')->where('id', $unmatched)->value('category'))->toBe('Misc');
});

test('storing a transaction with a category id links it and copies the category name', function () {
    $category = linkingCategory($this->budget, 'Groceries');

    $this->actingAs($this->user)
        ->post(route('budget.transaction.store', $this->budget), [
            'account_id' => $this->account->id,
            'description' => 'Weekly shop',
            'amount' => -42.10,
            'date' => '2026-10-01',
            'category_id' => $category->id,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $transaction = Transaction::where('description', 'Weekly shop')->firstOrFail();
    expect($transaction->category_id)->toBe($category->id)
        ->and($transaction->category)->toBe('Groceries');
});

test('storing a transaction without a category leaves it uncategorized', function () {
    $this->actingAs($this->user)
        ->post(route('budget.transaction.store', $this->budget), [
            'account_id' => $this->account->id,
            'description' => 'Mystery',
            'amount' => -5,
            'date' => '2026-10-01',
            'category_id' => null,
        ])
        ->assertSessionHasNoErrors();

    $transaction = Transaction::where('description', 'Mystery')->firstOrFail();
    expect($transaction->category_id)->toBeNull()
        ->and($transaction->category)->toBeNull();
});

test('storing a transaction rejects a category from another budget', function () {
    $foreign = linkingCategory(Budget::factory()->create(), 'Foreign');

    $this->actingAs($this->user)
        ->post(route('budget.transaction.store', $this->budget), [
            'account_id' => $this->account->id,
            'description' => 'Sneaky',
            'amount' => -5,
            'date' => '2026-10-01',
            'category_id' => $foreign->id,
        ])
        ->assertSessionHasErrors('category_id');

    expect(Transaction::where('description', 'Sneaky')->exists())->toBeFalse();
});

test('a category name from quick add is linked to the matching budget category', function () {
    $category = linkingCategory($this->budget, 'Food & Dining');

    $this->actingAs($this->user)
        ->post(route('budget.transaction.store', $this->budget), [
            'account_id' => $this->account->id,
            'description' => 'Lunch',
            'amount' => -12,
            'date' => '2026-10-01',
            'category' => ' food & dining',
        ])
        ->assertSessionHasNoErrors();

    $transaction = Transaction::where('description', 'Lunch')->firstOrFail();
    expect($transaction->category_id)->toBe($category->id)
        ->and($transaction->category)->toBe('Food & Dining');
});

test('updating a transaction changes its category id and rejects another budgets category', function () {
    $old = linkingCategory($this->budget, 'Old');
    $new = linkingCategory($this->budget, 'New', 1);
    $foreign = linkingCategory(Budget::factory()->create(), 'Foreign');
    $transaction = Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'category_id' => $old->id,
    ]);

    $payload = [
        'account_id' => $this->account->id,
        'description' => 'Updated',
        'amount' => -10,
        'date' => '2026-10-02',
    ];

    $this->actingAs($this->user)
        ->patch(route('budget.transaction.update', [$this->budget, $transaction]), $payload + ['category_id' => $foreign->id])
        ->assertSessionHasErrors('category_id');

    expect($transaction->fresh()->category_id)->toBe($old->id);

    $this->actingAs($this->user)
        ->patch(route('budget.transaction.update', [$this->budget, $transaction]), $payload + ['category_id' => $new->id])
        ->assertSessionHasNoErrors();

    expect($transaction->fresh()->category_id)->toBe($new->id)
        ->and($transaction->fresh()->category)->toBe('New');
});

test('updating a legacy transaction without choosing a category keeps its legacy name', function () {
    $id = rawTransaction($this->budget, $this->account, 'Misc');

    $this->actingAs($this->user)
        ->patch(route('budget.transaction.update', [$this->budget, $id]), [
            'account_id' => $this->account->id,
            'description' => 'Still legacy',
            'amount' => -10,
            'date' => '2026-10-02',
            'category_id' => null,
            'category' => 'Misc',
        ])
        ->assertSessionHasNoErrors();

    $transaction = Transaction::findOrFail($id);
    expect($transaction->category_id)->toBeNull()
        ->and($transaction->category)->toBe('Misc');
});

test('the transaction forms offer the budgets categories', function () {
    linkingCategory($this->budget, 'Groceries');
    linkingCategory(Budget::factory()->create(), 'Foreign');
    $transaction = Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);

    $this->actingAs($this->user)
        ->get(route('budget.transaction.create', [$this->budget, 'category' => 'groceries']))
        ->assertInertia(fn ($page) => $page
            ->has('categories', 1)
            ->where('categories.0.name', 'Groceries')
            ->where('prefillData.category_id', Category::where('name', 'Groceries')->value('id'))
        );

    $this->actingAs($this->user)
        ->get(route('budget.transaction.edit', [$this->budget, $transaction]))
        ->assertInertia(fn ($page) => $page->has('categories', 1));
});

test('renaming a category carries its transactions and templates', function () {
    $category = linkingCategory($this->budget, 'Food');
    $linked = Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
        'amount_in_cents' => -2000,
    ]);
    $template = RecurringTransactionTemplate::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    $this->actingAs($this->user)
        ->patch(route('budgets.categories.update', [$this->budget, $category]), [
            'name' => 'Groceries',
            'amount' => 100,
        ])
        ->assertSessionHasNoErrors();

    expect($linked->fresh()->category_id)->toBe($category->id)
        ->and($linked->fresh()->category)->toBe('Groceries')
        ->and($template->fresh()->category)->toBe('Groceries');

    $this->actingAs($this->user)
        ->get(route('budgets.categories.index', $this->budget))
        ->assertInertia(fn ($page) => $page
            ->where('categories.0.name', 'Groceries')
            ->where('categories.0.spent_cents', 2000)
        );
});

test('spending is grouped by category id with the legacy string as fallback', function () {
    $category = linkingCategory($this->budget, 'Groceries');
    $tx = ['budget_id' => $this->budget->id, 'account_id' => $this->account->id, 'date' => now()->toDateString()];

    Transaction::factory()->create($tx + ['category_id' => $category->id, 'amount_in_cents' => -3000]);
    // Linked by id even though its denormalized name is stale.
    $stale = Transaction::factory()->create($tx + ['category_id' => $category->id, 'amount_in_cents' => -1000]);
    DB::table('transactions')->where('id', $stale->id)->update(['category' => 'Old name']);
    // An unlinked legacy row whose string matches the category name still counts.
    rawTransaction($this->budget, $this->account, 'Groceries', -500);
    // An unmatched legacy row is grouped by its own string.
    rawTransaction($this->budget, $this->account, 'Misc', -700);

    $this->actingAs($this->user)
        ->get(route('budgets.categories.index', $this->budget))
        ->assertInertia(fn ($page) => $page->where('categories.0.spent_cents', 4500));

    $stats = collect($this->budget->getMonthlyStatistics()['by_category'])
        ->mapWithKeys(fn ($row) => [$row->category => $row->total]);

    expect($stats->get('Groceries'))->toBe(-4500)
        ->and($stats->get('Misc'))->toBe(-700)
        ->and($stats->has('Old name'))->toBeFalse();
});

test('deleting a category is blocked by transactions linked by id even when their name differs', function () {
    $category = linkingCategory($this->budget, 'Groceries');
    $transaction = Transaction::factory()->create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);
    DB::table('transactions')->where('id', $transaction->id)->update(['category' => 'Something else']);

    $this->actingAs($this->user)
        ->from(route('budgets.categories.index', $this->budget))
        ->delete(route('budgets.categories.destroy', [$this->budget, $category]))
        ->assertRedirect(route('budgets.categories.index', $this->budget))
        ->assertSessionHas('error');

    expect(Category::find($category->id))->not->toBeNull();
});

test('imported transactions with a category string are linked to a matching category or left unlinked', function () {
    $category = linkingCategory($this->budget, 'Travel');

    $matched = Transaction::create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Airline',
        'category' => 'TRAVEL',
        'amount_in_cents' => -30000,
        'date' => now()->toDateString(),
        'is_plaid_imported' => true,
    ]);
    $unmatched = Transaction::create([
        'budget_id' => $this->budget->id,
        'account_id' => $this->account->id,
        'description' => 'Store',
        'category' => 'Shops',
        'amount_in_cents' => -1500,
        'date' => now()->toDateString(),
        'is_plaid_imported' => true,
    ]);

    expect($matched->category_id)->toBe($category->id)
        ->and($unmatched->category_id)->toBeNull()
        ->and($unmatched->category)->toBe('Shops');
});

test('recurring templates store a category id scoped to the budget', function () {
    $category = linkingCategory($this->budget, 'Utilities');
    $foreign = linkingCategory(Budget::factory()->create(), 'Foreign');

    $payload = [
        'description' => 'Electric bill',
        'amount' => -80,
        'account_id' => $this->account->id,
        'frequency' => 'monthly',
        'day_of_month' => 5,
        'start_date' => '2026-10-05',
        'is_dynamic_amount' => false,
    ];

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), $payload + ['category_id' => $foreign->id])
        ->assertSessionHasErrors('category_id');

    $this->actingAs($this->user)
        ->post(route('recurring-transactions.store', $this->budget), $payload + ['category_id' => $category->id])
        ->assertSessionHasNoErrors();

    $template = RecurringTransactionTemplate::where('description', 'Electric bill')->firstOrFail();
    expect($template->category_id)->toBe($category->id)
        ->and($template->category)->toBe('Utilities');
});
