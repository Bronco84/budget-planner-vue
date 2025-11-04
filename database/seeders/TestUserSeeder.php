<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\PayoffPlan;
use App\Models\PayoffPlanDebt;
use App\Models\RecurringTransactionTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;
use Carbon\Carbon;

class TestUserSeeder extends Seeder
{
    private $faker;
    private $user;
    private $budget;
    private $accounts = [];
    private $categories = [];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->faker = Faker::create();

        $this->createUser();
        $this->createBudget();
        $this->createCategories();
        $this->createAccounts();
        $this->createPayoffPlans();
        $this->createRecurringTransactions();
        $this->createTransactions();
        $this->setUserPreferences();

        $this->command->info('✅ Test user created successfully!');
        $this->command->info('📧 Email: demo@example.com');
        $this->command->info('🔑 Password: password');
        $this->command->info('💰 Budget: ' . $this->budget->name);
        $this->command->info('🏦 Accounts: ' . count($this->accounts));
        $this->command->info('📊 Transactions: ' . Transaction::where('budget_id', $this->budget->id)->count());
    }

    /**
     * Create the test user
     */
    private function createUser(): void
    {
        $this->user = User::create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $this->command->info('Created user: ' . $this->user->email);
    }

    /**
     * Create a budget for the user
     */
    private function createBudget(): void
    {
        $this->budget = Budget::create([
            'user_id' => $this->user->id,
            'name' => 'Demo Family Budget 2025',
            'description' => 'A realistic budget with sample data for testing and demonstration purposes.',
        ]);

        $this->command->info('Created budget: ' . $this->budget->name);
    }

    /**
     * Create budget categories
     */
    private function createCategories(): void
    {
        $categoryData = [
            ['name' => 'Housing', 'amount' => 1800, 'color' => '#ef4444'],
            ['name' => 'Food & Dining', 'amount' => 600, 'color' => '#f59e0b'],
            ['name' => 'Transportation', 'amount' => 400, 'color' => '#10b981'],
            ['name' => 'Utilities', 'amount' => 200, 'color' => '#3b82f6'],
            ['name' => 'Entertainment', 'amount' => 150, 'color' => '#8b5cf6'],
            ['name' => 'Insurance', 'amount' => 300, 'color' => '#6366f1'],
            ['name' => 'Savings', 'amount' => 500, 'color' => '#06b6d4'],
            ['name' => 'Miscellaneous', 'amount' => 200, 'color' => '#64748b'],
        ];

        foreach ($categoryData as $data) {
            $this->categories[] = Category::create([
                'budget_id' => $this->budget->id,
                'name' => $data['name'],
                'amount' => $data['amount'],
                'color' => $data['color'],
            ]);
        }

        $this->command->info('Created ' . count($this->categories) . ' categories');
    }

    /**
     * Create accounts with different types
     */
    private function createAccounts(): void
    {
        // 1. Checking Account
        $this->accounts['checking'] = Account::create([
            'budget_id' => $this->budget->id,
            'name' => 'Chase Premier Checking',
            'type' => 'checking',
            'current_balance_cents' => 350000, // $3,500
            'account_number' => '****' . rand(1000, 9999),
            'institution' => 'Chase Bank',
            'is_active' => true,
            'include_in_budget' => true,
            'exclude_from_total_balance' => false,
            'balance_updated_at' => now(),
        ]);

        // 2. Savings Account
        $this->accounts['savings'] = Account::create([
            'budget_id' => $this->budget->id,
            'name' => 'Chase Savings Account',
            'type' => 'savings',
            'current_balance_cents' => 1200000, // $12,000
            'account_number' => '****' . rand(1000, 9999),
            'institution' => 'Chase Bank',
            'is_active' => true,
            'include_in_budget' => true,
            'exclude_from_total_balance' => true,
            'balance_updated_at' => now(),
        ]);

        // 3. Credit Card
        $this->accounts['credit_card'] = Account::create([
            'budget_id' => $this->budget->id,
            'name' => 'American Express Blue Cash',
            'type' => 'credit card',
            'current_balance_cents' => -245000, // -$2,450 (liability)
            'account_number' => '****' . rand(1000, 9999),
            'institution' => 'American Express',
            'is_active' => true,
            'include_in_budget' => true,
            'exclude_from_total_balance' => false,
            'balance_updated_at' => now(),
        ]);

        // 4. Mortgage
        $this->accounts['mortgage'] = Account::create([
            'budget_id' => $this->budget->id,
            'name' => 'Home Mortgage',
            'type' => 'mortgage',
            'current_balance_cents' => -28500000, // -$285,000 (liability)
            'account_number' => '****' . rand(1000, 9999),
            'institution' => 'Wells Fargo',
            'is_active' => true,
            'include_in_budget' => false,
            'exclude_from_total_balance' => false,
            'balance_updated_at' => now(),
        ]);

        // 5. Car Loan (In Progress - 34% paid off)
        $this->accounts['car_loan'] = Account::create([
            'budget_id' => $this->budget->id,
            'name' => 'Toyota Camry Auto Loan',
            'type' => 'loan',
            'current_balance_cents' => -1850000, // -$18,500 (started at -$28,000)
            'account_number' => '****' . rand(1000, 9999),
            'institution' => 'Capital One Auto Finance',
            'is_active' => true,
            'include_in_budget' => true,
            'exclude_from_total_balance' => false,
            'balance_updated_at' => now(),
        ]);

        // 6. Student Loan (In Progress - 28% paid off)
        $this->accounts['student_loan'] = Account::create([
            'budget_id' => $this->budget->id,
            'name' => 'Federal Student Loan',
            'type' => 'loan',
            'current_balance_cents' => -3240000, // -$32,400 (started at -$45,000)
            'account_number' => '****' . rand(1000, 9999),
            'institution' => 'Navient',
            'is_active' => true,
            'include_in_budget' => true,
            'exclude_from_total_balance' => false,
            'balance_updated_at' => now(),
        ]);

        // 7. Personal Loan (Nearly Paid Off - 85% paid off)
        $this->accounts['personal_loan'] = Account::create([
            'budget_id' => $this->budget->id,
            'name' => 'Personal Loan',
            'type' => 'loan',
            'current_balance_cents' => -120000, // -$1,200 (started at -$8,000)
            'account_number' => '****' . rand(1000, 9999),
            'institution' => 'Marcus by Goldman Sachs',
            'is_active' => true,
            'include_in_budget' => true,
            'exclude_from_total_balance' => false,
            'balance_updated_at' => now(),
        ]);

        // 8. Second Credit Card (No Plan - Won't Show on Debt Payoff Page)
        $this->accounts['credit_card_2'] = Account::create([
            'budget_id' => $this->budget->id,
            'name' => 'Discover It Card',
            'type' => 'credit card',
            'current_balance_cents' => -45000, // -$450 (liability, no plan)
            'account_number' => '****' . rand(1000, 9999),
            'institution' => 'Discover',
            'is_active' => true,
            'include_in_budget' => true,
            'exclude_from_total_balance' => false,
            'balance_updated_at' => now(),
        ]);

        // 9. Store Credit Card (Paid Off - Inactive Plan)
        $this->accounts['store_card'] = Account::create([
            'budget_id' => $this->budget->id,
            'name' => 'Target REDcard',
            'type' => 'credit card',
            'current_balance_cents' => 0, // $0 (paid off!)
            'account_number' => '****' . rand(1000, 9999),
            'institution' => 'Target',
            'is_active' => true,
            'include_in_budget' => true,
            'exclude_from_total_balance' => false,
            'balance_updated_at' => now(),
        ]);

        $this->command->info('Created ' . count($this->accounts) . ' accounts');
    }

    /**
     * Create multiple debt payoff plans with realistic payment schedules
     */
    private function createPayoffPlans(): void
    {
        // PLAN 1: "Aggressive Debt Payoff 2025" (ACTIVE)
        // Focus on high-interest consumer debt with avalanche strategy
        $activePlan1 = PayoffPlan::create([
            'budget_id' => $this->budget->id,
            'name' => 'Aggressive Debt Payoff 2025',
            'description' => 'Tackling high-interest consumer debts using the avalanche method. Paying highest interest rates first to minimize total interest paid.',
            'strategy' => 'avalanche',
            'monthly_extra_payment_cents' => 80000, // $800 extra per month
            'is_active' => true,
            'start_date' => Carbon::now()->subMonths(6)->startOfMonth(), // Started 6 months ago
        ]);

        // Add Personal Loan to Active Plan 1 (highest interest, nearly paid off)
        PayoffPlanDebt::create([
            'payoff_plan_id' => $activePlan1->id,
            'account_id' => $this->accounts['personal_loan']->id,
            'starting_balance_cents' => 800000, // Started at $8,000
            'interest_rate' => 11.50, // 11.5% APR
            'minimum_payment_cents' => 30000, // $300 minimum payment
            'priority' => 1, // Highest priority (highest interest)
        ]);

        // Add Credit Card to Active Plan 1
        PayoffPlanDebt::create([
            'payoff_plan_id' => $activePlan1->id,
            'account_id' => $this->accounts['credit_card']->id,
            'starting_balance_cents' => 350000, // Started at $3,500
            'interest_rate' => 18.99, // 18.99% APR
            'minimum_payment_cents' => 7500, // $75 minimum payment
            'priority' => 2,
        ]);

        // Add Car Loan to Active Plan 1
        PayoffPlanDebt::create([
            'payoff_plan_id' => $activePlan1->id,
            'account_id' => $this->accounts['car_loan']->id,
            'starting_balance_cents' => 2800000, // Started at $28,000
            'interest_rate' => 4.50, // 4.5% APR
            'minimum_payment_cents' => 52500, // $525 monthly payment
            'priority' => 3,
        ]);

        // Add Student Loan to Active Plan 1
        PayoffPlanDebt::create([
            'payoff_plan_id' => $activePlan1->id,
            'account_id' => $this->accounts['student_loan']->id,
            'starting_balance_cents' => 4500000, // Started at $45,000
            'interest_rate' => 5.80, // 5.8% APR
            'minimum_payment_cents' => 45000, // $450 monthly payment
            'priority' => 4,
        ]);

        // PLAN 2: "Mortgage Long-term Plan" (ACTIVE)
        // Separate plan for mortgage with extra principal payments
        $activePlan2 = PayoffPlan::create([
            'budget_id' => $this->budget->id,
            'name' => 'Mortgage Long-term Plan',
            'description' => 'Long-term mortgage payoff strategy with additional principal payments to reduce interest and pay off early.',
            'strategy' => 'custom',
            'monthly_extra_payment_cents' => 20000, // $200 extra per month
            'is_active' => true,
            'start_date' => Carbon::now()->subMonths(6)->startOfMonth(),
        ]);

        // Add Mortgage to Active Plan 2
        PayoffPlanDebt::create([
            'payoff_plan_id' => $activePlan2->id,
            'account_id' => $this->accounts['mortgage']->id,
            'starting_balance_cents' => 28500000, // Started at $285,000 (no change yet for demo)
            'interest_rate' => 3.75, // 3.75% APR
            'minimum_payment_cents' => 180000, // $1,800 monthly payment
            'priority' => 1,
        ]);

        // PLAN 3: "High-Interest Elimination 2024" (INACTIVE/COMPLETED)
        // Completed plan from earlier in the year
        $completedPlan = PayoffPlan::create([
            'budget_id' => $this->budget->id,
            'name' => 'High-Interest Elimination 2024',
            'description' => 'Successfully paid off high-interest store credit card. Plan completed!',
            'strategy' => 'avalanche',
            'monthly_extra_payment_cents' => 50000, // Was $500 extra per month
            'is_active' => false, // INACTIVE - completed
            'start_date' => Carbon::now()->subMonths(12)->startOfMonth(), // Started 12 months ago
        ]);

        // Add Store Card to Completed Plan (now paid off)
        PayoffPlanDebt::create([
            'payoff_plan_id' => $completedPlan->id,
            'account_id' => $this->accounts['store_card']->id,
            'starting_balance_cents' => 350000, // Started at $3,500
            'interest_rate' => 23.99, // 23.99% APR (very high)
            'minimum_payment_cents' => 10000, // Was $100 minimum payment
            'priority' => 1,
        ]);

        $this->command->info('Created 3 payoff plans: 2 active (7 debts), 1 completed (1 debt)');
        $this->command->info('Note: Discover It Card has no payoff plan and will not appear on debt payoff page');
    }

    /**
     * Create recurring transaction templates
     */
    private function createRecurringTransactions(): void
    {
        $recurringData = [
            [
                'description' => 'Salary Deposit',
                'category' => 'Income',
                'amount_in_cents' => 280000, // $2,800
                'frequency' => RecurringTransactionTemplate::FREQUENCY_BIWEEKLY,
                'account_id' => $this->accounts['checking']->id,
                'day_of_week' => 5, // Friday
            ],
            [
                'description' => 'Mortgage Payment',
                'category' => 'Housing',
                'amount_in_cents' => -180000, // -$1,800
                'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
                'account_id' => $this->accounts['checking']->id,
                'day_of_month' => 1,
            ],
            [
                'description' => 'Electric Bill - Energy Company',
                'category' => 'Utilities',
                'amount_in_cents' => -12000, // -$120
                'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
                'account_id' => $this->accounts['checking']->id,
                'day_of_month' => 15,
            ],
            [
                'description' => 'Internet - Spectrum',
                'category' => 'Utilities',
                'amount_in_cents' => -8000, // -$80
                'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
                'account_id' => $this->accounts['checking']->id,
                'day_of_month' => 10,
            ],
            [
                'description' => 'Car Insurance - State Farm',
                'category' => 'Insurance',
                'amount_in_cents' => -15000, // -$150
                'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
                'account_id' => $this->accounts['checking']->id,
                'day_of_month' => 5,
            ],
            [
                'description' => 'Gym Membership - Planet Fitness',
                'category' => 'Entertainment',
                'amount_in_cents' => -4500, // -$45
                'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
                'account_id' => $this->accounts['credit_card']->id,
                'day_of_month' => 20,
            ],
            [
                'description' => 'Netflix Subscription',
                'category' => 'Entertainment',
                'amount_in_cents' => -1500, // -$15
                'frequency' => RecurringTransactionTemplate::FREQUENCY_MONTHLY,
                'account_id' => $this->accounts['credit_card']->id,
                'day_of_month' => 12,
            ],
        ];

        foreach ($recurringData as $data) {
            RecurringTransactionTemplate::create([
                'budget_id' => $this->budget->id,
                'account_id' => $data['account_id'],
                'description' => $data['description'],
                'category' => $data['category'],
                'amount_in_cents' => $data['amount_in_cents'],
                'start_date' => Carbon::now()->subMonths(6)->startOfMonth(),
                'frequency' => $data['frequency'],
                'day_of_month' => $data['day_of_month'] ?? null,
                'day_of_week' => $data['day_of_week'] ?? null,
                'auto_generate' => true,
            ]);
        }

        $this->command->info('Created recurring transaction templates');
    }

    /**
     * Create realistic transactions for the past 6 months
     */
    private function createTransactions(): void
    {
        $startDate = Carbon::now()->subMonths(6);
        $endDate = Carbon::now();

        // Generate salary deposits (biweekly)
        $this->generateSalaryDeposits($startDate, $endDate);

        // Generate monthly bills
        $this->generateMonthlyBills($startDate, $endDate);

        // Generate grocery shopping
        $this->generateGroceryShopping($startDate, $endDate);

        // Generate gas/fuel purchases
        $this->generateGasPurchases($startDate, $endDate);

        // Generate restaurant/dining
        $this->generateDining($startDate, $endDate);

        // Generate online shopping
        $this->generateOnlineShopping($startDate, $endDate);

        // Generate miscellaneous transactions
        $this->generateMiscellaneous($startDate, $endDate);

        // Generate credit card payments
        $this->generateCreditCardPayments($startDate, $endDate);

        $this->command->info('Created historical transactions');
    }

    private function generateSalaryDeposits(Carbon $start, Carbon $end): void
    {
        $date = $start->copy();
        while ($date->lte($end)) {
            if ($date->dayOfWeek === Carbon::FRIDAY) {
                Transaction::create([
                    'budget_id' => $this->budget->id,
                    'account_id' => $this->accounts['checking']->id,
                    'description' => 'Payroll Deposit - ABC Company',
                    'category' => 'Income',
                    'amount_in_cents' => 280000,
                    'date' => $date->copy(),
                    'is_reconciled' => true,
                    'plaid_transaction_id' => 'demo_' . $this->faker->uuid(),
                    'is_plaid_imported' => true,
                ]);
                $date->addWeeks(2);
            } else {
                $date->addDay();
            }
        }
    }

    private function generateMonthlyBills(Carbon $start, Carbon $end): void
    {
        $bills = [
            ['description' => 'Mortgage Payment - Wells Fargo', 'amount' => -180000, 'day' => 1, 'category' => 'Housing'],
            ['description' => 'Electric Bill', 'amount' => rand(-10000, -15000), 'day' => 15, 'category' => 'Utilities'],
            ['description' => 'Water Bill', 'amount' => -4500, 'day' => 18, 'category' => 'Utilities'],
            ['description' => 'Internet - Spectrum', 'amount' => -8000, 'day' => 10, 'category' => 'Utilities'],
            ['description' => 'Car Insurance', 'amount' => -15000, 'day' => 5, 'category' => 'Insurance'],
            ['description' => 'Cell Phone - Verizon', 'amount' => -8500, 'day' => 22, 'category' => 'Utilities'],
        ];

        $currentMonth = $start->copy()->startOfMonth();
        while ($currentMonth->lte($end)) {
            foreach ($bills as $bill) {
                $billDate = $currentMonth->copy()->day($bill['day']);
                if ($billDate->gte($start) && $billDate->lte($end)) {
                    Transaction::create([
                        'budget_id' => $this->budget->id,
                        'account_id' => $this->accounts['checking']->id,
                        'description' => $bill['description'],
                        'category' => $bill['category'],
                        'amount_in_cents' => is_array($bill['amount']) ? rand($bill['amount'][0], $bill['amount'][1]) : $bill['amount'],
                        'date' => $billDate,
                        'is_reconciled' => true,
                        'plaid_transaction_id' => 'demo_' . $this->faker->uuid(),
                        'is_plaid_imported' => true,
                    ]);
                }
            }
            $currentMonth->addMonth();
        }
    }

    private function generateGroceryShopping(Carbon $start, Carbon $end): void
    {
        $stores = ['Walmart', 'Kroger', 'Target', 'Whole Foods', 'Aldi', 'Trader Joe\'s'];
        $date = $start->copy();

        while ($date->lte($end)) {
            // 2-3 grocery trips per week
            for ($i = 0; $i < rand(2, 3); $i++) {
                $shopDate = $date->copy()->addDays(rand(0, 6));
                if ($shopDate->lte($end)) {
                    Transaction::create([
                        'budget_id' => $this->budget->id,
                        'account_id' => rand(0, 1) ? $this->accounts['checking']->id : $this->accounts['credit_card']->id,
                        'description' => $this->faker->randomElement($stores) . ' - Groceries',
                        'category' => 'Food & Dining',
                        'amount_in_cents' => -rand(4500, 15000), // $45 - $150
                        'date' => $shopDate,
                        'is_reconciled' => true,
                        'plaid_transaction_id' => 'demo_' . $this->faker->uuid(),
                        'is_plaid_imported' => true,
                    ]);
                }
            }
            $date->addWeek();
        }
    }

    private function generateGasPurchases(Carbon $start, Carbon $end): void
    {
        $stations = ['Shell', 'BP', 'Chevron', 'Exxon', 'Circle K', 'QuikTrip'];
        $date = $start->copy();

        while ($date->lte($end)) {
            // 1-2 gas fill-ups per week
            for ($i = 0; $i < rand(1, 2); $i++) {
                $gasDate = $date->copy()->addDays(rand(0, 6));
                if ($gasDate->lte($end)) {
                    Transaction::create([
                        'budget_id' => $this->budget->id,
                        'account_id' => $this->accounts['credit_card']->id,
                        'description' => $this->faker->randomElement($stations) . ' Gas Station',
                        'category' => 'Transportation',
                        'amount_in_cents' => -rand(3500, 6500), // $35 - $65
                        'date' => $gasDate,
                        'is_reconciled' => true,
                        'plaid_transaction_id' => 'demo_' . $this->faker->uuid(),
                        'is_plaid_imported' => true,
                    ]);
                }
            }
            $date->addWeek();
        }
    }

    private function generateDining(Carbon $start, Carbon $end): void
    {
        $restaurants = [
            'Chipotle', 'Panera Bread', 'Olive Garden', 'Chili\'s', 'Applebee\'s',
            'Local Pizza Place', 'Thai Restaurant', 'Sushi Bar', 'McDonald\'s', 'Starbucks'
        ];
        $date = $start->copy();

        while ($date->lte($end)) {
            // 3-5 dining transactions per week
            for ($i = 0; $i < rand(3, 5); $i++) {
                $dineDate = $date->copy()->addDays(rand(0, 6));
                if ($dineDate->lte($end)) {
                    Transaction::create([
                        'budget_id' => $this->budget->id,
                        'account_id' => $this->faker->boolean(70) ? $this->accounts['credit_card']->id : $this->accounts['checking']->id,
                        'description' => $this->faker->randomElement($restaurants),
                        'category' => 'Food & Dining',
                        'amount_in_cents' => -rand(800, 8500), // $8 - $85
                        'date' => $dineDate,
                        'is_reconciled' => true,
                        'plaid_transaction_id' => 'demo_' . $this->faker->uuid(),
                        'is_plaid_imported' => true,
                    ]);
                }
            }
            $date->addWeek();
        }
    }

    private function generateOnlineShopping(Carbon $start, Carbon $end): void
    {
        $merchants = ['Amazon', 'eBay', 'Walmart.com', 'Target.com', 'Best Buy', 'Etsy', 'Apple Store'];
        $date = $start->copy();

        while ($date->lte($end)) {
            // 1-3 online purchases per month
            for ($i = 0; $i < rand(1, 3); $i++) {
                $shopDate = $date->copy()->addDays(rand(0, 30));
                if ($shopDate->lte($end)) {
                    Transaction::create([
                        'budget_id' => $this->budget->id,
                        'account_id' => $this->accounts['credit_card']->id,
                        'description' => $this->faker->randomElement($merchants),
                        'category' => 'Miscellaneous',
                        'amount_in_cents' => -rand(2500, 20000), // $25 - $200
                        'date' => $shopDate,
                        'is_reconciled' => true,
                        'plaid_transaction_id' => 'demo_' . $this->faker->uuid(),
                        'is_plaid_imported' => true,
                    ]);
                }
            }
            $date->addMonth();
        }
    }

    private function generateMiscellaneous(Carbon $start, Carbon $end): void
    {
        $misc = [
            ['description' => 'ATM Withdrawal', 'amount' => -rand(2000, 10000), 'category' => 'Miscellaneous'],
            ['description' => 'Doctor Visit Copay', 'amount' => -3000, 'category' => 'Insurance'],
            ['description' => 'Pharmacy - CVS', 'amount' => -rand(1500, 4500), 'category' => 'Insurance'],
            ['description' => 'Haircut', 'amount' => -3500, 'category' => 'Miscellaneous'],
            ['description' => 'Movie Tickets', 'amount' => -rand(2500, 5000), 'category' => 'Entertainment'],
            ['description' => 'Streaming Service', 'amount' => -rand(1000, 2000), 'category' => 'Entertainment'],
        ];

        $date = $start->copy();
        while ($date->lte($end)) {
            // 2-4 misc transactions per month
            for ($i = 0; $i < rand(2, 4); $i++) {
                $miscDate = $date->copy()->addDays(rand(0, 30));
                if ($miscDate->lte($end)) {
                    $item = $this->faker->randomElement($misc);
                    Transaction::create([
                        'budget_id' => $this->budget->id,
                        'account_id' => $this->faker->randomElement([$this->accounts['checking']->id, $this->accounts['credit_card']->id]),
                        'description' => $item['description'],
                        'category' => $item['category'],
                        'amount_in_cents' => is_array($item['amount']) ? rand($item['amount'][0], $item['amount'][1]) : $item['amount'],
                        'date' => $miscDate,
                        'is_reconciled' => true,
                        'plaid_transaction_id' => 'demo_' . $this->faker->uuid(),
                        'is_plaid_imported' => true,
                    ]);
                }
            }
            $date->addMonth();
        }
    }

    private function generateCreditCardPayments(Carbon $start, Carbon $end): void
    {
        $currentMonth = $start->copy()->startOfMonth();

        while ($currentMonth->lte($end)) {
            $paymentDate = $currentMonth->copy()->day(25); // Pay on 25th of each month
            if ($paymentDate->gte($start) && $paymentDate->lte($end)) {
                Transaction::create([
                    'budget_id' => $this->budget->id,
                    'account_id' => $this->accounts['checking']->id,
                    'description' => 'Credit Card Payment - American Express',
                    'category' => 'Payment',
                    'amount_in_cents' => -rand(50000, 100000), // $500 - $1,000
                    'date' => $paymentDate,
                    'is_reconciled' => true,
                    'plaid_transaction_id' => 'demo_' . $this->faker->uuid(),
                    'is_plaid_imported' => true,
                ]);
            }
            $currentMonth->addMonth();
        }
    }

    /**
     * Set user preferences
     */
    private function setUserPreferences(): void
    {
        UserPreference::create([
            'user_id' => $this->user->id,
            'theme' => 'light',
            'notifications_enabled' => true,
            'show_balance_projection' => true,
            'other_preferences' => [
                'active_budget_id' => $this->budget->id,
                'account_type_order' => [
                    'checking',
                    'savings',
                    'money market',
                    'cd',
                    'investment',
                    'credit card',
                    'credit',
                    'loan',
                    'line of credit',
                    'mortgage',
                    'other'
                ],
            ],
        ]);

        $this->command->info('Set user preferences');
    }
}
