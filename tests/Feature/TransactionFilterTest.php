<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Budget;
use App\Models\Account;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Budget $budget;
    protected Account $account1;
    protected Account $account2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a user with a budget and accounts
        $this->user = User::factory()->create();
        $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
        $this->account1 = Account::factory()->checking()->create(['budget_id' => $this->budget->id, 'name' => 'Checking Account']);
        $this->account2 = Account::factory()->savings()->create(['budget_id' => $this->budget->id, 'name' => 'Savings Account']);
    }

    public function test_transactions_page_loads_without_filters(): void
    {
        $response = $this
            ->actingAs($this->user)
            ->get(route('budget.transaction.index', $this->budget->id));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->has('transactions')
            ->has('accounts')
            ->has('categories')
        );
    }

    public function test_can_filter_transactions_by_search_term(): void
    {
        // Create transactions with different descriptions
        Transaction::factory()->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'description' => 'Grocery Store Purchase',
            'category' => 'Groceries',
        ]);

        Transaction::factory()->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'description' => 'Restaurant Dinner',
            'category' => 'Dining',
        ]);

        Transaction::factory()->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'description' => 'Gas Station',
            'category' => 'Transportation',
        ]);

        // Search for "Grocery"
        $response = $this
            ->actingAs($this->user)
            ->get(route('budget.transaction.index', ['budget' => $this->budget->id, 'search' => 'Grocery']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('transactions.total', 1)
            ->where('transactions.data.0.description', 'Grocery Store Purchase')
        );
    }

    public function test_can_filter_transactions_by_account(): void
    {
        // Create transactions in different accounts
        Transaction::factory()->count(3)->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
        ]);

        Transaction::factory()->count(2)->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account2->id,
        ]);

        // Filter by account1
        $response = $this
            ->actingAs($this->user)
            ->get(route('budget.transaction.index', [
                'budget' => $this->budget->id,
                'account_id' => $this->account1->id
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('transactions.total', 3)
        );

        // Filter by account2
        $response = $this
            ->actingAs($this->user)
            ->get(route('budget.transaction.index', [
                'budget' => $this->budget->id,
                'account_id' => $this->account2->id
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('transactions.total', 2)
        );
    }

    public function test_can_filter_transactions_by_category(): void
    {
        // Create transactions in different categories
        Transaction::factory()->count(2)->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'category' => 'Groceries',
        ]);

        Transaction::factory()->count(3)->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'category' => 'Dining',
        ]);

        // Filter by Groceries
        $response = $this
            ->actingAs($this->user)
            ->get(route('budget.transaction.index', [
                'budget' => $this->budget->id,
                'category' => 'Groceries'
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('transactions.total', 2)
        );
    }

    public function test_can_filter_transactions_by_this_month(): void
    {
        // Create transactions in different time periods
        Transaction::factory()->count(3)->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'date' => Carbon::now()->startOfMonth(),
        ]);

        Transaction::factory()->count(2)->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'date' => Carbon::now()->subMonth(),
        ]);

        // Filter by this month
        $response = $this
            ->actingAs($this->user)
            ->get(route('budget.transaction.index', [
                'budget' => $this->budget->id,
                'timeframe' => 'this_month'
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('transactions.total', 3)
        );
    }

    public function test_can_filter_transactions_by_last_month(): void
    {
        // Create transactions in different time periods
        Transaction::factory()->count(2)->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'date' => Carbon::now()->subMonth()->startOfMonth(),
        ]);

        Transaction::factory()->count(3)->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'date' => Carbon::now()->startOfMonth(),
        ]);

        // Filter by last month
        $response = $this
            ->actingAs($this->user)
            ->get(route('budget.transaction.index', [
                'budget' => $this->budget->id,
                'timeframe' => 'last_month'
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('transactions.total', 2)
        );
    }

    public function test_can_filter_transactions_by_last_3_months(): void
    {
        // Create transactions in different time periods
        Transaction::factory()->count(5)->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'date' => Carbon::now()->subMonths(2),
        ]);

        Transaction::factory()->count(2)->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'date' => Carbon::now()->subMonths(4),
        ]);

        // Filter by last 3 months
        $response = $this
            ->actingAs($this->user)
            ->get(route('budget.transaction.index', [
                'budget' => $this->budget->id,
                'timeframe' => 'last_3_months'
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('transactions.total', 5)
        );
    }

    public function test_can_filter_transactions_by_this_year(): void
    {
        // Create transactions in different years
        Transaction::factory()->count(4)->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'date' => Carbon::now()->startOfYear(),
        ]);

        Transaction::factory()->count(2)->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'date' => Carbon::now()->subYear(),
        ]);

        // Filter by this year
        $response = $this
            ->actingAs($this->user)
            ->get(route('budget.transaction.index', [
                'budget' => $this->budget->id,
                'timeframe' => 'this_year'
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('transactions.total', 4)
        );
    }

    public function test_can_combine_multiple_filters(): void
    {
        // Create transactions with various combinations
        Transaction::factory()->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'category' => 'Groceries',
            'description' => 'Whole Foods Market',
            'date' => Carbon::now(),
        ]);

        Transaction::factory()->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'category' => 'Dining',
            'description' => 'Restaurant',
            'date' => Carbon::now(),
        ]);

        Transaction::factory()->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account2->id,
            'category' => 'Groceries',
            'description' => 'Whole Foods Market',
            'date' => Carbon::now(),
        ]);

        // Filter by multiple criteria: account1, Groceries category, search "Whole"
        $response = $this
            ->actingAs($this->user)
            ->get(route('budget.transaction.index', [
                'budget' => $this->budget->id,
                'account_id' => $this->account1->id,
                'category' => 'Groceries',
                'search' => 'Whole',
                'timeframe' => 'this_month'
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('transactions.total', 1)
            ->where('transactions.data.0.description', 'Whole Foods Market')
        );
    }

    public function test_filters_are_passed_back_to_view(): void
    {
        $response = $this
            ->actingAs($this->user)
            ->get(route('budget.transaction.index', [
                'budget' => $this->budget->id,
                'search' => 'test',
                'account_id' => $this->account1->id,
                'category' => 'Groceries',
                'timeframe' => 'this_month'
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->has('filters')
            ->where('filters.search', 'test')
            ->where('filters.account_id', $this->account1->id)
            ->where('filters.category', 'Groceries')
            ->where('filters.timeframe', 'this_month')
        );
    }

    public function test_pagination_works_with_filters(): void
    {
        // Create 30 transactions
        Transaction::factory()->count(30)->create([
            'budget_id' => $this->budget->id,
            'account_id' => $this->account1->id,
            'category' => 'Groceries',
        ]);

        // Get first page
        $response = $this
            ->actingAs($this->user)
            ->get(route('budget.transaction.index', [
                'budget' => $this->budget->id,
                'category' => 'Groceries',
                'page' => 1
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('transactions.total', 30)
            ->where('transactions.current_page', 1)
            ->has('transactions.data', 20) // Should have 20 items per page
        );

        // Get second page
        $response = $this
            ->actingAs($this->user)
            ->get(route('budget.transaction.index', [
                'budget' => $this->budget->id,
                'category' => 'Groceries',
                'page' => 2
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('transactions.current_page', 2)
            ->has('transactions.data', 10) // Remaining 10 items
        );
    }
}
