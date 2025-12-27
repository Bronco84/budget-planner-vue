import { test, expect } from '@playwright/test';

test('Final chat test', async ({ browser }) => {
  // Create a new context with no cache
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
  });
  
  const page = await context.newPage();
  
  console.log('1. Navigating...');
  await page.goto('https://budget-planner-vue.test/');
  await page.waitForLoadState('networkidle');
  
  // Login
  console.log('2. Logging in...');
  await page.fill('input[type="email"]', 'demo@example.com');
  await page.fill('input[type="password"]', 'password');
  await page.locator('button:has-text("Log in")').click();
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1000);
  
  console.log('3. Opening chat...');
  await page.locator('button:has-text("Chat Assistant")').click();
  await page.waitForTimeout(1000);
  
  console.log('4. Sending message...');
  await page.locator('textarea[placeholder*="Ask me anything"]').fill('Hello');
  await page.locator('button[title="Send message"]').click();
  
  console.log('5. Waiting for response...');
  await page.waitForTimeout(8000);
  
  await page.screenshot({ path: 'tests/playwright/screenshots/final-test.png', fullPage: true });
  console.log('✅ Screenshot saved');
  
  await context.close();
});




