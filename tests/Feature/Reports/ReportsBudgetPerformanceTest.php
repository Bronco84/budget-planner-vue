<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * REPORT-001: budget performance pinned for a realistic mix of transactions.
 * Spending per category is summed in SQL (PR-080); these values must not
 * change with how the summing is done.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(12, 0));

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);

    $this->checking = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->card = Account::factory()->creditCard()->create(['budget_id' => $this->budget->id]);

    $category = fn (Budget $budget, string $name, $amount) => Category::create([
        'budget_id' => $budget->id,
        'name' => $name,
        'amount' => $amount,
        'color' => '#'.substr(md5($name), 0, 6),
    ]);

    $groceries = $category($this->budget, 'Groceries', 300);
    $dining = $category($this->budget, 'Dining', 100);
    $rent = $category($this->budget, 'Rent', 0);
    $category($this->budget, 'Utilities', 50);

    $otherBudget = Budget::factory()->create();
    $otherAccount = Account::factory()->checking()->create(['budget_id' => $otherBudget->id]);
    $otherGroceries = $category($otherBudget, 'Groceries', 300);

    $rows = [
        // [account, date, amount, category_id, legacy category string, extra]
        [$this->checking, '2026-01-05', -5000, $groceries->id, 'Groceries', []],
        [$this->card, '2026-02-10', -2500, $groceries->id, null, []],
        [$this->checking, '2026-01-20', -1000, null, 'Groceries', []],        // legacy, name only
        [$this->checking, '2026-01-21', -700, null, 'groceries ', []],         // legacy, differs in case and spaces: still counts (PR-083)
        [$this->checking, '2026-01-22', -4000, $dining->id, 'Groceries', []],  // id wins over the string
        [$this->checking, '2026-02-01', 1500, $groceries->id, 'Groceries', []], // refund, not spending
        [$this->card, '2026-02-14', -3000, null, 'Dining', []],               // legacy on the card
        [$this->checking, '2026-02-01', -120000, $rent->id, 'Rent', []],      // nothing allocated
        [$this->checking, '2026-02-02', -9999, $groceries->id, 'Groceries', ['removed_by_bank_at' => '2026-02-05 00:00:00']],
        [$this->checking, '2025-12-31', -77777, $groceries->id, 'Groceries', []], // before the range
        [$this->checking, '2026-03-01', -66666, $groceries->id, 'Groceries', []], // after the range
        [$this->checking, '2026-01-15', -888, $otherGroceries->id, 'Groceries', []], // linked to another budget's category
        [$this->checking, '2026-01-16', -600, null, null, []],                // uncategorised
        [$otherAccount, '2026-01-10', -55555, $otherGroceries->id, 'Groceries', []], // another budget
        [$otherAccount, '2026-01-11', -44444, null, 'Groceries', []],
    ];

    // Raw inserts: the model would relink or rename these on save, but rows
    // written before category ids existed (or by older importers) look like this.
    foreach ($rows as [$account, $date, $amount, $categoryId, $legacy, $extra]) {
        DB::table('transactions')->insert(array_merge([
            'budget_id' => $account->budget_id,
            'account_id' => $account->id,
            'description' => 'Fixture',
            'category' => $legacy,
            'category_id' => $categoryId,
            'amount_in_cents' => $amount,
            'date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }
});

function budgetPerformanceReport($test, array $query = []): array
{
    $response = $test->actingAs($test->user)->get(route('reports.index', array_merge([
        $test->budget,
        'date_range' => 'custom',
        'start_date' => '2026-01-01',
        'end_date' => '2026-02-28',
    ], $query)));

    $response->assertOk();

    return $response->viewData('page')['props']['budgetPerformance'];
}

function performanceRow(string $name, int $allocated, int $spent, int|float $percentUsed): array
{
    return [
        'category' => $name,
        'allocated' => $allocated,
        'spent' => $spent,
        'remaining' => $allocated - $spent,
        'percentUsed' => $percentUsed,
        'isOverBudget' => $spent > $allocated,
        'color' => '#'.substr(md5($name), 0, 6),
    ];
}

test('budget performance for all accounts matches the pinned values', function () {
    $performance = budgetPerformanceReport($this);

    // Two full months: allocations are twice the monthly amount.
    expect($performance['categories'])->toBe([
        performanceRow('Rent', 0, 120000, 0),
        performanceRow('Groceries', 60000, 9200, 9200 / 60000 * 100),
        performanceRow('Dining', 20000, 7000, 7000 / 20000 * 100),
        performanceRow('Utilities', 10000, 0, 0),
    ])->and($performance['summary'])->toBe([
        'totalAllocated' => 90000,
        'totalSpent' => 136200,
        'totalRemaining' => -46200,
        'categoriesOverBudget' => 1,
    ]);
});

test('budget performance filtered to one account matches the pinned values', function () {
    $performance = budgetPerformanceReport($this, ['account_id' => $this->card->id]);

    expect($performance['categories'])->toBe([
        performanceRow('Dining', 20000, 3000, 3000 / 20000 * 100),
        performanceRow('Groceries', 60000, 2500, 2500 / 60000 * 100),
        performanceRow('Rent', 0, 0, 0),
        performanceRow('Utilities', 10000, 0, 0),
    ])->and($performance['summary'])->toBe([
        'totalAllocated' => 90000,
        'totalSpent' => 5500,
        'totalRemaining' => 84500,
        'categoriesOverBudget' => 0,
    ]);
});

test('budget performance sums spending in SQL instead of loading the range', function () {
    $hydrated = 0;
    Transaction::retrieved(function () use (&$hydrated) {
        $hydrated++;
    });

    budgetPerformanceReport($this);

    // Cash flow loads the 10 in-range rows of this budget (the bank-removed
    // one excluded) and spending patterns the 9 expenses among them; budget
    // performance must not load them a third time.
    expect($hydrated)->toBeLessThanOrEqual(10 + 9);
});
