/* ── admin-login.js ── OAuth + email login modal for admin (same markup as community) ── */
(function() {
'use strict';

// Same helpers as community.js
function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
function showMsg(el, msg) {
    if (!el) { alert(msg); return; }
    // Reset to trigger animation even if same message
    el.style.transition = 'none';
    el.style.opacity = '0';
    el.style.transform = 'translateY(-8px)';
    el.textContent = msg;
    el.style.display = 'block';
    el.style.color = '#c0392b';
    el.style.fontSize = '0.85rem';
    el.style.textAlign = 'center';
    el.style.marginBottom = '10px';
    el.style.padding = '8px';
    el.style.background = '#fef2f2';
    el.style.borderRadius = '6px';
    el.style.border = '1px solid #fecaca';
    // Force reflow then animate
    void el.offsetWidth;
    el.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
    el.style.opacity = '1';
    el.style.transform = 'translateY(0)';
}

// Same SVGs as community-auth.js
var googleSvg = '<svg viewBox="0 0 48 48" style="width:18px;height:18px;"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59A14.5 14.5 0 0 1 9.5 24c0-1.59.28-3.14.76-4.59l-7.98-6.19A23.99 23.99 0 0 0 0 24c0 3.77.9 7.35 2.56 10.53l7.97-5.94z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 5.94C6.51 42.62 14.62 48 24 48z"/></svg>';
var msSvg = '<svg viewBox="0 0 21 21" style="width:18px;height:18px;"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>';
var xSvg = '<svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:#000;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231z"/></svg>';
var yahooSvg = '<svg viewBox="-143 145 512 512" style="width:22px;height:22px;"><circle cx="113" cy="401" r="256" fill="#720e9e"/><g fill="#fff" transform="translate(113,401) scale(1.1) translate(-113,-401)"><path d="M191.2,362.3c-6.3,1.7-63.6,46-67.4,56.8c-0.8,3.8,0.1,40.2,0.9,45.6c3.8,0.9,31,0.1,36,1l-0.6,11.2c-4.9-0.4-39.5-0.3-59.3-0.3c-10,0-42.3,1.1-52.2,0.8l1.9-10.7c5.4-0.4,28,1,32.9-4.2c2.5-2.6,1.7-37.2,0.9-43c-2.1-6.3-52.3-69-65.4-79.2H-15v-15.8H99.1v1.1c0.1,0,0.3,0,0.4,0.1l-0.4,2.6v12H64.7c15.3,22.3,37.3,49.3,46.7,62.3l46-41.5H130l-3.9-15.7h100.1l-0.7,1.1c0.1,0,0.2,0,0.4,0l-7.1,10.3c-0.1,0-0.2,0-0.2,0l-2.9,4.2h-18.5C194.7,361.5,192.6,362,191.2,362.3z M221.4,477.5l-8.4-0.6l-7.8-0.7l-0.1-16.8l9.8,1.2l8.8,0.4L221.4,477.5z M223.1,449.5l-15.3-1.2l-0.4-71.1c3.5,0.7,30.6,3.2,33.6,3.3L223.1,449.5z"/></g></svg>';

// Hide old login screen and remove its login-error to avoid duplicate IDs
var oldScreen = document.getElementById('login-screen');
if (oldScreen) {
    oldScreen.style.display = 'none';
    var oldErr = oldScreen.querySelector('#login-error');
    if (oldErr) oldErr.removeAttribute('id');
}

// Create modal — same structure as community.html
var modal = document.createElement('div');
modal.className = 'login-modal';
modal.id = 'login-modal';
modal.setAttribute('onclick', "if(event.target===this)return");
modal.innerHTML = '<div class="login-box" id="login-box"></div>';
document.body.appendChild(modal);

// Check auth
fetch('/api/auth.php', { credentials: 'include' })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.authenticated && d.pages && Object.keys(d.pages).length > 0) {
            // OK
        } else if (d.authenticated) {
            showNoAccess();
        } else {
            openLoginModal('login');
        }
    });

function showNoAccess() {
    var box = document.getElementById('login-box');
    box.innerHTML = '<h2>Access Denied</h2>'
        + '<p style="text-align:center;color:#666;font-size:0.9rem;margin:0 0 16px;">Your account does not have admin access.</p>'
        + '<button class="btn btn-primary" style="width:100%;" onclick="doLogout()">Sign Out</button>';
    modal.classList.add('active');
}

// Same markup as CommunityAuth.openLoginModal
function openLoginModal(mode) {
    var box = document.getElementById('login-box');
    var returnParam = '?return=' + encodeURIComponent(window.location.pathname);

    if (mode === 'forgot') {
        box.innerHTML = '<button class="login-close" onclick="AdminLogin.open(\'login\')">&times;</button>'
            + '<h2>Reset Password</h2>'
            + '<div id="login-error" class="login-error" style="display:none"></div>'
            + '<div id="forgot-success" style="display:none;text-align:center;padding:10px 0;color:#155724;font-size:0.9rem;"></div>'
            + '<p style="font-size:0.85rem;color:#666;text-align:center;margin:0 0 16px;">Enter your email and we\'ll send you a reset link.</p>'
            + '<input id="auth-email" type="email" placeholder="Email address">'
            + '<button class="btn btn-primary" style="width:100%;" onclick="AdminLogin.submitForgot()">Send Reset Link</button>'
            + '<div class="login-toggle"><a onclick="AdminLogin.open(\'login\')">Back to sign in</a></div>';
        modal.classList.add('active');
        setTimeout(function() { var el = document.getElementById('auth-email'); if (el) el.focus(); }, 100);
        return;
    }

    box.innerHTML = '<h2>Admin Sign In</h2>'
        + '<div class="login-social">'
        + '<a class="btn btn-google" href="/api/oauth-login.php' + returnParam + '&provider=google">' + googleSvg + ' Google</a>'
        + '<a class="btn btn-microsoft" href="/api/oauth-login.php' + returnParam + '&provider=microsoft">' + msSvg + ' Microsoft</a>'
        + '<a class="btn btn-yahoo" href="/api/oauth-login.php' + returnParam + '&provider=yahoo">' + yahooSvg + ' Yahoo</a>'
        + '<a class="btn btn-x" href="/api/oauth-login.php' + returnParam + '&provider=x">' + xSvg + '</a>'
        + '</div>'
        + '<div class="login-or">or</div>'
        + '<div id="login-error" class="login-error" style="display:none"></div>'
        + '<input id="auth-email" type="email" placeholder="Email address">'
        + '<div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;"><input id="auth-pass" type="password" placeholder="Password" style="flex:1;margin-bottom:0;">'
        + '<button type="button" id="pass-toggle" style="background:none;border:1px solid #ddd;border-radius:6px;cursor:pointer;font-size:1.1rem;color:#999;padding:6px 10px;flex-shrink:0;" title="Show password">&#128065;</button></div>'
        + '<button class="btn btn-primary" style="width:100%;" id="admin-signin-btn">Sign In</button>'
        + '<div style="text-align:center;margin-top:10px;"><a style="font-size:0.82rem;color:#31345A;cursor:pointer;" id="admin-forgot-link">Forgot password?</a></div>';
    modal.classList.add('active');

    setTimeout(function() {
        var el = document.getElementById('auth-email');
        if (el) el.focus();
        document.getElementById('admin-signin-btn').addEventListener('click', function() { AdminLogin.submitEmail(); });
        document.getElementById('admin-forgot-link').addEventListener('click', function() { openLoginModal('forgot'); });
        var passEl = document.getElementById('auth-pass');
        if (passEl) passEl.addEventListener('keydown', function(e) { if (e.key === 'Enter') AdminLogin.submitEmail(); });
        var toggleBtn = document.getElementById('pass-toggle');
        if (toggleBtn) toggleBtn.addEventListener('click', function() {
            var inp = document.getElementById('auth-pass');
            if (inp.type === 'password') { inp.type = 'text'; this.style.color = '#31345A'; }
            else { inp.type = 'password'; this.style.color = '#999'; }
        });
    }, 100);
}

window.AdminLogin = {
    open: openLoginModal,

    submitEmail: function() {
        var email = document.getElementById('auth-email').value.trim();
        var pass = document.getElementById('auth-pass').value;
        var errEl = document.getElementById('login-error');
        console.log('submitEmail called', { email: email, hasPass: !!pass, errEl: errEl });
        if (!email) { showMsg(errEl, 'Email is required'); return; }
        if (!pass) { showMsg(errEl, 'Password is required'); return; }
        fetch('/api/community-auth.php', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'login', email: email, password: pass })
        }).then(function(r) {
            console.log('fetch response', r.status);
            return r.json();
        }).then(function(data) {
            console.log('response data', data);
            if (data.error) { showMsg(errEl, data.error); }
            else { location.reload(); }
        }).catch(function(e) {
            console.error('fetch error', e);
            showMsg(errEl, 'Connection error. Please try again.');
        });
    },

    submitForgot: function() {
        var email = document.getElementById('auth-email').value.trim();
        var errEl = document.getElementById('login-error');
        if (!email) { showMsg(errEl, 'Email is required', 'login-error'); return; }
        fetch('/api/community-auth.php', {
            method: 'POST', credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'forgot', email: email })
        }).then(function(r) { return r.json(); }).then(function(data) {
            if (data.error) { showMsg(errEl, data.error, 'login-error'); }
            else {
                errEl.style.display = 'none';
                var suc = document.getElementById('forgot-success');
                suc.textContent = data.message;
                suc.style.display = '';
            }
        }).catch(function() { showMsg(errEl, 'Connection error.', 'login-error'); });
    }
};

window.doLogout = function() {
    fetch('/api/community-session.php', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'logout' })
    }).then(function() {
        return fetch('/api/auth.php', { method: 'DELETE', credentials: 'include' });
    }).then(function() { location.reload(); });
};

})();
