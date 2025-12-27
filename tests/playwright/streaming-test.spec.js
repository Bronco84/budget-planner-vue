import { test, expect } from '@playwright/test';

test('Chat streaming with EventSource', async ({ page }) => {
  test.setTimeout(60000); // 60 seconds max

  console.log('1. Navigating to application...');
  await page.goto('/');
  await page.waitForLoadState('networkidle');

  // Login
  const loginButton = await page.locator('button:has-text("Log in")').first();
  if (await loginButton.isVisible()) {
    console.log('2. Logging in...');
    await page.fill('input[type="email"]', 'demo@example.com');
    await page.fill('input[type="password"]', 'password');
    await loginButton.click();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(1000);
    console.log('✓ Logged in');
  }

  console.log('3. Opening chat...');
  const chatButton = await page.locator('button[title="Chat Assistant"]').first();
  await chatButton.click();
  await page.waitForSelector('.chat-panel', { state: 'visible', timeout: 10000 });
  console.log('✓ Chat panel opened');

  console.log('4. Sending message...');
  const chatInput = page.locator('textarea[placeholder*="Ask me anything"]');
  await chatInput.fill('Count to 5');
  
  const sendButton = page.locator('button[title="Send message"]');
  await sendButton.click();
  
  console.log('5. Waiting for streaming to start...');
  // Wait for the assistant message to appear
  const assistantMessage = page.locator('.markdown-content').last();
  await expect(assistantMessage).toBeVisible({ timeout: 10000 });
  
  console.log('6. Waiting for streaming indicator...');
  // Check if streaming indicator is visible
  const streamingIndicator = page.locator('.inline-block.animate-pulse');
  await expect(streamingIndicator).toBeVisible({ timeout: 5000 });
  console.log('✓ Streaming indicator visible');
  
  console.log('7. Waiting for streaming to complete...');
  // Wait for streaming to finish (indicator disappears)
  await expect(streamingIndicator).not.toBeVisible({ timeout: 30000 });
  console.log('✓ Streaming completed');
  
  console.log('8. Verifying message content...');
  const messageContent = await assistantMessage.textContent();
  console.log(`Final message: "${messageContent.substring(0, 100)}..."`);
  expect(messageContent.length).toBeGreaterThan(0);
  
  await page.screenshot({ path: 'tests/playwright/screenshots/streaming-final.png', fullPage: true });
  console.log('✅ Test completed successfully!');
});




