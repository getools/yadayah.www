const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const SCRIPTS_DIR = 'C:/Users/Joe/Work/dev/yada/translations';
const RESULTS_FILE = path.join(SCRIPTS_DIR, '_kdp_scrape_results.json');
const BROWSER_DIR = path.join(SCRIPTS_DIR, '.kdp_browser');

(async () => {
  const context = await chromium.launchPersistentContext(BROWSER_DIR, {
    headless: false,
    slowMo: 200,
    viewport: { width: 1280, height: 900 },
  });
  const page = context.pages()[0] || await context.newPage();
  page.setDefaultTimeout(60000);

  try {
    // Navigate to bookshelf - will redirect to login if needed
    console.log('Navigating to KDP bookshelf...');
    await page.goto('https://kdp.amazon.com/en_US/bookshelf');
    await page.waitForTimeout(3000);

    // Check if we need to log in
    const url = page.url();
    if (url.includes('signin') || url.includes('ap/signin')) {
      console.log('Login required. Current URL: ' + url);

      // Step 1: Enter email if the field exists
      const emailField = page.locator('#ap_email');
      if (await emailField.isVisible({ timeout: 5000 }).catch(() => false)) {
        await emailField.fill('craig@winns.org');
        console.log('Entered email');
        await page.waitForTimeout(1000);
      }

      // Step 2: Click Continue if present (Amazon two-step login)
      // Try multiple selectors - Amazon uses different ones
      const continueSels = ['#continue', '#continue-announce', '.a-button-input[type="submit"]', 'input[id="continue"]', 'input.a-button-input'];
      let clicked = false;
      for (const sel of continueSels) {
        const btn = page.locator(sel).first();
        if (await btn.isVisible({ timeout: 2000 }).catch(() => false)) {
          await btn.click();
          console.log(`Clicked Continue via ${sel}`);
          clicked = true;
          break;
        }
      }
      if (!clicked) {
        // Try clicking any submit-type button on the page
        const submitBtn = page.locator('input[type="submit"], button[type="submit"]').first();
        if (await submitBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
          const btnText = await submitBtn.getAttribute('value') || await submitBtn.textContent();
          await submitBtn.click();
          console.log('Clicked submit button: ' + btnText);
          clicked = true;
        }
      }
      if (!clicked) {
        console.log('WARNING: Could not find Continue button');
        // Take screenshot for debugging
        await page.screenshot({ path: 'C:/Users/Joe/Work/dev/yada/translations/kdp_login_debug.png' });
        console.log('Screenshot saved to kdp_login_debug.png');
      }
      await page.waitForTimeout(3000);

      // Step 3: Enter password - wait up to 30s for it to appear
      const passField = page.locator('#ap_password');
      if (await passField.isVisible({ timeout: 30000 }).catch(() => false)) {
        await passField.fill('cwcrystal5542cw');
        console.log('Entered password');
        await page.waitForTimeout(500);
        await page.click('#signInSubmit');
        console.log('Submitted login');
      } else {
        console.log('Password field not found. Page may need manual interaction.');
        console.log('Current URL: ' + page.url());
        console.log('Waiting 2 minutes for manual login...');
      }

      // Wait for bookshelf - allow time for OTP/captcha/manual login
      // Check every few seconds if we've landed on the bookshelf
      console.log('Waiting for bookshelf (complete any OTP/captcha manually)...');
      const loginStart = Date.now();
      while (Date.now() - loginStart < 180000) { // 3 minutes
        await page.waitForTimeout(3000);
        const curUrl = page.url();
        if (curUrl.includes('bookshelf')) {
          console.log('Landed on bookshelf!');
          break;
        }
        if (Date.now() - loginStart > 10000) {
          // Take a screenshot every 30s for debugging
          if (Math.round((Date.now() - loginStart) / 1000) % 30 === 0) {
            await page.screenshot({ path: 'C:/Users/Joe/Work/dev/yada/translations/kdp_login_debug.png' });
            console.log('  Still waiting... URL: ' + curUrl.substring(0, 80));
          }
        }
      }
      if (!page.url().includes('bookshelf')) {
        console.log('TIMEOUT: Could not reach bookshelf. Last URL: ' + page.url());
        await page.screenshot({ path: 'C:/Users/Joe/Work/dev/yada/translations/kdp_login_debug.png' });
        throw new Error('Login failed - could not reach bookshelf');
      }
      console.log('Logged in successfully');
    }
    await page.waitForTimeout(5000);

    // Show all books (not just live)
    const viewFilter = page.locator('#podbookshelftable_view_input-option');
    if (await viewFilter.count() > 0) {
      await viewFilter.selectOption('ALL');
      await page.waitForTimeout(3000);
    }

    // Debug: screenshot and dump HTML structure to understand the bookshelf layout
    await page.screenshot({ path: 'C:/Users/Joe/Work/dev/yada/translations/kdp_bookshelf.png', fullPage: true });
    console.log('Screenshot saved to kdp_bookshelf.png');

    // Dump some HTML structure for debugging
    const bodyHtml = await page.evaluate(() => {
      // Find the main content area and get an overview of its structure
      const rows = document.querySelectorAll('tr[id]');
      const divRows = document.querySelectorAll('[class*="book"], [class*="title"], [data-book], [data-action]');
      const allIds = Array.from(document.querySelectorAll('[id]')).slice(0, 100).map(el => el.id + ' (' + el.tagName + ')');
      return JSON.stringify({
        trWithId: rows.length,
        bookDivs: divRows.length,
        sampleIds: allIds,
        tableCount: document.querySelectorAll('table').length,
        url: window.location.href,
      }, null, 2);
    });
    console.log('Page structure: ' + bodyHtml);

    // Try multiple selector strategies for book rows
    const strategies = [
      'tr[id^="title-action-"]',
      'tr[id^="pod-"]',
      '.zme-indie-bookshelf-row',
      '[data-action="title-action"]',
      'table tbody tr',
      '.a-row[id]',
    ];

    let rowSelector = null;
    for (const sel of strategies) {
      const cnt = await page.$$(sel);
      console.log(`  Selector "${sel}": ${cnt.length} elements`);
      if (cnt.length > 0 && !rowSelector) {
        rowSelector = sel;
      }
    }

    const books = [];

    if (!rowSelector) {
      console.log('WARNING: Could not find book rows. Trying to extract from page content...');

      // Last resort: extract all links that look like book edit URLs
      const links = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('a[href*="title-setup"]')).map(a => ({
          href: a.href,
          text: a.textContent.trim().substring(0, 200),
        }));
      });
      console.log(`Found ${links.length} title-setup links`);
      for (const link of links) {
        const match = link.href.match(/paperback\/([^\/]+)\//);
        if (match) {
          books.push({
            bookId: match[1],
            title: link.text,
            asin: '',
            status: '',
            type: 'paperback',
          });
          console.log(`  ${link.text} [${match[1]}]`);
        }
      }
    } else {
      console.log(`\nUsing selector: ${rowSelector}`);
      const rows = await page.$$(rowSelector);

      for (const row of rows) {
        try {
          const id = await row.getAttribute('id') || '';
          const text = (await row.textContent()).trim();
          // Try to find a title-setup link within the row
          const link = await row.$('a[href*="title-setup"]');
          let bookId = '';
          let title = '';

          if (link) {
            const href = await link.getAttribute('href') || '';
            const match = href.match(/paperback\/([^\/]+)\//);
            if (match) bookId = match[1];
            title = (await link.textContent()).trim();
          }

          if (!bookId && id) {
            bookId = id.replace(/^(title-action-|pod-)/, '');
          }

          if (!title) {
            // Get first bold or heading text in the row
            const bold = await row.$('.a-text-bold, strong, h2, h3');
            if (bold) title = (await bold.textContent()).trim();
          }

          if (bookId && title) {
            books.push({ bookId, title, asin: '', status: '', type: '' });
            console.log(`  ${title} [${bookId}]`);
          }
        } catch (e) {
          // skip
        }
      }
    }

    // Pagination: check for more pages
    let hasMore = true;
    while (hasMore) {
      const nextBtn = page.locator('.a-last:not(.a-disabled) a');
      if (await nextBtn.count() > 0) {
        await nextBtn.click();
        await page.waitForTimeout(3000);
        const moreRows = await page.$$('a[href*="title-setup/paperback"]');
        for (const link of moreRows) {
          const href = await link.getAttribute('href') || '';
          const match = href.match(/paperback\/([^\/]+)\//);
          const title = (await link.textContent()).trim();
          if (match && title && !books.find(b => b.bookId === match[1])) {
            books.push({ bookId: match[1], title, asin: '', status: '', type: 'paperback' });
            console.log(`  ${title} [${match[1]}]`);
          }
        }
      } else {
        hasMore = false;
      }
    }

    console.log(`\nTotal books found: ${books.length}`);
    fs.writeFileSync(RESULTS_FILE, JSON.stringify(books, null, 2));
    console.log(`Results saved to ${RESULTS_FILE}`);

  } catch (err) {
    console.error('Error:', err.message);
  } finally {
    await context.close();
  }
})();
