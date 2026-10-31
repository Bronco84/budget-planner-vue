// Console-error collection shared by the Playwright specs.

/**
 * Whether a console error is a failed load the app does not control:
 * - a third-party resource, e.g. a bank's favicon from gstatic returning 404,
 *   which depends on external services and the account's institutions;
 * - the browser's own /favicon.ico request (public/favicon.ico is an empty
 *   file, which Herd's nginx answers with a 404);
 * - the 409 location visit that plaid.js's guard answers budget creation with;
 * - Herd's dev tooling (/herd-studio/*), injected into every page by Herd's
 *   nginx on the local site, not part of the app.
 */
function isIgnoredResourceFailure(msg, appOrigin) {
    if (!msg.text().startsWith('Failed to load resource')) {
        return false;
    }
    const { url } = msg.location();
    if (!url) {
        return false;
    }
    const parsed = new URL(url);
    return (
        parsed.origin !== appOrigin ||
        parsed.pathname === '/favicon.ico' ||
        parsed.pathname.startsWith('/herd-studio/') ||
        (parsed.pathname === '/budgets' && msg.text().includes('status of 409'))
    );
}

/**
 * Collect console errors and uncaught page errors for the lifetime of the page.
 *
 * Returns an array of messages with an extra `allow(pattern)` method for errors
 * a test provokes on purpose (e.g. the browser's "Failed to load resource: 419"
 * line when a stale CSRF token is replayed). Allowed patterns are matched
 * against the message text plus its source URL.
 */
export function trackErrors(page, appOrigin) {
    const errors = [];
    const allowed = [];
    const isAllowed = (line) => allowed.some((pattern) => pattern.test(line));

    page.on('console', (msg) => {
        if (msg.type() !== 'error' || isIgnoredResourceFailure(msg, appOrigin)) {
            return;
        }
        const { url } = msg.location();
        const line = `console: ${msg.text()}${url ? ` (${url})` : ''}`;
        if (!isAllowed(line)) {
            errors.push(line);
        }
    });
    page.on('pageerror', (err) => {
        const line = `pageerror: ${err.message}`;
        if (!isAllowed(line)) {
            errors.push(line);
        }
    });

    Object.defineProperty(errors, 'allow', {
        value: (pattern) => allowed.push(pattern),
        enumerable: false,
    });

    return errors;
}
