// Keeps the specs away from Plaid.
//
// The local .env points at a real Plaid environment, and several pages create a
// Plaid Link token server-side while rendering (budget setup, account create,
// Plaid discover and link pages). The specs must never reach Plaid, so every
// browser context gets a route guard that blocks those page requests before
// they leave the browser and records them; specs assert the list stays empty.

const PLAID_PAGE_PATTERNS = [
    /^\/budgets\/\d+\/setup(\/connect|\/manual)?$/, // BudgetController@setup, PlaidController@discover, AccountController@create
    /^\/budgets\/\d+\/accounts\/create$/, // AccountController@create
    /^\/budget\/\d+\/plaid\/discover$/, // PlaidController@discover
    /^\/budget\/\d+\/account\/\d+\/plaid\/link$/, // PlaidController@showLinkForm
    /^\/budget\/\d+\/account\/\d+\/plaid\/(upgrade-link-token|update-connection|sync|store)$/,
    /^\/budget\/\d+\/plaid\/(sync-all|import)$/,
];

export function isPlaidPage(url, appOrigin) {
    const parsed = new URL(url);
    return parsed.origin === appOrigin && PLAID_PAGE_PATTERNS.some((pattern) => pattern.test(parsed.pathname));
}

/**
 * Block Plaid-backed pages for every page in `context` and make budget
 * creation land on the new (empty) budget instead of its setup page.
 *
 * BudgetController@store redirects to budgets.setup, which would create a
 * Link token. The guard performs the POST itself without following the
 * redirect and answers the Inertia request with a 409 location visit to
 * budgets.show for the new budget.
 *
 * Returns { blocked: string[] } listing any blocked Plaid-backed requests
 * (specs that create their own data assert it stays empty).
 */
export async function guardPlaid(context, baseURL) {
    const appOrigin = new URL(baseURL).origin;
    const blocked = [];

    await context.route(
        (url) => isPlaidPage(url.href, appOrigin),
        (route) => {
            const request = route.request();
            blocked.push(`${request.method()} ${request.url()}`);
            // The dashboard POSTs sync-all by itself when the last sync is over 12
            // hours old. Send that Inertia request back to the dashboard instead of
            // failing it, so the page carries on without a network error.
            const sync = new URL(request.url()).pathname.match(/^\/budget\/(\d+)\/plaid\/sync-all$/);
            if (sync && request.method() === 'POST') {
                return route.fulfill({ status: 303, headers: { Location: `${appOrigin}/budgets/${sync[1]}` } });
            }
            return route.abort('blockedbyclient');
        },
    );

    await context.route(
        (url) => url.origin === appOrigin && url.pathname === '/budgets',
        async (route) => {
            if (route.request().method() !== 'POST') {
                return route.fallback();
            }
            const response = await route.fetch({ maxRedirects: 0 });
            const location = response.headers()['location'] || '';
            const created = location.match(/\/budgets\/(\d+)\/setup$/);
            if (!created) {
                return route.fulfill({ response });
            }
            return route.fulfill({
                status: 409,
                headers: { 'X-Inertia-Location': `${appOrigin}/budgets/${created[1]}` },
                body: '',
            });
        },
    );

    return { blocked };
}
