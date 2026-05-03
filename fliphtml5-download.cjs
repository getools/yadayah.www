/**
 * fliphtml5-download.cjs — Download the rendered offline package (.zip) for a
 * FlipHTML5 book.
 *
 * Mirror of fliphtml5-upload.cjs. Runs INSIDE the rsshub container (which has
 * rebrowser-puppeteer + Chrome). Login → navigate to the book's "Download
 * offline package" affordance → wait for the .zip to land in the configured
 * download dir → print the zip path on stdout.
 *
 * Extraction is intentionally NOT done here — the rsshub container (Debian
 * 12 minimal) doesn't ship `unzip`, and adding apt installs to a stock image
 * is more invasive than just letting the host wrapper extract from a
 * `docker cp`-ed copy of the .zip.
 *
 * Required env vars:
 *   FLIP_CODE   — FlipHTML5 book code (the bookId returned by upload)
 *   FLIP_EMAIL  — FlipHTML5 account email
 *   FLIP_PASS   — FlipHTML5 account password
 * Optional:
 *   TIMEOUT_MS  — hard cap, default 600000 (10 min). Render + download can
 *                 take a while for long PDFs, so this is bigger than the
 *                 upload script's 8 min default.
 *   DOWNLOAD_DIR— in-container scratch dir Chrome saves the .zip into.
 *                 Defaults to /tmp/flip-dl-<code>.
 *
 * Stdout (last line): the absolute container-side path of the downloaded
 * .zip file. Host wrapper docker-cp's it to the host and extracts.
 * Stderr: progress + errors.
 * Exit codes:
 *   0 — .zip downloaded; path printed
 *   1 — failure (FlipHTML5 navigation, missing download button, render not
 *       finished, plan tier doesn't permit offline download, etc.)
 *
 * Resource posture: same as upload — single headless tab, V8 heap capped at
 * 400 MB. Wrap with systemd-run --property=MemoryMax=700M for a hard cgroup
 * ceiling.
 */
const fs = require('fs');
const path = require('path');

let puppeteer;
try {
    puppeteer = require('puppeteer-real-browser');
} catch (e) {
    try {
        puppeteer = require('rebrowser-puppeteer');
    } catch (e2) {
        console.error('[fliphtml5-download] Neither puppeteer-real-browser nor rebrowser-puppeteer found in NODE_PATH');
        process.exit(1);
    }
}

const FLIP_CODE  = process.env.FLIP_CODE;
const FLIP_EMAIL = process.env.FLIP_EMAIL;
const FLIP_PASS  = process.env.FLIP_PASS;
const TIMEOUT_MS = parseInt(process.env.TIMEOUT_MS || '600000', 10);
const DOWNLOAD_DIR = process.env.DOWNLOAD_DIR || `/tmp/flip-dl-${FLIP_CODE || 'x'}`;

if (!FLIP_CODE) {
    console.error('[fliphtml5-download] Missing FLIP_CODE env');
    process.exit(1);
}
if (!FLIP_EMAIL || !FLIP_PASS) {
    console.error('[fliphtml5-download] Missing FLIP_EMAIL or FLIP_PASS env');
    process.exit(1);
}

fs.mkdirSync(DOWNLOAD_DIR, { recursive: true });

const log = (...a) => console.error('[fliphtml5-download]', ...a);

// Click an element whose visible text matches `regex`. Same helper as upload.
async function clickByText(page, regex, label) {
    const re = regex.toString();
    const clicked = await page.evaluate((reSrc) => {
        const re = new RegExp(reSrc.slice(1, reSrc.lastIndexOf('/')),
                              reSrc.slice(reSrc.lastIndexOf('/') + 1));
        const els = Array.from(document.querySelectorAll('button, span, div, a, li'))
            .filter(el => el.offsetParent !== null
                         && re.test((el.textContent || '').trim()));
        if (els[0]) { els[0].click(); return true; }
        return false;
    }, re).catch(() => false);
    if (clicked) log(`Clicked ${label}`);
    return clicked;
}

// Wait for a .zip to land in DOWNLOAD_DIR. Chrome writes a `.crdownload`
// suffix while in flight; we wait for that to disappear (download done) and
// for a finished `.zip` to appear.
async function waitForZip(timeoutMs) {
    const start = Date.now();
    while (Date.now() - start < timeoutMs) {
        const files = fs.readdirSync(DOWNLOAD_DIR);
        const inFlight = files.find(f => f.endsWith('.crdownload'));
        const done = files.find(f => f.endsWith('.zip'));
        if (done && !inFlight) {
            return path.join(DOWNLOAD_DIR, done);
        }
        await new Promise(r => setTimeout(r, 1000));
    }
    return null;
}

// Try the FlipHTML5 publish API directly. If the account+plan permits, this
// returns a pre-signed URL for the offline package without needing UI clicks.
// Fully optional — falls back to UI flow if no candidate endpoint matches.
async function tryApiDownload(page, flipCode) {
    return page.evaluate(async (code) => {
        const candidates = [
            { url: 'https://fliphtml5.com/api/book/download-package',     body: 'book_id=' + encodeURIComponent(code) },
            { url: 'https://fliphtml5.com/api/book/download-offline',     body: 'book_id=' + encodeURIComponent(code) },
            { url: 'https://fliphtml5.com/api/book/get-download-package', body: 'book_id=' + encodeURIComponent(code) },
        ];
        for (const c of candidates) {
            try {
                const r = await fetch(c.url, {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: c.body,
                });
                if (!r.ok) continue;
                const j = await r.json().catch(() => null);
                if (!j) continue;
                // Look for a URL field anywhere in the response.
                const urlMatch = JSON.stringify(j).match(/https?:\/\/[^"'\s]+\.zip[^"'\s]*/);
                if (urlMatch) return { endpoint: c.url, downloadUrl: urlMatch[0], raw: j };
            } catch (e) { /* try next */ }
        }
        return null;
    }, flipCode);
}

(async () => {
    let browser = null;
    const hardKill = setTimeout(() => {
        log(`Hard timeout (${TIMEOUT_MS}ms) — killing browser`);
        try { if (browser) browser.close(); } catch (e) {}
        process.exit(1);
    }, TIMEOUT_MS);

    try {
        log(`flip_code=${FLIP_CODE} download_dir=${DOWNLOAD_DIR}`);

        // puppeteer-real-browser requires CHROME_PATH set OR an executablePath
        // in launch options. Find the puppeteer-cached Chrome the rsshub
        // container ships with.
        if (!process.env.CHROME_PATH) {
            const cacheDirs = [
                '/app/node_modules/.cache/puppeteer/chrome',
                '/scraper/node_modules/.cache/puppeteer/chrome',
                '/root/.cache/puppeteer/chrome',
            ];
            for (const dir of cacheDirs) {
                if (!fs.existsSync(dir)) continue;
                const versions = fs.readdirSync(dir);
                for (const v of versions) {
                    const candidate = path.join(dir, v, 'chrome-linux64', 'chrome');
                    if (fs.existsSync(candidate)) {
                        process.env.CHROME_PATH = candidate;
                        log(`Using Chrome at ${candidate}`);
                        break;
                    }
                }
                if (process.env.CHROME_PATH) break;
            }
            if (!process.env.CHROME_PATH) {
                throw new Error('Could not locate Chrome binary; set CHROME_PATH env');
            }
        }

        const launchOpts = {
            headless: true,
            executablePath: process.env.CHROME_PATH,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-extensions',
                '--disable-gpu',
                '--no-first-run',
                '--no-default-browser-check',
                '--blink-settings=imagesEnabled=false',
                '--js-flags=--max-old-space-size=400',
            ],
        };
        // puppeteer-real-browser only exposes `connect` (and its `length` is
        // 0 because options are destructured), while rebrowser-puppeteer has
        // `launch`. The original `connect.length > 0` discriminator picked
        // the wrong branch for puppeteer-real-browser; fall back to launch
        // only when connect is genuinely absent.
        if (typeof puppeteer.connect === 'function') {
            // turnstile: true lets puppeteer-real-browser auto-solve any
            // Cloudflare Turnstile challenge that FlipHTML5's login throws
            // up. The original `turnstile: false` was a likely contributor
            // to login silently failing — page redirects back to /login.php
            // after the click but no error is shown.
            const r = await puppeteer.connect({ ...launchOpts, headless: 'new', turnstile: true, customConfig: {} });
            browser = r.browser;
        } else if (typeof puppeteer.launch === 'function') {
            browser = await puppeteer.launch(launchOpts);
        } else {
            throw new Error('puppeteer module has neither connect nor launch');
        }
        const page = await browser.newPage();
        page.setDefaultTimeout(45000);
        page.setDefaultNavigationTimeout(60000);

        // Configure Chrome to save downloads to DOWNLOAD_DIR. Has to happen
        // via CDP because puppeteer's high-level API doesn't expose download
        // path control.
        const client = await page.target().createCDPSession();
        await client.send('Page.setDownloadBehavior', {
            behavior: 'allow',
            downloadPath: DOWNLOAD_DIR,
        });

        // ── Login ───────────────────────────────────────────────────
        log('Logging in...');
        await page.goto('https://fliphtml5.com/login.php', { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('input[name="login-email"], input[type="email"]', { timeout: 30000 });

        // Dismiss any "What's New" / cookie / promo popover that might be
        // intercepting clicks. Operator confirmed manually that such a
        // popover can appear and block automated actions.
        const dismissModals = async () => {
            await page.keyboard.press('Escape').catch(() => {});
            await page.evaluate(() => {
                const isVisible = el => el && el.offsetParent !== null;
                // Click any element that looks like a close affordance.
                const closeSelectors = [
                    '[aria-label="Close" i]',
                    '[aria-label="close" i]',
                    'button[class*="close" i]',
                    'span[class*="close" i]',
                    '[class*="modal" i] [class*="close" i]',
                    '[class*="popup" i] [class*="close" i]',
                    '[class*="dialog" i] [class*="close" i]',
                ];
                for (const sel of closeSelectors) {
                    document.querySelectorAll(sel).forEach(el => { if (isVisible(el)) try { el.click(); } catch (e) {} });
                }
                // Click "Got it" / "OK" / "I understand" / "X" text buttons.
                const all = Array.from(document.querySelectorAll('button, span[role="button"], div[role="button"]'));
                for (const el of all) {
                    if (!isVisible(el)) continue;
                    const t = (el.textContent || '').trim();
                    if (/^(got it|ok|i understand|skip|close|dismiss|×|✕|X)$/i.test(t)) {
                        try { el.click(); } catch (e) {}
                    }
                }
            }).catch(() => {});
        };
        await dismissModals();
        await new Promise(r => setTimeout(r, 800));
        await page.type('input[name="login-email"], input[type="email"]', FLIP_EMAIL, { delay: 15 });
        await page.type('input[name="login-password"], input[type="password"]', FLIP_PASS, { delay: 15 });
        // Click the dark "Log in" button explicitly. `button[type="submit"]`
        // was matching SSO buttons ("Continue with Google/Facebook/Microsoft
        // /Apple") that appear before the email submit button on FlipHTML5's
        // redesigned login UI — the click was silently a no-op (no nav, no
        // error, just the form re-rendered).
        const loginClicked = await page.evaluate(() => {
            const all = Array.from(document.querySelectorAll('button, [role=button]'));
            const isLogIn = el => /^\s*log in\s*$/i.test((el.textContent || '').trim());
            const isSso   = el => /(continue with|google|facebook|microsoft|apple)/i.test((el.textContent || '').trim());
            const candidates = all.filter(el => el.offsetParent !== null && isLogIn(el) && !isSso(el));
            if (candidates.length === 0) return false;
            candidates.sort((a, b) => (b.offsetWidth * b.offsetHeight) - (a.offsetWidth * a.offsetHeight));
            candidates[0].click();
            return true;
        });
        if (!loginClicked) {
            const loginBtn = await page.$('.cc-login-actions-btn') || await page.$('button[type="submit"]');
            if (!loginBtn) throw new Error('Login button not found');
            await loginBtn.click();
        }
        await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {});
        // Give the post-login redirect a moment to settle, then dismiss any
        // post-login "What's New" / promo modal that interferes with later clicks.
        await new Promise(r => setTimeout(r, 4000));
        await dismissModals();
        await new Promise(r => setTimeout(r, 800));
        const postLoginUrl = page.url();
        log('Post-login URL: ' + postLoginUrl);
        if (/login\.php/.test(postLoginUrl)) {
            const visible = await page.evaluate(() => {
                const errEls = Array.from(document.querySelectorAll('.error, .login-error, .cc-login-error, [class*=error], .ant-message, .toast'))
                    .filter(el => el.offsetParent !== null && (el.textContent || '').trim());
                const errs = errEls.map(e => (e.textContent || '').trim()).slice(0, 5);
                // First 1KB of body text — captures whatever messaging is shown.
                const body = (document.body.innerText || '').slice(0, 1500);
                // Are turnstile/captcha widgets present?
                const captcha = !!document.querySelector('[id*=turnstile], [id*=captcha], [class*=cf-], iframe[src*=challenges]');
                return { errs, body_snippet: body, captcha_present: captcha, url: location.href };
            }).catch(() => ({ errs: [], body_snippet: '<eval failed>', captcha_present: false }));
            // Save a screenshot for offline inspection.
            try { await page.screenshot({ path: '/tmp/flip-login-fail.png', fullPage: true }); } catch (e) {}
            log('Login diagnostic: ' + JSON.stringify(visible).slice(0, 800));
            throw new Error('Login did not redirect away from /login.php. ' + (visible.captcha_present ? 'Captcha/Turnstile widget detected — site is blocking the headless browser.' : 'No visible error message; check /tmp/flip-login-fail.png inside rsshub container.'));
        }
        log('Logged in successfully');

        // ── Strategy 1: try the API directly ────────────────────────
        // If FlipHTML5 exposes an endpoint that returns a presigned URL we
        // can fetch in-page with the auth cookies set, we skip the UI flow
        // entirely.
        log('Probing API endpoints for direct download URL...');
        const apiResult = await tryApiDownload(page, FLIP_CODE);
        let zipPath = null;
        if (apiResult && apiResult.downloadUrl) {
            log(`API path worked: ${apiResult.endpoint} -> ${apiResult.downloadUrl}`);
            page.evaluate((u) => { window.location.href = u; }, apiResult.downloadUrl).catch(() => {});
            zipPath = await waitForZip(Math.max(TIMEOUT_MS - 60000, 60000));
            if (!zipPath) log('API URL did not produce a .zip — falling through to UI flow');
        } else {
            log('No API endpoint matched — using UI flow');
        }

        // ── Strategy 2: navigate Publications, click the book row's Download button ──
        // Confirmed from operator's screenshot at fliphtml5.com/dashboard/publications:
        // each book card displays Customize / Edit Page / Download / Share inline —
        // no kebab menu required. The book may live in a folder (e.g.
        // "Observations") so we use the folder-list API to find which folder
        // contains FLIP_CODE and navigate there.
        if (!zipPath) {
            log('Navigating to /dashboard/publications to locate book row');
            await page.goto('https://fliphtml5.com/dashboard/publications?lang=en', { waitUntil: 'domcontentloaded' });
            await new Promise(r => setTimeout(r, 3000));
            await dismissModals();
            await new Promise(r => setTimeout(r, 600));

            // FlipHTML5 may have folders. The book may live inside a folder
            // (the operator screenshots showed an "Observations" folder).
            // Easiest cross-folder approach: navigate via the API to find
            // the book's containing folder and go there directly. If the
            // book is in root, this is a no-op.
            const folderId = await page.evaluate(async (code) => {
                async function findInFolder(fid) {
                    const r = await fetch('https://fliphtml5.com/api/folder/list-folder-data', {
                        method: 'POST', credentials: 'include',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'folder_id=' + fid + '&page=1&limit=200',
                    });
                    if (!r.ok) return null;
                    const j = await r.json().catch(() => null);
                    if (!j || !j.data) return null;
                    const items = j.data.list || j.data.books || [];
                    const folders = j.data.folders || [];
                    for (const b of items) {
                        const bid = String(b.bookId || b.bid || b.id || b.bookcode || '');
                        if (bid === String(code)) return fid;
                    }
                    for (const f of folders) {
                        const sub = await findInFolder(f.folder_id || f.id);
                        if (sub !== null) return sub;
                    }
                    return null;
                }
                return findInFolder(0);
            }, FLIP_CODE).catch(() => null);

            if (folderId !== null && folderId !== 0) {
                log(`Book is in folder_id=${folderId}, navigating in`);
                await page.goto(`https://fliphtml5.com/dashboard/publications?folder_id=${folderId}&lang=en`, { waitUntil: 'domcontentloaded' });
                await new Promise(r => setTimeout(r, 2500));
                await dismissModals();
            }

            // Locate the book's row and click its inline "Download" button.
            const clickResult = await page.evaluate((code) => {
                const isVisible = el => el && el.offsetParent !== null;
                const containsCode = (el) => {
                    if (!el) return false;
                    const html = el.innerHTML || '';
                    if (html.indexOf(code) !== -1) return true;
                    const a = Array.from(el.querySelectorAll('a[href]'));
                    return a.some(x => (x.href || '').indexOf(code) !== -1);
                };
                // Find the smallest visible container that references the code.
                const candidates = Array.from(document.querySelectorAll(
                    '[class*="book-item" i], [class*="publication" i], [class*="row" i], [class*="card" i], li, article, div'
                )).filter(isVisible).filter(containsCode);
                if (!candidates.length) return { ok: false, reason: 'no row matches FLIP_CODE on this page' };
                candidates.sort((a, b) => a.getBoundingClientRect().height - b.getBoundingClientRect().height);
                const row = candidates[0];
                row.scrollIntoView({ block: 'center' });
                // Find the visible Download affordance INSIDE this row.
                const dlBtn = Array.from(row.querySelectorAll('button, a, span, div, [role=button]'))
                    .find(el => isVisible(el) && /^\s*download\s*$/i.test((el.textContent || '').trim()));
                if (!dlBtn) {
                    // Fall back to any clickable element whose text contains "Download".
                    const fallback = Array.from(row.querySelectorAll('button, a, [role=button]'))
                        .find(el => isVisible(el) && /download/i.test((el.textContent || '').trim()));
                    if (!fallback) return { ok: false, reason: 'no Download button inside row' };
                    try { fallback.click(); return { ok: true, used: 'fallback' }; } catch (e) { return { ok: false, reason: 'fallback click threw: ' + e.message }; }
                }
                try { dlBtn.click(); return { ok: true, used: 'exact-text' }; } catch (e) { return { ok: false, reason: 'click threw: ' + e.message }; }
            }, FLIP_CODE);
            log('Download click result: ' + JSON.stringify(clickResult));

            if (clickResult.ok) {
                // The row Download click opens a modal with multiple format
                // options under "For Self-hosting or Offline Reading":
                // HTML / EXE / EXE(ZIP) / Mac App. We want HTML — that's
                // the offline package we self-host at /flipbook/<code>/.
                // The modal has several "Download" buttons (one per format),
                // so we have to find the HTML row specifically and click ITS
                // Download button.
                await new Promise(r => setTimeout(r, 2500));
                const formatPicked = await page.evaluate(() => {
                    const isVisible = el => el && el.offsetParent !== null;
                    // Find the smallest visible element whose text is exactly "HTML".
                    const htmlLabels = Array.from(document.querySelectorAll('span, div, li, td, button'))
                        .filter(el => isVisible(el) && /^\s*HTML\s*$/i.test((el.textContent || '').trim()))
                        .sort((a, b) => a.getBoundingClientRect().height - b.getBoundingClientRect().height);
                    if (!htmlLabels.length) return { ok: false, reason: 'no HTML label visible' };
                    const htmlLabel = htmlLabels[0];
                    // Walk up the DOM looking for the row that contains
                    // both this label and a Download button.
                    let row = htmlLabel;
                    for (let depth = 0; depth < 8 && row; depth++, row = row.parentElement) {
                        const dl = Array.from(row.querySelectorAll('button, [role=button], a'))
                            .find(b => isVisible(b) && /^\s*download\s*$/i.test((b.textContent || '').trim()));
                        if (dl) { try { dl.click(); return { ok: true, depth }; } catch (e) { return { ok: false, reason: e.message }; } }
                    }
                    return { ok: false, reason: 'HTML row found but no Download button alongside it' };
                });
                log('Format pick result: ' + JSON.stringify(formatPicked));

                if (formatPicked.ok) {
                    // Some FlipHTML5 download flows then show a "preparing"
                    // progress screen. We just wait for the .zip to land in
                    // our DOWNLOAD_DIR — up to 4 min for the assemble +
                    // transfer of a multi-hundred-MB book package.
                    zipPath = await waitForZip(240000);
                }
            }
        }

        if (!zipPath) {
            throw new Error(
                'Could not obtain offline package. Possible reasons: (1) FlipHTML5 has not finished rendering the PDF yet (try again in 5+ min), (2) account plan does not permit offline download, (3) FlipHTML5 changed their UI/API and this script needs updating.'
            );
        }

        const zipBytes = fs.statSync(zipPath).size;
        log(`Got .zip: ${zipPath} (${(zipBytes / 1024 / 1024).toFixed(2)} MB)`);

        // Print the path on the LAST stdout line so the host wrapper can
        // capture it via `tail -1`. The host then docker-cp's the file out
        // and unzips on the host side (rsshub doesn't ship `unzip`).
        process.stdout.write(zipPath + '\n');
        log('Done');
        clearTimeout(hardKill);
        process.exit(0);
    } catch (err) {
        log(`ERROR: ${err && err.message}`);
        clearTimeout(hardKill);
        process.exit(1);
    } finally {
        try { if (browser) await browser.close(); } catch (e) {}
    }
})();
