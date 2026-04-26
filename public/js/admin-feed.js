/* ── admin-feed.js ── Unified admin interface for feed-based pages ──
 * Requires: window.ADMIN_PAGE_CODE set before loading (e.g. 'basics', 'vlog', 'music')
 * Requires: window.ADMIN_PAGE_TITLE set before loading (e.g. 'Basics', 'Vlog', 'Music')
 */
(function() {
'use strict';

var PAGE = window.ADMIN_PAGE_CODE || '';
var TITLE = window.ADMIN_PAGE_TITLE || PAGE;
var API = '/api/admin-feed.php?page=' + PAGE;
var allCategories = [];

function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

// ── Tab switching ──
window.switchTab = function(tab) {
    document.querySelectorAll('.sub-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.sub-panel').forEach(function(p) { p.classList.remove('active'); });
    document.querySelector('.sub-tab[onclick*="' + tab + '"]').classList.add('active');
    document.getElementById('panel-' + tab).classList.add('active');
    if (tab === 'settings' && !document.getElementById('s-heading').value) loadSettings();
    if (tab === 'categories') loadCategories();
};

// ── Auth ──
window.checkAuth = function() {
    fetch('/api/auth.php', {credentials:'include'}).then(function(r){return r.json()}).then(function(d) {
        if (d.authenticated) {
            document.getElementById('app').style.display = '';
            document.getElementById('user-display').textContent = d.user_name;
            if (typeof applyPagePermissions === 'function') applyPagePermissions(d.pages);
            loadVideos();
        } else {
            document.getElementById('login-screen').classList.add('visible');
        }
    });
};

window.doLogin = function() {
    var user = document.getElementById('login-user').value;
    var pass = document.getElementById('login-pass').value;
    var errEl = document.getElementById('login-error');
    errEl.style.display = 'none';
    fetch('/api/auth.php', {
        method: 'POST', credentials: 'include',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({login: user, password: pass})
    }).then(function(r){return r.json()}).then(function(d) {
        if (d.authenticated) {
            document.getElementById('login-screen').classList.remove('visible');
            document.getElementById('app').style.display = '';
            document.getElementById('user-display').textContent = d.user_name;
            if (typeof applyPagePermissions === 'function') applyPagePermissions(d.pages);
            loadVideos();
        } else {
            errEl.textContent = d.error || 'Login failed';
            errEl.style.display = '';
        }
    });
};

window.doLogout = function() {
    fetch('/api/auth.php', {method:'DELETE', credentials:'include'}).then(function() { location.reload(); });
};

window.togglePassword = function() {
    var input = document.getElementById('login-pass');
    input.type = input.type === 'password' ? 'text' : 'password';
};

// ── Categories ──
window.loadCategories = function() {
    fetch(API + '&type=categories', {credentials:'include'}).then(function(r){return r.json()}).then(function(data) {
        allCategories = data.categories || [];
        renderCategories();
    });
};

function renderCategories() {
    var cats = allCategories;
    if (!cats.length) {
        document.getElementById('cat-container').innerHTML = '<div style="text-align:center;padding:20px;color:#888">No categories yet.</div>';
        return;
    }
    var html = '<table class="cat-table"><thead><tr><th>Title</th><th>Subtitle</th><th>Order</th><th>Items</th><th></th></tr></thead><tbody>';
    cats.forEach(function(c, i) {
        html += '<tr data-ck="' + c.category_key + '">'
            + '<td><input type="text" value="' + esc(c.category_title || '') + '" data-field="title" onchange="saveCategory(' + c.category_key + ')"></td>'
            + '<td><input type="text" value="' + esc(c.category_subtitle || '') + '" data-field="subtitle" onchange="saveCategory(' + c.category_key + ')"></td>'
            + '<td style="white-space:nowrap">'
            + '<button style="border:1px solid #ccc;border-radius:3px;background:#fff;cursor:pointer;padding:1px 5px;font-size:0.7rem;margin-right:2px;' + (i === 0 ? 'opacity:0.25;pointer-events:none;' : '') + '" onclick="moveCat(' + c.category_key + ',-1)">&#9650;</button>'
            + '<button style="border:1px solid #ccc;border-radius:3px;background:#fff;cursor:pointer;padding:1px 5px;font-size:0.7rem;' + (i === cats.length - 1 ? 'opacity:0.25;pointer-events:none;' : '') + '" onclick="moveCat(' + c.category_key + ',1)">&#9660;</button>'
            + '</td>'
            + '<td style="color:#888">' + (c.video_count || 0) + '</td>'
            + '<td class="cat-actions">'
            + '<button class="del" onclick="deleteCategory(' + c.category_key + ')">Del</button>'
            + '</td></tr>';
    });
    html += '</tbody></table>';
    document.getElementById('cat-container').innerHTML = html;
}

window.addCategory = function() {
    document.getElementById('add-cat-title').value = '';
    document.getElementById('add-cat-subtitle').value = '';
    document.getElementById('add-cat-overlay').style.display = 'flex';
    document.getElementById('add-cat-title').focus();
};

window.closeAddCat = function() {
    document.getElementById('add-cat-overlay').style.display = 'none';
};

window.submitAddCat = function() {
    var title = document.getElementById('add-cat-title').value.trim();
    var subtitle = document.getElementById('add-cat-subtitle').value.trim();
    if (!title) { alert('Title is required'); return; }
    fetch(API + '&type=category', {
        method: 'POST', credentials: 'include',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ category_title: title, category_subtitle: subtitle || null, category_sort: 0 })
    }).then(function(r){return r.json()}).then(function(d) {
        if (d.error) alert(d.error);
        else { closeAddCat(); loadCategories(); }
    });
};

var _catSaveTimers = {};
window.saveCategory = function(key) {
    if (_catSaveTimers[key]) clearTimeout(_catSaveTimers[key]);
    _catSaveTimers[key] = setTimeout(function() {
        var row = document.querySelector('tr[data-ck="' + key + '"]');
        if (!row) return;
        var title = row.querySelector('[data-field="title"]').value.trim();
        var subtitle = row.querySelector('[data-field="subtitle"]').value.trim();
        if (!title) return;
        var sort = 0;
        for (var i = 0; i < allCategories.length; i++) {
            if (allCategories[i].category_key == key) { sort = allCategories[i].category_sort || 0; break; }
        }
        fetch(API + '&type=category&key=' + key, {
            method: 'PUT', credentials: 'include',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ category_title: title, category_subtitle: subtitle, category_sort: sort })
        }).then(function(r){return r.json()}).then(function(d) {
            if (d.error) alert(d.error);
        });
    }, 300);
};

window.moveCat = function(key, direction) {
    var idx = -1;
    for (var i = 0; i < allCategories.length; i++) {
        if (allCategories[i].category_key == key) { idx = i; break; }
    }
    if (idx < 0) return;
    var swapIdx = idx + direction;
    if (swapIdx < 0 || swapIdx >= allCategories.length) return;

    var a = allCategories[idx], b = allCategories[swapIdx];
    var tmpSort = a.category_sort;
    a.category_sort = b.category_sort;
    b.category_sort = tmpSort;
    if (a.category_sort === b.category_sort) {
        a.category_sort = b.category_sort + direction;
    }

    fetch(API + '&type=category&key=' + a.category_key, {
        method: 'PUT', credentials: 'include',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ category_sort: a.category_sort })
    });
    fetch(API + '&type=category&key=' + b.category_key, {
        method: 'PUT', credentials: 'include',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ category_sort: b.category_sort })
    }).then(function() { loadCategories(); });
};

window.deleteCategory = function(key) {
    if (!confirm('Delete this category? Items will be unassigned.')) return;
    fetch(API + '&type=category&key=' + key, {
        method: 'DELETE', credentials: 'include'
    }).then(function(r){return r.json()}).then(function(d) {
        if (d.error) alert(d.error);
        else loadCategories();
    });
};

// ── Videos / Items ──
window.loadVideos = function() {
    return fetch(API + '&type=categories', {credentials:'include'}).then(function(r){return r.json()}).then(function(catData) {
        allCategories = catData.categories || [];
        return fetch(API + '&type=videos', {credentials:'include'});
    }).then(function(r){return r.json()}).then(function(data) {
        var videos = data.videos || [];
        document.getElementById('item-count').textContent = videos.length + ' item' + (videos.length !== 1 ? 's' : '');
        if (!videos.length) {
            document.getElementById('table-container').innerHTML = '<div style="text-align:center;padding:20px;color:#888">No items found. Click Sync to import.</div>';
            return;
        }
        var catOpts = '<option value="">— None —</option>';
        allCategories.forEach(function(c) {
            var catLabel = c.category_title || '';
            if (c.category_subtitle) catLabel += ' ~ ' + c.category_subtitle;
            catOpts += '<option value="' + c.category_key + '">' + esc(catLabel) + '</option>';
        });

        // Group videos by category for first/last detection
        var catGroups = {};
        videos.forEach(function(v) {
            var cat = v.category_key || '_none';
            if (!catGroups[cat]) catGroups[cat] = [];
            catGroups[cat].push(v.video_key);
        });

        // Category jump links
        var seenCats = {};
        var catSlugs = [];
        videos.forEach(function(v) {
            var c = v.category_title || 'Uncategorized';
            if (!seenCats[c]) { seenCats[c] = true; catSlugs.push(c); }
        });
        var jumpHtml = '';
        if (catSlugs.length > 1) {
            jumpHtml = '<div style="display:flex;flex-wrap:wrap;gap:6px 14px;margin-bottom:12px;padding:10px 14px;border-bottom:1px solid #ddd;background:#f5f7fa;border-radius:6px;">';
            catSlugs.forEach(function(c) {
                var slug = c.replace(/[^a-zA-Z0-9]+/g, '-').toLowerCase();
                jumpHtml += '<a href="#cat-' + slug + '" style="font-size:0.82rem;color:#31345A;font-weight:600;text-decoration:none;" onmouseover="this.style.textDecoration=\'underline\'" onmouseout="this.style.textDecoration=\'none\'">' + esc(c) + '</a>';
            });
            jumpHtml += '</div>';
        }

        var arrowStyle = 'border:1px solid #ccc;border-radius:3px;background:#fff;cursor:pointer;padding:1px 5px;font-size:0.7rem;';
        var hiddenStyle = 'visibility:hidden;';

        var html = jumpHtml + '<table class="tbl"><thead><tr>'
            + '<th>Thumb</th>'
            + '<th style="width:50%;">Title</th>'
            + '<th>Category</th>'
            + '<th>Episode</th>'
            + '<th style="text-align:center;">Sort</th>'
            + '<th style="text-align:center;">MP3</th>'
            + '<th>Active</th>'
            + '<th></th>'
            + '</tr></thead><tbody>';

        var lastCat = null;
        videos.forEach(function(v) {
            var curCat = v.category_title || 'Uncategorized';
            if (curCat !== lastCat) {
                var catSlug = curCat.replace(/[^a-zA-Z0-9]+/g, '-').toLowerCase();
                html += '<tr id="cat-' + catSlug + '"><td colspan="8" style="background:#31345A;color:#E5C86C;font-family:\'Julius Sans One\',sans-serif;font-weight:600;font-size:0.95rem;padding:10px 12px;border:none;">' + esc(curCat) + '</td></tr>';
                lastCat = curCat;
            }

            var inactive = !v.active_flag;
            var cat = v.category_key || '_none';
            var group = catGroups[cat];
            var posInGroup = group.indexOf(v.video_key);
            var isFirst = posInGroup === 0;
            var isLast = posInGroup === group.length - 1;

            html += '<tr class="' + (inactive ? 'inactive' : '') + '" data-vk="' + v.video_key + '">'
                + '<td>' + (v.url ? '<a href="' + esc(v.url) + '" target="_blank" rel="noopener">' : '') + '<img class="thumb" src="' + esc(v.thumbnail || '') + '" alt="">' + (v.url ? '</a>' : '') + '</td>'
                + '<td><input type="text" value="' + esc(v.title || '') + '" data-field="title" style="width:100%;font-size:0.82rem;" onchange="saveVideo(' + v.video_key + ')"></td>'
                + '<td><select data-field="cat" onchange="saveVideo(' + v.video_key + ')">' + catOpts.replace('value="' + (v.category_key || '') + '"', 'value="' + (v.category_key || '') + '" selected') + '</select></td>'
                + '<td><input type="text" value="' + esc(v.episode || '') + '" data-field="episode" style="width:60px;font-size:0.82rem;padding:4px 6px;border:1px solid #ddd;border-radius:4px;" onchange="saveVideo(' + v.video_key + ')"></td>'
                + '<td style="text-align:center;">'
                + '<div style="display:flex;gap:2px;justify-content:center;margin-bottom:3px;">'
                + '<button style="' + arrowStyle + (isFirst ? hiddenStyle : '') + '" onclick="moveVideo(' + v.video_key + ',-1)">&#9650;</button>'
                + '<button style="' + arrowStyle + (isLast ? hiddenStyle : '') + '" onclick="moveVideo(' + v.video_key + ',1)">&#9660;</button>'
                + '</div>'
                + '<input type="number" value="' + (v.sort || 0) + '" data-field="sort" style="width:50px;text-align:center;" onchange="saveVideo(' + v.video_key + ')">'
                + '</td>'
                + '<td style="text-align:center;">'
                + '<input type="file" id="mp3-' + v.video_key + '" accept=".mp3" style="display:none" onchange="uploadMp3(' + v.video_key + ')">'
                + (v.audio_file ? '<div style="display:flex;justify-content:space-between;margin-bottom:3px;"><a href="/' + esc(v.audio_file) + '" target="_blank" title="Play MP3" style="font-size:1.1rem;text-decoration:none;">&#127911;</a><button class="act" style="font-size:0.7rem;color:#c00;" onclick="removeMp3(' + v.video_key + ')">X</button></div>' : '')
                + '<button class="act" style="font-size:0.7rem;width:100%;" onclick="document.getElementById(\'mp3-' + v.video_key + '\').click()">' + (v.audio_file ? 'Replace' : 'Upload') + '</button>'
                + '</td>'
                + '<td style="text-align:center"><input type="checkbox" data-field="active"' + (inactive ? '' : ' checked') + ' onchange="saveVideo(' + v.video_key + ')"></td>'
                + '<td><button class="del" onclick="deleteVideo(' + v.video_key + ')">Del</button></td></tr>';
        });
        html += '</tbody></table>';
        document.getElementById('table-container').innerHTML = html;
    });
};

var _saveTimers = {};
window.saveVideo = function(key) {
    if (_saveTimers[key]) clearTimeout(_saveTimers[key]);
    _saveTimers[key] = setTimeout(function() {
        var row = document.querySelector('tr[data-vk="' + key + '"]');
        if (!row) return;
        var title = row.querySelector('[data-field="title"]').value.trim();
        var catKey = row.querySelector('[data-field="cat"]').value || null;
        var episode = row.querySelector('[data-field="episode"]').value.trim() || null;
        var sort = parseInt(row.querySelector('[data-field="sort"]').value) || 0;
        var active = row.querySelector('[data-field="active"]').checked;
        row.classList.toggle('inactive', !active);
        fetch(API + '&type=video&key=' + key, {
            method: 'PUT', credentials: 'include',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ title: title, category_key: catKey ? parseInt(catKey) : null, episode: episode, sort: sort, active_flag: active })
        }).then(function(r){return r.json()}).then(function(d) {
            if (d.error) alert(d.error);
        });
    }, 300);
};

window.moveVideo = function(key, direction) {
    var rows = document.querySelectorAll('tr[data-vk]');
    var groups = {};
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var vk = parseInt(r.getAttribute('data-vk'));
        var cat = r.querySelector('[data-field="cat"]').value || '_none';
        var sort = parseInt(r.querySelector('[data-field="sort"]').value) || 0;
        if (!groups[cat]) groups[cat] = [];
        groups[cat].push({ key: vk, sort: sort, row: r });
    }
    for (var cat in groups) {
        var g = groups[cat];
        for (var j = 0; j < g.length; j++) {
            if (g[j].key === key) {
                var swapIdx = j + direction;
                if (swapIdx < 0 || swapIdx >= g.length) return;
                var tmpSort = g[j].sort;
                g[j].sort = g[swapIdx].sort;
                g[swapIdx].sort = tmpSort;
                if (g[j].sort === g[swapIdx].sort) {
                    g[j].sort = g[swapIdx].sort + direction;
                }
                g[j].row.querySelector('[data-field="sort"]').value = g[j].sort;
                g[swapIdx].row.querySelector('[data-field="sort"]').value = g[swapIdx].sort;
                saveVideo(g[j].key);
                saveVideo(g[swapIdx].key);
                setTimeout(loadVideos, 500);
                return;
            }
        }
    }
};

window.deleteVideo = function(key) {
    if (!confirm('Delete this item?')) return;
    fetch(API + '&type=video&key=' + key, {method:'DELETE', credentials:'include'}).then(function(r){return r.json()}).then(function(d) {
        if (d.error) alert(d.error); else loadVideos();
    });
};

// ── MP3 Upload ──
window.uploadMp3 = function(key) {
    var input = document.getElementById('mp3-' + key);
    if (!input || !input.files[0]) return;
    var row = document.querySelector('tr[data-vk="' + key + '"]');
    var mp3Cell = row ? row.querySelectorAll('td')[5] : null;
    var oldHtml = mp3Cell ? mp3Cell.innerHTML : '';
    if (mp3Cell) mp3Cell.innerHTML = '<span style="color:#888;font-size:0.8rem;">Uploading...</span>';

    var fd = new FormData();
    fd.append('audio', input.files[0]);
    fetch(API + '&type=audio&key=' + key, { method: 'POST', credentials: 'include', body: fd })
    .then(function(r) { return r.json(); }).then(function(d) {
        if (d.error) { alert(d.error); if (mp3Cell) mp3Cell.innerHTML = oldHtml; }
        else {
            loadVideos().then(function() {
                var r = document.querySelector('tr[data-vk="' + key + '"]');
                if (r) { r.style.background = '#d4edda'; setTimeout(function() { r.style.background = ''; }, 2500); }
            });
        }
    }).catch(function() { if (mp3Cell) mp3Cell.innerHTML = oldHtml; });
};

window.removeMp3 = function(key) {
    if (!confirm('Remove MP3?')) return;
    fetch(API + '&type=audio&key=' + key, { method: 'DELETE', credentials: 'include' })
    .then(function(r) { return r.json(); }).then(function(d) {
        if (d.error) alert(d.error); else loadVideos();
    });
};

// ── Sync ──
window.syncFeed = function() {
    var btn = document.getElementById('sync-btn');
    var status = document.getElementById('sync-status');
    btn.disabled = true;
    btn.textContent = 'Syncing...';
    status.textContent = '';
    fetch(API + '&type=sync', {credentials:'include'}).then(function(r){return r.json()}).then(function(d) {
        btn.disabled = false;
        btn.textContent = 'Sync';
        if (d.synced || d.inserted !== undefined) {
            status.textContent = 'Inserted: ' + (d.inserted||0) + ', Updated: ' + (d.updated||0) + ', Skipped: ' + (d.skipped||0);
            status.className = 'sync-status';
            loadVideos();
        } else {
            status.textContent = d.error || d.message || 'Sync failed';
            status.className = 'sync-status sync-error';
        }
    }).catch(function() {
        btn.disabled = false;
        btn.textContent = 'Sync';
        status.textContent = 'Connection error';
        status.className = 'sync-status sync-error';
    });
};

// ── RGBA Color Picker ──
window.initRgbaPickers = function() {
    document.querySelectorAll('.rgba-picker').forEach(function(el) {
        var id = el.getAttribute('data-id');
        el.innerHTML = '<div class="color-swatch" id="' + id + '-swatch" style="background:#000;"><input type="color" id="' + id + '-hex" value="#000000"></div>'
            + '<div class="opacity-wrap"><input type="range" id="' + id + '-opacity" min="0" max="100" value="100" step="1"><span class="opacity-val" id="' + id + '-opval">1.00</span></div>'
            + '<span class="rgba-result" id="' + id + '-result"></span>';
        var hex = document.getElementById(id + '-hex');
        var opacity = document.getElementById(id + '-opacity');
        var swatch = document.getElementById(id + '-swatch');
        var opval = document.getElementById(id + '-opval');
        var result = document.getElementById(id + '-result');
        function update() {
            var h = hex.value, a = (parseInt(opacity.value) / 100).toFixed(2);
            opval.textContent = a;
            var r = parseInt(h.substr(1,2),16), g = parseInt(h.substr(3,2),16), b = parseInt(h.substr(5,2),16);
            var rgba = 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
            swatch.style.background = rgba; result.textContent = rgba;
        }
        hex.addEventListener('input', update); opacity.addEventListener('input', update);
    });
};

window.setPickerValue = function(id, cssColor) {
    if (!cssColor) cssColor = 'rgba(0,0,0,1)';
    var hex = document.getElementById(id + '-hex'), opacity = document.getElementById(id + '-opacity');
    if (!hex || !opacity) return;
    var r = 0, g = 0, b = 0, a = 1;
    var m = cssColor.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+))?\s*\)/);
    if (m) { r = parseInt(m[1]); g = parseInt(m[2]); b = parseInt(m[3]); a = m[4] !== undefined ? parseFloat(m[4]) : 1; }
    else if (cssColor.charAt(0) === '#') { var hx = cssColor.replace('#',''); if (hx.length===3) hx=hx[0]+hx[0]+hx[1]+hx[1]+hx[2]+hx[2]; r=parseInt(hx.substr(0,2),16);g=parseInt(hx.substr(2,2),16);b=parseInt(hx.substr(4,2),16); }
    hex.value = '#' + ((1<<24)+(r<<16)+(g<<8)+b).toString(16).slice(1);
    opacity.value = Math.round(a * 100);
    hex.dispatchEvent(new Event('input'));
};

window.getPickerValue = function(id) {
    var hex = document.getElementById(id + '-hex'), opacity = document.getElementById(id + '-opacity');
    if (!hex || !opacity) return '';
    var h = hex.value, a = (parseInt(opacity.value) / 100).toFixed(2);
    var r = parseInt(h.substr(1,2),16), g = parseInt(h.substr(3,2),16), b = parseInt(h.substr(5,2),16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
};

// ── Page Settings ──
window.loadSettings = function() {
    fetch(API + '&type=config', {credentials:'include'}).then(function(r){return r.json()}).then(function(cfg) {
        document.getElementById('s-heading').value = cfg.heading || '';
        setPickerValue('s-heading-color', cfg['heading-color'] || '#E5C86C');
        setPickerValue('s-heading-bg', cfg['heading-bg'] || 'rgba(0,0,0,0)');
        setPickerValue('s-background', cfg.background || '#373737');
        document.getElementById('s-columns').value = cfg.columns || '';
        document.getElementById('s-label-size').value = cfg['label-size'] || '';
        setPickerValue('s-label-color', cfg['label-color'] || 'rgba(255,255,255,0.85)');
    });
};

window.saveSettings = function() {
    var settings = {
        heading: document.getElementById('s-heading').value,
        'heading-color': getPickerValue('s-heading-color'),
        'heading-bg': getPickerValue('s-heading-bg'),
        background: getPickerValue('s-background'),
        columns: document.getElementById('s-columns').value,
        'label-size': document.getElementById('s-label-size').value,
        'label-color': getPickerValue('s-label-color')
    };
    fetch(API + '&type=config', {
        method: 'PUT', credentials: 'include',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(settings)
    }).then(function(r){return r.json()}).then(function(d) {
        var el = document.getElementById('settings-status');
        if (d.saved) { el.textContent = 'Saved!'; el.className = 'sync-status'; }
        else { el.textContent = d.error || 'Failed'; el.className = 'sync-status sync-error'; }
        setTimeout(function() { el.textContent = ''; }, 3000);
    });
};

// Init pickers on load
initRgbaPickers();

})();
