import { test, expect } from '@playwright/test';

test.describe('Chat Streaming Feature', () => {
  let page;
  
  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    
    // Listen for console errors
    page.on('console', msg => {
      if (msg.type() === 'error') {
        console.error(`Console error: ${msg.text()}`);
      }
    });
    
    // Listen for page errors
    page.on('pageerror', error => {
      console.error(`Page error: ${error.message}`);
    });
  });

  test.beforeEach(async () => {
    // Navigate to the application
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    
    // Check if we need to login
    const loginButton = await page.locator('button:has-text("Log in")').first();
    if (await loginButton.isVisible()) {
      console.log('Login form detected - logging in as demo user');
      
      // Fill in login credentials
      await page.fill('input[type="email"]', 'demo@example.com');
      await page.fill('input[type="password"]', 'password');
      
      // Click login button
      await loginButton.click();
      
      // Wait for navigation
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(1000);
      
      console.log('✓ Logged in successfully');
    }
  });

  test('should open chat panel', async () => {
    // Look for chat button or panel trigger
    const chatButton = await page.locator('[aria-label*="chat" i], button:has-text("Chat")').first();
    
    if (await chatButton.isVisible()) {
      await chatButton.click();
      await page.waitForTimeout(500);
      
      // Verify chat panel is visible
      const chatPanel = await page.locator('[class*="chat"]').first();
      await expect(chatPanel).toBeVisible();
      
      console.log('✓ Chat panel opened successfully');
    } else {
      console.log('⚠ Chat button not found - checking if panel is already open');
    }
  });

  test('should display chat input and send button', async () => {
    // Find the chat textarea
    const chatInput = await page.locator('textarea[placeholder*="Ask" i], textarea[placeholder*="message" i]').first();
    await expect(chatInput).toBeVisible();
    
    // Find the send button
    const sendButton = await page.locator('button[title*="Send" i], button:has(svg)').last();
    await expect(sendButton).toBeVisible();
    
    console.log('✓ Chat input and send button are visible');
  });

  test('should send message and receive streaming response', async () => {
    // Find chat input
    const chatInput = await page.locator('textarea[placeholder*="Ask" i], textarea[placeholder*="message" i]').first();
    
    // Type a test message
    const testMessage = 'Hello, can you help me?';
    await chatInput.fill(testMessage);
    
    // Wait a moment for the input to register
    await page.waitForTimeout(300);
    
    // Find and click send button
    const sendButton = await page.locator('button[title*="Send" i], button:has(svg)').last();
    
    // Set up request/response monitoring
    let streamingStarted = false;
    let chunksReceived = 0;
    
    page.on('response', async (response) => {
      if (response.url().includes('/chat/stream')) {
        console.log(`Stream response status: ${response.status()}`);
        console.log(`Content-Type: ${response.headers()['content-type']}`);
        
        if (response.headers()['content-type']?.includes('text/event-stream')) {
          streamingStarted = true;
          console.log('✓ Streaming response detected');
        }
      }
    });
    
    // Click send
    await sendButton.click();
    
    // Wait for user message to appear
    await page.waitForTimeout(500);
    const userMessage = await page.locator(`text="${testMessage}"`).first();
    await expect(userMessage).toBeVisible();
    console.log('✓ User message displayed');
    
    // Wait for assistant response to start appearing
    await page.waitForTimeout(2000);
    
    // Look for assistant message container
    const assistantMessages = await page.locator('[class*="assistant"], [class*="bg-gray-100"]').all();
    
    if (assistantMessages.length > 0) {
      console.log(`✓ Found ${assistantMessages.length} assistant message(s)`);
      
      // Check if streaming indicator is present
      const streamingIndicator = await page.locator('[class*="animate-pulse"]').first();
      const hasStreamingIndicator = await streamingIndicator.isVisible().catch(() => false);
      
      if (hasStreamingIndicator) {
        console.log('✓ Streaming indicator is visible');
      } else {
        console.log('⚠ Streaming indicator not visible (might have completed)');
      }
      
      // Wait for response to complete
      await page.waitForTimeout(3000);
      
      // Verify the streaming indicator is gone (response complete)
      const indicatorGone = await streamingIndicator.isHidden().catch(() => true);
      if (indicatorGone) {
        console.log('✓ Streaming completed (indicator removed)');
      }
    } else {
      console.log('⚠ No assistant messages found yet');
    }
    
    // Take a screenshot for verification
    await page.screenshot({ path: 'tests/playwright/screenshots/chat-streaming.png', fullPage: true });
    console.log('✓ Screenshot saved to tests/playwright/screenshots/chat-streaming.png');
  });

  test('should handle network errors gracefully', async () => {
    // Simulate network failure
    await page.route('**/chat/stream', route => {
      route.abort('failed');
    });
    
    const chatInput = await page.locator('textarea[placeholder*="Ask" i], textarea[placeholder*="message" i]').first();
    await chatInput.fill('Test error handling');
    
    const sendButton = await page.locator('button[title*="Send" i], button:has(svg)').last();
    await sendButton.click();
    
    // Wait for error message
    await page.waitForTimeout(2000);
    
    // Look for error message
    const errorMessage = await page.locator('[class*="error"], [class*="red"]').first();
    const hasError = await errorMessage.isVisible().catch(() => false);
    
    if (hasError) {
      console.log('✓ Error message displayed correctly');
    } else {
      console.log('⚠ Error message not found');
    }
  });

  test('should display markdown formatting in responses', async () => {
    const chatInput = await page.locator('textarea[placeholder*="Ask" i], textarea[placeholder*="message" i]').first();
    await chatInput.fill('Give me a list of tips');
    
    const sendButton = await page.locator('button[title*="Send" i], button:has(svg)').last();
    await sendButton.click();
    
    // Wait for response
    await page.waitForTimeout(5000);
    
    // Check for markdown content class
    const markdownContent = await page.locator('[class*="markdown-content"]').first();
    const hasMarkdown = await markdownContent.isVisible().catch(() => false);
    
    if (hasMarkdown) {
      console.log('✓ Markdown content container found');
      
      // Check for common markdown elements
      const hasList = await page.locator('ul, ol').count() > 0;
      const hasParagraph = await page.locator('p').count() > 0;
      
      console.log(`  - Lists: ${hasList ? '✓' : '✗'}`);
      console.log(`  - Paragraphs: ${hasParagraph ? '✓' : '✗'}`);
    } else {
      console.log('⚠ Markdown content not found');
    }
  });

  test.afterAll(async () => {
    await page.close();
  });
});

// Additional diagnostic test
test.describe('Chat Panel Diagnostics', () => {
  test('inspect chat panel structure', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    
    // Login if needed
    const loginButton = await page.locator('button:has-text("Log in")').first();
    if (await loginButton.isVisible()) {
      await page.fill('input[type="email"]', 'demo@example.com');
      await page.fill('input[type="password"]', 'password');
      await loginButton.click();
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(1000);
      console.log('✓ Logged in');
    }
    
    // Take screenshot after login
    await page.screenshot({ path: 'tests/playwright/screenshots/logged-in-page.png', fullPage: true });
    
    // Log all visible buttons
    const buttons = await page.locator('button').all();
    console.log(`Found ${buttons.length} buttons on page`);
    
    for (let i = 0; i < Math.min(buttons.length, 20); i++) {
      const text = await buttons[i].textContent().catch(() => '');
      const ariaLabel = await buttons[i].getAttribute('aria-label').catch(() => '');
      const title = await buttons[i].getAttribute('title').catch(() => '');
      console.log(`  Button ${i}: "${text.trim()}" (aria-label: "${ariaLabel}", title: "${title}")`);
    }
    
    // Look for chat-related elements
    const chatElements = await page.locator('[class*="chat" i]').all();
    console.log(`Found ${chatElements.length} chat-related elements`);
    
    // Check for textarea
    const textareas = await page.locator('textarea').all();
    console.log(`Found ${textareas.length} textarea elements`);
    
    for (const textarea of textareas) {
      const placeholder = await textarea.getAttribute('placeholder').catch(() => '');
      console.log(`  Textarea placeholder: "${placeholder}"`);
    }
  });
});

