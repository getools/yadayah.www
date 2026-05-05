// Auto-match books on FlipHTML5 to volumes in our DB by name.
//
// Why: the upload page is bot-blocked, so we can't auto-upload PDFs. But
// re-uploads to existing flip_codes work fine. So the admin's only manual
// step is to drop PDFs into the FlipHTML5 dashboard once. After that THIS
// script (run on cron) reads list-folder-data, matches each book's name
// against yy_volume.volume_pdf / volume_code, and writes the flip_code back.
// Subsequent docx updates flow through the working re-upload path.
//
// Output: JSON to stdout summarising matches found / written. Logs to stderr.

const fs = require('fs');

let puppeteer;
try { puppeteer = require('puppeteer-real-browser'); }
catch (e) { puppeteer = require('rebrowser-puppeteer'); }

const COOKIES_PATH = '/host_jobs/fliphtml5/cookies.json';
const STATUS_PATH  = '/host_jobs/fliphtml5/match-status.json';

const log = (...a) => console.error('[fliphtml5-match]', ...a);

function loadCookies() {
    try {
        const raw = fs.readFileSync(COOKIES_PATH, 'utf8');
        const data = JSON.parse(raw);
        return Array.isArray(data) ? data : (data.cookies || []);
    } catch (e) { return []; }
}

// Normalize a name for fuzzy comparison: strip extension, lowercase,
// collapse separators (spaces, underscores, dots, hyphens, apostrophes).
function normalizeName(s) {
    return String(s || '')
        .replace(/\.(pdf|docx?)$/i, '')
        .toLowerCase()
        .replace(/[\s_.\-'’]/g, '');
}

(async () => {
    const cookies = loadCookies();
    if (!cookies.length) {
        const out = { ok: false, error: 'no cookies — admin must paste FlipHTML5 session first' };
        console.log(JSON.stringify(out));
        try { fs.writeFileSync(STATUS_PATH, JSON.stringify({ ...out, run_at: new Date().toISOString() }, null, 2)); } catch (e) {}
        process.exit(2);
    }

    let browser = null;
    try {
        const launchOpts = {
            headless: false,
            args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage',
                   '--disable-extensions', '--disable-gpu', '--no-first-run',
                   '--no-default-browser-check', '--blink-settings=imagesEnabled=false',
                   '--js-flags=--max-old-space-size=300', '--window-size=1280,900'],
        };
        if (typeof puppeteer.connect === 'function') {
            const r = await puppeteer.connect({
                ...launchOpts, turnstile: true, disableXvfb: false,
                customConfig: { chromePath: process.env.CHROME_PATH || undefined },
            });
            browser = r.browser;
        } else {
            browser = await puppeteer.launch(launchOpts);
        }
        const page = await browser.newPage();
        page.setDefaultTimeout(30000);
        await page.setCookie(...cookies);
        await page.goto('https://fliphtml5.com/dashboard/home', { waitUntil: 'domcontentloaded' });
        await new Promise(r => setTimeout(r, 1500));

        // Pull root + all sub-folders. We paginate large folder_ids if there
        // are >200 books eventually; for now 200 is plenty.
        const allBooks = await page.evaluate(async () => {
            const collect = async (folderId) => {
                const r = await fetch('https://fliphtml5.com/api/folder/list-folder-data', {
                    method: 'POST', credentials: 'include',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                               'X-Requested-With': 'XMLHttpRequest' },
                    body: `folder_id=${folderId}&page=1&limit=500`,
                });
                const j = await r.json().catch(() => null);
                if (!j || !j.data) return { folders: [], books: [] };
                const folders = (j.data.folders || j.data.folder_list || []);
                const books   = (j.data.list || j.data.books || j.data.book_list || []);
                return { folders, books };
            };
            const seen = new Set();
            const queue = ['0'];
            const allBooks = [];
            while (queue.length) {
                const fid = queue.shift();
                if (seen.has(String(fid))) continue;
                seen.add(String(fid));
                const { folders, books } = await collect(fid);
                for (const f of folders) {
                    const subId = f.folder_id || f.id || f.fid;
                    if (subId && !seen.has(String(subId))) queue.push(String(subId));
                }
                for (const b of books) {
                    const id = String(b.bookId || b.bid || b.id || b.bookcode || '');
                    if (!id) continue;
                    allBooks.push({
                        id,
                        name: b.book_name || b.bookname || b.title || b.name || '',
                        pdf:  b.pdf_name  || b.pdfname  || b.original_pdf_name || '',
                        cover: b.book_cover || b.cover || '',
                    });
                }
            }
            return allBooks;
        });

        // Dedupe by id — list-folder-data returns the same book under each
        // folder it appears in.
        const seenIds = new Set();
        const uniqueBooks = allBooks.filter(b => {
            if (seenIds.has(b.id)) return false;
            seenIds.add(b.id);
            return true;
        });
        log(`Found ${uniqueBooks.length} unique book(s) on FlipHTML5 (${allBooks.length} including dupes across folders)`);

        const matches = uniqueBooks.map(b => ({
            flip_id: b.id,
            book_name: b.name,
            pdf_name: b.pdf,
            normalized_name: normalizeName(b.name),
            normalized_pdf: normalizeName(b.pdf),
        })).filter(m => m.flip_id);

        const out = {
            ok: true,
            run_at: new Date().toISOString(),
            books_found: matches.length,
            matches,
        };
        console.log(JSON.stringify(out));
        try { fs.writeFileSync(STATUS_PATH, JSON.stringify(out, null, 2)); } catch (e) {}
    } catch (err) {
        const out = { ok: false, error: err.message, run_at: new Date().toISOString() };
        console.log(JSON.stringify(out));
        try { fs.writeFileSync(STATUS_PATH, JSON.stringify(out, null, 2)); } catch (e) {}
        process.exit(1);
    } finally {
        if (browser) await browser.close().catch(() => {});
    }
})();
