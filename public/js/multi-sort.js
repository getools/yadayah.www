/* multi-sort.js — shared multi-column table sorting for the admin pages.
 *
 * UX (same on every table):
 *   click a header        → sort by that column alone (clicking the primary again flips it)
 *   Shift+click a header  → add it as the next tie-breaker; Shift+click it again flips it,
 *                           a third time drops it (the last remaining key only flips)
 *   Arrows show ▲/▼ plus a small priority number once 2+ columns are active.
 *
 * State is a plain array of keys: [{col: 'name', dir: 'asc'|'desc'}, …], first = primary.
 *
 *   keys = MultiSort.click(keys, col, ev, defaultDir)   // ev = the click event (reads shiftKey)
 *   rows.sort(MultiSort.comparator(keys, getValue))     // getValue(row, col) → string|number|null
 *   el.innerHTML = MultiSort.arrowHtml(keys, col)       // ' ▲' / ' ▼2' / ''
 *   MultiSort.dir(keys, col)                            // 'asc'|'desc'|'' for CSS-class tables
 *   MultiSort.rank(keys, col)                           // 1-based priority, 0 if not sorted
 *   keys = MultiSort.parse(saved) || default            // tolerant JSON / array restore
 *   MultiSort.toParam(keys)  → 'col:asc,col2:desc'      // for server-side (API) sorting
 */
(function () {
    'use strict';

    function flip(d) { return d === 'asc' ? 'desc' : 'asc'; }

    function norm(keys) {
        if (!Array.isArray(keys)) return [];
        return keys.filter(function (k) { return k && k.col != null && k.col !== ''; })
                   .map(function (k) { return { col: String(k.col), dir: k.dir === 'desc' ? 'desc' : 'asc' }; });
    }

    function click(keys, col, ev, defaultDir) {
        keys = norm(keys);
        col = String(col);
        var dflt = defaultDir === 'desc' ? 'desc' : 'asc';
        var additive = !!(ev && (ev.shiftKey || ev === true));
        var i = -1;
        keys.forEach(function (k, j) { if (k.col === col) i = j; });
        if (!additive) {
            return [{ col: col, dir: i === 0 ? flip(keys[0].dir) : dflt }];
        }
        if (i < 0) { keys.push({ col: col, dir: dflt }); return keys; }
        if (keys[i].dir === dflt || keys.length === 1) keys[i].dir = flip(keys[i].dir);
        else keys.splice(i, 1);
        return keys;
    }

    function rank(keys, col) {
        keys = norm(keys);
        for (var i = 0; i < keys.length; i++) if (keys[i].col === String(col)) return i + 1;
        return 0;
    }

    function dir(keys, col) {
        var r = rank(keys, col);
        return r ? norm(keys)[r - 1].dir : '';
    }

    function arrowHtml(keys, col, arrows) {
        keys = norm(keys);
        var r = rank(keys, col);
        if (!r) return '';
        var a = arrows || { asc: '▲', desc: '▼' };
        return ' ' + a[keys[r - 1].dir] +
            (keys.length > 1 ? '<sup class="ms-rank" style="font-size:0.68em;margin-left:1px;">' + r + '</sup>' : '');
    }

    // Default value comparison: nulls/empties last (in either direction),
    // numbers numerically, everything else case-insensitive natural order.
    function cmpValues(a, b) {
        var ea = (a === null || a === undefined || a === ''), eb = (b === null || b === undefined || b === '');
        if (ea || eb) return ea === eb ? 0 : (ea ? 1 : -1);
        if (typeof a === 'number' && typeof b === 'number') return a - b;
        if (typeof a === 'boolean' || typeof b === 'boolean') return (a ? 1 : 0) - (b ? 1 : 0);
        return String(a).localeCompare(String(b), undefined, { sensitivity: 'base', numeric: true });
    }

    // getValue(row, col) → value; or pass cmpCol(a, b, col) → ascending result.
    function comparator(keys, getValue, cmpCol) {
        keys = norm(keys);
        return function (a, b) {
            for (var i = 0; i < keys.length; i++) {
                var k = keys[i], c;
                if (cmpCol) c = cmpCol(a, b, k.col);
                else {
                    var va = getValue(a, k.col), vb = getValue(b, k.col);
                    var ea = (va === null || va === undefined || va === ''), eb = (vb === null || vb === undefined || vb === '');
                    if (ea || eb) { if (ea !== eb) return ea ? 1 : -1; continue; } // empties always last
                    c = cmpValues(va, vb);
                }
                if (c) return k.dir === 'desc' ? -c : c;
            }
            return 0;
        };
    }

    function parse(raw) {
        try {
            var v = typeof raw === 'string' ? (raw ? JSON.parse(raw) : null) : raw;
            if (v && !Array.isArray(v) && v.col) v = [v];      // legacy {col, dir}
            v = norm(v);
            return v.length ? v : null;
        } catch (e) { return null; }
    }

    function toParam(keys) {
        return norm(keys).map(function (k) { return k.col + ':' + k.dir; }).join(',');
    }

    // Stop Shift+click on sortable headers from selecting text.
    try {
        var st = document.createElement('style');
        st.textContent = 'th.ms-sortable, th[data-ms-col] { user-select: none; -webkit-user-select: none; }';
        (document.head || document.documentElement).appendChild(st);
    } catch (e) {}

    window.MultiSort = {
        click: click, comparator: comparator, cmpValues: cmpValues, arrowHtml: arrowHtml,
        rank: rank, dir: dir, parse: parse, toParam: toParam, normalize: norm,
        HINT: 'Click a column header to sort; Shift+click more headers to add them as tie-breakers.',
    };
})();
