// Read-only smoke test of the main authenticated pages against the local Herd site.
//
// Logs in through spatie/laravel-login-link (local environment only), so it needs
// the email of an existing local account:
//
//   PLAYWRIGHT_EMAIL=you@example.com npx playwright test tests/playwright/smoke.spec.js
//
// Note: laravel-login-link creates the user if the email does not exist, so use
// an account that already has a budget. Plaid requests (the dashboard's automatic
// sync) are blocked in the browser; see support/plaid.js.
import { test, expect } from '@playwright/test';
import { login } from './support/auth.js';
import { trackErrors } from './support/errors.js';
import { guardPlaid } from './support/plaid.js';

const EMAIL = process.env.PLAYWRIGHT_EMAIL;

test.skip(!EMAIL, 'Set PLAYWRIGHT_EMAIL to the email of an existing local account to run the smoke suite.');

/** Extract the initial Inertia page object from a full HTML response. */
async function inertiaPage(response) {
    const html = await response.text();
    const script = html.match(/<script data-page="app" type="application\/json">([\s\S]*?)<\/script>/);
    if (script) {
        return JSON.parse(script[1]);
    }
    const attr = html.match(/data-page="([^"]*)"/);
    expect(attr, 'Inertia data-page payload present').toBeTruthy();
    const decoded = attr[1]
        .replace(/&quot;/g, '"')
        .replace(/&#039;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&');
    return JSON.parse(decoded);
}

/** Visit a URL and assert the response is OK, the Inertia app mounted and no errors were emitted. */
async function visit(page, errors, url) {
    const response = await page.goto(url);
    expect(response, `navigation response for ${url}`).not.toBeNull();
    expect(response.ok(), `${url} returned ${response.status()}`).toBeTruthy();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#app > *').first()).toBeVisible();
    expect(errors, `console/page errors on ${url}`).toEqual([]);
    return response;
}

/** Open /budgets, follow the redirect to the active budget and return its id and accounts. */
async function openDashboard(page, errors) {
    const response = await visit(page, errors, '/budgets');
    const match = new URL(page.url()).pathname.match(/^\/budgets\/(\d+)$/);
    expect(match, `/budgets should redirect to the active budget, got ${page.url()}`).toBeTruthy();
    const inertia = await inertiaPage(response);
    expect(inertia.component).toBe('Budgets/Show');
    return { budgetId: match[1], accounts: inertia.props.accounts ?? [] };
}

test.describe('authenticated smoke', () => {
    let errors;

    test.beforeEach(async ({ page, context, baseURL }) => {
        errors = trackErrors(page, new URL(baseURL).origin);
        // The dashboard syncs Plaid on load when the last sync is over 12 hours old;
        // block that (and any Link-token page) so the smoke run never calls Plaid.
        await guardPlaid(context, baseURL);
        await login(page, EMAIL);
    });

    test('budgets index redirects to the active budget dashboard', async ({ page }) => {
        const { budgetId } = await openDashboard(page, errors);
        expect(budgetId).toBeTruthy();
    });

    test('monthly statistics page loads', async ({ page }) => {
        const { budgetId } = await openDashboard(page, errors);
        await visit(page, errors, `/budget/${budgetId}/statistics/monthly`);
    });

    test('account projections page loads for six months', async ({ page }) => {
        const { budgetId, accounts } = await openDashboard(page, errors);
        test.skip(accounts.length === 0, 'The active budget has no accounts to project.');
        await visit(page, errors, `/budget/${budgetId}/account/${accounts[0].id}/projections?months=6`);
    });

    test('payoff plans index page loads', async ({ page }) => {
        const { budgetId } = await openDashboard(page, errors);
        await visit(page, errors, `/budget/${budgetId}/payoff-plans`);
    });

    test('first payoff plan page loads', async ({ page }) => {
        const { budgetId } = await openDashboard(page, errors);
        const response = await visit(page, errors, `/budget/${budgetId}/payoff-plans`);
        const { props } = await inertiaPage(response);
        const plans = Array.isArray(props.plans) ? props.plans : (props.plans?.data ?? []);
        test.skip(plans.length === 0, 'The active budget has no payoff plans to open.');
        await visit(page, errors, `/budget/${budgetId}/payoff-plans/${plans[0].id}`);
    });
});
