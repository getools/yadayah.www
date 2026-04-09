// ── Save-button change tracking ──
// Watches .form-body and .form-card containers. When they become visible the
// primary Save button is disabled; it re-enables as soon as any field differs
// from the snapshot taken at open time. Call window.rearmForm(panel) after a
// successful save to reset the baseline and re-disable the button.
(function () {
    var _state = new WeakMap();

    function _snap(panel) {
        var vals = [];
        panel.querySelectorAll(
            'input:not([type=hidden]):not([type=button]):not([type=submit]),' +
            'select, textarea'
        ).forEach(function (f) {
            vals.push(f.type === 'checkbox' || f.type === 'radio' ? (f.checked ? '1' : '0') : f.value);
        });
        return vals.join('\x00');
    }

    function _arm(panel) {
        var btn = panel.querySelector('.btn-primary:not(.btn-sm)');
        if (!btn) return;
        var snap = _snap(panel);
        _state.set(panel, { btn: btn, snap: snap });
        btn.disabled = true;
    }

    function _check(panel) {
        var s = _state.get(panel);
        if (s) s.btn.disabled = (_snap(panel) === s.snap);
    }

    function _findPanel(el) {
        return el.closest('.form-body, .form-card');
    }

    function _tryArm(root) {
        (root.querySelectorAll ? root.querySelectorAll('.form-body, .form-card') : [])
            .forEach(function (p) {
                if (p.offsetHeight > 0) setTimeout(function () { _arm(p); }, 0);
            });
    }

    ['input', 'change'].forEach(function (evt) {
        document.addEventListener(evt, function (e) {
            var p = _findPanel(e.target);
            if (p) _check(p);
        }, true);
    });

    var _mo = new MutationObserver(function (muts) {
        muts.forEach(function (m) {
            if (m.type === 'attributes') _tryArm(m.target);
            m.addedNodes.forEach(function (n) {
                if (n.nodeType === 1) _tryArm(n);
            });
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        _mo.observe(document.body, {
            childList: true, subtree: true,
            attributes: true, attributeFilter: ['class', 'style']
        });
        _tryArm(document);
    });

    // Public: reset baseline after a successful save
    window.rearmForm = function (panel) {
        if (!panel) {
            document.querySelectorAll('.form-body, .form-card').forEach(function (p) {
                if (p.offsetHeight > 0) _arm(p);
            });
        } else {
            _arm(panel);
        }
    };
}());

// Admin page permission filtering
// Maps nav link href to yy_setting.setting_code
var PAGE_SETTING_MAP = {
    'admin-translation.html': 'translation',
    'admin-word.html': 'word',
    'admin-lookups.html': 'lookups',
    'admin-ask.html': 'ask',
    'admin-transcripts.html': 'transcripts',
    'admin-timeline.html': 'timeline',
    'admin-memorial.html': 'memorial',
    'admin-media.html': 'media',
    'admin-misc.html': 'misc',
    'admin-backgrounds.html': 'backgrounds',
    'admin-users.html': 'users',
    'admin-basics.html': 'basics',
    'admin-links.html': 'links'
};

function applyPagePermissions(pages) {
    if (!pages || typeof pages !== 'object') return;

    // Hide nav tabs the user doesn't have access to
    var tabs = document.querySelectorAll('.nav-tabs .nav-tab');
    for (var i = 0; i < tabs.length; i++) {
        var href = tabs[i].getAttribute('href');
        var code = PAGE_SETTING_MAP[href];
        if (code && pages[code] === false) {
            tabs[i].style.display = 'none';
        }
    }

    // If the current page is not allowed, redirect to the first allowed page
    var currentPage = location.pathname.split('/').pop();
    var currentCode = PAGE_SETTING_MAP[currentPage];
    if (currentCode && pages[currentCode] === false) {
        // Find first allowed page
        for (var j = 0; j < tabs.length; j++) {
            var h = tabs[j].getAttribute('href');
            var c = PAGE_SETTING_MAP[h];
            if (!c || pages[c] !== false) {
                location.href = h;
                return;
            }
        }
    }
}
