/* ── community-profile.js ── Profile view, settings ── */
(function() {
'use strict';

window.CommunityProfile = {};

// ── Show profile / settings ──
CommunityProfile.show = function() {
    Community.showView('view-profile');
    var el = document.getElementById('view-profile');
    if (!Community.currentUser) {
        el.innerHTML = '<div class="empty-state">Sign in to view your profile.</div>';
        return;
    }
    el.innerHTML = '<div class="empty-state">Loading profile...</div>';

    Community.api('/api/community-profile.php').then(function(u) {
        var esc = Community.esc;
        var av = u.user_avatar;
        var nm = u.user_name_display || '';
        var avImg = av
            ? '<img class="profile-avatar-lg" src="' + esc(av) + '" onerror="this.outerHTML=\'<div class=&quot;profile-avatar-circle-lg&quot;>' + esc(Community.initials(nm)) + '</div>\'">'
            : '<div class="profile-avatar-circle-lg">' + esc(Community.initials(nm)) + '</div>';

        var html = '<a href="#topics" class="back-link">&larr; Back to topics</a>';
        html += '<div class="profile-section"><h2>Profile</h2>';
        html += '<div id="profile-msg"></div>';
        html += '<div class="profile-avatar-wrap">' + avImg
            + '<div><label class="btn btn-outline btn-sm" style="cursor:pointer;"><input type="file" id="avatar-file" accept="image/*" style="display:none" onchange="CommunityProfile.uploadAvatar()"> Change Photo</label></div></div>';
        html += '<div class="profile-field"><label>Display Name</label><input id="pf-name" value="' + esc(nm) + '"></div>';
        html += '<div class="profile-field"><label>Handle</label><input id="pf-handle" value="' + esc(u.user_handle || '') + '" placeholder="e.g. yada_fan"><div class="hint">Letters, numbers, underscore only. Max 30 characters. Shown as @handle.</div></div>';
        html += '<div class="profile-field"><label>Email</label><input id="pf-email" type="email" value="' + esc(u.user_email || '') + '"></div>';
        html += '<div class="profile-field"><label>Bio</label><textarea id="pf-bio" placeholder="Tell us about yourself...">' + esc(u.user_bio || '') + '</textarea></div>';
        html += '<div class="profile-actions"><button class="btn btn-primary" onclick="CommunityProfile.save()">Save Changes</button></div>';
        html += '</div>';

        // Password section
        html += '<div class="profile-section"><h2>Password</h2>';
        html += '<div id="pw-msg"></div>';
        if (u.has_password) {
            html += '<div class="profile-field"><label>Current Password</label><input id="pw-current" type="password"></div>';
        } else {
            html += '<p style="font-size:0.85rem;color:#666;margin:0 0 12px;">You signed in with social login. Set a password to also enable email login.</p>';
        }
        html += '<div class="profile-field"><label>New Password</label><input id="pw-new" type="password" placeholder="Enter new password"></div>';
        html += '<div class="profile-field"><label>Confirm New Password</label><input id="pw-confirm" type="password"></div>';
        html += '<div class="profile-actions"><button class="btn btn-primary" onclick="CommunityProfile.changePassword(' + (u.has_password ? 'true' : 'false') + ')">Update Password</button></div>';
        html += '</div>';

        // Linked Accounts section
        var methods = u.auth_methods || [];
        var allProviders = ['email', 'google', 'microsoft', 'yahoo', 'x'];
        var providerLabels = { email: 'Email', google: 'Google', microsoft: 'Microsoft', yahoo: 'Yahoo', x: 'X' };
        var linkedProviders = methods.map(function(m) { return m.auth_provider; });

        html += '<div class="profile-section"><h2>Linked Accounts</h2>';
        html += '<div id="link-msg"></div>';

        if (methods.length) {
            html += '<div style="margin-bottom:16px;">';
            for (var m = 0; m < methods.length; m++) {
                var am = methods[m];
                var canUnlink = methods.length > 1;
                html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #eee;">'
                    + '<strong style="min-width:80px;">' + esc(providerLabels[am.auth_provider] || am.auth_provider) + '</strong>'
                    + '<span style="flex:1;font-size:0.85rem;color:#666;">' + esc(am.auth_email || '') + '</span>';
                if (canUnlink) {
                    html += '<button class="btn btn-sm btn-outline" style="color:#c0392b;border-color:#c0392b;font-size:0.75rem;" '
                        + 'onclick="CommunityProfile.unlinkAuth(' + am.user_auth_key + ')">Unlink</button>';
                }
                html += '</div>';
            }
            html += '</div>';
        }

        html += '</div>';

        // Merge Account section
        html += '<div class="profile-section"><h2>Merge Account</h2>';
        html += '<p style="font-size:0.85rem;color:#666;margin:0 0 12px;">Have multiple accounts? Merge another account into this one. All content (topics, replies, messages, likes) will be moved here and the other account will be disabled. This cannot be undone.</p>';
        html += '<button class="btn btn-outline" onclick="CommunityProfile.openMergeModal()">Merge Another Account</button>';
        html += '</div>';

        el.innerHTML = html;
    });
};

// ── Unlink auth method ──
CommunityProfile.unlinkAuth = function(authKey) {
    if (!confirm('Remove this sign-in method?')) return;
    Community.api('/api/user-auth-link.php', {
        method: 'POST',
        body: { action: 'unlink', user_auth_key: authKey }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        CommunityProfile.show();
    });
};

// ── Save profile ──
CommunityProfile.save = function() {
    var data = {
        action: 'update',
        display_name: document.getElementById('pf-name').value,
        handle: document.getElementById('pf-handle').value,
        email: document.getElementById('pf-email').value,
        bio: document.getElementById('pf-bio').value
    };
    Community.api('/api/community-profile.php', {
        method: 'POST',
        body: data
    }).then(function(res) {
        var msg = document.getElementById('profile-msg');
        if (res.error) {
            Community.showMsg(msg, res.error, 'profile-msg error');
        } else {
            Community.showMsg(msg, 'Profile updated!', 'profile-msg success');
            Community.currentUser.user_name_display = data.display_name;
            CommunityAuth.renderAuth();
        }
    });
};

// ── Upload avatar ──
CommunityProfile.uploadAvatar = function() {
    var file = document.getElementById('avatar-file').files[0];
    if (!file) return;
    var fd = new FormData();
    fd.append('avatar', file);
    Community.api('/api/community-profile.php', {
        method: 'POST',
        body: fd
    }).then(function(res) {
        if (res.error) { alert(res.error); return; }
        Community.currentUser.user_avatar = res.avatar;
        CommunityAuth.renderAuth();
        CommunityProfile.show();
    });
};

// ── Change password ──
CommunityProfile.changePassword = function(hasCurrent) {
    var current = hasCurrent ? document.getElementById('pw-current').value : '';
    var newPw = document.getElementById('pw-new').value;
    var confirm = document.getElementById('pw-confirm').value;
    var msg = document.getElementById('pw-msg');
    if (newPw !== confirm) { Community.showMsg(msg, 'Passwords do not match', 'profile-msg error'); return; }
    var data = { action: 'change_password', new_password: newPw };
    if (hasCurrent) data.current_password = current;
    Community.api('/api/community-profile.php', {
        method: 'POST',
        body: data
    }).then(function(res) {
        if (res.error) Community.showMsg(msg, res.error, 'profile-msg error');
        else Community.showMsg(msg, 'Password updated!', 'profile-msg success');
    });
};

// ══════════════════════════════════════
// ── Account Merge ──
// ══════════════════════════════════════

CommunityProfile.openMergeModal = function() {
    var existing = document.getElementById('merge-modal');
    if (existing) existing.remove();

    var esc = Community.esc;
    var providerLabels = { google: 'Google', microsoft: 'Microsoft', yahoo: 'Yahoo', x: 'X' };
    var providers = ['google', 'microsoft', 'yahoo', 'x'];
    var oauthBtns = '';
    for (var i = 0; i < providers.length; i++) {
        var p = providers[i];
        oauthBtns += '<a class="btn btn-sm btn-outline" href="/api/oauth-login.php?provider=' + p + '&merge=1">' + esc(providerLabels[p]) + '</a>';
    }

    var modal = document.createElement('div');
    modal.id = 'merge-modal';
    modal.className = 'login-modal active';
    modal.setAttribute('onclick', "if(event.target===this){this.remove();}");
    modal.innerHTML = '<div class="login-box">'
        + '<button class="login-close" onclick="this.closest(\'.login-modal\').remove()">&times;</button>'
        + '<h2>Merge Account</h2>'
        + '<p style="font-size:0.85rem;color:#666;text-align:center;margin:0 0 16px;">Sign into the account you want to merge into this one.</p>'
        + '<div id="merge-error" style="display:none;color:#c0392b;font-size:0.85rem;text-align:center;margin-bottom:10px;"></div>'
        + '<div style="display:flex;gap:8px;justify-content:center;margin-bottom:16px;">' + oauthBtns + '</div>'
        + '<div class="login-or" style="text-align:center;color:#999;font-size:0.85rem;margin:16px 0;position:relative;"><span style="display:inline-flex;align-items:center;gap:12px;"><span style="flex:1;height:1px;width:40px;background:#ddd;"></span>or<span style="flex:1;height:1px;width:40px;background:#ddd;"></span></span></div>'
        + '<input id="merge-email" type="email" placeholder="Email address of other account" style="width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:0.9rem;box-sizing:border-box;margin-bottom:12px;">'
        + '<input id="merge-pass" type="password" placeholder="Password" style="width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:0.9rem;box-sizing:border-box;margin-bottom:12px;">'
        + '<button class="btn btn-primary" style="width:100%;" id="merge-verify-btn">Verify & Preview</button>'
        + '</div>';
    document.body.appendChild(modal);

    setTimeout(function() {
        document.getElementById('merge-verify-btn').addEventListener('click', CommunityProfile.submitMergeAuth);
        document.getElementById('merge-pass').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') CommunityProfile.submitMergeAuth();
        });
        document.getElementById('merge-email').focus();
    }, 50);
};

CommunityProfile.submitMergeAuth = function() {
    var email = document.getElementById('merge-email').value.trim();
    var pass = document.getElementById('merge-pass').value;
    var errEl = document.getElementById('merge-error');
    if (!email) { errEl.textContent = 'Email is required'; errEl.style.display = ''; return; }
    if (!pass) { errEl.textContent = 'Password is required'; errEl.style.display = ''; return; }

    Community.api('/api/account-merge.php', {
        method: 'POST',
        body: { action: 'merge_auth_email', email: email, password: pass }
    }).then(function(data) {
        if (data.error) { errEl.textContent = data.error; errEl.style.display = ''; return; }
        CommunityProfile.showMergePreview(data);
    }).catch(function() { errEl.textContent = 'Connection error'; errEl.style.display = ''; });
};

CommunityProfile.showMergePreview = function(data) {
    var modal = document.getElementById('merge-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'merge-modal';
        modal.className = 'login-modal active';
        modal.setAttribute('onclick', "if(event.target===this){this.remove();}");
        modal.innerHTML = '<div class="login-box"></div>';
        document.body.appendChild(modal);
    }
    var box = modal.querySelector('.login-box');
    var esc = Community.esc;

    var items = [];
    if (data.topics) items.push(data.topics + ' topic' + (data.topics !== 1 ? 's' : ''));
    if (data.replies) items.push(data.replies + ' repl' + (data.replies !== 1 ? 'ies' : 'y'));
    if (data.dm_messages) items.push(data.dm_messages + ' DM' + (data.dm_messages !== 1 ? 's' : ''));
    if (data.likes) items.push(data.likes + ' like' + (data.likes !== 1 ? 's' : ''));
    if (data.bookmarks) items.push(data.bookmarks + ' bookmark' + (data.bookmarks !== 1 ? 's' : ''));
    if (data.roles && data.roles.length) items.push('Roles: ' + data.roles.join(', '));
    if (!items.length) items.push('No content to transfer');

    box.innerHTML = '<button class="login-close" onclick="this.closest(\'.login-modal\').remove()">&times;</button>'
        + '<h2>Confirm Merge</h2>'
        + '<div style="background:#fff3cd;color:#856404;padding:12px;border-radius:8px;font-size:0.85rem;margin-bottom:16px;">'
        + '<strong>This cannot be undone.</strong> All content from <strong>' + esc(data.target_name || '') + '</strong>'
        + (data.target_email ? ' (' + esc(data.target_email) + ')' : '')
        + ' will be merged into your current account, and that account will be disabled.'
        + '</div>'
        + '<div style="margin-bottom:16px;font-size:0.85rem;color:#333;">'
        + '<strong>Content to transfer:</strong><ul style="margin:6px 0;padding-left:20px;">'
        + items.map(function(i) { return '<li>' + esc(i) + '</li>'; }).join('')
        + '</ul></div>'
        + '<div style="display:flex;gap:10px;">'
        + '<button class="btn btn-outline" style="flex:1;" onclick="document.getElementById(\'merge-modal\').remove()">Cancel</button>'
        + '<button class="btn btn-primary" style="flex:1;background:#c0392b;" id="merge-confirm-btn">Merge Now</button>'
        + '</div>';

    setTimeout(function() {
        document.getElementById('merge-confirm-btn').addEventListener('click', function() {
            CommunityProfile.confirmMerge(data.token);
        });
    }, 50);
};

CommunityProfile.confirmMerge = function(token) {
    var btn = document.getElementById('merge-confirm-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Merging...'; }

    Community.api('/api/account-merge.php', {
        method: 'POST',
        body: { action: 'merge_confirm', token: token }
    }).then(function(data) {
        if (data.error) { alert(data.error); if (btn) { btn.disabled = false; btn.textContent = 'Merge Now'; } return; }
        var modal = document.getElementById('merge-modal');
        if (modal) {
            var box = modal.querySelector('.login-box');
            box.innerHTML = '<h2 style="color:#27ae60;">Merge Complete</h2>'
                + '<p style="text-align:center;font-size:0.9rem;color:#333;">All content has been merged into your account.</p>'
                + '<button class="btn btn-primary" style="width:100%;margin-top:16px;" onclick="document.getElementById(\'merge-modal\').remove();CommunityProfile.show();">Done</button>';
        }
    }).catch(function() { alert('Merge failed. Please try again.'); if (btn) { btn.disabled = false; btn.textContent = 'Merge Now'; } });
};

// ── Handle #merge-confirm route (after OAuth merge flow) ──
CommunityProfile.handleMergeReturn = function() {
    Community.api('/api/account-merge.php', {
        method: 'POST',
        body: { action: 'merge_preview' }
    }).then(function(data) {
        if (data.error) { alert(data.error); window.location.hash = '#profile'; return; }
        CommunityProfile.showMergePreview(data);
    });
};

})();
