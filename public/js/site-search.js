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
    // Mode-picker icon SVGs. Defined once so the dropdown trigger (which
    // shows only the current mode's icon) can rebuild its content from a
    // single source when the user picks a different mode.
    var MODE_ICON_SVG = {
        all:    '<svg class="ss-mode-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">'
              +   '<line x1="6" y1="7"  x2="18" y2="7"/>'
              +   '<line x1="6" y1="12" x2="18" y2="12"/>'
              +   '<line x1="6" y1="17" x2="18" y2="17"/>'
              + '</svg>',
        // Exact Phrase: two LEFT double-quotes (““). Rendered
        // as SVG <text> so the glyphs match whatever serif font the
        // browser picks; this is more legible than custom paths and
        // visually unambiguous as opening (left) quotes.
        phrase: '<svg class="ss-mode-icon" viewBox="0 0 24 24" aria-hidden="true">'
              +   '<text x="2" y="18" font-size="22" font-weight="700" font-family="Georgia, \'Times New Roman\', serif" fill="currentColor">““</text>'
              + '</svg>',
        any:    '<svg class="ss-mode-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
              +   '<path d="M4 6l8 6-8 6"/>'
              +   '<path d="M12 6l8 6-8 6"/>'
              + '</svg>',
    };

    // ── CSS (one-shot inject; lifted verbatim from prototype) ───────────
    function injectStyle() {
        if (document.getElementById('site-search-style')) return;
        var st = document.createElement('style');
        st.id = 'site-search-style';
        st.textContent = [
            // Container is a band that sits below the header. Background
            // colour follows the page-nav config when set; otherwise a
            // cream tint (#fdf6df) matching the design screenshot.
            // The band must span the full viewport width regardless of
            // whatever padding/margin the host page applies to <body>.
            // Some pages (e.g. vlog.html) put `body { padding: 20px }`
            // for content layout, which would otherwise inset the band
            // 20px from each edge. The 100vw + 50%-shift trick breaks
            // out of that container constraint without needing per-page
            // CSS overrides.
            // Tight vertical padding (6px) so the band hugs the row
            // when no min-height is configured. Flex column + center
            // also re-centers content inside any admin-set min-height.
            // 100vw + 50%-shift breaks out of any host-page body
            // padding (e.g. vlog.html: body{padding:20px}).
            '.site-search-band { background: var(--search-band-bg, #fdf6df); padding: 6px 0;',
            '                    width: 100vw; position: relative;',
            '                    left: 50%; transform: translateX(-50%);',
            '                    display: flex; flex-direction: column; justify-content: center; }',
            // Container holds both the bar and the results. Bar is
            // constrained separately (form max-width: 400px); results
            // inherit the container's main-display width.
            '.site-search-container { max-width: 1200px; margin: 0 auto; padding: 0 20px; width: 100%; box-sizing: border-box; }',
            // Reset default <form> block-margin that browsers add — it
            // would otherwise stack inside the band as visible whitespace.
            // The bar itself is constrained to 400px and centered; the
            // results area below inherits the wider container width.
            '.site-search-band form { margin: 0 auto; max-width: 400px; }',

            // Both rows zero margin-bottom by default; the gap between
            // row-1 and row-2 only appears when row-2 actually has a
            // visible filter strip. row-2 itself never gets [hidden]
            // (only its filter children do), so the :has selector
            // checks for a non-hidden filter inside row-2.
            '.ss-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 0; }',
            '.ss-row:has(+ .ss-row.row-two .ss-scope-filters:not([hidden])) { margin-bottom: 8px; }',
            '.ss-row.row-two { margin-bottom: 0; }',
            '.ss-row input[type="text"] {',
            '  flex: 1; min-width: 200px; padding: 9px 14px; font-size: 1rem;',
            '  border: 2px solid #ccc; border-radius: 6px; outline: none;',
            '  transition: border-color 0.2s; background: #fff; color: #222;',
            '  width: auto; height: auto; margin: 0; box-sizing: border-box;',
            // Inherit the body/header font stack instead of letting the
            // browser pick its default form-control font, so the search
            // input matches the header link typography.
            '  font-family: inherit;',
            // Placeholder centered + gold (matching the magnifying glass
            // / mode picker icon color). The text the user types stays
            // left-aligned at standard input color so they can read it
            // back; only the placeholder hint gets the gold treatment.
            '  text-align: center;',
            '}',
            '.ss-row input[type="text"]:focus { border-color: #31345A; }',
            // Placeholder uses Julius Sans One (loaded once at init via
            // injectFonts), matching the heading typography on the
            // login/about pages. Color stays gold (#d4a017) so it pairs
            // visually with the magnifying-glass icon.
            '.ss-row input[type="text"]::placeholder {',
            '  color: var(--search-band-text, #d4a017); opacity: 1; text-align: center;',
            '  font-family: \'Julius Sans One\', sans-serif;',
            '  letter-spacing: 0.04em;',
            '}',
            // Once the user starts typing, fall back to left-align so
            // long queries remain readable.
            '.ss-row input[type="text"]:not(:placeholder-shown) { text-align: left; }',

            // Search submit button — gold magnifying glass on transparent
            // background. No box-shadow; the glass is the entire visual
            // affordance.
            '.ss-btn {',
            '  padding: 6px 8px; background: transparent; color: var(--search-band-text, #d4a017); border: 0;',
            '  cursor: pointer; border-radius: 6px; line-height: 1;',
            '  display: inline-flex; align-items: center; justify-content: center;',
            '  transition: color 0.15s;',
            '}',
            '.ss-btn:hover { color: #b8870e; }',
            '.ss-btn svg { width: 22px; height: 22px; stroke: currentColor; }',

            '.ss-filter-group { display: flex; align-items: center; gap: 6px; }',
            '.ss-filter-group label { font-weight: 600; font-size: 0.875rem; color: #555; white-space: nowrap; }',
            // Self-defend against host pages that style raw `select` or
            // `input[type=text]` globally — without explicit width
            // declarations here, a `select { width: 250px }` rule on
            // a host page (e.g. translations.html) would leak into the
            // injected mode dropdown and blow up its width. width:auto
            // restores natural sizing; the higher specificity of these
            // selectors lets us beat any host page's bare-element rules.
            '.ss-filter-group select {',
            '  padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.875rem;',
            '  background: #fff; color: #222; width: auto; height: auto;',
            '  margin: 0; box-sizing: border-box;',
            '}',

            // Mode picker (dropdown): icon-only trigger button on the
            // left of the search input. Click → menu of all 3 options
            // (icon + label). Picking an option closes the menu and
            // updates the trigger's icon.
            '.ss-mode-picker { position: relative; display: inline-flex; flex: 0 0 auto; }',
            // Trigger button matches the search-submit (gold magnifying
            // glass) styling: transparent background, gold icon, no
            // border. Hover darkens the gold like the search button.
            '.ss-mode-picker .ss-mode-current {',
            '  display: inline-flex; align-items: center; justify-content: center;',
            '  padding: 6px 8px; background: transparent; color: var(--search-band-text, #d4a017);',
            '  border: 0; border-radius: 6px; cursor: pointer; line-height: 1;',
            '  transition: color 0.15s;',
            '}',
            '.ss-mode-picker .ss-mode-current:hover { color: #b8870e; }',
            '.ss-mode-picker .ss-mode-current .ss-mode-icon { width: 22px; height: 22px; }',
            '.ss-mode-picker .ss-mode-menu {',
            '  position: absolute; top: calc(100% + 4px); left: 0; z-index: 50;',
            '  background: #fff; border: 1px solid #d0d4dd; border-radius: 6px;',
            '  box-shadow: 0 6px 24px rgba(0,0,0,0.12); padding: 4px;',
            '  min-width: 160px; display: none;',
            '}',
            '.ss-mode-picker.open .ss-mode-menu { display: block; }',
            '.ss-mode-picker .ss-mode-option {',
            '  display: flex; align-items: center; gap: 10px; width: 100%;',
            '  padding: 8px 12px; border: 0; border-radius: 4px;',
            '  background: transparent; color: #333; cursor: pointer;',
            '  font: inherit; font-size: 0.875rem; font-weight: 500; text-align: left;',
            '  white-space: nowrap;',
            '}',
            '.ss-mode-picker .ss-mode-option:hover { background: #eef2ff; }',
            '.ss-mode-picker .ss-mode-option .ss-mode-icon { width: 16px; height: 16px; flex: 0 0 16px; color: #31345A; }',
            '.ss-mode-picker .ss-mode-option.is-active { background: #31345A; color: #fff; }',
            '.ss-mode-picker .ss-mode-option.is-active .ss-mode-icon { color: #fff; }',

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

            // Filter strip per scope. [hidden] collapses to display:none
            // so it takes ZERO horizontal space. The earlier "animation"
            // override (display:flex !important + max-height:0) kept
            // hidden strips occupying horizontal space inside row-two,
            // which pushed the visible strip toward the middle of the
            // row when scope=video. We need the [hidden] rule to win
            // over the bare-class rule below — its `class[attr]`
            // selector has higher specificity, so display:none wins.
            '.ss-scope-filters {',
            '  display: flex; gap: 10px; align-items: center; flex-wrap: wrap;',
            '}',
            '.ss-scope-filters[hidden] { display: none; }',

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

            // Results area + result-card backgrounds. Both are
            // configurable via Admin → Site → Search; the CSS vars are
            // set on <html> by site-nav.js when those settings are
            // populated. Defaults: transparent results area (so the
            // band background shows through) and white cards.
            '#ss-results { background: var(--search-results-bg, transparent); border-radius: 8px; padding: 8px; box-sizing: border-box; }',
            // Hide the container completely when it has no content — otherwise
            // the padding + configured background show as a thin sliver before
            // any search has run (or after the user clears the input).
            '#ss-results:empty { display: none; }',
            '.ss-result-card { padding: 16px; margin-bottom: 12px; border: 1px solid #e0e0e0; border-radius: 8px; background: var(--search-result-item-bg, #fff); transition: box-shadow 0.2s; }',
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
            // .player-pane is the positioning context for the close
            // button — placing the button on the pane (not the frame)
            // lets it sit OUTSIDE the player\'s clipped rectangle, so
            // it can\'t overlap built-in controls (YouTube gear, Rumble
            // settings, etc.) in the player\'s own corners.
            '.ss-video-overlay .player-pane { position: relative; width: min(90vw, 1100px); }',
            '.ss-video-overlay .frame-wrap { position: relative; width: 100%; aspect-ratio: 16/9; background: #000; border-radius: 8px; overflow: hidden; box-shadow: 0 12px 40px rgba(0,0,0,0.6); }',
            '#ss-video-frame-host { position: absolute; inset: 0; }',
            '.ss-video-overlay iframe, .ss-video-overlay video { width: 100%; height: 100%; border: 0; display: block; }',
            // Close-X sits on top of the player; the prior -36px top offset
            // sat outside frame-wrap and got clipped by its overflow:hidden.
            // Close button sits just OUTSIDE the player\'s top-right
            // corner so it can\'t overlap the player\'s built-in
            // controls. Negative offsets push it onto the dark overlay
            // backdrop; the player-pane (its positioning parent) has
            // overflow:visible so the button isn\'t clipped.
            '.ss-video-overlay .close-btn { position: absolute; top: -32px; right: -32px; z-index: 3; background: rgba(0,0,0,0.85); border: 2px solid rgba(255,255,255,0.25); color: #fff; width: 36px; height: 36px; border-radius: 50%; font-size: 1.4rem; line-height: 1; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.4); }',
            '.ss-video-overlay .close-btn:hover { background: #000; }',
            // seek-info sits ABOVE the player rectangle (on the dark
            // backdrop) so it never obscures the video itself or any
            // of the player's built-in controls. Same approach as the
            // close button — positioned on .player-pane (which has
            // overflow:visible) with a negative top offset that pushes
            // it onto the overlay backdrop just above the frame.
            '.ss-video-overlay .seek-info { position: absolute; bottom: 100%; left: 0; margin-bottom: 8px; z-index: 2; color: #fff; font-size: 0.85rem; background: rgba(0,0,0,0.55); padding: 4px 10px; border-radius: 4px; white-space: nowrap; opacity: 1; transition: opacity 0.6s ease; pointer-events: none; }',
            '.ss-video-overlay .seek-info.fade-out { opacity: 0; }',

            // Mobile (≤767px): keep all controls on one row by letting the
            // input shrink, slimming the scope picker, tightening the
            // Search button's side padding, and swapping its label to
            // "Go" via CSS (font-size:0 hides the existing "Search" text;
            // the ::before pseudo-element supplies the shorter label).
            // Reduce the band's side padding too so the bar reaches
            // closer to the screen edges on phones.
            '@media (max-width: 767px) {',
            '  .site-search-container { padding: 0 8px; }',
            // Zero the inter-row margins on mobile. row-1 had 8px and
            // row-2 had 16px built-in margin-bottom; combined with the
            // band's own padding they were stacking into a visible gap
            // below the search fields even when row-two was empty
            // (scope=site). Drop both — band padding already provides
            // breathing room.
            //
            // flex-wrap:nowrap forces every base control to share the
            // single row by shrinking; without it the input's auto
            // basis (~200px) could push siblings to a second line on
            // narrow phones. Row-two stays wrappable because filter
            // strips can legitimately need a second line.
            '  .ss-row { gap: 6px; margin-bottom: 0; flex-wrap: nowrap; }',
            '  .ss-row.row-two { margin-bottom: 0; flex-wrap: wrap; }',
            // Input takes only spare space, never claims a natural
            // basis that could push siblings to wrap. flex-basis:0
            // means the input contributes nothing to the wrap
            // calculation and grows from there.
            '  .ss-row input[type="text"] { min-width: 0; flex: 1 1 0; padding: 8px 10px; font-size: 0.95rem; }',
            '  .ss-scope-picker .ss-scope-current {',
            '    min-width: 0; padding: 8px 10px; gap: 4px;',
            '  }',
            '  .ss-scope-picker .ss-scope-current .icon { width: 16px; height: 16px; flex: 0 0 16px; }',
            '  .ss-scope-picker .ss-scope-current .chevron { width: 10px; height: 10px; flex: 0 0 10px; }',
            // Compact the mode dropdown so the input gets more room.
            '  .ss-filter-group select { padding: 6px 8px; font-size: 0.85rem; }',
            // Mobile: search button stays a magnifying glass — no "Go"
            // text injected.
            '  .ss-btn { padding: 6px 8px; }',
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
            // Hidden select preserves the existing $(ss-mode).value reader
            // path; the visible UI is the dropdown picker on the left.
            '      <select id="ss-mode" style="display:none;">' +
            '        <option value="all" selected>All Words</option>' +
            '        <option value="phrase">Exact Phrase</option>' +
            '        <option value="any">Any Word</option>' +
            '      </select>' +
            // Mode picker (icon-only trigger; click → menu of all 3 options).
            '      <div class="ss-mode-picker" id="ss-mode-picker">' +
            '        <button type="button" class="ss-mode-current" id="ss-mode-current-btn" aria-haspopup="menu" aria-expanded="false" title="Match mode">' +
            '          <span id="ss-mode-current-icon" aria-hidden="true">' + MODE_ICON_SVG.all + '</span>' +
            '        </button>' +
            '        <div class="ss-mode-menu" role="menu">' +
            '          <button type="button" class="ss-mode-option" role="menuitem" data-mode="all">' +
            '            ' + MODE_ICON_SVG.all +
            '            <span class="ss-mode-label">All Words</span>' +
            '          </button>' +
            '          <button type="button" class="ss-mode-option" role="menuitem" data-mode="phrase">' +
            '            ' + MODE_ICON_SVG.phrase +
            '            <span class="ss-mode-label">Exact Phrase</span>' +
            '          </button>' +
            '          <button type="button" class="ss-mode-option" role="menuitem" data-mode="any">' +
            '            ' + MODE_ICON_SVG.any +
            '            <span class="ss-mode-label">Any Word</span>' +
            '          </button>' +
            '        </div>' +
            '      </div>' +
            '      <input type="text" id="ss-input" placeholder="Search" autocomplete="off" value="' + esc(initialQ) + '">' +
            '      <button type="submit" class="ss-btn" aria-label="Search" title="Search">' +
            '        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
            '          <circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/>' +
            '        </svg>' +
            '      </button>' +
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
            '<div class="player-pane">' +
            '  <button class="close-btn" id="ss-video-close" title="Close">×</button>' +
            '  <div class="seek-info" id="ss-video-seek-info"></div>' +
            '  <div class="frame-wrap">' +
            '    <div id="ss-video-frame-host"></div>' +
            '  </div>' +
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
    // Per-scope filters were removed when the search bar was simplified
    // to always parallel-fetch books + video. Stub remains for any
    // legacy callers expecting a Promise return; resolves immediately.
    function loadFilters() { return Promise.resolve(); }

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

    // ── Search ──────────────────────────────────────────────────────────
    // Search bar always queries books AND video transcripts in parallel
    // and renders a mixed view. The scope picker (Site/Books/Video) was
    // removed; this is now the only mode.
    function doSearch(page) {
        currentPage = Math.max(1, page || 1);
        var q = $('ss-input').value.trim();
        if (!q) { $('ss-results').innerHTML = ''; return; }
        return searchSite(q);
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
        // Filter selects (series/volume) were removed when the scope
        // picker went away, but the search.php API still accepts them —
        // read defensively so missing elements don't crash this call
        // path (used by the "See all in Books" link).
        var modeEl   = $('ss-mode');
        var seriesEl = $('ss-filter-series');
        var volumeEl = $('ss-filter-volume');
        var mode     = modeEl   ? modeEl.value   : 'all';
        var series   = seriesEl ? seriesEl.value : '';
        var volume   = volumeEl ? volumeEl.value : '';
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
        var modeEl     = $('ss-mode');
        var groupEl    = $('ss-filter-group');
        var categoryEl = $('ss-filter-category');
        var mode       = modeEl     ? modeEl.value     : 'all';
        var group      = groupEl    ? groupEl.value    : '';
        var category   = categoryEl ? categoryEl.value : '';
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
        info.textContent = 'Playing at ' + fmtSeconds(t)
            + (r.before ? ' (start of previous transcript line)' : ' (start of transcript)');
        // Reset visibility so a freshly opened video shows the label,
        // then auto-fade after 10s. Cancel any pending timer from a
        // previous video so the new label gets its own full window.
        info.classList.remove('fade-out');
        if (window.__ssSeekFadeTimer) clearTimeout(window.__ssSeekFadeTimer);
        window.__ssSeekFadeTimer = setTimeout(function () {
            info.classList.add('fade-out');
        }, 20000);
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
        // Mode picker: click trigger toggles menu; click an option sets
        // the mode (in the hidden <select> so $('ss-mode').value still
        // works), updates the trigger's icon, and re-runs the search if
        // there's an active query.
        var picker = $('ss-mode-picker');
        $('ss-mode-current-btn').addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !picker.classList.contains('open');
            picker.classList.toggle('open', open);
            $('ss-mode-current-btn').setAttribute('aria-expanded', String(open));
            if (open) syncModeOptionActive();
        });
        Array.prototype.forEach.call(picker.querySelectorAll('.ss-mode-option'), function (opt) {
            opt.addEventListener('click', function () {
                var mode = opt.dataset.mode;
                $('ss-mode').value = mode;
                $('ss-mode-current-icon').innerHTML = MODE_ICON_SVG[mode] || '';
                picker.classList.remove('open');
                $('ss-mode-current-btn').setAttribute('aria-expanded', 'false');
                if ($('ss-input').value.trim()) doSearch(1);
            });
        });
        // Close picker on outside click.
        document.addEventListener('click', function (e) {
            if (picker.classList.contains('open') && !picker.contains(e.target)) {
                picker.classList.remove('open');
                $('ss-mode-current-btn').setAttribute('aria-expanded', 'false');
            }
        });
        // Delegated handler for results-area: pagination buttons,
        // see-all links, video play targets — all single source so the
        // results-area HTML can be rebuilt freely without re-attaching.
        $('ss-results').addEventListener('click', function (e) {
            var go = e.target.closest && e.target.closest('[data-go]');
            if (go) { e.preventDefault(); doSearch(parseInt(go.dataset.go, 10)); return; }
            // "See all in Books / Video" links switch the scope picker to
            // the matching scope and re-run the search at page 1, so the
            // user gets the full paginated list (Site mode caps each
            // section at SITE_SECTION_LIMIT). The scope picker UI was
            // removed earlier but these links must still drill into the
            // single-source-paginated view, so call the search functions
            // directly here.
            var see = e.target.closest && e.target.closest('[data-see]');
            if (see) {
                e.preventDefault();
                var q = $('ss-input').value.trim();
                if (!q) return;
                currentPage = 1;
                if (see.dataset.see === 'books')      searchBooks(q);
                else if (see.dataset.see === 'video') searchVideo(q);
                return;
            }
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
    function syncModeOptionActive() {
        var current = $('ss-mode').value;
        Array.prototype.forEach.call(document.querySelectorAll('#ss-mode-picker .ss-mode-option'), function (opt) {
            opt.classList.toggle('is-active', opt.dataset.mode === current);
        });
    }

    // The mode select shows full labels on desktop and shorter ones on
    // mobile. <option> text content can't be styled via CSS, so we swap
    // it in JS based on a matchMedia listener that fires whenever the
    // viewport crosses the 767px breakpoint.
    var MODE_LABELS_DESKTOP = { all: 'All Words', phrase: 'Exact Phrase', any: 'Any Word' };
    var MODE_LABELS_MOBILE  = { all: 'All',       phrase: 'Phrase',       any: 'Any'      };
    function applyModeLabels() {
        var sel = document.getElementById('ss-mode');
        if (!sel) return;
        var isMobile = window.matchMedia('(max-width: 767px)').matches;
        var labels = isMobile ? MODE_LABELS_MOBILE : MODE_LABELS_DESKTOP;
        for (var i = 0; i < sel.options.length; i++) {
            var v = sel.options[i].value;
            if (labels[v]) sel.options[i].textContent = labels[v];
        }
    }

    // Pull in the Julius Sans One Google Font so the search-input
    // placeholder can use it on every host page (some pages already
    // load it for login modal headings, others don't).
    function injectFonts() {
        if (document.getElementById('site-search-fonts')) return;
        var link = document.createElement('link');
        link.id = 'site-search-fonts';
        link.rel = 'stylesheet';
        link.href = 'https://fonts.googleapis.com/css2?family=Julius+Sans+One&display=swap';
        document.head.appendChild(link);
    }

    function init() {
        // Admin pages have their own /js/admin-nav.js toolbar + page-specific
        // controls. The public search band would crowd those layouts and the
        // admin user already has the admin sidebar. The path can take many
        // shapes — bare /admin, /admin.html, /admin/foo, /admin-foo,
        // /admin-foo.html — so use a word-boundary match instead of just
        // the hyphen suffix that an earlier guard relied on. The hyphen-only
        // version let /admin (the landing page) slip through, which kept
        // re-introducing the search band on the admin home each time
        // site-nav.js was reintroduced to that page.
        var p = location.pathname || '';
        if (/^\/admin\b/i.test(p) || /^\/test\//i.test(p)) return;
        injectFonts();
        injectStyle();
        buildBar();
        wire();
        applyModeLabels();
        try {
            window.matchMedia('(max-width: 767px)').addEventListener('change', applyModeLabels);
        } catch (_) {
            // Safari < 14 lacks addEventListener on MediaQueryList; fall
            // back to a resize listener that's debounced via rAF.
            var rafId = 0;
            window.addEventListener('resize', function () {
                if (rafId) return;
                rafId = requestAnimationFrame(function () { rafId = 0; applyModeLabels(); });
            });
        }
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
