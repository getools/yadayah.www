/* Site-wide search bar (Books · Video · Site) injected below the header on
 * every page that loads this script. Lifted from
 * /search-prototype.html (the previously-built prototype the operator
 * approved as the design); the prototype was a standalone page, this
 * version self-injects into any host page that has a <header>.
 *
 * Scopes:
 *   Site  → parallel-fetch top-N from books AND transcripts, mixed view
 *   Books → /api/search.php (paginated)
 *   Video → /api/search-transcripts.php (paginated)
 *
 * Each Books hit links straight to /<volume_code>/#page=<N> so search
 * results open the new flipbook viewer on the matched page.
 */
(function () {
    'use strict';
    if (window.__siteSearchLoaded) return;
    window.__siteSearchLoaded = true;

    // ── State ───────────────────────────────────────────────────────────
    var scope = 'site';        // 'site' | 'books' | 'video'  — Site is default
    var currentPage = 1;
    var booksFilters = { series: [], volumes: [] };
    var videoFilters = { groups: [], categories: [] };
    var SITE_SECTION_LIMIT = 10;

    // ── CSS (one-shot inject; lifted verbatim from prototype) ───────────
    function injectStyle() {
        if (document.getElementById('site-search-style')) return;
        var st = document.createElement('style');
        st.id = 'site-search-style';
        st.textContent = [
            // Container is a band that sits below the header. Background
            // colour follows the page-nav config when set; otherwise a
            // cream tint (#fdf6df) matching the design screenshot.
            // The band can be given an explicit min-height by the admin
            // (page_heading_search_height). When that height exceeds what
            // the content needs, the row would otherwise sit at the top
            // and leave dead space below. Make the band a flex column with
            // centered content so any extra min-height pads above and
            // below equally instead of all at the bottom. Padding kept
            // tight (6px top/bottom) so the band wraps the row snugly
            // when no min-height is configured.
            '.site-search-band { background: var(--search-band-bg, #fdf6df); padding: 6px 0;',
            '                    display: flex; flex-direction: column; justify-content: center; }',
            '.site-search-container { max-width: 1024px; margin: 0 auto; padding: 0 20px; width: 100%; box-sizing: border-box; }',
            // The injected <form> ships with default browser margin-block;
            // zero it so it doesn't add invisible vertical space inside
            // the band on top of our padding.
            '.site-search-band form { margin: 0; }',

            // Row-bottom margin only matters when row-two is visible; when
            // row-two is hidden (scope=site) we don't want row-one's
            // margin-bottom showing up as trailing whitespace under the
            // input. The :has(+ .row-two:not([hidden])) rule keeps the
            // gap when both rows are present and zeroes it otherwise.
            '.ss-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 0; }',
            '.ss-row:has(+ .ss-row.row-two:not([hidden])) { margin-bottom: 8px; }',
            '.ss-row.row-two:not([hidden]) { margin-bottom: 0; }',
            '.ss-row input[type="text"] {',
            '  flex: 1; min-width: 200px; padding: 9px 14px; font-size: 1rem;',
            '  border: 2px solid #ccc; border-radius: 6px; outline: none;',
            '  transition: border-color 0.2s; background: #fff; color: #222;',
            '}',
            '.ss-row input[type="text"]:focus { border-color: #31345A; }',

            '.ss-btn {',
            '  padding: 9px 22px; background-color: #31345A; color: #fff; border: none;',
            '  cursor: pointer; border-radius: 6px; font-size: 0.95rem; font-weight: 600;',
            '  letter-spacing: 0.3px; transition: background-color 0.2s, box-shadow 0.2s;',
            '  box-shadow: 0 2px 4px rgba(0,0,0,0.15);',
            '}',
            '.ss-btn:hover { background-color: #4a4e7a; box-shadow: 0 3px 8px rgba(0,0,0,0.2); }',

            '.ss-filter-group { display: flex; align-items: center; gap: 6px; }',
            '.ss-filter-group label { font-weight: 600; font-size: 0.875rem; color: #555; white-space: nowrap; }',
            '.ss-filter-group select {',
            '  padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.875rem;',
            '  background: #fff; color: #222;',
            '}',

            // Scope picker
            '.ss-scope-picker {',
            '  position: relative; display: inline-flex; align-items: stretch;',
            '  font-size: 0.95rem; user-select: none; flex: 0 0 auto;',
            '}',
            '.ss-scope-picker .ss-scope-current {',
            '  display: inline-flex; align-items: center; gap: 8px;',
            '  padding: 9px 14px; background: #31345A; color: #fff; border: 0; cursor: pointer;',
            '  border-radius: 6px; font: inherit; font-weight: 600; line-height: 1; min-width: 116px;',
            '  transition: background-color 0.15s;',
            '}',
            '.ss-scope-picker .ss-scope-current:hover { background: #4a4e7a; }',
            '.ss-scope-picker .ss-scope-current .icon { width: 18px; height: 18px; flex: 0 0 18px; }',
            '.ss-scope-picker .ss-scope-current .label { flex: 1; text-align: left; }',
            '.ss-scope-picker .ss-scope-current .chevron { width: 12px; height: 12px; flex: 0 0 12px; opacity: 0.85; transition: transform 0.15s; }',
            '.ss-scope-picker.open .ss-scope-current .chevron { transform: rotate(180deg); }',

            '.ss-scope-picker .ss-scope-menu {',
            '  position: absolute; top: calc(100% + 4px); left: 0; z-index: 50;',
            '  background: #fff; border: 1px solid #d0d4dd; border-radius: 6px;',
            '  box-shadow: 0 6px 24px rgba(0,0,0,0.12); min-width: 100%; padding: 4px; display: none;',
            '}',
            '.ss-scope-picker.open .ss-scope-menu { display: block; }',
            '.ss-scope-picker .ss-scope-option {',
            '  display: flex; align-items: center; gap: 10px;',
            '  padding: 8px 14px; border-radius: 4px; cursor: pointer;',
            '  font-weight: 500; color: #333; white-space: nowrap;',
            '}',
            '.ss-scope-picker .ss-scope-option:hover { background: #eef2ff; }',
            '.ss-scope-picker .ss-scope-option .icon { width: 18px; height: 18px; flex: 0 0 18px; color: #31345A; }',
            '.ss-scope-picker .ss-scope-option[aria-selected="true"] { background: #31345A; color: #fff; }',
            '.ss-scope-picker .ss-scope-option[aria-selected="true"] .icon { color: #fff; }',

            // Collapsible filter strip
            '.ss-scope-filters {',
            '  display: flex; gap: 10px; align-items: center; flex-wrap: wrap;',
            '  overflow: hidden; max-height: 200px; opacity: 1;',
            '  transition: max-height 0.2s ease, opacity 0.15s ease, margin 0.2s ease;',
            '}',
            '.ss-scope-filters[hidden] {',
            '  display: flex !important; max-height: 0; opacity: 0; margin-bottom: 0; pointer-events: none;',
            '}',

            // Results
            '.ss-results-info {',
            '  font-size: 0.9rem; color: #555; margin-bottom: 16px; padding-bottom: 8px;',
            '  border-bottom: 1px solid #eee;',
            '  display: flex; justify-content: space-between; align-items: center;',
            '}',
            '.ss-results-info strong { color: #31345A; }',
            '.ss-clear { color: #31345A; cursor: pointer; font-size: 0.9rem; text-decoration: underline; white-space: nowrap; }',

            // Site-mode section header
            '.ss-section {',
            '  display: flex; align-items: baseline; justify-content: space-between;',
            '  gap: 8px; margin: 18px 0 10px; padding-bottom: 6px;',
            '  border-bottom: 2px solid #31345A;',
            '}',
            '.ss-section .heading { font-size: 1rem; font-weight: 700; color: #31345A; display: inline-flex; align-items: center; gap: 8px; }',
            '.ss-section .heading .icon { width: 18px; height: 18px; }',
            '.ss-section .heading .count { font-weight: 500; color: #777; font-size: 0.9rem; }',
            '.ss-section .see-all { font-size: 0.85rem; cursor: pointer; color: #2563eb; text-decoration: underline; }',
            '.ss-section .see-all:hover { color: #1d4ed8; }',

            // Result cards
            '.ss-result-card { padding: 16px; margin-bottom: 12px; border: 1px solid #e0e0e0; border-radius: 8px; background: #fff; transition: box-shadow 0.2s; }',
            '.ss-result-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.08); }',

            '.ss-result-book { display: flex; gap: 12px; align-items: flex-start; }',
            '.ss-result-book .cover { flex: 0 0 60px; width: 60px; height: 73px; background: #f5f5f5; border-radius: 3px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.18); }',
            '.ss-result-book .cover img { width: 100%; height: 100%; object-fit: cover; display: block; }',
            '.ss-result-book .body { flex: 1; min-width: 0; }',
            '.ss-result-meta { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; flex-wrap: wrap; gap: 4px; }',
            '.ss-result-meta a { text-decoration: underline; color: #2563eb; font-size: 0.9rem; }',
            '.ss-result-meta .volume { font-weight: 700; }',
            '.ss-result-meta .sep { color: #999; margin: 0 4px; }',
            '.ss-result-meta .series { font-style: italic; }',
            '.ss-result-meta .location-right { font-size: 0.8rem; text-align: right; }',
            '.ss-result-snippet { font-size: 0.9rem; color: #333; line-height: 1.6; }',
            '.ss-result-snippet mark { background: #fff3cd; padding: 1px 2px; border-radius: 2px; font-weight: 600; }',

            '.ss-result-video { display: flex; gap: 14px; align-items: stretch; }',
            '.ss-result-video .thumb { flex: 0 0 200px; max-width: 200px; aspect-ratio: 16/9; background: #000; border-radius: 6px; overflow: hidden; cursor: pointer; position: relative; }',
            '.ss-result-video .thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }',
            '.ss-result-video .thumb .play-glyph { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 32px; text-shadow: 0 1px 4px rgba(0,0,0,0.6); opacity: 0.85; pointer-events: none; }',
            '.ss-result-video .body { flex: 1; min-width: 0; }',
            '.ss-result-video .title-row { font-weight: 700; font-size: 0.95rem; color: #31345A; margin-bottom: 4px; line-height: 1.3; }',
            '.ss-result-video .title-row a { color: inherit; text-decoration: none; cursor: pointer; }',
            '.ss-result-video .title-row a:hover { text-decoration: underline; }',
            '.ss-result-video .ts-row { font-size: 0.85rem; line-height: 1.45; padding: 2px 0; color: #444; word-wrap: break-word; }',
            '.ss-result-video .ts-row .ts { display: inline-block; min-width: 56px; color: #777; font-variant-numeric: tabular-nums; font-size: 0.78rem; margin-right: 6px; }',
            '.ss-result-video .ts-row.is-hit { color: #222; }',
            '.ss-result-video .ts-row.is-hit .ts { color: #c66400; font-weight: 600; }',
            '.ss-result-video .ts-row mark { background: #fff3cd; padding: 1px 2px; border-radius: 2px; font-weight: 600; }',
            '.ss-result-video .ts-row.is-context { opacity: 0.7; }',

            '.ss-pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 24px; padding-top: 16px; border-top: 1px solid #eee; }',
            '.ss-pagination button { padding: 8px 16px; font-size: 0.875rem; background-color: #31345A; color: #fff; border: none; cursor: pointer; border-radius: 6px; font-weight: 600; }',
            '.ss-pagination button:hover { background-color: #4a4e7a; }',
            '.ss-pagination button:disabled { background: #ccc; cursor: not-allowed; }',
            '.ss-pagination .page-info { font-size: 0.875rem; color: #666; }',
            '.ss-loading { text-align: center; padding: 40px; color: #888; }',
            '.ss-no-results { text-align: center; padding: 40px; color: #888; }',

            // Video popover
            '.ss-video-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.78); z-index: 1000; display: none; align-items: center; justify-content: center; padding: 24px; }',
            '.ss-video-overlay.open { display: flex; }',
            '.ss-video-overlay .frame-wrap { position: relative; width: min(90vw, 1100px); aspect-ratio: 16/9; background: #000; border-radius: 8px; overflow: hidden; box-shadow: 0 12px 40px rgba(0,0,0,0.6); }',
            '#ss-video-frame-host { position: absolute; inset: 0; }',
            '.ss-video-overlay iframe, .ss-video-overlay video { width: 100%; height: 100%; border: 0; display: block; }',
            '.ss-video-overlay .close-btn { position: absolute; top: -36px; right: 0; background: transparent; border: 0; color: #fff; font-size: 1.6rem; cursor: pointer; line-height: 1; padding: 4px 8px; }',
            '.ss-video-overlay .seek-info { position: absolute; top: -32px; left: 0; color: #ddd; font-size: 0.85rem; }',

            '@media (max-width: 767px) {',
            '  .ss-row input[type="text"] { min-width: 100%; }',
            '  .ss-row { gap: 8px; }',
            '  .ss-scope-filters { width: 100%; }',
            '  .ss-result-video { flex-direction: column; }',
            '  .ss-result-video .thumb { flex: 0 0 auto; max-width: 100%; }',
            '}',
        ].join('\n');
        document.head.appendChild(st);
    }

    // ── DOM ─────────────────────────────────────────────────────────────
    function buildBar() {
        var initialQ = '';
        try {
            var qp = new URLSearchParams(window.location.search).get('q');
            if (qp) initialQ = qp;
        } catch (_) {}

        var band = document.createElement('div');
        band.className = 'site-search-band';
        band.id = 'site-search-band';
        band.innerHTML =
            '<div class="site-search-container">' +
            '  <form id="ss-form">' +
            '    <div class="ss-row">' +
            '      <div class="ss-scope-picker" id="ss-scope-picker" role="combobox" aria-haspopup="listbox" aria-expanded="false">' +
            '        <button type="button" class="ss-scope-current" id="ss-scope-current-btn" aria-label="Search scope">' +
            '          <span class="icon" id="ss-scope-current-icon" aria-hidden="true"></span>' +
            '          <span class="label" id="ss-scope-current-label">Site</span>' +
            '          <svg class="chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">' +
            '            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.06l3.71-3.83a.75.75 0 1 1 1.08 1.04l-4.25 4.39a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06z" clip-rule="evenodd"/>' +
            '          </svg>' +
            '        </button>' +
            '        <div class="ss-scope-menu" role="listbox" aria-label="Search scope options">' +
            '          <div class="ss-scope-option" role="option" data-scope="site"  aria-selected="true">' +
            '            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>Site' +
            '          </div>' +
            '          <div class="ss-scope-option" role="option" data-scope="books" aria-selected="false">' +
            '            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5a2 2 0 0 1 2-2h4a3 3 0 0 1 3 3v14a2 2 0 0 0-2-2H3z"/><path d="M21 5a2 2 0 0 0-2-2h-4a3 3 0 0 0-3 3v14a2 2 0 0 1 2-2h7z"/></svg>Books' +
            '          </div>' +
            '          <div class="ss-scope-option" role="option" data-scope="video" aria-selected="false">' +
            '            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10 9.5v5l5-2.5z" fill="currentColor"/></svg>Video' +
            '          </div>' +
            '        </div>' +
            '      </div>' +
            '      <input type="text" id="ss-input" placeholder="Search the site…" autocomplete="off" value="' + esc(initialQ) + '">' +
            '      <div class="ss-filter-group">' +
            '        <select id="ss-mode">' +
            '          <option value="all">All Words</option>' +
            '          <option value="phrase">Exact Phrase</option>' +
            '          <option value="any">Any Word</option>' +
            '        </select>' +
            '      </div>' +
            '      <button type="submit" class="ss-btn">Search</button>' +
            '    </div>' +
            '    <div class="ss-row row-two">' +
            '      <div class="ss-scope-filters" id="ss-filters-books" hidden>' +
            '        <div class="ss-filter-group">' +
            '          <label for="ss-filter-series">Series:</label>' +
            '          <select id="ss-filter-series"><option value="">All</option></select>' +
            '        </div>' +
            '        <div class="ss-filter-group">' +
            '          <label for="ss-filter-volume">Volume:</label>' +
            '          <select id="ss-filter-volume"><option value="">All</option></select>' +
            '        </div>' +
            '      </div>' +
            '      <div class="ss-scope-filters" id="ss-filters-video" hidden>' +
            '        <div class="ss-filter-group">' +
            '          <label for="ss-filter-group">Group:</label>' +
            '          <select id="ss-filter-group"><option value="">All</option></select>' +
            '        </div>' +
            '        <div class="ss-filter-group" id="ss-filter-category-wrap" hidden>' +
            '          <label for="ss-filter-category">Category:</label>' +
            '          <select id="ss-filter-category"><option value="">All</option></select>' +
            '        </div>' +
            '      </div>' +
            '    </div>' +
            '  </form>' +
            '  <div id="ss-results"></div>' +
            '</div>';

        var headerEl = document.querySelector('header');
        if (headerEl && headerEl.parentNode) {
            headerEl.parentNode.insertBefore(band, headerEl.nextSibling);
        } else {
            document.body.insertBefore(band, document.body.firstChild);
        }

        // Video popover at the very end of the body so it overlays everything.
        var ov = document.createElement('div');
        ov.className = 'ss-video-overlay';
        ov.id = 'ss-video-overlay';
        ov.innerHTML =
            '<div class="frame-wrap">' +
            '  <button class="close-btn" id="ss-video-close" title="Close">×</button>' +
            '  <div class="seek-info" id="ss-video-seek-info"></div>' +
            '  <div id="ss-video-frame-host"></div>' +
            '</div>';
        document.body.appendChild(ov);
    }

    // ── Helpers ─────────────────────────────────────────────────────────
    function $(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function highlightPlain(text, query, mode) {
        var safe = esc(text);
        if (!query) return safe;
        var words = (mode === 'phrase') ? [query] : query.split(/\s+/).filter(Boolean);
        words.forEach(function (w) {
            if (!w) return;
            var pattern = w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            safe = safe.replace(new RegExp('(' + pattern + ')', 'ig'), '<mark>$1</mark>');
        });
        return safe;
    }
    function fmtSeconds(total) {
        if (total == null) return '';
        var t = Math.max(0, Math.floor(total));
        var h = Math.floor(t / 3600);
        var m = Math.floor((t % 3600) / 60);
        var s = t % 60;
        return h > 0 ? h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0')
                     : m + ':' + String(s).padStart(2, '0');
    }

    // ── Filter loading ──────────────────────────────────────────────────
    function loadFilters() {
        return Promise.all([
            fetch('/api/search-filters.php').then(function (r) { return r.json(); }).catch(function () { return null; }),
            fetch('/api/search-video-filters.php').then(function (r) { return r.json(); }).catch(function () { return null; }),
        ]).then(function (out) {
            if (out[0]) booksFilters = out[0];
            if (out[1]) videoFilters = out[1];
            populateBooksFilters();
            populateVideoFilters();
        });
    }

    function populateBooksFilters() {
        var sel = $('ss-filter-series');
        if (!sel) return;
        sel.innerHTML = '<option value="">All</option>';
        (booksFilters.series || []).forEach(function (s) {
            var opt = document.createElement('option');
            opt.value = s.series_key;
            opt.textContent = s.series_label;
            sel.appendChild(opt);
        });
        onSeriesChange();
    }
    function onSeriesChange() {
        var seriesKey = $('ss-filter-series').value;
        var vol = $('ss-filter-volume');
        vol.innerHTML = '<option value="">All</option>';
        if (!seriesKey) return;
        (booksFilters.volumes || [])
            .filter(function (v) { return String(v.series_key) === String(seriesKey); })
            .forEach(function (v) {
                var opt = document.createElement('option');
                opt.value = v.volume_key;
                opt.textContent = v.volume_label;
                vol.appendChild(opt);
            });
    }
    function populateVideoFilters() {
        var sel = $('ss-filter-group');
        if (!sel) return;
        sel.innerHTML = '<option value="">All</option>';
        (videoFilters.groups || []).forEach(function (g) {
            var opt = document.createElement('option');
            opt.value = g.page_key;
            opt.textContent = g.page_title;
            sel.appendChild(opt);
        });
        onGroupChange();
    }
    function onGroupChange() {
        var pageKey = $('ss-filter-group').value;
        var wrap = $('ss-filter-category-wrap');
        var cat  = $('ss-filter-category');
        cat.innerHTML = '<option value="">All</option>';
        if (!pageKey) { wrap.hidden = true; return; }
        var matching = (videoFilters.categories || []).filter(function (c) {
            return String(c.page_key) === String(pageKey);
        });
        if (!matching.length) { wrap.hidden = true; return; }
        matching.forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = c.category_key;
            opt.textContent = c.category_title;
            cat.appendChild(opt);
        });
        wrap.hidden = false;
    }

    // ── Scope picker ────────────────────────────────────────────────────
    var SCOPE_META = {
        site:  { label: 'Site',  placeholder: 'Search the site…' },
        books: { label: 'Books', placeholder: 'Search books…' },
        video: { label: 'Video', placeholder: 'Search video transcripts…' },
    };
    function toggleScopeMenu() {
        var picker = $('ss-scope-picker');
        var open = !picker.classList.contains('open');
        picker.classList.toggle('open', open);
        picker.setAttribute('aria-expanded', String(open));
    }
    function setScope(s) {
        scope = s;
        var picker = $('ss-scope-picker');
        picker.classList.remove('open');
        picker.setAttribute('aria-expanded', 'false');
        var menuOption = picker.querySelector('[data-scope="' + s + '"]');
        $('ss-scope-current-label').textContent = SCOPE_META[s].label;
        var icon = $('ss-scope-current-icon');
        icon.innerHTML = menuOption ? menuOption.querySelector('.icon').outerHTML : '';
        Array.prototype.forEach.call(picker.querySelectorAll('[role="option"]'), function (el) {
            el.setAttribute('aria-selected', String(el.dataset.scope === s));
        });
        $('ss-filters-books').hidden = (s !== 'books');
        $('ss-filters-video').hidden = (s !== 'video');
        $('ss-input').placeholder = SCOPE_META[s].placeholder;
    }

    // ── Search ──────────────────────────────────────────────────────────
    function doSearch(page) {
        currentPage = Math.max(1, page || 1);
        var q = $('ss-input').value.trim();
        if (!q) { $('ss-results').innerHTML = ''; return; }
        if (scope === 'site')  return searchSite(q);
        if (scope === 'video') return searchVideo(q);
        return searchBooks(q);
    }
    function searchSite(q) {
        var mode = $('ss-mode').value;
        var area = $('ss-results');
        area.innerHTML = '<div class="ss-loading">Searching the site…</div>';
        var booksUrl = '/api/search.php?q=' + encodeURIComponent(q)
                     + '&mode=' + encodeURIComponent(mode)
                     + '&limit=' + SITE_SECTION_LIMIT;
        var videoUrl = '/api/search-transcripts.php?q=' + encodeURIComponent(q)
                     + '&mode=' + encodeURIComponent(mode)
                     + '&limit=' + SITE_SECTION_LIMIT;
        return Promise.all([
            fetch(booksUrl).then(function (r) { return r.json(); }).catch(function () { return null; }),
            fetch(videoUrl).then(function (r) { return r.json(); }).catch(function () { return null; }),
        ]).then(function (out) {
            renderSiteResults(q, mode, out[0], out[1]);
        });
    }
    function searchBooks(q) {
        var mode    = $('ss-mode').value;
        var series  = $('ss-filter-series').value;
        var volume  = $('ss-filter-volume').value;
        var url = '/api/search.php?q=' + encodeURIComponent(q)
                + '&mode=' + encodeURIComponent(mode)
                + (series ? '&series=' + series : '')
                + (volume ? '&volume=' + volume : '')
                + '&page=' + currentPage;
        var area = $('ss-results');
        area.innerHTML = '<div class="ss-loading">Searching books…</div>';
        return fetch(url).then(function (r) { return r.json(); }).then(function (data) {
            renderBookResults(q, mode, data);
        }).catch(function () {
            area.innerHTML = '<div class="ss-no-results">Search failed. Please try again.</div>';
        });
    }
    function searchVideo(q) {
        var mode     = $('ss-mode').value;
        var group    = $('ss-filter-group').value;
        var category = $('ss-filter-category').value;
        var url = '/api/search-transcripts.php?q=' + encodeURIComponent(q)
                + '&mode=' + encodeURIComponent(mode)
                + (group    ? '&group='    + group    : '')
                + (category ? '&category=' + category : '')
                + '&page=' + currentPage;
        var area = $('ss-results');
        area.innerHTML = '<div class="ss-loading">Searching transcripts…</div>';
        return fetch(url).then(function (r) { return r.json(); }).then(function (data) {
            renderVideoResults(q, mode, data);
        }).catch(function () {
            area.innerHTML = '<div class="ss-no-results">Search failed. Please try again.</div>';
        });
    }

    // ── Render: books ───────────────────────────────────────────────────
    function booksResultsHtml(results) {
        var html = '';
        results.forEach(function (r) {
            // Build the chapter+page location string. We escape each piece
            // individually because chapter_name can contain '&' / '<' (Hebrew
            // book names with parens, etc.).
            var locParts = [];
            if (r.chapter_number != null && r.chapter_name) {
                locParts.push('Ch ' + r.chapter_number + ' · ' + esc(r.chapter_name));
            } else if (r.chapter_number != null) {
                locParts.push('Ch ' + r.chapter_number);
            } else if (r.chapter_name) {
                locParts.push(esc(r.chapter_name));
            }
            if (r.page) locParts.push('Page ' + r.page);
            var locationText = locParts.join(' · ');
            // search.php now returns three URL fields:
            //   book_url     — always the named-path bundle for the book
            //                  (e.g. /YY-s02v03-Yada-Yahowah-Beyth-In-the-Family/)
            //   chapter_url  — book_url + #chapter=<slug> when chapter
            //                  info is present; the new flipbook viewer
            //                  honors this and seeks; older viewers
            //                  ignore unknown anchors and fall through
            //                  to the book's first page.
            //   flip_url     — backwards-compat alias of book_url;
            //                  flipbook_url (the old typo'd name this
            //                  file used to read) is also accepted so
            //                  a stale-cached client doesn't lose the
            //                  link.
            var bookHref = r.book_url || r.flip_url || r.flipbook_url || '';
            var locHref  = r.chapter_url || bookHref;
            var titleLink = bookHref
                ? '<a href="' + esc(bookHref) + '" target="_blank">' + esc(r.volume_label) + '</a>'
                : esc(r.volume_label);
            var locationHtml = locationText
                ? (locHref
                    ? '<a href="' + esc(locHref) + '" target="_blank">' + locationText + '</a>'
                    : locationText)
                : '';
            // Cover thumb: derive sNNvNN from volume_code; broken images
            // are removed so the layout doesn't show a placeholder.
            var coverHtml = '<div class="cover"></div>';
            if (r.volume_code) {
                var m = String(r.volume_code).match(/s\d{2}v\d{2}/i);
                if (m) {
                    var coverUrl = '/images/covers/YY.book_.3d.6x9-vertical-softcover-spine.00490x00600.'
                                 + m[0].toLowerCase() + '-245x300.jpg';
                    coverHtml = '<div class="cover"><img src="' + esc(coverUrl) + '" alt="" loading="lazy" onerror="this.remove()"></div>';
                }
            }
            html += '<div class="ss-result-card">'
                  + '<div class="ss-result-book">'
                  +   coverHtml
                  +   '<div class="body">'
                  +     '<div class="ss-result-meta">'
                  +       '<div><span class="volume">' + titleLink + '</span>'
                  +         '<span class="sep">·</span><span class="series">' + esc(r.series_label || '') + '</span></div>'
                  +       '<div class="location-right">' + locationHtml + '</div>'
                  +     '</div>'
                  +     '<div class="ss-result-snippet">' + (r.snippet || '') + '</div>'
                  +   '</div>'
                  + '</div>'
                  + '</div>';
        });
        return html;
    }
    function renderBookResults(q, mode, data) {
        var area = $('ss-results');
        if (!data || !data.results || data.results.length === 0) {
            area.innerHTML = '<div class="ss-no-results">No matches for <strong>' + esc(q) + '</strong> in books.</div>';
            return;
        }
        var html = '<div class="ss-results-info"><span><strong>' + data.total + '</strong> book matches for <strong>' + esc(q) + '</strong></span>'
                 + '<a class="ss-clear" id="ss-clear-link">Clear</a></div>';
        html += booksResultsHtml(data.results);
        html += renderPagination(data);
        area.innerHTML = html;
        wireClearLink();
    }

    // ── Render: video / transcripts ─────────────────────────────────────
    function videoResultsHtml(q, mode, results) {
        var html = '';
        results.forEach(function (r) {
            window.__siteSearchHits = window.__siteSearchHits || {};
            var hitId = 'sshit_' + r.transcript_key;
            window.__siteSearchHits[hitId] = r;

            var beforeRow = r.before
                ? '<div class="ts-row is-context"><span class="ts">' + esc(r.before.segment ? r.before.segment.label : '') + '</span>'
                  + highlightPlain(r.before.text || '', q, mode) + '</div>'
                : '';
            var afterRow = r.after
                ? '<div class="ts-row is-context"><span class="ts">' + esc(r.after.segment ? r.after.segment.label : '') + '</span>'
                  + highlightPlain(r.after.text || '', q, mode) + '</div>'
                : '';
            var hitTs = esc(r.segment ? r.segment.label : '');
            var thumb = r.thumbnail
                ? '<img src="' + esc(r.thumbnail) + '" alt="" loading="lazy">'
                : '<div style="background:#222;width:100%;height:100%;"></div>';

            html += '<div class="ss-result-card">'
                  + '<div class="ss-result-video">'
                  +   '<div class="thumb" data-hit="' + esc(hitId) + '">'
                  +     thumb
                  +     '<div class="play-glyph">▶</div>'
                  +   '</div>'
                  +   '<div class="body">'
                  +     '<div class="title-row"><a data-hit="' + esc(hitId) + '">' + esc(r.title || '(untitled)') + '</a></div>'
                  +     beforeRow
                  +     '<div class="ts-row is-hit"><span class="ts">' + hitTs + '</span>' + (r.snippet_html || '') + '</div>'
                  +     afterRow
                  +   '</div>'
                  + '</div>'
                  + '</div>';
        });
        return html;
    }
    function renderVideoResults(q, mode, data) {
        var area = $('ss-results');
        if (!data || !data.results || data.results.length === 0) {
            area.innerHTML = '<div class="ss-no-results">No matches for <strong>' + esc(q) + '</strong> in video transcripts.</div>';
            return;
        }
        var html = '<div class="ss-results-info"><span><strong>' + data.total + '</strong> transcript matches for <strong>' + esc(q) + '</strong></span>'
                 + '<a class="ss-clear" id="ss-clear-link">Clear</a></div>';
        html += videoResultsHtml(q, mode, data.results);
        html += renderPagination(data);
        area.innerHTML = html;
        wireClearLink();
    }

    function renderSiteResults(q, mode, books, videos) {
        var area = $('ss-results');
        var hasBooks = books && books.results && books.results.length;
        var hasVideo = videos && videos.results && videos.results.length;
        if (!hasBooks && !hasVideo) {
            area.innerHTML = '<div class="ss-no-results">No matches for <strong>' + esc(q) + '</strong>.</div>';
            return;
        }
        var html = '<div class="ss-results-info">'
                 + '<span><strong>' + (((books && books.total) || 0) + ((videos && videos.total) || 0))
                 + '</strong> total matches for <strong>' + esc(q) + '</strong></span>'
                 + '<a class="ss-clear" id="ss-clear-link">Clear</a></div>';
        if (hasBooks) {
            html += '<div class="ss-section">'
                  +   '<div class="heading">'
                  +     '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5a2 2 0 0 1 2-2h4a3 3 0 0 1 3 3v14a2 2 0 0 0-2-2H3z"/><path d="M21 5a2 2 0 0 0-2-2h-4a3 3 0 0 0-3 3v14a2 2 0 0 1 2-2h7z"/></svg>'
                  +     'Books <span class="count">· ' + books.total + ' match' + (books.total === 1 ? '' : 'es') + '</span>'
                  +   '</div>'
                  +   (books.total > books.results.length ? '<a class="see-all" data-see="books">See all in Books →</a>' : '')
                  + '</div>';
            html += booksResultsHtml(books.results);
        }
        if (hasVideo) {
            html += '<div class="ss-section">'
                  +   '<div class="heading">'
                  +     '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10 9.5v5l5-2.5z" fill="currentColor"/></svg>'
                  +     'Video <span class="count">· ' + videos.total + ' match' + (videos.total === 1 ? '' : 'es') + '</span>'
                  +   '</div>'
                  +   (videos.total > videos.results.length ? '<a class="see-all" data-see="video">See all in Video →</a>' : '')
                  + '</div>';
            html += videoResultsHtml(q, mode, videos.results);
        }
        area.innerHTML = html;
        wireClearLink();
    }
    function renderPagination(data) {
        if (data.pages <= 1) return '';
        var prev = '<button data-go="' + (data.page - 1) + '"' + (data.page <= 1 ? ' disabled' : '') + '>« Previous</button>';
        var next = '<button data-go="' + (data.page + 1) + '"' + (data.page >= data.pages ? ' disabled' : '') + '>Next »</button>';
        var info = '<span class="page-info">Page ' + data.page + ' of ' + data.pages + '</span>';
        return '<div class="ss-pagination">' + prev + info + next + '</div>';
    }
    function wireClearLink() {
        var c = $('ss-clear-link');
        if (c) c.onclick = function () { $('ss-input').value = ''; $('ss-results').innerHTML = ''; };
    }

    // ── Video popover ───────────────────────────────────────────────────
    function playHit(hitId) {
        var r = (window.__siteSearchHits || {})[hitId];
        if (!r) return;
        var host  = $('ss-video-frame-host');
        var info  = $('ss-video-seek-info');
        var url   = r.video_url || '';
        var t     = Math.max(0, parseInt(r.start_seconds || 0, 10));
        var html;
        if (/youtu\.be|youtube\.com/.test(url) || r.embed_id) {
            var ytId = r.embed_id || extractYouTubeId(url);
            if (ytId) {
                html = '<iframe src="https://www.youtube.com/embed/' + encodeURIComponent(ytId)
                     + '?start=' + t + '&autoplay=1&rel=0" allow="autoplay; fullscreen"></iframe>';
            }
        } else if (/rumble\.com/.test(url)) {
            var rid = extractRumbleEmbedId(url);
            html = rid
                ? '<iframe src="https://rumble.com/embed/' + encodeURIComponent(rid)
                  + '/?pub=4&start=' + t + '&autoplay=1" allow="autoplay; fullscreen"></iframe>'
                : '<iframe src="' + esc(url) + '" allow="autoplay; fullscreen"></iframe>';
        }
        if (!html) {
            html = '<video src="' + esc(url) + '#t=' + t + '" controls autoplay></video>';
        }
        host.innerHTML = html;
        info.textContent = 'Resuming at ' + fmtSeconds(t)
            + (r.before ? ' (start of previous transcript line)' : ' (start of transcript)');
        $('ss-video-overlay').classList.add('open');
    }
    function extractYouTubeId(url) {
        if (!url) return null;
        var m = url.match(/(?:youtu\.be\/|v=|\/embed\/|\/shorts\/)([\w-]{11})/);
        return m ? m[1] : null;
    }
    function extractRumbleEmbedId(url) {
        if (!url) return null;
        var m = url.match(/\/(v[\w]+)/);
        return m ? m[1] : null;
    }
    function closeVideo() {
        $('ss-video-overlay').classList.remove('open');
        $('ss-video-frame-host').innerHTML = '';
        $('ss-video-seek-info').textContent = '';
    }

    // ── Wiring ──────────────────────────────────────────────────────────
    function wire() {
        $('ss-form').addEventListener('submit', function (e) { e.preventDefault(); doSearch(1); });
        $('ss-scope-current-btn').addEventListener('click', toggleScopeMenu);
        Array.prototype.forEach.call(document.querySelectorAll('#ss-scope-picker [data-scope]'), function (el) {
            el.addEventListener('click', function () { setScope(el.dataset.scope); });
        });
        $('ss-filter-series').addEventListener('change', onSeriesChange);
        $('ss-filter-group').addEventListener('change', onGroupChange);
        // Close scope menu on outside click.
        document.addEventListener('click', function (e) {
            var picker = $('ss-scope-picker');
            if (!picker) return;
            if (picker.classList.contains('open') && !picker.contains(e.target)) {
                picker.classList.remove('open');
                picker.setAttribute('aria-expanded', 'false');
            }
        });
        // Delegated handler for results-area: pagination buttons,
        // see-all links, video play targets — all single source so the
        // results-area HTML can be rebuilt freely without re-attaching.
        $('ss-results').addEventListener('click', function (e) {
            var go = e.target.closest && e.target.closest('[data-go]');
            if (go) { e.preventDefault(); doSearch(parseInt(go.dataset.go, 10)); return; }
            var see = e.target.closest && e.target.closest('[data-see]');
            if (see) { e.preventDefault(); setScope(see.dataset.see); doSearch(1); return; }
            var hit = e.target.closest && e.target.closest('[data-hit]');
            if (hit) { e.preventDefault(); playHit(hit.dataset.hit); return; }
        });
        $('ss-video-overlay').addEventListener('click', function (e) {
            if (e.target === $('ss-video-overlay')) closeVideo();
        });
        $('ss-video-close').addEventListener('click', closeVideo);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeVideo();
        });
    }

    function init() {
        injectStyle();
        buildBar();
        wire();
        loadFilters();
        setScope('site');
        // Auto-search if landed with ?q= (e.g., from a "View all" link).
        var initialQ = ($('ss-input').value || '').trim();
        if (initialQ) doSearch(1);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
