"""
Parse Dead Sea Scrolls parallel manuscripts PDF into dss_scroll / dss_verse tables.

Structure:
- Title line: "Dead Sea Scrolls Parallel Manuscripts - Genesis / בראשית"
- Chapter headers: Blue Cambria-Bold "Chapter N: ..." or "Psalm N: ..."
- Verse numbers: Blue Cambria-Bold size=11, standalone number
- Scroll code: Black Calibri size=14 + superscript size=9
- Hebrew text: TimesNewRomanPSMT size=20 with color markup:
    olive (128,128,0) = preserved text
    green (0,176,80)  = reconstructed text
    blue  (0,112,192) = brackets [ ]
    red   (255,0,0)   = variant/other

Some PDFs use garbled font encoding:
- Hebrew chars shifted by -0x330 (U+02A0-U+02BF instead of U+05D0-U+05EF)
- Latin text uses custom glyph mapping (not a simple offset)
- Digits encoded as U+0372-U+037B (offset -0x342 from ASCII)
- Colon/semicolon as U+01E3/U+01E2
"""

import pymupdf
import re
import sys
import os
import tempfile
import psycopg2
from dotenv import load_dotenv

load_dotenv()

CALIBRI_FONT_PATH = r"C:\Windows\Fonts\calibri.ttf"

PDF_PATH = sys.argv[1] if len(sys.argv) > 1 else r"C:\Users\Joe\Downloads\Parallel_DSS_-_Genesis.pdf"

DB_HOST = os.environ.get("PG_HOST", "localhost")
DB_PORT = os.environ.get("PG_PORT", "5433")
DB_NAME = "yada"
DB_USER = "postgres"
DB_PASS = os.environ.get("POSTGRES_PASSWORD", "postgres")

# Color map for Hebrew text markup (with entries for garbled PDF color variants)
COLOR_MAP = {
    (128, 128, 0): "olive",      # preserved/extant
    (127, 129, 51): "olive",     # alternate olive in garbled PDFs
    (0, 176, 80): "green",       # reconstructed
    (0, 176, 79): "green",       # alternate green
    (0, 112, 192): "blue",       # brackets
    (25, 112, 185): "blue",      # alternate blue in garbled PDFs
    (255, 0, 0): "red",          # variant
    (0, 0, 0): "black",          # normal black text
    (35, 31, 32): "black",       # alternate black in garbled PDFs
}

def color_tuple(c):
    return ((c >> 16) & 0xff, (c >> 8) & 0xff, c & 0xff)

def color_name(rgb):
    for key, name in COLOR_MAP.items():
        if abs(key[0] - rgb[0]) < 30 and abs(key[1] - rgb[1]) < 30 and abs(key[2] - rgb[2]) < 30:
            return name
    return None

# Garbled font encoding prefixes
GARBLED_CHAPTER_PREFIX = '\x06\x8a\x83\x92\x96\x87\x94'  # garbled "Chapter"
GARBLED_PSALM_PREFIX = '\x13\x95\x83\x8e\x8f'              # garbled "Psalm"

def fix_hebrew(text):
    """Fix garbled Hebrew codepoints to standard Hebrew (U+05D0-U+05EF).
    Some PDFs encode Hebrew characters with offsets from their correct codepoint:
    - TimesNewRoman garbled: U+02A0-U+02BF (offset -0x0330)
    - EvyoniHebrewEncodedPalae garbled: U+00A8-U+00C2 (offset -0x0528)"""
    result = []
    for ch in text:
        cp = ord(ch)
        if 0x02A0 <= cp <= 0x02BF:
            result.append(chr(cp + 0x0330))
        elif 0x00A8 <= cp <= 0x00C2:
            result.append(chr(cp + 0x0528))
        else:
            result.append(ch)
    return ''.join(result)

# Mapping from IPA/Latin Extended-B codepoints to Greek Unicode.
# Some PDFs (e.g. 4QLXXNum, 4QLXXDeut) extract Greek text as IPA characters
# because the font's ToUnicode CMap maps GIDs to Latin Extended-B instead of Greek.
IPA_TO_GREEK = {
    # Lowercase
    '\u0272': '\u03B1',  # ɲ → α
    '\u0274': '\u03B2',  # ɴ → β
    '\u0276': '\u03B3',  # ɶ → γ
    '\u0277': '\u03B4',  # ɷ → δ
    '\u0278': '\u03B5',  # ɸ → ε
    '\u027A': '\u03B6',  # ɺ → ζ
    '\u027B': '\u03B7',  # ɻ → η
    '\u027D': '\u03B8',  # ɽ → θ
    '\u027F': '\u03B9',  # ɿ → ι
    '\u0283': '\u03BA',  # ʃ → κ
    '\u0284': '\u03BB',  # ʄ → λ
    '\u0285': '\u03BC',  # ʅ → μ
    '\u0286': '\u03BD',  # ʆ → ν
    '\u0287': '\u03BE',  # ʇ → ξ
    '\u0289': '\u03BF',  # ʉ → ο
    '\u028B': '\u03C0',  # ʋ → π
    '\u028C': '\u03C1',  # ʌ → ρ
    '\u028D': '\u03C3',  # ʍ → σ
    '\u028E': '\u03C2',  # ʎ → ς (final sigma)
    '\u028F': '\u03C4',  # ʏ → τ
    '\u0290': '\u03C5',  # ʐ → υ
    '\u0294': '\u03C6',  # ʔ → φ
    '\u0296': '\u03C7',  # ʖ → χ
    '\u0297': '\u03C8',  # ʗ → ψ
    '\u0298': '\u03C9',  # ʘ → ω
    # Uppercase
    '\u0230': '\u0391',  # Ȱ → Α
    '\u0235': '\u0395',  # ȵ → Ε
    '\u023B': '\u0399',  # Ȼ → Ι
    '\u023E': '\u039A',  # Ⱦ → Κ
    '\u023F': '\u039B',  # ȿ → Λ
    '\u0240': '\u039C',  # ɀ → Μ
    # Artifact
    '\u0358': '',         # combining dot above right → remove
}

_ipa_greek_trans = str.maketrans(IPA_TO_GREEK)

def fix_ipa_to_greek(text):
    """Convert IPA/Latin Extended-B characters to proper Greek Unicode."""
    return text.translate(_ipa_greek_trans)

def decode_garbled_digits(text):
    """Decode garbled digit characters (0x0372-0x037B -> '0'-'9') or normal ASCII digits."""
    digits = ''
    for ch in text:
        code = ord(ch)
        if 0x0372 <= code <= 0x037B:
            digits += chr(code - 0x0342)
        elif 0x30 <= code <= 0x39:  # normal ASCII digit
            digits += ch
        elif code == 0x03:  # garbled space
            if digits:
                break
            continue
        elif digits:
            break
    return digits

def build_calibri_cmap():
    """Build a ToUnicode CMap for Calibri font using system font's GID-to-Unicode mapping."""
    try:
        from fontTools.ttLib import TTFont
    except ImportError:
        return None

    if not os.path.exists(CALIBRI_FONT_PATH):
        return None

    ttfont = TTFont(CALIBRI_FONT_PATH)
    cmap_data = ttfont.getBestCmap()
    glyph_order = ttfont.getGlyphOrder()

    name_to_unicode = {}
    for cp, name in cmap_data.items():
        if name not in name_to_unicode:
            name_to_unicode[name] = cp

    gid_to_unicode = {}
    for gid, name in enumerate(glyph_order):
        if name in name_to_unicode:
            gid_to_unicode[gid] = name_to_unicode[name]
    ttfont.close()

    bfchar_lines = []
    for gid in sorted(gid_to_unicode.keys()):
        bfchar_lines.append(f'<{gid:04X}> <{gid_to_unicode[gid]:04X}>')

    cmap_parts = []
    for i in range(0, len(bfchar_lines), 100):
        chunk = bfchar_lines[i:i+100]
        cmap_parts.append(f'{len(chunk)} beginbfchar\n' + '\n'.join(chunk) + '\nendbfchar')

    cs = '/CIDInit /ProcSet findresource begin 12 dict begin begincmap /CIDSystemInfo <<\n'
    cs += '/Registry (Fix) /Ordering (T42UV) /Supplement 0 >> def\n'
    cs += '/CMapName /Fix def /CMapType 2 def\n'
    cs += '1 begincodespacerange <0000> <FFFF> endcodespacerange\n'
    cs += '\n'.join(cmap_parts)
    cs += '\nendcmap CMapName currentdict /CMap defineresource pop end end'
    return cs.encode('latin-1')


def fix_calibri_tounicode(pdf_path):
    """Fix Calibri fonts without ToUnicode CMap in a PDF.

    Some PDFs have subsetted Calibri fonts missing the ToUnicode mapping,
    causing Greek text to decode as U+FFFD. This injects a CMap built from
    the system Calibri font.

    Returns the path to a fixed temp PDF, or the original path if no fix needed.
    """
    doc = pymupdf.open(pdf_path)

    # Find a Calibri font without ToUnicode
    needs_fix = False
    no_tounicode_xrefs = set()
    tounicode_xref = None

    for page_idx in range(min(3, doc.page_count)):
        for fi in doc[page_idx].get_fonts():
            font_name = fi[3] if len(fi) > 3 else ''
            if 'Calibri' not in str(font_name) or 'Bold' in str(font_name):
                continue
            xref = fi[0]
            obj = doc.xref_object(xref)
            if '/ToUnicode' not in obj:
                no_tounicode_xrefs.add(xref)
                needs_fix = True
            else:
                # Extract existing ToUnicode xref to use as template
                m = re.search(r'/ToUnicode\s+(\d+)\s+0\s+R', obj)
                if m:
                    tounicode_xref = int(m.group(1))

    if not needs_fix:
        doc.close()
        return pdf_path

    cmap_bytes = build_calibri_cmap()
    if cmap_bytes is None:
        doc.close()
        return pdf_path

    if tounicode_xref and doc.xref_is_stream(tounicode_xref):
        # Replace existing CMap stream content with our comprehensive mapping
        doc.update_stream(tounicode_xref, cmap_bytes)
        # Point all Calibri fonts without ToUnicode to this CMap
        for xref in no_tounicode_xrefs:
            doc.xref_set_key(xref, 'ToUnicode', f'{tounicode_xref} 0 R')
    else:
        doc.close()
        return pdf_path

    tmp_path = os.path.join(tempfile.gettempdir(), f'dss_fixed_{os.path.basename(pdf_path)}')
    doc.save(tmp_path)
    doc.close()
    return tmp_path


def combine_block_first_line(page_items, idx):
    """Combine text from all Cambria ~13pt spans in the same block's first line."""
    bi = page_items[idx][0]
    li = page_items[idx][1]
    combined = ''
    j = idx
    while j < len(page_items):
        nbi, nli = page_items[j][0], page_items[j][1]
        if nbi != bi or nli != li:
            break
        nspan = page_items[j][3]
        if 'Cambria' in nspan['font'] and abs(nspan['size'] - 13.0) < 1.5:
            combined += nspan['text']
        j += 1
    return combined

def extract_chapter_number(page_items, idx):
    """Extract chapter number from a chapter/psalm header block starting at idx.
    Returns (chapter_number_or_None, end_idx) if this is a chapter header,
    or (None, None) if not."""
    bi, li, si, span, block, line = page_items[idx]
    font = span["font"]
    size = span["size"]

    if "Cambria" not in font or abs(size - 13.0) >= 1.5:
        return None, None

    # Combine all Cambria ~13pt spans from this block's first line
    combined = combine_block_first_line(page_items, idx)
    # Strip standard whitespace AND \x03 (garbled space) from edges
    stripped = combined.strip().strip('\x03').strip()

    # Check for chapter/psalm header patterns
    is_garbled_ch = stripped.startswith(GARBLED_CHAPTER_PREFIX)
    is_garbled_ps = stripped.startswith(GARBLED_PSALM_PREFIX)
    # Handle split-span "Chapter"/"Psalm" (e.g., "Chapte" + "r 1:")
    # Also handle hybrid encoding where "Ch" or "Chap" is normal but rest is garbled
    is_normal = 'Chapter' in stripped[:15] or 'Psalm' in stripped[:12]
    is_hybrid_ch = not is_normal and stripped[:2] == 'Ch' and len(stripped) > 4

    if not (is_garbled_ch or is_garbled_ps or is_normal or is_hybrid_ch):
        return None, None

    # Find end of this block
    end_idx = idx
    while end_idx < len(page_items) and page_items[end_idx][0] == bi:
        end_idx += 1

    # Strategy 1: Normal "Chapter N" or "Psalm N" in combined text
    m = re.search(r'(?:Chapter|Psalm)\s+(\d+)', stripped)
    if m:
        return int(m.group(1)), end_idx

    # Strategy 2: Garbled prefix with garbled digits
    prefix_len = len(GARBLED_CHAPTER_PREFIX) if is_garbled_ch else len(GARBLED_PSALM_PREFIX) if is_garbled_ps else 0
    if prefix_len:
        after = stripped[prefix_len:].lstrip('\x03')
        digits = decode_garbled_digits(after)
        if digits:
            return int(digits), end_idx

    # Strategy 2b: Hybrid "Ch"/"Chap" + garbled suffix — skip non-digit garbled chars, then decode digits
    if is_hybrid_ch:
        after = stripped[2:]  # skip "Ch" (minimum normal prefix)
        # Skip garbled non-digit chars (the garbled "apter" / "ter" portion)
        after = after.lstrip('\x03')
        while after and ord(after[0]) < 0x0372:
            after = after[1:]
        after = after.lstrip('\x03')
        digits = decode_garbled_digits(after)
        if digits:
            return int(digits), end_idx

    # Strategy 3: Check subsequent spans for normal ASCII digits
    for j in range(idx + 1, min(idx + 6, len(page_items))):
        if page_items[j][0] != bi:
            break
        next_text = page_items[j][3]["text"].strip()
        m = re.match(r'(\d+)', next_text)
        if m:
            return int(m.group(1)), end_idx

    return None, end_idx


def parse_pdf(pdf_path):
    # Fix Calibri fonts without ToUnicode (needed for Greek LXX text)
    fixed_path = fix_calibri_tounicode(pdf_path)
    doc = pymupdf.open(fixed_path)

    book_code_hebrew = ""
    current_chapter = 0
    current_verse = 0
    source_code = os.path.basename(pdf_path)

    # Extract book name from filename (reliable for all PDFs)
    m_fname = re.search(r'Parallel_DSS_-_(.+?)\.pdf', source_code, re.IGNORECASE)
    book_code_english = m_fname.group(1).replace('_', ' ') if m_fname else ""
    book_code = book_code_english

    verses = []
    scroll_codes = set()

    for page_num in range(len(doc)):
        page = doc[page_num]
        blocks = page.get_text("dict")["blocks"]

        page_items = []
        for bi, block in enumerate(blocks):
            if "lines" not in block:
                continue
            for li, line in enumerate(block["lines"]):
                for si, span in enumerate(line["spans"]):
                    page_items.append((bi, li, si, span, block, line))

        # Pre-scan page 0 for Hebrew book name before processing any verses
        if page_num == 0 and not book_code_hebrew:
            for item in page_items:
                span = item[3]
                if "TimesNewRoman" in span["font"] and "Bold" in span["font"] and span["size"] >= 13:
                    htxt = span["text"].strip().replace('\x03', ' ').strip()
                    if htxt:
                        book_code_hebrew = fix_hebrew(htxt)
                        book_code = f"{book_code_english} / {book_code_hebrew}" if book_code_english else book_code_hebrew
                        break

        idx = 0
        while idx < len(page_items):
            bi, li, si, span, block, line = page_items[idx]
            text = span["text"].strip()
            rgb = color_tuple(span["color"])
            font = span["font"]
            size = span["size"]

            if not text:
                idx += 1
                continue

            # Title detection on page 0, block 0: combine all spans to check
            if page_num == 0 and bi == 0 and si == 0 and "Cambria" in font and size >= 13.5:
                # Combine all text in this block to detect title
                title_text = ''
                title_end = idx
                hebrew_title_span = None
                while title_end < len(page_items) and page_items[title_end][0] == bi:
                    tspan = page_items[title_end][3]
                    if "TimesNewRoman" in tspan["font"] and "Bold" in tspan["font"]:
                        hebrew_title_span = tspan
                    title_text += tspan["text"]
                    title_end += 1
                if "Dead Sea Scrolls" in title_text or "Parallel" in title_text:
                    m = re.search(r'-\s*(.+?)\s*/\s*', title_text)
                    if m:
                        book_code_english = m.group(1).strip()
                    if hebrew_title_span:
                        htxt = fix_hebrew(hebrew_title_span["text"].strip())
                        if htxt:
                            book_code_hebrew = htxt
                    # Always update book_code with best available info
                    book_code = f"{book_code_english} / {book_code_hebrew}" if book_code_hebrew else book_code_english
                    idx = title_end
                    continue

            # Chapter/Psalm header detection
            chapter_num, chapter_end = extract_chapter_number(page_items, idx)
            if chapter_end is not None:
                if chapter_num is not None:
                    current_chapter = chapter_num
                else:
                    current_chapter += 1
                current_verse = 0
                idx = chapter_end
                continue

            # Verse number: blue Cambria-Bold size~11, standalone number
            is_verse_num = (
                "Cambria" in font
                and abs(size - 11.0) < 1.0
                and rgb[0] < 100 and rgb[2] > 100  # blue-ish
                and re.match(r'^\d+$', text)
            )
            if is_verse_num:
                current_verse = int(text)
                idx += 1
                continue

            # Scroll code: Calibri size~14 (black) possibly followed by superscript
            is_scroll = (
                "Calibri" in font
                and abs(size - 14.0) < 2.0
                and not re.match(r'^\d+$', text)
            )
            if is_scroll:
                scroll_code = text
                # Check for superscript continuation in same block
                j = idx + 1
                sup_parts = []
                while j < len(page_items):
                    nbi, nli, nsi, nspan, nblock, nline = page_items[j]
                    if nbi != bi:
                        break
                    ntext = nspan["text"].strip()
                    nfont = nspan["font"]
                    nsize = nspan["size"]

                    if "Calibri" in nfont and nsize < 12 and nli == li:
                        sup_parts.append(ntext)
                        j += 1
                        continue
                    if "TimesNewRoman" in nfont or "Evyoni" in nfont:
                        break
                    # Check for Greek text: Calibri ~14pt on a DIFFERENT line from scroll code
                    if "Calibri" in nfont and abs(nsize - 14.0) < 2.0 and nli != li:
                        break
                    j += 1
                if sup_parts:
                    scroll_code += '<sup>' + ''.join(sup_parts) + '</sup>'

                # Collect verse text spans from same block (Hebrew or Greek)
                hebrew_spans = []
                prev_li = None
                is_greek_block = False
                while j < len(page_items):
                    nbi, nli, nsi, nspan, nblock, nline = page_items[j]
                    if nbi != bi:
                        break
                    ntext = nspan["text"]
                    nfont = nspan["font"]
                    nrgb = color_tuple(nspan["color"])

                    is_hebrew_font = "TimesNewRoman" in nfont or "Evyoni" in nfont
                    # Greek text: Calibri/Calibri-Bold ~14pt on lines after scroll code
                    is_greek_font = "Calibri" in nfont and abs(nspan["size"] - 14.0) < 2.0 and nli != page_items[idx][1]
                    if is_hebrew_font or is_greek_font:
                        cname = color_name(nrgb)
                        is_paleo = "Evyoni" in nfont
                        if is_greek_font:
                            is_greek_block = True
                        if cname and ntext:
                            if is_hebrew_font:
                                # Fix garbled Hebrew codepoints
                                fixed = fix_hebrew(ntext)
                                # Swap brackets: PDF stores visual-order RTL brackets,
                                # but HTML RTL auto-mirrors them, causing double-flip
                                fixed = fixed.replace('[', '\x01').replace(']', '[').replace('\x01', ']')
                            else:
                                # Greek text: fix IPA→Greek encoding, no bracket swap (LTR)
                                fixed = fix_ipa_to_greek(ntext)
                            # Insert space when line changes and no explicit space
                            if (prev_li is not None and nli != prev_li
                                    and hebrew_spans and not fixed.isspace()
                                    and not hebrew_spans[-1][1].isspace()
                                    and not hebrew_spans[-1][1].rstrip().endswith('[')
                                    and not fixed.lstrip().startswith(']')):
                                hebrew_spans.append(("black", " ", False))
                            hebrew_spans.append((cname, fixed, is_paleo))
                            prev_li = nli
                    j += 1

                if hebrew_spans:
                    html_parts = []
                    for cname, htxt, is_paleo in hebrew_spans:
                        if htxt.isspace():
                            html_parts.append(htxt)
                        elif is_paleo and cname == "black":
                            html_parts.append(f'<span class="dss-paleo">{htxt}</span>')
                        elif is_paleo:
                            html_parts.append(f'<span class="dss-paleo dss-{cname}">{htxt}</span>')
                        elif cname == "black":
                            html_parts.append(htxt)
                        else:
                            html_parts.append(f'<span class="dss-{cname}">{htxt}</span>')
                    hebrew_html = "".join(html_parts).replace('\x03', ' ')

                    if current_verse > 0:
                        scroll_codes.add(scroll_code)
                        verses.append((
                            scroll_code, book_code, book_code_english, book_code_hebrew,
                            current_chapter, current_verse, hebrew_html, source_code
                        ))

                idx = j
                continue

            idx += 1

        # After page 0: extract Hebrew name from garbled title if not found
        if page_num == 0 and not book_code_hebrew:
            for item in page_items:
                span = item[3]
                if "TimesNewRoman" in span["font"] and "Bold" in span["font"] and span["size"] >= 13:
                    htxt = span["text"].strip().replace('\x03', ' ').strip()
                    if htxt:
                        book_code_hebrew = fix_hebrew(htxt)
                        break
            if book_code_hebrew:
                book_code = f"{book_code_english} / {book_code_hebrew}"

    doc.close()
    # Clean up temp file if we created one
    if fixed_path != pdf_path and os.path.exists(fixed_path):
        os.unlink(fixed_path)
    return verses, scroll_codes, book_code, book_code_english, book_code_hebrew


def insert_to_db(verses, scroll_codes):
    conn = psycopg2.connect(host=DB_HOST, port=DB_PORT, dbname=DB_NAME, user=DB_USER, password=DB_PASS)
    cur = conn.cursor()

    scroll_key_map = {}
    for sc in sorted(scroll_codes):
        cur.execute(
            "INSERT INTO dss_scroll (scroll_code) VALUES (%s) ON CONFLICT DO NOTHING RETURNING scroll_key",
            (sc,)
        )
        row = cur.fetchone()
        if row:
            scroll_key_map[sc] = row[0]
        else:
            cur.execute("SELECT scroll_key FROM dss_scroll WHERE scroll_code = %s", (sc,))
            scroll_key_map[sc] = cur.fetchone()[0]

    inserted = 0
    for scroll_code, bcode, bcode_en, bcode_he, chapter, verse, hebrew_html, source in verses:
        cur.execute("""
            INSERT INTO dss_verse (scroll_key, scroll_code, book_code, book_code_input, book_code_english, book_code_hebrew,
                                   chapter_number, verse_number, verse_text_hebrew, source_code)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """, (
            scroll_key_map[scroll_code], scroll_code, bcode_en, bcode, bcode_en, bcode_he,
            chapter, verse, hebrew_html, source
        ))
        inserted += 1

    conn.commit()
    cur.close()
    conn.close()
    return inserted


def truncate_tables():
    conn = psycopg2.connect(host=DB_HOST, port=DB_PORT, dbname=DB_NAME, user=DB_USER, password=DB_PASS)
    cur = conn.cursor()
    cur.execute("TRUNCATE dss_verse RESTART IDENTITY CASCADE")
    cur.execute("TRUNCATE dss_scroll RESTART IDENTITY CASCADE")
    conn.commit()
    cur.close()
    conn.close()


if __name__ == "__main__":
    import glob
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

    target = sys.argv[1] if len(sys.argv) > 1 else PDF_PATH
    if os.path.isdir(target):
        pdf_files = sorted(glob.glob(os.path.join(target, "*.pdf")))
    else:
        pdf_files = [target]

    print(f"Found {len(pdf_files)} PDF file(s)")
    print("Truncating dss_scroll and dss_verse tables...")
    truncate_tables()

    grand_total = 0
    all_scrolls = set()

    for pdf_path in pdf_files:
        print(f"\n{'='*60}")
        print(f"Parsing: {os.path.basename(pdf_path)}")
        verses, scroll_codes, book_code, book_code_english, book_code_hebrew = parse_pdf(pdf_path)

        print(f"  Book: {book_code_english} / {book_code_hebrew}")
        print(f"  Scrolls: {len(scroll_codes)}, Verses: {len(verses)}")
        chapters = sorted(set(v[4] for v in verses))
        print(f"  Chapters: {len(chapters)} ({min(chapters) if chapters else 0}-{max(chapters) if chapters else 0})")

        if verses:
            inserted = insert_to_db(verses, scroll_codes)
            print(f"  Inserted: {inserted} records")
            grand_total += inserted
            all_scrolls.update(scroll_codes)
        else:
            print(f"  WARNING: No verses parsed!")

    print(f"\n{'='*60}")
    print(f"TOTAL: {grand_total} verse records, {len(all_scrolls)} unique scrolls across {len(pdf_files)} books")

    # Map yah_scroll_key for Torah-order sorting
    print("\nMapping yah_scroll_key...")
    conn = psycopg2.connect(host=DB_HOST, port=DB_PORT, dbname=DB_NAME, user=DB_USER, password=DB_PASS)
    cur = conn.cursor()
    BOOK_SCROLL_MAP = {
        'Genesis': 1, 'Exodus': 2, 'Leviticus': 3, 'Numbers': 4, 'Deuteronomy': 5,
        'Joshua': 6, 'Judges': 7, '1Samuel': 8, '2 Samuel': 9, '1Kings': 10,
        'Isaiah': 12, 'Jeremiah': 13, 'Ezekiel': 14,
        'Hosea': 15, 'Joel': 16, 'Amos': 17, 'Obadiah': 18, 'Jonah': 19,
        'Micah': 20, 'Nahum': 21, 'Habakkuk': 22, 'Zephaniah': 23, 'Haggai': 24,
        'Zechariah': 25, 'Malachi': 26,
        'Psalms': 27, 'Daniel': 28, 'Proverbs': 29,
        'Job': 33, 'Ruth': 34,
    }
    for book, key in BOOK_SCROLL_MAP.items():
        cur.execute("UPDATE dss_verse SET yah_scroll_key = %s WHERE book_code = %s", (key, book))
    conn.commit()
    cur.close()
    conn.close()
    print(f"  Mapped {len(BOOK_SCROLL_MAP)} books to yah_scroll_key")

    # Populate verse_text_yt (Yada Towrah font mapping)
    print("\nPopulating verse_text_yt...")
    conn = psycopg2.connect(host=DB_HOST, port=DB_PORT, dbname=DB_NAME, user=DB_USER, password=DB_PASS)
    cur = conn.cursor()
    cur.execute("SELECT letter_hebrew, letter_yt FROM yy_letter WHERE letter_hebrew IS NOT NULL AND letter_hebrew <> ''")
    letter_map = dict(cur.fetchall())
    cur.execute("SELECT verse_key, verse_text_hebrew FROM dss_verse WHERE verse_text_hebrew IS NOT NULL AND verse_text_hebrew <> ''")
    rows = cur.fetchall()
    tag_pattern = re.compile(r'(<[^>]+>)')
    yt_count = 0
    greek_re = re.compile(r'[\u0370-\u03FF]')
    for verse_key, hebrew_text in rows:
        # Skip Greek LXX text — no YT font mapping applies
        plain = tag_pattern.sub('', hebrew_text)
        if greek_re.search(plain):
            continue
        parts = tag_pattern.split(hebrew_text)
        yt_parts = []
        for part in parts:
            if part.startswith('<'):
                yt_parts.append(part)
            else:
                yt_parts.append(''.join(letter_map.get(ch, ch) for ch in part))
        cur.execute("UPDATE dss_verse SET verse_text_yt = %s WHERE verse_key = %s", (''.join(yt_parts), verse_key))
        yt_count += 1
    conn.commit()
    cur.close()
    conn.close()
    print(f"  Updated {yt_count} verses with verse_text_yt")
