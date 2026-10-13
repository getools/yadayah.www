/* admin-ai-queue.js — Puget AI job queue widget in the admin toolbar (top right).
 *
 * Badge: what is on the GPU now, how many jobs are queued, when it all clears.
 * Click: every running / queued / held job with its type, item, submitted time,
 * estimated run time and estimated finish; a Priorities tab edits the job
 * classes (yy_ai_job_class). Data: /api/admin-ai-queue.php. Push: the
 * 'ai_queue' channel on /api/admin-job-sse.php (status changes on every Puget
 * job table) with a slow fallback poll only while the stream is down.
 * Loaded by admin-nav.js. */
(function () {
'use strict';
if (window.top !== window) return;               // embedded admin pages: the parent shows it
// admin-nav.js loads this from inside .app-header, before .user-info is parsed.
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
function init() {
var header = document.querySelector('.app-header');
if (!header || document.getElementById('aiq-btn')) return;

var API = '/api/admin-ai-queue.php';
var data = null, open = false, tab = 'queue', es = null, sseUp = false;
var pollTimer = null, openTimer = null, debounce = null, clsDraft = null;

var css = '' +
'#aiq-btn{display:inline-flex;align-items:center;gap:6px;margin:0 10px 0 6px;padding:4px 10px;border:1px solid #d5d8de;border-radius:14px;background:#fff;color:#444;font:12px/1.3 inherit;cursor:pointer;white-space:nowrap;max-width:250px}' +
'#aiq-btn:hover{border-color:#3498db}' +
'#aiq-btn svg{flex:none}' +
'#aiq-btn .aiq-dot{width:8px;height:8px;border-radius:50%;background:#b8bcc4;flex:none}' +
'#aiq-btn.busy .aiq-dot{background:#27ae60;animation:aiqPulse 1.6s infinite}' +
'#aiq-btn .aiq-txt{overflow:hidden;text-overflow:ellipsis}' +
'@keyframes aiqPulse{50%{opacity:.35}}' +
'#aiq-pop{position:fixed;top:52px;right:12px;width:min(860px,calc(100vw - 24px));max-height:78vh;display:flex;flex-direction:column;background:#fff;border:1px solid #ccd0d6;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.18);z-index:10050;font-size:13px;color:#333}' +
'#aiq-pop .aiq-head{display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:1px solid #e3e5e9;background:#f7f8fa;border-radius:8px 8px 0 0}' +
'#aiq-pop .aiq-head h3{margin:0;font-size:15px}' +
'#aiq-pop .aiq-sum{color:#666;flex:1}' +
'#aiq-pop .aiq-tabs button{border:0;background:none;padding:4px 8px;cursor:pointer;color:#666;border-bottom:2px solid transparent}' +
'#aiq-pop .aiq-tabs button.on{color:#3498db;border-bottom-color:#3498db}' +
'#aiq-pop .aiq-x{border:0;background:none;font-size:20px;line-height:1;cursor:pointer;color:#888}' +
'#aiq-pop .aiq-body{overflow:auto;padding:10px 14px 14px}' +
'#aiq-pop h4{margin:12px 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#777}' +
'#aiq-pop h4:first-child{margin-top:2px}' +
'#aiq-pop table{width:100%;border-collapse:collapse}' +
'#aiq-pop th{text-align:left;font-weight:600;color:#666;font-size:12px;padding:4px 6px;border-bottom:1px solid #e3e5e9;position:sticky;top:-10px;background:#fff}' +
'#aiq-pop td{padding:5px 6px;border-bottom:1px solid #f0f1f3;vertical-align:top}' +
'#aiq-pop td.n{text-align:right;white-space:nowrap;color:#555}' +
'#aiq-pop .aiq-chip{display:inline-block;padding:1px 7px;border-radius:9px;font-size:11px;white-space:nowrap;background:#eef1f5;color:#445}' +
'#aiq-pop .aiq-chip.x{background:#fdeeee;color:#a33}' +
'#aiq-pop .aiq-item{font-weight:500}' +
'#aiq-pop .aiq-sub{color:#888;font-size:12px}' +
'#aiq-pop .aiq-run{border:1px solid #e3e5e9;border-radius:6px;padding:8px 10px;margin-bottom:6px}' +
'#aiq-pop .aiq-run .aiq-row{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}' +
'#aiq-pop .aiq-run .aiq-right{margin-left:auto;color:#555;white-space:nowrap}' +
'#aiq-pop .aiq-bar{height:5px;background:#eef0f3;border-radius:3px;margin-top:6px;overflow:hidden}' +
'#aiq-pop .aiq-bar i{display:block;height:100%;background:#3498db}' +
'#aiq-pop .aiq-empty{color:#888;padding:6px 0}' +
'#aiq-pop .aiq-more td{color:#777;font-style:italic}' +
'#aiq-pop details summary{cursor:pointer;color:#777;font-size:12px;margin-top:12px}' +
'#aiq-pop input[type=number]{width:64px;padding:2px 4px}' +
'#aiq-pop td.aiq-nw{white-space:nowrap}' +
'#aiq-pop .aiq-note{color:#777;font-size:12px;margin:0 0 8px}' +
'#aiq-pop .aiq-actions{margin-top:10px;display:flex;gap:8px;align-items:center}' +
'@media (max-width:700px){#aiq-btn .aiq-txt{display:none}#aiq-pop{top:8px;right:8px;width:calc(100vw - 16px);max-height:90vh}#aiq-pop .aiq-hide-sm{display:none}}';
var st = document.createElement('style');
st.textContent = css;
document.head.appendChild(st);

var btn = document.createElement('button');
btn.id = 'aiq-btn';
btn.type = 'button';
btn.title = 'Puget AI queue';
btn.innerHTML = '<span class="aiq-dot"></span>' +
    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"/></svg>' +
    '<span class="aiq-txt">AI queue</span>';
var userInfo = header.querySelector('.user-info');
header.insertBefore(btn, userInfo || null);
btn.addEventListener('click', function (e) { e.stopPropagation(); open ? closePop() : openPop(); });

var pop = null;

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function dur(s) {
    s = Math.max(0, Math.round(s || 0));
    if (s < 60) return s + 's';
    var m = Math.round(s / 60);
    if (m < 60) return m + 'm';
    var h = Math.floor(m / 60); m = m % 60;
    if (h < 48) return h + 'h' + (m ? ' ' + m + 'm' : '');
    var d = Math.floor(h / 24); h = h % 24;
    return d + 'd' + (h ? ' ' + h + 'h' : '');
}
function ago(t) { return t ? dur(nowSec() - t) + ' ago' : ''; }
function nowSec() { return Math.floor(Date.now() / 1000); }
function clock(t) {
    if (!t) return '';
    var d = new Date(t * 1000), n = new Date();
    var hm = d.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
    if (d.toDateString() === n.toDateString()) return hm;
    return d.toLocaleDateString([], {weekday: 'short', month: 'short', day: 'numeric'}) + ' ' + hm;
}
function num(n) { return Number(n || 0).toLocaleString(); }
function chip(r) {
    var excl = false;
    (data && data.classes || []).forEach(function (c) { if (c.code === r['class']) excl = c.exclusive; });
    return '<span class="aiq-chip' + (excl ? ' x' : '') + '" title="Priority ' + esc(r.priority) + (excl ? ' · needs the whole GPU' : '') + '">' + esc(r.class_name) + '</span>';
}

/* ── data ── */
function load() {
    var lim = open ? 300 : 0;
    return fetch(API + '?limit=' + lim, {credentials: 'same-origin', cache: 'no-store'})
        .then(function (r) {
            if (r.status === 401 || r.status === 403) { btn.style.display = 'none'; stopAll(); throw new Error('auth'); }
            return r.json();
        })
        .then(function (j) { if (j && !j.error) { data = j; paintBtn(); if (open && tab === 'queue') paintPop(); } })  // never repaint the Priorities form under the cursor
        .catch(function () {});
}
function soon() { clearTimeout(debounce); debounce = setTimeout(load, 2500); }

function paintBtn() {
    var run = data.running || [], q = data.queued_total || 0;
    btn.classList.toggle('busy', run.length > 0);
    var txt;
    if (!run.length && !q) txt = 'AI idle';
    else {
        var cur = run.filter(function (r) { return r.on_gpu; })[0] || run[0];
        txt = (cur ? cur.class_name + (cur.remaining_secs != null ? ' · ' + dur(cur.remaining_secs) : '') : 'Waiting') +
              (q ? ' · ' + num(q) + ' queued' : '');
    }
    btn.querySelector('.aiq-txt').textContent = txt;
    var tip = ['Puget AI queue'];
    run.forEach(function (r) { tip.push('▶ ' + r.class_name + ': ' + r.label + (r.remaining_secs != null ? ' (~' + dur(r.remaining_secs) + ' left)' : '')); });
    if (q) tip.push(num(q) + ' queued · all done ~' + clock(data.all_done_at) + ' (' + dur(data.all_done_at - data.now) + ')');
    if (data.held_total) tip.push(num(data.held_total) + ' held');
    btn.title = tip.join('\n');
}

/* ── popover ── */
function openPop() {
    open = true;
    if (!pop) {
        pop = document.createElement('div');
        pop.id = 'aiq-pop';
        pop.addEventListener('click', function (e) { e.stopPropagation(); });
        document.body.appendChild(pop);
    }
    pop.style.display = '';
    if (data) paintPop(); else pop.innerHTML = '<div class="aiq-body aiq-empty">Loading…</div>';
    load();
    clearInterval(openTimer);
    openTimer = setInterval(function () { if (!document.hidden) load(); }, 10000);   // progress bars move without a status change
}
function closePop() {
    open = false;
    clsDraft = null;
    if (pop) pop.style.display = 'none';
    clearInterval(openTimer);
}
document.addEventListener('click', function () { if (open) closePop(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && open) closePop(); });

function paintPop() {
    var d = data, q = d.queued_total || 0;
    var sum = (d.running.length ? d.running.length + ' running' : 'Nothing running') +
              (q ? ' · ' + num(q) + ' queued · all done ~' + esc(clock(d.all_done_at)) + ' (' + dur(d.all_done_at - d.now) + ')' : '') +
              (d.held_total ? ' · ' + num(d.held_total) + ' held' : '');
    var scroll = pop.querySelector('.aiq-body') ? pop.querySelector('.aiq-body').scrollTop : 0;
    pop.innerHTML = '<div class="aiq-head"><h3>Puget AI queue</h3><span class="aiq-sum">' + sum + '</span>' +
        '<span class="aiq-tabs"><button data-t="queue" class="' + (tab === 'queue' ? 'on' : '') + '">Queue</button>' +
        '<button data-t="classes" class="' + (tab === 'classes' ? 'on' : '') + '">Priorities</button></span>' +
        '<button class="aiq-x" title="Close">&times;</button></div>' +
        '<div class="aiq-body">' + (tab === 'queue' ? queueHtml(d) : classesHtml(d)) + '</div>';
    pop.querySelector('.aiq-body').scrollTop = scroll;
    pop.querySelector('.aiq-x').onclick = closePop;
    pop.querySelectorAll('.aiq-tabs button').forEach(function (b) { b.onclick = function () { tab = b.getAttribute('data-t'); paintPop(); }; });
    if (tab === 'classes') wireClasses();
}

function queueHtml(d) {
    var h = '<h4>Running</h4>';
    if (!d.running.length) h += '<div class="aiq-empty">Nothing on the GPU.</div>';
    d.running.forEach(function (r) {
        var p = r.progress != null ? Math.max(0, Math.min(100, +r.progress)) : null;
        h += '<div class="aiq-run"><div class="aiq-row">' + chip(r) +
             '<span class="aiq-item">' + esc(r.label) + '</span><span class="aiq-sub">' + esc(r.sub) + '</span>' +
             '<span class="aiq-right">' + (r.started ? 'started ' + ago(r.started) + ' · ' : '') +
             (r.remaining_secs != null ? '~' + dur(r.remaining_secs) + ' left · done ~' + esc(clock(r.eta)) : '') + '</span></div>' +
             (r.message || !r.on_gpu ? '<div class="aiq-sub">' + esc(r.on_gpu ? r.message : 'building (not using the GPU yet)' + (r.message ? ' — ' + r.message : '')) + '</div>' : '') +
             (p != null ? '<div class="aiq-bar"><i style="width:' + p + '%"></i></div>' : '') + '</div>';
    });

    h += '<h4>Queued — next first (priority, then submitted)</h4>';
    if (!d.queued.length) h += '<div class="aiq-empty">Queue is empty.</div>';
    else {
        h += '<table><thead><tr><th>#</th><th>Type</th><th>Item</th><th class="aiq-hide-sm">Submitted</th><th>Est. time</th><th>Est. done</th></tr></thead><tbody>';
        d.queued.forEach(function (r, i) {
            h += '<tr><td class="n">' + (i + 1) + '</td><td>' + chip(r) + '</td>' +
                 '<td><div class="aiq-item">' + esc(r.label) + '</div><div class="aiq-sub">' + esc(r.sub) +
                 (r.why ? ' · ' + esc(r.why) : '') +
                 (r.message && /^Waiting for GPU/i.test(r.message) ? ' · ' + esc(r.message) : '') + '</div></td>' +
                 '<td class="n aiq-hide-sm" title="' + esc(new Date(r.submitted * 1000).toLocaleString()) + '">' + ago(r.submitted) + '</td>' +
                 '<td class="n">' + dur(r.est_secs) + '</td><td class="n" title="starts ~' + esc(clock(r.start_at)) + '">' + esc(clock(r.eta)) + '</td></tr>';
        });
        // Rows beyond the list limit, summarised per class.
        var shown = {};
        d.queued.forEach(function (r) { shown[r['class']] = (shown[r['class']] || 0) + 1; });
        (d.classes || []).forEach(function (c) {
            var b = d.by_class[c.code];
            if (!b || !b.count) return;
            var rest = b.count - (shown[c.code] || 0);
            if (rest > 0) h += '<tr class="aiq-more"><td></td><td colspan="3">+' + num(rest) + ' more ' + esc(c.name) + ' jobs</td>' +
                               '<td class="n">' + dur(b.est_secs) + ' total</td><td class="n">' + esc(clock(b.eta)) + '</td></tr>';
        });
        h += '</tbody></table>';
    }

    if (d.held && d.held.length) {
        h += '<details><summary>Held — not startable yet (' + num(d.held_total) + ')</summary><table><tbody>';
        d.held.forEach(function (r) {
            h += '<tr><td>' + chip(r) + '</td><td><div class="aiq-item">' + esc(r.label) + '</div><div class="aiq-sub">' + esc(r.sub) + '</div></td>' +
                 '<td class="aiq-sub">' + esc(r.why) + '</td><td class="n">' + ago(r.submitted) + '</td></tr>';
        });
        h += '</tbody></table></details>';
    }
    return h;
}

function classesHtml(d) {
    if (!clsDraft) clsDraft = (d.classes || []).map(function (c) { return {code: c.code, priority: c.priority, exclusive: c.exclusive, est_secs: c.est_secs}; });
    var byCode = {};
    clsDraft.forEach(function (c) { byCode[c.code] = c; });
    var h = '<p class="aiq-note">The next job is picked by <b>priority (highest first)</b>, then by <b>time submitted (oldest first)</b>. ' +
            '"Whole GPU" jobs never run beside anything else; the others (resident TTS / STT engines) can share the card. ' +
            'Running jobs are never interrupted. TTS chapter builds and previews start as soon as they are queued.</p>' +
            '<table><thead><tr><th>Type</th><th class="aiq-hide-sm">What it is</th><th>Priority</th><th>Whole GPU</th><th>Default est.</th><th>Queued</th></tr></thead><tbody>';
    (d.classes || []).forEach(function (c) {
        var x = byCode[c.code] || c, b = d.by_class[c.code] || {};
        h += '<tr><td class="aiq-item">' + esc(c.name) + '</td><td class="aiq-sub aiq-hide-sm">' + esc(c.description) + '</td>' +
             '<td><input type="number" min="0" max="1000" data-c="' + esc(c.code) + '" data-f="priority" value="' + esc(x.priority) + '"></td>' +
             '<td><input type="checkbox" data-c="' + esc(c.code) + '" data-f="exclusive"' + (x.exclusive ? ' checked' : '') + '></td>' +
             '<td class="aiq-nw"><input type="number" min="1" max="86400" data-c="' + esc(c.code) + '" data-f="est_secs" value="' + esc(x.est_secs) + '" title="seconds — used only until there is run history"> s</td>' +
             '<td class="n">' + num(b.count || 0) + (b.held ? ' <span class="aiq-sub">+' + num(b.held) + ' held</span>' : '') + '</td></tr>';
    });
    h += '</tbody></table><div class="aiq-actions"><button class="btn btn-primary btn-sm" id="aiq-save">Save priorities</button>' +
         '<button class="btn btn-secondary btn-sm" id="aiq-reset">Undo changes</button><span class="aiq-sub" id="aiq-msg"></span></div>';
    return h;
}

function wireClasses() {
    pop.querySelectorAll('input[data-c]').forEach(function (inp) {
        inp.addEventListener('input', function () {
            var code = inp.getAttribute('data-c'), f = inp.getAttribute('data-f');
            clsDraft.forEach(function (c) {
                if (c.code !== code) return;
                c[f] = f === 'exclusive' ? inp.checked : parseInt(inp.value, 10);
            });
        });
        inp.addEventListener('change', function () { inp.dispatchEvent(new Event('input')); });
    });
    pop.querySelector('#aiq-reset').onclick = function () { clsDraft = null; paintPop(); };
    pop.querySelector('#aiq-save').onclick = function () {
        var orig = {};
        (data.classes || []).forEach(function (c) { orig[c.code] = c; });
        var changed = clsDraft.filter(function (c) {
            var o = orig[c.code] || {};
            return o.priority !== c.priority || o.exclusive !== c.exclusive || o.est_secs !== c.est_secs;
        });
        var msg = pop.querySelector('#aiq-msg');
        if (!changed.length) { msg.textContent = 'Nothing changed.'; return; }
        var bad = changed.filter(function (c) { return isNaN(c.priority) || isNaN(c.est_secs); });
        if (bad.length) { msg.textContent = 'Enter a number for every priority and estimate.'; return; }
        msg.textContent = 'Saving…';
        fetch(API, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'save_classes', classes: changed})})
            .then(function (r) { return window.safeJson ? window.safeJson(r) : r.json(); })
            .then(function (j) {
                if (j.error) { msg.textContent = j.error; return; }
                clsDraft = null;
                data.classes = j.classes;
                load().then(function () { paintPop(); var m = pop.querySelector('#aiq-msg'); if (m) m.textContent = 'Saved ' + j.updated + '.'; });
            })
            .catch(function (e) { msg.textContent = 'Save failed: ' + e.message; });
    };
}

/* ── push + fallback poll ── */
function startSse() {
    if (es || !window.EventSource) return;
    es = new EventSource('/api/admin-job-sse.php?channel=ai_queue');
    es.addEventListener('ready', function () { sseUp = true; setPoll(); });
    es.addEventListener('job', soon);
    es.addEventListener('reconnect', function () { stopSse(); startSse(); });
    es.onerror = function () { sseUp = false; setPoll(); stopSse(); setTimeout(function () { if (!document.hidden) startSse(); }, 5000); };
}
function stopSse() { if (es) { es.close(); es = null; } }
function setPoll() {
    clearInterval(pollTimer);
    // Live: a slow tick keeps ETAs honest; stream down: the fallback poll.
    pollTimer = setInterval(function () { if (!document.hidden) load(); }, sseUp ? 120000 : 45000);
}
function stopAll() { stopSse(); clearInterval(pollTimer); clearInterval(openTimer); }

document.addEventListener('visibilitychange', function () {
    if (document.hidden) { stopSse(); sseUp = false; }
    else { load(); startSse(); }
});

load();
setPoll();
startSse();
}
})();
