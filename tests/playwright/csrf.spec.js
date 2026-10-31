// CSRF token recovery in the browser (AUTH-009) against the local Herd site.
//
// A real stale token is produced by signing the session out and back in through
// the API context that shares the page's cookies: Laravel rotates the session's
// CSRF token, while the open page keeps the old one in its meta tag and axios
// defaults (Laravel prefers that X-CSRF-TOKEN header over the fresh XSRF-TOKEN
// cookie, so the next write 419s). Throwaway accounts only.
//
//   npx playwright test tests/playwright/csrf.spec.js
import { test, expect } from './support/fixtures.js';
import { createBudget, createManualAccount, quickAddTransaction } from './support/budget.js';

/** Sign out and back in behind the page's back, leaving it with a stale CSRF token. */
async function rotateSessionToken(page, email) {
    const staleToken = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const logout = await page.request.post('/logout', { form: { _token: staleToken }, maxRedirects: 0 });
    expect(logout.status()).toBe(302);
    const { csrf_token: guestToken } = await (await page.request.get('/csrf-token')).json();
    const login = await page.request.post('/laravel-login-link-login', { form: { _token: guestToken, email }, maxRedirects: 0 });
    expect(login.status()).toBe(302);
    const { csrf_token: freshToken } = await (await page.request.get('/csrf-token')).json();
    expect(freshToken).not.toBe(staleToken);
    return staleToken;
}

/** Record the status of every response whose path matches. */
function recordResponses(page, pathPattern) {
    const seen = [];
    page.on('response', (response) => {
        const { pathname } = new URL(response.url());
        if (pathPattern.test(pathname)) {
            seen.push(`${response.request().method()} ${pathname} ${response.status()}`);
        }
    });
    return seen;
}

async function ownerWithTwoAccountTypes(throwawayUser) {
    const user = await throwawayUser('csrf');
    const budget = user.ownBudget(await createBudget(user.page));
    await createManualAccount(user.page, budget.id, { name: 'E2E Checking', type: 'checking' });
    await createManualAccount(user.page, budget.id, { name: 'E2E Savings', type: 'savings' });
    await user.page.goto(`/budgets/${budget.id}`);
    return { user, page: user.page, errors: user.errors, budget };
}

test('a fetch write with a rotated CSRF token is replayed once with a fresh token', async ({ throwawayUser }) => {
    const { user, page, errors } = await ownerWithTwoAccountTypes(throwawayUser);
    errors.allow(/status of 419 .*\/api\/preferences\/account-type-order\)$/);
    const groupTitles = page.locator('.drag-handle span.uppercase');
    const handle = (title) => page.locator('.drag-handle', { hasText: title });
    await expect(groupTitles).toHaveText(['Checking Accounts', 'Savings Accounts']);

    await rotateSessionToken(page, user.email);
    const seen = recordResponses(page, /^\/(csrf-token|api\/preferences\/account-type-order)$/);

    // Dragging saves the order with apiFetch (fetch), which retries a 419, then
    // partially reloads the page props.
    const reloaded = page.waitForResponse((r) => !!r.request().headers()['x-inertia-partial-data']);
    await handle('Savings Accounts').dragTo(handle('Checking Accounts'));
    await reloaded;
    expect(seen).toEqual([
        'POST /api/preferences/account-type-order 419',
        'GET /csrf-token 200',
        'POST /api/preferences/account-type-order 200',
    ]);

    await expect(groupTitles).toHaveText(['Savings Accounts', 'Checking Accounts']);

    // The replayed save persisted.
    await page.reload();
    await expect(groupTitles).toHaveText(['Savings Accounts', 'Checking Accounts']);

    expect(errors).toEqual([]);
});

test('concurrent axios writes that hit a 419 share one token refresh and each replay once', async ({ throwawayUser }) => {
    const { user, page, errors, budget } = await ownerWithTwoAccountTypes(throwawayUser);
    errors.allow(/status of 419 .*\/api\/preferences\/(account-type-order|active-budget)\)$/);

    await rotateSessionToken(page, user.email);
    const seen = recordResponses(page, /^\/(csrf-token|api\/preferences\/.+)$/);

    const statuses = await page.evaluate(async (budgetId) => {
        const results = await Promise.all([
            window.axios.post('/api/preferences/account-type-order', { order: ['savings', 'checking'] }),
            window.axios.post('/api/preferences/active-budget', { budget_id: budgetId }),
        ]);
        return results.map((r) => r.status);
    }, budget.id);

    expect(statuses).toEqual([200, 200]);
    expect(seen.filter((line) => line.startsWith('GET /csrf-token'))).toEqual(['GET /csrf-token 200']);
    expect(seen.filter((line) => line.includes('account-type-order')).sort()).toEqual([
        'POST /api/preferences/account-type-order 200',
        'POST /api/preferences/account-type-order 419',
    ]);
    expect(seen.filter((line) => line.includes('active-budget')).sort()).toEqual([
        'POST /api/preferences/active-budget 200',
        'POST /api/preferences/active-budget 419',
    ]);

    expect(errors).toEqual([]);
});

test('an Inertia form submitted with a rotated CSRF token still succeeds', async ({ throwawayUser }) => {
    const { user, page, errors, budget } = await ownerWithTwoAccountTypes(throwawayUser);
    errors.allow(/status of 419 .*\/budget\/\d+\/transactions\)$/);

    await rotateSessionToken(page, user.email);
    const seen = recordResponses(page, /^\/(csrf-token|budget\/\d+\/transactions)$/);

    await quickAddTransaction(page, { account: 'E2E Checking', description: 'E2E after token rotation', amount: -12 });
    expect(seen).toEqual([
        `POST /budget/${budget.id}/transactions 419`,
        'GET /csrf-token 200',
        `POST /budget/${budget.id}/transactions 302`,
    ]);

    expect(errors).toEqual([]);
});

test('an Inertia visit answered with 419 reloads the page instead of leaving it stuck', async ({ throwawayUser }) => {
    const { page, errors, budget } = await ownerWithTwoAccountTypes(throwawayUser);
    errors.allow(/status of 419 .*\/recurring-transactions\)$/);

    // Answer Inertia's XHR for the Recurring link with Laravel's "Page Expired"
    // response; full-page (non-Inertia) requests go through.
    let expired = 0;
    const recurring = (url) => url.pathname.endsWith('/recurring-transactions');
    await page.route(recurring, (route) => {
        if (!route.request().headers()['x-inertia']) {
            return route.fallback();
        }
        expired += 1;
        return route.fulfill({ status: 419, contentType: 'text/html', body: '<h1>Page Expired</h1>' });
    });

    const reloaded = page.waitForEvent('load');
    await page.getByRole('link', { name: 'Recurring', exact: true }).click();
    await reloaded;
    expect(expired).toBeGreaterThan(0);
    // The reload re-renders the page the user was on, with a working app.
    await expect(page).toHaveURL(new RegExp(`/budgets/${budget.id}$`));
    await expect(page.getByText('Checking Accounts')).toBeVisible();
    await expect(page.getByText('Page Expired')).toHaveCount(0);

    await page.unroute(recurring);
    await page.getByRole('link', { name: 'Recurring', exact: true }).click();
    await expect(page.getByText('No recurring transactions have been set up yet.')).toBeVisible();

    expect(errors).toEqual([]);
});

test('a partial reload keeps the refreshed CSRF token instead of restoring the stale one', async ({ throwawayUser }) => {
    const { user, page, errors } = await ownerWithTwoAccountTypes(throwawayUser);
    errors.allow(/status of 419 .*\/api\/preferences\/account-type-order\)$/);
    const handle = (title) => page.locator('.drag-handle', { hasText: title });

    const staleToken = await rotateSessionToken(page, user.email);

    // The drag's save 419s, refreshes the token and replays; then the page
    // partially reloads (only: [...]). That reload must hand back the live token.
    const reloaded = page.waitForResponse((r) => !!r.request().headers()['x-inertia-partial-data']);
    await handle('Savings Accounts').dragTo(handle('Checking Accounts'));
    const partial = await (await reloaded).json();
    const { csrf_token: liveToken } = await (await page.request.get('/csrf-token')).json();
    expect(partial.props.csrf_token).toBe(liveToken);
    await expect(page.locator('meta[name="csrf-token"]')).toHaveAttribute('content', liveToken);
    expect(liveToken).not.toBe(staleToken);

    // So the next write goes straight through, with no 419 and no refresh.
    const seen = recordResponses(page, /^\/(csrf-token|api\/preferences\/account-type-order)$/);
    const status = await page.evaluate(async () =>
        (await window.axios.post('/api/preferences/account-type-order', { order: ['checking', 'savings'] })).status,
    );
    expect(status).toBe(200);
    expect(seen).toEqual(['POST /api/preferences/account-type-order 200']);

    expect(errors).toEqual([]);
});
