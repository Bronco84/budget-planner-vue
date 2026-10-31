// Throwaway budget helpers. Budgets are created and deleted through the UI;
// see plaid.js for why budget creation skips the setup page.
import { expect } from '@playwright/test';
import { csrfToken, randomSuffix } from './auth.js';

/** Create a budget named `E2E <random>` from the Create Budget page; returns { id, name }. */
export async function createBudget(page, name = `E2E ${randomSuffix()}`) {
    await page.goto('/budgets/create');
    await page.locator('#name').fill(name);
    await page.getByRole('button', { name: /Create Budget/i }).click();
    await page.waitForURL(/\/budgets\/\d+$/);
    await expect(page.getByText(name).first()).toBeVisible();
    const id = Number(new URL(page.url()).pathname.match(/\/budgets\/(\d+)$/)[1]);
    return { id, name };
}

/** Delete a budget from its Edit page (owner only). */
export async function deleteBudget(page, budgetId) {
    await page.goto(`/budgets/${budgetId}/edit`);
    await page.getByRole('button', { name: 'Delete Budget' }).first().click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete Budget' }).click();
    await page.waitForURL((url) => !url.pathname.startsWith(`/budgets/${budgetId}`));
}

/**
 * Add a manual account to a budget by sending the same POST the account form
 * sends. The account form pages themselves request a Plaid Link token while
 * rendering, so the specs don't open them (see plaid.js).
 */
export async function createManualAccount(page, budgetId, { name, type = 'checking', balance = 1000 }) {
    const response = await page.request.post(`/budgets/${budgetId}/accounts`, {
        form: { _token: await csrfToken(page), name, type, current_balance: String(balance), include_in_budget: '1' },
        maxRedirects: 0,
    });
    expect(response.status(), `account store for ${name}`).toBe(302);
    expect(response.headers()['location']).toMatch(new RegExp(`/budgets/${budgetId}$`));
}

/** A YYYY-MM-DD date `days` from today. */
export function isoDateInDays(days) {
    return new Date(Date.now() + days * 86_400_000).toISOString().slice(0, 10);
}

/**
 * Add a transaction through the sidebar's Add > New Transaction quick-add
 * modal, which posts to the active budget. Waits for the store redirect to the
 * new transaction's edit page and returns its id.
 *
 * Defaults to a date a few days ahead: the dashboard lists past transactions
 * only when they came from the bank feed, but shows upcoming manual ones.
 */
export async function quickAddTransaction(page, { account, description, amount, category = null, date = isoDateInDays(5) }) {
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await page.getByRole('button', { name: 'New Transaction' }).click();
    const modal = page.getByRole('dialog').filter({ hasText: 'Quick Add Transaction' });
    await expect(modal).toBeVisible();
    await modal.locator('#account_id').selectOption({ label: account });
    await modal.locator('#description').fill(description);
    if (category) {
        await modal.locator('#quick_add_category_id').selectOption({ label: category });
    }
    await modal.locator('#amount').fill(String(amount));
    await modal.locator('#date').fill(date);
    await modal.getByRole('button', { name: 'Add Transaction' }).click();
    await page.waitForURL(/\/budget\/\d+\/transactions\/\d+\/edit$/);
    // The edit page's fields (the closed quick-add dialog reuses some ids).
    const field = (id) => page.locator(id).filter({ visible: true });
    await expect(field('#description')).toHaveValue(description);
    await expect(field('#account_id').locator('option:checked')).toHaveText(account);
    if (category) {
        await expect(field('#category_id').locator('option:checked')).toHaveText(category);
    }
    return Number(new URL(page.url()).pathname.match(/\/transactions\/(\d+)\/edit$/)[1]);
}
