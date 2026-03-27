/* ── community-dm.js ── Direct messages: inbox, thread, compose, popover ── */
(function() {
'use strict';

window.CommunityDM = {};

var _activeThreadKey = null;

// ── Media upload helper (shared across all compose areas) ──
function uploadMedia(file, callback) {
    if (!file) return;
    var isImage = file.type.startsWith('image/');
    var isVideo = file.type.startsWith('video/');
    if (!isImage && !isVideo) { alert('Only images and videos are supported'); return; }
    if (file.size > 20 * 1024 * 1024) { alert('File too large (max 20MB)'); return; }

    if (isImage) {
        var fd = new FormData();
        fd.append('image', file);
        fetch('/api/community-image-upload.php', { method: 'POST', credentials: 'include', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.url) callback('![image](' + data.url + ')');
                else alert(data.error || 'Upload failed');
            });
    } else {
        // Video: upload as image endpoint (backend will need to handle, or use same path)
        var fd = new FormData();
        fd.append('video', file);
        fetch('/api/community-image-upload.php', { method: 'POST', credentials: 'include', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.url) callback('[video](' + data.url + ')');
                else alert(data.error || 'Upload failed');
            });
    }
}

// Wire up a compose area with media buttons, paste, and drag-drop
function wireCompose(textareaId, sendFn) {
    var ta = document.getElementById(textareaId);
    if (!ta) return;

    // Enter to send (Shift+Enter for newline)
    ta.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendFn();
        }
    });

    // Paste images/videos
    ta.addEventListener('paste', function(e) {
        var items = (e.clipboardData || {}).items || [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') === 0) {
                e.preventDefault();
                uploadMedia(items[i].getAsFile(), function(md) { insertAtCursor(ta, md); });
                return;
            }
        }
    });

    // Drag and drop
    ta.addEventListener('dragover', function(e) { e.preventDefault(); ta.style.borderColor = '#31345A'; });
    ta.addEventListener('dragleave', function() { ta.style.borderColor = ''; });
    ta.addEventListener('drop', function(e) {
        e.preventDefault();
        ta.style.borderColor = '';
        var files = e.dataTransfer.files;
        for (var i = 0; i < files.length; i++) {
            uploadMedia(files[i], function(md) { insertAtCursor(ta, md); });
        }
    });

    // Media button click
    var btn = document.getElementById(textareaId + '-media-btn');
    var fileInput = document.getElementById(textareaId + '-media-file');
    if (btn && fileInput) {
        btn.addEventListener('click', function() { fileInput.click(); });
        fileInput.addEventListener('change', function() {
            var files = this.files;
            for (var i = 0; i < files.length; i++) {
                uploadMedia(files[i], function(md) { insertAtCursor(ta, md); });
            }
            this.value = '';
        });
    }

    ta.focus();
}

function insertAtCursor(ta, text) {
    var start = ta.selectionStart;
    var end = ta.selectionEnd;
    var before = ta.value.substring(0, start);
    var after = ta.value.substring(end);
    // Add newline before if needed
    if (before && !before.endsWith('\n')) before += '\n';
    ta.value = before + text + '\n' + after;
    ta.selectionStart = ta.selectionEnd = before.length + text.length + 1;
    ta.focus();
}

// Build compose HTML with media button
function composeHtml(textareaId, placeholder, rows, sendLabel, sendOnclick) {
    return '<div class="dm-compose">'
        + '<div class="dm-compose-row">'
        + '<textarea id="' + textareaId + '" placeholder="' + (placeholder || 'Type a message...') + '" rows="' + (rows || 2) + '"></textarea>'
        + '</div>'
        + '<div class="dm-compose-actions">'
        + '<button type="button" class="dm-media-btn" id="' + textareaId + '-media-btn" title="Attach image or video">&#128247;</button>'
        + '<input type="file" id="' + textareaId + '-media-file" accept="image/*,video/*" multiple style="display:none">'
        + '<button class="btn btn-primary" onclick="' + sendOnclick + '">' + sendLabel + '</button>'
        + '</div></div>';
}

// ── Called by SSE when a new DM arrives ──
CommunityDM.onNewMessage = function(msg) {
    if (!msg || !msg.thread_key) return;
    if (_activeThreadKey === msg.thread_key) {
        var container = document.getElementById('dm-messages');
        if (!container) return;
        var div = document.createElement('div');
        div.className = 'dm-message';
        div.innerHTML = '<div class="dm-message-bubble">' + Community.formatBody(msg.message_body) + '</div>'
            + '<div class="dm-message-time">' + Community.timeAgo(msg.message_dtime) + '</div>';
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
        CommunityNotifications.clearDmCount();
        Community.api('/api/community-dm.php?thread=' + msg.thread_key);
    }
    var inboxEl = document.getElementById('view-messages');
    if (inboxEl && inboxEl.style.display !== 'none' && !_activeThreadKey) {
        CommunityDM.loadInbox();
    }
};

// ── Update DM badge in nav ──
CommunityDM.updateUnreadBadge = function(count) {
    var nav = document.getElementById('nav-messages');
    if (!nav) return;
    nav.innerHTML = nav.textContent.replace(/ \(\d+\)$/, '');
    if (count > 0) {
        nav.innerHTML = 'Messages <span class="dm-unread-badge">' + count + '</span>';
    } else {
        nav.textContent = 'Messages';
    }
};

// ── Load inbox ──
CommunityDM.loadInbox = function() {
    _activeThreadKey = null;
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
    _activeThreadKey = threadKey;
    Community.showView('view-messages');
    var el = document.getElementById('view-messages');
    if (!Community.currentUser) {
        el.innerHTML = '<div class="empty-state">Sign in to view messages.</div>';
        return;
    }
    el.innerHTML = '<div class="empty-state">Loading...</div>';

    CommunityNotifications.clearDmCount();

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

        html += composeHtml('dm-reply-body', 'Type a message...', 2, 'Send', 'CommunityDM.sendMessage(' + threadKey + ')');
        el.innerHTML = html;

        var msgContainer = document.getElementById('dm-messages');
        if (msgContainer) msgContainer.scrollTop = msgContainer.scrollHeight;

        wireCompose('dm-reply-body', function() { CommunityDM.sendMessage(threadKey); });
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
    _activeThreadKey = null;
    Community.showView('view-messages');
    var el = document.getElementById('view-messages');

    var html = '<a href="#messages" class="back-link">&larr; Back to inbox</a>';
    html += '<div class="compose-form">'
        + '<h3 class="compose-title">New Message</h3>'
        + '<div class="dm-recipient-search">'
        + '<input id="dm-recipient" placeholder="Search for a user..." autocomplete="off"' + (recipientKey ? ' data-key="' + recipientKey + '"' : '') + '>'
        + '<div id="dm-recipient-results" class="mention-dropdown" style="display:none"></div>'
        + '</div>'
        + composeHtml('dm-new-body', 'Your message...', 4, 'Send Message', 'CommunityDM.submitNew()')
        + '</div>';
    el.innerHTML = html;

    wireCompose('dm-new-body', CommunityDM.submitNew);

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

// ══════════════════════════════════════════════
// ── Chat Popover (Messenger-style floating widget) ──
// ══════════════════════════════════════════════

var _popThread = null;

CommunityDM.initPopover = function() {
    var fab = document.getElementById('chat-fab');
    if (fab && Community.currentUser) fab.style.display = '';
};

CommunityDM.updateFabBadge = function(count) {
    var badge = document.getElementById('chat-fab-badge');
    if (!badge) return;
    if (count > 0) { badge.textContent = count; badge.style.display = ''; }
    else { badge.style.display = 'none'; }
};

CommunityDM.togglePopover = function() {
    var pop = document.getElementById('chat-popover');
    if (pop.classList.contains('open')) {
        pop.classList.remove('open');
        _popThread = null;
    } else {
        pop.classList.add('open');
        CommunityDM.popInbox();
    }
};

CommunityDM.popInbox = function() {
    _popThread = null;
    document.getElementById('chat-pop-title').textContent = 'Messages';
    document.getElementById('chat-pop-back').style.display = 'none';
    var body = document.getElementById('chat-pop-body');
    body.innerHTML = '<div class="chat-pop-empty">Loading...</div>';

    Community.api('/api/community-dm.php?threads=1').then(function(data) {
        var threads = data.threads || [];
        if (!threads.length) {
            body.innerHTML = '<div class="chat-pop-empty">No messages yet</div>';
            return;
        }
        var html = '';
        threads.forEach(function(t) {
            var u = t.other_user || {};
            html += '<div class="chat-pop-thread' + (t.unread_count > 0 ? ' unread' : '') + '" onclick="CommunityDM.popThread(' + t.thread_key + ')">'
                + Community.avatarHtml(u.user_avatar, u.user_display_name, 'dm-avatar', u.user_last_active_dtime)
                + '<div style="flex:1;min-width:0;">'
                + '<div class="cp-name">' + Community.esc(u.user_display_name || 'Unknown')
                + (t.unread_count > 0 ? ' <span class="dm-unread-badge">' + t.unread_count + '</span>' : '') + '</div>'
                + '<div class="cp-preview">' + Community.esc(t.last_message || '') + '</div>'
                + '</div>'
                + '<span class="cp-time">' + Community.timeAgo(t.last_message_dtime) + '</span>'
                + '</div>';
        });
        body.innerHTML = html;
    });
};

CommunityDM.popThread = function(threadKey) {
    _popThread = threadKey;
    document.getElementById('chat-pop-back').style.display = '';
    var body = document.getElementById('chat-pop-body');
    body.innerHTML = '<div class="chat-pop-empty">Loading...</div>';

    CommunityNotifications.clearDmCount();

    Community.api('/api/community-dm.php?thread=' + threadKey).then(function(data) {
        var messages = data.messages || [];
        var thread = data.thread || {};
        var otherUser = thread.other_user || {};

        document.getElementById('chat-pop-title').textContent = otherUser.user_display_name || 'Messages';

        var html = '<div class="chat-pop-msgs" id="chat-pop-msgs">';
        messages.forEach(function(m) {
            var isMine = m.user_key === Community.currentUser.user_key;
            html += '<div class="dm-message' + (isMine ? ' mine' : '') + '">'
                + '<div class="dm-message-bubble">' + Community.formatBody(m.message_body) + '</div>'
                + '<div class="dm-message-time">' + Community.timeAgo(m.message_dtime) + '</div>'
                + '</div>';
        });
        html += '</div>';
        html += '<div class="chat-pop-compose">'
            + '<textarea id="chat-pop-input" placeholder="Type a message..." rows="1"></textarea>'
            + '<button type="button" class="dm-media-btn" id="chat-pop-input-media-btn" title="Attach image or video">&#128247;</button>'
            + '<input type="file" id="chat-pop-input-media-file" accept="image/*,video/*" multiple style="display:none">'
            + '<button class="chat-pop-send" onclick="CommunityDM.popSend(' + threadKey + ')">&#10148;</button>'
            + '</div>';
        body.innerHTML = html;

        var msgs = document.getElementById('chat-pop-msgs');
        if (msgs) msgs.scrollTop = msgs.scrollHeight;

        // Wire up media button
        var mediaBtn = document.getElementById('chat-pop-input-media-btn');
        var mediaFile = document.getElementById('chat-pop-input-media-file');
        var popTa = document.getElementById('chat-pop-input');
        if (mediaBtn && mediaFile) {
            mediaBtn.addEventListener('click', function() { mediaFile.click(); });
            mediaFile.addEventListener('change', function() {
                for (var i = 0; i < this.files.length; i++) {
                    uploadMedia(this.files[i], function(md) { insertAtCursor(popTa, md); });
                }
                this.value = '';
            });
        }

        // Paste support
        if (popTa) {
            popTa.focus();
            popTa.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    CommunityDM.popSend(threadKey);
                }
            });
            popTa.addEventListener('paste', function(e) {
                var items = (e.clipboardData || {}).items || [];
                for (var i = 0; i < items.length; i++) {
                    if (items[i].type.indexOf('image') === 0) {
                        e.preventDefault();
                        uploadMedia(items[i].getAsFile(), function(md) { insertAtCursor(popTa, md); });
                        return;
                    }
                }
            });
        }
    });
};

CommunityDM.popSend = function(threadKey) {
    var input = document.getElementById('chat-pop-input');
    if (!input) return;
    var body = input.value.trim();
    if (!body) return;
    input.value = '';

    Community.api('/api/community-dm.php', {
        method: 'POST',
        body: { action: 'send', thread_key: threadKey, body: body }
    }).then(function(data) {
        if (data.error) return;
        var msgs = document.getElementById('chat-pop-msgs');
        if (msgs) {
            var div = document.createElement('div');
            div.className = 'dm-message mine';
            div.innerHTML = '<div class="dm-message-bubble">' + Community.formatBody(body) + '</div>'
                + '<div class="dm-message-time">just now</div>';
            msgs.appendChild(div);
            msgs.scrollTop = msgs.scrollHeight;
        }
    });
};

// Hook into SSE for live popover updates
var _origOnNewMessage = CommunityDM.onNewMessage;
CommunityDM.onNewMessage = function(msg) {
    _origOnNewMessage(msg);
    if (!msg || !msg.thread_key) return;

    var pop = document.getElementById('chat-popover');
    if (pop && pop.classList.contains('open')) {
        if (_popThread === msg.thread_key) {
            var msgs = document.getElementById('chat-pop-msgs');
            if (msgs) {
                var div = document.createElement('div');
                div.className = 'dm-message';
                div.innerHTML = '<div class="dm-message-bubble">' + Community.formatBody(msg.message_body) + '</div>'
                    + '<div class="dm-message-time">' + Community.timeAgo(msg.message_dtime) + '</div>';
                msgs.appendChild(div);
                msgs.scrollTop = msgs.scrollHeight;
            }
            CommunityNotifications.clearDmCount();
            Community.api('/api/community-dm.php?thread=' + msg.thread_key);
        } else {
            if (!_popThread) CommunityDM.popInbox();
        }
    }
    CommunityDM.updateFabBadge(CommunityNotifications.getUnreadDm ? CommunityNotifications.getUnreadDm() : 0);
};

})();
