// Budget sharing end to end (SHARE-001, SHARE-002) against the local Herd site.
//
// Uses two throwaway accounts (owner and invitee) and a throwaway budget; all
// of it is deleted through the UI at the end. The invitation link is read from
// the email the app sends to Herd's local mail server (see support/mail.js).
//
//   npx playwright test tests/playwright/sharing.spec.js
import { test, expect } from './support/fixtures.js';
import { createBudget, createManualAccount, quickAddTransaction } from './support/budget.js';
import { waitForMailedLink } from './support/mail.js';

test('owner invites a viewer who accepts, is promoted to editor, adds a transaction and is removed', async ({ throwawayUser }) => {
    test.setTimeout(120_000);
    const owner = await throwawayUser('owner');
    const invitee = await throwawayUser('invitee');
    let budget = null;

    await test.step('owner creates a budget with an account and a transaction', async () => {
        budget = owner.ownBudget(await createBudget(owner.page));
        await createManualAccount(owner.page, budget.id, { name: 'E2E Checking' });
        await owner.page.goto(`/budgets/${budget.id}`);
        await quickAddTransaction(owner.page, { account: 'E2E Checking', description: 'E2E owner groceries', amount: -42.5 });
    });

    await test.step('owner invites the invitee as a viewer from the Sharing page', async () => {
        await owner.page.goto(`/budgets/${budget.id}`);
        await owner.page.getByTitle('Sharing').click();
        await expect(owner.page).toHaveURL(new RegExp(`/budgets/${budget.id}/sharing$`));
        await expect(owner.page.getByText('You are an owner (primary owner).')).toBeVisible();

        await owner.page.locator('#invite-email').fill(invitee.email);
        await owner.page.locator('#invite-role').selectOption('viewer');
        await owner.page.getByRole('button', { name: 'Send invite' }).click();

        // The "Invitation sent" flash isn't rendered on this page; the pending list is the feedback.
        await expect(owner.page.getByRole('heading', { name: 'Pending invitations (1)' })).toBeVisible();
        await expect(owner.page.locator('#invite-email')).toHaveValue('');
        await expect(owner.page.locator('li', { hasText: invitee.email })).toContainText('viewer');
    });

    await test.step('invitee opens the emailed link and accepts', async () => {
        const link = await waitForMailedLink(invitee.email, /https?:\/\/[^\s"'<>]+\/invitations\/[A-Za-z0-9]+/);
        await invitee.page.goto(link);
        await expect(invitee.page.getByText(budget.name).first()).toBeVisible();
        await invitee.page.getByRole('button', { name: 'Accept and open budget' }).click();

        await invitee.page.waitForURL(new RegExp(`/budgets/${budget.id}$`));
        await expect(invitee.page.getByText('Active Budget').locator('..')).toContainText(budget.name);
    });

    await test.step('the viewer sees the budget read-only', async () => {
        await expect(invitee.page.getByText('E2E owner groceries')).toBeVisible();
        await expect(invitee.page.getByTitle('Sharing (you are a viewer)')).toBeVisible();
        await expect(invitee.page.getByTitle('Edit Budget')).toHaveCount(0);
        await expect(invitee.page.getByRole('link', { name: 'Add your first property' })).toHaveCount(0);
        // The account menu offers the read-only views but no Edit link.
        await invitee.page.getByTitle('Actions').first().click();
        await expect(invitee.page.getByRole('link', { name: 'Balance Chart' })).toBeVisible();
        await expect(invitee.page.getByRole('link', { name: 'Edit', exact: true })).toHaveCount(0);

        await invitee.page.getByTitle('Sharing (you are a viewer)').click();
        await expect(invitee.page.getByText('You are a viewer.')).toBeVisible();
        await expect(invitee.page.getByRole('button', { name: 'Send invite' })).toHaveCount(0);
        await expect(invitee.page.locator(`[data-member="${invitee.email}"]`)).toContainText('viewer');
    });

    await test.step('owner sees the member and promotes them to editor', async () => {
        await owner.page.reload();
        await expect(owner.page.getByRole('heading', { name: 'Members (2)' })).toBeVisible();
        await expect(owner.page.getByRole('heading', { name: 'Pending invitations (0)' })).toBeVisible();

        const row = owner.page.locator(`[data-member="${invitee.email}"]`);
        const update = owner.page.waitForResponse(
            (r) => r.request().method() === 'PATCH' && /\/members\/\d+$/.test(new URL(r.url()).pathname),
        );
        await row.locator('select').selectOption('editor');
        expect((await update).status()).toBeLessThan(400);
        await expect(row.locator('select')).toHaveValue('editor');
    });

    await test.step('the editor can now change data and adds a transaction', async () => {
        await invitee.page.goto(`/budgets/${budget.id}`);
        await expect(invitee.page.getByTitle('Sharing (you are an editor)')).toBeVisible();
        await expect(invitee.page.getByTitle('Edit Budget')).toHaveCount(0);
        await expect(invitee.page.getByRole('link', { name: 'Add your first property' })).toBeVisible();
        await invitee.page.getByTitle('Actions').first().click();
        await expect(invitee.page.getByRole('link', { name: 'Edit', exact: true })).toBeVisible();

        await quickAddTransaction(invitee.page, { account: 'E2E Checking', description: 'E2E editor coffee', amount: -4.25 });
        await owner.page.goto(`/budgets/${budget.id}`);
        await expect(owner.page.getByText('E2E editor coffee')).toBeVisible();
    });

    await test.step('owner removes the member, who loses access', async () => {
        await owner.page.goto(`/budgets/${budget.id}/sharing`);
        owner.page.once('dialog', (dialog) => dialog.accept());
        await owner.page.locator(`[data-member="${invitee.email}"]`).getByRole('button', { name: 'Remove' }).click();
        await expect(owner.page.locator(`[data-member="${invitee.email}"]`)).toHaveCount(0);
        await expect(owner.page.getByRole('heading', { name: 'Members (1)' })).toBeVisible();

        invitee.errors.allow(new RegExp(`status of 403 .*\\/budgets\\/${budget.id}\\)$`));
        const response = await invitee.page.goto(`/budgets/${budget.id}`);
        expect(response.status()).toBe(403);

        // Their stale active budget falls back; the invitee has no budget of their own.
        await invitee.page.goto('/budgets');
        await expect(invitee.page).toHaveURL(/\/budgets\/create$/);
    });

    expect(owner.errors, 'owner console errors').toEqual([]);
    expect(invitee.errors, 'invitee console errors').toEqual([]);
    expect([...owner.plaid.blocked, ...invitee.plaid.blocked], 'no Plaid-backed page was requested').toEqual([]);
});
