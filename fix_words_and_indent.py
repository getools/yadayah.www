"""Replace specific transliteration words and fix first-line indent in YY Word documents.

Replacements fix half-ring usage (ʾ aleph vs ʿ ayin) and remove errant curly quotes.
Half-ring characters are set to Yada Towrah font.
First-line indent of 0.5" is changed to 0.3".
"""

import os
import sys
import glob
from copy import deepcopy
from docx import Document
from docx.oxml.ns import qn
from docx.shared import Inches
from lxml import etree

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

HALF_RING_ALEPH = '\u02be'  # ʾ right half ring
HALF_RING_AYIN  = '\u02bf'  # ʿ left half ring
HALF_RINGS = frozenset([HALF_RING_ALEPH, HALF_RING_AYIN])

DOCS_DIR = r'C:\Users\Joe\Work\dev\yada\docs'

# Word replacements: (old, new)
# Order matters - longer/more specific patterns first to avoid partial matches
REPLACEMENTS = [
    # Compound words first (most specific)
    ('\u02beEly\u2018ezar', '\u02beEly\u02bfezar'),       # ʾEly'ezar → ʾElyʿezar
    ('\u02beely\u2018ezar', '\u02beely\u02bfezar'),       # ʾely'ezar → ʾelyʿezar
    ('\u02beEly\u2019ezar', '\u02beEly\u02bfezar'),       # ʾEly'ezar → ʾElyʿezar (right curly)
    ('\u02beely\u2019ezar', '\u02beely\u02bfezar'),       # ʾely'ezar → ʾelyʿezar (right curly)
    ('Mala\u2018kah', 'Mala\u02bekah'),                   # Mala'kah → Malaʾkah
    ('Mala\u2019kah', 'Mala\u02bekah'),                   # Mala'kah → Malaʾkah (right curly)
    ('Ra\u2018uwben', 'Ra\u02beuwben'),                   # Ra'uwben → Raʾuwben
    ('Ra\u2019uwben', 'Ra\u02beuwben'),                   # Ra'uwben → Raʾuwben (right curly)
    ('\u02beAbyhuw\u2018', '\u02beAbyhuw\u02be'),          # ʾAbyhuw' → ʾAbyhuwʾ
    ('\u02beAbyhuw\u2019', '\u02beAbyhuw\u02be'),          # ʾAbyhuw' → ʾAbyhuwʾ (right curly)
    ('\u02beabyhuw\u02bf', '\u02beabyhuw\u02be'),          # ʾabyhuwʿ → ʾabyhuwʾ
    ('sa\u02bfyr \u2018ez', 'sa\u02bfyr \u02bfez'),       # saʿyr 'ez → saʿyr ʿez
    ('sa\u02bfyr \u2019ez', 'sa\u02bfyr \u02bfez'),       # saʿyr 'ez → saʿyr ʿez (right curly)
    # Single words - curly quote to left half ring (ayin)
    ('\u2018Ay', '\u02bfAy'),                              # 'Ay → ʿAy
    ('\u2019Ay', '\u02bfAy'),                              # 'Ay → ʿAy (right curly)
    ('\u2018ataq', '\u02bfataq'),                          # 'ataq → ʿataq
    ('\u2019ataq', '\u02bfataq'),                          # 'ataq → ʿataq (right curly)
    ('\u2018aphar', '\u02bfaphar'),                        # 'aphar → ʿaphar
    ('\u2019aphar', '\u02bfaphar'),                        # 'aphar → ʿaphar (right curly)
    ('\u02beaphar', '\u02bfaphar'),                        # ʾaphar → ʿaphar (wrong half ring)
    ('\u2018esrym', '\u02bfesrym'),                        # 'esrym → ʿesrym
    ('\u2019esrym', '\u02bfesrym'),                        # 'esrym → ʿesrym (right curly)
    ('\u2018ez', '\u02bfez'),                              # 'ez → ʿez (standalone)
    ('\u2019ez', '\u02bfez'),                              # 'ez → ʿez (right curly)
    # Single words - curly quote to right half ring (aleph)
    ('\u2018Ayl', '\u02beAyl'),                            # 'Ayl → ʾAyl
    ('\u2019Ayl', '\u02beAyl'),                            # 'Ayl → ʾAyl (right curly)
    ('\u2018edeny', '\u02beedeny'),                        # 'edeny → ʾedeny
    ('\u2019edeny', '\u02beedeny'),                        # 'edeny → ʾedeny (right curly)
    ('\u2018adon', '\u02beadon'),                          # 'adon → ʾadon (also covers 'adonay)
    ('\u2019adon', '\u02beadon'),                          # 'adon → ʾadon (right curly)
    ('\u2018ahab', '\u02beahab'),                          # 'ahab → ʾahab
    ('\u2019ahab', '\u02beahab'),                          # 'ahab → ʾahab (right curly)
    ('\u2018Abymelek', '\u02beAbymelek'),                  # 'Abymelek → ʾAbymelek
    ('\u2019Abymelek', '\u02beAbymelek'),                  # 'Abymelek → ʾAbymelek (right curly)
    ('\u2018amnah', '\u02beamnah'),                        # 'amnah → ʾamnah
    ('\u2019amnah', '\u02beamnah'),                        # 'amnah → ʾamnah (right curly)
    ('\u2018Eyalowth', '\u02beEyalowth'),                  # 'Eyalowth → ʾEyalowth
    ('\u2019Eyalowth', '\u02beEyalowth'),                  # 'Eyalowth → ʾEyalowth (right curly)
    ('\u2018abnet', '\u02beabnet'),                        # 'abnet → ʾabnet
    ('\u2019abnet', '\u02beabnet'),                        # 'abnet → ʾabnet (right curly)
    ('\u2018esheh', '\u02beesheh'),                        # 'esheh → ʾesheh
    ('\u2019esheh', '\u02beesheh'),                        # 'esheh → ʾesheh (right curly)
    # Remove curly quote (no half ring)
    ('\u2018edonay', 'edonay'),                            # 'edonay → edonay
    ('\u2019edonay', 'edonay'),                            # 'edonay → edonay (right curly)
]

# Target indent: 0.5" = 720 twips, 0.3" = 432 twips
# (w:firstLine uses twips in OOXML: 1 inch = 1440 twips)
INDENT_OLD = 720   # 0.5 inches in twips
INDENT_NEW = 432   # 0.3 inches in twips
INDENT_TOLERANCE = 5  # Allow small rounding differences


# ---------------------------------------------------------------------------
# Text replacement helpers
# ---------------------------------------------------------------------------

def get_text_and_map(p_elem):
    """Extract full paragraph text with character map to source elements."""
    cmap = []
    full = []
    for r in p_elem.iter(qn('w:r')):
        for t in r.findall(qn('w:t')):
            text = t.text or ''
            for i, ch in enumerate(text):
                cmap.append((r, t, i))
                full.append(ch)
    return ''.join(full), cmap


def apply_replacements(p_elem):
    """Apply word replacements to a paragraph element. Returns count."""
    text, cmap = get_text_and_map(p_elem)
    if not text:
        return 0

    count = 0
    # Apply each replacement
    for old, new in REPLACEMENTS:
        if old not in text:
            continue

        # Find all occurrences
        start = 0
        while True:
            idx = text.find(old, start)
            if idx == -1:
                break

            # Build new text
            text = text[:idx] + new + text[idx + len(old):]

            # Adjust cmap
            len_diff = len(new) - len(old)
            if len_diff != 0:
                if len_diff > 0:
                    # Insert placeholder entries
                    last_entry = cmap[idx + len(old) - 1] if idx + len(old) - 1 < len(cmap) else cmap[-1]
                    for _ in range(len_diff):
                        cmap.insert(idx + len(old), last_entry)
                else:
                    # Remove entries
                    for _ in range(-len_diff):
                        if idx + len(new) < len(cmap):
                            cmap.pop(idx + len(new))

            start = idx + len(new)
            count += 1

    if count == 0:
        return 0

    # Now write back the modified text to the t elements
    # Group characters by their t element
    t_texts = {}
    for i, ch in enumerate(text):
        if i < len(cmap):
            r, t, char_idx = cmap[i]
            t_id = id(t)
            if t_id not in t_texts:
                t_texts[t_id] = (t, [])
            t_texts[t_id][1].append(ch)

    # Apply new text to each t element
    for t_id, (t_elem, chars) in t_texts.items():
        new_text = ''.join(chars)
        t_elem.text = new_text
        if new_text and (new_text[0] == ' ' or new_text[-1] == ' '):
            t_elem.set(qn('xml:space'), 'preserve')

    return count


# ---------------------------------------------------------------------------
# Font application for half-rings
# ---------------------------------------------------------------------------

def _add_t(run_elem, text):
    """Add a w:t element with the given text to a run."""
    t = etree.SubElement(run_elem, qn('w:t'))
    t.text = text
    if text and (text[0] == ' ' or text[-1] == ' '):
        t.set(qn('xml:space'), 'preserve')


def _set_yt_font(run_elem):
    """Ensure a run element has Yada Towrah font set."""
    rpr = run_elem.find(qn('w:rPr'))
    if rpr is None:
        rpr = etree.SubElement(run_elem, qn('w:rPr'))
        run_elem.insert(0, rpr)
    rf = rpr.find(qn('w:rFonts'))
    if rf is None:
        rf = etree.SubElement(rpr, qn('w:rFonts'))
    rf.set(qn('w:ascii'), 'Yada Towrah')
    rf.set(qn('w:hAnsi'), 'Yada Towrah')
    rf.set(qn('w:cs'), 'Yada Towrah')


def apply_yt_font_to_half_rings(p_elem):
    """Split runs that mix half-ring chars with other text and set font."""
    for r in list(p_elem.iter(qn('w:r'))):
        t_elems = r.findall(qn('w:t'))
        if not t_elems:
            continue

        text = ''.join(t.text or '' for t in t_elems)
        if not any(ch in text for ch in HALF_RINGS):
            continue

        # Build segments: (text, is_half_ring)
        segments = []
        cur_text = ''
        cur_hr = None
        for ch in text:
            is_hr = ch in HALF_RINGS
            if cur_hr is not None and is_hr != cur_hr and cur_text:
                segments.append((cur_text, cur_hr))
                cur_text = ''
            cur_hr = is_hr
            cur_text += ch
        if cur_text:
            segments.append((cur_text, cur_hr))

        # Single segment entirely half-rings – just set font
        if len(segments) == 1:
            if segments[0][1]:
                _set_yt_font(r)
            continue

        # Multiple segments – split the run
        orig_copy = deepcopy(r)

        # Remove old w:t elements
        for t in t_elems:
            r.remove(t)

        # First segment reuses original run
        _add_t(r, segments[0][0])
        if segments[0][1]:
            _set_yt_font(r)

        # Remaining segments get cloned runs
        prev = r
        for seg_text, is_hr in segments[1:]:
            nr = deepcopy(orig_copy)
            for t in nr.findall(qn('w:t')):
                nr.remove(t)
            _add_t(nr, seg_text)
            if is_hr:
                _set_yt_font(nr)
            prev.addnext(nr)
            prev = nr


# ---------------------------------------------------------------------------
# Indent fix
# ---------------------------------------------------------------------------

def fix_first_line_indent(doc):
    """Change all 0.5" first-line indents to 0.3"."""
    count = 0
    for p in doc.element.body.iter(qn('w:p')):
        ppr = p.find(qn('w:pPr'))
        if ppr is None:
            continue
        ind = ppr.find(qn('w:ind'))
        if ind is None:
            continue
        first_line = ind.get(qn('w:firstLine'))
        if first_line is not None:
            try:
                val = int(first_line)
                if abs(val - INDENT_OLD) <= INDENT_TOLERANCE:
                    ind.set(qn('w:firstLine'), str(INDENT_NEW))
                    count += 1
            except ValueError:
                pass
    return count


# ---------------------------------------------------------------------------
# Document processing
# ---------------------------------------------------------------------------

def collect_paragraph_elements(doc):
    """Collect all w:p elements from body and headers."""
    elems = []
    seen = set()

    for p in doc.element.body.iter(qn('w:p')):
        pid = id(p)
        if pid not in seen:
            seen.add(pid)
            elems.append(p)

    for section in doc.sections:
        for hdr_attr in ('header', 'first_page_header', 'even_page_header'):
            try:
                hdr = getattr(section, hdr_attr, None)
                if hdr is None:
                    continue
                for p in hdr.element.iter(qn('w:p')):
                    pid = id(p)
                    if pid not in seen:
                        seen.add(pid)
                        elems.append(p)
            except Exception:
                pass

    return elems


def process_document(filepath, dry_run=False):
    """Process a single document. Returns (word_count, indent_count)."""
    doc = Document(filepath)
    paras = collect_paragraph_elements(doc)

    word_count = 0
    for p_elem in paras:
        word_count += apply_replacements(p_elem)

    # Apply Yada Towrah font to all half-rings (including pre-existing ones)
    for p_elem in paras:
        apply_yt_font_to_half_rings(p_elem)

    # Fix indents
    indent_count = fix_first_line_indent(doc)

    if not dry_run and (word_count > 0 or indent_count > 0):
        doc.save(filepath)

    return word_count, indent_count


def main():
    dry_run = '--dry-run' in sys.argv
    file_filter = None
    for arg in sys.argv[1:]:
        if not arg.startswith('--'):
            file_filter = arg

    files = sorted(glob.glob(os.path.join(DOCS_DIR, 'YY-*.docx')))
    if file_filter:
        files = [f for f in files if file_filter in os.path.basename(f)]

    print(f'{"DRY RUN - " if dry_run else ""}Processing {len(files)} documents...\n')

    total_words = 0
    total_indents = 0

    for filepath in files:
        fname = os.path.basename(filepath)
        try:
            wc, ic = process_document(filepath, dry_run)
            total_words += wc
            total_indents += ic
            if wc > 0 or ic > 0:
                print(f'  {fname}: {wc} word replacements, {ic} indent fixes')
            else:
                print(f'  {fname}: no changes')
        except Exception as e:
            print(f'  {fname}: ERROR - {e}')

    print(f'\nTotal: {total_words} word replacements, {total_indents} indent fixes across {len(files)} files')
    if dry_run:
        print('(Dry run - no files were modified)')


if __name__ == '__main__':
    main()
