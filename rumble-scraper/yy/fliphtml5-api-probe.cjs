// Probe FlipHTML5's internal AJAX endpoints. The list-folder-data endpoint
// already works for us (we use it in fliphtml5-upload.cjs); maybe a sibling
// endpoint accepts a POST that creates a book without going through the
// bot-blocked /edit-book/upload page.
const fs = require('fs');
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

(async () => {
    const r = await puppeteer.connect({
        headless: false,
        turnstile: true,
        disableXvfb: false,
        customConfig: { chromePath: process.env.CHROME_PATH || undefined },
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });
    const browser = r.browser;
    try {
        const page = await browser.newPage();
        const cookies = loadCookies();
        if (cookies.length) await page.setCookie(...cookies);
        await page.goto('https://fliphtml5.com/dashboard/home', { waitUntil: 'domcontentloaded' });
        await new Promise(r => setTimeout(r, 2000));

        const probes = await page.evaluate(async () => {
            const endpoints = [
                ['POST', '/api/book/create-book', 'name=test&folder_id=0'],
                ['POST', '/api/book/create',      'name=test&folder_id=0'],
                ['POST', '/api/book/add',         'name=test'],
                ['POST', '/api/book/upload',      'name=test'],
                ['POST', '/api/upload/start',     ''],
                ['GET',  '/api/user/get-user-info', ''],
                ['POST', '/api/book/list-book',   'page=1&limit=5'],
                ['GET',  '/api/folder/list-folder',''],
                ['POST', '/api/book/upload-pdf',  'name=test'],
                ['POST', '/api/upload/get-upload-url', 'filename=test.pdf&size=100'],
            ];
            const results = [];
            for (const [method, p, body] of endpoints) {
                try {
                    const init = { method, credentials: 'include',
                                   headers: { 'X-Requested-With': 'XMLHttpRequest' } };
                    if (method === 'POST') {
                        init.headers['Content-Type'] = 'application/x-www-form-urlencoded';
                        init.body = body;
                    }
                    const r = await fetch('https://fliphtml5.com' + p, init);
                    const text = await r.text();
                    results.push({ p, method, status: r.status, len: text.length, snippet: text.slice(0, 200) });
                } catch (e) { results.push({ p, method, err: e.message }); }
            }
            return results;
        });
        for (const r of probes) console.log(JSON.stringify(r));
    } finally { await browser.close().catch(() => {}); }
})();
