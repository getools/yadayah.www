"""Add new modifier mappings for remaining italic Hebrew words with single quotes,
then re-run replacement on all YY Word documents.

Each mapping: (old_form, new_form, hebrew, note)
- old_form uses ASCII ' for quote chars, plus existing half-rings where present
- new_form replaces the appropriate ' with ʾ (Aleph U+02BE) or ʿ (Ayin U+02BF)
- Quotation-mark quotes (not Hebrew letters) are left as ' in both old and new
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
# New mappings to add
# ---------------------------------------------------------------------------

# (old, new, hebrew_script, english_note)
NEW_MAPPINGS = [
    # === Leading Aleph (ʾ) — word starts with א ===
    ("'adon", "\u02beadon", "\u05d0\u05d3\u05d5\u05df", "lord"),
    ("'adonay", "\u02beadonay", "\u05d0\u05d3\u05e0\u05d9", "my lord"),
    ("'adownay", "\u02beadownay", "\u05d0\u05d3\u05e0\u05d9", "my lord (variant)"),
    ("'adoni", "\u02beadoni", "\u05d0\u05d3\u05e0\u05d9", "my lord"),
    ("'aphaq", "\u02beaphaq", "\u05d0\u05e4\u05e7", "restrain"),
    ("'achyah", "\u02beachyah", "\u05d0\u05d7\u05d9\u05d4", "Ahijah"),
    ("'amatsyah", "\u02beamatsyah", "\u05d0\u05de\u05e6\u05d9\u05d4", "Amaziah"),
    ("'amaryah", "\u02beamaryah", "\u05d0\u05de\u05e8\u05d9\u05d4", "Amariah"),
    ("'edonyah", "\u02beedonyah", "\u05d0\u05d3\u05d5\u05e0\u05d9\u05d4", "Adonijah"),
    ("'amacyah", "\u02beamacyah", "\u05d0\u05de\u05e6\u05d9\u05d4", "Amaziah (variant)"),
    ("'aw", "\u02beaw", "\u05d0\u05d5", "or"),
    ("'elmosheh", "\u02beelmosheh", "\u05d0\u05dc \u05de\u05e9\u05d4", "to Moses"),
    ("'elyw", "\u02beelyw", "\u05d0\u05dc\u05d9\u05d5", "to him"),
    ("'oh", "\u02beoh", "\u05d0\u05d5\u05d9", "woe"),

    # === Leading Ayin (ʿ) — word starts with ע ===
    ("'aliyah", "\u02bfaliyah", "\u05e2\u05dc\u05d9\u05d4", "ascent"),
    ("'obadyah", "\u02bfobadyah", "\u05e2\u05d1\u05d3\u05d9\u05d4", "Obadiah"),
    ("'adayah", "\u02bfadayah", "\u05e2\u05d3\u05d9\u05d4", "Adaiah"),
    ("'ananyah", "\u02bfananyah", "\u05e2\u05e0\u05e0\u05d9\u05d4", "Ananiah"),
    ("'anayah", "\u02bfanayah", "\u05e2\u05e0\u05d9\u05d4", "Anaiah"),
    ("'azaryahuw", "\u02bfazaryahuw", "\u05e2\u05d6\u05e8\u05d9\u05d4\u05d5", "Azariah"),
    ("'asayah", "\u02bfasayah", "\u05e2\u05e9\u05d9\u05d4", "Asaiah"),
    ("'athalyahuw", "\u02bfathalyahuw", "\u05e2\u05ea\u05dc\u05d9\u05d4\u05d5", "Athaliah"),
    ("'arabym", "\u02bfarabym", "\u05e2\u05e8\u05d1\u05d9\u05dd", "Arabs"),
    ("'elem", "\u02bfelem", "\u05e2\u05dc\u05dd", "eternity/hidden"),
    ("'estem", "\u02bfestem", "\u05e2\u05e6\u05dd", "bone/self"),
    ("'aruw\u02bfel", "\u02bfaruw\u02bfel", "\u05e2\u05e8\u05d5\u05d0\u05dc", "Aruel"),

    # === Internal/Trailing Aleph (ʾ) ===
    ("wyqara'", "wyqara\u02be", "\u05d5\u05d9\u05e7\u05e8\u05d0", "and he called"),
    ("mw'ohel", "mw\u02beohel", "\u05de\u05d0\u05d5\u05d4\u05dc", "from the tent"),
    ("la'amar", "la\u02beamar", "\u05dc\u05d0\u05de\u05e8", "saying"),
    ("da'ag", "da\u02beag", "\u05d3\u05d0\u05d2", "anxious"),
    ("tse'etsa'", "tse\u02beetsa\u02be", "\u05e6\u05d0\u05e6\u05d0", "offspring"),
    ("ga'uwlym", "ga\u02beuwlym", "\u05d2\u05d0\u05d5\u05dc\u05d9\u05dd", "redeemed"),
    ("sh'ar", "sh\u02bear", "\u05e9\u05d0\u05e8", "remnant"),
    ("lo'", "lo\u02be", "\u05dc\u05d0", "no/not"),
    ("yow'ach", "yow\u02beach", "\u05d9\u05d5\u05d0\u05d7", "Joah"),
    ("yow'achaz", "yow\u02beachaz", "\u05d9\u05d5\u05d0\u05d7\u05d6", "Joahaz"),

    # === Internal/Trailing Ayin (ʿ) ===
    ("mishma'", "mishma\u02bf", "\u05de\u05e9\u05de\u05e2", "hearing"),
    ("zeruwa'", "zeruwa\u02bf", "\u05d6\u05e8\u05d5\u05e2", "arm"),
    ("ma'erah", "ma\u02bferah", "\u05de\u05e2\u05e8\u05d4", "cave"),
    ("mari'yth", "mari\u02bfyth", "\u05de\u05e8\u05e2\u05d9\u05ea", "pasture"),
    ("ra'yah", "ra\u02bfyah", "\u05e8\u05e2\u05d9\u05d4", "companion"),
    ("shama'yah", "shama\u02bfyah", "\u05e9\u05de\u05e2\u05d9\u05d4", "Shemaiah"),
    ("yahuwyada'", "yahuwyada\u02bf", "\u05d9\u05d4\u05d5\u05d9\u05d3\u05e2", "Jehoiada"),
    ("yahuwsheba'", "yahuwsheba\u02bf", "\u05d9\u05d4\u05d5\u05e9\u05d1\u05e2", "Jehosheba"),
    ("yakda'yah", "yakda\u02bfyah", "\u05d9\u05d3\u05e2\u05d9\u05d4", "Yedaiah"),
    ("mow'adyah", "mow\u02bfadyah", "\u05de\u05d5\u05e2\u05d3\u05d9\u05d4", "Moadiah"),
    ("ma'aseyah", "ma\u02bfaseyah", "\u05de\u05e2\u05e9\u05d9\u05d4", "Maaseiah"),
    ("ma'aseyahuw", "ma\u02bfaseyahuw", "\u05de\u05e2\u05e9\u05d9\u05d4\u05d5", "Maaseiah"),
    ("ne'aryah", "ne\u02bfaryah", "\u05e0\u05e2\u05e8\u05d9\u05d4", "Neariah"),

    # === Mixed Aleph + Aleph, or Ayin + Aleph ===
    ("'abyahuw'", "\u02beabyahuw\u02be", "\u05d0\u05d1\u05d9\u05d4\u05d5\u05d0", "Abihu"),
    ("'uzya'", "\u02bfuzya\u02be", "\u05e2\u05d6\u05d9\u05d0", "Uzziah"),

    # === Words with existing half-rings — leading ' is Hebrew letter ===
    ("'ach\u02beab", "\u02beach\u02beab", "\u05d0\u05d7\u05d0\u05d1", "Ahab"),
    ("'esa\u02bfow", "\u02bfesa\u02bfow", "\u05e2\u05e9\u05d5", "Esau"),
    ("'araba\u02bf", "\u02bearaba\u02bf", "\u05d0\u05e8\u05d1\u05e2", "four"),
    ("'arba\u02bf", "\u02bearba\u02bf", "\u05d0\u05e8\u05d1\u05e2", "four (variant)"),
    ("'ana\u02be", "\u02beana\u02be", "\u05d0\u05e0\u05d0", "please"),

    # === Words with existing half-rings — internal ' needs replacement ===
    ("\u02beaph'aphym", "\u02beaph\u02bfaphym", "\u05d0\u05e4\u05e2\u05e4\u05d9\u05d9\u05dd", "eyelids"),
    ("\u02bearba'", "\u02bearba\u02bf", "\u05d0\u05e8\u05d1\u05e2", "four"),

    # === Partial: leading ' is quotation mark, internal ' is Hebrew ===
    ("'shim'own", "'shim\u02bfown", "\u05e9\u05de\u05e2\u05d5\u05df", "Simon (middle Ayin)"),

    # === With possessive 's — only the Hebrew quote changes ===
    ("ma'own's", "ma\u02bfown's", "\u05de\u05e2\u05d5\u05df", "dwelling (poss.)"),
    ("'achar's", "\u02bfachar's", "\u05e2\u05db\u05e8", "Achan (poss.)"),
    ("'iyezebel's", "\u02beiyezebel's", "\u05d0\u05d9\u05d6\u05d1\u05dc", "Jezebel (poss.)"),
    ("'amah's", "\u02beamah's", "\u05d0\u05de\u05d4", "handmaid (poss.)"),
    ("'esa\u02bfow's", "\u02bfesa\u02bfow's", "\u05e2\u05e9\u05d5", "Esau (poss.)"),
]


# ---------------------------------------------------------------------------
# Constants (same as replace_modifiers_in_docs.py)
# ---------------------------------------------------------------------------

QUOTE_CHARS = frozenset(
    "'"
    "\u2018\u2019\u0060\u00b4\u02bc\u2032\u201b"
)
HALF_RING_ALEPH = '\u02be'
HALF_RING_AYIN  = '\u02bf'
HALF_RINGS = frozenset([HALF_RING_ALEPH, HALF_RING_AYIN])

DOCS_DIR = r'C:\Users\Joe\Work\dev\yada\docs'

TOKEN_RE = re.compile(
    r"[a-zA-Z"
    r"'\u2018\u2019\u0060\u00b4\u02bc\u2032\u201b"
    r"\u02be\u02bf"
    r"\-\}\{"
    r"]+"
)


# ---------------------------------------------------------------------------
# DB helpers
# ---------------------------------------------------------------------------

def get_connection():
    return psycopg2.connect(
        host='localhost', port=5433, dbname='yada',
        user='postgres', password='yada_password'
    )


def insert_new_mappings():
    """Insert new mappings into yy_word_map_modifier, skip duplicates."""
    conn = get_connection()
    cur = conn.cursor()

    # Get existing mappings to avoid duplicates
    cur.execute("SELECT LOWER(word_map_modifier_old) FROM yy_word_map_modifier")
    existing = set(r[0] for r in cur.fetchall())

    added = 0
    skipped = 0
    for old, new, hebrew, note in NEW_MAPPINGS:
        if old.lower() in existing:
            print(f"  SKIP (exists): {old}")
            skipped += 1
            continue

        # Validate lengths match
        if len(old) != len(new):
            print(f"  ERROR length mismatch: '{old}' ({len(old)}) vs '{new}' ({len(new)})")
            continue

        cur.execute(
            "INSERT INTO yy_word_map_modifier (word_map_modifier_old, word_map_modifier_new) "
            "VALUES (%s, %s)",
            (old, new)
        )
        added += 1

    conn.commit()
    cur.close()
    conn.close()
    print(f"\n  Added {added} new mappings, skipped {skipped} duplicates")
    return added


def load_all_mappings():
    """Load ALL mappings from yy_word_map_modifier."""
    conn = get_connection()
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
    for ch in QUOTE_CHARS:
        if ch != "'":
            s = s.replace(ch, "'")
    return s


def build_lookup(mappings):
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
# Paragraph processing (same as replace_modifiers_in_docs.py)
# ---------------------------------------------------------------------------

def get_text_and_map(p_elem):
    text = ''
    cmap = []
    for r in p_elem.iter(qn('w:r')):
        for t in r.findall(qn('w:t')):
            s = t.text or ''
            for ci, ch in enumerate(s):
                text += ch
                cmap.append((r, t, ci))
    return text, cmap


def process_element(p_elem, lookup):
    text, cmap = get_text_and_map(p_elem)
    if not text:
        return 0
    if not any(ch in text for ch in QUOTE_CHARS):
        return 0

    replacements = {}
    matched_ranges = []

    for m in TOKEN_RE.finditer(text):
        token = m.group()
        if not any(ch in token for ch in QUOTE_CHARS):
            continue
        key = normalize_quotes(token).lower()
        if key not in lookup:
            continue
        start = m.start()
        end = m.end()
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

    affected = {}
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
# Phase 2 – Apply "Yada Towrah" font
# ---------------------------------------------------------------------------

def apply_yt_font_to_half_rings(p_elem):
    for r in list(p_elem.iter(qn('w:r'))):
        t_elems = r.findall(qn('w:t'))
        if not t_elems:
            continue
        text = ''.join(t.text or '' for t in t_elems)
        if not any(ch in text for ch in HALF_RINGS):
            continue

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

        if len(segments) == 1:
            if segments[0][1]:
                _set_yt_font(r)
            continue

        orig_copy = deepcopy(r)
        for t in t_elems:
            r.remove(t)

        _add_t(r, segments[0][0])
        if segments[0][1]:
            _set_yt_font(r)

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


def _add_t(run_elem, text):
    t = etree.SubElement(run_elem, qn('w:t'))
    t.text = text
    if text and (text[0] == ' ' or text[-1] == ' '):
        t.set(qn('xml:space'), 'preserve')


def _set_yt_font(run_elem):
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
# Document processing
# ---------------------------------------------------------------------------

def collect_paragraph_elements(doc):
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
                hdr = getattr(section, hdr_attr)
                if hdr is None or hdr.is_linked_to_previous:
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
    fname = os.path.basename(filepath)
    try:
        doc = Document(filepath)
    except Exception as e:
        print(f"  ERROR opening {fname}: {e}")
        return 0, 0

    paras = collect_paragraph_elements(doc)
    total_chars = 0
    paras_modified = 0

    for p in paras:
        n = process_element(p, lookup)
        if n:
            paras_modified += 1
            total_chars += n

    for p in paras:
        apply_yt_font_to_half_rings(p)

    if total_chars == 0:
        print(f"  {fname}: no new replacements")
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
    # Step 1: Validate mapping lengths
    print("Validating new mappings ...")
    errors = 0
    for old, new, heb, note in NEW_MAPPINGS:
        if len(old) != len(new):
            print(f"  LENGTH ERROR: '{old}' ({len(old)}) vs '{new}' ({len(new)}) — {note}")
            errors += 1
    if errors:
        print(f"\n{errors} mapping errors. Fix before continuing.")
        sys.exit(1)
    print(f"  {len(NEW_MAPPINGS)} mappings validated OK\n")

    # Step 2: Insert new mappings into DB
    print("Inserting new mappings into yy_word_map_modifier ...")
    added = insert_new_mappings()

    # Step 3: Load ALL mappings (existing + new)
    print("\nLoading all mappings from yy_word_map_modifier ...")
    all_mappings = load_all_mappings()
    print(f"  {len(all_mappings)} total mappings")

    lookup = build_lookup(all_mappings)
    print(f"  {len(lookup)} lookup entries prepared")

    # Step 4: Process documents
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

    print(f"\nDone!  {grand_chars} new characters replaced across "
          f"{grand_paras} paragraphs in {len(docs)} documents.")

    # Step 5: Summary of what was added
    print(f"\n{'='*60}")
    print("NEW MAPPINGS ADDED:")
    print(f"{'Old':<25} {'New':<25} {'Hebrew':<15} {'Note'}")
    print('-' * 80)
    for old, new, heb, note in NEW_MAPPINGS:
        try:
            print(f"{old:<25} {new:<25} {heb:<15} {note}")
        except UnicodeEncodeError:
            print(f"{old:<25} {new:<25} {'(unicode)':<15} {note}")


if __name__ == '__main__':
    main()
