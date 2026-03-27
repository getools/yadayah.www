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
            html += '<p style="font-size:0.85rem;color:#666;margin:0 0 12px;">You signed in with ' + esc(u.user_oauth_provider || 'social login') + '. Set a password to also enable email login.</p>';
        }
        html += '<div class="profile-field"><label>New Password</label><input id="pw-new" type="password" placeholder="At least 6 characters"></div>';
        html += '<div class="profile-field"><label>Confirm New Password</label><input id="pw-confirm" type="password"></div>';
        html += '<div class="profile-actions"><button class="btn btn-primary" onclick="CommunityProfile.changePassword(' + (u.has_password ? 'true' : 'false') + ')">Update Password</button></div>';
        html += '</div>';

        el.innerHTML = html;
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
