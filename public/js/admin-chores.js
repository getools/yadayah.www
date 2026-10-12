// Chores tabs are the real admin pages opened with ?chores=<tab> (no iframe):
// the page keeps the admin nav, shows the Chores title + tab bar instead of its
// own title and tabs, and scrolls normally. Loaded in <head> of admin-feeds,
// admin-ask, admin-books and admin-glossary; a no-op without ?chores=.
(function () {
    var m = /[?&]chores=([a-z0-9]+)/.exec(location.search);
    if (!m) return;

    // No .html in the links: the .html form 301s to http://.
    var TABS = [
        { tab: 'mp3',         label: 'MP3s',        href: '/admin-feeds?chores=mp3',         code: 'chores.mp3',         title: 'Feeds items that have no MP3 yet' },
        { tab: 'transcripts', label: 'Transcripts', href: '/admin-feeds?chores=transcripts', code: 'chores.transcripts', title: 'Feeds items that have an MP3, oldest first' },
        { tab: 'ask',         label: 'Ask Yada',    href: '/admin-ask?chores=ask',           code: 'chores.ask',         title: 'Ask → Log' },
        { tab: 'edit',        label: 'Book Edit',   href: '/admin-books?chores=edit#edit',   code: 'chores.edit',        title: 'Books → Edit' },
        { tab: 'words',       label: 'Words',       href: '/admin-glossary?chores=words',    code: 'chores.words',       title: 'Glossary → Words' }
    ];
    var tab = m[1];
    if (!TABS.some(function (t) { return t.tab === tab; })) return;

    // A Chores page cached from the iframe days may still frame this one.
    try { if (window.self !== window.top) { window.top.location.replace(location.href); return; } } catch (e) {}

    window.CHORES_TAB = tab;
    var html = document.documentElement;
    html.classList.add('chores', 'chores-' + tab);
    // session-state.js keys on the URL path, so it would load this user's
    // Feeds filters into the Chores tab (and save Chores' back). Chores
    // keeps its own state via adminUI instead.
    html.setAttribute('data-no-persist', '');
    try { localStorage.setItem('admin-chores:tab', tab); } catch (e) {}

    var css = document.createElement('style');
    css.textContent =
        '.chores-tabs { display: flex; gap: 0; border-bottom: 2px solid #e0e0e0; margin-bottom: 12px; }' +
        '.chores-tabs a { padding: 10px 24px; font-size: 0.95rem; font-weight: 600; color: #666; text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: color 0.15s, border-color 0.15s; }' +
        '.chores-tabs a:hover { color: #31345A; }' +
        '.chores-tabs a.active { color: #31345A; border-bottom-color: #31345A; }';
    document.head.appendChild(css);

    function build() {
        // The nav highlights the page's own tab (Feeds, Books, ...); this is Chores.
        document.querySelectorAll('.nav-tabs .nav-tab').forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('href') === 'admin-chores.html');
        });
        var header = document.querySelector('#app > .app-header');
        if (!header || document.querySelector('.chores-tabs')) return;
        var h1 = document.createElement('h1');
        h1.className = 'chores-title';
        h1.textContent = 'Chores';
        var bar = document.createElement('div');
        bar.className = 'chores-tabs';
        bar.innerHTML = TABS.map(function (t) {
            return '<a href="' + t.href + '" data-tab="' + t.tab + '" title="' + t.title + '"'
                + (t.tab === tab ? ' class="active"' : '') + '>' + t.label + '</a>';
        }).join('');
        header.parentNode.insertBefore(bar, header.nextSibling);
        header.parentNode.insertBefore(h1, bar);
        document.title = 'Chores - Yada Yah';

        // Hide tabs the user can't open; leave a disallowed tab for the first allowed one.
        fetch('/api/auth.php', { credentials: 'include' }).then(function (r) { return r.json(); }).then(function (d) {
            var pages = (d && d.authenticated && d.pages) || null;
            if (!pages) return;
            var allowed = TABS.filter(function (t) { return pages[t.code] !== false; });
            TABS.forEach(function (t) {
                var a = bar.querySelector('a[data-tab="' + t.tab + '"]');
                if (a && pages[t.code] === false) a.style.display = 'none';
            });
            if (pages['chores.' + tab] === false && allowed.length) location.replace(allowed[0].href);
        }).catch(function () {});
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build);
    else build();
})();
