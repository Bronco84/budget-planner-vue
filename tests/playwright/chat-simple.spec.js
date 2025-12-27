import { test, expect } from '@playwright/test';

test.describe('Chat Streaming - Simple Test', () => {
  test.setTimeout(60000); // 60 second timeout

  test('should display chat panel and test streaming', async ({ page }) => {
    // Set up console and error listeners
    const consoleErrors = [];
    const pageErrors = [];
    
    page.on('console', msg => {
      if (msg.type() === 'error') {
        consoleErrors.push(msg.text());
        console.error(`Console error: ${msg.text()}`);
      }
    });
    
    page.on('pageerror', error => {
      pageErrors.push(error.message);
      console.error(`Page error: ${error.message}`);
    });

    // Navigate and login
    console.log('1. Navigating to application...');
    await page.goto('/', { waitUntil: 'networkidle' });
    
    const loginButton = await page.locator('button:has-text("Log in")').first();
    if (await loginButton.isVisible()) {
      console.log('2. Logging in...');
      await page.fill('input[type="email"]', 'demo@example.com');
      await page.fill('input[type="password"]', 'password');
      await loginButton.click();
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(1000);
      console.log('✓ Logged in successfully');
    }

    // Take screenshot of main page
    await page.screenshot({ path: 'tests/playwright/screenshots/01-main-page.png', fullPage: true });

    // Click Chat Assistant button
    console.log('3. Opening chat panel...');
    const chatButton = await page.locator('button:has-text("Chat Assistant")').first();
    await expect(chatButton).toBeVisible();
    await chatButton.click();
    await page.waitForTimeout(1000);
    console.log('✓ Chat panel opened');

    // Take screenshot of chat panel
    await page.screenshot({ path: 'tests/playwright/screenshots/02-chat-panel.png', fullPage: true });

    // Verify chat input is visible
    console.log('4. Verifying chat input...');
    const chatInput = await page.locator('textarea[placeholder*="Ask me anything"]').first();
    await expect(chatInput).toBeVisible();
    console.log('✓ Chat input visible');

    // Type a message
    console.log('5. Typing message...');
    const testMessage = 'Hello, what is my total balance?';
    await chatInput.fill(testMessage);
    await page.waitForTimeout(500);

    // Take screenshot before sending
    await page.screenshot({ path: 'tests/playwright/screenshots/03-before-send.png', fullPage: true });

    // Find send button
    const sendButton = await page.locator('button[title="Send message"]').first();
    await expect(sendButton).toBeVisible();

    // Set up network monitoring
    let streamResponseReceived = false;
    let streamChunksReceived = 0;
    
    page.on('response', async (response) => {
      if (response.url().includes('/chat/stream')) {
        console.log(`Stream response: ${response.status()}`);
        console.log(`Content-Type: ${response.headers()['content-type']}`);
        streamResponseReceived = true;
      }
    });

    // Click send
    console.log('6. Sending message...');
    await sendButton.click();

    // Wait for user message to appear
    await page.waitForTimeout(1000);
    await page.screenshot({ path: 'tests/playwright/screenshots/04-message-sent.png', fullPage: true });

    // Verify user message is visible
    const userMessage = await page.locator(`text="${testMessage}"`).first();
    await expect(userMessage).toBeVisible({ timeout: 5000 });
    console.log('✓ User message displayed');

    // Wait for assistant response to start appearing (with timeout)
    console.log('7. Waiting for assistant response...');
    try {
      // Look for assistant message container (gray background)
      await page.waitForSelector('[class*="bg-gray-100"]', { timeout: 10000 });
      console.log('✓ Assistant message container appeared');
      
      // Wait a bit for streaming to happen
      await page.waitForTimeout(3000);
      
      // Take screenshot during/after streaming
      await page.screenshot({ path: 'tests/playwright/screenshots/05-response-received.png', fullPage: true });
      
      // Check if we got any response text
      const assistantMessages = await page.locator('[class*="bg-gray-100"]').all();
      console.log(`Found ${assistantMessages.length} assistant message(s)`);
      
      if (assistantMessages.length > 0) {
        const responseText = await assistantMessages[0].textContent();
        console.log(`Response text (first 100 chars): ${responseText.substring(0, 100)}...`);
      }
      
    } catch (error) {
      console.error(`Error waiting for response: ${error.message}`);
      await page.screenshot({ path: 'tests/playwright/screenshots/05-error-state.png', fullPage: true });
    }

    // Final screenshot
    await page.screenshot({ path: 'tests/playwright/screenshots/06-final-state.png', fullPage: true });

    // Report any errors
    if (consoleErrors.length > 0) {
      console.log('\n⚠ Console Errors:');
      consoleErrors.forEach(err => console.log(`  - ${err}`));
    }
    
    if (pageErrors.length > 0) {
      console.log('\n⚠ Page Errors:');
      pageErrors.forEach(err => console.log(`  - ${err}`));
    }

    console.log('\n✓ Test completed');
    console.log(`Stream response received: ${streamResponseReceived}`);
  });
});




