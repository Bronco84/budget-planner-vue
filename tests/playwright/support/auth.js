// Sign-in, passkey and throwaway-account helpers for the Playwright specs.
//
// Everything here talks to the local Herd site only. Sign-in uses
// spatie/laravel-login-link (allowed in the local environment only), which
// creates the user when the email does not exist yet; throwaway users are
// created that way and removed again through the profile's account deletion.
import { expect } from '@playwright/test';
import { trackErrors } from './errors.js';
import { guardPlaid } from './plaid.js';

/** Read the CSRF token from the current page's meta tag. */
export async function csrfToken(page) {
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    expect(token, 'CSRF token meta tag present').toBeTruthy();
    return token;
}

/** Sign in as `email` through the local login-link endpoint. */
export async function login(page, email) {
    await page.goto('/login');
    const response = await page.request.post('/laravel-login-link-login', {
        form: { _token: await csrfToken(page), email },
        maxRedirects: 0,
    });
    expect(response.status(), `login link POST for ${email} returned ${response.status()}`).toBe(302);
}

/**
 * Attach a CTAP2 platform authenticator (Chrome DevTools Protocol WebAuthn
 * domain) to the page, so navigator.credentials.create/get complete without a
 * real device. It lives as long as the page.
 */
export async function addVirtualAuthenticator(page) {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
        options: {
            protocol: 'ctap2',
            transport: 'internal',
            hasResidentKey: true,
            hasUserVerification: true,
            isUserVerified: true,
            automaticPresenceSimulation: true,
        },
    });
    return {
        authenticatorId,
        credentials: async () => (await cdp.send('WebAuthn.getCredentials', { authenticatorId })).credentials,
        /** Detach it, e.g. to stand in for a second device with a fresh authenticator. */
        remove: () => cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId }),
    };
}

/**
 * Complete the passkey registration ceremony on the PasskeyRegister page that
 * is currently open, and wait for its client-side redirect to `expectedPath`.
 */
export async function completePasskeyRegistration(page, expectedPath, name = 'E2E virtual authenticator') {
    await expect(page).toHaveURL(/\/passkey\/register/);
    await page.locator('#passkey-name').fill(name);
    await page.getByRole('button', { name: /Complete Registration|Register Passkey/ }).click();
    await expect(page.getByText('Passkey registered successfully! Redirecting...')).toBeVisible();
    await page.waitForURL((url) => expectedPath.test(url.pathname), { timeout: 15_000 });
}

/** A random suffix for throwaway names and emails. */
export function randomSuffix() {
    return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`;
}

/**
 * Create a throwaway user in a fresh browser context: sign in through the
 * login link (which creates the account), then register a passkey with a
 * virtual authenticator, because the app requires one before anything else.
 *
 * Returns { email, context, page, errors, authenticator, deleteAccount }.
 * `deleteAccount()` removes the account through the profile's Delete Account
 * dialog (type-email confirmation), which cascades its budgets.
 */
export function throwawayEmail(label) {
    return `e2e-${label}-${randomSuffix()}@example.test`;
}

export async function createThrowawayUser(browser, baseURL, email) {
    const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const plaid = await guardPlaid(context, baseURL);
    const errors = trackErrors(page, new URL(baseURL).origin);
    const authenticator = await addVirtualAuthenticator(page);

    await login(page, email);

    // The passkey requirement sends a user without one to the registration page.
    await page.goto('/budgets');
    await expect(page).toHaveURL(/\/passkey\/register$/);
    await completePasskeyRegistration(page, /^\/budgets(\/create)?$/);
    expect((await authenticator.credentials()).length, 'virtual authenticator holds the new passkey').toBe(1);

    let deleted = false;
    return {
        email,
        context,
        page,
        errors,
        authenticator,
        plaid,
        async deleteAccount() {
            if (deleted) {
                return;
            }
            await page.goto('/profile');
            await page.getByRole('button', { name: 'Delete Account' }).click();
            await page.locator('#delete_account_email').fill(email);
            await page.getByRole('dialog').getByRole('button', { name: 'Delete Account' }).click();
            await page.waitForURL(/\/login$/);
            deleted = true;
        },
        async close() {
            await context.close();
        },
    };
}
