(function() {
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

    function applyVideo(src) {
        var videos = document.querySelectorAll('.header-video, .footer-video');
        for (var i = 0; i < videos.length; i++) {
            var source = videos[i].querySelector('source');
            if (source) {
                source.setAttribute('src', src);
            } else {
                source = document.createElement('source');
                source.setAttribute('src', src);
                source.setAttribute('type', 'video/webm');
                videos[i].appendChild(source);
            }
            videos[i].load();
        }
    }

    if (cached) {
        if (cachedThumb) applyThumb(cachedThumb);
        applyVideo(cached);
    } else {
        fetch('/api/public-backgrounds.php')
            .then(function(r) { return r.json(); })
            .then(function(items) {
                if (items.length) {
                    var pick = items[Math.floor(Math.random() * items.length)];
                    sessionStorage.setItem(STORAGE_KEY, pick.video);
                    sessionStorage.setItem(THUMB_KEY, pick.thumb);
                    applyThumb(pick.thumb);
                    applyVideo(pick.video);
                }
            })
            .catch(function() {});
    }
})();
