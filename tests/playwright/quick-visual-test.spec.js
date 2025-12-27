import { test } from '@playwright/test';

test('Quick visual test of chat streaming', async ({ page }) => {
  test.setTimeout(45000); // 45 seconds max
  
  // Clear cache
  await page.context().clearCookies();
  
  console.log('1. Navigating...');
  await page.goto('/?v=' + Date.now());
  await page.waitForLoadState('networkidle');
  
  // Login
  const loginButton = await page.locator('button:has-text("Log in")').first();
  if (await loginButton.isVisible()) {
    console.log('2. Logging in...');
    await page.fill('input[type="email"]', 'demo@example.com');
    await page.fill('input[type="password"]', 'password');
    await loginButton.click();
    await page.waitForLoadState('networkidle');
  }
  
  console.log('3. Opening chat...');
  await page.locator('button:has-text("Chat Assistant")').click();
  await page.waitForTimeout(500);
  await page.screenshot({ path: 'tests/playwright/screenshots/chat-opened.png' });
  
  console.log('4. Typing message...');
  const input = await page.locator('textarea[placeholder*="Ask me anything"]');
  await input.fill('What is my total balance?');
  await page.screenshot({ path: 'tests/playwright/screenshots/message-typed.png' });
  
  console.log('5. Sending message...');
  await page.locator('button[title="Send message"]').click();
  
  // Wait just 5 seconds to see what happens
  console.log('6. Waiting 5 seconds for response...');
  await page.waitForTimeout(5000);
  await page.screenshot({ path: 'tests/playwright/screenshots/after-5-seconds.png', fullPage: true });
  
  console.log('7. Waiting another 5 seconds...');
  await page.waitForTimeout(5000);
  await page.screenshot({ path: 'tests/playwright/screenshots/after-10-seconds.png', fullPage: true });
  
  console.log('✅ Screenshots captured!');
  console.log('Check: tests/playwright/screenshots/');
});

