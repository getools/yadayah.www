(function () {
    // Inject hiding style immediately (before first paint) to prevent flicker
    var _style = document.createElement('style');
    _style.textContent = 'header .header-logos img, .sub-toolbar { visibility: hidden; }';
    document.head.appendChild(_style);

    var path = window.location.pathname.toLowerCase().replace(/\.html$/, '');

    function esc(s) {
        return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function isActive(url) {
        if (!url) return false;
        var u = url.toLowerCase().replace(/\.html$/, '');
        if (u === '/') return path === '/' || path === '/index';
        return path === u || path.startsWith(u + '/');
    }

    function renderLinks(items) {
        return items.map(function (item) {
            var active = isActive(item.url) ? ' class="active"' : '';
            return '<a href="' + esc(item.url) + '"' + active + '>' + esc(item.title) + '</a>';
        }).join('\n');
    }

    function reveal() {
        if (_style.parentNode) _style.parentNode.removeChild(_style);
    }

    fetch('/api/page-nav.php')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            // Logo
            var logoImg     = document.querySelector('header .header-logos img');
            var headerEl    = document.querySelector('header');
            var headerLogos = document.querySelector('header .header-logos');
            if (logoImg) {
                if (data.logo) logoImg.src = data.logo;
                if (data.logo_height) {
                    logoImg.style.height = data.logo_height + 'px';
                    logoImg.style.width  = 'auto';
                }
                logoImg.style.padding = '0';
                logoImg.style.margin  = '0';
                logoImg.style.display = 'block'; // eliminate inline baseline gap
                // Also fix the parent <a> tag if present
                if (logoImg.parentElement && logoImg.parentElement.tagName === 'A') {
                    logoImg.parentElement.style.display = 'block';
                    logoImg.parentElement.style.lineHeight = '0';
                }
            }
            if (headerEl)    headerEl.style.padding    = '0';
            if (headerLogos) {
                headerLogos.style.padding      = '0';
                headerLogos.style.marginTop    = (data.logo_margin_top    || 0) + 'px';
                headerLogos.style.marginBottom = (data.logo_margin_bottom || 0) + 'px';
            }

            // Title prefix
            if (data.title_prefix) {
                var pageTitle = document.title.trim();
                document.title = pageTitle
                    ? data.title_prefix + pageTitle
                    : data.title_prefix.replace(/[\s\-–]+$/, '').trim();
            }

            // Main header nav — split into two rows at midpoint
            var mainEl = document.querySelector('header nav');
            if (mainEl && data.main && data.main.length) {
                var items = data.main;
                var mid = Math.ceil(items.length / 2);
                var html = '<div class="nav-row">' + renderLinks(items.slice(0, mid)) + '</div>';
                if (items.length > mid) {
                    html += '<div class="nav-row">' + renderLinks(items.slice(mid)) + '</div>';
                }
                mainEl.innerHTML = html;
            }

            // Main nav — text size, color, margins
            var mainNav = document.querySelector('header nav');
            if (mainNav) {
                if (data.toolbar_main_text_size)  mainNav.style.fontSize     = data.toolbar_main_text_size + 'em';
                if (data.toolbar_main_text_color) mainNav.style.color        = '#' + data.toolbar_main_text_color;
                mainNav.style.marginTop    = (data.toolbar_main_margin_top    || 0) + 'px';
                mainNav.style.marginBottom = (data.toolbar_main_margin_bottom || 0) + 'px';
            }

            // Sub-toolbar — text size, colors, margins, links
            var subEl = document.querySelector('.sub-toolbar');
            if (subEl) {
                if (data.toolbar_sub_bg_color)   document.documentElement.style.setProperty('--toolbar-bg',   '#' + data.toolbar_sub_bg_color);
                if (data.toolbar_sub_text_color) document.documentElement.style.setProperty('--toolbar-text', '#' + data.toolbar_sub_text_color);
                if (!subEl.hasAttribute('data-static') && data.sub && data.sub.length) subEl.innerHTML = renderLinks(data.sub);
                if (data.toolbar_sub_text_size)  subEl.style.fontSize = data.toolbar_sub_text_size + 'em';
                subEl.style.paddingTop    = (data.toolbar_sub_margin_top    || 0) + 'px';
                subEl.style.paddingBottom = (data.toolbar_sub_margin_bottom || 0) + 'px';
            }

            // Contact link obfuscation
            document.querySelectorAll('[data-contact]').forEach(function (el) {
                el.href = 'mailto:contact' + '\x40' + 'YadaYah.com';
            });

            reveal();
        })
        .catch(reveal);
})();
