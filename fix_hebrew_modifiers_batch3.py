"""
Hebrew modifier replacement - Batch 3.
Replaces apostrophe variants with ALEPH (ʾ) or AYIN (ʿ).
Handles body, tables, TOC (hyperlinks), headers, footers.
Cross-run aware. Applies Yada Towrah font to all modifiers.
"""

import sys, os, glob, time, copy
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document
from docx.oxml import OxmlElement
from docx.oxml.ns import qn

ALEPH = chr(0x02BE)  # ʾ
AYIN = chr(0x02BF)   # ʿ

APOSTROPHE_VARIANTS = [
    chr(0x0027), chr(0x2019), chr(0x2018),
    chr(0x0060), chr(0x00B4), chr(0x02BC),
]

# Batch 3 patterns
BASE_REPLACEMENTS = [
    ("'ElYah", "\u02BEElYah"),
    ("'anachnuw", "\u02BEanachnuw"),
    ("L'Chayim", "L\u02BEChayim"),
    ("Ba'al", "Ba\u02BFal"),
    ("ba'al", "ba\u02BFal"),
    ("Hora'ah", "Hora\u02BEah"),
    ("hora'ah", "hora\u02BEah"),
    ("ra'ah", "ra\u02BEah"),
    ("Ra'ah", "Ra\u02BEah"),
]


def generate_all_variants():
    all_patterns = []
    seen = set()
    for old, new in BASE_REPLACEMENTS:
        for apos in APOSTROPHE_VARIANTS:
            variant = old.replace("'", apos)
            if variant != new and variant not in seen:
                all_patterns.append((variant, new))
                seen.add(variant)
    all_patterns.sort(key=lambda x: len(x[0]), reverse=True)
    return all_patterns


ALL_PATTERNS = generate_all_variants()


def replace_in_text(text):
    for old, new in ALL_PATTERNS:
        if old in text:
            text = text.replace(old, new)
    return text


def get_run_text(r_elem):
    t = r_elem.find(qn('w:t'))
    if t is None:
        return ''
    return t.text or ''


def set_run_text(r_elem, text):
    t = r_elem.find(qn('w:t'))
    if t is None:
        t = OxmlElement('w:t')
        t.set(qn('xml:space'), 'preserve')
        r_elem.append(t)
    t.text = text
    t.set(qn('xml:space'), 'preserve')


def split_and_font(r_elem):
    """Split run at modifier positions and apply Yada Towrah font."""
    t_elem = r_elem.find(qn('w:t'))
    if t_elem is None:
        return 0
    text = t_elem.text or ''
    if not text or (ALEPH not in text and AYIN not in text):
        return 0

    if text in (ALEPH, AYIN):
        rPr = r_elem.find(qn('w:rPr'))
        if rPr is None:
            rPr = OxmlElement('w:rPr')
            r_elem.insert(0, rPr)
        rFonts = rPr.find(qn('w:rFonts'))
        if rFonts is not None and rFonts.get(qn('w:ascii'), '') == 'Yada Towrah':
            return 0
        if rFonts is None:
            rFonts = OxmlElement('w:rFonts')
            rPr.append(rFonts)
        rFonts.set(qn('w:ascii'), 'Yada Towrah')
        rFonts.set(qn('w:hAnsi'), 'Yada Towrah')
        return 1

    parent = r_elem.getparent()
    if parent is None:
        return 0

    idx = list(parent).index(r_elem)
    rPr = r_elem.find(qn('w:rPr'))

    segments = []
    buf = []
    for ch in text:
        if ch in (ALEPH, AYIN):
            if buf:
                segments.append(('text', ''.join(buf)))
                buf = []
            segments.append(('modifier', ch))
        else:
            buf.append(ch)
    if buf:
        segments.append(('text', ''.join(buf)))

    if len(segments) <= 1:
        return 0

    def clone_rPr(exclude_font=False):
        new_rPr = OxmlElement('w:rPr')
        if rPr is not None:
            for child in rPr:
                if exclude_font and child.tag == qn('w:rFonts'):
                    continue
                new_rPr.append(copy.deepcopy(child))
        return new_rPr

    def make_run(content, is_modifier=False):
        r = OxmlElement('w:r')
        p = clone_rPr(exclude_font=is_modifier)
        if is_modifier:
            rf = OxmlElement('w:rFonts')
            rf.set(qn('w:ascii'), 'Yada Towrah')
            rf.set(qn('w:hAnsi'), 'Yada Towrah')
            p.append(rf)
        r.append(p)
        t = OxmlElement('w:t')
        t.set(qn('xml:space'), 'preserve')
        t.text = content
        r.append(t)
        return r

    parent.remove(r_elem)
    cur = idx
    count = 0
    for seg_type, seg_text in segments:
        nr = make_run(seg_text, is_modifier=(seg_type == 'modifier'))
        parent.insert(cur, nr)
        cur += 1
        if seg_type == 'modifier':
            count += 1
    return count


def process_runs_in_container(container_elem):
    """Replace patterns in all w:r elements within a container, handle cross-run, split/font."""
    fixes = 0

    # Phase 1: same-run replacements
    for r in container_elem.findall('.//' + qn('w:r')):
        text = get_run_text(r)
        if not text:
            continue
        new_text = replace_in_text(text)
        if new_text != text:
            old_m = text.count(ALEPH) + text.count(AYIN)
            new_m = new_text.count(ALEPH) + new_text.count(AYIN)
            fixes += max(0, new_m - old_m)
            set_run_text(r, new_text)

    # Phase 2: cross-run in each parent (paragraph or hyperlink)
    # Find all direct-run parents
    parents_seen = set()
    for r in container_elem.findall('.//' + qn('w:r')):
        p = r.getparent()
        pid = id(p)
        if pid in parents_seen:
            continue
        parents_seen.add(pid)

        for _ in range(50):
            runs = p.findall(qn('w:r'))
            texts = [get_run_text(r) for r in runs]
            full = ''.join(texts)

            found = False
            for search, replacement in ALL_PATTERNS:
                pos = full.find(search)
                if pos == -1:
                    continue

                char_count = 0
                sr = er = None
                for ri, rt in enumerate(texts):
                    rs = char_count
                    re = char_count + len(rt)
                    if sr is None and pos >= rs and pos < re:
                        sr = ri
                    if sr is not None and pos + len(search) <= re:
                        er = ri
                        break
                    char_count = re

                if sr is None or er is None:
                    continue

                if sr == er:
                    so = pos - sum(len(texts[i]) for i in range(sr))
                    eo = so + len(search)
                    old_t = texts[sr]
                    new_t = old_t[:so] + replacement + old_t[eo:]
                    set_run_text(runs[sr], new_t)
                    fixes += max(0, new_t.count(ALEPH) + new_t.count(AYIN) - old_t.count(ALEPH) - old_t.count(AYIN))
                else:
                    combined = ''.join(texts[sr:er+1])
                    new_combined = combined.replace(search, replacement, 1)
                    set_run_text(runs[sr], new_combined)
                    for ri in range(er, sr, -1):
                        p.remove(runs[ri])
                    fixes += max(0, new_combined.count(ALEPH) + new_combined.count(AYIN) - combined.count(ALEPH) - combined.count(AYIN))

                found = True
                break

            if not found:
                break

    # Phase 3: split modifiers and ensure font
    all_runs = container_elem.findall('.//' + qn('w:r'))
    i = 0
    while i < len(all_runs):
        r = all_runs[i]
        text = get_run_text(r)
        if text and (ALEPH in text or AYIN in text):
            result = split_and_font(r)
            if result > 0:
                all_runs = container_elem.findall('.//' + qn('w:r'))
                continue
        i += 1

    return fixes


def process_document(docx_path):
    doc_name = os.path.basename(docx_path)
    print(f"Processing: {doc_name}")
    start = time.time()

    try:
        doc = Document(docx_path)
    except Exception as e:
        print(f"  ERROR: {e}")
        return 0

    fixes = 0
    fixes += process_runs_in_container(doc.element.body)

    for section in doc.sections:
        for part in [section.header, section.footer]:
            if part:
                fixes += process_runs_in_container(part._element)

    elapsed = time.time() - start

    if fixes > 0:
        try:
            doc.save(docx_path)
            print(f"  Fixed {fixes} ({elapsed:.1f}s)")
        except Exception as e:
            print(f"  ERROR saving: {e}")
            return 0
    else:
        print(f"  No changes ({elapsed:.1f}s)")

    return fixes


# Main
docs_dir = r'C:\users\joe\work\dev\yada\docs'
print("Hebrew modifier replacement - Batch 3")
print(f"{len(BASE_REPLACEMENTS)} base patterns x {len(APOSTROPHE_VARIANTS)} variants = {len(ALL_PATTERNS)} total")
print("=" * 80)

all_files = glob.glob(os.path.join(docs_dir, 'YY*.docx'))
docx_files = [f for f in all_files
              if not any(x in os.path.basename(f).lower()
                         for x in ['_updated', '_final', '_fmt', '_formatted',
                                   '_fixed', '_test', '_reverted', '_tagline',
                                   '_debug', '_backup'])]

print(f"Found {len(docx_files)} documents\n")

total = 0
for path in sorted(docx_files):
    total += process_document(path)

print("\n" + "=" * 80)
print(f"COMPLETE: {total} fixes across {len(docx_files)} documents")
