const puppeteer = require('puppeteer-real-browser');
(async () => {
    const r = await puppeteer.connect({ headless: false, turnstile: false, disableXvfb: false, customConfig: { chromePath: process.env.CHROME_PATH }, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
    const page = await r.browser.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push('pageerror: ' + e.message));
    page.on('console', m => { if (m.type() === 'error') errors.push('console.error: ' + m.text()); });
    await page.goto('https://yadayah.com/admin-feeds', { waitUntil: 'networkidle2', timeout: 20000 }).catch(e => errors.push('goto: ' + e.message));
    const info = await page.evaluate(() => ({
        bodyChars: (document.body && document.body.innerText || '').length,
        appDisplay: document.getElementById('app') ? document.getElementById('app').style.display : '(no #app)',
        loginVisible: document.getElementById('login-screen') ? document.getElementById('login-screen').classList.contains('visible') : false,
        title: document.title,
    }));
    console.log(JSON.stringify({ info, errors }, null, 2));
    await r.browser.close();
})();
