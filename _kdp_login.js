const { chromium } = require('playwright');
const BROWSER_DIR = 'C:/Users/Joe/Work/dev/yada/translations/.kdp_browser';

(async () => {
  const context = await chromium.launchPersistentContext(BROWSER_DIR, {
    headless: false,
    viewport: { width: 1280, height: 900 },
  });
  const page = context.pages()[0] || await context.newPage();

  console.log('Opening KDP login page...');
  console.log('Please log in manually. The browser will stay open for 3 minutes.');
  console.log('Once you see the bookshelf, press Ctrl+C or just wait.\n');

  await page.goto('https://kdp.amazon.com/en_US/bookshelf');

  // Poll until we reach the bookshelf or timeout
  const start = Date.now();
  while (Date.now() - start < 180000) {
    await page.waitForTimeout(3000);
    if (page.url().includes('bookshelf')) {
      console.log('\nBookshelf reached! Session saved.');
      break;
    }
    console.log('  Waiting for login... (' + page.url().substring(0, 60) + ')');
  }

  await page.waitForTimeout(2000);
  await context.close();
  console.log('Browser closed. Session cookies saved to .kdp_browser/');
})();
