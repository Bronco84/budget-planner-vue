import { test } from '@playwright/test';

test('verify scenario summary shows net impact', async ({ page }) => {
  // Login
  await page.goto('http://budget-planner-vue.test/login');
  await page.fill('input[name="email"]', 'brad@bradmccoley.com');
  await page.fill('input[name="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForTimeout(2000);

  // Navigate to scenarios page
  await page.click('a[href*="/budgets/1"]');
  await page.waitForTimeout(1000);
  await page.click('a[href*="/scenarios"]');
  await page.waitForTimeout(2000);

  // Take screenshot of summary panel
  await page.screenshot({ 
    path: 'tests/playwright/screenshots/scenario-summary.png',
    fullPage: true
  });
  
  console.log('Screenshot saved to tests/playwright/screenshots/scenario-summary.png');
});
