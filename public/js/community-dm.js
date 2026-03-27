/* ── community-dm.js ── Direct messages: inbox, thread, compose ── */
(function() {
'use strict';

window.CommunityDM = {};

// ── Load inbox ──
CommunityDM.loadInbox = function() {
    Community.showView('view-messages');
    var el = document.getElementById('view-messages');
    if (!Community.currentUser) {
        el.innerHTML = '<div class="empty-state"><a href="#" onclick="CommunityAuth.openLoginModal(\'login\');return false;">Sign in</a> to see your messages.</div>';
        return;
    }
    el.innerHTML = '<div class="empty-state">Loading...</div>';

    Community.api('/api/community-dm.php?threads=1').then(function(data) {
        var threads = data.threads || [];
        var html = '<div class="section-header">'
            + '<h2 class="section-title">&#128172; Messages</h2>'
            + '<button class="btn btn-primary btn-sm" onclick="CommunityDM.showCompose()">New Message</button>'
            + '</div>';

        if (!threads.length) {
            html += '<div class="empty-state">No messages yet</div>';
        } else {
            html += '<div class="dm-thread-list">';
            threads.forEach(function(t) {
                var unread = t.unread_count > 0;
                var otherUser = t.other_user || {};
                html += '<div class="dm-thread-item' + (unread ? ' unread' : '') + '" onclick="window.location.hash=\'#message/' + t.thread_key + '\'">'
                    + Community.avatarHtml(otherUser.user_avatar, otherUser.user_display_name, 'dm-avatar', otherUser.user_last_active_dtime)
                    + '<div class="dm-thread-info">'
                    + '<div class="dm-thread-name">' + Community.esc(otherUser.user_display_name || 'Unknown') + (unread ? ' <span class="dm-unread-badge">' + t.unread_count + '</span>' : '') + '</div>'
                    + '<div class="dm-thread-preview">' + Community.esc(t.last_message || '') + '</div>'
                    + '</div>'
                    + '<div class="dm-thread-time">' + Community.timeAgo(t.last_message_dtime) + '</div>'
                    + '</div>';
            });
            html += '</div>';
        }
        el.innerHTML = html;
    });
};

// ── Load thread ──
CommunityDM.loadThread = function(threadKey) {
    Community.showView('view-messages');
    var el = document.getElementById('view-messages');
    if (!Community.currentUser) {
        el.innerHTML = '<div class="empty-state">Sign in to view messages.</div>';
        return;
    }
    el.innerHTML = '<div class="empty-state">Loading...</div>';

    Community.api('/api/community-dm.php?thread=' + threadKey).then(function(data) {
        var messages = data.messages || [];
        var thread = data.thread || {};
        var otherUser = thread.other_user || {};

        var html = '<a href="#messages" class="back-link">&larr; Back to inbox</a>';
        html += '<div class="dm-thread-header">'
            + Community.avatarHtml(otherUser.user_avatar, otherUser.user_display_name, 'dm-header-avatar', otherUser.user_last_active_dtime)
            + '<span class="dm-header-name">' + Community.esc(otherUser.user_display_name || 'Unknown') + '</span>'
            + '</div>';

        html += '<div class="dm-messages" id="dm-messages">';
        messages.forEach(function(m) {
            var isMine = m.user_key === Community.currentUser.user_key;
            html += '<div class="dm-message' + (isMine ? ' mine' : '') + '">'
                + '<div class="dm-message-bubble">' + Community.formatBody(m.message_body) + '</div>'
                + '<div class="dm-message-time">' + Community.timeAgo(m.message_dtime) + '</div>'
                + '</div>';
        });
        html += '</div>';

        // Reply form
        html += '<div class="dm-compose">'
            + '<textarea id="dm-reply-body" placeholder="Type a message..." rows="2"></textarea>'
            + '<button class="btn btn-primary" onclick="CommunityDM.sendMessage(' + threadKey + ')">Send</button>'
            + '</div>';

        el.innerHTML = html;

        // Scroll to bottom
        var msgContainer = document.getElementById('dm-messages');
        if (msgContainer) msgContainer.scrollTop = msgContainer.scrollHeight;

        // Enter to send
        var input = document.getElementById('dm-reply-body');
        if (input) {
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    CommunityDM.sendMessage(threadKey);
                }
            });
            input.focus();
        }
    });
};

// ── Send message ──
CommunityDM.sendMessage = function(threadKey) {
    var body = document.getElementById('dm-reply-body').value.trim();
    if (!body) return;
    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'send', thread_key: threadKey, body: body }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        CommunityDM.loadThread(threadKey);
    });
};

// ── Show compose new message ──
CommunityDM.showCompose = function(recipientKey) {
    Community.showView('view-messages');
    var el = document.getElementById('view-messages');

    var html = '<a href="#messages" class="back-link">&larr; Back to inbox</a>';
    html += '<div class="compose-form">'
        + '<h3 class="compose-title">New Message</h3>'
        + '<div class="dm-recipient-search">'
        + '<input id="dm-recipient" placeholder="Search for a user..." autocomplete="off"' + (recipientKey ? ' data-key="' + recipientKey + '"' : '') + '>'
        + '<div id="dm-recipient-results" class="mention-dropdown" style="display:none"></div>'
        + '</div>'
        + '<textarea id="dm-new-body" placeholder="Your message..." rows="4"></textarea>'
        + '<button class="btn btn-primary" onclick="CommunityDM.submitNew()">Send Message</button>'
        + '</div>';
    el.innerHTML = html;

    // Recipient search
    var input = document.getElementById('dm-recipient');
    var timeout;
    input.addEventListener('input', function() {
        clearTimeout(timeout);
        var q = this.value.trim();
        if (q.length < 2) {
            document.getElementById('dm-recipient-results').style.display = 'none';
            return;
        }
        timeout = setTimeout(function() {
            Community.api('/api/community-members.php?q=' + encodeURIComponent(q) + '&limit=5').then(function(data) {
                var members = data.members || [];
                var dd = document.getElementById('dm-recipient-results');
                if (!members.length) { dd.style.display = 'none'; return; }
                var h = '';
                members.forEach(function(m) {
                    h += '<div class="mention-item" data-key="' + m.user_key + '" data-name="' + Community.esc(m.user_display_name) + '">'
                        + Community.avatarHtml(m.user_avatar, m.user_display_name, 'mention-avatar', m.user_last_active_dtime)
                        + '<span>' + Community.esc(m.user_display_name) + '</span></div>';
                });
                dd.innerHTML = h;
                dd.style.display = '';
                dd.querySelectorAll('.mention-item').forEach(function(item) {
                    item.addEventListener('click', function() {
                        input.value = this.getAttribute('data-name');
                        input.setAttribute('data-key', this.getAttribute('data-key'));
                        dd.style.display = 'none';
                    });
                });
            });
        }, 200);
    });

    // If recipientKey given, prefill
    if (recipientKey) {
        Community.api('/api/community-members.php?user_key=' + recipientKey).then(function(data) {
            var m = data.member || data;
            if (m && m.user_display_name) {
                input.value = m.user_display_name;
                input.setAttribute('data-key', recipientKey);
            }
        });
    }
};

// ── Start new DM from profile card ──
CommunityDM.startNew = function(userKey) {
    CommunityDM.showCompose(userKey);
};

// ── Submit new thread ──
CommunityDM.submitNew = function() {
    var recipientKey = document.getElementById('dm-recipient').getAttribute('data-key');
    var body = document.getElementById('dm-new-body').value.trim();
    if (!recipientKey) { alert('Please select a recipient'); return; }
    if (!body) { alert('Message cannot be empty'); return; }

    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'new_thread', recipient_key: parseInt(recipientKey), body: body }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        if (data.thread_key) {
            window.location.hash = '#message/' + data.thread_key;
        } else {
            window.location.hash = '#messages';
        }
    });
};

})();
