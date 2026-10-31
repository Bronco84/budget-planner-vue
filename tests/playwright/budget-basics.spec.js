// Budget dashboard behaviour that only a browser can check (TXN-002, RECUR-001,
// PREF-001) against the local Herd site. Each test uses a throwaway account
// with a throwaway `E2E <random>` budget, deleted through the UI afterwards.
//
//   npx playwright test tests/playwright/budget-basics.spec.js
import { test, expect } from './support/fixtures.js';
import { createBudget, createManualAccount, quickAddTransaction } from './support/budget.js';

async function ownerWithBudget(throwawayUser, accounts = [{ name: 'E2E Checking', type: 'checking' }]) {
    const user = await throwawayUser('owner');
    const budget = user.ownBudget(await createBudget(user.page));
    for (const account of accounts) {
        await createManualAccount(user.page, budget.id, account);
    }
    return { user, page: user.page, errors: user.errors, budget };
}

test('a budget without recurring templates shows the empty recurring list', async ({ throwawayUser }) => {
    const { user, page, errors, budget } = await ownerWithBudget(throwawayUser, []);

    await page.getByRole('link', { name: 'Recurring', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/budget/${budget.id}/recurring-transactions$`));
    await expect(page.getByText('No recurring transactions have been set up yet.')).toBeVisible();

    expect(errors).toEqual([]);
    expect(user.plaid.blocked).toEqual([]);
});

test('quick add stores a transaction with a picked category in the active budget', async ({ throwawayUser }) => {
    const { user, page, errors, budget } = await ownerWithBudget(throwawayUser);

    await page.goto(`/budgets/${budget.id}/categories`);
    await page.getByRole('button', { name: 'Create Category' }).first().click();
    const modal = page.getByRole('dialog').filter({ hasText: 'Create Budget Category' });
    await modal.locator('#name').fill('E2E Groceries');
    await modal.locator('#amount').fill('300');
    await modal.getByRole('button', { name: 'Create Category' }).click();
    await expect(modal).toBeHidden();
    await expect(page.getByText('E2E Groceries').first()).toBeVisible();

    await page.goto(`/budgets/${budget.id}`);
    const transactionId = await quickAddTransaction(page, {
        account: 'E2E Checking',
        description: 'E2E weekly shop',
        amount: -87.65,
        category: 'E2E Groceries',
    });
    expect(transactionId).toBeGreaterThan(0);
    await expect(page).toHaveURL(new RegExp(`/budget/${budget.id}/transactions/${transactionId}/edit$`));

    // The dashboard lists it with its category.
    await page.goto(`/budgets/${budget.id}`);
    const row = page.getByRole('row', { name: /E2E weekly shop/ });
    await expect(row).toContainText('E2E Groceries');
    await expect(row).toContainText('-$87.65');

    expect(errors).toEqual([]);
    expect(user.plaid.blocked).toEqual([]);
});

test('a failed account type order save snaps the dragged sections back', async ({ throwawayUser }) => {
    const { user, page, errors, budget } = await ownerWithBudget(throwawayUser, [
        { name: 'E2E Checking', type: 'checking' },
        { name: 'E2E Savings', type: 'savings', balance: 2500 },
    ]);
    const groupTitles = page.locator('.drag-handle span.uppercase');
    const handle = (title) => page.locator('.drag-handle', { hasText: title });
    const orderSaved = () => page.waitForResponse((r) => new URL(r.url()).pathname === '/api/preferences/account-type-order');

    await page.goto(`/budgets/${budget.id}`);
    await expect(groupTitles).toHaveText(['Checking Accounts', 'Savings Accounts']);

    // A successful drag is saved and survives a reload.
    let saved = orderSaved();
    await handle('Savings Accounts').dragTo(handle('Checking Accounts'));
    expect((await saved).status()).toBe(200);
    await expect(groupTitles).toHaveText(['Savings Accounts', 'Checking Accounts']);
    await page.reload();
    await expect(groupTitles).toHaveText(['Savings Accounts', 'Checking Accounts']);

    // When the save fails the sections return to the saved order.
    errors.allow(/status of 500 .*\/api\/preferences\/account-type-order\)$/);
    await page.route('**/api/preferences/account-type-order', (route) =>
        route.fulfill({ status: 500, json: { message: 'Server Error' } }),
    );
    saved = orderSaved();
    await handle('Checking Accounts').dragTo(handle('Savings Accounts'));
    expect((await saved).status()).toBe(500);
    await expect(groupTitles).toHaveText(['Savings Accounts', 'Checking Accounts']);

    expect(errors).toEqual([]);
    expect(user.plaid.blocked).toEqual([]);
});
