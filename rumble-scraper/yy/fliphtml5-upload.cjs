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

// Cookie jar + session status — shared with admin-fliphtml5-session.php via
// the public/jobs/fliphtml5/ directory (bind-mounted into rsshub at
// /host_jobs and into the web container at /var/www/html/jobs).
// Both files are JSON and small (< 8 KB).
const COOKIES_PATH = '/host_jobs/fliphtml5/cookies.json';
const STATUS_PATH = '/host_jobs/fliphtml5/session-status.json';
function writeStatus(state, message) {
    try {
        const dir = path.dirname(STATUS_PATH);
        if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
        fs.writeFileSync(STATUS_PATH, JSON.stringify({
            state, message: message || '',
            updated_at: new Date().toISOString(),
        }, null, 2));
    } catch (e) { /* non-fatal */ }
}
function loadCookieJar() {
    try {
        if (!fs.existsSync(COOKIES_PATH)) return null;
        const raw = fs.readFileSync(COOKIES_PATH, 'utf8');
        const data = JSON.parse(raw);
        // Accept either { cookies: [...], saved_at: "..." } or a bare array.
        const list = Array.isArray(data) ? data : (data.cookies || []);
        if (!Array.isArray(list) || list.length === 0) return null;
        return list;
    } catch (e) {
        return null;
    }
}
function saveCookieJar(cookies) {
    try {
        fs.writeFileSync(COOKIES_PATH, JSON.stringify({
            cookies, saved_at: new Date().toISOString(),
        }, null, 2));
        try { fs.chmodSync(COOKIES_PATH, 0o600); } catch (e) {}
    } catch (e) { /* non-fatal */ }
}

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

// Click an upload affordance and feed the PDF to the resulting chooser.
// Used in both re-upload and first-time-upload modes. FlipHTML5 changes
// dashboard chrome regularly, so we try several strategies in order:
//
//   1) Click any visible button/link/div whose text matches an upload
//      regex; race against waitForFileChooser. Works when the click
//      programmatically triggers a real <input type=file>.click().
//   2) If the chooser never opens, look for an <input type=file> that's
//      already in the DOM (often hidden) and uploadFile() directly.
//   3) Last resort: inject a fresh <input type=file>, fire change events
//      so any global listener picks it up.
//
// On total failure we dump a short DOM snapshot to stderr so the next
// failed run is easier to diagnose.
const UPLOAD_TEXT_RE = /^(\+\s*)?Upload(\s+(Files?|PDFs?|Book|Now))?$|Upload Files?|Add (PDF|Book|File)/i;

// Click an upload affordance and feed the PDF to FlipHTML5's upload widget.
// Strategy:
//   1) Click any visible "Upload Files / Upload PDF / Add Book / + Upload"
//      affordance to open the upload modal.
//   2) Wait up to ~10s for an <input type=file> to appear (FlipHTML5 mounts
//      it lazily). Search the main frame and any iframes.
//   3) Call uploadFile(pdfPath) on that input — this dispatches a change
//      event so FlipHTML5's own JS picks the file up.
//   4) Fall back to FileChooser race (covers any flow that opens an OS
//      file picker via window.showOpenFilePicker / input.click).
//   5) On total failure dump a short DOM snapshot to stderr.
async function selectFileAndAccept(page, pdfPath) {
    const log = (...a) => console.error('[fliphtml5-upload]', ...a);
    log('Selecting file…');

    // Click the upload trigger. Match real buttons/links only (not parent
    // nav containers whose textContent happens to *contain* "Upload"), and
    // try patterns in priority order so the most specific button wins.
    const clicked = await page.evaluate(() => {
        const visible = el => el && el.offsetParent !== null;
        const all = Array.from(document.querySelectorAll('a, button, [role=button], label, div, span'))
            .filter(visible)
            .map(el => ({ el, txt: (el.textContent || '').trim() }))
            .filter(o => o.txt.length > 0 && o.txt.length <= 80);
        const isClickable = el =>
            /^(A|BUTTON)$/i.test(el.tagName) || el.getAttribute('role') === 'button';
        const patterns = [
            /^Upload Files\b/i,                  // "Upload Files PDF/Word/PPT/Images"
            /^Upload\s+(PDF|Word|Book|Now)\b/i,  // "Upload PDF" etc.
            /^\+?\s*Upload$/i,                   // bare "Upload" / "+ Upload"
            /^Add\s+(PDF|Book|File)\b/i,         // "Add Book"
        ];
        for (const re of patterns) {
            // Prefer real anchors/buttons over generic divs.
            const ranked = [...all].sort((a, b) => Number(!isClickable(a.el)) - Number(!isClickable(b.el)));
            const hit = ranked.find(o => re.test(o.txt));
            if (hit) {
                hit.el.click();
                return hit.el.tagName + ':' + hit.txt.slice(0, 60);
            }
        }
        return null;
    }).catch(() => null);
    log(`Upload trigger clicked: ${clicked || '(none matched)'}`);

    // The "Upload Files" link navigates to /edit-book/upload?lang=en, which
    // loads the actual upload widget via JS — give it generous time to
    // render. waitForNavigation may have already fired by the time we get
    // here so it's best-effort, then we explicitly wait for any input
    // to appear in the DOM.
    await page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 8000 }).catch(() => {});
    await page.waitForFunction(
        () => document.querySelectorAll('input[type=file]').length > 0
              || document.querySelectorAll('iframe').length > 0,
        { timeout: 10000 },
    ).catch(() => {});
    await new Promise(r => setTimeout(r, 2000));

    // Strategy A: poll for an <input type=file> in main frame + iframes.
    // FlipHTML5 typically mounts a hidden input inside the upload modal that
    // opens after the click. Be defensive: the click may navigate the page
    // (the "Upload Files" link does), which detaches frames mid-iteration —
    // every frame/handle operation must be try-wrapped.
    const deadline = Date.now() + 12000;
    while (Date.now() < deadline) {
        let frames = [];
        try { frames = page.frames(); } catch (e) { /* page navigating */ }
        for (const frame of frames) {
            let inputs = [];
            try { inputs = await frame.$$('input[type=file]'); } catch (e) { continue; }
            for (const inp of inputs) {
                try {
                    await inp.uploadFile(pdfPath);
                } catch (e) { continue; }
                let frameUrl = '(main)'; try { frameUrl = frame.url() || frameUrl; } catch (e) {}
                log(`File uploaded via <input type=file> in frame ${frameUrl}`);
                // Best-effort change-event dispatch — some widgets listen for
                // it explicitly. Failure here is non-fatal.
                try {
                    await frame.evaluate(el => {
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    }, inp);
                } catch (e) {}
                return;
            }
        }
        await new Promise(r => setTimeout(r, 500));
    }

    // Strategy B: race the click again against a FileChooser. Some flows open
    // an OS picker rather than embedding an <input>.
    log('No <input type=file> appeared; trying FileChooser race');
    try {
        const [fileChooser] = await Promise.all([
            page.waitForFileChooser({ timeout: 8000 }),
            page.evaluate(() => {
                const visible = el => el && el.offsetParent !== null;
                const all = Array.from(document.querySelectorAll('a, button, [role=button], label'))
                    .filter(visible)
                    .map(el => ({ el, txt: (el.textContent || '').trim() }))
                    .filter(o => o.txt.length > 0 && o.txt.length <= 80);
                const patterns = [/^Upload Files\b/i, /^\+?\s*Upload$/i];
                for (const re of patterns) {
                    const hit = all.find(o => re.test(o.txt));
                    if (hit) { hit.el.click(); return; }
                }
            }),
        ]);
        await fileChooser.accept([pdfPath]);
        log(`File selected via FileChooser: ${pdfPath}`);
        return;
    } catch (e) {
        log(`FileChooser race failed: ${e.message}`);
    }

    // Diagnostic dump for future failures — include hidden elements + frame
    // contents so we can tell whether the upload UI is rendered-but-hidden,
    // or genuinely missing.
    const snippet = await page.evaluate(() => {
        const buttons = Array.from(document.querySelectorAll('button, a, [role=button]'))
            .slice(0, 30)
            .map(el => `${el.tagName}: "${(el.textContent || '').trim().slice(0,60)}"`);
        const inputs = Array.from(document.querySelectorAll('input'))
            .map(el => `type=${el.type || ''} id=${el.id || ''} class=${(el.className||'').toString().slice(0,40)} hidden=${el.offsetParent === null}`)
            .slice(0, 20);
        const iframes = Array.from(document.querySelectorAll('iframe')).map(f => ({
            src: f.src, id: f.id, w: f.offsetWidth, h: f.offsetHeight,
        })).slice(0, 5);
        return {
            url: window.location.href,
            bodyChars: (document.body && document.body.innerText || '').length,
            iframes, buttons, inputs,
        };
    }).catch(e => ({ error: e.message }));
    // Also try to peek inside same-origin iframes.
    let frameInfo = [];
    try {
        for (const f of page.frames()) {
            try {
                const info = await f.evaluate(() => ({
                    inputs: document.querySelectorAll('input[type=file]').length,
                    bodyLen: (document.body && document.body.innerText || '').length,
                }));
                frameInfo.push({ url: f.url(), ...info });
            } catch (e) { frameInfo.push({ url: 'unknown', err: e.message }); }
        }
    } catch (e) {}
    log('No upload widget found. Page snapshot: ' + JSON.stringify(snippet));
    log('Frame contents: ' + JSON.stringify(frameInfo));
    // Save a screenshot + full HTML so the admin can see exactly what FlipHTML5
    // rendered when the upload widget didn't appear. Lands at
    // /opt/yada-www/public/jobs/fliphtml5/last-failure-{volume_key}.{png,html}
    // via the bind-mounted /host_jobs path.
    try {
        const failDir = '/host_jobs/fliphtml5';
        if (!fs.existsSync(failDir)) fs.mkdirSync(failDir, { recursive: true });
        const stem = failDir + '/last-failure-' + VOLUME_KEY;
        await page.screenshot({ path: stem + '.png', fullPage: true });
        const html = await page.content();
        fs.writeFileSync(stem + '.html', html);
        log('Diagnostic screenshot+html saved: ' + stem + '.{png,html}');
    } catch (e) { log('Could not save diagnostic capture: ' + e.message); }
    throw new Error('Could not trigger file upload');
}

// Pull the set of bookIds in the root bookshelf via FlipHTML5's own
// list-folder-data API. We're already authenticated (cookies set during
// login), so the page-context fetch piggybacks on that session. This is
// how we detect the newly-created book after a first-time upload.
//
// page.evaluate must return a JSON-serializable value — Sets do not survive
// the bridge (they come back as `{}` with no `.size`). Return an array and
// build the Set on the Node side.
async function snapshotRootBookIds(page) {
    const arr = await page.evaluate(async () => {
        try {
            const r = await fetch('https://fliphtml5.com/api/folder/list-folder-data', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'folder_id=0&page=1&limit=200',
            });
            const j = await r.json();
            const items = (j && j.data && (j.data.list || j.data.books)) || [];
            return items.map(b => String(b.bookId || b.bid || b.id || b.bookcode || '')).filter(Boolean);
        } catch (e) {
            return [];
        }
    }).catch(() => []);
    return new Set(Array.isArray(arr) ? arr : []);
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

        // Run a real-browser-flavored session under a virtual display (xvfb).
        // FlipHTML5's bot detection silently sinks headless logins, so we
        // launch non-headless and let puppeteer-real-browser's stealth do
        // its thing. xvfb-run wraps the worker in book-pipeline-worker.sh
        // so DISPLAY is set when this script runs.
        const launchOpts = {
            headless: false,
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
                '--window-size=1280,900',
            ],
        };

        // puppeteer-real-browser only exposes `connect` (its `length` is 0
        // because options are destructured), while rebrowser-puppeteer has
        // `launch`. The original `connect.length > 0` discriminator picked
        // the wrong branch for puppeteer-real-browser, throwing
        // "puppeteer.launch is not a function" — likely the cause of the
        // recurring "FlipHTML5 upload failed (exit 1)" events.
        if (typeof puppeteer.connect === 'function') {
            const r = await puppeteer.connect({
                ...launchOpts,
                turnstile: true,
                disableXvfb: false,
                customConfig: { chromePath: process.env.CHROME_PATH || undefined },
            });
            browser = r.browser;
        } else if (typeof puppeteer.launch === 'function') {
            browser = await puppeteer.launch(launchOpts);
        } else {
            throw new Error('puppeteer module has neither connect nor launch');
        }
        const page = await browser.newPage();
        page.setDefaultTimeout(45000);
        page.setDefaultNavigationTimeout(60000);

        // Try the saved cookie jar before doing any form login. The admin
        // panel can refresh this file when it expires.
        const savedCookies = loadCookieJar();
        let usingSavedCookies = false;
        if (savedCookies) {
            try {
                await page.setCookie(...savedCookies);
                usingSavedCookies = true;
                log(`Loaded ${savedCookies.length} cookie(s) from ${COOKIES_PATH}`);
            } catch (e) {
                log(`Failed to inject saved cookies: ${e.message}`);
            }
        }

        // Probe the dashboard to see if our (cookie-injected or fresh) session
        // is authenticated. If the goto lands on /login.php, the cookies are
        // expired (or never existed) and we fall back to form login.
        log('Probing session via dashboard…');
        await page.goto('https://fliphtml5.com/dashboard/home', { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {});
        let probeUrl = page.url();
        let authed = !/login\.php/.test(probeUrl);

        if (!authed) {
            log(`Session not authenticated (landed on ${probeUrl}). Falling back to form login.`);
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
            probeUrl = page.url();
            authed = /dashboard|home|projects/.test(probeUrl) && !/login\.php/.test(probeUrl);
        }

        if (authed) {
            // Persist the current cookie jar so subsequent runs can skip
            // login entirely until they expire.
            try {
                const fresh = await page.cookies('https://fliphtml5.com');
                if (fresh && fresh.length) saveCookieJar(fresh);
            } catch (e) { /* ignore */ }
            writeStatus('active', usingSavedCookies ? 'Reused saved cookies' : 'Logged in via form (saved fresh cookies)');
            log(`Authenticated (url=${probeUrl}, source=${usingSavedCookies ? 'cookies' : 'form'})`);
        } else {
            writeStatus('expired', usingSavedCookies
                ? 'Saved cookies are expired — refresh via the admin panel.'
                : 'Form login was rejected (bot detection); paste a fresh session via the admin panel.');
            log(`Unauthenticated (url=${probeUrl}). Re-upload may still work without dashboard auth; first-time upload will fail.`);
            // For first-time upload (no FLIP_CODE) we strictly need dashboard
            // access. Bail now so the admin sees a clear status.
            if (!FLIP_CODE) {
                throw new Error('FlipHTML5 session unavailable for first-time upload: ' + probeUrl);
            }
        }

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

            // Detect the new flip code. Three signals, in priority order:
            //   a) the URL navigates to /edit-book/{code}/... (most reliable
            //      when the convert flow lands on the editor)
            //   b) list-folder-data shows a bookId we didn't see pre-upload
            //   c) if list-folder-data was empty pre-upload (API quirk),
            //      take the first bookId that appears post-upload
            const start = Date.now();
            while (Date.now() - start < 90000) {
                await new Promise(r => setTimeout(r, 5000));
                const urlMatch = page.url().match(/\/edit-book\/([^\/?#]+)/);
                if (urlMatch && urlMatch[1]) {
                    resolvedFlipCode = urlMatch[1];
                    log(`New book code from URL: ${resolvedFlipCode}`);
                    break;
                }
                const afterIds = await snapshotRootBookIds(page);
                if (beforeIds.size > 0) {
                    for (const id of afterIds) {
                        if (!beforeIds.has(id)) { resolvedFlipCode = id; break; }
                    }
                } else if (afterIds.size > 0) {
                    // Pre-upload snapshot was empty (API hiccup). Take the
                    // most-recent book — list-folder-data sorts newest first.
                    resolvedFlipCode = afterIds.values().next().value;
                }
                if (resolvedFlipCode) {
                    log(`New book detected: ${resolvedFlipCode}`);
                    break;
                }
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
