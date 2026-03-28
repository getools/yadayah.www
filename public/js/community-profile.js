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
        var nm = u.user_display_name || '';
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
        html += '<div class="profile-field"><label>New Password</label><input id="pw-new" type="password" placeholder="At least 6 characters"></div>';
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

        // Show buttons to link providers not yet linked
        var unlinked = allProviders.filter(function(p) { return linkedProviders.indexOf(p) < 0 && p !== 'email'; });
        if (unlinked.length) {
            html += '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
            for (var p = 0; p < unlinked.length; p++) {
                html += '<a class="btn btn-sm btn-outline" href="/api/oauth-login.php?provider=' + unlinked[p] + '&return=/community%23profile&link=1">'
                    + 'Link ' + esc(providerLabels[unlinked[p]]) + '</a>';
            }
            html += '</div>';
        }
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
            Community.currentUser.user_display_name = data.display_name;
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

})();
