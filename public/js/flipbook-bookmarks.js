/* ── flipbook-bookmarks.js ──────────────────────────────────────────────
   Per-user bookmark UI for the self-hosted flipbook viewer.
   Provides:
     - Bookmark icon overlay on each visible page (faded outline by default;
       filled with bookmark_color when a manual bookmark exists for that page)
     - Add/edit popover (Page · Color · Label · Notes · Save/Cancel · Delete)
     - "Bookmarks" sidebar tab with sortable list
     - Colored clickable pointer markers above the page slider
     - Auto-bookmark of the last viewed page (debounced)

   Public surface:
     FlipbookBookmarks.init({ bookCode, totalPages, getCurrentPage, gotoPage });
     FlipbookBookmarks.refresh();   // call after every page render

   Backed by /api/bookmarks.php (the API resolves bookCode → volume_key on
   the server so the viewer doesn't need to know volume_key).
*/
(function () {
    'use strict';
    var BM = {};
    window.FlipbookBookmarks = BM;

    var cfg = {};
    var bookmarks = [];          // server-fetched list, sorted by page
    var bookmarksByPage = {};    // page → first manual bookmark on that page
    var autoDefaults = {};       // { color, label, note } from admin-flipbook
    var siteSettings = {};       // raw flipbook-scoped yy_setting values
    var autoSaveTimer = null;

    // ── SVG icons ───────────────────────────────────────────────────────
    // Width/height + outline color come from admin-flipbook (Bookmark
    // Placeholder section); we fall back to the same defaults the admin form
    // shows so the icon still renders if those settings haven't been saved.
    function placeholderW() { return parseInt(siteSettings['placeholder-width'],  10) || 18; }
    function placeholderH() { return parseInt(siteSettings['placeholder-height'], 10) || 22; }
    function placeholderColor() { return siteSettings['placeholder-color'] || '#888888'; }
    // Icon wrapper (the clickable hit-area around the SVG glyph) — separate
    // from the glyph itself so admins can grow the tap target without
    // distorting the bookmark drawing.
    function iconW()   { return parseInt(siteSettings['icon-width'],  10) || 24; }
    function iconH()   { return parseInt(siteSettings['icon-height'], 10) || 28; }
    function iconBg()  { return siteSettings['icon-bg'] || 'transparent'; }
    function iconFilled(color) {
        var w = placeholderW(), h = placeholderH();
        return '<svg viewBox="0 0 24 24" width="' + w + '" height="' + h + '" aria-hidden="true">'
             + '<path d="M6 3 H18 V21 L12 17 L6 21 Z" fill="' + (color || '#888') + '" stroke="rgba(0,0,0,0.45)" stroke-width="1.2"/>'
             + '</svg>';
    }
    function iconOutline() {
        var w = placeholderW(), h = placeholderH(), c = placeholderColor();
        return '<svg viewBox="0 0 24 24" width="' + w + '" height="' + h + '" aria-hidden="true">'
             + '<path d="M6 3 H18 V21 L12 17 L6 21 Z" fill="none" stroke="' + c + '" stroke-width="1.5" opacity="0.85"/>'
             + '</svg>';
    }
    function randomColor() {
        // HSL with mid lightness keeps colors readable on white pages.
        return 'hsl(' + Math.floor(Math.random() * 360) + ', 70%, 55%)';
    }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    // ── Init ────────────────────────────────────────────────────────────
    BM.init = function (opts) {
        cfg = opts || {};
        injectStyle();
        ensureSidebarTab();
        ensureMarkerLane();
        ensurePopover();
        loadBookmarks();
    };

    BM.refresh = function () {
        renderIconOverlays();
        renderMarkerLane();
    };

    function loadBookmarks() {
        if (!cfg.bookCode) return;
        fetch('/api/bookmarks.php?book_code=' + encodeURIComponent(cfg.bookCode), { credentials: 'include' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                bookmarks = (d && d.bookmarks) || [];
                autoDefaults = (d && d.auto_defaults) || {};
                siteSettings = (d && d.settings) || {};
                // Apply auto-defaults to auto bookmarks where the row's own
                // field is null. The DB row stays untouched — this is purely
                // a render concern, set by the admin Flipbook page.
                bookmarks.forEach(function (b) {
                    if (!b.bookmark_auto_flag) return;
                    if (!b.bookmark_color) b.bookmark_color = autoDefaults.color || null;
                    if (!b.bookmark_label) b.bookmark_label = autoDefaults.label || null;
                    if (!b.bookmark_note)  b.bookmark_note  = autoDefaults.note  || null;
                });
                rebuildIndex();
                BM.refresh();
                renderSidebarList();
            })
            .catch(function () { /* anonymous or offline — silently leave empty */ });
    }

    function rebuildIndex() {
        bookmarksByPage = {};
        // Manual bookmarks sort before auto in the API (ORDER BY bookmark_auto_flag ASC),
        // so first-write-wins gives manual precedence when a page has both.
        bookmarks.forEach(function (b) {
            var p = b.bookmark_page;
            if (!bookmarksByPage[p]) bookmarksByPage[p] = b;
        });
    }

    // ── Styles ──────────────────────────────────────────────────────────
    function injectStyle() {
        if (document.getElementById('fb-bm-style')) return;
        var st = document.createElement('style');
        st.id = 'fb-bm-style';
        st.textContent = [
            // Icon overlay sits absolute inside each visible page. The base
            // CSS already gives `#book .pg { position:relative }` and
            // `#carousel .cpg { position:absolute }`, both of which establish
            // a positioning context, so we don't add a position rule here —
            // an earlier attempt to do so collapsed `.cpg` from absolute to
            // relative and stacked pages vertically, breaking layout.
            '.fb-bm-icon { position: absolute; top: 4px; right: 6px; z-index: 5; cursor: pointer;',
            '              color: #444; line-height: 0; padding: 0;',
            // Strip the <button> default chrome (gray fill, rounded border) so
            // only the SVG bookmark glyph shows on the page corner.
            '              background: none; border: 0; -webkit-appearance: none; appearance: none;',
            '              filter: drop-shadow(0 1px 1px rgba(0,0,0,0.18));',
            '              transition: transform 0.12s ease, opacity 0.12s ease; }',
            '.fb-bm-icon:focus { outline: none; }',
            '.fb-bm-icon:focus-visible { outline: 2px solid #5b8def; outline-offset: 2px; border-radius: 2px; }',
            '.fb-bm-icon:hover { transform: scale(1.15); }',
            // Popover
            '.fb-bm-pop-mask { position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 200;',
            '                  display: none; align-items: center; justify-content: center; }',
            '.fb-bm-pop-mask.show { display: flex; }',
            '.fb-bm-pop { background: #1f1f22; color: #ddd; border-radius: 8px; padding: 16px;',
            '             min-width: 320px; max-width: 560px; width: 90%;',
            '             box-shadow: 0 10px 40px rgba(0,0,0,0.5); border: 1px solid #3a3a3e;',
            '             font: 13px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; }',
            '.fb-bm-pop label { display: block; font-size: 11px; opacity: 0.75; margin: 8px 0 3px; text-transform: uppercase; letter-spacing: 0.04em; }',
            '.fb-bm-pop input[type=text], .fb-bm-pop input[type=number], .fb-bm-pop textarea {',
            '   width: 100%; box-sizing: border-box; padding: 6px 9px; background: #2a2a2e; color: #eee;',
            '   border: 1px solid #3a3a3e; border-radius: 4px; font: inherit; }',
            '.fb-bm-pop textarea { min-height: 90px; resize: vertical; }',
            '.fb-bm-pop input[type=color] { width: 36px; height: 32px; padding: 0; border: 1px solid #3a3a3e; background: none; cursor: pointer; border-radius: 4px; }',
            '.fb-bm-pop .row { display: flex; gap: 10px; align-items: flex-end; }',
            '.fb-bm-pop .row > div { flex: 1; }',
            '.fb-bm-pop .row > div.shrink { flex: 0 0 auto; }',
            '.fb-bm-pop .actions { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; gap: 8px; }',
            '.fb-bm-pop button { padding: 6px 14px; border-radius: 4px; border: 1px solid #3a3a3e; cursor: pointer; font: inherit; }',
            '.fb-bm-pop button.primary { background: #3a4d6e; color: #fff; border-color: #5b8def; }',
            '.fb-bm-pop button.primary:hover { background: #4a5d7e; }',
            '.fb-bm-pop button.ghost { background: #2a2a2e; color: #ddd; }',
            // With justify-content:space-between and three buttons, Save sits at
            // the far left, Cancel at the far right, and Delete naturally lands
            // in the middle. (Earlier had margin-right:auto here which shoved
            // Delete off-center toward Cancel.)
            '.fb-bm-pop button.danger { background: #4d2a2a; color: #ffbcbc; border-color: #6d3a3a; }',
            '.fb-bm-pop .err { color: #ff8a8a; font-size: 11px; margin-top: 6px; min-height: 14px; }',
            // Sidebar list
            '.fb-bm-list { padding: 6px 4px; }',
            '.fb-bm-list .row { display: grid; grid-template-columns: 38px 1fr 22px 22px; gap: 4px;',
            '                   align-items: center; padding: 5px 8px; border-radius: 4px; cursor: pointer; }',
            '.fb-bm-list .row:hover { background: #2a2a2e; }',
            '.fb-bm-list .pg { font: 12px/1.2 ui-monospace, monospace; color: #aaa; text-align: right; padding-right: 6px; border-right: 2px solid currentColor; }',
            '.fb-bm-list .lbl { font-size: 13px; color: #ddd; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }',
            '.fb-bm-list .lbl.empty { font-style: italic; opacity: 0.55; }',
            '.fb-bm-list button.icon { width: 22px; height: 22px; display: flex; align-items: center; justify-content: center;',
            '                          background: transparent; border: 0; color: #888; cursor: pointer; border-radius: 3px; padding: 0; }',
            '.fb-bm-list button.icon:hover { background: #3a3a3e; color: #fff; }',
            '.fb-bm-list .empty-msg { padding: 16px; text-align: center; color: #888; font-size: 12px; font-style: italic; }',
            // Slider markers
            '.fb-bm-seek-wrap { position: relative; flex: 1 1 0; display: flex; align-items: center; max-width: none; }',
            // Override the global "input[type=range] { flex:1; max-width:220px }"
            // when the seek input lives inside our wrapper — let it fill so the
            // marker lane and the slider thumb align across the same width.
            '.fb-bm-seek-wrap > input[type=range] { flex: 1 1 0; width: 100%; max-width: none; }',
            '.fb-bm-marker-lane { position: absolute; left: 0; right: 0; bottom: 100%; height: 8px;',
            '                     pointer-events: none;',
            '                     background: var(--fb-bm-lane-bg, transparent);',
            '                     border-radius: 4px 4px 0 0; }',
            '.fb-bm-marker { position: absolute; transform: translateX(-50%); pointer-events: auto;',
            '                cursor: pointer; padding: 2px 1px; }',
            '.fb-bm-marker svg { display: block; }',
            '.fb-bm-marker:hover { transform: translateX(-50%) scale(1.4); }'
        ].join('\n');
        document.head.appendChild(st);
    }

    // ── Sidebar tab ─────────────────────────────────────────────────────
    // Idempotent: the tab + pane shells are also baked into the static HTML
    // so we don't recreate them, but we DO need to ensure the list element
    // and click handler exist regardless of whether the shells came from JS
    // or HTML. (Earlier version early-returned when the tab existed, leaving
    // an empty pane with no click handler — bookmarks tab silently blank.)
    function ensureSidebarTab() {
        var tabs = document.querySelector('aside .tabs');
        if (!tabs) return;

        // 1. Tab itself
        var tab = document.querySelector('aside .tab[data-tab="bookmarks"]');
        if (!tab) {
            tab = document.createElement('div');
            tab.className = 'tab';
            tab.setAttribute('data-tab', 'bookmarks');
            tab.textContent = 'Bookmark';
            tabs.appendChild(tab);
        }

        // 2. Pane shell
        var body = document.querySelector('aside .body');
        var pane = document.getElementById('bookmarks-pane');
        if (body && !pane) {
            pane = document.createElement('div');
            pane.id = 'bookmarks-pane';
            pane.style.display = 'none';
            body.appendChild(pane);
        }

        // 3. List element inside the pane (separate from the pane shell so the
        //    static HTML can pre-create the pane without us double-creating it).
        if (pane && !document.getElementById('fb-bm-list')) {
            var list = document.createElement('div');
            list.className = 'fb-bm-list';
            list.id = 'fb-bm-list';
            pane.appendChild(list);
        }

        // 4. Click handler (idempotent — flag on the element so we don't double-bind).
        if (!tab.dataset.fbBmWired) {
            tab.dataset.fbBmWired = '1';
            tab.addEventListener('click', function () {
                document.querySelectorAll('aside .tab').forEach(function (x) {
                    x.classList.toggle('active', x === tab);
                    var p = document.getElementById(x.getAttribute('data-tab') + '-pane');
                    if (p) p.style.display = (x === tab) ? '' : 'none';
                });
            });
        }
    }

    function renderSidebarList() {
        var list = document.getElementById('fb-bm-list');
        if (!list) return;
        if (!bookmarks.length) {
            list.innerHTML = '<div class="empty-msg">No bookmarks yet — click the bookmark icon on any page to add one.</div>';
            return;
        }
        // Auto bookmark first (it's the "resume reading" pointer), manual ones sorted by page.
        var ordered = bookmarks.slice().sort(function (a, b) {
            if (a.bookmark_auto_flag !== b.bookmark_auto_flag) return a.bookmark_auto_flag ? -1 : 1;
            return a.bookmark_page - b.bookmark_page;
        });
        list.innerHTML = ordered.map(function (b) {
            var color = b.bookmark_color || '#888';
            var labelHtml = b.bookmark_label
                ? '<span class="lbl" title="' + esc(b.bookmark_label) + (b.bookmark_note ? '\n\n' + esc(b.bookmark_note) : '') + '">' + esc(b.bookmark_label) + '</span>'
                : '<span class="lbl empty">(no label)</span>';
            return '<div class="row" data-key="' + b.bookmark_key + '" data-page="' + b.bookmark_page + '">'
                 + '<span class="pg" style="color:' + esc(color) + '">' + b.bookmark_page + '</span>'
                 + labelHtml
                 + '<button class="icon" data-action="edit" title="Edit"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4Z"/></svg></button>'
                 + '<button class="icon" data-action="del"  title="Delete"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>'
                 + '</div>';
        }).join('');
        list.querySelectorAll('.row').forEach(function (row) {
            var key = parseInt(row.dataset.key, 10);
            var page = parseInt(row.dataset.page, 10);
            row.addEventListener('click', function (e) {
                var action = e.target.closest('button[data-action]');
                if (action && action.dataset.action === 'edit') { e.stopPropagation(); openPopover(byKey(key)); return; }
                if (action && action.dataset.action === 'del')  { e.stopPropagation(); deleteBookmark(key); return; }
                if (cfg.gotoPage && page) cfg.gotoPage(page);
            });
        });
    }

    function byKey(k) { return bookmarks.find(function (b) { return b.bookmark_key === k; }); }

    // ── Icon overlay (on each visible page) ─────────────────────────────
    function renderIconOverlays() {
        // Strip any existing overlay first so we don't duplicate on re-render.
        document.querySelectorAll('.fb-bm-icon').forEach(function (el) { el.remove(); });

        var carouselMode = document.body.classList.contains('mode-carousel');
        var targets = [];
        if (carouselMode) {
            var center = document.querySelector('#carousel .cpg.center');
            if (center) targets.push({ el: center, page: getSlotPage(center) });
        } else {
            // Spread mode — both visible pages of the current spread. StPageFlip
            // marks the rendered pair with .--left and .--right classes, but
            // their `data-page` (set during loadFromHTML) is what we actually
            // care about. Fall back to scanning any .pg with display !== 'none'
            // when the helper classes aren't on this version of the lib.
            var pairs = document.querySelectorAll('#book .stf__item.--page, #book .pg');
            pairs.forEach(function (el) {
                if (el.offsetParent === null) return;
                var page = getSlotPage(el);
                if (page) targets.push({ el: el, page: page });
            });
        }
        targets.forEach(function (t) {
            var bm = bookmarksByPage[t.page];
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fb-bm-icon';
            // Apply admin-configurable wrapper size + background. The SVG
            // glyph inside still uses its own width/height (placeholder-*),
            // so the wrapper acts as a padded hit-area around it.
            btn.style.width      = iconW() + 'px';
            btn.style.height     = iconH() + 'px';
            btn.style.background = iconBg();
            btn.style.display    = 'flex';
            btn.style.alignItems = 'center';
            btn.style.justifyContent = 'center';
            btn.innerHTML = bm ? iconFilled(bm.bookmark_color || '#888') : iconOutline();
            // Tooltip text on the placeholder (no-bookmark) icon comes from
            // admin-flipbook's "Hover text" setting; supports a {page} token
            // that we substitute at render time.
            var tipTpl = siteSettings['placeholder-tooltip'] || 'Add bookmark for page {page}';
            btn.title = bm
                ? ((bm.bookmark_label || '(no label)') + (bm.bookmark_note ? '\n\n' + bm.bookmark_note : ''))
                : tipTpl.replace(/\{page\}/g, t.page);
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                openPopover(bm || { bookmark_page: t.page });
            });
            t.el.appendChild(btn);
        });
    }

    function getSlotPage(el) {
        // Each .pg has data-page (set when StPageFlip loads HTML) or a child element
        // .pg-num with the page index. Fall back to its position among siblings.
        var d = el.getAttribute('data-page');
        if (d) return parseInt(d, 10);
        var pgnum = el.querySelector('.pg-num, [data-page]');
        if (pgnum) return parseInt(pgnum.getAttribute('data-page') || pgnum.textContent, 10);
        return 0;
    }

    // ── Slider marker lane (above the seek slider) ──────────────────────
    function ensureMarkerLane() {
        var seek = document.getElementById('seek');
        if (!seek || document.getElementById('fb-bm-marker-lane')) return;
        var nav = seek.parentNode;
        // Wrap the seek input in a positioned container so we can place markers
        // absolutely above it. The wrapper takes nav's full flex slot AND
        // overrides the global 220px cap on the seek (via .fb-bm-seek-wrap CSS)
        // so the slider thumb and marker triangles span the same width.
        var wrap = document.createElement('span');
        wrap.className = 'fb-bm-seek-wrap';
        nav.insertBefore(wrap, seek);
        wrap.appendChild(seek);
        var lane = document.createElement('div');
        lane.className = 'fb-bm-marker-lane';
        lane.id = 'fb-bm-marker-lane';
        wrap.appendChild(lane);
    }

    function renderMarkerLane() {
        var lane = document.getElementById('fb-bm-marker-lane');
        if (!lane) return;
        var total = cfg.totalPages || 1;
        // Thumb-edge inset: a native <input type=range> renders its thumb so
        // value=min is NOT at left:0 but at left:thumbHalf (≈8 px). Mirror
        // that so markers line up with the slider thumb instead of drifting
        // a few pixels to the left at the start and a few right at the end.
        var THUMB_HALF = 8;
        lane.innerHTML = bookmarks
            .map(function (b) {
                var frac = (b.bookmark_page - 1) / Math.max(1, total - 1);
                frac = Math.max(0, Math.min(1, frac));
                var color = b.bookmark_color || '#888';
                var titleAttr = (b.bookmark_label || 'Page ' + b.bookmark_page)
                              + (b.bookmark_note ? ' — ' + b.bookmark_note : '');
                var leftCss = 'calc(' + THUMB_HALF + 'px + (100% - ' + (THUMB_HALF * 2) + 'px) * ' + frac.toFixed(4) + ')';
                return '<span class="fb-bm-marker" data-page="' + b.bookmark_page + '" style="left:' + leftCss + '" title="' + esc(titleAttr) + '">'
                     + '<svg viewBox="0 0 12 14" width="12" height="14"><path d="M6 14 L0 0 L12 0 Z" fill="' + esc(color) + '" stroke="rgba(0,0,0,0.4)" stroke-width="0.6"/></svg>'
                     + '</span>';
            }).join('');
        lane.querySelectorAll('.fb-bm-marker').forEach(function (m) {
            m.addEventListener('click', function () {
                var p = parseInt(m.dataset.page, 10);
                if (cfg.gotoPage && p) cfg.gotoPage(p);
            });
        });
    }

    // ── Popover (add / edit) ────────────────────────────────────────────
    var popState = null;

    function ensurePopover() {
        if (document.getElementById('fb-bm-pop-mask')) return;
        var mask = document.createElement('div');
        mask.id = 'fb-bm-pop-mask';
        mask.className = 'fb-bm-pop-mask';
        mask.innerHTML = ''
            + '<div class="fb-bm-pop" role="dialog" aria-modal="true" onclick="event.stopPropagation()">'
            + '  <div class="row">'
            + '    <div class="shrink">'
            + '      <label>Page</label>'
            + '      <input type="number" id="fb-bm-page" min="1" style="width:80px">'
            + '    </div>'
            + '    <div class="shrink">'
            + '      <label>Color</label>'
            + '      <input type="color" id="fb-bm-color">'
            + '    </div>'
            + '  </div>'
            + '  <div>'
            + '    <label>Label</label>'
            + '    <input type="text" id="fb-bm-label" maxlength="200" placeholder="A short title for this bookmark">'
            + '  </div>'
            + '  <div>'
            + '    <label>Notes</label>'
            + '    <textarea id="fb-bm-note" rows="4" placeholder="Optional longer notes…"></textarea>'
            + '  </div>'
            + '  <div class="err" id="fb-bm-err"></div>'
            + '  <div class="actions">'
            + '    <button class="primary" id="fb-bm-save">Save</button>'
            + '    <button class="danger"  id="fb-bm-del" style="display:none">Delete</button>'
            + '    <button class="ghost"   id="fb-bm-cancel">Cancel</button>'
            + '  </div>'
            + '</div>';
        document.body.appendChild(mask);
        // Wire
        mask.addEventListener('click', closePopover);
        document.getElementById('fb-bm-cancel').addEventListener('click', closePopover);
        document.getElementById('fb-bm-save').addEventListener('click', savePopover);
        document.getElementById('fb-bm-del').addEventListener('click', function () {
            if (popState && popState.bookmark_key) deleteBookmark(popState.bookmark_key);
        });
        // Esc to close
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.getElementById('fb-bm-pop-mask').classList.contains('show')) closePopover();
        });
    }

    function openPopover(bm) {
        ensurePopover();
        popState = bm || {};
        var isNew = !bm || !bm.bookmark_key;
        document.getElementById('fb-bm-page').value  = bm.bookmark_page || (cfg.getCurrentPage ? cfg.getCurrentPage() : 1);
        var color = bm.bookmark_color && /^#[0-9a-f]{6}$/i.test(bm.bookmark_color)
                    ? bm.bookmark_color
                    : hslToHex(bm.bookmark_color) || hslToHex(randomColor());
        document.getElementById('fb-bm-color').value = color;
        document.getElementById('fb-bm-label').value = bm.bookmark_label || '';
        document.getElementById('fb-bm-note').value  = bm.bookmark_note || '';
        document.getElementById('fb-bm-err').textContent = '';
        document.getElementById('fb-bm-del').style.display = isNew ? 'none' : '';
        document.getElementById('fb-bm-pop-mask').classList.add('show');
        // Label gets focus per spec.
        setTimeout(function () { document.getElementById('fb-bm-label').focus(); }, 30);
    }

    function closePopover() {
        var m = document.getElementById('fb-bm-pop-mask');
        if (m) m.classList.remove('show');
        popState = null;
    }

    function savePopover() {
        if (!popState) return;
        var page  = parseInt(document.getElementById('fb-bm-page').value, 10) || 0;
        var color = document.getElementById('fb-bm-color').value || null;
        var label = document.getElementById('fb-bm-label').value.trim();
        var note  = document.getElementById('fb-bm-note').value;
        if (!page) { document.getElementById('fb-bm-err').textContent = 'Page number is required.'; return; }
        var body = {
            book_code: cfg.bookCode,
            bookmark_page: page,
            bookmark_color: color,
            bookmark_label: label || null,
            bookmark_note:  note  || null,
        };
        var url, method;
        if (popState.bookmark_key) {
            url = '/api/bookmarks.php?key=' + popState.bookmark_key;
            method = 'PUT';
        } else {
            url = '/api/bookmarks.php';
            method = 'POST';
        }
        fetch(url, {
            method: method, credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.error) { document.getElementById('fb-bm-err').textContent = d.error; return; }
            closePopover();
            loadBookmarks();
        }).catch(function () {
            document.getElementById('fb-bm-err').textContent = 'Network error — try again.';
        });
    }

    function deleteBookmark(key) {
        if (!confirm('Delete this bookmark? This cannot be undone.')) return;
        fetch('/api/bookmarks.php?key=' + key, { method: 'DELETE', credentials: 'include' })
            .then(function (r) { return r.json(); })
            .then(function () { closePopover(); loadBookmarks(); });
    }

    // hsl(...) → #rrggbb. Color picker requires hex; we render hsl on creation
    // and need a stable hex when the user edits an existing bookmark.
    function hslToHex(s) {
        if (!s) return null;
        if (/^#[0-9a-f]{6}$/i.test(s)) return s;
        var m = String(s).match(/hsl\(\s*(\d+)\s*,\s*(\d+)%?\s*,\s*(\d+)%?/i);
        if (!m) return null;
        var h = +m[1] / 360, sa = +m[2] / 100, l = +m[3] / 100;
        function c(p, q, t) { if (t < 0) t += 1; if (t > 1) t -= 1;
            if (t < 1/6) return p + (q - p) * 6 * t;
            if (t < 1/2) return q;
            if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
            return p; }
        var q = l < 0.5 ? l * (1 + sa) : l + sa - l * sa;
        var p = 2 * l - q;
        var r = c(p, q, h + 1/3), g = c(p, q, h), b = c(p, q, h - 1/3);
        function hex(v) { return ('0' + Math.round(v * 255).toString(16)).slice(-2); }
        return '#' + hex(r) + hex(g) + hex(b);
    }

    // ── Auto-bookmark (only when leaving the book) ──────────────────────
    // Track the last page seen so unload can write it. Auto-bookmark used to
    // fire on every page-change with a 1.5 s debounce, which made "Resume
    // reading" jump to wherever the user happened to last be — even when
    // they were just flipping through. The user wants it set only when they
    // actually LEAVE the book, so we now flush via sendBeacon on
    // pagehide/beforeunload (synchronous-ish, fires even on tab close).
    var lastSeenPage = 0;
    BM.recordCurrentPage = function (page) {
        if (page > 0) lastSeenPage = page;
    };

    function flushAutoBookmark() {
        if (!lastSeenPage || !cfg.bookCode) return;
        var body = JSON.stringify({ action: 'auto_bookmark', book_code: cfg.bookCode, bookmark_page: lastSeenPage });
        // sendBeacon is the right API for unload — runs to completion even
        // after the page is closing. Falls back to fetch+keepalive for older
        // browsers.
        try {
            if (navigator.sendBeacon) {
                var blob = new Blob([body], { type: 'application/json' });
                navigator.sendBeacon('/api/bookmarks.php', blob);
                return;
            }
        } catch (e) {}
        try {
            fetch('/api/bookmarks.php', {
                method: 'POST', credentials: 'include', keepalive: true,
                headers: { 'Content-Type': 'application/json' }, body: body
            }).catch(function () {});
        } catch (e) {}
    }
    // Fire on every plausible exit hook — different browsers honor different
    // ones, and pagehide is the most reliable (no firing on bfcache restore).
    window.addEventListener('pagehide', flushAutoBookmark);
    window.addEventListener('beforeunload', flushAutoBookmark);
    // visibilitychange catches the tab being switched away on mobile where
    // beforeunload often doesn't fire. Re-flush every time the tab hides;
    // the API is idempotent (UPSERT keyed on user+volume+auto_flag).
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flushAutoBookmark();
    });
})();
