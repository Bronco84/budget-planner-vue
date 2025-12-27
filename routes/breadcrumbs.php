<?php

use Diglactic\Breadcrumbs\Breadcrumbs;
use Diglactic\Breadcrumbs\Generator as BreadcrumbTrail;
use App\Models\Budget;
use App\Models\Account;
use App\Models\Property;

// ============================================================
// ROOT LEVEL PAGES
// ============================================================

// Home / Budget (Root of most pages)
Breadcrumbs::for('budgets.show', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->push($budget->name, route('budgets.show', $budget));
});

// Standalone Pages
Breadcrumbs::for('profile.edit', function (BreadcrumbTrail $trail) {
    $trail->push('Profile', route('profile.edit'));
});

Breadcrumbs::for('calendar.index', function (BreadcrumbTrail $trail) {
    // Calendar is under active budget context
    $activeBudget = auth()->user()->getActiveBudget();
    if ($activeBudget) {
        $trail->parent('budgets.show', $activeBudget);
    }
    $trail->push('Calendar', route('calendar.index'));
});

// ============================================================
// BUDGET MANAGEMENT
// ============================================================

Breadcrumbs::for('budgets.index', function (BreadcrumbTrail $trail) {
    $trail->push('Budgets', route('budgets.index'));
});

Breadcrumbs::for('budgets.create', function (BreadcrumbTrail $trail) {
    $trail->parent('budgets.index');
    $trail->push('Create Budget', route('budgets.create'));
});

Breadcrumbs::for('budgets.edit', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Edit Budget', route('budgets.edit', $budget));
});

Breadcrumbs::for('budgets.setup', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Setup', route('budgets.setup', $budget));
});

// ============================================================
// ACCOUNTS
// ============================================================

Breadcrumbs::for('budgets.accounts.create', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Create Account', route('budgets.accounts.create', $budget));
});

Breadcrumbs::for('budgets.accounts.edit', function (BreadcrumbTrail $trail, Budget $budget, Account $account) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Edit Account: ' . $account->name, route('budgets.accounts.edit', [$budget, $account]));
});

// ============================================================
// PROPERTIES
// ============================================================

Breadcrumbs::for('budgets.properties.index', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Properties', route('budgets.properties.index', $budget));
});

Breadcrumbs::for('budgets.properties.create', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.properties.index', $budget);
    $trail->push('Add Property', route('budgets.properties.create', $budget));
});

Breadcrumbs::for('budgets.properties.edit', function (BreadcrumbTrail $trail, Budget $budget, $property) {
    $trail->parent('budgets.properties.index', $budget);
    $trail->push('Edit: ' . $property->name, route('budgets.properties.edit', [$budget, $property]));
});

Breadcrumbs::for('budgets.properties.show', function (BreadcrumbTrail $trail, Budget $budget, $property) {
    $trail->parent('budgets.properties.index', $budget);
    $trail->push($property->name, route('budgets.properties.show', [$budget, $property]));
});

Breadcrumbs::for('budget.account.projections', function (BreadcrumbTrail $trail, Budget $budget, Account $account) {
    $trail->parent('budgets.show', $budget);
    $trail->push($account->name . ' Projections', route('budget.account.projections', [$budget, $account]));
});

Breadcrumbs::for('budget.account.balance-projection', function (BreadcrumbTrail $trail, Budget $budget, Account $account) {
    $trail->parent('budgets.show', $budget);
    $trail->push($account->name . ' Balance Chart', route('budget.account.balance-projection', [$budget, $account]));
});

// ============================================================
// PLAID / BANK CONNECTIONS
// ============================================================

Breadcrumbs::for('plaid.discover', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Connect Bank Account', route('plaid.discover', $budget));
});

Breadcrumbs::for('plaid.link', function (BreadcrumbTrail $trail, Budget $budget, Account $account) {
    $trail->parent('budgets.accounts.edit', $budget, $account);
    $trail->push('Bank Connection', route('plaid.link', [$budget, $account]));
});

// ============================================================
// CATEGORIES
// ============================================================

Breadcrumbs::for('budgets.categories.index', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Categories', route('budgets.categories.index', $budget));
});

Breadcrumbs::for('budgets.categories.create', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.categories.index', $budget);
    $trail->push('Create Category', route('budgets.categories.create', $budget));
});

Breadcrumbs::for('budgets.categories.edit', function (BreadcrumbTrail $trail, Budget $budget, $category) {
    $trail->parent('budgets.categories.index', $budget);
    $trail->push('Edit Category: ' . $category->name, route('budgets.categories.edit', [$budget, $category]));
});

// ============================================================
// TRANSACTIONS
// ============================================================

Breadcrumbs::for('budget.transaction.index', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Transactions', route('budget.transaction.index', $budget));
});

Breadcrumbs::for('budgets.transactions.create', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Create Transaction', route('budgets.transactions.create', $budget));
});

Breadcrumbs::for('budget.transaction.edit', function (BreadcrumbTrail $trail, Budget $budget, $transaction) {
    $trail->parent('budget.transaction.index', $budget);
    $trail->push('Edit Transaction', route('budget.transaction.edit', [$budget, $transaction]));
});

// ============================================================
// RECURRING TRANSACTIONS
// ============================================================

Breadcrumbs::for('recurring-transactions.index', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Recurring Transactions', route('recurring-transactions.index', $budget));
});

Breadcrumbs::for('recurring-transactions.create', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('recurring-transactions.index', $budget);
    $trail->push('Create Recurring Transaction', route('recurring-transactions.create', $budget));
});

Breadcrumbs::for('recurring-transactions.edit', function (BreadcrumbTrail $trail, Budget $budget, $recurringTransaction) {
    $trail->parent('recurring-transactions.index', $budget);
    $trail->push('Edit: ' . $recurringTransaction->description, route('recurring-transactions.edit', [$budget, $recurringTransaction]));
});

Breadcrumbs::for('recurring-transactions.analysis', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('recurring-transactions.index', $budget);
    $trail->push('Recurring Transaction Analysis', route('recurring-transactions.analysis', $budget));
});

// Recurring Transaction Rules
Breadcrumbs::for('recurring-transactions.rules.index', function (BreadcrumbTrail $trail, Budget $budget, $recurringTransaction) {
    $trail->parent('recurring-transactions.edit', $budget, $recurringTransaction);
    $trail->push('Rules', route('recurring-transactions.rules.index', [$budget, $recurringTransaction]));
});

Breadcrumbs::for('recurring-transactions.rules.preview', function (BreadcrumbTrail $trail, Budget $budget, $recurringTransaction) {
    $trail->parent('recurring-transactions.rules.index', $budget, $recurringTransaction);
    $trail->push('Preview', route('recurring-transactions.rules.preview', [$budget, $recurringTransaction]));
});

Breadcrumbs::for('recurring-transactions.rules.linked', function (BreadcrumbTrail $trail, Budget $budget, $recurringTransaction) {
    $trail->parent('recurring-transactions.rules.index', $budget, $recurringTransaction);
    $trail->push('Linked Transactions', route('recurring-transactions.rules.linked', [$budget, $recurringTransaction]));
});

// ============================================================
// REPORTS & ANALYTICS
// ============================================================

Breadcrumbs::for('reports.index', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Reports', route('reports.index', $budget));
});

Breadcrumbs::for('budget.statistics.yearly', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Yearly Statistics', route('budget.statistics.yearly', $budget));
});

Breadcrumbs::for('budget.statistics.monthly', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Monthly Statistics', route('budget.statistics.monthly', $budget));
});

Breadcrumbs::for('budget.projections', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Budget Projections', route('budget.projections', $budget));
});

// ============================================================
// PAYOFF PLANS
// ============================================================

Breadcrumbs::for('payoff-plans.index', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Payoff Plans', route('payoff-plans.index', $budget));
});

Breadcrumbs::for('payoff-plans.create', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('payoff-plans.index', $budget);
    $trail->push('Create Plan', route('payoff-plans.create', $budget));
});

Breadcrumbs::for('payoff-plans.show', function (BreadcrumbTrail $trail, Budget $budget, $payoffPlan) {
    $trail->parent('payoff-plans.index', $budget);
    $trail->push($payoffPlan->name, route('payoff-plans.show', [$budget, $payoffPlan]));
});

Breadcrumbs::for('payoff-plans.edit', function (BreadcrumbTrail $trail, Budget $budget, $payoffPlan) {
    $trail->parent('payoff-plans.show', $budget, $payoffPlan);
    $trail->push('Edit', route('payoff-plans.edit', [$budget, $payoffPlan]));
});
