/* ── community-topics.js ── Topic list, detail, create, reply, likes, reactions, watch, bookmark, polls ── */
(function() {
'use strict';

window.CommunityTopics = {};

// ── Emoji reaction codes ──
var REACTIONS = [
    { code: 'thumbsup', emoji: '\uD83D\uDC4D' },
    { code: 'heart', emoji: '\u2764\uFE0F' },
    { code: 'pray', emoji: '\uD83D\uDE4F' },
    { code: 'fire', emoji: '\uD83D\uDD25' },
    { code: 'laugh', emoji: '\uD83D\uDE02' },
    { code: 'sad', emoji: '\uD83D\uDE22' }
];

// ── Rich text editor (TinyMCE) ──
CommunityTopics._editors = {};

CommunityTopics.createEditor = function(containerId, textareaId) {
    var container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = '<textarea id="' + textareaId + '"></textarea>';

    // Remove existing instance if any
    var existing = tinymce.get(textareaId);
    if (existing) existing.remove();

    tinymce.init({
        selector: '#' + textareaId,
        base_url: '/js/tinymce',
        plugins: 'autolink link image media lists advlist codesample emoticons fullscreen table autoresize charmap',
        toolbar: 'bold italic underline strikethrough | blocks | bullist numlist | blockquote codesample | link image media emoticons charmap | table | fullscreen',
        menubar: false,
        statusbar: true,
        branding: false,
        promotion: false,
        placeholder: 'Write your message...',
        min_height: 220,
        max_height: 600,
        autoresize_bottom_margin: 10,
        skin: 'oxide',
        content_css: 'default',
        body_class: 'community-editor-body',
        content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 0.95rem; line-height: 1.6; color: #333; padding: 8px; } img { max-width: 100%; height: auto; border-radius: 6px; } a { color: #31345A; } blockquote { border-left: 3px solid #E5C86C; margin: 8px 0; padding: 8px 16px; background: #fafafa; } pre { background: #f4f4f4; padding: 12px; border-radius: 6px; overflow-x: auto; } table { border-collapse: collapse; width: 100%; } td, th { border: 1px solid #ddd; padding: 8px; }',

        // Image upload
        images_upload_handler: function(blobInfo) {
            return new Promise(function(resolve, reject) {
                var fd = new FormData();
                fd.append('image', blobInfo.blob(), blobInfo.filename());
                fetch('/api/community-image-upload.php', {
                    method: 'POST',
                    credentials: 'include',
                    body: fd
                }).then(function(r) { return r.json(); }).then(function(data) {
                    if (data.url) resolve(data.url);
                    else reject(data.error || 'Upload failed');
                }).catch(function() { reject('Upload failed'); });
            });
        },
        automatic_uploads: true,
        file_picker_types: 'image media file',
        images_reuse_filename: true,

        // File picker — handles image, video, and document uploads
        file_picker_callback: function(callback, value, meta) {
            var input = document.createElement('input');
            input.type = 'file';
            if (meta.filetype === 'image') {
                input.accept = 'image/jpeg,image/png,image/gif,image/webp';
            } else if (meta.filetype === 'media') {
                input.accept = 'video/mp4,video/webm,video/quicktime,video/ogg,.mp4,.webm,.mov,.ogg,.avi,.mkv';
            } else {
                input.accept = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.rtf,.csv,.zip';
            }
            input.onchange = function() {
                var file = input.files[0];
                if (!file) return;
                var fd = new FormData();
                if (meta.filetype === 'image') {
                    fd.append('image', file, file.name);
                    fetch('/api/community-image-upload.php', {
                        method: 'POST', credentials: 'include', body: fd
                    }).then(function(r) { return r.json(); }).then(function(data) {
                        if (data.url) callback(data.url, { alt: file.name });
                        else alert(data.error || 'Upload failed');
                    }).catch(function() { alert('Upload failed'); });
                } else if (meta.filetype === 'media') {
                    fd.append('video', file, file.name);
                    fetch('/api/community-video-upload.php', {
                        method: 'POST', credentials: 'include', body: fd
                    }).then(function(r) { return r.json(); }).then(function(data) {
                        if (data.url) callback(data.url, { source2: '', poster: '' });
                        else alert(data.error || 'Upload failed');
                    }).catch(function() { alert('Upload failed'); });
                } else {
                    fd.append('file', file, file.name);
                    fetch('/api/community-doc-upload.php', {
                        method: 'POST', credentials: 'include', body: fd
                    }).then(function(r) { return r.json(); }).then(function(data) {
                        if (data.url) callback(data.url, { text: data.name || file.name, title: file.name });
                        else alert(data.error || 'Upload failed');
                    }).catch(function() { alert('Upload failed'); });
                }
            };
            input.click();
        },

        // Media embed — allow YouTube, Rumble, etc.
        media_live_embeds: true,
        media_alt_source: false,
        media_poster: false,

        // Link settings
        link_default_target: '_blank',
        link_assume_external_targets: true,

        // Paste cleanup
        paste_as_text: false,
        paste_block_drop: false,

        // Block formats
        block_formats: 'Paragraph=p; Heading 3=h3; Heading 4=h4; Blockquote=blockquote; Code=pre',

        setup: function(editor) {
            CommunityTopics._editors[textareaId] = editor;
        }
    });
};

// Get editor HTML content
CommunityTopics.getEditorHtml = function(textareaId) {
    var editor = (typeof tinymce !== 'undefined') ? tinymce.get(textareaId) : null;
    if (editor) return editor.getContent();
    var ta = document.getElementById(textareaId);
    return ta ? ta.value : '';
};

// Get plain text from editor
CommunityTopics.getEditorText = function(textareaId) {
    var editor = (typeof tinymce !== 'undefined') ? tinymce.get(textareaId) : null;
    if (editor) return editor.getContent({ format: 'text' });
    var ta = document.getElementById(textareaId);
    return ta ? ta.value : '';
};

// ── Load topic list ──
CommunityTopics.loadTopics = function(page, category) {
    page = page || Community.currentPage || 1;
    category = category !== undefined ? category : Community.currentCategory;
    Community.currentPage = page;

    Community.showView('view-topics');
    var el = document.getElementById('topic-list-content');
    if (!el) return;

    var url = '/api/community-topics.php?page=' + page;
    if (category) url += '&category=' + encodeURIComponent(category);

    Community.api(url).then(function(data) {
        var topics = data.topics || [];
        var html = '';

        if (!topics.length) {
            html += '<div class="empty-state">No discussions yet. Be the first to start one!</div>';
        } else {
            html += '<div class="topic-list">';
            for (var i = 0; i < topics.length; i++) {
                var t = topics[i];
                var pinned = t.topic_pinned === true || t.topic_pinned === 't';
                var hidden = t.topic_active_flag === false || t.topic_active_flag === 'f';
                html += '<div class="topic-item' + (pinned ? ' pinned' : '') + (hidden ? ' topic-hidden' : '') + '" onclick="window.location.hash=\'#topic/' + t.topic_key + '\'">'
                    + Community.clickableAvatar(t.user_key, t.user_avatar, t.user_display_name, 'topic-avatar', t.user_last_active_dtime)
                    + '<div class="topic-info">'
                    + '<div class="topic-title">' + (hidden ? '<span class="hidden-badge">Hidden</span> ' : '') + (pinned ? '&#128204; ' : '') + Community.esc(t.topic_title) + '</div>'
                    + '<div class="topic-meta">'
                    + Community.categoryBadge(t.category_slug)
                    + '<span onclick="event.stopPropagation();Community.showProfileCard(event,' + t.user_key + ')" style="cursor:pointer;">' + Community.esc(t.user_display_name || 'Anonymous') + '</span>'
                    + ' &middot; ' + Community.timeAgo(t.topic_dtime)
                    + '</div></div>'
                    + '<div class="topic-stats">'
                    + '<div class="stat-item"><span class="stat-icon">&#128172;</span><span class="stat-num">' + (t.topic_reply_count || 0) + '</span></div>'
                    + '<div class="stat-item"><span class="stat-icon">&#128065;</span><span class="stat-num">' + (t.topic_view_count || 0) + '</span></div>'
                    + '<div class="stat-item"><span class="stat-icon">&#10084;</span><span class="stat-num">' + (t.topic_like_count || 0) + '</span></div>'
                    + '</div></div>';
            }
            html += '</div>';
            if (data.pages > 1) {
                html += '<div class="pagination">';
                for (var p = 1; p <= data.pages; p++) {
                    html += '<button class="' + (p === data.page ? 'active' : '') + '" onclick="CommunityTopics.loadTopics(' + p + ')">' + p + '</button>';
                }
                html += '</div>';
            }
        }
        el.innerHTML = html;
    });
};

// ── Load single topic ──
CommunityTopics.loadTopic = function(key) {
    Community.showView('view-topic');
    var el = document.getElementById('view-topic');
    el.innerHTML = '<div class="empty-state">Loading...</div>';

    Community.api('/api/community-topics.php?topic=' + key).then(function(data) {
        var t = data.topic;
        var replies = data.replies || [];
        var user = Community.currentUser;
        var isMod = user && user.roles && (user.roles.indexOf('admin') >= 0 || user.roles.indexOf('moderator') >= 0);
        var isAuthorOrMod = user && (isMod || user.user_key === t.user_key);

        var html = '<a href="#topics" class="back-link">&larr; Back to topics</a>';

        // Topic detail
        var topicHidden = t.topic_active_flag === false || t.topic_active_flag === 'f';
        var topicIsRemoved = !!t.topic_delete_dtime;
        html += '<div class="topic-detail' + (topicHidden ? ' item-hidden' : '') + (topicIsRemoved ? ' removed' : '') + '">';
        html += '<div class="topic-detail-header">';
        html += '<h2>' + Community.esc(t.topic_title) + '</h2>';
        // Watch and bookmark buttons
        if (user) {
            var watched = t.user_status && t.user_status.watching;
            var bookmarked = t.user_status && t.user_status.bookmarked;
            html += '<div class="topic-actions-top">'
                + '<button class="btn-icon' + (watched ? ' active' : '') + '" onclick="CommunityTopics.toggleWatch(' + key + ')" title="' + (watched ? 'Stop watching' : 'Watch') + '">&#128064;' + (watched ? ' Watching' : '') + '</button>'
                + '<button class="btn-icon' + (bookmarked ? ' active' : '') + '" onclick="CommunityTopics.toggleBookmark(' + key + ')" title="' + (bookmarked ? 'Remove bookmark' : 'Bookmark') + '">&#128278;' + (bookmarked ? ' Saved' : '') + '</button>'
                + '</div>';
        }
        html += '</div>';

        html += '<div class="topic-author">'
            + Community.clickableAvatar(t.user_key, t.user_avatar, t.user_display_name, 'detail-avatar', t.user_last_active_dtime)
            + '<span onclick="Community.showProfileCard(event,' + t.user_key + ')" style="cursor:pointer;">' + Community.esc(t.user_display_name || 'Anonymous') + '</span>'
            + ' &middot; ' + Community.timeAgo(t.topic_dtime)
            + Community.categoryBadge(t.category_slug);
        if (isMod && user.user_key !== t.user_key) {
            html += '<button class="btn btn-sm btn-outline reply-ban-btn" onclick="CommunityTopics.openBanModal(' + t.user_key + ',\'' + Community.esc(t.user_display_name || '').replace(/'/g, "\\'") + '\')">Ban User</button>';
        }
        html += '</div>';

        if (t.topic_body_html || t.topic_body) {
            html += '<div class="topic-body">' + (t.topic_body_html || Community.formatBody(t.topic_body)) + '</div>';
        }

        // Poll
        if (data.poll) {
            html += CommunityTopics.renderPoll(data.poll, key);
        }

        // Like + reactions
        html += CommunityTopics.renderLikeAndReactions('topic', key, t);

        // Report button
        if (user && !isAuthorOrMod) {
            html += '<button class="btn-icon report-btn" onclick="CommunityTopics.openReport(\'topic\',' + key + ')" title="Report">&#9873; Report</button>';
        }

        // Topic remove/restore actions
        var topicRemoved = !!t.topic_delete_dtime;
        if (topicRemoved && isMod) {
            html += '<div class="removed-notice"><span>Topic removed ' + Community.timeAgo(t.topic_delete_dtime)
                + (t.topic_delete_note ? ': ' + Community.esc(t.topic_delete_note) : '')
                + '</span><button class="btn btn-sm btn-outline restore-btn" onclick="CommunityTopics.restoreTopic(' + key + ')">Restore</button></div>';
        }
        if (!topicRemoved && isAuthorOrMod) {
            html += '<div class="mod-actions">'
                + '<button class="btn btn-sm btn-outline" style="color:#c0392b;border-color:#c0392b;" onclick="CommunityTopics.removeTopic(' + key + ')">Remove</button>'
                + '</div>';
        }

        html += '</div>';

        // Replies
        if (replies.length) {
            html += '<div class="reply-list">';
            for (var i = 0; i < replies.length; i++) {
                var r = replies[i];
                var canRemove = user && (isMod || user.user_key === r.user_key);
                var isRemoved = !!r.reply_delete_dtime;

                html += '<div class="reply-item' + (isRemoved ? ' removed' : '') + '" id="reply-' + r.reply_key + '">';

                if (isRemoved && isMod) {
                    html += '<div class="removed-notice"><span>Removed ' + Community.timeAgo(r.reply_delete_dtime)
                        + (r.reply_delete_note ? ': ' + Community.esc(r.reply_delete_note) : '')
                        + '</span><button class="btn btn-sm btn-outline restore-btn" onclick="CommunityTopics.restoreReply(' + r.reply_key + ',' + key + ')">Restore</button></div>';
                }

                html += '<div class="reply-author">'
                    + Community.clickableAvatar(r.user_key, r.user_avatar, r.user_display_name, 'reply-avatar', r.user_last_active_dtime)
                    + '<span onclick="Community.showProfileCard(event,' + r.user_key + ')" style="cursor:pointer;">' + Community.esc(r.user_display_name || 'Anonymous') + '</span>'
                    + ' &middot; ' + Community.timeAgo(r.reply_dtime);
                if (isMod && !isRemoved && user.user_key !== r.user_key) {
                    html += '<button class="btn btn-sm btn-outline reply-ban-btn" onclick="CommunityTopics.openBanModal(' + r.user_key + ',\'' + Community.esc(r.user_display_name || '').replace(/'/g, "\\'") + '\')">Ban</button>';
                }
                html += '</div>'
                    + '<div class="reply-body">' + (r.reply_body_html || Community.formatBody(r.reply_body)) + '</div>';

                if (!isRemoved) {
                    // Reply like + reactions
                    html += CommunityTopics.renderLikeAndReactions('reply', r.reply_key, r);

                    // Report for reply (non-mods, non-authors)
                    if (user && !isMod && user.user_key !== r.user_key) {
                        html += '<button class="btn-icon report-btn" onclick="CommunityTopics.openReport(\'reply\',' + r.reply_key + ')" title="Report">&#9873;</button>';
                    }

                    // Remove button
                    if (canRemove) {
                        html += '<div class="reply-actions">';
                        html += '<button class="btn btn-sm btn-outline" style="color:#c0392b;border-color:#c0392b;" onclick="CommunityTopics.removeReply(' + r.reply_key + ',' + key + ')">Remove</button>';
                        html += '</div>';
                    }
                }
                html += '</div>';
            }
            html += '</div>';
        }

        // Reply form
        var locked = t.topic_locked === true || t.topic_locked === 't';
        if (user && !locked) {
            html += '<div class="compose-form">'
                + '<div id="reply-editor"></div>'
                + '<button class="btn btn-primary" onclick="CommunityTopics.submitReply(' + key + ')">Reply</button>'
                + '</div>';
        } else if (locked) {
            html += '<div class="empty-state" style="padding:20px;">This topic is locked.</div>';
        } else {
            html += '<div class="empty-state" style="padding:20px;"><a href="#" onclick="CommunityAuth.openLoginModal(\'login\');return false;">Sign in</a> to reply.</div>';
        }

        el.innerHTML = html;

        // Init reply editor
        if (user && !locked) {
            CommunityTopics.createEditor('reply-editor', 'reply-body');
        }

        // Load poll if present
        if (!data.poll) {
            Community.api('/api/community-polls.php?topic=' + key).then(function(pollData) {
                if (pollData && pollData.poll) {
                    var pollContainer = document.createElement('div');
                    pollContainer.innerHTML = CommunityTopics.renderPoll(pollData.poll, key);
                    var topicDetail = el.querySelector('.topic-detail');
                    if (topicDetail) {
                        var body = topicDetail.querySelector('.topic-body');
                        if (body) body.insertAdjacentElement('afterend', pollContainer);
                    }
                }
            });
        }
    });
};

// ── Like + Reactions HTML ──
CommunityTopics.renderLikeAndReactions = function(type, key, item) {
    var user = Community.currentUser;
    var likeCount = item[type + '_like_count'] || item.like_count || 0;
    var userLiked = item.user_liked;
    var reactions = item.reactions || {};

    var html = '<div class="like-reaction-bar">';
    // Like button
    html += '<button class="btn-icon like-btn' + (userLiked ? ' liked' : '') + '" onclick="CommunityTopics.toggleLike(\'' + type + '\',' + key + ')">'
        + (userLiked ? '&#10084;&#65039;' : '&#9825;')
        + ' <span class="like-count">' + likeCount + '</span></button>';

    // Existing reactions display
    REACTIONS.forEach(function(r) {
        var count = reactions[r.code] || 0;
        var active = item.user_reactions && item.user_reactions.indexOf(r.code) >= 0;
        if (count > 0 || active) {
            html += '<button class="reaction-chip' + (active ? ' active' : '') + '" onclick="CommunityTopics.toggleReaction(\'' + type + '\',' + key + ',\'' + r.code + '\')">'
                + r.emoji + ' ' + count + '</button>';
        }
    });

    // Add reaction button
    if (user) {
        html += '<div class="reaction-picker-wrap">'
            + '<button class="btn-icon add-reaction-btn" onclick="CommunityTopics.toggleReactionPicker(this)" title="Add reaction">+</button>'
            + '<div class="reaction-picker" style="display:none">';
        REACTIONS.forEach(function(r) {
            html += '<button class="reaction-option" onclick="CommunityTopics.toggleReaction(\'' + type + '\',' + key + ',\'' + r.code + '\')" title="' + r.code + '">' + r.emoji + '</button>';
        });
        html += '</div></div>';
    }

    html += '</div>';
    return html;
};

CommunityTopics.toggleReactionPicker = function(btn) {
    var picker = btn.nextElementSibling;
    var isOpen = picker.style.display !== 'none';
    // Close all pickers first
    document.querySelectorAll('.reaction-picker').forEach(function(p) { p.style.display = 'none'; });
    picker.style.display = isOpen ? 'none' : 'flex';
};

// ── Toggle like ──
CommunityTopics.toggleLike = function(type, key) {
    if (!Community.currentUser) { CommunityAuth.openLoginModal('login'); return; }
    Community.api('/api/community-likes.php', {
        method: 'POST',
        body: { target_type: type, target_key: key }
    }).then(function(data) {
        // Reload current view
        var hash = window.location.hash;
        if (hash.indexOf('#topic/') === 0) {
            CommunityTopics.loadTopic(parseInt(hash.split('/')[1]));
        }
    });
};

// ── Toggle reaction ──
CommunityTopics.toggleReaction = function(type, key, code) {
    if (!Community.currentUser) { CommunityAuth.openLoginModal('login'); return; }
    Community.api('/api/community-reactions.php', {
        method: 'POST',
        body: { target_type: type, target_key: key, reaction_code: code }
    }).then(function() {
        var hash = window.location.hash;
        if (hash.indexOf('#topic/') === 0) {
            CommunityTopics.loadTopic(parseInt(hash.split('/')[1]));
        }
    });
};

// ── Watch ──
CommunityTopics.toggleWatch = function(topicKey) {
    if (!Community.currentUser) return;
    Community.api('/api/community-watch.php', {
        method: 'POST',
        body: { topic_key: topicKey }
    }).then(function() {
        CommunityTopics.loadTopic(topicKey);
    });
};

// ── Bookmark ──
CommunityTopics.toggleBookmark = function(topicKey) {
    if (!Community.currentUser) return;
    Community.api('/api/community-bookmarks.php', {
        method: 'POST',
        body: { topic_key: topicKey }
    }).then(function() {
        CommunityTopics.loadTopic(topicKey);
    });
};

// ── Bookmarks view ──
CommunityTopics.loadBookmarks = function() {
    Community.showView('view-bookmarks');
    var el = document.getElementById('view-bookmarks');
    if (!Community.currentUser) {
        el.innerHTML = '<div class="empty-state">Sign in to see your bookmarks.</div>';
        return;
    }
    el.innerHTML = '<div class="empty-state">Loading...</div>';
    Community.api('/api/community-bookmarks.php').then(function(data) {
        var topics = data.bookmarks || [];
        var html = '<h2 class="section-title">&#128278; Bookmarked Topics</h2>';
        if (!topics.length) {
            html += '<div class="empty-state">No bookmarks yet. Bookmark topics to save them here.</div>';
        } else {
            html += '<div class="topic-list">';
            for (var i = 0; i < topics.length; i++) {
                var t = topics[i];
                html += '<div class="topic-item" onclick="window.location.hash=\'#topic/' + t.topic_key + '\'">'
                    + Community.avatarHtml(t.user_avatar, t.user_display_name, 'topic-avatar', t.user_last_active_dtime)
                    + '<div class="topic-info"><div class="topic-title">' + Community.esc(t.topic_title) + '</div>'
                    + '<div class="topic-meta">' + Community.esc(t.user_display_name || 'Anonymous') + ' &middot; ' + Community.timeAgo(t.topic_dtime) + '</div></div>'
                    + '<div class="topic-stats">'
                    + '<div class="stat-item"><span class="stat-icon">&#128172;</span><span class="stat-num">' + (t.topic_reply_count || 0) + '</span></div>'
                    + '</div></div>';
            }
            html += '</div>';
        }
        el.innerHTML = html;
    });
};

// ── New topic form ──
CommunityTopics.showNewTopic = function() {
    if (!Community.currentUser) { CommunityAuth.openLoginModal('login'); return; }
    Community.showView('view-new');
    var el = document.getElementById('view-new');
    var cats = Community.categories;
    var catOptions = '<option value="">Select category</option>';
    for (var i = 0; i < cats.length; i++) {
        catOptions += '<option value="' + Community.esc(cats[i].category_slug) + '">' + Community.esc(cats[i].category_name) + '</option>';
    }

    el.innerHTML = '<a href="#topics" class="back-link">&larr; Back to topics</a>'
        + '<div class="compose-form">'
        + '<h3 class="compose-title">New Topic</h3>'
        + '<input id="new-title" placeholder="Topic title">'
        + '<select id="new-category" class="form-select">' + catOptions + '</select>'
        + '<div id="new-body-editor"></div>'
        + '<div id="poll-section">'
        + '<label class="poll-toggle"><input type="checkbox" id="add-poll" onchange="CommunityTopics.togglePollForm()"> Add a poll</label>'
        + '<div id="poll-form" style="display:none">'
        + '<input id="poll-question" placeholder="Poll question">'
        + '<div id="poll-options"><input class="poll-option-input" placeholder="Option 1"><input class="poll-option-input" placeholder="Option 2"></div>'
        + '<button class="btn btn-outline btn-sm" onclick="CommunityTopics.addPollOption()">+ Add option</button>'
        + '</div></div>'
        + '<button class="btn btn-primary" onclick="CommunityTopics.submitTopic()">Create Topic</button>'
        + '</div>';

    CommunityTopics.createEditor('new-body-editor', 'new-body');
};

CommunityTopics.togglePollForm = function() {
    var checked = document.getElementById('add-poll').checked;
    document.getElementById('poll-form').style.display = checked ? '' : 'none';
};

CommunityTopics.addPollOption = function() {
    var container = document.getElementById('poll-options');
    var count = container.querySelectorAll('input').length + 1;
    var input = document.createElement('input');
    input.className = 'poll-option-input';
    input.placeholder = 'Option ' + count;
    container.appendChild(input);
};

CommunityTopics.submitTopic = function() {
    var title = document.getElementById('new-title').value.trim();
    var bodyHtml = CommunityTopics.getEditorHtml('new-body');
    var body = CommunityTopics.getEditorText('new-body');
    var category = document.getElementById('new-category').value;
    if (!title) { alert('Title is required'); return; }

    var payload = { action: 'create_topic', title: title, body: body, topic_body_html: bodyHtml, category: category };

    // Poll data
    if (document.getElementById('add-poll') && document.getElementById('add-poll').checked) {
        var question = document.getElementById('poll-question').value.trim();
        var optInputs = document.querySelectorAll('.poll-option-input');
        var options = [];
        optInputs.forEach(function(inp) {
            var v = inp.value.trim();
            if (v) options.push(v);
        });
        if (question && options.length >= 2) {
            payload.poll = { question: question, options: options };
        }
    }

    Community.api('/api/community-topics.php', {
        method: 'POST',
        body: payload
    }).then(function(data) {
        if (data.topic_key) {
            // Create poll separately if needed
            if (payload.poll) {
                Community.api('/api/community-polls.php', {
                    method: 'POST',
                    body: { action: 'create', topic_key: data.topic_key, question: payload.poll.question, options: payload.poll.options }
                }).then(function() {
                    window.location.hash = '#topic/' + data.topic_key;
                });
            } else {
                window.location.hash = '#topic/' + data.topic_key;
            }
        } else {
            alert(data.error || 'Failed');
        }
    });
};

// ── Submit reply ──
CommunityTopics.submitReply = function(topicKey) {
    var bodyHtml = CommunityTopics.getEditorHtml('reply-body');
    var body = CommunityTopics.getEditorText('reply-body');

    // Check both plain text and HTML — media-only posts have no text
    var htmlEmpty = !bodyHtml || bodyHtml.replace(/<[^>]*>/g, '').trim() === '' && bodyHtml.indexOf('<img') === -1 && bodyHtml.indexOf('<video') === -1 && bodyHtml.indexOf('<iframe') === -1;
    if (!body.trim() && htmlEmpty) {
        alert('Please enter a reply');
        return;
    }
    Community.api('/api/community-topics.php', {
        method: 'POST',
        body: { action: 'reply', topic_key: topicKey, body: body || '(media)', reply_body_html: bodyHtml }
    }).then(function(data) {
        if (data.saved) CommunityTopics.loadTopic(topicKey);
        else alert(data.error || 'Failed');
    });
};

// ── Remove / Restore ──
CommunityTopics.removeReply = function(replyKey, topicKey) {
    var note = prompt('Reason for removal (optional):');
    if (note === null) return; // cancelled
    fetch('/api/community-topics.php?type=reply&reply=' + replyKey, {
        method: 'DELETE', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ note: note })
    }).then(function() { CommunityTopics.loadTopic(topicKey); });
};

CommunityTopics.removeTopic = function(topicKey) {
    var note = prompt('Reason for removal (optional):');
    if (note === null) return;
    fetch('/api/community-topics.php?topic=' + topicKey + '&type=topic', {
        method: 'DELETE', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ note: note })
    }).then(function() { CommunityTopics.loadTopic(topicKey); });
};

CommunityTopics.restoreReply = function(replyKey, topicKey) {
    fetch('/api/community-topics.php?topic=' + topicKey, {
        method: 'PATCH', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'reply', reply_key: replyKey })
    }).then(function() { CommunityTopics.loadTopic(topicKey); });
};

CommunityTopics.restoreTopic = function(topicKey) {
    fetch('/api/community-topics.php?topic=' + topicKey, {
        method: 'PATCH', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'topic' })
    }).then(function() { CommunityTopics.loadTopic(topicKey); });
};

// ── Legacy delete (kept for compatibility) ──
CommunityTopics.deleteReply = function(replyKey, topicKey) {
    if (!confirm('Delete this reply?')) return;
    fetch('/api/community-topics.php?type=reply&reply=' + replyKey, { method: 'DELETE' })
        .then(function() { CommunityTopics.loadTopic(topicKey); });
};

// ── Report modal ──
CommunityTopics.openReport = function(type, key) {
    var reasons = ['Spam', 'Harassment', 'Inappropriate content', 'Off-topic', 'Other'];
    var html = '<div class="report-modal-inner">'
        + '<h3>Report ' + type + '</h3>'
        + '<select id="report-reason" class="form-select">';
    reasons.forEach(function(r) { html += '<option value="' + r + '">' + r + '</option>'; });
    html += '</select>'
        + '<textarea id="report-detail" placeholder="Additional details (optional)" rows="3"></textarea>'
        + '<div class="report-actions">'
        + '<button class="btn btn-primary" onclick="CommunityTopics.submitReport(\'' + type + '\',' + key + ')">Submit Report</button>'
        + '<button class="btn btn-outline" onclick="document.getElementById(\'report-modal\').classList.remove(\'active\')">Cancel</button>'
        + '</div></div>';

    var modal = document.getElementById('report-modal');
    modal.innerHTML = html;
    modal.classList.add('active');
};

CommunityTopics.submitReport = function(type, key) {
    var reason = document.getElementById('report-reason').value;
    var detail = document.getElementById('report-detail').value.trim();
    Community.api('/api/community-reports.php', {
        method: 'POST',
        body: { target_type: type, target_key: key, report_reason: reason, report_detail: detail }
    }).then(function(data) {
        document.getElementById('report-modal').classList.remove('active');
        if (data.error) alert(data.error);
        else alert('Report submitted. Thank you.');
    });
};

// ── Hide/Delete modal (moderators) ──
CommunityTopics.openHideModal = function(type, key, topicKey) {
    var html = '<div class="report-modal-inner">'
        + '<h3>Hide ' + type + '</h3>'
        + '<p style="font-size:0.85rem;color:#666;margin:0 0 12px;">This will remove the ' + type + ' from public view.</p>'
        + '<textarea id="hide-reason" placeholder="Reason (optional)" rows="3" style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.9rem;box-sizing:border-box;resize:vertical;"></textarea>'
        + '<div class="report-actions" style="margin-top:12px;">'
        + '<button class="btn btn-primary" onclick="CommunityTopics.submitHide(\'' + type + '\',' + key + ',' + (topicKey || key) + ')">Hide ' + type + '</button>'
        + '<button class="btn btn-outline" onclick="document.getElementById(\'report-modal\').classList.remove(\'active\')">Cancel</button>'
        + '</div></div>';

    var modal = document.getElementById('report-modal');
    modal.innerHTML = html;
    modal.classList.add('active');
};

CommunityTopics.submitHide = function(type, key, topicKey) {
    var reason = document.getElementById('hide-reason').value.trim();
    var url = '/api/community-topics.php?type=' + type;
    if (type === 'topic') url += '&topic=' + key;
    else url += '&reply=' + key;

    Community.api(url, {
        method: 'DELETE',
        body: { reason: reason }
    }).then(function(data) {
        document.getElementById('report-modal').classList.remove('active');
        if (data.error) { alert(data.error); return; }
        if (type === 'topic') {
            window.location.hash = '#topics';
        } else {
            CommunityTopics.loadTopic(topicKey);
        }
    });
};

// ── Ban modal (moderators) ──
CommunityTopics.openBanModal = function(targetUserKey, displayName) {
    // Don't allow banning yourself
    if (Community.currentUser && Community.currentUser.user_key === targetUserKey) {
        alert('You cannot ban yourself.');
        return;
    }

    var html = '<div class="report-modal-inner">'
        + '<h3>Ban User</h3>'
        + '<p style="font-size:0.85rem;color:#666;margin:0 0 12px;">Ban <strong>' + Community.esc(displayName) + '</strong> from the community.</p>'
        + '<div style="margin-bottom:12px;">'
        + '<label style="display:block;font-size:0.82rem;font-weight:600;margin-bottom:4px;">Duration</label>'
        + '<select id="ban-duration" style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.9rem;">'
        + '<option value="">Permanent</option>'
        + '<option value="1">1 hour</option>'
        + '<option value="24">24 hours</option>'
        + '<option value="168">7 days</option>'
        + '<option value="720">30 days</option>'
        + '</select></div>'
        + '<textarea id="ban-reason" placeholder="Reason (optional)" rows="3" style="width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.9rem;box-sizing:border-box;resize:vertical;"></textarea>'
        + '<div class="report-actions" style="margin-top:12px;">'
        + '<button class="btn btn-primary" style="background:#c0392b;" onclick="CommunityTopics.submitBan(' + targetUserKey + ')">Ban User</button>'
        + '<button class="btn btn-outline" onclick="document.getElementById(\'report-modal\').classList.remove(\'active\')">Cancel</button>'
        + '</div></div>';

    var modal = document.getElementById('report-modal');
    modal.innerHTML = html;
    modal.classList.add('active');
};

CommunityTopics.submitBan = function(targetUserKey) {
    var reason = document.getElementById('ban-reason').value.trim();
    var duration = document.getElementById('ban-duration').value;

    Community.api('/api/community-ban.php?user=' + targetUserKey, {
        method: 'POST',
        body: { action: 'ban', reason: reason, duration: duration || null }
    }).then(function(data) {
        document.getElementById('report-modal').classList.remove('active');
        if (data.error) { alert(data.error); return; }
        alert('User has been banned.');
    });
};

// ── Poll rendering ──
CommunityTopics.renderPoll = function(poll, topicKey) {
    if (!poll || !poll.question) return '';
    var options = poll.options || [];
    var totalVotes = 0;
    options.forEach(function(o) { totalVotes += (o.vote_count || 0); });
    var userVoted = poll.user_voted;

    var html = '<div class="poll-card">'
        + '<h4 class="poll-question">&#128202; ' + Community.esc(poll.question) + '</h4>';

    if (userVoted || !Community.currentUser) {
        // Show results
        options.forEach(function(o) {
            var pct = totalVotes > 0 ? Math.round((o.vote_count / totalVotes) * 100) : 0;
            html += '<div class="poll-result">'
                + '<div class="poll-result-bar" style="width:' + pct + '%"></div>'
                + '<span class="poll-result-label">' + Community.esc(o.option_text) + '</span>'
                + '<span class="poll-result-pct">' + pct + '% (' + (o.vote_count || 0) + ')</span>'
                + '</div>';
        });
    } else {
        // Show vote buttons
        options.forEach(function(o) {
            html += '<button class="poll-option-btn" onclick="CommunityTopics.votePoll(' + poll.poll_key + ',' + o.option_key + ',' + topicKey + ')">'
                + Community.esc(o.option_text) + '</button>';
        });
    }

    html += '<div class="poll-total">' + totalVotes + ' vote' + (totalVotes !== 1 ? 's' : '') + '</div>';
    html += '</div>';
    return html;
};

CommunityTopics.votePoll = function(pollKey, optionKey, topicKey) {
    if (!Community.currentUser) { CommunityAuth.openLoginModal('login'); return; }
    Community.api('/api/community-polls.php', {
        method: 'POST',
        body: { action: 'vote', poll_key: pollKey, option_key: optionKey }
    }).then(function() {
        CommunityTopics.loadTopic(topicKey);
    });
};

})();
