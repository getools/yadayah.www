/* Flipbook TTS playback
 *
 * Adds a Play/Pause button just above the bottom seek bar. When a TTS
 * MP3 is available for the chapter containing the current page, the
 * button is enabled and clicking it plays the chapter audio starting
 * from the first paragraph's offset on the current page.
 *
 *   • As playback crosses each paragraph marker, the flipbook auto-
 *     turns to that paragraph's page.
 *   • At the end of a chapter MP3, playback pauses for the admin-set
 *     "New Chapter" pause (Admin → TTS → Pauses) then loads and plays
 *     the next chapter's MP3 (if one exists & is ready).
 *   • Pages whose chapter has no audio still show the button — at
 *     opacity 0.25 and disabled — so the toolbar layout is stable.
 *
 * Public API (called from flipbook-viewer.js):
 *   FlipbookTTS.init({ bookCode, getCurrentPage, gotoPage, totalPages });
 *   FlipbookTTS.notifyPageChange(page1);   // viewer calls this whenever
 *                                          // the visible page changes
 */
(function () {
    'use strict';

    var BM = window.FlipbookTTS = window.FlipbookTTS || {};
    var cfg = null;
    var btn = null;
    var audio = null;
    var progressEl = null;       // wrapper around the seek track + fill
    var progressFill = null;     // inner bar that grows with currentTime
    var current = null;     // last fetched chapter payload (see API shape)
    var fetchSeq = 0;       // monotonic — only latest fetch's response wins
    var loadedChapterKey = null;
    var isPlaying = false;
    var chapterPauseTimer = null;
    var suppressPageSync = false;  // set true when WE drive a goto from a marker
    var lastPage = 0;
    var currentParagraphNumber = -1;  // paragraph being narrated right now

    // ── DOM injection ───────────────────────────────────────────────────
    function injectStyles() {
        if (document.getElementById('fb-tts-style')) return;
        var st = document.createElement('style');
        st.id = 'fb-tts-style';
        st.textContent = [
            // The button is a fixed circle floating above the bottom nav,
            // centered horizontally over the seek bar. z-index sits below
            // any modal masks (bookmark popover @200) but above page content.
            '.fb-tts-btn { position: fixed; left: 50%; transform: translateX(-50%);',
            '              bottom: calc(var(--nav, 56px) - 2px); z-index: 12;',
            '              width: 43px; height: 43px; border-radius: 50%;',
            '              background: #1f3550; color: #cfe1ff; border: 1px solid #2a4d70;',
            '              display: flex; align-items: center; justify-content: center;',
            '              cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.35);',
            '              transition: opacity 0.2s, transform 0.1s, background 0.15s; }',
            '.fb-tts-btn:hover:not(.is-disabled) { background: #28456a; }',
            '.fb-tts-btn:active:not(.is-disabled) { transform: translateX(-50%) scale(0.95); }',
            '.fb-tts-btn.is-disabled { opacity: 0.25; cursor: not-allowed; }',
            '.fb-tts-btn svg { width: 20px; height: 20px; }',
            // Brief amber tint when waiting between chapters.
            '.fb-tts-btn.is-pausing { background: #4d3a1f; border-color: #6d5a3a; color: #f7d77a; }',
            // MP3 seek bar — sits just below the Play/Pause button and
            // spans only the visible page(s), so its left/width get
            // recomputed from the active page rect on every layout change.
            // Hidden while no chapter audio is available.
            '.fb-tts-progress { position: fixed; z-index: 11; height: 12px;',
            '                   transition: opacity 0.2s; pointer-events: auto; }',
            '.fb-tts-progress.is-hidden { opacity: 0; pointer-events: none; }',
            '.fb-tts-progress-track { position: relative; width: 100%; height: 6px;',
            '                         margin-top: 3px; background: rgba(255,255,255,0.18);',
            '                         border-radius: 3px; cursor: pointer; overflow: hidden; }',
            '.fb-tts-progress-fill  { position: absolute; top: 0; left: 0; height: 100%;',
            '                         background: #cfe1ff; width: 0%; }',
            // Subtle read-along highlight — applied to text-layer spans
            // that belong to the paragraph currently being narrated. We
            // only mark spans on the visible page, so a paragraph
            // straddling a page break highlights its portion of each
            // page as the flipbook turns. !important wins against the
            // page-specific styles already injected on each span.
            '.text-layer span.tts-current,',
            '.pg .text-layer span.tts-current,',
            '.cpg .text-layer span.tts-current {',
            '    background: rgba(255,230,150,0.14) !important;',
            '    box-shadow: 0 0 0 2px rgba(255,230,150,0.14);',
            '    border-radius: 2px;',
            '}',
        ].join('\n');
        document.head.appendChild(st);
    }

    var ICON_PLAY  = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5v14l12-7L7 5z"/></svg>';
    var ICON_PAUSE = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14"/><rect x="14" y="5" width="4" height="14"/></svg>';

    function ensureButton() {
        if (btn) return;
        btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fb-tts-btn is-disabled';
        btn.innerHTML = ICON_PLAY;
        btn.title = 'Loading TTS…';
        btn.setAttribute('aria-label', 'Play chapter audio');
        btn.addEventListener('click', onClick);
        document.body.appendChild(btn);
    }

    function ensureProgress() {
        if (progressEl) return;
        progressEl = document.createElement('div');
        progressEl.className = 'fb-tts-progress is-hidden';
        var track = document.createElement('div');
        track.className = 'fb-tts-progress-track';
        progressFill = document.createElement('div');
        progressFill.className = 'fb-tts-progress-fill';
        track.appendChild(progressFill);
        progressEl.appendChild(track);
        track.addEventListener('click', onProgressClick);
        document.body.appendChild(progressEl);
        // Keep geometry in sync with whatever's on screen.
        window.addEventListener('resize', syncProgressGeometry);
        window.addEventListener('scroll', syncProgressGeometry, true);
    }

    // Width + horizontal position track the currently visible page area:
    //   • carousel (single-page) mode → .cpg.center
    //   • spread (two-page) mode      → #book covers both pages
    // Vertical position sits just below the play/pause button.
    function syncProgressGeometry() {
        if (!progressEl) return;
        var pageEl = document.body.classList.contains('mode-carousel')
            ? document.querySelector('#carousel .cpg.center')
            : document.getElementById('book');
        if (!pageEl) return;
        var r = pageEl.getBoundingClientRect();
        if (r.width <= 0) return;
        progressEl.style.left   = Math.round(r.left)  + 'px';
        progressEl.style.width  = Math.round(r.width) + 'px';
        // Button bottom edge ≈ calc(var(--nav, 56px) - 2px). Drop the bar
        // a few pixels under that so it visually anchors to the button.
        progressEl.style.bottom = 'calc(var(--nav, 56px) - 22px)';
    }

    function ensureAudio() {
        if (audio) return;
        audio = document.createElement('audio');
        audio.preload = 'metadata';
        audio.addEventListener('timeupdate',     function () { onTimeUpdate(); renderProgress(); });
        audio.addEventListener('loadedmetadata', renderProgress);
        audio.addEventListener('durationchange', renderProgress);
        audio.addEventListener('seeked',         renderProgress);
        audio.addEventListener('ended', onEnded);
        audio.addEventListener('playing', function () { isPlaying = true; renderButton(); renderProgress(); });
        audio.addEventListener('pause',   function () { isPlaying = false; renderButton(); clearHighlight(); });
        document.body.appendChild(audio);
    }

    // Click anywhere on the track to seek. The track lives at .fb-tts-progress-track;
    // the inner fill is pointer-events transparent (default) so click coords are
    // always against the track itself.
    function onProgressClick(e) {
        if (!current || !current.available || !audio || !audio.duration) return;
        var trackRect = e.currentTarget.getBoundingClientRect();
        var ratio = (e.clientX - trackRect.left) / trackRect.width;
        ratio = Math.max(0, Math.min(1, ratio));
        try { audio.currentTime = ratio * audio.duration; } catch (err) {}
    }

    function renderProgress() {
        if (!progressEl) return;
        var show = !!(current && current.available);
        progressEl.classList.toggle('is-hidden', !show);
        if (!show || !audio || !audio.duration || !isFinite(audio.duration)) {
            if (progressFill) progressFill.style.width = '0%';
            return;
        }
        if (progressFill) {
            var pct = (audio.currentTime / audio.duration) * 100;
            progressFill.style.width = pct.toFixed(2) + '%';
        }
        syncProgressGeometry();
    }

    // ── State + render ─────────────────────────────────────────────────
    function renderButton() {
        // Whenever button state changes, the progress bar's visibility
        // (and geometry, if it just became visible) follows along.
        renderProgress();
        if (!btn) return;
        if (chapterPauseTimer) {
            btn.classList.remove('is-disabled');
            btn.classList.add('is-pausing');
            btn.innerHTML = ICON_PAUSE;
            btn.title = 'Pausing between chapters — click to cancel';
            return;
        }
        btn.classList.remove('is-pausing');
        if (!current || !current.available) {
            btn.classList.add('is-disabled');
            btn.innerHTML = ICON_PLAY;
            btn.title = 'No TTS audio for this chapter';
            return;
        }
        btn.classList.remove('is-disabled');
        if (isPlaying) {
            btn.innerHTML = ICON_PAUSE;
            btn.title = 'Pause (' + (current.chapter_number || '') + ' ' + (current.chapter_name || '') + ')';
        } else {
            btn.innerHTML = ICON_PLAY;
            btn.title = 'Play (' + (current.chapter_number || '') + ' ' + (current.chapter_name || '') + ')';
        }
    }

    // ── Lookup helpers ─────────────────────────────────────────────────
    function markerForPage(markers, page1) {
        // Return the FIRST marker whose paragraph_page is >= the requested
        // page (so playing from a given page lands on its first paragraph).
        // Falls back to nearest preceding marker if none reach the page.
        if (!markers || !markers.length) return null;
        for (var i = 0; i < markers.length; i++) {
            if (markers[i].paragraph_page >= page1) return markers[i];
        }
        return markers[markers.length - 1];
    }

    function pageAtMs(markers, ms) {
        // Largest paragraph_page among markers with offset_ms <= ms.
        // Returns 0 if before the first marker (treat as chapter start).
        if (!markers || !markers.length) return 0;
        var page = 0;
        for (var i = 0; i < markers.length; i++) {
            if (markers[i].offset_ms <= ms) page = markers[i].paragraph_page;
            else break;
        }
        return page;
    }

    function markerAtMs(markers, ms) {
        // Largest marker with offset_ms <= ms.
        if (!markers || !markers.length) return null;
        var best = null;
        for (var i = 0; i < markers.length; i++) {
            if (markers[i].offset_ms <= ms) best = markers[i];
            else break;
        }
        return best;
    }

    // ── Read-along highlight ───────────────────────────────────────────
    // Map paragraph_number → marker so we can fetch the paragraph text
    // to span-match on the current page. Each marker payload carries
    // `.text` (paragraph_text_plain) from the API.
    function paragraphMarkers(num) {
        if (!current || !current.markers) return [];
        var out = [];
        for (var i = 0; i < current.markers.length; i++) {
            if (current.markers[i].paragraph_number === num) out.push(current.markers[i]);
        }
        return out;
    }

    // Normalize text for fuzzy span matching. The PDF text-layer and
    // the DB-stored paragraph_text_plain disagree on a lot of details:
    // the layer inserts spaces around parens / before half-rings /
    // between bold and italic runs, splits "kanaʿ" into "kana ʿ", etc.
    // Rather than chase every variant, strip ALL non-alphanumeric chars
    // and lowercase — the remaining alpha+digit stream is identical
    // across both sources, so substring matching is reliable.
    function norm(s) {
        return String(s || '').toLowerCase().replace(/[^a-z0-9]/g, '');
    }

    // Find all .text-layer spans on the visible page whose concatenated
    // text falls inside the current paragraph's text. We compute a
    // normalized "page string" by concatenating all span texts, mark
    // each span with [startOffset, endOffset) within that string, then
    // locate the paragraph text (normalized) inside it. Any span whose
    // [start, end) range overlaps the paragraph's [hit, hit+plen) is
    // tagged with .tts-current.
    var highlightRetryTimer = null;

    // Run the match-and-mark logic against a single page's text-layer.
    // Returns true on a match (so the caller knows whether to retry).
    function markSpansOnLayer(layer, paraNorm) {
        var spans = layer.children;
        if (!spans.length) return false;

        // Build a single normalized string for the page + index of each
        // span's [start, end) into that string. Since norm() strips all
        // non-alphanumerics (including whitespace), we concatenate span
        // contributions directly with no separator. Spans whose normed
        // text is empty (punctuation-only, like "(" or "ʿ") get a
        // zero-width range at the current position so they can still
        // be marked when they sit inside the matched paragraph run.
        var pageStr = '';
        var ranges = []; // [start, end, spanEl]
        for (var i = 0; i < spans.length; i++) {
            var sEl = spans[i];
            var sText = norm(sEl.dataset.text || sEl.textContent || '');
            var start = pageStr.length;
            pageStr += sText;
            ranges.push([start, pageStr.length, sEl]);
        }

        // Locate the paragraph in the page string. Try a full match
        // first; if interior glyphs differ enough to fail it, anchor
        // with the longest prefix AND the longest suffix and mark
        // everything between them. For paragraphs that straddle a page
        // break (typical in spread mode), prefix-only matches on the
        // first page and suffix-only matches on the second.
        var paraLen = paraNorm.length;
        var hitStart = -1, hitEnd = -1;
        var full = pageStr.indexOf(paraNorm);
        if (full >= 0) { hitStart = full; hitEnd = full + paraLen; }
        else {
            var prefStart = -1, prefEnd = -1;
            for (var n = Math.min(paraLen, 300); n >= 20; n = Math.floor(n * 0.8)) {
                var p = pageStr.indexOf(paraNorm.substring(0, n));
                if (p >= 0) { prefStart = p; prefEnd = p + n; break; }
            }
            var suffStart = -1, suffEnd = -1;
            for (var n2 = Math.min(paraLen, 300); n2 >= 20; n2 = Math.floor(n2 * 0.8)) {
                var sp = pageStr.indexOf(paraNorm.substring(paraLen - n2));
                if (sp >= 0) { suffStart = sp; suffEnd = sp + n2; break; }
            }
            if (prefStart >= 0 && suffEnd > prefStart) { hitStart = prefStart; hitEnd = suffEnd; }
            else if (prefStart >= 0)                   { hitStart = prefStart; hitEnd = prefEnd; }
            else if (suffStart >= 0)                   { hitStart = suffStart; hitEnd = suffEnd; }
        }
        if (hitStart < 0) return false;

        var marked = 0;
        for (var r = 0; r < ranges.length; r++) {
            var rs = ranges[r][0], re = ranges[r][1], el = ranges[r][2];
            var inside = (rs < re)
                ? (rs < hitEnd && re > hitStart)
                : (rs > hitStart && rs < hitEnd);
            if (inside) { el.classList.add('tts-current'); marked++; }
        }
        return marked > 0;
    }

    function applyHighlight(paragraphNum, retriesLeft) {
        if (retriesLeft == null) retriesLeft = 12;   // ~12 × 100ms ≈ 1.2s
        if (highlightRetryTimer) { clearTimeout(highlightRetryTimer); highlightRetryTimer = null; }
        // Clear previous highlights (cheap — small DOM).
        document.querySelectorAll('.text-layer span.tts-current')
            .forEach(function (el) { el.classList.remove('tts-current'); });
        if (paragraphNum == null || paragraphNum < 0) { console.log('[tts hl] bail: paragraphNum', paragraphNum); return; }
        if (!cfg.getCurrentPage) { console.log('[tts hl] bail: no getCurrentPage'); return; }
        var page = cfg.getCurrentPage();
        if (!page) { console.log('[tts hl] bail: page=0'); return; }

        // Find the paragraph_text_plain to match against (carried on
        // every marker for this paragraph; pick the first non-empty).
        var markers = paragraphMarkers(paragraphNum);
        if (!markers.length) { console.log('[tts hl] bail: no markers for paragraph', paragraphNum); return; }
        var paragraphText = '';
        for (var k = 0; k < markers.length; k++) {
            if (markers[k].text) { paragraphText = markers[k].text; break; }
        }
        if (!paragraphText) { console.log('[tts hl] bail: no text on any marker for paragraph', paragraphNum); return; }
        var paraNorm = norm(paragraphText);
        if (paraNorm.length < 4) { console.log('[tts hl] bail: paraNorm too short', paraNorm.length); return; }

        // Pick the relevant pages from the paragraph's own markers — one
        // per paragraph_page the paragraph appears on. This is the
        // authoritative answer: a paragraph that straddles pages 7 and 8
        // has markers for both, regardless of what spread is on screen.
        // Filtering by markers (rather than "every layer in #book") is
        // essential after a page turn: PageFlip keeps the previous
        // spread's .pg elements in the DOM with their text-layer still
        // populated, so without this filter we'd happily match against
        // stale content from the spread we just left.
        var pages = [];
        var seen = {};
        for (var pi = 0; pi < markers.length; pi++) {
            var pg = markers[pi].paragraph_page;
            if (pg && !seen[pg]) { seen[pg] = true; pages.push(pg); }
        }
        if (!pages.length) pages = [page]; // fallback — match on the visible page

        var isCarousel = document.body.classList.contains('mode-carousel');
        var layers = [];
        var missingLayer = false;   // page-element exists but no text yet
        for (var pp = 0; pp < pages.length; pp++) {
            var pgNum = pages[pp];
            var pgEl = isCarousel
                ? (document.querySelector('#carousel .cpg[data-page="' + pgNum + '"]'))
                : (document.querySelector('#book .pg[data-page="' + pgNum + '"]'));
            if (!pgEl) continue;
            var L = pgEl.querySelector('.text-layer');
            if (!L || !L.children.length) { missingLayer = true; continue; }
            layers.push(L);
        }

        if (!layers.length || missingLayer) {
            // Either no page element rendered yet, or the page is on
            // screen but its text-layer hasn't been fetched yet. Retry —
            // text-layers are fetched async by the viewer right after a
            // page turn settles.
            console.log('[tts hl] layers not ready (have=', layers.length,
                        'missing=', missingLayer,
                        ' retriesLeft=', retriesLeft, ') carousel=', isCarousel);
            if (retriesLeft > 0) {
                highlightRetryTimer = setTimeout(function () { applyHighlight(paragraphNum, retriesLeft - 1); }, 100);
                // If we already have one layer ready, mark its spans now
                // so the user sees something while we wait for the other.
                if (layers.length) {
                    for (var li0 = 0; li0 < layers.length; li0++) {
                        markSpansOnLayer(layers[li0], paraNorm);
                    }
                }
                return;
            }
        }

        var anyMatch = false;
        for (var li = 0; li < layers.length; li++) {
            if (markSpansOnLayer(layers[li], paraNorm)) anyMatch = true;
        }
        if (!anyMatch) {
            console.log('[tts hl] NO MATCH across', layers.length, 'layers (pages=', pages.join(','), ')');
        }
    }

    function clearHighlight() {
        document.querySelectorAll('.text-layer span.tts-current')
            .forEach(function (el) { el.classList.remove('tts-current'); });
        currentParagraphNumber = -1;
    }

    // ── Fetching ───────────────────────────────────────────────────────
    // Init fires with cfg.getCurrentPage()==1 (carouselCenter default
    // before the deep-link nav lands), so we send a fetch for page=1
    // that comes back "no chapter" even though the user will end up on,
    // say, page 26 a moment later. notifyPageChange(26) fires a second
    // fetch — but whichever resolves LAST wins, so we sometimes saw
    // the page-1 "unavailable" overwrite the page-26 "available". A
    // monotonic seq stamp on each fetch fixes that: stale responses
    // are dropped on arrival.
    function fetchForPage(page1, opts) {
        opts = opts || {};
        if (!cfg.bookCode || !page1) return;
        // Skip redundant fetches when the same chapter still applies.
        if (current && current.chapter_key && !opts.force && current.markers && current.markers.length) {
            var minP = current.markers[0].paragraph_page;
            var maxP = current.markers[current.markers.length - 1].paragraph_page;
            if (page1 >= minP && page1 <= maxP) return;
        }
        var seq = ++fetchSeq;
        var url = '/api/tts-audio.php?book_code=' + encodeURIComponent(cfg.bookCode)
                + '&page=' + page1;
        console.log('[tts] fetchForPage', page1, 'seq=', seq);
        fetch(url).then(function (r) { return r.json(); })
            .then(function (data) {
                console.log('[tts] fetch resp seq=', seq, 'fetchSeq=', fetchSeq, 'available=', data && data.available);
                if (seq !== fetchSeq) return;   // a newer fetch superseded this
                current = data;
                renderButton();
                if (opts.afterLoad) opts.afterLoad();
            })
            .catch(function () {
                if (seq !== fetchSeq) return;
                current = { available: false };
                renderButton();
            });
    }

    function fetchByChapterKey(chapterKey, afterLoad) {
        if (!cfg.bookCode || !chapterKey) return;
        var seq = ++fetchSeq;
        fetch('/api/tts-audio.php?book_code=' + encodeURIComponent(cfg.bookCode)
            + '&chapter_key=' + chapterKey)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (seq !== fetchSeq) return;
                current = data;
                renderButton();
                if (afterLoad) afterLoad();
            })
            .catch(function () {});
    }

    // ── Playback control ───────────────────────────────────────────────
    function onClick() {
        if (chapterPauseTimer) {
            // Cancel the inter-chapter pause and stop playback.
            clearTimeout(chapterPauseTimer);
            chapterPauseTimer = null;
            renderButton();
            return;
        }
        if (!current || !current.available) return;
        if (isPlaying) {
            audio.pause();
            return;
        }
        startPlayback();
    }

    function startPlayback() {
        ensureAudio();
        if (!current || !current.available) return;
        var page = cfg.getCurrentPage ? cfg.getCurrentPage() : 1;
        var marker = markerForPage(current.markers, page);
        var startMs = marker ? marker.offset_ms : 0;
        // Re-source the audio if we just switched chapters.
        if (loadedChapterKey !== current.chapter_key) {
            audio.src = current.audio_url;
            loadedChapterKey = current.chapter_key;
        }
        try { audio.currentTime = startMs / 1000; } catch (e) {}
        var p = audio.play();
        if (p && p.catch) p.catch(function () {});
    }

    function onTimeUpdate() {
        if (!current || !current.markers || !cfg.gotoPage) return;
        // A timeupdate may fire between audio.pause() and the audio element's
        // paused-state propagation; if the user has navigated off the chapter,
        // notifyPageChange has paused us — don't fight back with cfg.gotoPage.
        if (audio && audio.paused) return;
        var ms = audio.currentTime * 1000;
        var nowPage = pageAtMs(current.markers, ms);
        if (!nowPage) return;
        var cur = cfg.getCurrentPage ? cfg.getCurrentPage() : nowPage;
        if (nowPage !== cur) {
            suppressPageSync = true;
            try { cfg.gotoPage(nowPage); } catch (e) {}
            // Page-change events from goto will re-trigger our refresh;
            // suppress the chapter-refetch path for one tick.
            setTimeout(function () { suppressPageSync = false; }, 0);
        }
        // Read-along: refresh the highlight when the paragraph_number
        // changes. Same-paragraph timeupdates skip the DOM work.
        var m = markerAtMs(current.markers, ms);
        if (m && m.paragraph_number !== currentParagraphNumber) {
            currentParagraphNumber = m.paragraph_number;
            applyHighlight(currentParagraphNumber);
        }
    }

    function onEnded() {
        // Chapter MP3 ran out — schedule the inter-chapter pause then
        // load+play the next chapter's audio if it's available.
        isPlaying = false;
        if (!current) { renderButton(); return; }
        var nextKey = current.next_chapter_key;
        var nextOk  = current.next_chapter_available;
        var pauseMs = current.chapter_pause_ms | 0;
        if (!nextKey || !nextOk) { renderButton(); return; }
        renderButton();
        chapterPauseTimer = setTimeout(function () {
            chapterPauseTimer = null;
            fetchByChapterKey(nextKey, function () {
                if (!current || !current.available) { renderButton(); return; }
                // Jump the flipbook to the new chapter's first page so the
                // user sees the right pages while listening.
                if (current.markers && current.markers.length && cfg.gotoPage) {
                    suppressPageSync = true;
                    try { cfg.gotoPage(current.markers[0].paragraph_page); } catch (e) {}
                    setTimeout(function () { suppressPageSync = false; }, 0);
                }
                startPlayback();
            });
        }, Math.max(0, pauseMs));
    }

    // ── Public API ─────────────────────────────────────────────────────
    // At init time cfg.getCurrentPage() returns 1 (carouselCenter default)
    // because the deep-link nav hasn't applied yet — so trusting it leads
    // to a wasted fetch for page=1 (which has no chapter, leaving the
    // button disabled until notifyPageChange fires). Read the URL hash
    // directly to find the intended landing page so the very first fetch
    // already targets the right chapter.
    function readInitialPageFromHash() {
        try {
            var hash = (location.hash || '').replace(/^#/, '');
            if (!hash) return 0;
            var p = new URLSearchParams(hash).get('page');
            var n = p ? parseInt(p, 10) : 0;
            return (n > 0) ? n : 0;
        } catch (e) { return 0; }
    }

    BM.init = function (c) {
        cfg = c || {};
        injectStyles();
        ensureButton();
        ensureProgress();
        ensureAudio();
        var fromHash = readInitialPageFromHash();
        var fromCfg  = cfg.getCurrentPage ? cfg.getCurrentPage() : 1;
        var p = fromHash || fromCfg;
        lastPage = p;
        console.log('[tts] init page=', p, 'hash=', fromHash, 'cfg=', fromCfg);
        fetchForPage(p);
        observeModeChange();
    };

    // Watch <body> for the mode-carousel ↔ (no class = spread) toggle so
    // we can re-apply the highlight against the newly-active tree. The
    // viewer toggles the class on the user's view-mode button; we don't
    // need to plumb a hook into it. Body class is the source of truth
    // applyHighlight already reads, so just nudging it after the switch
    // is enough.
    var modeObserver = null;
    function observeModeChange() {
        if (modeObserver) return;
        var lastMode = document.body.classList.contains('mode-carousel') ? 'carousel' : 'spread';
        modeObserver = new MutationObserver(function () {
            var nowMode = document.body.classList.contains('mode-carousel') ? 'carousel' : 'spread';
            if (nowMode === lastMode) return;
            lastMode = nowMode;
            syncProgressGeometry();
            if (currentParagraphNumber < 0) return;
            // applyHighlight clears existing .tts-current marks across
            // both trees at its top, so we don't call clearHighlight
            // separately (that would zero currentParagraphNumber too).
            // Text-layers on the newly-active tree are populated async;
            // the retry budget inside applyHighlight handles the wait.
            setTimeout(function () { applyHighlight(currentParagraphNumber); }, 80);
        });
        modeObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }

    // Called from flipbook-viewer.js whenever the visible page changes.
    BM.notifyPageChange = function (page1) {
        if (!cfg) return;
        // Active page rect just moved (mode switch, zoom, navigation) —
        // realign the seek bar's width + horizontal position.
        syncProgressGeometry();
        console.log('[tts] notifyPageChange', page1, 'lastPage=', lastPage, 'suppress=', suppressPageSync);
        // When OUR auto-turn drives the page change, the text-layer on
        // the new page rebuilds asynchronously; re-apply the highlight
        // for the still-current paragraph once that settles.
        if (suppressPageSync) {
            setTimeout(function () {
                if (currentParagraphNumber >= 0) applyHighlight(currentParagraphNumber);
            }, 80);
            return;
        }
        if (page1 === lastPage) return;
        lastPage = page1;
        // If the new page is outside the current chapter's range, refetch.
        if (current && current.markers && current.markers.length) {
            var minP = current.markers[0].paragraph_page;
            var maxP = current.markers[current.markers.length - 1].paragraph_page;
            if (page1 >= minP && page1 <= maxP) {
                // Same chapter — no fetch needed; just update button title
                // metadata stays the same. If the user manually turned the
                // page while audio was playing, jump the audio to track.
                if (isPlaying) {
                    var m = markerForPage(current.markers, page1);
                    if (m) { try { audio.currentTime = m.offset_ms / 1000; } catch (e) {} }
                }
                // Re-apply the highlight against whatever's now visible.
                // If the paragraph being narrated spans the page we just
                // left and the one we just landed on, paragraph_number
                // doesn't change so onTimeUpdate won't trigger a re-match
                // on its own — and the user would see no highlight on
                // the new page until the *next* paragraph begins.
                if (currentParagraphNumber >= 0) {
                    setTimeout(function () { applyHighlight(currentParagraphNumber); }, 80);
                }
                return;
            }
        }
        // Different chapter — pause audio (we don't auto-cross chapters on
        // manual page turn; only on natural end-of-audio) and refetch. Clear
        // `current` synchronously so any racing timeupdate (between pause()
        // and the audio element actually stopping) doesn't snap the page
        // back via cfg.gotoPage. Also cancel any pending inter-chapter
        // pause timer so audio doesn't resume after the user has left.
        if (audio && !audio.paused) try { audio.pause(); } catch (e) {}
        if (chapterPauseTimer) { clearTimeout(chapterPauseTimer); chapterPauseTimer = null; }
        current = null;
        currentParagraphNumber = -1;
        clearHighlight();
        renderButton();
        fetchForPage(page1);
    };
})();
