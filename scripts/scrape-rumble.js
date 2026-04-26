#!/usr/bin/env node
/**
 * Scrape a Rumble channel's video listing using puppeteer-real-browser.
 *
 * Outputs JSON to stdout. Uses Cloudflare bypass via puppeteer-real-browser
 * (which must be installed in the container where this runs).
 *
 * Usage: CHROME_PATH=/path/to/chrome node scrape-rumble.cjs [channelHandle] [maxPages]
 *
 * Expected to run inside the rsshub container which has Chrome pre-installed
 * at /app/node_modules/.cache/puppeteer/chrome/.../chrome-linux64/chrome.
 */
const { connect } = require('puppeteer-real-browser');

const channel = process.argv[2] || 'YadaYahowah7';
const maxPages = parseInt(process.argv[3] || '20', 10);
const baseUrl = `https://rumble.com/c/${channel}`;

(async () => {
    const { browser, page } = await connect({
        headless: false,
        turnstile: true,
        args: ['--no-sandbox', '--disable-setuid-sandbox'],
    });

    try {
        const seen = new Set();
        const videos = [];

        for (let p = 1; p <= maxPages; p++) {
            const url = p === 1 ? baseUrl : `${baseUrl}?page=${p}`;
            process.stderr.write(`fetching page ${p}: ${url}\n`);

            try {
                await page.goto(url, { waitUntil: 'networkidle2', timeout: 90000 });
            } catch (e) {
                process.stderr.write(`page ${p} nav error: ${e.message}\n`);
                break;
            }

            // First page needs extra time for Cloudflare challenge to resolve
            if (p === 1) {
                await new Promise(r => setTimeout(r, 15000));
            } else {
                await new Promise(r => setTimeout(r, 3000));
            }

            const pageVideos = await page.evaluate(() => {
                const out = [];
                const tiles = document.querySelectorAll('[data-video-id]');
                tiles.forEach(tile => {
                    const vidId = tile.getAttribute('data-video-id') || '';
                    if (!vidId) return;
                    const titleEl = tile.querySelector('.thumbnail__title, h3');
                    const linkEl = tile.querySelector('a[href^="/v"]');
                    const imgEl = tile.querySelector('img');
                    const timeEl = tile.querySelector('time');

                    const title = titleEl ? titleEl.textContent.trim() : '';
                    const href = linkEl ? linkEl.getAttribute('href') : '';
                    const thumbnail = imgEl ? (imgEl.getAttribute('src') || '') : '';
                    const dateIso = timeEl ? (timeEl.getAttribute('datetime') || '') : '';
                    const dateAgo = timeEl ? timeEl.textContent.trim() : '';

                    let embedId = '';
                    const m = href.match(/\/v([a-z0-9]+)-/);
                    if (m) embedId = 'v' + m[1];

                    if (vidId && title && href) {
                        out.push({
                            video_id: vidId,
                            title,
                            url: href.startsWith('http') ? href : 'https://rumble.com' + href,
                            thumbnail,
                            embed_id: embedId,
                            date: dateIso,
                            date_ago: dateAgo,
                        });
                    }
                });
                return out;
            });

            let added = 0;
            for (const v of pageVideos) {
                if (seen.has(v.video_id)) continue;
                seen.add(v.video_id);
                videos.push(v);
                added++;
            }
            process.stderr.write(`page ${p}: found ${pageVideos.length}, new ${added}\n`);
            if (added === 0) break;
        }

        videos.sort((a, b) => (b.date || '').localeCompare(a.date || ''));
        process.stdout.write(JSON.stringify(videos, null, 2));
    } finally {
        await browser.close();
    }
})().catch(e => {
    process.stderr.write('FATAL: ' + (e && e.stack || e) + '\n');
    process.exit(2);
});
