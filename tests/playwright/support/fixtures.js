// `test` with a `throwawayUser(label)` fixture: creates a passkey-enrolled
// throwaway account in its own browser context (see auth.js) and, when the
// test ends (pass or fail), deletes the budgets registered with
// `user.ownBudget(budget)` through the UI and then deletes the account.
import { test as base, expect } from '@playwright/test';
import { createThrowawayUser, throwawayEmail } from './auth.js';
import { deleteBudget } from './budget.js';

export const test = base.extend({
    throwawayUser: async ({ browser, baseURL }, use) => {
        const users = [];
        const unfinished = [];

        await use(async (label) => {
            const email = throwawayEmail(label);
            unfinished.push(email);
            const user = await createThrowawayUser(browser, baseURL, email);
            unfinished.splice(unfinished.indexOf(email), 1);
            user.budgets = [];
            user.ownBudget = (budget) => {
                user.budgets.push(budget);
                return budget;
            };
            users.push(user);
            return user;
        });

        // A user whose passkey enrolment failed can't reach the profile page.
        const failures = unfinished.map((email) => `${email}: not fully created; delete it manually`);
        for (const user of users.reverse()) {
            for (const budget of user.budgets) {
                await deleteBudget(user.page, budget.id).catch((e) => failures.push(`budget ${budget.id}: ${e.message}`));
            }
            await user.deleteAccount().catch((e) => failures.push(`${user.email}: ${e.message}`));
            await user.close();
        }
        expect(failures, 'throwaway data cleanup').toEqual([]);
    },
});

export { expect };
