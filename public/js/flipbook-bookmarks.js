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
    var autoSaveTimer = null;

    // ── SVG icons ───────────────────────────────────────────────────────
    function iconFilled(color) {
        return '<svg viewBox="0 0 24 24" width="18" height="22" aria-hidden="true">'
             + '<path d="M6 3 H18 V21 L12 17 L6 21 Z" fill="' + (color || '#888') + '" stroke="rgba(0,0,0,0.45)" stroke-width="1.2"/>'
             + '</svg>';
    }
    function iconOutline() {
        return '<svg viewBox="0 0 24 24" width="18" height="22" aria-hidden="true">'
             + '<path d="M6 3 H18 V21 L12 17 L6 21 Z" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.55"/>'
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
        bookmarks.forEach(function (b) {
            if (b.bookmark_auto_flag) return; // auto bookmark doesn't drive icon fill
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
            // Icon overlay (top-right of every visible .pg / .cpg.center)
            '.pg, .cpg { position: relative; }',
            '.fb-bm-icon { position: absolute; top: 4px; right: 6px; z-index: 5; cursor: pointer;',
            '              color: #444; line-height: 0; padding: 2px;',
            '              filter: drop-shadow(0 1px 1px rgba(0,0,0,0.18));',
            '              transition: transform 0.12s ease, opacity 0.12s ease; }',
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
            '.fb-bm-pop button.danger { background: #4d2a2a; color: #ffbcbc; border-color: #6d3a3a; margin-right: auto; }',
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
            '.fb-bm-marker-lane { position: absolute; left: 0; right: 0; bottom: 100%; height: 12px;',
            '                     pointer-events: none; }',
            '.fb-bm-marker { position: absolute; transform: translateX(-50%); pointer-events: auto;',
            '                cursor: pointer; padding: 2px 1px; }',
            '.fb-bm-marker svg { display: block; }',
            '.fb-bm-marker:hover { transform: translateX(-50%) scale(1.4); }'
        ].join('\n');
        document.head.appendChild(st);
    }

    // ── Sidebar tab ─────────────────────────────────────────────────────
    function ensureSidebarTab() {
        var tabs = document.querySelector('aside .tabs');
        if (!tabs || document.querySelector('aside .tab[data-tab="bookmarks"]')) return;
        var tab = document.createElement('div');
        tab.className = 'tab';
        tab.setAttribute('data-tab', 'bookmarks');
        tab.textContent = 'Bookmarks';
        tabs.appendChild(tab);
        // Pane
        var body = document.querySelector('aside .body');
        if (body && !document.getElementById('bookmarks-pane')) {
            var pane = document.createElement('div');
            pane.id = 'bookmarks-pane';
            pane.style.display = 'none';
            pane.innerHTML = '<div class="fb-bm-list" id="fb-bm-list"></div>';
            body.appendChild(pane);
        }
        // Wire click using the same convention other tabs use (data-tab → toggle pane visibility).
        tab.addEventListener('click', function () {
            document.querySelectorAll('aside .tab').forEach(function (x) {
                x.classList.toggle('active', x === tab);
                var p = document.getElementById(x.getAttribute('data-tab') + '-pane');
                if (p) p.style.display = (x === tab) ? '' : 'none';
            });
        });
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
            btn.innerHTML = bm ? iconFilled(bm.bookmark_color || '#888') : iconOutline();
            btn.title = bm
                ? ((bm.bookmark_label || '(no label)') + (bm.bookmark_note ? '\n\n' + bm.bookmark_note : ''))
                : 'Add bookmark for page ' + t.page;
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
        // absolutely above it. The container takes the same flex slot the input
        // had so layout doesn't shift.
        var wrap = document.createElement('span');
        wrap.style.cssText = 'position:relative; flex: 1; display: flex;';
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
        lane.innerHTML = bookmarks
            .map(function (b) {
                var pct = ((b.bookmark_page - 1) / Math.max(1, total - 1)) * 100;
                pct = Math.max(0, Math.min(100, pct));
                var color = b.bookmark_color || '#888';
                var titleAttr = (b.bookmark_label || 'Page ' + b.bookmark_page)
                              + (b.bookmark_note ? ' — ' + b.bookmark_note : '');
                return '<span class="fb-bm-marker" data-page="' + b.bookmark_page + '" style="left:' + pct.toFixed(2) + '%" title="' + esc(titleAttr) + '">'
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

    // ── Auto-bookmark (fire-and-forget on page change, debounced) ───────
    BM.recordCurrentPage = function (page) {
        if (!page || !cfg.bookCode) return;
        if (autoSaveTimer) clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(function () {
            fetch('/api/bookmarks.php', {
                method: 'POST', credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'auto_bookmark', book_code: cfg.bookCode, bookmark_page: page })
            }).catch(function () { /* offline / anonymous — fine to drop */ });
        }, 1500);
    };
})();
