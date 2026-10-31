import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/playwright',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: 'html',
  use: {
    baseURL: 'https://budget-planner-vue.test',
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      // Use the locally installed Google Chrome rather than a Playwright-bundled
      // browser build, so the suite runs without `npx playwright install`.
      // Branded Chrome starts its updater ~20s after launch and won't exit until
      // that finishes, which stalls (or hangs) worker shutdown; don't schedule it.
      use: {
        ...devices['Desktop Chrome'],
        channel: 'chrome',
        launchOptions: { args: ['--disable-updater-scheduler'] },
      },
    },
  ],
  // Laravel Herd is already running, so we don't need to start a web server.
  // These specs run against the local Herd site and its database only; they
  // are not part of CI (see docs/user-journeys/README.md, "E2E").
});

