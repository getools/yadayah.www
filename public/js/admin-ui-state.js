/* admin-ui-state.js — small localStorage helper for persisting admin UI state
   (selected tabs, list items, filter values, sort columns, etc.) across page
   refreshes. Each value is namespaced by page so admin pages don't collide.

   Usage:
     adminUI.save('feeds', 'tab', 'items');
     var tab = adminUI.load('feeds', 'tab', 'sources'); // default if not set

     adminUI.saveAll('feeds.filters', { feed: 'cat:5', active: 'yes' });
     var filters = adminUI.loadAll('feeds.filters', {});

     adminUI.clear('feeds');                 // wipe everything for the 'feeds' page
*/
(function () {
    var PREFIX = 'admin-ui:';
    var hasStorage = (function () {
        try {
            var k = '__test__';
            window.localStorage.setItem(k, k);
            window.localStorage.removeItem(k);
            return true;
        } catch (e) { return false; }
    })();

    function key(page, k) { return PREFIX + page + (k ? ':' + k : ''); }

    var adminUI = {
        save: function (page, k, value) {
            if (!hasStorage) return;
            try {
                if (value === null || value === undefined || value === '') {
                    window.localStorage.removeItem(key(page, k));
                } else {
                    window.localStorage.setItem(key(page, k), JSON.stringify(value));
                }
            } catch (e) {}
        },
        load: function (page, k, defaultValue) {
            if (!hasStorage) return defaultValue;
            try {
                var raw = window.localStorage.getItem(key(page, k));
                if (raw === null || raw === undefined) return defaultValue;
                return JSON.parse(raw);
            } catch (e) { return defaultValue; }
        },
        // Save/load an entire object (collapses many keys into one)
        saveAll: function (page, obj) {
            this.save(page, '__all__', obj || {});
        },
        loadAll: function (page, defaultValue) {
            return this.load(page, '__all__', defaultValue || {});
        },
        clear: function (page) {
            if (!hasStorage) return;
            try {
                var prefix = page ? PREFIX + page : PREFIX;
                var toRemove = [];
                for (var i = 0; i < window.localStorage.length; i++) {
                    var k = window.localStorage.key(i);
                    if (k && k.indexOf(prefix) === 0) toRemove.push(k);
                }
                toRemove.forEach(function (k) { window.localStorage.removeItem(k); });
            } catch (e) {}
        },
        /**
         * Helper: save+restore a select/input element's value automatically.
         * Call this once after the element is in the DOM. It hydrates the
         * element from saved state and wires up onchange/oninput to save on
         * every change.
         *
         *   adminUI.bindInput('feeds', 'filter-feed', document.getElementById('filter-feed'), function() { loadItems(); });
         */
        bindInput: function (page, k, el, onChange) {
            if (!el) return;
            var saved = this.load(page, k, null);
            if (saved !== null && saved !== undefined) {
                if (el.type === 'checkbox') el.checked = !!saved;
                else el.value = saved;
            }
            var self = this;
            var handler = function () {
                var v = el.type === 'checkbox' ? el.checked : el.value;
                self.save(page, k, v);
                if (typeof onChange === 'function') onChange();
            };
            el.addEventListener('change', handler);
            if (el.tagName === 'INPUT' && (el.type === 'text' || el.type === 'search')) {
                el.addEventListener('input', handler);
            }
        }
    };

    window.adminUI = adminUI;
})();
