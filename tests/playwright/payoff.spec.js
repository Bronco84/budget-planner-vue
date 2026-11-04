// Payoff plan wizard behaviour that only a browser can check (PAYOFF-001)
// against the local Herd site. Each test uses a throwaway account with a
// throwaway `E2E <random>` budget holding one credit card, deleted through the
// UI afterwards.
//
//   npx playwright test tests/playwright/payoff.spec.js
import { test, expect } from './support/fixtures.js';
import { createBudget, createManualAccount } from './support/budget.js';

async function ownerAtWizardStrategyStep(throwawayUser) {
    const user = await throwawayUser('payoff');
    const { page } = user;
    const budget = user.ownBudget(await createBudget(page));
    await createManualAccount(page, budget.id, { name: 'E2E Card', type: 'credit', balance: 2500 });

    const posts = [];
    page.on('request', (request) => {
        if (request.method() === 'POST' && new URL(request.url()).pathname === `/budget/${budget.id}/payoff-plans`) {
            posts.push(request.postDataJSON());
        }
    });

    await page.goto(`/budget/${budget.id}/payoff-plans/create`);
    await page.locator('#name').fill('E2E Plan');
    await page.getByRole('button', { name: 'Next' }).click();

    await page.getByLabel(/E2E Card/).check();
    await page.getByPlaceholder('e.g., 18.99').fill('19.99');
    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page.locator('#extra_payment')).toBeVisible();

    return { user, page, errors: user.errors, budget, posts };
}

test('pressing Enter on the strategy step advances to Goals instead of creating the plan', async ({ throwawayUser }) => {
    const { page, errors, posts } = await ownerAtWizardStrategyStep(throwawayUser);

    await page.locator('#extra_payment').fill('150');
    await page.locator('#extra_payment').press('Enter');

    await expect(page.getByRole('heading', { name: 'Financial Goals (Optional)' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Create Plan' })).toHaveCount(0);
    expect(posts).toEqual([]);

    expect(errors).toEqual([]);
});
