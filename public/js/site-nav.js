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
                // Ensure logo is wrapped in a link to home
                if (logoImg.parentElement && logoImg.parentElement.tagName === 'A') {
                    logoImg.parentElement.style.display = 'block';
                    logoImg.parentElement.style.lineHeight = '0';
                    if (!logoImg.parentElement.getAttribute('href')) logoImg.parentElement.href = '/';
                } else {
                    // Wrap in <a href="/">
                    var logoLink = document.createElement('a');
                    logoLink.href = '/';
                    logoLink.style.display = 'block';
                    logoLink.style.lineHeight = '0';
                    logoImg.parentElement.insertBefore(logoLink, logoImg);
                    logoLink.appendChild(logoImg);
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

            // Page Heading settings (apply to .content h1)
            var root = document.documentElement;
            if (data.page_heading_text_color)    root.style.setProperty('--heading-color', data.page_heading_text_color);
            if (data.page_heading_text_size)     root.style.setProperty('--heading-size', data.page_heading_text_size + 'em');
            if (data.page_heading_bg_opacity) {
                var op = parseFloat(data.page_heading_bg_opacity);
                root.style.setProperty('--heading-bg-opacity', String(op));
                root.style.setProperty('--heading-bg', 'rgba(0,0,0,' + op + ')');
            }
            if (data.page_heading_margin_top)    root.style.setProperty('--heading-margin-top', data.page_heading_margin_top + 'px');
            if (data.page_heading_margin_bottom) root.style.setProperty('--heading-margin-bottom', data.page_heading_margin_bottom + 'px');
            if (data.page_heading_padding_top)   root.style.setProperty('--heading-padding-top', data.page_heading_padding_top + 'px');
            if (data.page_heading_padding_bottom) root.style.setProperty('--heading-padding-bottom', data.page_heading_padding_bottom + 'px');
            // Global search bar (rendered just below the site header) shares
            // the page-heading group in admin so the operator can tune both
            // bands together. bg accepts any rgba()/hex; height is the
            // total band height in px. Set both as CSS vars on <html>
            // (used by the prototype's stylesheet) AND directly on the
            // .search-band element if present — the direct path is a
            // belt-and-suspenders fallback in case of any CSS cascade
            // issue that would silently swallow the var().
            if (data.page_heading_search_bg_color)   root.style.setProperty('--search-band-bg',   data.page_heading_search_bg_color);
            if (data.page_heading_search_text_color) root.style.setProperty('--search-band-text', data.page_heading_search_text_color);
            if (data.page_heading_search_height)     root.style.setProperty('--search-band-height', data.page_heading_search_height + 'px');
            var bandEl = document.querySelector('.search-band');
            if (bandEl) {
                if (data.page_heading_search_bg_color)   bandEl.style.background = data.page_heading_search_bg_color;
                // `color` cascades to the filter-group labels inside the
                // band, which inherit (see prototype CSS); inputs/selects
                // keep their own colors because they reset color on
                // form-control elements.
                if (data.page_heading_search_text_color) bandEl.style.color      = data.page_heading_search_text_color;
                if (data.page_heading_search_height)     bandEl.style.minHeight  = data.page_heading_search_height + 'px';
            }

            reveal();
        })
        .catch(reveal);

    // Load the dedicated site-wide search bar script. Doing it from
    // site-nav.js means we do not have to add a second <script> tag to
    // the 31 pages that already include site-nav.js. Cache-busting via
    // ?v= matches the convention used elsewhere on the site.
    var s = document.createElement('script');
    s.src = '/js/site-search.js?v=3';
    s.async = false;
    document.head.appendChild(s);
})();
