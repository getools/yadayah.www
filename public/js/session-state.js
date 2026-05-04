/* session-state.js — generic per-user UI state auto-persistence
   ============================================================
   Drop this script into any page (admin or public) and it will:
     - Bind every <input>, <select>, <textarea> with an `id`
     - Save its value to localStorage AND /api/session-state.php on change
     - Restore the saved value on page load
     - Re-apply the saved value when a <select>'s options arrive late
       (handles the common "filter dropdown populated from API response"
       race that otherwise drops the user's selection on refresh)
     - Track newly-rendered inputs (table re-renders, modal opens) via a
       MutationObserver

   Per-page namespace ("scope") is derived from the URL path. So
   /admin-feeds.html and /admin-books.html each get their own bucket and
   IDs can repeat between pages without collision.

   Opt-out controls (least-disruptive defaults):
     - Element with `data-no-persist` attribute → skipped
     - Any ancestor with `data-no-persist` → all descendants skipped.
       Wrap modal forms (`<div id="form-overlay" data-no-persist>…`) and
       login screens (`<div id="login-screen" data-no-persist>…`) so
       record-edit fields and credentials never persist.
     - Element types we never persist: password, hidden, file, submit, button
     - Inputs with no `id` AND no `data-persist` attribute → skipped
       (we need a stable handle to save under)

   Server sync:
     - Loads from /api/session-state.php?scope=<page> on init, then layers
       localStorage on top so unsaved-but-cached values aren't lost if
       the server fetch fails or hasn't completed.
     - Writes are debounced (300ms) and batched. Server failures fall back
       to localStorage-only and retry on next change.

   Public surface (window.sessionState):
     - .save(name, value)     — manually persist a key
     - .load(name, default)   — read most-recent value
     - .bind(element, [name]) — manually bind a non-id'd element
     - .scope                 — the current page's scope string
     - .clear()               — wipe everything for this page
*/
(function () {
    var SCOPE = (location.pathname || '/')
        .replace(/^\/+/, '').replace(/\.html?$/i, '').replace(/[^a-z0-9._-]+/gi, '_') || 'index';
    var STORAGE_PREFIX = 'session-state:' + SCOPE + ':';
    var API_URL = '/api/session-state.php';
    var DEBOUNCE_MS = 300;

    function ls(op, k, v) {
        try {
            if (op === 'get') {
                var raw = localStorage.getItem(k);
                return raw === null ? undefined : JSON.parse(raw);
            }
            if (op === 'set') {
                if (v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0)) localStorage.removeItem(k);
                else localStorage.setItem(k, JSON.stringify(v));
            }
            if (op === 'del') localStorage.removeItem(k);
        } catch (e) { /* quota or disabled */ }
    }

    var serverSnapshot = {}; // last-known server values, used as restore fallback
    var pending = {};        // queued writes awaiting flush
    var flushTimer = null;

    function scheduleFlush() {
        if (flushTimer) return;
        flushTimer = setTimeout(flush, DEBOUNCE_MS);
    }
    function flush() {
        flushTimer = null;
        if (!Object.keys(pending).length) return;
        var items = pending;
        pending = {};
        fetch(API_URL, {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ scope: SCOPE, items: items }),
        }).catch(function () {
            // On failure, requeue. localStorage already has the value so
            // the user doesn't lose anything; the next flush will retry.
            for (var k in items) if (Object.prototype.hasOwnProperty.call(items, k) && !(k in pending)) pending[k] = items[k];
            scheduleFlush();
        });
    }

    function save(name, value) {
        ls('set', STORAGE_PREFIX + name, value);
        pending[name] = value;
        serverSnapshot[name] = value;
        scheduleFlush();
    }
    function load(name, dflt) {
        var v = ls('get', STORAGE_PREFIX + name);
        if (v !== undefined) return v;
        if (Object.prototype.hasOwnProperty.call(serverSnapshot, name)) return serverSnapshot[name];
        return dflt;
    }
    function clearAll() {
        try {
            for (var i = localStorage.length - 1; i >= 0; i--) {
                var k = localStorage.key(i);
                if (k && k.indexOf(STORAGE_PREFIX) === 0) localStorage.removeItem(k);
            }
        } catch (e) {}
        fetch(API_URL + '?scope=' + encodeURIComponent(SCOPE), {
            method: 'DELETE', credentials: 'include',
        }).catch(function () {});
    }

    // ── Element helpers ────────────────────────────────────────────────
    var SKIP_TYPES = { password: 1, hidden: 1, file: 1, submit: 1, button: 1, image: 1, reset: 1 };
    // Default opt-out containers — login screens, modal/edit forms (so
    // record-edit fields and credentials never persist). Admins can also
    // tag any other container with `data-no-persist`.
    var SKIP_ANCESTOR_SELECTOR = '[data-no-persist], .form-overlay, .login-screen, #login-screen, [id$="form-overlay"]';
    function shouldSkip(el) {
        if (!el || !el.tagName) return true;
        if (el.dataset && (el.dataset.noPersist !== undefined || el.dataset.persist === 'false')) return true;
        if (el.closest && el.closest(SKIP_ANCESTOR_SELECTOR)) return true;
        var tag = el.tagName.toUpperCase();
        if (tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'TEXTAREA') return true;
        if (tag === 'INPUT' && SKIP_TYPES[el.type]) return true;
        if (!el.id && !(el.dataset && el.dataset.persist)) return true;
        return false;
    }
    function nameFor(el) {
        return (el.dataset && el.dataset.persist && el.dataset.persist !== 'true') ? el.dataset.persist : el.id;
    }
    function readEl(el) {
        if (el.type === 'checkbox') return el.checked;
        if (el.type === 'radio') return el.checked ? el.value : null;
        if (el.tagName === 'SELECT' && el.multiple) {
            return Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; });
        }
        return el.value;
    }
    function writeEl(el, v) {
        if (v === undefined || v === null) return false;
        if (el.type === 'checkbox') { el.checked = !!v; return true; }
        if (el.type === 'radio') {
            var match = String(v) === el.value;
            if (match) el.checked = true;
            return match;
        }
        if (el.tagName === 'SELECT' && el.multiple && Array.isArray(v)) {
            var set = {};
            v.forEach(function (x) { set[String(x)] = true; });
            Array.prototype.forEach.call(el.options, function (o) { o.selected = !!set[o.value]; });
            return true;
        }
        var prev = el.value;
        el.value = String(v);
        // For <select>, the assignment is silently dropped if no option matches.
        if (el.tagName === 'SELECT' && el.value !== String(v)) { el.value = prev; return false; }
        return true;
    }

    // ── Bind / restore ─────────────────────────────────────────────────
    var bound = (typeof WeakSet !== 'undefined') ? new WeakSet() : { _arr: [], has: function (e) { return this._arr.indexOf(e) >= 0; }, add: function (e) { this._arr.push(e); } };
    var pendingRestores = []; // { el, name, value, observer? }

    function tryRestore(el, name) {
        var saved = load(name);
        if (saved === undefined) return true; // nothing to restore — done
        var ok = writeEl(el, saved);
        if (ok) return true;
        // Couldn't apply (likely a <select> whose options aren't here yet).
        // Watch the element for option additions and retry.
        if (el.tagName === 'SELECT' && typeof MutationObserver !== 'undefined') {
            var obs = new MutationObserver(function () {
                if (writeEl(el, load(name))) {
                    obs.disconnect();
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            obs.observe(el, { childList: true, subtree: true });
            pendingRestores.push({ el: el, name: name, observer: obs });
        }
        return false;
    }
    function bindEl(el) {
        if (bound.has(el) || shouldSkip(el)) return;
        bound.add(el);
        var name = nameFor(el);
        if (!name) return;
        tryRestore(el, name);
        var handler = function () { save(name, readEl(el)); };
        el.addEventListener('change', handler);
        var t = (el.tagName === 'INPUT') ? el.type : '';
        if (t === 'text' || t === 'search' || t === 'date' || t === 'time' || t === 'datetime-local'
            || t === 'number' || t === 'email' || t === 'tel' || t === 'url' || el.tagName === 'TEXTAREA') {
            el.addEventListener('input', handler);
        }
    }
    function bindAll(root) {
        (root || document).querySelectorAll('input, select, textarea').forEach(bindEl);
    }

    // ── Boot: fetch server snapshot, then bind everything ───────────────
    function boot() {
        // Bind whatever's already in the DOM using localStorage values first
        // (instant, even before the server responds). The server fetch will
        // overlay any newer values when it arrives.
        bindAll();
        fetch(API_URL + '?scope=' + encodeURIComponent(SCOPE), { credentials: 'include' })
            .then(function (r) { return r.ok ? r.json() : { items: {} }; })
            .then(function (d) {
                var items = (d && d.items) || {};
                serverSnapshot = items;
                // Re-apply server snapshot only where localStorage lacks a
                // newer value (localStorage acts as a write-through cache).
                Object.keys(items).forEach(function (name) {
                    if (ls('get', STORAGE_PREFIX + name) === undefined) ls('set', STORAGE_PREFIX + name, items[name]);
                });
                // Trigger restore on already-bound elements that didn't have
                // a localStorage value at first bind.
                pendingRestores.slice().forEach(function (pr) { tryRestore(pr.el, pr.name); });
                document.querySelectorAll('input, select, textarea').forEach(function (el) {
                    if (shouldSkip(el)) return;
                    var name = nameFor(el);
                    if (!name || el.value || el.checked) return;
                    if (Object.prototype.hasOwnProperty.call(items, name)) writeEl(el, items[name]);
                });
            })
            .catch(function () { /* offline → localStorage is fine */ });

        // Watch for late-rendered inputs (table re-renders, modal opens, etc.)
        if (typeof MutationObserver !== 'undefined') {
            var obs = new MutationObserver(function (muts) {
                for (var i = 0; i < muts.length; i++) {
                    var added = muts[i].addedNodes;
                    for (var j = 0; j < added.length; j++) {
                        var n = added[j];
                        if (n.nodeType !== 1) continue;
                        if (n.matches && n.matches('input, select, textarea')) bindEl(n);
                        if (n.querySelectorAll) bindAll(n);
                    }
                }
            });
            obs.observe(document.documentElement, { childList: true, subtree: true });
        }

        // Make sure pending writes flush before the user navigates away.
        window.addEventListener('beforeunload', flush);
        window.addEventListener('pagehide', flush);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.sessionState = { save: save, load: load, bind: bindEl, scope: SCOPE, clear: clearAll };
})();
