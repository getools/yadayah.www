/* admin-dirty.js — auto-disables Save buttons until form fields change.
 * Works across all admin pages without per-page modifications.
 *
 * Usage: automatic — loaded via admin-nav.js.
 * Save buttons are found by: .btn-primary with text "Save" (or containing "Save").
 * The containing section (.section-box, .edit-panel, .tab-panel, form, or nearest parent
 * with inputs) defines the scope of tracked fields.
 *
 * Manual API:
 *   AdminDirty.snapshot(sectionEl)  — retake snapshot (after save or dynamic load)
 *   AdminDirty.check(sectionEl)     — re-evaluate dirty state
 *   AdminDirty.reset()              — re-scan entire page (after dynamic content load)
 */
(function() {
'use strict';

window.AdminDirty = {};

var _tracked = []; // [{ section, button, snapshot }]

function getInputs(section) {
    return section.querySelectorAll('input:not([type=file]):not([type=hidden]):not([type=submit]):not([type=button]), select, textarea');
}

function takeSnapshot(section) {
    var snap = {};
    var inputs = getInputs(section);
    for (var i = 0; i < inputs.length; i++) {
        var el = inputs[i];
        var key = el.id || el.name || ('_idx_' + i);
        if (el.type === 'checkbox' || el.type === 'radio') {
            snap[key] = el.checked;
        } else {
            snap[key] = el.value;
        }
    }
    return snap;
}

function isDirty(section, snapshot) {
    var inputs = getInputs(section);
    for (var i = 0; i < inputs.length; i++) {
        var el = inputs[i];
        var key = el.id || el.name || ('_idx_' + i);
        if (!(key in snapshot)) return true; // new field
        if (el.type === 'checkbox' || el.type === 'radio') {
            if (el.checked !== snapshot[key]) return true;
        } else {
            if (el.value !== snapshot[key]) return true;
        }
    }
    return false;
}

function findSection(button) {
    // Walk up to find the containing section
    var containers = ['.edit-panel', '.section-box', '.tab-panel', '.card', 'form', '.compose-form'];
    for (var i = 0; i < containers.length; i++) {
        var sec = button.closest(containers[i]);
        if (sec && getInputs(sec).length > 0) return sec;
    }
    // Fallback: parent that contains inputs
    var el = button.parentElement;
    while (el && el !== document.body) {
        if (getInputs(el).length > 0) return el;
        el = el.parentElement;
    }
    return null;
}

function isSaveButton(btn) {
    var text = (btn.textContent || '').trim();
    // Match "Save", "Save Settings", etc. but not "Save As" file inputs
    return /^Save\b/i.test(text) && !btn.closest('.img-upload');
}

function updateButton(entry) {
    var dirty = isDirty(entry.section, entry.snapshot);
    entry.button.disabled = !dirty;
    entry.button.style.opacity = dirty ? '' : '0.5';
    entry.button.style.cursor = dirty ? '' : 'not-allowed';
}

function wireSection(section, button) {
    var snap = takeSnapshot(section);
    var entry = { section: section, button: button, snapshot: snap };
    _tracked.push(entry);

    // Disable initially
    button.disabled = true;
    button.style.opacity = '0.5';
    button.style.cursor = 'not-allowed';

    // Listen for changes
    var handler = function() { updateButton(entry); };
    section.addEventListener('input', handler);
    section.addEventListener('change', handler);

    // Store reference for manual API
    section._dirtyEntry = entry;
    button._dirtyEntry = entry;
}

function scan() {
    _tracked = [];
    var buttons = document.querySelectorAll('.btn-primary, .btn.btn-primary, button.btn-primary');
    for (var i = 0; i < buttons.length; i++) {
        var btn = buttons[i];
        if (!isSaveButton(btn)) continue;
        if (btn._dirtyEntry) continue; // already tracked
        var section = findSection(btn);
        if (!section) continue;
        wireSection(section, btn);
    }
}

// ── Public API ──

AdminDirty.snapshot = function(sectionOrButton) {
    var entry = sectionOrButton._dirtyEntry;
    if (entry) {
        entry.snapshot = takeSnapshot(entry.section);
        updateButton(entry);
    }
};

AdminDirty.check = function(sectionOrButton) {
    var entry = sectionOrButton._dirtyEntry;
    if (entry) updateButton(entry);
};

AdminDirty.reset = function() {
    // Clear old tracking
    for (var i = 0; i < _tracked.length; i++) {
        delete _tracked[i].section._dirtyEntry;
        delete _tracked[i].button._dirtyEntry;
    }
    _tracked = [];
    scan();
};

// Re-snapshot after a successful save (hook into fetch responses)
AdminDirty.afterSave = function(button) {
    var entry = button._dirtyEntry;
    if (entry) {
        entry.snapshot = takeSnapshot(entry.section);
        entry.button.disabled = true;
        entry.button.style.opacity = '0.5';
        entry.button.style.cursor = 'not-allowed';
    }
};

// Force-mark a section as dirty (for external editors like TinyMCE)
AdminDirty.markDirty = function(sectionOrButton) {
    var entry = sectionOrButton ? sectionOrButton._dirtyEntry : null;
    if (!entry && _tracked.length) entry = _tracked[0];
    if (entry) {
        entry.button.disabled = false;
        entry.button.style.opacity = '';
        entry.button.style.cursor = '';
    }
};
window.markDirty = function() { AdminDirty.markDirty(); };

// Auto-scan on load and after dynamic content changes
function init() {
    scan();
    // Re-scan when DOM changes (admin pages load content dynamically)
    if (typeof MutationObserver !== 'undefined') {
        var _scanTimer = null;
        new MutationObserver(function() {
            clearTimeout(_scanTimer);
            _scanTimer = setTimeout(function() {
                // Only scan for new untracked buttons
                var buttons = document.querySelectorAll('.btn-primary, .btn.btn-primary, button.btn-primary');
                for (var i = 0; i < buttons.length; i++) {
                    var btn = buttons[i];
                    if (!isSaveButton(btn)) continue;
                    if (btn._dirtyEntry) continue;
                    var section = findSection(btn);
                    if (!section) continue;
                    wireSection(section, btn);
                }
            }, 300);
        }).observe(document.body, { childList: true, subtree: true });
    }
}

// Auto-detect successful saves: watch for "Saved!" status elements appearing
// This covers pages that don't explicitly call AdminDirty.afterSave()
if (typeof MutationObserver !== 'undefined') {
    new MutationObserver(function(mutations) {
        for (var i = 0; i < mutations.length; i++) {
            var t = mutations[i].target;
            if (t.nodeType !== 1) continue;
            var text = (t.textContent || '').trim();
            if ((text === 'Saved!' || text === 'Saved') && t.style.display !== 'none') {
                // Find the save button in the same section
                for (var j = 0; j < _tracked.length; j++) {
                    if (_tracked[j].section.contains(t)) {
                        _tracked[j].snapshot = takeSnapshot(_tracked[j].section);
                        updateButton(_tracked[j]);
                        break;
                    }
                }
            }
        }
    }).observe(document.body, { childList: true, subtree: true, characterData: true });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    // Delay to let admin pages render their dynamic content first
    setTimeout(init, 500);
}

})();
