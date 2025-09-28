<?php

use Diglactic\Breadcrumbs\Breadcrumbs;
use Diglactic\Breadcrumbs\Generator as BreadcrumbTrail;
use App\Models\Budget;
use App\Models\Account;

// Dashboard
Breadcrumbs::for('dashboard', function (BreadcrumbTrail $trail) {
    $trail->push('Dashboard', route('dashboard'));
});

// Budgets
Breadcrumbs::for('budgets.index', function (BreadcrumbTrail $trail) {
    $trail->parent('dashboard');
    $trail->push('Budgets', route('budgets.index'));
});

Breadcrumbs::for('budgets.create', function (BreadcrumbTrail $trail) {
    $trail->parent('budgets.index');
    $trail->push('Create Budget', route('budgets.create'));
});

Breadcrumbs::for('budgets.show', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.index');
    $trail->push($budget->name, route('budgets.show', $budget));
});

Breadcrumbs::for('budgets.edit', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Edit Budget', route('budgets.edit', $budget));
});

// Accounts
Breadcrumbs::for('budgets.accounts.create', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Create Account', route('budgets.accounts.create', $budget));
});

Breadcrumbs::for('budgets.accounts.edit', function (BreadcrumbTrail $trail, Budget $budget, Account $account) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Edit Account: ' . $account->name, route('budgets.accounts.edit', [$budget, $account]));
});

// Plaid
Breadcrumbs::for('plaid.link', function (BreadcrumbTrail $trail, Budget $budget, Account $account) {
    $trail->parent('budgets.accounts.edit', $budget, $account);
    $trail->push('Bank Connection', route('plaid.link', [$budget, $account]));
});

// Categories
Breadcrumbs::for('budgets.categories.create', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Create Category', route('budgets.categories.create', $budget));
});

Breadcrumbs::for('budgets.categories.edit', function (BreadcrumbTrail $trail, Budget $budget, $category) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Edit Category: ' . $category->name, route('budgets.categories.edit', [$budget, $category]));
});

// Transactions
Breadcrumbs::for('budgets.transactions.create', function (BreadcrumbTrail $trail, Budget $budget) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Create Transaction', route('budgets.transactions.create', $budget));
});

Breadcrumbs::for('budgets.transactions.edit', function (BreadcrumbTrail $trail, Budget $budget, $transaction) {
    $trail->parent('budgets.show', $budget);
    $trail->push('Edit Transaction', route('budgets.transactions.edit', [$budget, $transaction]));
});

// Profile
Breadcrumbs::for('profile.edit', function (BreadcrumbTrail $trail) {
    $trail->parent('dashboard');
    $trail->push('Profile', route('profile.edit'));
});