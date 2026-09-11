/* ipa-helper.js — the IPA character helper popover.
   ==================================================
   Clickable IPA symbols, each labelled with an English example word, which
   insert at the cursor of whichever input the helper was opened against.

   Shared: the TTS Pronunciations tab and the Glossary Words editor both use
   it. Lifted out of admin-tts.html so there is one copy rather than two — the
   global names are unchanged, so the existing call sites did not move.

   Usage:
     <button type="button" class="f-ipa-helper" title="Insert IPA symbols">i</button>
     btn.addEventListener('click', function () { showIpaHelper(btn, ipaInput); });

   Styles are injected below, so a page only has to include this one file. */

(function () {
    'use strict';
    if (document.getElementById('ipa-helper-css')) return;
    var style = document.createElement('style');
    style.id = 'ipa-helper-css';
    style.textContent = [
        '.f-ipa-helper { background:none; border:1px solid transparent; cursor:pointer; padding:1px 4px; margin-left:2px; font-size:0.78rem; color:#888; line-height:1; flex:0 0 auto; border-radius:3px; }',
        '.f-ipa-helper:hover { color:#31345A; border-color:#ccc; background:#f5f7fa; }',
        '#ipa-helper-popover { display:none; position:fixed; background:#fff; border:1px solid #ccc; border-radius:6px; box-shadow:0 6px 20px rgba(0,0,0,.22); padding:8px 10px; min-width:520px; max-width:640px; max-height:70vh; overflow-y:auto; z-index:10006; font-size:0.82rem; }',
        '#ipa-helper-popover h4 { margin:6px 0 4px; font-size:0.78rem; font-weight:700; color:#31345A; text-transform:uppercase; letter-spacing:.5px; }',
        '#ipa-helper-popover .row { display:grid; grid-template-columns: repeat(3, 1fr); gap:4px 6px; }',
        '#ipa-helper-popover .row.wide { grid-template-columns: repeat(2, 1fr); }',
        '#ipa-helper-popover .chip { cursor:pointer; padding:5px 7px; border:1px solid #ddd; border-radius:4px; background:#fafafa; display:flex; align-items:baseline; gap:6px; line-height:1.2; }',
        '#ipa-helper-popover .chip:hover { background:#e8eaf6; border-color:#31345A; }',
        '#ipa-helper-popover .chip .ipa { font-size:1rem; font-family:Consolas,Monaco,monospace; color:#31345A; min-width:22px; text-align:center; }',
        '#ipa-helper-popover .chip .ex { color:#555; font-size:0.74rem; }',
        '#ipa-helper-popover .chip .desc { color:#888; font-size:0.68rem; }',
    ].join('\n');
    document.head.appendChild(style);
})();

/* Self-contained escape — the helper must not lean on a host page's helpers. */
function ipaEsc(s) {
    var d = document.createElement('div');
    d.textContent = (s === null || s === undefined) ? '' : s;
    return d.innerHTML;
}

// IPA character helper — opens a popover with clickable IPA symbols,
// each labeled with an English example word and a short description.
// Clicking a symbol inserts it at the IPA input's current cursor.
var IPA_HELPER_GROUPS = [
    { title: 'Hebrew word patterns — click to insert full IPA, then edit to taste', wide:true, chars: [
        { ipa:'bɛˈɹɪθ',     ex:'beryth (covenant)', desc:'stress 2nd syllable (be-RYTH)' },
        { ipa:'jɑˈhoʊwɑ',    ex:'Yahowah',           desc:'stress 2nd (yah-HOE-wah)' },
        { ipa:'toʊˈɹɑ',      ex:'Torah',             desc:'final stress (toh-RAH)' },
        { ipa:'jɪsɹɑˈɛl',    ex:'Yisrael',           desc:'final stress (yis-ra-EL)' },
        { ipa:'moʊˈʃɛ',      ex:'Mosheh',            desc:'stress 2nd (mo-SHEH)' },
        { ipa:'ʃɑˈloʊm',     ex:'shalom',            desc:'stress 2nd (sha-LOHM)' },
        { ipa:'ɛloʊˈhiːm',   ex:'Elohim',            desc:'final stress (el-o-HEEM)' },
    ]},
    { title: 'Stress & Length', chars: [
        { ipa:'ˈ', ex:'before stressed syllable', desc:'primary stress' },
        { ipa:'ˌ', ex:'before lightly stressed',  desc:'secondary stress' },
        { ipa:'ː', ex:'long vowel (uː = "oo")',   desc:'length mark' },
    ]},
    { title: 'Vowels', chars: [
        { ipa:'ɑ',  ex:'f**a**ther', desc:'open back' },
        { ipa:'æ',  ex:'c**a**t',    desc:'near-open front' },
        { ipa:'ʌ',  ex:'b**u**t',    desc:'open-mid back' },
        { ipa:'ɔ',  ex:'th**ou**ght',desc:'open-mid back rounded' },
        { ipa:'ə',  ex:'**a**bout',  desc:'schwa (unstressed)' },
        { ipa:'ɛ',  ex:'b**e**d',    desc:'open-mid front' },
        { ipa:'ɝ',  ex:'b**ir**d',   desc:'r-coloured schwa' },
        { ipa:'ɪ',  ex:'b**i**t',    desc:'near-close front' },
        { ipa:'i',  ex:'s**ee**',    desc:'close front' },
        { ipa:'ʊ',  ex:'b**oo**k',   desc:'near-close back' },
        { ipa:'u',  ex:'f**oo**d',   desc:'close back' },
        { ipa:'e',  ex:'caf**é**',   desc:'close-mid front (non-Eng)' },
    ]},
    { title: 'Diphthongs', chars: [
        { ipa:'aɪ', ex:'l**i**ke',   desc:'pie, time' },
        { ipa:'aʊ', ex:'c**ow**',    desc:'now, out' },
        { ipa:'eɪ', ex:'s**ay**',    desc:'day, face' },
        { ipa:'oʊ', ex:'g**o**',     desc:'boat, slow' },
        { ipa:'ɔɪ', ex:'b**oy**',    desc:'toy, joy' },
    ]},
    { title: 'Consonants', chars: [
        { ipa:'ʃ',  ex:'**sh**ip',         desc:'voiceless palato-alv.' },
        { ipa:'ʒ',  ex:'mea**s**ure',      desc:'voiced palato-alv.' },
        { ipa:'θ',  ex:'**th**ink',        desc:'voiceless dental' },
        { ipa:'ð',  ex:'**th**is',         desc:'voiced dental' },
        { ipa:'ŋ',  ex:'si**ng**',         desc:'velar nasal' },
        { ipa:'tʃ', ex:'**ch**air',        desc:'voiceless affricate' },
        { ipa:'dʒ', ex:'**j**udge',        desc:'voiced affricate' },
        { ipa:'ɹ',  ex:'**r**ed',          desc:'English r' },
        { ipa:'ɡ',  ex:'**g**o',           desc:'voiced velar plosive' },
    ]},
    { title: 'Hebrew-specific', chars: [
        { ipa:'ʔ', ex:'uh-**oh** gap',     desc:'aleph / glottal stop' },
        { ipa:'ʕ', ex:'(no English)',      desc:'ayin / pharyngeal — Azure only; drop for Kokoro' },
        { ipa:'χ', ex:'Ba**ch** (Ger.)',   desc:'chet — Azure only; use h for Kokoro' },
    ]},
];

// Insert text at the cursor of an input, preserving cursor placement
// and firing an `input` event so the autosave + validator listeners run.
function insertAtCursor(input, text) {
    if (!input) return;
    var s = (typeof input.selectionStart === 'number') ? input.selectionStart : input.value.length;
    var e = (typeof input.selectionEnd   === 'number') ? input.selectionEnd   : input.value.length;
    input.value = input.value.slice(0, s) + text + input.value.slice(e);
    var pos = s + text.length;
    try { input.setSelectionRange(pos, pos); } catch (err) { /* readonly type */ }
    input.focus();
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

function getIpaHelperPopover() {
    var pop = document.getElementById('ipa-helper-popover');
    if (pop) return pop;
    pop = document.createElement('div');
    pop.id = 'ipa-helper-popover';
    // Build the HTML once. Click handler is delegated.
    var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">'
             + '<span style="font-weight:600;color:#31345A;">IPA helper — click to insert at cursor</span>'
             + '<button type="button" onclick="closeIpaHelper()" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:#888;line-height:1;">&times;</button>'
             + '</div>'
             + '<div style="font-size:0.72rem;color:#777;margin:-2px 0 6px;line-height:1.3;">Tip: Hebrew usually stresses the <b>final</b> syllable — put <b>ˈ</b> right before it. For Kokoro / local engines avoid <b>ʕ</b> and <b>χ</b> (use <b>h</b> or omit).</div>';
    IPA_HELPER_GROUPS.forEach(function(g) {
        html += '<h4>' + g.title + '</h4><div class="row' + (g.wide ? ' wide' : '') + '">';
        g.chars.forEach(function(c) {
            // Bold the example letters that correspond to the sound.
            var ex = c.ex.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
            html += '<div class="chip" data-ipa="' + c.ipa + '" title="' + ipaEsc(c.desc) + '">'
                  + '<span class="ipa">' + c.ipa + '</span>'
                  + '<span class="ex">' + ex + '</span>'
                  + '</div>';
        });
        html += '</div>';
    });
    pop.innerHTML = html;
    document.body.appendChild(pop);
    // Click any chip → insert into the currently-anchored input.
    pop.addEventListener('click', function(e) {
        var chip = e.target.closest('.chip');
        if (!chip) return;
        var ipa = chip.getAttribute('data-ipa');
        if (!ipa) return;
        var target = pop._anchorInput;
        if (target) insertAtCursor(target, ipa);
    });
    // Outside click dismiss.
    document.addEventListener('mousedown', function(e) {
        var p = document.getElementById('ipa-helper-popover');
        if (!p || p.style.display === 'none') return;
        if (p.contains(e.target)) return;
        if (e.target.classList && e.target.classList.contains('f-ipa-helper')) return;
        p.style.display = 'none';
    });
    return pop;
}
function showIpaHelper(anchorBtn, ipaInput) {
    var pop = getIpaHelperPopover();
    pop._anchorInput = ipaInput;
    // Render off-screen first so we can measure the real height.
    pop.style.visibility = 'hidden';
    pop.style.display = 'block';
    pop.style.top  = '0px';
    pop.style.left = '0px';
    var popH = pop.offsetHeight;
    var popW = pop.offsetWidth;
    var r = anchorBtn.getBoundingClientRect();
    var vh = window.innerHeight;
    var vw = window.innerWidth;
    // Horizontal placement: prefer slightly left of the anchor, clamp to viewport.
    var left = r.left - 100;
    if (left + popW > vw - 8) left = Math.max(8, vw - popW - 8);
    if (left < 8) left = 8;
    // Vertical placement: prefer below the anchor; if that runs off the
    // bottom AND there's more room above, flip above. Otherwise clamp to
    // the viewport so the popover never overflows either edge.
    var topBelow = r.bottom + 4;
    var topAbove = r.top - popH - 4;
    var top;
    if (topBelow + popH <= vh - 8) {
        top = topBelow;
    } else if (topAbove >= 8) {
        top = topAbove;
    } else {
        // Neither fits cleanly — pin to the viewport edge that has more space.
        top = Math.max(8, vh - popH - 8);
        if (top < 8) top = 8;
    }
    pop.style.top  = top  + 'px';
    pop.style.left = left + 'px';
    pop.style.visibility = 'visible';
}
function closeIpaHelper() {
    var pop = document.getElementById('ipa-helper-popover');
    if (pop) pop.style.display = 'none';
}
