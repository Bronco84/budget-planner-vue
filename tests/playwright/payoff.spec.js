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

test('removing a goal keeps the other goals\' target and contribution amounts', async ({ throwawayUser }) => {
    const { page, errors, posts } = await ownerAtWizardStrategyStep(throwawayUser);
    await page.getByRole('button', { name: 'Next' }).click();

    const goals = [
        { name: 'Goal A', target: '100', contribution: '10' },
        { name: 'Goal B', target: '200', contribution: '20' },
        { name: 'Goal C', target: '300', contribution: '30' },
    ];
    const cards = page.locator('div.border.rounded-lg.relative');
    for (const [index, goal] of goals.entries()) {
        await page.getByRole('button', { name: 'Add Goal' }).click();
        const card = cards.nth(index);
        await card.getByPlaceholder('e.g., Vacation Fund').fill(goal.name);
        await card.getByPlaceholder('0.00').nth(0).fill(goal.target);
        await card.getByPlaceholder('0.00').nth(1).fill(goal.contribution);
    }

    // Remove Goal A: B and C keep their own amounts.
    await cards.nth(0).getByRole('button').first().click();
    await expect(cards).toHaveCount(2);
    for (const [index, goal] of goals.slice(1).entries()) {
        const card = cards.nth(index);
        await expect(card.getByPlaceholder('e.g., Vacation Fund')).toHaveValue(goal.name);
        await expect(card.getByPlaceholder('0.00').nth(0)).toHaveValue(goal.target);
        await expect(card.getByPlaceholder('0.00').nth(1)).toHaveValue(goal.contribution);
    }

    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('button', { name: 'Create Plan' }).click();
    await page.waitForURL(/\/payoff-plans\/\d+$/);

    expect(posts).toHaveLength(1);
    expect(posts[0].goals.map((g) => [g.name, g.target_amount_cents, g.monthly_contribution_cents, g.priority])).toEqual([
        ['Goal B', 20000, 2000, 1],
        ['Goal C', 30000, 3000, 2],
    ]);

    expect(errors).toEqual([]);
});
