"""Replace single-quote Hebrew transliterations in YY Word documents
with half-ring modifier versions from yy_word_map_modifier table.

Processes: body paragraphs (including TOC), tables, and page headers.
Matches any single-quote variant (' ' ' ` etc.).
Preserves original letter case (only quote chars are replaced).
Applies "Yada Towrah" font to all half-ring modifier characters.
"""

import os
import re
import sys
from copy import deepcopy
from docx import Document
from docx.oxml.ns import qn
from lxml import etree
import psycopg2

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

# All single-quote character variants to treat as equivalent
QUOTE_CHARS = frozenset(
    "'"          # U+0027 straight apostrophe
    "\u2018"     # ' left single quotation mark
    "\u2019"     # ' right single quotation mark
    "\u0060"     # ` grave accent / backtick
    "\u00b4"     # ´ acute accent
    "\u02bc"     # ʼ modifier letter apostrophe
    "\u2032"     # ′ prime
    "\u201b"     # ‛ single high-reversed-9 quotation mark
)

# Half-ring modifier characters
HALF_RING_ALEPH = '\u02be'  # ʾ right half ring (Aleph)
HALF_RING_AYIN  = '\u02bf'  # ʿ left half ring (Ayin)
HALF_RINGS = frozenset([HALF_RING_ALEPH, HALF_RING_AYIN])

DOCS_DIR = r'C:\Users\Joe\Work\dev\yada\docs'

# Token pattern: sequences of letters, quote variants, half-rings, hyphens,
# and other chars that may appear in mapping values
TOKEN_RE = re.compile(
    r"[a-zA-Z"
    r"'\u2018\u2019\u0060\u00b4\u02bc\u2032\u201b"   # quote variants
    r"\u02be\u02bf"                                     # half-rings
    r"\-\}\{"                                           # hyphen, braces
    r"]+"
)


# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------

def load_mappings():
    """Load all old->new mappings from yy_word_map_modifier."""
    conn = psycopg2.connect(
        host='localhost', port=5433, dbname='yada',
        user='postgres', password='yada_password'
    )
    cur = conn.cursor()
    cur.execute(
        "SELECT word_map_modifier_old, word_map_modifier_new "
        "FROM yy_word_map_modifier"
    )
    rows = cur.fetchall()
    cur.close()
    conn.close()
    return rows


# ---------------------------------------------------------------------------
# Mapping preparation
# ---------------------------------------------------------------------------

def normalize_quotes(s):
    """Replace every quote variant with a straight ASCII apostrophe."""
    for ch in QUOTE_CHARS:
        if ch != "'":
            s = s.replace(ch, "'")
    return s


def build_lookup(mappings):
    """Build dict:  normalized_lower_old  ->  {char_position: modifier_char}

    Only positions where the old value has a quote and the new value has
    a half-ring modifier are recorded.
    """
    lookup = {}
    skipped = 0
    for old_val, new_val in mappings:
        if len(old_val) != len(new_val):
            skipped += 1
            continue
        quote_map = {}
        for i, (oc, nc) in enumerate(zip(old_val, new_val)):
            if oc in QUOTE_CHARS and nc in HALF_RINGS:
                quote_map[i] = nc
        if quote_map:
            key = normalize_quotes(old_val).lower()
            lookup[key] = quote_map
    if skipped:
        print(f"  Skipped {skipped} mappings with length mismatch")
    return lookup


# ---------------------------------------------------------------------------
# Paragraph text extraction (works with nested runs inside hyperlinks etc.)
# ---------------------------------------------------------------------------

def get_text_and_map(p_elem):
    """Return (full_text, cmap) for a paragraph XML element.

    cmap[i] = (run_element, t_element, char_index_within_t)
    Covers runs inside hyperlinks, SDT, bookmarks, etc.
    """
    text = ''
    cmap = []
    for r in p_elem.iter(qn('w:r')):
        for t in r.findall(qn('w:t')):
            s = t.text or ''
            for ci, ch in enumerate(s):
                text += ch
                cmap.append((r, t, ci))
    return text, cmap


# ---------------------------------------------------------------------------
# Phase 1 – Replace quote chars with half-ring modifiers
# ---------------------------------------------------------------------------

def process_element(p_elem, lookup):
    """Replace quote characters in a single paragraph element.

    Returns the number of individual character replacements made.
    """
    text, cmap = get_text_and_map(p_elem)
    if not text:
        return 0

    # Quick check – any quote character at all?
    if not any(ch in text for ch in QUOTE_CHARS):
        return 0

    replacements = {}          # absolute position -> new char
    matched_ranges = []        # prevent overlapping matches

    for m in TOKEN_RE.finditer(text):
        token = m.group()
        # Only care about tokens that contain a quote
        if not any(ch in token for ch in QUOTE_CHARS):
            continue

        key = normalize_quotes(token).lower()
        if key not in lookup:
            continue

        start = m.start()
        end = m.end()

        # Check overlap with previous matches
        overlap = False
        for ms, me in matched_ranges:
            if start < me and end > ms:
                overlap = True
                break
        if overlap:
            continue
        matched_ranges.append((start, end))

        quote_map = lookup[key]
        for i, ch in enumerate(token):
            if ch in QUOTE_CHARS and i in quote_map:
                replacements[start + i] = quote_map[i]

    if not replacements:
        return 0

    # Group changes by w:t element and apply
    affected = {}   # id(t_elem) -> (t_elem, list_of_chars)
    for pos, new_ch in replacements.items():
        _r, t, ci = cmap[pos]
        tid = id(t)
        if tid not in affected:
            affected[tid] = (t, list(t.text or ''))
        affected[tid][1][ci] = new_ch

    for _tid, (t, chars) in affected.items():
        t.text = ''.join(chars)

    return len(replacements)


# ---------------------------------------------------------------------------
# Phase 2 – Apply "Yada Towrah" font to all half-ring characters
# ---------------------------------------------------------------------------

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

        # Single segment that is entirely half-rings – just set font
        if len(segments) == 1:
            if segments[0][1]:
                _set_yt_font(r)
            continue

        # Multiple segments – need to split the run
        # Save unmodified copy for cloning
        orig_copy = deepcopy(r)

        # Remove old w:t elements from original run
        for t in t_elems:
            r.remove(t)

        # First segment reuses the original run element
        _add_t(r, segments[0][0])
        if segments[0][1]:
            _set_yt_font(r)

        # Remaining segments get cloned runs inserted after previous
        prev = r
        for seg_text, is_hr in segments[1:]:
            nr = deepcopy(orig_copy)
            # Remove cloned t elements
            for t in nr.findall(qn('w:t')):
                nr.remove(t)
            _add_t(nr, seg_text)
            if is_hr:
                _set_yt_font(nr)
            prev.addnext(nr)
            prev = nr


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


# ---------------------------------------------------------------------------
# Document-level processing
# ---------------------------------------------------------------------------

def collect_paragraph_elements(doc):
    """Collect all w:p elements from body (inc. TOC, tables) and headers."""
    elems = []
    seen = set()

    # Body – includes TOC, tables, SDT, everything under w:body
    for p in doc.element.body.iter(qn('w:p')):
        pid = id(p)
        if pid not in seen:
            seen.add(pid)
            elems.append(p)

    # Headers from every section
    for section in doc.sections:
        for hdr_attr in ('header', 'first_page_header', 'even_page_header'):
            try:
                hdr = getattr(section, hdr_attr)
                if hdr is None:
                    continue
                if hdr.is_linked_to_previous:
                    continue
                for p in hdr._element.iter(qn('w:p')):
                    pid = id(p)
                    if pid not in seen:
                        seen.add(pid)
                        elems.append(p)
            except Exception:
                pass

    return elems


def process_document(filepath, lookup):
    """Process one Word document.  Returns (paragraphs_modified, chars_replaced)."""
    fname = os.path.basename(filepath)
    try:
        doc = Document(filepath)
    except Exception as e:
        print(f"  ERROR opening {fname}: {e}")
        return 0, 0

    paras = collect_paragraph_elements(doc)
    total_chars = 0
    paras_modified = 0

    # Phase 1: replace quotes with half-ring modifiers
    for p in paras:
        n = process_element(p, lookup)
        if n:
            paras_modified += 1
            total_chars += n

    # Phase 2: apply Yada Towrah font to ALL half-ring chars (new + existing)
    for p in paras:
        apply_yt_font_to_half_rings(p)

    if total_chars == 0:
        print(f"  {fname}: no replacements")
        return 0, 0

    try:
        doc.save(filepath)
        print(f"  {fname}: {paras_modified} paragraphs, {total_chars} chars replaced")
    except Exception as e:
        print(f"  ERROR saving {fname}: {e}")
        return 0, 0

    return paras_modified, total_chars


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    print("Loading mappings from yy_word_map_modifier ...")
    mappings = load_mappings()
    print(f"  {len(mappings)} mappings loaded")

    lookup = build_lookup(mappings)
    print(f"  {len(lookup)} lookup entries prepared")

    # Collect base-name YY documents
    docs = sorted([
        os.path.join(DOCS_DIR, f)
        for f in os.listdir(DOCS_DIR)
        if f.startswith('YY-') and f.endswith('.docx')
        and '_backup' not in f and '_updated' not in f
        and '_fixed' not in f and '_final' not in f
        and not f.startswith('~$')
    ])
    print(f"  {len(docs)} documents found\n")

    grand_paras = 0
    grand_chars = 0
    for fp in docs:
        p, c = process_document(fp, lookup)
        grand_paras += p
        grand_chars += c

    print(f"\nDone!  {grand_chars} characters replaced across "
          f"{grand_paras} paragraphs in {len(docs)} documents.")


if __name__ == '__main__':
    main()
