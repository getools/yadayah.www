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
    var fetchingForKey = null;
    var loadedChapterKey = null;
    var isPlaying = false;
    var chapterPauseTimer = null;
    var suppressPageSync = false;  // set true when WE drive a goto from a marker
    var lastPage = 0;

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
            '              bottom: calc(var(--nav, 56px) + 8px); z-index: 12;',
            '              width: 48px; height: 48px; border-radius: 50%;',
            '              background: #1f3550; color: #cfe1ff; border: 1px solid #2a4d70;',
            '              display: flex; align-items: center; justify-content: center;',
            '              cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,0.35);',
            '              transition: opacity 0.2s, transform 0.1s, background 0.15s; }',
            '.fb-tts-btn:hover:not(.is-disabled) { background: #28456a; }',
            '.fb-tts-btn:active:not(.is-disabled) { transform: translateX(-50%) scale(0.95); }',
            '.fb-tts-btn.is-disabled { opacity: 0.25; cursor: not-allowed; }',
            '.fb-tts-btn svg { width: 22px; height: 22px; }',
            // Brief amber tint when waiting between chapters.
            '.fb-tts-btn.is-pausing { background: #4d3a1f; border-color: #6d5a3a; color: #f7d77a; }',
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
        audio.addEventListener('pause',   function () { isPlaying = false; renderButton(); });
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

    // ── Fetching ───────────────────────────────────────────────────────
    function fetchForPage(page1, opts) {
        opts = opts || {};
        if (!cfg.bookCode || !page1) return;
        // Skip redundant fetches when the same chapter still applies.
        if (current && current.chapter_key && !opts.force) {
            var stillSameChapter = !!markerForPage(current.markers, page1);
            // markerForPage always returns something when markers exist,
            // so test more precisely: this chapter still covers page1 if
            // page1 is within [minPage, maxPage].
            if (current.markers && current.markers.length) {
                var minP = current.markers[0].paragraph_page;
                var maxP = current.markers[current.markers.length - 1].paragraph_page;
                if (page1 >= minP && page1 <= maxP) return;
            }
        }
        if (fetchingForKey === page1) return;
        fetchingForKey = page1;
        var url = '/api/tts-audio.php?book_code=' + encodeURIComponent(cfg.bookCode)
                + '&page=' + page1;
        fetch(url).then(function (r) { return r.json(); })
            .then(function (data) {
                fetchingForKey = null;
                current = data;
                renderButton();
                if (opts.afterLoad) opts.afterLoad();
            })
            .catch(function () {
                fetchingForKey = null;
                current = { available: false };
                renderButton();
            });
    }

    function fetchByChapterKey(chapterKey, afterLoad) {
        if (!cfg.bookCode || !chapterKey) return;
        fetch('/api/tts-audio.php?book_code=' + encodeURIComponent(cfg.bookCode)
            + '&chapter_key=' + chapterKey)
            .then(function (r) { return r.json(); })
            .then(function (data) {
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
    BM.init = function (c) {
        cfg = c || {};
        injectStyles();
        ensureButton();
        ensureAudio();
        var p = cfg.getCurrentPage ? cfg.getCurrentPage() : 1;
        lastPage = p;
        fetchForPage(p);
    };

    // Called from flipbook-viewer.js whenever the visible page changes.
    BM.notifyPageChange = function (page1) {
        if (!cfg) return;
        if (suppressPageSync) return;          // our own auto-turn — already in-chapter
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
