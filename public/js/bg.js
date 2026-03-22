(function() {
    fetch('/api/active-background.php')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.file) return;
            var videoSrc = '/' + d.file;
            var thumbSrc = d.thumb ? '/' + d.thumb : null;

            // Update header
            var hv = document.querySelector('header .header-video');
            if (hv) {
                var hs = hv.querySelector('source');
                if (hs) { hs.src = videoSrc; hs.type = 'video/webm'; }
                else { hs = document.createElement('source'); hs.src = videoSrc; hs.type = 'video/webm'; hv.appendChild(hs); }
                hv.load();
            }
            // Update footer
            var fv = document.querySelector('footer .footer-video');
            if (fv) {
                var fs = fv.querySelector('source');
                if (fs) { fs.src = videoSrc; fs.type = 'video/webm'; }
                else { fs = document.createElement('source'); fs.src = videoSrc; fs.type = 'video/webm'; fv.appendChild(fs); }
                fv.load();
            }
            // Update CSS background fallbacks
            if (thumbSrc) {
                var header = document.querySelector('header');
                if (header) header.style.backgroundImage = "url('" + thumbSrc + "')";
                var footer = document.querySelector('footer');
                if (footer) footer.style.backgroundImage = "url('" + thumbSrc + "')";
            }
        })
        .catch(function() {});
})();
