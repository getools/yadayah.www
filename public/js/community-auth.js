/* ── community-auth.js ── Login modal, OAuth, email auth, forgot/reset/verify ── */
(function() {
'use strict';

window.CommunityAuth = {};
CommunityAuth._notifyHelp = '';

// Load configurable notification help text
(function() {
    fetch('/api/site-config.php').then(function(r) { return r.json(); }).then(function(cfg) {
        if (cfg['comments-notify-help']) CommunityAuth._notifyHelp = cfg['comments-notify-help'];
    }).catch(function() {});
})();

// Ensure Community utilities exist even without community.js
if (typeof Community === 'undefined') window.Community = {};
if (!Community.esc) Community.esc = function(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; };
if (!Community.api) Community.api = function(url, opts) {
    opts = opts || {};
    if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
        opts.headers = opts.headers || {};
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(opts.body);
    }
    return fetch(url, opts).then(function(r) { return r.json(); });
};
if (!Community.showMsg) Community.showMsg = function(el, msg, cls) {
    if (!el) return;
    el.textContent = msg;
    el.className = cls || '';
    el.style.display = '';
};
if (!Community.initials) Community.initials = function(name) {
    if (!name) return '?';
    var p = name.trim().split(/\s+/);
    return p.length > 1 ? (p[0][0] + p[p.length - 1][0]).toUpperCase() : p[0].substring(0, 2).toUpperCase();
};

// ── SVG icons for OAuth buttons ──
var googleSvg = '<svg viewBox="0 0 48 48" style="width:18px;height:18px;"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59A14.5 14.5 0 0 1 9.5 24c0-1.59.28-3.14.76-4.59l-7.98-6.19A23.99 23.99 0 0 0 0 24c0 3.77.9 7.35 2.56 10.53l7.97-5.94z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 5.94C6.51 42.62 14.62 48 24 48z"/></svg>';
var msSvg = '<svg viewBox="0 0 21 21" style="width:18px;height:18px;"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>';
var xSvg = '<svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:#000;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231z"/></svg>';
var yahooSvg = '<svg viewBox="-143 145 512 512" style="width:22px;height:22px;"><circle cx="113" cy="401" r="256" fill="#720e9e"/><g fill="#fff" transform="translate(113,401) scale(1.1) translate(-113,-401)"><path d="M191.2,362.3c-6.3,1.7-63.6,46-67.4,56.8c-0.8,3.8,0.1,40.2,0.9,45.6c3.8,0.9,31,0.1,36,1l-0.6,11.2c-4.9-0.4-39.5-0.3-59.3-0.3c-10,0-42.3,1.1-52.2,0.8l1.9-10.7c5.4-0.4,28,1,32.9-4.2c2.5-2.6,1.7-37.2,0.9-43c-2.1-6.3-52.3-69-65.4-79.2H-15v-15.8H99.1v1.1c0.1,0,0.3,0,0.4,0.1l-0.4,2.6v12H64.7c15.3,22.3,37.3,49.3,46.7,62.3l46-41.5H130l-3.9-15.7h100.1l-0.7,1.1c0.1,0,0.2,0,0.4,0l-7.1,10.3c-0.1,0-0.2,0-0.2,0l-2.9,4.2h-18.5C194.7,361.5,192.6,362,191.2,362.3z M221.4,477.5l-8.4-0.6l-7.8-0.7l-0.1-16.8l9.8,1.2l8.8,0.4L221.4,477.5z M223.1,449.5l-15.3-1.2l-0.4-71.1c3.5,0.7,30.6,3.2,33.6,3.3L223.1,449.5z"/></g></svg>';

// ── Render auth bar ──
CommunityAuth.renderAuth = function() {
    var bar = document.getElementById('auth-bar');
    if (!bar) return;
    var user = Community.currentUser;
    if (user) {
        var av = user.user_avatar;
        var nm = user.user_name_display || '';
        var avEl = av
            ? '<img class="avatar" src="' + Community.esc(av) + '" alt="" onclick="window.location.hash=\'#profile\'" onerror="this.outerHTML=\'<span class=&quot;avatar-circle&quot; onclick=&quot;window.location.hash=\\\'#profile\\\'&quot;>' + Community.esc(Community.initials(nm)) + '</span>\'">'
            : '<span class="avatar-circle" onclick="window.location.hash=\'#profile\'">' + Community.esc(Community.initials(nm)) + '</span>';
        var notifyOn = user.user_email_notifications === true || user.user_email_notifications === 't';
        var notifyHelp = CommunityAuth._notifyHelp || 'When enabled, you will receive email notifications for topics you create or reply to.';
        bar.innerHTML = '<span id="notification-bell" class="notification-bell" onclick="window.location.hash=\'#messages\'" style="cursor:pointer"></span>'
            + avEl
            + '<span class="user-name" onclick="window.location.hash=\'#profile\'">' + Community.esc(nm) + '</span>'
            + '<label class="notify-toggle" title="' + Community.esc(notifyHelp) + '">'
            + '<span class="notify-label">Notifications</span>'
            + '<input type="checkbox" ' + (notifyOn ? 'checked' : '') + ' onchange="CommunityAuth.toggleNotifications(this.checked)">'
            + '<span class="notify-slider"></span>'
            + '</label>'
            + '<button class="btn btn-outline btn-sm" onclick="CommunityAuth.logout()">Logout</button>';
        // Show Deletions toggle for mods only
        var roles = user.roles || [];
        if (typeof roles === 'string') try { roles = JSON.parse(roles.replace('{','[').replace('}',']')); } catch(e) { roles = []; }
        var isMod = roles.indexOf('admin') >= 0 || roles.indexOf('moderator') >= 0;
        if (isMod) {
            var showDel = CommunityAuth._showDeletions || false;
            var notifyEl = bar.querySelector('.notify-toggle');
            var delHtml = '<label class="notify-toggle" title="Show removed topics and replies">'
                + '<span class="notify-label">Show Deletions</span>'
                + '<input type="checkbox" ' + (showDel ? 'checked' : '') + ' onchange="CommunityAuth.toggleShowDeletions(this.checked)">'
                + '<span class="notify-slider"></span>'
                + '</label>';
            if (notifyEl) notifyEl.insertAdjacentHTML('afterend', delHtml);
        }
        if (typeof CommunityNotifications !== 'undefined') CommunityNotifications.renderBell();
    } else {
        bar.innerHTML = '<button class="btn btn-primary" onclick="CommunityAuth.openLoginModal(\'login\')">Sign In</button>';
    }
    var catNav = document.getElementById('nav-categories');
    if (catNav) {
        var isMod = user && (user.roles.indexOf('admin') >= 0 || user.roles.indexOf('moderator') >= 0);
        catNav.style.display = isMod ? '' : 'none';
    }
};

// ── Logout ──
CommunityAuth.logout = function() {
    Community.api('/api/community-session.php', {
        method: 'POST',
        body: { action: 'logout' }
    }).then(function() {
        Community.currentUser = null;
        CommunityAuth.renderAuth();
        if (typeof CommunityNotifications !== 'undefined') CommunityNotifications.stopPolling();
        if (window.location.pathname.indexOf('/chat') >= 0) window.location.hash = '#topics';
        else window.location.reload();
    });
};

CommunityAuth.toggleNotifications = function(on) {
    Community.api('/api/community-profile.php', {
        method: 'POST',
        body: { action: 'update', email_notifications: on }
    }).then(function(d) {
        if (d.error) { alert(d.error); return; }
        if (Community.currentUser) Community.currentUser.user_email_notifications = on;
    });
};

CommunityAuth._showDeletions = false;
CommunityAuth.toggleShowDeletions = function(on) {
    CommunityAuth._showDeletions = on;
    // Reload current view to apply filter
    if (typeof Community !== 'undefined' && Community.route) Community.route();
};

// ── OAuth popup ──
CommunityAuth.oauthPopup = function(provider) {
    var url = '/api/oauth-login.php?provider=' + encodeURIComponent(provider)
        + '&return=' + encodeURIComponent(window.location.pathname)
        + '&popup=1';
    var w = 500, h = 600;
    var left = (screen.width - w) / 2, top = (screen.height - h) / 2;
    window.open(url, 'oauth_popup', 'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top + ',toolbar=no,menubar=no');
};

// Listen for OAuth popup completion
window.addEventListener('message', function(e) {
    if (e.origin !== window.location.origin) return;
    if (e.data && e.data.type === 'oauth_complete') {
        CommunityAuth.closeLoginModal();
        Community.api('/api/community-session.php').then(function(d) {
            Community.currentUser = d.user;
            CommunityAuth.renderAuth();
            CommunityAuth.renderVerifyBanner();
            if (d.user && typeof CommunityNotifications !== 'undefined') CommunityNotifications.startPolling();
            if (typeof Community.route === 'function') Community.route();
            if (typeof VideoComments !== 'undefined') VideoComments.load();
        });
    }
});

// ── Login Modal ──
CommunityAuth.openLoginModal = function(mode) {
    var box = document.getElementById('login-box');
    var esc = Community.esc;

    if (mode === 'forgot') {
        var prefill = CommunityAuth._prefillEmail || '';
        CommunityAuth._prefillEmail = '';
        box.innerHTML = '<button class="login-close" onclick="CommunityAuth.closeLoginModal()">&times;</button>'
            + '<h2>Reset Password</h2>'
            + '<div id="login-error" class="login-error" style="display:none"></div>'
            + '<div id="forgot-success" style="display:none;text-align:center;padding:10px 0;color:#155724;font-size:0.9rem;"></div>'
            + '<p style="font-size:0.85rem;color:#666;text-align:center;margin:0 0 16px;">Enter your email and we\'ll send you a reset link.</p>'
            + '<input id="auth-email" type="email" placeholder="Email address" value="' + esc(prefill) + '">'
            + '<button class="btn btn-primary" style="width:100%;" onclick="CommunityAuth.submitForgot()">Send Reset Link</button>'
            + '<div class="login-toggle"><a onclick="CommunityAuth.openLoginModal(\'login\')">Back to sign in</a></div>';
        document.getElementById('login-modal').classList.add('active');
        return;
    }

    if (mode === 'reset') {
        var token = window._resetToken || '';
        box.innerHTML = '<button class="login-close" onclick="CommunityAuth.closeLoginModal()">&times;</button>'
            + '<h2>Set New Password</h2>'
            + '<div id="login-error" class="login-error" style="display:none"></div>'
            + '<div id="reset-success" style="display:none;text-align:center;padding:10px 0;color:#155724;font-size:0.9rem;"></div>'
            + '<input id="reset-pass" type="password" placeholder="New password">'
            + '<input id="reset-pass2" type="password" placeholder="Confirm password">'
            + '<label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;color:#555;cursor:pointer;margin:4px 0 10px;white-space:nowrap;"><input type="checkbox" style="width:auto;margin:0;" onchange="var t=this.checked?\'text\':\'password\';document.getElementById(\'reset-pass\').type=t;document.getElementById(\'reset-pass2\').type=t;"> Show password</label>'
            + '<input type="hidden" id="reset-token" value="' + esc(token) + '">'
            + '<button class="btn btn-primary" style="width:100%;" onclick="CommunityAuth.submitReset()">Reset Password</button>';
        document.getElementById('login-modal').classList.add('active');
        return;
    }

    var isLogin = mode === 'login';
    box.innerHTML = '<button class="login-close" onclick="CommunityAuth.closeLoginModal()">&times;</button>'
        + '<h2>' + (isLogin ? 'Sign In' : 'Create Account') + '</h2>'
        + '<div class="login-social">'
        + '<a class="btn btn-google" href="#" onclick="CommunityAuth.oauthPopup(\'google\');return false;">' + googleSvg + ' Google</a>'
        + '<a class="btn btn-microsoft" href="#" onclick="CommunityAuth.oauthPopup(\'microsoft\');return false;">' + msSvg + ' Microsoft</a>'
        + '<a class="btn btn-yahoo" href="#" onclick="CommunityAuth.oauthPopup(\'yahoo\');return false;">' + yahooSvg + ' Yahoo</a>'
        + '<a class="btn btn-x" href="#" onclick="CommunityAuth.oauthPopup(\'x\');return false;">' + xSvg + '</a>'
        + '</div>'
        + '<div class="login-or">or</div>'
        + '<div id="login-error" class="login-error" style="display:none"></div>'
        + (isLogin ? '' : '<input id="auth-name" type="text" placeholder="Display name">')
        + '<input id="auth-email" type="email" placeholder="Email address">'
        + '<div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;"><input id="auth-pass" type="password" placeholder="Password" style="flex:1;margin-bottom:0;">'
        + '<button type="button" class="pass-toggle-btn" onclick="var p=document.getElementById(\'auth-pass\');if(p.type===\'password\'){p.type=\'text\';this.style.color=\'#31345A\';}else{p.type=\'password\';this.style.color=\'#999\';}" style="background:none;border:1px solid #ddd;border-radius:6px;cursor:pointer;font-size:1.1rem;color:#999;padding:6px 10px;flex-shrink:0;" title="Show password">&#128065;</button></div>'
        + '<button class="btn btn-primary" style="width:100%;" onclick="CommunityAuth.submitEmailAuth(\'' + mode + '\')">' + (isLogin ? 'Sign In' : 'Create Account') + '</button>'
        + (isLogin ? '<div style="text-align:center;margin-top:10px;"><a style="font-size:0.82rem;color:#31345A;cursor:pointer;" onclick="CommunityAuth._prefillEmail=(document.getElementById(\'auth-email\')||{}).value||\'\';CommunityAuth.openLoginModal(\'forgot\')">Forgot password?</a></div>' : '')
        + '<div class="login-toggle">' + (isLogin
            ? 'No account? <a onclick="CommunityAuth.openLoginModal(\'register\')">Create one</a>'
            : 'Already have an account? <a onclick="CommunityAuth.openLoginModal(\'login\')">Sign in</a>') + '</div>';
    document.getElementById('login-modal').classList.add('active');

    // Focus first input and wire Enter key on password
    setTimeout(function() {
        var first = box.querySelector('input');
        if (first) first.focus();
        var passEl = document.getElementById('auth-pass');
        if (passEl) passEl.addEventListener('keydown', function(e) { if (e.key === 'Enter') CommunityAuth.submitEmailAuth(mode); });
    }, 100);
};

CommunityAuth.closeLoginModal = function() {
    document.getElementById('login-modal').classList.remove('active');
};

// ── Submit email auth ──
CommunityAuth.submitEmailAuth = function(mode) {
    var email = document.getElementById('auth-email').value.trim();
    var pass = document.getElementById('auth-pass').value;
    var name = mode === 'register' ? (document.getElementById('auth-name').value.trim()) : '';
    var errEl = document.getElementById('login-error');

    if (mode === 'register' && !name) { Community.showMsg(errEl, 'Name is required', 'login-error'); return; }
    if (!email) { Community.showMsg(errEl, 'Email is required', 'login-error'); return; }
    if (!pass) { Community.showMsg(errEl, 'Password is required', 'login-error'); return; }

    Community.api('/api/community-auth.php', {
        method: 'POST',
        body: { action: mode, email: email, password: pass, name: name }
    }).then(function(data) {
        if (data.error) {
            Community.showMsg(errEl, data.error, 'login-error');
        } else {
            CommunityAuth.closeLoginModal();
            Community.api('/api/community-session.php').then(function(d) {
                Community.currentUser = d.user;
                CommunityAuth.renderAuth();
                CommunityAuth.renderVerifyBanner();
                if (d.user && typeof CommunityNotifications !== 'undefined') CommunityNotifications.startPolling();
                if (typeof Community.route === 'function') Community.route();
                if (typeof VideoComments !== 'undefined') VideoComments.load();
            });
        }
    }).catch(function() {
        Community.showMsg(errEl, 'Connection error. Please try again.', 'login-error');
    });
};

// ── Forgot password ──
CommunityAuth.submitForgot = function() {
    var email = document.getElementById('auth-email').value.trim();
    var errEl = document.getElementById('login-error');
    if (!email) { Community.showMsg(errEl, 'Email is required', 'login-error'); return; }
    Community.api('/api/community-auth.php', {
        method: 'POST',
        body: { action: 'forgot', email: email }
    }).then(function(data) {
        if (data.error) {
            Community.showMsg(errEl, data.error, 'login-error');
        } else {
            var box = document.getElementById('login-box');
            box.innerHTML = '<button class="login-close" onclick="CommunityAuth.closeLoginModal()">&times;</button>'
                + '<div style="text-align:center;padding:20px 0;">'
                + '<div style="font-size:2rem;margin-bottom:12px;">&#9993;</div>'
                + '<h2 style="margin:0 0 12px;">Reset Link Sent</h2>'
                + '<p style="color:#555;font-size:0.95rem;">A password reset link has been sent to</p>'
                + '<p style="font-weight:600;color:#31345A;font-size:1rem;word-break:break-all;">' + Community.esc(email) + '</p>'
                + '<p style="color:#999;font-size:0.82rem;margin-top:16px;">Check your inbox and spam folder.<br>The link expires in 1 hour.</p>'
                + '<button class="btn btn-primary" style="width:100%;margin-top:16px;" onclick="CommunityAuth.closeLoginModal()">OK</button>'
                + '</div>';
        }
    }).catch(function() {
        Community.showMsg(errEl, 'Connection error.', 'login-error');
    });
};

// ── Reset password ──
CommunityAuth.submitReset = function() {
    var pass = document.getElementById('reset-pass').value;
    var pass2 = document.getElementById('reset-pass2').value;
    var token = document.getElementById('reset-token').value;
    var errEl = document.getElementById('login-error');
    if (!pass) { Community.showMsg(errEl, 'Password is required', 'login-error'); return; }
    if (pass !== pass2) { Community.showMsg(errEl, 'Passwords do not match', 'login-error'); return; }
    Community.api('/api/community-auth.php', {
        method: 'POST',
        body: { action: 'reset', token: token, password: pass }
    }).then(function(data) {
        if (data.error) {
            Community.showMsg(errEl, data.error, 'login-error');
        } else {
            errEl.style.display = 'none';
            var suc = document.getElementById('reset-success');
            suc.textContent = data.message;
            suc.style.display = '';
            setTimeout(function() { CommunityAuth.openLoginModal('login'); }, 2000);
        }
    }).catch(function() {
        Community.showMsg(errEl, 'Connection error.', 'login-error');
    });
};

// ── Verify email ──
CommunityAuth.verifyEmail = function(token) {
    Community.api('/api/community-auth.php', {
        method: 'POST',
        body: { action: 'verify', token: token }
    }).then(function(data) {
        var banner = document.getElementById('verify-banner');
        if (data.success) {
            banner.className = 'verify-banner';
            banner.style.background = '#d4edda';
            banner.style.color = '#155724';
            banner.innerHTML = data.message;
            banner.style.display = '';
            if (Community.currentUser) Community.currentUser.user_verified = true;
            setTimeout(function() { banner.style.display = 'none'; }, 5000);
        } else {
            banner.className = 'verify-banner';
            banner.innerHTML = data.error;
            banner.style.display = '';
        }
    });
};

// ── Verify banner ──
CommunityAuth.renderVerifyBanner = function() {
    var banner = document.getElementById('verify-banner');
    if (!banner) return;
    var user = Community.currentUser;
    if (user && (user.user_verified === false || user.user_verified === 'f')) {
        banner.className = 'verify-banner';
        banner.innerHTML = 'Please verify your email to post in the Community. Check your inbox.'
            + ' <a onclick="CommunityAuth.resendVerify()">Resend email</a>';
        banner.style.display = '';
    } else {
        banner.style.display = 'none';
    }
};

CommunityAuth.resendVerify = function() {
    Community.api('/api/community-auth.php', {
        method: 'POST',
        body: { action: 'resend_verify' }
    }).then(function(data) {
        var banner = document.getElementById('verify-banner');
        if (data.success) {
            banner.style.background = '#d4edda';
            banner.style.color = '#155724';
            banner.innerHTML = data.message;
            setTimeout(function() { CommunityAuth.renderVerifyBanner(); }, 3000);
        } else {
            banner.innerHTML = data.error;
        }
    });
};

// ── Link Account Prompt ──
// Shown when OAuth callback finds an email match and redirects to #link-account
CommunityAuth.showLinkPrompt = function() {
    // Check if there's a pending link in the session
    Community.api('/api/user-auth-link.php', {
        method: 'POST',
        body: { action: 'check_pending' }
    }).then(function(data) {
        if (!data.pending) {
            window.location.hash = '#topics';
            return;
        }

        var providerLabels = { google: 'Google', microsoft: 'Microsoft', yahoo: 'Yahoo', x: 'X', facebook: 'Facebook' };
        var providerName = providerLabels[data.provider] || data.provider;

        var modal = document.getElementById('login-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.className = 'login-modal active';
            modal.id = 'login-modal';
            document.body.appendChild(modal);
        }

        var box = modal.querySelector('.login-box');
        if (!box) {
            box = document.createElement('div');
            box.className = 'login-box';
            modal.appendChild(box);
        }

        box.innerHTML = '<h2>Link Account?</h2>'
            + '<p style="font-size:0.9rem;color:#333;text-align:center;margin:0 0 8px;">'
            + 'Your <strong>' + Community.esc(providerName) + '</strong> account (<strong>' + Community.esc(data.email) + '</strong>) '
            + 'matches an existing account' + (data.existing_name ? ' for <strong>' + Community.esc(data.existing_name) + '</strong>' : '') + '.</p>'
            + '<p style="font-size:0.85rem;color:#666;text-align:center;margin:0 0 20px;">'
            + 'Would you like to link your ' + Community.esc(providerName) + ' login to that account?</p>'
            + '<div id="link-prompt-msg" style="display:none;text-align:center;margin-bottom:12px;font-size:0.85rem;"></div>';

        // If user is logged in, they can confirm directly
        if (Community.currentUser) {
            box.innerHTML += '<div style="display:flex;gap:10px;justify-content:center;">'
                + '<button class="btn btn-primary" onclick="CommunityAuth.confirmLink()">Link Account</button>'
                + '<button class="btn btn-outline" onclick="CommunityAuth.declineLink()">Create Separate Account</button>'
                + '</div>';
        } else {
            // User needs to sign in first to prove they own the existing account
            box.innerHTML += '<p style="font-size:0.85rem;color:#c0392b;text-align:center;margin:0 0 12px;">'
                + 'To link, please sign in with your existing account first.</p>'
                + '<div id="link-login-error" class="login-error" style="display:none"></div>'
                + '<input id="link-email" type="email" placeholder="Email address" value="' + Community.esc(data.email) + '">'
                + '<input id="link-pass" type="password" placeholder="Password">'
                + '<div style="display:flex;gap:10px;justify-content:center;margin-top:8px;">'
                + '<button class="btn btn-primary" onclick="CommunityAuth.linkLogin()">Sign In & Link</button>'
                + '<button class="btn btn-outline" onclick="CommunityAuth.declineLink()">Create Separate Account</button>'
                + '</div>';
            setTimeout(function() {
                var passEl = document.getElementById('link-pass');
                if (passEl) passEl.addEventListener('keydown', function(e) { if (e.key === 'Enter') CommunityAuth.linkLogin(); });
            }, 100);
        }

        modal.classList.add('active');
    });
};

CommunityAuth.confirmLink = function() {
    var msgEl = document.getElementById('link-prompt-msg');
    Community.api('/api/user-auth-link.php', {
        method: 'POST',
        body: { action: 'confirm_link' }
    }).then(function(data) {
        if (data.error) {
            if (msgEl) { msgEl.style.display = ''; msgEl.style.color = '#c0392b'; msgEl.textContent = data.error; }
            return;
        }
        // Success — reload as the linked user
        location.reload();
    });
};

CommunityAuth.declineLink = function() {
    Community.api('/api/user-auth-link.php', {
        method: 'POST',
        body: { action: 'create_new' }
    }).then(function(data) {
        if (data.error) { alert(data.error); return; }
        location.reload();
    });
};

CommunityAuth.linkLogin = function() {
    var email = document.getElementById('link-email').value.trim();
    var pass = document.getElementById('link-pass').value;
    var errEl = document.getElementById('link-login-error');
    if (!email || !pass) { if (errEl) { errEl.textContent = 'Email and password required'; errEl.style.display = ''; } return; }

    Community.api('/api/community-auth.php', {
        method: 'POST',
        body: { action: 'login', email: email, password: pass }
    }).then(function(data) {
        if (data.error) {
            if (errEl) { errEl.textContent = data.error; errEl.style.display = ''; }
            return;
        }
        // Now logged in — confirm the link
        CommunityAuth.confirmLink();
    });
};

})();
