/**
 * VideoComments — collapsible comments section for video lightboxes/modals.
 *
 * Usage:
 *   1. Include this script on the page
 *   2. Add <div id="video-comments"></div> inside your lightbox/modal
 *   3. Call VideoComments.open(videoId, source) when lightbox opens
 *   4. Call VideoComments.close() when lightbox closes
 */
window.VideoComments = (function() {
    var API = '/api/video-comments.php';
    var currentVideoId = null;
    var currentSource = null;
    var currentUser = null;
    var expanded = false;

    function esc(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function timeAgo(dt) {
        var now = Date.now();
        var then = new Date(dt).getTime();
        var diff = Math.floor((now - then) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
        return new Date(dt).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function getContainer() {
        return document.getElementById('video-comments');
    }

    function render(comments) {
        var el = getContainer();
        if (!el) return;

        var count = comments.length;
        var toggleLabel = expanded
            ? 'Comments (' + count + ') &#9650;'
            : 'Comments (' + count + ') &#9660;';

        var html = '<div class="vc-toggle" onclick="VideoComments.toggle()">' + toggleLabel + '</div>';

        if (expanded) {
            html += '<div class="vc-body">';

            // Comment form
            if (currentUser) {
                html += '<div class="vc-form">'
                    + '<textarea id="vc-input" class="vc-input" placeholder="Add a comment..." rows="2" maxlength="2000"></textarea>'
                    + '<div class="vc-form-actions">'
                    + '<button class="vc-submit" onclick="VideoComments.submit()">Post</button>'
                    + '</div></div>';
            } else {
                html += '<div class="vc-signin">Sign in to comment: '
                    + '<a href="javascript:void(0)" onclick="CommunityAuth.openLoginModal(\'login\')">Sign In</a>'
                    + '</div>';
            }

            // Comment list
            if (count === 0) {
                html += '<div class="vc-empty">No comments yet. Be the first!</div>';
            } else {
                for (var i = 0; i < comments.length; i++) {
                    var c = comments[i];
                    var isOwn = currentUser && currentUser.account_key === c.user_key;
                    var avatarSrc = c.user_avatar ? '/u/avatars/' + esc(c.user_avatar) : '';
                    var avatarHtml = avatarSrc
                        ? '<img class="vc-avatar" src="' + avatarSrc + '" alt="">'
                        : '<div class="vc-avatar vc-avatar-default">' + esc((c.user_display_name || '?')[0]).toUpperCase() + '</div>';

                    html += '<div class="vc-comment" data-key="' + c.comment_key + '">'
                        + avatarHtml
                        + '<div class="vc-comment-body">'
                        + '<div class="vc-meta">'
                        + '<span class="vc-author">' + esc(c.user_display_name) + '</span>'
                        + '<span class="vc-time">' + timeAgo(c.comment_dtime) + '</span>'
                        + (isOwn ? '<a class="vc-delete" href="javascript:void(0)" onclick="VideoComments.remove(' + c.comment_key + ')">delete</a>' : '')
                        + '</div>'
                        + '<div class="vc-text">' + esc(c.comment_text) + '</div>'
                        + '</div></div>';
                }
            }

            html += '</div>';
        }

        el.innerHTML = html;
    }

    function load() {
        if (!currentVideoId) return;
        fetch(API + '?video_id=' + encodeURIComponent(currentVideoId) + '&source=' + encodeURIComponent(currentSource), { credentials: 'include' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                currentUser = data.user || null;
                render(data.comments || []);
            })
            .catch(function() {
                render([]);
            });
    }

    return {
        open: function(videoId, source) {
            currentVideoId = videoId;
            currentSource = source || 'youtube';
            expanded = false;
            load();
        },

        close: function() {
            currentVideoId = null;
            expanded = false;
            var el = getContainer();
            if (el) el.innerHTML = '';
        },

        toggle: function() {
            expanded = !expanded;
            load();
        },

        submit: function() {
            var input = document.getElementById('vc-input');
            if (!input) return;
            var text = input.value.trim();
            if (!text) return;

            var btn = document.querySelector('.vc-submit');
            if (btn) { btn.disabled = true; btn.textContent = 'Posting...'; }

            fetch(API + '?video_id=' + encodeURIComponent(currentVideoId) + '&source=' + encodeURIComponent(currentSource), {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ comment_text: text })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) { alert(data.error); return; }
                load();
            })
            .catch(function() { alert('Failed to post comment'); })
            .finally(function() {
                if (btn) { btn.disabled = false; btn.textContent = 'Post'; }
            });
        },

        remove: function(commentKey) {
            if (!confirm('Delete this comment?')) return;
            fetch(API + '?video_id=' + encodeURIComponent(currentVideoId) + '&source=' + encodeURIComponent(currentSource), {
                method: 'DELETE',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ comment_key: commentKey })
            })
            .then(function() { load(); });
        }
    };
})();
