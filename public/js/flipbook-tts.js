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

    function ensureAudio() {
        if (audio) return;
        audio = document.createElement('audio');
        audio.preload = 'metadata';
        audio.addEventListener('timeupdate', onTimeUpdate);
        audio.addEventListener('ended', onEnded);
        audio.addEventListener('playing', function () { isPlaying = true; renderButton(); });
        audio.addEventListener('pause',   function () { isPlaying = false; renderButton(); clearHighlight(); });
        document.body.appendChild(audio);
    }

    // ── State + render ─────────────────────────────────────────────────
    function renderButton() {
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

        // Find the marker for this paragraph_number on the visible page
        // first; if none, fall back to any marker for that paragraph
        // (its text is the same — paragraph_text_plain is whole-para).
        var markers = paragraphMarkers(paragraphNum);
        if (!markers.length) { console.log('[tts hl] bail: no markers for paragraph', paragraphNum); return; }
        var paragraphText = '';
        for (var k = 0; k < markers.length; k++) {
            if (markers[k].text) { paragraphText = markers[k].text; break; }
        }
        if (!paragraphText) { console.log('[tts hl] bail: no text on any marker for paragraph', paragraphNum); return; }
        var paraNorm = norm(paragraphText);
        if (paraNorm.length < 4) { console.log('[tts hl] bail: paraNorm too short', paraNorm.length); return; }

        // Grab the text-layer of the currently visible flipbook page.
        // In carousel mode the visible slot is .cpg.center; in spread
        // mode it's any .pg[data-page=N] that's currently displayed.
        // Both #book .pg and #carousel .cpg exist simultaneously; the inactive
        // tree's text-layer is never populated. Gate on the active mode so we
        // pick the element whose text-layer the viewer is actually filling.
        var isCarousel = document.body.classList.contains('mode-carousel');
        var pgEl = isCarousel
            ? (document.querySelector('#carousel .cpg.center[data-page="' + page + '"]')
               || document.querySelector('#carousel .cpg[data-page="' + page + '"]'))
            : document.querySelector('#book .pg[data-page="' + page + '"]');
        if (!pgEl) {
            console.log('[tts hl] no pgEl for page', page, '(retriesLeft=', retriesLeft, ') carousel=', isCarousel);
            // Page DOM not ready yet — try again shortly.
            if (retriesLeft > 0) {
                highlightRetryTimer = setTimeout(function () { applyHighlight(paragraphNum, retriesLeft - 1); }, 100);
            }
            return;
        }
        var layer = pgEl.querySelector('.text-layer');
        if (!layer || !layer.children.length) {
            console.log('[tts hl] empty layer for page', page, '(retriesLeft=', retriesLeft, ') carousel=', isCarousel);
            // Text layer is fetched async (ensureTextLayer / ensureCarouselText).
            // Retry until it's populated or we give up.
            if (retriesLeft > 0) {
                highlightRetryTimer = setTimeout(function () { applyHighlight(paragraphNum, retriesLeft - 1); }, 100);
            }
            return;
        }
        var spans = layer.children;
        console.log('[tts hl] paragraph=', paragraphNum, 'page=', page, 'spans=', spans.length, 'paraLen=', paraNorm.length);

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
        // everything between them. That way a paragraph with multiple
        // bold/italic sections still highlights end-to-end even when
        // only the head and tail align cleanly.
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
        if (hitStart < 0) {
            console.log('[tts hl] NO MATCH. pageNorm sample:', pageStr.substring(0, 200), 'paraNorm sample:', paraNorm.substring(0, 200));
            return;
        }
        console.log('[tts hl] MATCH hitStart=', hitStart, 'hitEnd=', hitEnd, 'pageLen=', pageStr.length);
        // Mark every span whose range falls inside [hitStart, hitEnd).
        // Zero-width spans (punctuation that normed to nothing) count
        // when their position sits within the match.
        var marked = 0;
        for (var r = 0; r < ranges.length; r++) {
            var rs = ranges[r][0], re = ranges[r][1], el = ranges[r][2];
            var inside = (rs < re)
                ? (rs < hitEnd && re > hitStart)
                : (rs > hitStart && rs < hitEnd);
            if (inside) { el.classList.add('tts-current'); marked++; }
        }
        console.log('[tts hl] marked', marked, 'spans');
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
        ensureAudio();
        var fromHash = readInitialPageFromHash();
        var fromCfg  = cfg.getCurrentPage ? cfg.getCurrentPage() : 1;
        var p = fromHash || fromCfg;
        lastPage = p;
        console.log('[tts] init page=', p, 'hash=', fromHash, 'cfg=', fromCfg);
        fetchForPage(p);
    };

    // Called from flipbook-viewer.js whenever the visible page changes.
    BM.notifyPageChange = function (page1) {
        if (!cfg) return;
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
                return;
            }
        }
        // Different chapter — pause audio (we don't auto-cross chapters on
        // manual page turn; only on natural end-of-audio) and refetch.
        if (audio && !audio.paused) try { audio.pause(); } catch (e) {}
        fetchForPage(page1);
    };
})();
