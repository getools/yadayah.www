/* error-reporter.js — captures JS errors, fetch failures, resource errors, and sends to /api/client-error.php */
(function() {
'use strict';

var API = '/api/client-error.php';
var _sent = {};       // dedup by message hash
var _queue = [];      // batch queue
var _timer = null;
var MAX_PER_PAGE = 25; // max reports per page load
var _count = 0;

function hash(s) {
    var h = 0;
    for (var i = 0; i < s.length; i++) h = ((h << 5) - h + s.charCodeAt(i)) | 0;
    return h;
}

function enqueue(entry) {
    if (_count >= MAX_PER_PAGE) return;
    var key = hash(entry.message + (entry.source || '') + (entry.type || ''));
    if (_sent[key]) return;
    _sent[key] = true;
    _count++;
    entry.timestamp = new Date().toISOString();
    _queue.push(entry);
    if (!_timer) _timer = setTimeout(flush, 2000);
}

function flush() {
    _timer = null;
    if (!_queue.length) return;
    var batch = _queue.splice(0, 15);
    try {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', API, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.send(JSON.stringify({ errors: batch }));
    } catch(e) {}
}

// Skip noise patterns
function isNoise(msg) {
    if (!msg) return true;
    var noise = ['favicon', 'ResizeObserver', 'chrome-extension', 'moz-extension', 'safari-extension',
                 'Script error.', 'Non-Error promise rejection', 'Loading chunk', 'googletagmanager',
                 'gtag', 'analytics', 'adsbygoogle', 'googlesyndication',
                 '__firefox__', 'ethereum', 'MetaMask', 'cdn-cgi', 'cancelable=false',
                 '__gCrWeb', 'did not match the expected pattern'];
    for (var i = 0; i < noise.length; i++) {
        if (msg.indexOf(noise[i]) >= 0) return true;
    }
    return false;
}

// ── 1. Global JS error handler ──
window.addEventListener('error', function(e) {
    if (!e.message) return;
    if (e.filename && (e.filename.indexOf('extension') >= 0 || e.filename.indexOf('chrome-extension') >= 0)) return;
    if (isNoise(e.message)) return;
    enqueue({
        type: 'js_error',
        message: e.message,
        source: e.filename || '',
        line: e.lineno || 0,
        col: e.colno || 0,
        stack: e.error ? (e.error.stack || '').substring(0, 1500) : '',
        page: location.pathname + location.hash,
        ua: navigator.userAgent
    });
}, true);

// ── 2. Unhandled promise rejections ──
window.addEventListener('unhandledrejection', function(e) {
    var msg = '';
    if (e.reason) {
        msg = e.reason.message || e.reason.toString();
        if (msg === '[object Object]') { try { msg = JSON.stringify(e.reason).substring(0, 500); } catch(x) { msg = 'Unknown rejection'; } }
    }
    if (!msg || isNoise(msg)) return;
    enqueue({
        type: 'promise_rejection',
        message: msg.substring(0, 1000),
        stack: e.reason && e.reason.stack ? e.reason.stack.substring(0, 1500) : '',
        page: location.pathname + location.hash,
        ua: navigator.userAgent
    });
});

// ── 3. Console.error intercept ──
var _origError = console.error;
console.error = function() {
    _origError.apply(console, arguments);
    var parts = [];
    for (var i = 0; i < arguments.length; i++) {
        var a = arguments[i];
        if (a instanceof Error) parts.push(a.message + '\n' + (a.stack || ''));
        else if (typeof a === 'object') { try { parts.push(JSON.stringify(a).substring(0, 300)); } catch(e) { parts.push(String(a)); } }
        else parts.push(String(a));
    }
    var msg = parts.join(' ');
    if (!msg || msg.length < 5 || isNoise(msg)) return;
    enqueue({
        type: 'console_error',
        message: msg.substring(0, 500),
        page: location.pathname + location.hash,
        ua: navigator.userAgent
    });
};

// ── 4. Console.warn intercept (captures RAISE WARNING from PostgreSQL triggers via PHP) ──
var _origWarn = console.warn;
console.warn = function() {
    _origWarn.apply(console, arguments);
    var parts = [];
    for (var i = 0; i < arguments.length; i++) parts.push(String(arguments[i]));
    var msg = parts.join(' ');
    // Only capture warnings that look like errors (DB, SQL, PHP)
    if (msg.indexOf('Rev trigger') >= 0 || msg.indexOf('SQLSTATE') >= 0 || msg.indexOf('PDO') >= 0 || msg.indexOf('Fatal') >= 0) {
        enqueue({
            type: 'console_warn',
            message: msg.substring(0, 500),
            page: location.pathname + location.hash,
            ua: navigator.userAgent
        });
    }
};

// ── 5. Fetch/XHR error interceptor — catches API 4xx/5xx responses ──
if (window.fetch) {
    var _origFetch = window.fetch;
    window.fetch = function() {
        var url = arguments[0];
        if (typeof url === 'object' && url.url) url = url.url;
        url = String(url || '');
        // Only monitor our own API calls (skip external, analytics, etc.)
        var isOwnApi = url.indexOf('/api/') >= 0 || (url.charAt(0) === '/' && url.indexOf('//') !== 0);
        return _origFetch.apply(this, arguments).then(function(response) {
            if (isOwnApi && response.status >= 400) {
                // Skip 403 (IP ban / honeypot) and 401 (session expired) — not code bugs
                if (response.status === 403 || response.status === 401) return response;
                var detail = 'Status: ' + response.status + ' ' + response.statusText;
                // Try to read error body
                response.clone().text().then(function(body) {
                    var errorMsg = body.substring(0, 300);
                    try { var j = JSON.parse(body); if (j.error) errorMsg = j.error; } catch(e) {}
                    // Skip expected auth/login failures
                    var lc = errorMsg.toLowerCase();
                    if (response.status === 400 && (lc.indexOf('incorrect password') >= 0 || lc.indexOf('invalid password') >= 0
                        || lc.indexOf('wrong password') >= 0 || lc.indexOf('login failed') >= 0
                        || lc.indexOf('invalid credentials') >= 0 || lc.indexOf('already exists') >= 0)) return;
                    enqueue({
                        type: 'fetch_error',
                        message: 'HTTP ' + response.status + ' on ' + url.substring(0, 200),
                        source: url.substring(0, 500),
                        stack: detail + '\n' + errorMsg,
                        page: location.pathname + location.hash,
                        ua: navigator.userAgent
                    });
                }).catch(function() {
                    enqueue({
                        type: 'fetch_error',
                        message: 'HTTP ' + response.status + ' on ' + url.substring(0, 200),
                        source: url.substring(0, 500),
                        page: location.pathname + location.hash,
                        ua: navigator.userAgent
                    });
                });
            }
            return response;
        }).catch(function(err) {
            // Network failures (offline, CORS, DNS, timeout)
            if (isOwnApi) {
                enqueue({
                    type: 'network_error',
                    message: 'Fetch failed: ' + (err.message || err) + ' — ' + url.substring(0, 200),
                    source: url.substring(0, 500),
                    page: location.pathname + location.hash,
                    ua: navigator.userAgent
                });
            }
            throw err; // re-throw so callers still see the error
        });
    };
}

// ── 6. XMLHttpRequest error interceptor (jQuery, legacy code) ──
if (window.XMLHttpRequest) {
    var _origXhrOpen = XMLHttpRequest.prototype.open;
    var _origXhrSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function(method, url) {
        this._errUrl = String(url || '');
        this._errMethod = method;
        return _origXhrOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function() {
        var xhr = this;
        var url = xhr._errUrl || '';
        var isOwnApi = url.indexOf('/api/') >= 0 || (url.charAt(0) === '/' && url.indexOf('//') !== 0);
        if (isOwnApi) {
            xhr.addEventListener('loadend', function() {
                if (xhr.status >= 400) {
                    // Skip 403 (IP ban / honeypot) and 401 (session expired)
                    if (xhr.status === 403 || xhr.status === 401) return;
                    var errorMsg = '';
                    try { var j = JSON.parse(xhr.responseText); errorMsg = j.error || xhr.responseText.substring(0, 300); } catch(e) { errorMsg = (xhr.responseText || '').substring(0, 300); }
                    var lc = errorMsg.toLowerCase();
                    if (xhr.status === 400 && (lc.indexOf('incorrect password') >= 0 || lc.indexOf('invalid password') >= 0
                        || lc.indexOf('wrong password') >= 0 || lc.indexOf('login failed') >= 0
                        || lc.indexOf('invalid credentials') >= 0 || lc.indexOf('already exists') >= 0)) return;
                    enqueue({
                        type: 'xhr_error',
                        message: 'XHR ' + xhr.status + ' ' + xhr._errMethod + ' ' + url.substring(0, 200),
                        source: url.substring(0, 500),
                        stack: errorMsg,
                        page: location.pathname + location.hash,
                        ua: navigator.userAgent
                    });
                }
            });
            xhr.addEventListener('error', function() {
                enqueue({
                    type: 'network_error',
                    message: 'XHR network error: ' + xhr._errMethod + ' ' + url.substring(0, 200),
                    source: url.substring(0, 500),
                    page: location.pathname + location.hash,
                    ua: navigator.userAgent
                });
            });
            xhr.addEventListener('timeout', function() {
                enqueue({
                    type: 'timeout_error',
                    message: 'XHR timeout: ' + xhr._errMethod + ' ' + url.substring(0, 200),
                    source: url.substring(0, 500),
                    page: location.pathname + location.hash,
                    ua: navigator.userAgent
                });
            });
        }
        return _origXhrSend.apply(this, arguments);
    };
}

// ── 7. Resource load errors (images, scripts, stylesheets, fonts) ──
window.addEventListener('error', function(e) {
    var el = e.target;
    if (!el || el === window) return; // skip JS errors (handled above)
    if (el.tagName === 'SCRIPT' || el.tagName === 'LINK' || el.tagName === 'IMG' || el.tagName === 'VIDEO' || el.tagName === 'AUDIO' || el.tagName === 'SOURCE') {
        var src = el.src || el.href || '';
        if (!src || src.indexOf('extension') >= 0) return;
        // Skip external resources (CDNs, analytics, etc.) — only report our own
        if (src.indexOf(location.host) < 0 && src.charAt(0) !== '/') return;
        enqueue({
            type: 'resource_error',
            message: el.tagName + ' failed to load: ' + src.substring(0, 300),
            source: src.substring(0, 500),
            page: location.pathname + location.hash,
            ua: navigator.userAgent
        });
    }
}, true); // capture phase to catch resource errors

// ── 8. CSP violation reports ──
document.addEventListener('securitypolicyviolation', function(e) {
    enqueue({
        type: 'csp_violation',
        message: 'CSP: ' + e.violatedDirective + ' blocked ' + (e.blockedURI || '').substring(0, 200),
        source: e.sourceFile ? e.sourceFile + ':' + e.lineNumber : '',
        page: location.pathname + location.hash,
        ua: navigator.userAgent
    });
});

// ── 9. Performance: long tasks (>500ms blocks on main thread) ──
if (window.PerformanceObserver) {
    try {
        var longTaskObserver = new PerformanceObserver(function(list) {
            list.getEntries().forEach(function(entry) {
                if (entry.duration > 5000) {
                    enqueue({
                        type: 'long_task',
                        message: 'Long task: ' + Math.round(entry.duration) + 'ms',
                        source: entry.name || 'unknown',
                        page: location.pathname + location.hash,
                        ua: navigator.userAgent
                    });
                }
            });
        });
        longTaskObserver.observe({ type: 'longtask', buffered: true });
    } catch(e) {} // Not supported in all browsers
}

// ── 10. Deprecation warnings from browser ──
if (window.ReportingObserver) {
    try {
        var reportObserver = new ReportingObserver(function(reports) {
            reports.forEach(function(report) {
                if (report.type === 'deprecation' || report.type === 'intervention') {
                    enqueue({
                        type: 'browser_' + report.type,
                        message: report.body.message || report.type,
                        source: report.body.sourceFile ? report.body.sourceFile + ':' + report.body.lineNumber : '',
                        page: location.pathname + location.hash,
                        ua: navigator.userAgent
                    });
                }
            });
        }, { types: ['deprecation', 'intervention'], buffered: true });
        reportObserver.observe();
    } catch(e) {}
}

// ── Flush on page unload ──
window.addEventListener('beforeunload', function() {
    if (_queue.length) {
        try {
            navigator.sendBeacon(API, JSON.stringify({ errors: _queue }));
        } catch(e) {
            flush();
        }
    }
});

// ── Also flush on visibilitychange (mobile tab switch) ──
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'hidden' && _queue.length) {
        try {
            navigator.sendBeacon(API, JSON.stringify({ errors: _queue }));
            _queue = [];
        } catch(e) {}
    }
});

})();
