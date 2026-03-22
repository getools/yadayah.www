"""
Replace specific Hebrew transliteration words in all YY Word documents.
Handles body text, TOC, headers, footers, tables, and hyperlinks.

Replacements:
1. 'emerym   -> ʾemerym   (quote at start replaced with ALEPH)
2. male'     -> maleʾ     (quote at end replaced with ALEPH)
3. merya'    -> meryaʾ    (quote at end replaced with ALEPH)
4. pe'oth    -> peʾoth    (quote in middle replaced with ALEPH)
5. sha'eryth -> shaʾeryth (quote in middle replaced with ALEPH)

Phase 1: Fix peʿoth -> peʾoth (wrong modifier character AYIN->ALEPH)
         Works at run level since words are split across runs.
Phase 2: Replace any remaining apostrophe variants with ALEPH
         (handles both single-run and cross-run cases)
Phase 3: Apply Yada Towrah font to all half-ring modifier characters.
"""

import sys, os, glob, time, copy, re
sys.stdout.reconfigure(encoding='utf-8')

from docx import Document
from docx.oxml import OxmlElement
from docx.oxml.ns import qn

ALEPH = chr(0x02BE)  # ʾ modifier letter right half ring
AYIN = chr(0x02BF)   # ʿ modifier letter left half ring

# Quote characters to match
QUOTE_CHARS = set("'\u2018\u2019\u0060\u2032\u02BC")

# Quote character class for regex
QUOTES = r"['\u2018\u2019\u0060\u2032\u02BC]"

# Replacement patterns for single w:t elements
REPLACEMENTS = [
    # 'emerym -> ʾemerym (quote at start)
    (re.compile(rf"(?<![a-zA-Z\u02BE\u02BF]){QUOTES}(emerym)(?![a-zA-Z])", re.IGNORECASE),
     lambda m: f'{ALEPH}{m.group(1)}'),

    # male' -> maleʾ (quote at end)
    (re.compile(rf"(?<![a-zA-Z])(male){QUOTES}(?![a-zA-Z])", re.IGNORECASE),
     lambda m: f'{m.group(1)}{ALEPH}'),

    # merya' -> meryaʾ (quote at end)
    (re.compile(rf"(?<![a-zA-Z])(merya){QUOTES}(?![a-zA-Z])", re.IGNORECASE),
     lambda m: f'{m.group(1)}{ALEPH}'),

    # pe'oth -> peʾoth (quote in middle)
    (re.compile(rf"(?<![a-zA-Z])(pe){QUOTES}(oth)(?![a-zA-Z])", re.IGNORECASE),
     lambda m: f'{m.group(1)}{ALEPH}{m.group(2)}'),

    # sha'eryth -> shaʾeryth (quote in middle)
    (re.compile(rf"(?<![a-zA-Z])(sha){QUOTES}(eryth)(?![a-zA-Z])", re.IGNORECASE),
     lambda m: f'{m.group(1)}{ALEPH}{m.group(2)}'),
]


def get_run_text(r_elem):
    """Get text from a w:r element."""
    t = r_elem.find(qn('w:t'))
    return t.text if t is not None and t.text else ''


def set_run_text(r_elem, new_text):
    """Set text on a w:r element."""
    t = r_elem.find(qn('w:t'))
    if t is not None:
        t.text = new_text


def fix_peoth_ayin_to_aleph(root_element):
    """
    Fix peʿoth -> peʾoth by finding AYIN runs between 'pe' and 'oth' runs.
    Returns count of fixes.
    """
    fixes = 0
    all_runs = root_element.findall('.//' + qn('w:r'))

    for i, r in enumerate(all_runs):
        text = get_run_text(r)
        if text != AYIN:
            continue

        # Check if previous run ends with 'pe' (case-insensitive)
        if i > 0:
            prev_text = get_run_text(all_runs[i - 1])
            if not prev_text.lower().endswith('pe'):
                continue
        else:
            continue

        # Check if next run starts with 'oth' (case-insensitive)
        if i + 1 < len(all_runs):
            next_text = get_run_text(all_runs[i + 1])
            if not next_text.lower().startswith('oth'):
                continue
        else:
            continue

        # Found peʿoth pattern - change AYIN to ALEPH
        set_run_text(r, ALEPH)
        fixes += 1
        print(f"    Fixed: pe{AYIN}oth -> pe{ALEPH}oth")

    return fixes


def fix_cross_run_words(root_element):
    """
    Fix words split across runs where apostrophe/quote is in its own run.
    Handles patterns like: [run:'emerym] or [run:male][run:'][run: next]
    Returns count of fixes.
    """
    fixes = 0
    all_runs = root_element.findall('.//' + qn('w:r'))

    for i, r in enumerate(all_runs):
        text = get_run_text(r)
        if not text or len(text) != 1:
            continue
        if text not in QUOTE_CHARS:
            continue

        # This run is a single quote character
        # Check patterns:

        # Pattern: [quote][emerym...] -> 'emerym at start
        if i + 1 < len(all_runs):
            next_text = get_run_text(all_runs[i + 1])
            if re.match(r'emerym\b', next_text, re.IGNORECASE):
                set_run_text(r, ALEPH)
                fixes += 1
                print(f"    Fixed cross-run: {text}emerym -> {ALEPH}emerym")
                continue

        # Pattern: [male][quote] -> male' at end
        if i > 0:
            prev_text = get_run_text(all_runs[i - 1])
            if prev_text.lower().endswith('male'):
                # Make sure quote is at word end (next run doesn't start with letter)
                next_text = get_run_text(all_runs[i + 1]) if i + 1 < len(all_runs) else ''
                if not next_text or not next_text[0].isalpha():
                    set_run_text(r, ALEPH)
                    fixes += 1
                    print(f"    Fixed cross-run: male{text} -> male{ALEPH}")
                    continue

        # Pattern: [merya][quote] -> merya' at end
        if i > 0:
            prev_text = get_run_text(all_runs[i - 1])
            if prev_text.lower().endswith('merya'):
                next_text = get_run_text(all_runs[i + 1]) if i + 1 < len(all_runs) else ''
                if not next_text or not next_text[0].isalpha():
                    set_run_text(r, ALEPH)
                    fixes += 1
                    print(f"    Fixed cross-run: merya{text} -> merya{ALEPH}")
                    continue

        # Pattern: [pe][quote][oth...] -> pe'oth in middle
        if i > 0 and i + 1 < len(all_runs):
            prev_text = get_run_text(all_runs[i - 1])
            next_text = get_run_text(all_runs[i + 1])
            if prev_text.lower().endswith('pe') and next_text.lower().startswith('oth'):
                set_run_text(r, ALEPH)
                fixes += 1
                print(f"    Fixed cross-run: pe{text}oth -> pe{ALEPH}oth")
                continue

        # Pattern: [sha][quote][eryth...] -> sha'eryth in middle
        if i > 0 and i + 1 < len(all_runs):
            prev_text = get_run_text(all_runs[i - 1])
            next_text = get_run_text(all_runs[i + 1])
            if prev_text.lower().endswith('sha') and next_text.lower().startswith('eryth'):
                set_run_text(r, ALEPH)
                fixes += 1
                print(f"    Fixed cross-run: sha{text}eryth -> sha{ALEPH}eryth")
                continue

    return fixes


def phase_replace_in_wt(root_element, label="body"):
    """Replace apostrophes with ALEPH in single w:t elements."""
    replacements = 0
    details = []

    for element in root_element.iter():
        if element.tag == qn('w:t') and element.text:
            original = element.text
            text = original
            for pattern, repl_func in REPLACEMENTS:
                text = pattern.sub(repl_func, text)
            if text != original:
                element.text = text
                replacements += 1
                details.append(f"    [{label}] {original} -> {text}")

    return replacements, details


def split_and_font(r_elem):
    """Split run at modifier positions and apply Yada Towrah font."""
    t_elem = r_elem.find(qn('w:t'))
    if t_elem is None:
        return 0
    text = t_elem.text or ''
    if not text or (ALEPH not in text and AYIN not in text):
        return 0

    # Single modifier character - just set font
    if text in (ALEPH, AYIN):
        rPr = r_elem.find(qn('w:rPr'))
        if rPr is None:
            rPr = OxmlElement('w:rPr')
            r_elem.insert(0, rPr)
        rFonts = rPr.find(qn('w:rFonts'))
        if rFonts is not None:
            if rFonts.get(qn('w:ascii'), '') == 'Yada Towrah':
                return 0  # Already correct
        if rFonts is None:
            rFonts = OxmlElement('w:rFonts')
            rPr.append(rFonts)
        rFonts.set(qn('w:ascii'), 'Yada Towrah')
        rFonts.set(qn('w:hAnsi'), 'Yada Towrah')
        return 1

    # Mixed text + modifiers - need to split
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
            rFonts = OxmlElement('w:rFonts')
            rFonts.set(qn('w:ascii'), 'Yada Towrah')
            rFonts.set(qn('w:hAnsi'), 'Yada Towrah')
            p.append(rFonts)
        r.append(p)
        t = OxmlElement('w:t')
        t.set(qn('xml:space'), 'preserve')
        t.text = content
        r.append(t)
        return r

    parent.remove(r_elem)
    cur = idx
    modifier_count = 0
    for seg_type, seg_text in segments:
        new_run = make_run(seg_text, is_modifier=(seg_type == 'modifier'))
        parent.insert(cur, new_run)
        cur += 1
        if seg_type == 'modifier':
            modifier_count += 1

    return modifier_count


def phase_apply_fonts(root_element):
    """Apply Yada Towrah font to all modifier characters."""
    fixes = 0
    all_runs = root_element.findall('.//' + qn('w:r'))

    i = 0
    while i < len(all_runs):
        r = all_runs[i]
        t_elem = r.find(qn('w:t'))
        if t_elem is not None:
            text = t_elem.text or ''
            if ALEPH in text or AYIN in text:
                result = split_and_font(r)
                if result > 0:
                    fixes += result
                    all_runs = root_element.findall('.//' + qn('w:r'))
                    continue
        i += 1

    return fixes


def process_document(docx_path):
    """Process a single document."""
    doc_name = os.path.basename(docx_path)
    print(f"\nProcessing: {doc_name}")
    start = time.time()

    try:
        doc = Document(docx_path)
    except Exception as e:
        print(f"  ERROR: {e}")
        return {'replacements': 0, 'font_fixes': 0}

    total_repl = 0
    all_details = []

    # --- Phase 1: Fix peʿoth -> peʾoth (AYIN to ALEPH) ---
    # Body
    ayin_fixes = fix_peoth_ayin_to_aleph(doc.element.body)
    total_repl += ayin_fixes
    # Headers/footers
    for section in doc.sections:
        if section.header:
            total_repl += fix_peoth_ayin_to_aleph(section.header._element)
        if section.footer:
            total_repl += fix_peoth_ayin_to_aleph(section.footer._element)

    # --- Phase 2: Fix cross-run apostrophe words ---
    # Body
    cross_fixes = fix_cross_run_words(doc.element.body)
    total_repl += cross_fixes
    # Headers/footers
    for section in doc.sections:
        if section.header:
            total_repl += fix_cross_run_words(section.header._element)
        if section.footer:
            total_repl += fix_cross_run_words(section.footer._element)

    # --- Phase 3: Replace any remaining apostrophes in single w:t elements ---
    repl, details = phase_replace_in_wt(doc.element, "body")
    total_repl += repl
    all_details.extend(details)
    for i, section in enumerate(doc.sections):
        if section.header:
            repl, details = phase_replace_in_wt(section.header._element, f"header-s{i}")
            total_repl += repl
            all_details.extend(details)
        if section.footer:
            repl, details = phase_replace_in_wt(section.footer._element, f"footer-s{i}")
            total_repl += repl
            all_details.extend(details)

    # --- Phase 4: Apply Yada Towrah font to all modifier characters ---
    font_fixes = 0
    font_fixes += phase_apply_fonts(doc.element.body)
    for section in doc.sections:
        if section.header:
            font_fixes += phase_apply_fonts(section.header._element)
        if section.footer:
            font_fixes += phase_apply_fonts(section.footer._element)

    elapsed = time.time() - start

    if total_repl > 0 or font_fixes > 0:
        for d in all_details:
            print(d)
        try:
            doc.save(docx_path)
            print(f"  Saved: {total_repl} replacements, {font_fixes} font fixes ({elapsed:.1f}s)")
        except Exception as e:
            print(f"  ERROR saving: {e}")
            return {'replacements': 0, 'font_fixes': 0}
    else:
        print(f"  No changes ({elapsed:.1f}s)")

    return {'replacements': total_repl, 'font_fixes': font_fixes}


# Main
docs_dir = r'C:\users\joe\work\dev\yada\docs'
print("=" * 80)
print("Replacing Hebrew words with proper ALEPH modifier")
print("=" * 80)
print("Replacements:")
print(f"  'emerym   -> {ALEPH}emerym")
print(f"  male'     -> male{ALEPH}")
print(f"  merya'    -> merya{ALEPH}")
print(f"  pe'oth    -> pe{ALEPH}oth")
print(f"  sha'eryth -> sha{ALEPH}eryth")
print()
print("Also fixes: pe" + AYIN + "oth -> pe" + ALEPH + "oth (wrong modifier)")
print()
print("Quote variants matched: ' \u2018 \u2019 ` \u2032 \u02BC")
print("All half-ring modifiers will use Yada Towrah font")
print()

# Get YY files
all_files = glob.glob(os.path.join(docs_dir, 'YY-s*.docx'))
docx_files = sorted([
    f for f in all_files
    if not any(x in os.path.basename(f).lower()
               for x in ['_updated', '_final', '_fmt', '_formatted', '_fixed',
                          '_test', '_reverted', '_tagline', '_debug', '_backup'])
])

print(f"Found {len(docx_files)} documents")
print("=" * 80)

total_files_updated = 0
total_repl = 0
total_fonts = 0

for path in docx_files:
    result = process_document(path)
    if result['replacements'] > 0 or result['font_fixes'] > 0:
        total_files_updated += 1
        total_repl += result['replacements']
        total_fonts += result['font_fixes']

print("\n" + "=" * 80)
print("COMPLETE")
print("=" * 80)
print(f"  Files processed: {len(docx_files)}")
print(f"  Files updated:   {total_files_updated}")
print(f"  Text replacements: {total_repl}")
print(f"  Font fixes:      {total_fonts}")
