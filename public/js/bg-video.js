(function() {
    // Skip video loading for bots/crawlers — they can't render video and cause resource errors
    var ua = navigator.userAgent || '';
    if (/bot|crawl|spider|Googlebot|bingbot|Slurp|DuckDuckBot|Baiduspider|YandexBot|facebookexternalhit|Twitterbot|LinkedInBot|WhatsApp|Bytespider|meta-webindexer/i.test(ua)) {
        return;
    }

    // Apply default header/footer background color from config
    fetch('/api/site-config.php').then(function(r){return r.json();}).then(function(cfg) {
        if (cfg['header-default-bg']) {
            var bg = '#' + cfg['header-default-bg'];
            document.querySelectorAll('header, footer').forEach(function(el) {
                el.style.backgroundColor = bg;
            });
        }
    }).catch(function(){});

    var STORAGE_KEY = 'yy_bg_video';
    var THUMB_KEY = 'yy_bg_thumb';
    var cached = sessionStorage.getItem(STORAGE_KEY);
    var cachedThumb = sessionStorage.getItem(THUMB_KEY);

    function applyThumb(src) {
        var els = document.querySelectorAll('.header-video, .footer-video');
        for (var i = 0; i < els.length; i++) {
            els[i].setAttribute('poster', src);
        }
    }

    var MP4_KEY = 'yy_bg_mp4';

    function applyVideo(webmSrc, mp4Src) {
        var videos = document.querySelectorAll('.header-video, .footer-video');
        for (var i = 0; i < videos.length; i++) {
            // Remove existing sources
            var existing = videos[i].querySelectorAll('source');
            for (var j = 0; j < existing.length; j++) existing[j].remove();
            // Add WebM source (preferred)
            var webmEl = document.createElement('source');
            webmEl.setAttribute('src', webmSrc);
            webmEl.setAttribute('type', 'video/webm');
            videos[i].appendChild(webmEl);
            // Add MP4 fallback (Safari/iOS)
            if (mp4Src) {
                var mp4El = document.createElement('source');
                mp4El.setAttribute('src', mp4Src);
                mp4El.setAttribute('type', 'video/mp4');
                videos[i].appendChild(mp4El);
            }
            videos[i].load();
        }
    }

    if (cached) {
        if (cachedThumb) applyThumb(cachedThumb);
        applyVideo(cached, sessionStorage.getItem(MP4_KEY) || '');
    } else {
        fetch('/api/public-backgrounds.php')
            .then(function(r) { return r.json(); })
            .then(function(items) {
                if (items.length) {
                    var pick = items[Math.floor(Math.random() * items.length)];
                    sessionStorage.setItem(STORAGE_KEY, pick.video);
                    sessionStorage.setItem(THUMB_KEY, pick.thumb);
                    sessionStorage.setItem(MP4_KEY, pick.mp4 || '');
                    applyThumb(pick.thumb);
                    applyVideo(pick.video, pick.mp4 || '');
                }
            })
            .catch(function() {});
    }
})();
