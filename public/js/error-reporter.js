// error-reporter.js
// Reports JS errors and unhandled promise rejections to the server.
// Filters out known third-party deprecation warnings we cannot fix.

(function () {
  'use strict';

  // Patterns for errors we should NOT report (third-party, unfixable deprecations, etc.)
  var IGNORE_PATTERNS = [
    // TinyMCE deprecation warnings we cannot fix
    /mozInputSource is deprecated/i,
    /tinymce/i,
    // Media autoplay policy (browser-enforced, not a code bug)
    /play method is not allowed by the user agent/i,
    /The play\(\) request was interrupted/i
  ];

  // Files whose errors we should not report (third-party bundles)
  var IGNORE_FILES = [
    /tinymce/i
  ];

  function shouldIgnore(message, source) {
    var msg = message || '';
    var src = source || '';

    for (var i = 0; i < IGNORE_PATTERNS.length; i++) {
      if (IGNORE_PATTERNS[i].test(msg)) return true;
    }
    for (var j = 0; j < IGNORE_FILES.length; j++) {
      if (IGNORE_FILES[j].test(src)) return true;
    }
    return false;
  }

  // POSTs to /api/client-error.php in the format that endpoint expects:
  //   { errors: [{ type, message, source, line, col, stack, page, ua }, ...] }
  // The previous URL ("/api/log-error") was non-existent and caused Apache
  // to hit LimitInternalRecursion (10 internal redirects) — every JS error
  // triggered a 500 + redirect storm that locked admin-feeds and similar
  // pages. Fixed 2026-05-03.
  function sendError(payload) {
    try {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', '/api/client-error.php', true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.send(JSON.stringify({ errors: [payload] }));
    } catch (e) {
      // Never throw from error reporter
    }
  }

  // Global error handler
  window.addEventListener('error', function (event) {
    var message = (event.message || '') + '';
    var source  = (event.filename || '') + '';

    if (shouldIgnore(message, source)) return;

    sendError({
      type:    'js_error',
      message: message,
      source:  source,
      line:    event.lineno,
      col:     event.colno,
      stack:   event.error && event.error.stack ? event.error.stack : null,
      page:    window.location.pathname + window.location.hash,
      ua:      navigator.userAgent
    });
  });

  // Unhandled promise rejection handler
  window.addEventListener('unhandledrejection', function (event) {
    var reason  = event.reason || {};
    var message = (reason.message || reason + '') + '';
    var source  = (reason.fileName || '') + '';

    if (shouldIgnore(message, source)) return;

    sendError({
      type:    'promise_rejection',
      message: message,
      source:  source,
      stack:   reason.stack || null,
      page:    window.location.pathname + window.location.hash,
      ua:      navigator.userAgent
    });
  });

}());
