const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: false, slowMo: 300 });
  const page = await browser.newPage();
  page.setDefaultTimeout(60000);

  // Login
  console.log('Logging in...');
  await page.goto('https://fliphtml5.com/login.php');
  await page.waitForTimeout(3000);
  await page.fill('input[name="login-email"], input[type="email"]', 'FlipHtml5@yadayah.com');
  await page.fill('input[name="login-password"], input[type="password"]', 'FlipHtml5#com09');
  await page.click('.cc-login-actions-btn, button[type="submit"]');
  await page.waitForURL('**/dashboard/**', { timeout: 30000 });
  console.log('Logged in!');
  await page.waitForTimeout(3000);

  const bookId = 12441200;
  const pdfPath = 'C:/Users/Joe/Work/dev/yada/docs/PDF/YY-s03v05-Observations-Understanding.pdf';

  await page.goto(`https://fliphtml5.com/edit-book/${bookId}/re-upload`);
  await page.waitForTimeout(5000);

  // Select file
  console.log('Selecting file...');
  const [fileChooser] = await Promise.all([
    page.waitForEvent('filechooser', { timeout: 15000 }),
    page.click('.fileAccessMainButton:has-text("Upload Files")'),
  ]);
  await fileChooser.setFiles(pdfPath);
  console.log('File selected');
  await page.waitForTimeout(3000);

  // Handle dialog
  const discardBtn = page.locator('text=Discard').first();
  if (await discardBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
    await discardBtn.click();
    await page.waitForTimeout(1000);
  }
  const confirmBtn = page.locator('text=Confirm').first();
  if (await confirmBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
    await confirmBtn.click();
    console.log('Confirmed');
  }

  // Wait for dialog to close and page to settle
  await page.waitForTimeout(5000);
  await page.screenshot({ path: 'C:/Users/Joe/Work/dev/yada/translations/_step1.png', fullPage: true });
  console.log('Screenshot after confirm: _step1.png');

  // Dump page state to understand what's visible
  const visibleElements = await page.$$eval('*', els =>
    els.filter(el => el.offsetParent !== null && el.children.length === 0 && el.textContent?.trim())
      .map(el => ({
        text: el.textContent?.trim().substring(0, 60),
        tag: el.tagName,
        class: (el.className?.toString() || '').substring(0, 60),
        x: Math.round(el.getBoundingClientRect().x),
        y: Math.round(el.getBoundingClientRect().y),
      }))
      .filter(el => el.x > 300 && el.y > 100 && el.y < 700) // main content area
  );
  console.log('\nVisible main content:', JSON.stringify(visibleElements.slice(0, 25), null, 2));

  // Look specifically for any button/clickable with Convert
  const convertElements = await page.$$eval('*', els =>
    els.filter(el => el.offsetParent !== null)
      .filter(el => {
        const t = (el.textContent?.trim() || '').toLowerCase();
        return t === 'convert' || t.includes('start convert');
      })
      .map(el => ({
        text: el.textContent?.trim(),
        tag: el.tagName,
        class: (el.className?.toString() || '').substring(0, 60),
        x: Math.round(el.getBoundingClientRect().x),
        y: Math.round(el.getBoundingClientRect().y),
        w: Math.round(el.getBoundingClientRect().width),
        h: Math.round(el.getBoundingClientRect().height),
        clickable: el.tagName === 'BUTTON' || el.tagName === 'A' || el.role === 'button' || el.style?.cursor === 'pointer',
      }))
  );
  console.log('\nConvert elements:', JSON.stringify(convertElements, null, 2));

  // Wait and take more screenshots
  for (let i = 0; i < 12; i++) {
    await page.waitForTimeout(10000);
    await page.screenshot({ path: `C:/Users/Joe/Work/dev/yada/translations/_progress_${i}.png` });

    const url = page.url();
    const texts = await page.$$eval('*', els =>
      els.filter(el => el.offsetParent !== null && el.children.length === 0)
        .map(el => el.textContent?.trim())
        .filter(t => t && (t.match(/\d+%/) || t.includes('Convert') || t.includes('complet') || t.includes('success')))
    ).catch(() => []);
    console.log(`[${(i+1)*10}s] URL: ${url.substring(0, 80)}, texts: ${texts.slice(0, 5).join(', ')}`);
  }

  console.log('\nDone. Browser stays open 30s...');
  await page.waitForTimeout(30000);
  await browser.close();
})();
