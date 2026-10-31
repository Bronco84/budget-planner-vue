// Passkey ceremonies in a real browser (AUTH-001, AUTH-007, AUTH-011) against
// the local Herd site, using Chrome's virtual WebAuthn authenticator (CDP
// WebAuthn domain) instead of a real device. Throwaway accounts only.
//
//   npx playwright test tests/playwright/passkeys.spec.js
import { test, expect } from './support/fixtures.js';
import { addVirtualAuthenticator, completePasskeyRegistration } from './support/auth.js';

/** The profile's Passkeys section. */
const passkeysSection = (page) =>
    page.locator('section', { has: page.getByRole('heading', { name: 'Passkeys', exact: true }) });
/** Listed passkeys, by alias (the name typed at registration, else a device-derived one). */
const listedPasskeys = (page) => passkeysSection(page).locator('h4');

test('a new account must enrol a passkey and then continues to its budgets', async ({ throwawayUser }) => {
    // The fixture signs in a brand-new account: the passkey requirement sends it to
    // /passkey/register, and after the ceremony the page redirects to /budgets
    // (here /budgets/create, as the account has no budget yet).
    const user = await throwawayUser('enrol');
    await expect(user.page).toHaveURL(/\/budgets\/create$/);

    await user.page.goto('/profile');
    // Named with what was typed at registration.
    await expect(listedPasskeys(user.page)).toHaveText(['E2E virtual authenticator']);
    expect(user.errors).toEqual([]);
});

test('a passkey added from the profile returns to the profile', async ({ throwawayUser }) => {
    const user = await throwawayUser('addkey');
    const { page, errors } = user;

    // A second device: a fresh authenticator (the first one holds an excluded credential).
    await user.authenticator.remove();
    const secondDevice = await addVirtualAuthenticator(page);

    await page.goto('/profile');
    await expect(listedPasskeys(page)).toHaveCount(1);
    await passkeysSection(page).getByRole('link', { name: 'Add New Passkey' }).click();
    await expect(page).toHaveURL(/\/passkey\/register\?return=profile$/);
    await expect(page.getByRole('heading', { name: 'Register a New Passkey' })).toBeVisible();

    await completePasskeyRegistration(page, /^\/profile$/, 'E2E second device');
    await expect(listedPasskeys(page)).toHaveCount(2);
    // The typed name is saved as the new passkey's alias.
    await expect(listedPasskeys(page).filter({ hasText: /^E2E second device$/ })).toHaveCount(1);
    expect((await secondDevice.credentials()).length).toBe(1);
    expect(errors).toEqual([]);
});

test('a user signs out and signs back in with their passkey', async ({ throwawayUser }) => {
    const user = await throwawayUser('signin');
    const { page, errors } = user;

    await page.goto('/profile');
    const name = await page.locator('#name').inputValue();
    await page.getByRole('button', { name, exact: true }).click();
    await page.getByRole('button', { name: 'Log Out' }).click();
    await page.waitForURL(/\/login$/);

    const assertion = page.waitForResponse((r) => new URL(r.url()).pathname === '/webauthn/login');
    await page.getByRole('button', { name: 'Sign in with Passkey' }).click();
    expect((await assertion).ok()).toBe(true);
    await page.waitForURL(/\/budgets\/create$/);
    await expect(page.getByRole('button', { name, exact: true })).toBeVisible();

    expect(errors).toEqual([]);
});
