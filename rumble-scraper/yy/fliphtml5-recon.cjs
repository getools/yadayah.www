// Diagnostic: try a few different paths to the FlipHTML5 upload page and
// dump what comes back. We're trying to find a URL/flow that doesn't get
// the anti-bot stripped-page treatment that /edit-book/upload (via dashboard
// click) returns.
const fs = require('fs');
const path = require('path');

let puppeteer;
try { puppeteer = require('puppeteer-real-browser'); }
catch (e) { puppeteer = require('rebrowser-puppeteer'); }

const COOKIES_PATH = '/host_jobs/fliphtml5/cookies.json';

function loadCookies() {
    try {
        const raw = fs.readFileSync(COOKIES_PATH, 'utf8');
        const data = JSON.parse(raw);
        return Array.isArray(data) ? data : (data.cookies || []);
    } catch (e) { return []; }
}

const URLS_TO_TRY = [
    'https://fliphtml5.com/edit-book/upload',
    'https://fliphtml5.com/edit-book/upload?lang=en',
    'https://fliphtml5.com/upload',
    'https://fliphtml5.com/dashboard/upload',
    'https://fliphtml5.com/dashboard/home',
    'https://fliphtml5.com/dashboard/projects',
    'https://fliphtml5.com/edit-book/batch-upload',
    'https://fliphtml5.com/edit-book/batch-upload?lang=en',
];

(async () => {
    const launchOpts = {
        headless: false,
        args: [
            '--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage',
            '--disable-extensions', '--disable-gpu', '--no-first-run',
            '--no-default-browser-check', '--window-size=1280,900',
        ],
    };
    let browser;
    if (typeof puppeteer.connect === 'function') {
        const r = await puppeteer.connect({
            ...launchOpts, turnstile: true, disableXvfb: false,
            customConfig: { chromePath: process.env.CHROME_PATH || undefined },
        });
        browser = r.browser;
    } else {
        browser = await puppeteer.launch(launchOpts);
    }
    try {
        const page = await browser.newPage();
        page.setDefaultTimeout(30000);
        page.setDefaultNavigationTimeout(45000);
        const cookies = loadCookies();
        if (cookies.length) await page.setCookie(...cookies);

        for (const url of URLS_TO_TRY) {
            await page.goto(url, { waitUntil: 'networkidle2', timeout: 20000 }).catch(() => {});
            await new Promise(r => setTimeout(r, 2500));
            const info = await page.evaluate(() => ({
                url: location.href,
                bodyChars: (document.body && document.body.innerText || '').length,
                fileInputs: document.querySelectorAll('input[type=file]').length,
                buttons: Array.from(document.querySelectorAll('a, button'))
                    .filter(el => el.offsetParent !== null)
                    .slice(0, 8)
                    .map(el => (el.textContent || '').trim().slice(0, 50))
                    .filter(t => t),
                iframes: Array.from(document.querySelectorAll('iframe'))
                    .map(f => f.src).filter(Boolean).slice(0, 3),
            })).catch(e => ({ error: e.message }));
            console.error(JSON.stringify({ probe: url, ...info }));
        }
    } finally {
        await browser.close().catch(() => {});
    }
})();
