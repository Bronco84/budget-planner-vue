import { test, expect } from '@playwright/test';

test.describe('Budget Page Viewport Layout', () => {
  test.beforeEach(async ({ page }) => {
    // Login first
    await page.goto('https://budget-planner-vue.test/login');
    await page.fill('input[name="email"]', 'brad@bradmccoley.com');
    await page.fill('input[name="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard');
    
    // Navigate to budget page
    await page.goto('https://budget-planner-vue.test/budgets/1');
    await page.waitForLoadState('networkidle');
  });

  test('should fit content within viewport on desktop', async ({ page }) => {
    // Set viewport to common desktop size
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.waitForTimeout(500);

    // Take initial screenshot
    await page.screenshot({ path: 'tests/playwright/screenshots/viewport-desktop-full.png', fullPage: false });

    // Check that main container doesn't exceed viewport height
    const mainContainer = await page.locator('.py-4').first();
    const containerBox = await mainContainer.boundingBox();
    
    console.log('Main container height:', containerBox?.height);
    console.log('Viewport height:', 1080);
    
    // Container should not exceed viewport height
    expect(containerBox?.height).toBeLessThanOrEqual(1080);

    // Check sidebar is scrollable
    const sidebar = await page.locator('.lg\\:col-span-1').first();
    const sidebarBox = await sidebar.boundingBox();
    console.log('Sidebar height:', sidebarBox?.height);

    // Check transaction table container
    const tableContainer = await page.locator('.lg\\:col-span-3').first();
    const tableBox = await tableContainer.boundingBox();
    console.log('Table container height:', tableBox?.height);

    // Verify both columns have similar heights (should match viewport constraint)
    if (sidebarBox && tableBox) {
      const heightDiff = Math.abs(sidebarBox.height - tableBox.height);
      console.log('Height difference between columns:', heightDiff);
      expect(heightDiff).toBeLessThan(50); // Allow small difference
    }

    // Check if pagination is visible (should be fixed at bottom)
    const pagination = await page.locator('nav[aria-label="Pagination"]').first();
    const isPaginationVisible = await pagination.isVisible();
    console.log('Pagination visible:', isPaginationVisible);
    expect(isPaginationVisible).toBe(true);

    // Scroll the transaction table and verify pagination stays visible
    await page.evaluate(() => {
      const scrollContainer = document.querySelector('.md\\:overflow-y-auto');
      if (scrollContainer) {
        scrollContainer.scrollTop = 500;
      }
    });
    await page.waitForTimeout(300);

    // Pagination should still be visible after scrolling
    const isPaginationStillVisible = await pagination.isVisible();
    expect(isPaginationStillVisible).toBe(true);

    await page.screenshot({ path: 'tests/playwright/screenshots/viewport-scrolled.png', fullPage: false });
  });

  test('should have scrollable table body', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.waitForTimeout(500);

    // Find the scrollable container
    const scrollContainer = await page.locator('.md\\:overflow-y-auto').first();
    
    // Get initial scroll position
    const initialScrollTop = await scrollContainer.evaluate(el => el.scrollTop);
    console.log('Initial scroll position:', initialScrollTop);

    // Scroll down
    await scrollContainer.evaluate(el => {
      el.scrollTop = 500;
    });
    await page.waitForTimeout(300);

    const newScrollTop = await scrollContainer.evaluate(el => el.scrollTop);
    console.log('New scroll position:', newScrollTop);

    // Verify scrolling worked
    expect(newScrollTop).toBeGreaterThan(initialScrollTop);

    await page.screenshot({ path: 'tests/playwright/screenshots/table-scrolled.png', fullPage: false });
  });

  test('should have scrollable accounts sidebar', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.waitForTimeout(500);

    // Find the sidebar scrollable container
    const sidebar = await page.locator('.lg\\:col-span-1').first();
    
    // Check if sidebar has overflow
    const hasOverflow = await sidebar.evaluate(el => {
      return el.scrollHeight > el.clientHeight;
    });
    
    console.log('Sidebar has overflow:', hasOverflow);

    if (hasOverflow) {
      // Get initial scroll position
      const initialScrollTop = await sidebar.evaluate(el => el.scrollTop);
      
      // Scroll down
      await sidebar.evaluate(el => {
        el.scrollTop = 200;
      });
      await page.waitForTimeout(300);

      const newScrollTop = await sidebar.evaluate(el => el.scrollTop);
      
      // Verify scrolling worked
      expect(newScrollTop).toBeGreaterThan(initialScrollTop);
    }

    await page.screenshot({ path: 'tests/playwright/screenshots/sidebar-scrolled.png', fullPage: false });
  });

  test('should maintain layout on smaller desktop viewport', async ({ page }) => {
    // Test with smaller desktop size
    await page.setViewportSize({ width: 1366, height: 768 });
    await page.waitForTimeout(500);

    await page.screenshot({ path: 'tests/playwright/screenshots/viewport-1366x768.png', fullPage: false });

    const mainContainer = await page.locator('.py-4').first();
    const containerBox = await mainContainer.boundingBox();
    
    console.log('Container height at 1366x768:', containerBox?.height);
    console.log('Viewport height:', 768);
    
    // Should still fit within viewport
    expect(containerBox?.height).toBeLessThanOrEqual(768);
  });

  test('should show natural flow on mobile', async ({ page }) => {
    // Test mobile viewport
    await page.setViewportSize({ width: 375, height: 667 });
    await page.waitForTimeout(500);

    await page.screenshot({ path: 'tests/playwright/screenshots/viewport-mobile.png', fullPage: true });

    // On mobile, the fixed height constraints should not apply
    // The page should scroll naturally
    const mainContainer = await page.locator('.py-4').first();
    const containerBox = await mainContainer.boundingBox();
    
    console.log('Container height on mobile:', containerBox?.height);
    
    // Mobile should allow natural content flow (can exceed viewport)
    // This is expected behavior
  });

  test('should measure actual table visibility', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.waitForTimeout(500);

    // Get all transaction rows
    const rows = await page.locator('tbody tr').all();
    console.log('Total transaction rows:', rows.length);

    // Check how many rows are visible in viewport
    let visibleRows = 0;
    for (const row of rows) {
      const isVisible = await row.isVisible();
      if (isVisible) {
        const box = await row.boundingBox();
        if (box && box.y >= 0 && box.y + box.height <= 1080) {
          visibleRows++;
        }
      }
    }

    console.log('Visible rows in viewport:', visibleRows);
    
    // Should have at least some rows visible
    expect(visibleRows).toBeGreaterThan(0);

    // Check if table header is visible
    const tableHeader = await page.locator('thead').first();
    const isHeaderVisible = await tableHeader.isVisible();
    console.log('Table header visible:', isHeaderVisible);
    expect(isHeaderVisible).toBe(true);

    await page.screenshot({ path: 'tests/playwright/screenshots/table-visibility.png', fullPage: false });
  });
});

