/**
 * fliphtml5-upload.cjs — Upload (or re-upload) a PDF to FlipHTML5.
 *
 * The FlipHTML5 books are intermediate artifacts: we upload the PDF, FlipHTML5
 * renders it, then the worker downloads the rendered package and self-hosts
 * under /opt/yada-www/public/flipbook/<code>/. So we don't care about folder
 * organization on FlipHTML5's side — everything lands in the root bookshelf.
 *
 * Runs INSIDE the rsshub container (which has rebrowser-puppeteer and Chrome).
 *
 * Two modes, selected automatically:
 *   - FLIP_CODE present → re-upload flow on the existing book (replace PDF)
 *   - FLIP_CODE absent  → first-time upload to root, returns the new book code
 *
 * Required env vars:
 *   PDF_NAME    — basename of PDF in /host_pdf/
 *   VOLUME_KEY  — yy_volume.volume_key for log context
 *   FLIP_EMAIL  — FlipHTML5 account email
 *   FLIP_PASS   — FlipHTML5 account password
 * Optional:
 *   FLIP_CODE   — existing book code (re-upload mode); else first-time upload.
 *   TIMEOUT_MS  — hard cap, default 480000 (8 min).
 *
 * Stdout (last line): the flip code on success (existing or newly created).
 * Stderr: progress + errors.
 * Exit codes:
 *   0 — upload submitted, flip code printed (FlipHTML5 conversion runs async)
 *   1 — failure
 *
 * Resource posture: single headless tab, images/extensions disabled, V8 heap
 * capped at 400 MB. Browser is closed in a `finally` block. Outer worker
 * wraps this with systemd-run --scope --property=MemoryMax=700M for a hard
 * cgroup ceiling, plus a pre-flight RAM/load gate.
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
        console.error('[fliphtml5-upload] Neither puppeteer-real-browser nor rebrowser-puppeteer found in NODE_PATH');
        process.exit(1);
    }
}

const PDF_NAME = process.env.PDF_NAME;
const VOLUME_KEY = process.env.VOLUME_KEY;
const FLIP_CODE = process.env.FLIP_CODE || '';
const FLIP_EMAIL = process.env.FLIP_EMAIL;
const FLIP_PASS = process.env.FLIP_PASS;
const TIMEOUT_MS = parseInt(process.env.TIMEOUT_MS || '480000', 10);

if (!PDF_NAME || !VOLUME_KEY) {
    console.error('[fliphtml5-upload] Missing PDF_NAME or VOLUME_KEY env');
    process.exit(1);
}
if (!FLIP_EMAIL || !FLIP_PASS) {
    console.error('[fliphtml5-upload] Missing FLIP_EMAIL or FLIP_PASS env');
    process.exit(1);
}

const PDF_PATH = `/host_pdf/${PDF_NAME}`;
if (!fs.existsSync(PDF_PATH)) {
    console.error(`[fliphtml5-upload] PDF not mounted: ${PDF_PATH}`);
    process.exit(1);
}

// ── Helpers ─────────────────────────────────────────────────────────

// Click an element whose visible text matches `regex`. Returns true if a
// candidate was found and clicked. Used for dialog buttons (Discard, Confirm,
// Convert) where FlipHTML5 doesn't expose stable IDs/test attributes.
async function clickByText(page, regex, label) {
    const re = regex.toString();
    const clicked = await page.evaluate((reSrc) => {
        const re = new RegExp(reSrc.slice(1, reSrc.lastIndexOf('/')),
                              reSrc.slice(reSrc.lastIndexOf('/') + 1));
        const els = Array.from(document.querySelectorAll('button, span, div, a'))
            .filter(el => el.offsetParent !== null
                         && re.test((el.textContent || '').trim()));
        if (els[0]) { els[0].click(); return true; }
        return false;
    }, re).catch(() => false);
    if (clicked) console.error('[fliphtml5-upload]', `Clicked ${label}`);
    return clicked;
}

// Click an "Upload Files" affordance and accept a file in the resulting
// chooser. Used in both re-upload and first-time-upload modes.
async function selectFileAndAccept(page, pdfPath) {
    console.error('[fliphtml5-upload]', 'Selecting file…');
    const [fileChooser] = await Promise.all([
        page.waitForFileChooser({ timeout: 25000 }),
        page.evaluate(() => {
            const candidates = Array.from(document.querySelectorAll('button, a, div'))
                .filter(el => el.offsetParent !== null
                             && /Upload Files/i.test(el.textContent || ''));
            if (candidates[0]) candidates[0].click();
        }),
    ]);
    await fileChooser.accept([pdfPath]);
    console.error('[fliphtml5-upload]', `File selected: ${pdfPath}`);
}

// Pull the set of bookIds in the root bookshelf via FlipHTML5's own
// list-folder-data API. We're already authenticated (cookies set during
// login), so the page-context fetch piggybacks on that session. This is
// how we detect the newly-created book after a first-time upload.
async function snapshotRootBookIds(page) {
    return page.evaluate(async () => {
        try {
            const r = await fetch('https://fliphtml5.com/api/folder/list-folder-data', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'folder_id=0&page=1&limit=200',
            });
            const j = await r.json();
            const items = (j && j.data && (j.data.list || j.data.books)) || [];
            return new Set(items.map(b => String(b.bookId || b.bid || b.id || b.bookcode || '')).filter(Boolean));
        } catch (e) {
            return new Set();
        }
    }).then(set => set || new Set());
}

(async () => {
    let browser = null;
    const log = (...a) => console.error('[fliphtml5-upload]', ...a);
    const hardKill = setTimeout(() => {
        log(`Hard timeout (${TIMEOUT_MS}ms) — killing browser`);
        try { if (browser) browser.close(); } catch (e) {}
        process.exit(1);
    }, TIMEOUT_MS);
    try {
        log(`volume_key=${VOLUME_KEY} pdf=${PDF_NAME} flip_code=${FLIP_CODE}`);

        // Resource-conservative launch. --no-sandbox is required when running
        // as a non-privileged docker user; --js-flags caps V8 heap so a
        // runaway page can't blow past the 700 MB cgroup ceiling.
        const launchOpts = {
            headless: true,
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

        // puppeteer-real-browser only exposes `connect` (its `length` is 0
        // because options are destructured), while rebrowser-puppeteer has
        // `launch`. The original `connect.length > 0` discriminator picked
        // the wrong branch for puppeteer-real-browser, throwing
        // "puppeteer.launch is not a function" — likely the cause of the
        // recurring "FlipHTML5 upload failed (exit 1)" events.
        if (typeof puppeteer.connect === 'function') {
            const r = await puppeteer.connect({ ...launchOpts, headless: 'new', turnstile: false, customConfig: {} });
            browser = r.browser;
        } else if (typeof puppeteer.launch === 'function') {
            browser = await puppeteer.launch(launchOpts);
        } else {
            throw new Error('puppeteer module has neither connect nor launch');
        }
        const page = await browser.newPage();
        page.setDefaultTimeout(45000);
        page.setDefaultNavigationTimeout(60000);

        // Login
        log('Logging in…');
        await page.goto('https://fliphtml5.com/login.php', { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('input[name="login-email"], input[type="email"]', { timeout: 30000 });
        await page.type('input[name="login-email"], input[type="email"]', FLIP_EMAIL, { delay: 15 });
        await page.type('input[name="login-password"], input[type="password"]', FLIP_PASS, { delay: 15 });
        const loginBtn = await page.$('.cc-login-actions-btn') || await page.$('button[type="submit"]');
        if (!loginBtn) throw new Error('Login button not found');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {}),
            loginBtn.click(),
        ]);
        // Confirm landed in dashboard. Some accounts redirect to home/projects.
        const urlAfterLogin = page.url();
        if (!/dashboard|home|projects/.test(urlAfterLogin)) {
            log(`Login URL unexpected: ${urlAfterLogin}`);
        }
        log('Logged in');

        let resolvedFlipCode = '';

        if (FLIP_CODE) {
            // ── Re-upload mode ──────────────────────────────────────
            const reuploadUrl = `https://fliphtml5.com/edit-book/${FLIP_CODE}/re-upload`;
            log(`Re-upload mode: navigating to ${reuploadUrl}`);
            await page.goto(reuploadUrl, { waitUntil: 'domcontentloaded' });
            await new Promise(r => setTimeout(r, 5000));
            await selectFileAndAccept(page, PDF_PATH);
            // Re-upload settings dialog: Discard old settings → import the
            // new PDF's bookmarks/TOC fresh.
            await clickByText(page, /^Discard$/i, 'Discard');
            await new Promise(r => setTimeout(r, 1500));
            await clickByText(page, /^Confirm$/i, 'Confirm');
            log('Confirmed — conversion running async on FlipHTML5');
            await new Promise(r => setTimeout(r, 2000));
            resolvedFlipCode = FLIP_CODE;
        } else {
            // ── First-time upload mode (default bookshelf location) ──
            log('First-time upload: navigating to dashboard');
            await page.goto('https://fliphtml5.com/dashboard/home', { waitUntil: 'domcontentloaded' });
            await new Promise(r => setTimeout(r, 5000));
            // Snapshot existing bookIds BEFORE upload so we can find the
            // newly-created one afterwards. Using FlipHTML5's own list-folder
            // API (folder_id=0 = root bookshelf).
            const beforeIds = await snapshotRootBookIds(page);
            log(`Pre-upload root books: ${beforeIds.size}`);
            await selectFileAndAccept(page, PDF_PATH);
            // Some flows show a "Convert" button on the upload landing page.
            await new Promise(r => setTimeout(r, 3000));
            await clickByText(page, /^Convert$/i, 'Convert');
            log('Submitted — waiting briefly for FlipHTML5 to register the new book');
            // Poll the API for a new bookId. We don't wait for full conversion
            // — just for the book record to be created. Up to 90 sec.
            const start = Date.now();
            while (Date.now() - start < 90000) {
                await new Promise(r => setTimeout(r, 5000));
                const afterIds = await snapshotRootBookIds(page);
                for (const id of afterIds) {
                    if (!beforeIds.has(id)) {
                        resolvedFlipCode = id;
                        log(`New book detected: ${id}`);
                        break;
                    }
                }
                if (resolvedFlipCode) break;
            }
            if (!resolvedFlipCode) {
                throw new Error('Upload submitted but new book code did not appear in root bookshelf within 90s');
            }
        }

        // Print the flip code as the LAST stdout line so the worker can
        // capture it via tail -1.
        process.stdout.write(resolvedFlipCode + '\n');
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
