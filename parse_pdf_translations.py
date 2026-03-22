#!/usr/bin/env python3
"""
PDF-based Translation Extraction Script

Parses PDF files (generated from Word documents) to extract formatted translation
text delimited by bold Unicode quotation marks (U+201C and U+201D) and stores
them in PostgreSQL.

Replaces parse_word_translations.py by reading PDFs directly via pymupdf,
eliminating the need for python-docx and Word COM automation.

Usage:
    python parse_pdf_translations.py
    python parse_pdf_translations.py --directory "C:\\path\\to\\PDFs"
    python parse_pdf_translations.py --dry-run --verbose
"""

import os
import re
import sys
import argparse
import logging
from pathlib import Path
from typing import List, Dict, Optional, Tuple
from enum import Enum

try:
    import fitz  # pymupdf
except ImportError:
    print("ERROR: pymupdf not installed. Run: pip install pymupdf")
    sys.exit(1)

try:
    import psycopg2
    from psycopg2 import sql
except ImportError:
    print("ERROR: psycopg2 not installed. Run: pip install psycopg2-binary")
    sys.exit(1)

try:
    from dotenv import load_dotenv
except ImportError:
    print("ERROR: python-dotenv not installed. Run: pip install python-dotenv")
    sys.exit(1)

# Constants
LEFT_QUOTE = "\u201C"  # "
RIGHT_QUOTE = "\u201D"  # "
DEFAULT_DIRECTORY = r"C:\Users\Joe\Work\dev\yada\docs\PDF"


class ExtractionState(Enum):
    SEARCHING = "searching"
    EXTRACTING = "extracting"
    FOUND_QUOTE = "found_quote"


# ── Utility functions (shared with parse_word_translations.py) ────────

def replace_unicode_chars(text: str) -> str:
    """Replace private use area Unicode characters with their intended letters."""
    text = text.replace('\uf065', 'e')
    text = text.replace('\uf066', 'f')
    text = text.replace('\uf069', 'i')
    return text


def consolidate_html(html: str) -> str:
    """Consolidate adjacent identical HTML tags."""
    prev = None
    while prev != html:
        prev = html
        html = re.sub(
            r'<span class="([^"]+)">([^<]*)</span><span class="\1">',
            r'<span class="\1">\2',
            html
        )
    for tag in ('b', 'i', 'u'):
        html = html.replace(f'</{tag}><{tag}>', '')
    return html


def extract_cite(text_after_quote: str) -> Optional[str]:
    """Extract citation from text following the closing quote."""
    text_after_quote = replace_unicode_chars(text_after_quote)
    match = re.match(r'^[\s\u00a0]*\(([^)]+)\)', text_after_quote)
    if match:
        return match.group(1).strip()
    return None


def parse_cite(cite_text: Optional[str]) -> tuple:
    """Split a citation into name, chapter, verse, verse_end, and note."""
    if not cite_text:
        return (None, None, None, None, None)
    match = re.match(r'^(.+?)\s+(\d+):(\d+)(?:-(\d+))?\s*(.*)$', cite_text)
    if match:
        name = match.group(1).strip()
        chapter = int(match.group(2))
        verse = int(match.group(3))
        verse_end = int(match.group(4)) if match.group(4) else None
        note_raw = match.group(5).strip() if match.group(5) else None
        note = None
        if note_raw:
            note = re.sub(r'^[^a-zA-Z]+|[^a-zA-Z]+$', '', note_raw)
            if not note:
                note = None
        return (name, chapter, verse, verse_end, note)
    match = re.match(r'^(\d+):(\d+)(?:-(\d+))?\s*(.*)$', cite_text)
    if match:
        chapter = int(match.group(1))
        verse = int(match.group(2))
        verse_end = int(match.group(3)) if match.group(3) else None
        note_raw = match.group(4).strip() if match.group(4) else None
        note = None
        if note_raw:
            note = re.sub(r'^[^a-zA-Z]+|[^a-zA-Z]+$', '', note_raw)
            if not note:
                note = None
        return (None, chapter, verse, verse_end, note)
    return (cite_text, None, None, None, None)


def split_cite_name(cite_name: Optional[str]) -> tuple:
    """Extract Hebrew and common name from a cite name containing slashes."""
    if not cite_name:
        return (None, None)
    if '/' not in cite_name:
        return (cite_name.strip(), None)
    parts = cite_name.split('/')
    return (parts[0].strip(), parts[-1].strip())


# ── PDF helpers ─────────────────────────────────────────────────────

def is_bold_span(span: dict) -> bool:
    """Check if a pymupdf span is bold (via flags or font name)."""
    if span['flags'] & (1 << 4):
        return True
    font = span.get('font', '')
    return 'Bold' in font or 'bold' in font


def is_italic_span(span: dict) -> bool:
    """Check if a pymupdf span is italic."""
    if span['flags'] & (1 << 1):
        return True
    font = span.get('font', '')
    return 'Italic' in font or 'italic' in font


def is_underline_span(span: dict) -> bool:
    """Check if a pymupdf span has underline (not reliably available in PDF)."""
    # PDF doesn't store underline in font flags; it's drawn as a line
    # We can't reliably detect this from text extraction alone
    return False


def get_footer_page_number(page) -> Optional[int]:
    """Extract the footer page number from a PDF page."""
    h = page.rect.height
    blocks = page.get_text('dict')['blocks']
    for block in blocks:
        if block['type'] != 0:
            continue
        # Footer area: bottom 50 points of page
        if block['bbox'][3] > h - 50 and block['bbox'][1] > h - 60:
            text = ''
            for line in block['lines']:
                for span in line['spans']:
                    text += span['text']
            text = text.strip()
            if text.isdigit():
                return int(text)
    return None


def get_content_page_range(pdf) -> Tuple[int, int, Dict[int, int]]:
    """
    Determine content page range from footer page numbers.

    Returns:
        (content_start_pdf_page, last_content_pdf_page, pdf_page_to_footer_map)
        content_start_pdf_page: 0-based PDF page index where footer page 1 starts
        last_content_pdf_page: 0-based PDF page index of the last content page (before contact info)
        pdf_page_to_footer_map: dict mapping PDF page index -> footer page number
    """
    page_map = {}
    content_start = None
    last_content = 0

    for pn in range(len(pdf)):
        footer = get_footer_page_number(pdf[pn])
        if footer is not None:
            page_map[pn] = footer
            if footer == 1 and content_start is None:
                content_start = pn
            last_content = pn

    if content_start is None:
        content_start = 0

    return content_start, last_content, page_map


def format_span_as_html(span: dict) -> str:
    """Convert a pymupdf span to HTML with formatting."""
    text = replace_unicode_chars(span['text'])
    if not text:
        return ''

    # Escape HTML
    text = text.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')

    # Font detection
    font_name = span.get('font', '')
    # Skip default Times New Roman
    non_default_font = font_name and 'TimesNewRoman' not in font_name.replace('-', '').replace(' ', '')
    if non_default_font:
        # Clean up font name for CSS class
        clean_font = font_name.split('-')[0] if '-' in font_name else font_name
        text = f'<span class="{clean_font}">{text}</span>'

    bold = is_bold_span(span)
    italic = is_italic_span(span)
    underline = is_underline_span(span)

    if underline:
        text = f"<u>{text}</u>"
    if italic:
        text = f"<i>{text}</i>"
    if bold:
        text = f"<b>{text}</b>"

    return text


# ── Span-based extraction (mirrors docx run-by-run extraction) ──────

def get_page_spans(page) -> List[dict]:
    """
    Get all text spans from a page in reading order, excluding header/footer.

    Returns list of span dicts with added 'block_idx' and 'line_idx' keys.
    """
    h = page.rect.height
    blocks = page.get_text('dict')['blocks']
    spans = []
    for bi, block in enumerate(blocks):
        if block['type'] != 0:
            continue
        bbox = block['bbox']
        # Skip header (top 55pt) and footer (bottom 50pt)
        if bbox[1] < 55 or bbox[3] > h - 50:
            continue
        for li, line in enumerate(block['lines']):
            for span in line['spans']:
                s = dict(span)
                s['block_idx'] = bi
                s['line_idx'] = li
                s['is_last_in_block'] = (li == len(block['lines']) - 1)
                spans.append(s)
    return spans


def extract_translations_from_pdf(pdf_path: Path, detect_chapters=False) -> List[Dict]:
    """
    Extract all translations from a PDF file.

    Walks through all spans looking for bold curly-quote delimited translations
    and bold paragraphs ending with citations.
    """
    translations = []
    chapter_boundaries = []

    try:
        pdf = fitz.open(str(pdf_path))
        book_name = pdf_path.stem
        logging.info(f"Processing PDF: {book_name} ({len(pdf)} pages)")

        content_start, last_content, page_footer_map = get_content_page_range(pdf)
        logging.info(f"  Content pages: PDF {content_start+1}-{last_content+1}, footer 1-{page_footer_map.get(last_content, '?')}")

        state = ExtractionState.SEARCHING
        accumulated_html = []
        start_page = None
        cite_search_text = ""

        # Bold-cite path
        bold_cite_html = []
        bold_cite_start_page = None

        # Track current block to detect paragraph boundaries
        prev_block_idx = None
        prev_page_num = None

        for pdf_page_idx in range(content_start, last_content + 1):
            page = pdf[pdf_page_idx]
            footer_page = page_footer_map.get(pdf_page_idx)

            spans = get_page_spans(page)
            if not spans:
                continue

            # Chapter detection: look for standalone digit blocks at top of page
            if detect_chapters:
                h = page.rect.height
                for block in page.get_text('dict')['blocks']:
                    if block['type'] != 0:
                        continue
                    bbox = block['bbox']
                    if bbox[1] > 55 and bbox[3] < 120:  # near top of content area
                        block_text = ''
                        for line in block['lines']:
                            for span in line['spans']:
                                block_text += span['text']
                        block_text = block_text.strip()
                        if block_text.isdigit() and 1 <= int(block_text) <= 50:
                            chapter_boundaries.append((pdf_page_idx, int(block_text)))
                            logging.debug(f"  Chapter {block_text} at PDF page {pdf_page_idx+1}")

            # Process spans on this page
            # Group spans by block to reconstruct paragraphs
            block_groups = {}
            for span in spans:
                bi = span['block_idx']
                if bi not in block_groups:
                    block_groups[bi] = []
                block_groups[bi].append(span)

            for bi in sorted(block_groups.keys()):
                block_spans = block_groups[bi]
                block_text = ''.join(replace_unicode_chars(s['text']) for s in block_spans).strip()
                block_extracted = False  # Track if this block was already handled

                # Each block is treated as a "paragraph" for extraction purposes
                para_html_start = len(accumulated_html) if state == ExtractionState.EXTRACTING else None
                para_extract_char_start = 0 if state == ExtractionState.EXTRACTING else None

                for si, span in enumerate(block_spans):
                    text = span['text']
                    bold = is_bold_span(span)

                    if state == ExtractionState.FOUND_QUOTE:
                        cite_search_text += text

                    if state == ExtractionState.SEARCHING:
                        if LEFT_QUOTE in text and bold:
                            state = ExtractionState.EXTRACTING
                            para_html_start = len(accumulated_html)
                            # Calculate char offset
                            char_offset = sum(len(block_spans[j]['text']) for j in range(si))
                            para_extract_char_start = char_offset + text.index(LEFT_QUOTE) + 1
                            start_page = footer_page

                            bold_cite_html = []
                            bold_cite_start_page = None

                            split_idx = text.index(LEFT_QUOTE)
                            text_after = text[split_idx + 1:]

                            if RIGHT_QUOTE in text_after and bold:
                                end_idx = text_after.index(RIGHT_QUOTE)
                                translation_text = text_after[:end_idx]
                                cite_search_text = text_after[end_idx + 1:]

                                formatted = replace_unicode_chars(translation_text)
                                formatted = formatted.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')

                                font_name = span.get('font', '')
                                non_default = font_name and 'TimesNewRoman' not in font_name.replace('-', '').replace(' ', '')
                                if non_default:
                                    clean_font = font_name.split('-')[0] if '-' in font_name else font_name
                                    formatted = f'<span class="{clean_font}">{formatted}</span>'
                                if is_italic_span(span):
                                    formatted = f"<i>{formatted}</i>"
                                if bold:
                                    formatted = f"<b>{formatted}</b>"

                                accumulated_html.append(formatted)
                                state = ExtractionState.FOUND_QUOTE
                            else:
                                formatted = replace_unicode_chars(text_after)
                                formatted = formatted.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')

                                font_name = span.get('font', '')
                                non_default = font_name and 'TimesNewRoman' not in font_name.replace('-', '').replace(' ', '')
                                if non_default:
                                    clean_font = font_name.split('-')[0] if '-' in font_name else font_name
                                    formatted = f'<span class="{clean_font}">{formatted}</span>'
                                if is_italic_span(span):
                                    formatted = f"<i>{formatted}</i>"
                                if bold:
                                    formatted = f"<b>{formatted}</b>"

                                accumulated_html.append(formatted)

                    elif state == ExtractionState.EXTRACTING:
                        if RIGHT_QUOTE in text and bold:
                            split_idx = text.index(RIGHT_QUOTE)
                            text_before = text[:split_idx]
                            cite_search_text = text[split_idx + 1:]

                            if text_before:
                                formatted = replace_unicode_chars(text_before)
                                formatted = formatted.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')

                                font_name = span.get('font', '')
                                non_default = font_name and 'TimesNewRoman' not in font_name.replace('-', '').replace(' ', '')
                                if non_default:
                                    clean_font = font_name.split('-')[0] if '-' in font_name else font_name
                                    formatted = f'<span class="{clean_font}">{formatted}</span>'
                                if is_italic_span(span):
                                    formatted = f"<i>{formatted}</i>"
                                if bold:
                                    formatted = f"<b>{formatted}</b>"

                                accumulated_html.append(formatted)

                            state = ExtractionState.FOUND_QUOTE
                        else:
                            accumulated_html.append(format_span_as_html(span))

                # End of block: check for cite-terminated extraction
                if state == ExtractionState.EXTRACTING:
                    stripped_bt = block_text.rstrip()
                    cite_found = False
                    if stripped_bt.endswith(')'):
                        depth = 0
                        paren_start = None
                        for i in range(len(stripped_bt) - 1, -1, -1):
                            if stripped_bt[i] == ')':
                                depth += 1
                            elif stripped_bt[i] == '(':
                                depth -= 1
                                if depth == 0:
                                    paren_start = i
                                    break
                        if paren_start is not None:
                            cite_content = stripped_bt[paren_start + 1:-1].strip()
                            if re.search(r'\d+:\d+', cite_content):
                                cite_found = True
                                raw_cite = cite_content
                                cite_start_pos = paren_start

                    if cite_found:
                        extract_from = para_extract_char_start if para_extract_char_start is not None else 0
                        if para_html_start is not None:
                            accumulated_html = accumulated_html[:para_html_start]

                        # Re-walk block spans for non-cite portion
                        char_pos = 0
                        for s in block_spans:
                            s_text = replace_unicode_chars(s['text'])
                            s_end = char_pos + len(s_text)
                            if s_end <= extract_from or char_pos >= cite_start_pos:
                                char_pos = s_end
                                continue
                            eff_start = max(0, extract_from - char_pos)
                            eff_end = min(len(s_text), cite_start_pos - char_pos)
                            if eff_end > eff_start:
                                keep = s_text[eff_start:eff_end]
                                if keep:
                                    fmt = keep.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
                                    font_name = s.get('font', '')
                                    non_default = font_name and 'TimesNewRoman' not in font_name.replace('-', '').replace(' ', '')
                                    if non_default:
                                        clean_font = font_name.split('-')[0] if '-' in font_name else font_name
                                        fmt = f'<span class="{clean_font}">{fmt}</span>'
                                    if is_italic_span(s):
                                        fmt = f"<i>{fmt}</i>"
                                    if is_bold_span(s):
                                        fmt = f"<b>{fmt}</b>"
                                    accumulated_html.append(fmt)
                            char_pos = s_end

                        cite_name, cite_chapter, cite_verse, cite_verse_end, cite_note = parse_cite(raw_cite)
                        cite_hebrew, cite_common = split_cite_name(cite_name)
                        full_text = consolidate_html("".join(accumulated_html))

                        if cite_name or cite_chapter:
                            translation = {
                                "book": book_name,
                                "page": start_page,
                                "text_word": full_text,
                                "cite": cite_name,
                                "cite_hebrew": cite_hebrew,
                                "cite_common": cite_common,
                                "cite_chapter": cite_chapter,
                                "cite_verse": cite_verse,
                                "cite_verse_end": cite_verse_end,
                                "cite_note": cite_note,
                                "_pdf_page": pdf_page_idx,
                            }
                            translations.append(translation)
                            logging.info(f"  Translation #{len(translations)} cite-terminated (page {start_page})")
                            block_extracted = True

                        state = ExtractionState.SEARCHING
                        accumulated_html = []
                        cite_search_text = ""
                        start_page = None
                    else:
                        # Multi-block translation: add line break between blocks
                        accumulated_html.append(" ")

                # Process found quote at end of block
                if state == ExtractionState.FOUND_QUOTE:
                    raw_cite = extract_cite(cite_search_text)

                    if raw_cite:
                        cite_name, cite_chapter, cite_verse, cite_verse_end, cite_note = parse_cite(raw_cite)
                        cite_hebrew, cite_common = split_cite_name(cite_name)
                        full_text = consolidate_html("".join(accumulated_html))

                        translation = {
                            "book": book_name,
                            "page": start_page,
                            "text_word": full_text,
                            "cite": cite_name,
                            "cite_hebrew": cite_hebrew,
                            "cite_common": cite_common,
                            "cite_chapter": cite_chapter,
                            "cite_verse": cite_verse,
                            "cite_verse_end": cite_verse_end,
                            "cite_note": cite_note,
                            "_pdf_page": pdf_page_idx,
                        }
                        translations.append(translation)
                        logging.info(f"  Translation #{len(translations)} quote-delimited (page {start_page})")
                        block_extracted = True

                    state = ExtractionState.SEARCHING
                    accumulated_html = []
                    cite_search_text = ""
                    start_page = None

                # Bold-cite detection (no curly quotes)
                if state == ExtractionState.SEARCHING and not block_extracted and LEFT_QUOTE not in block_text:
                    stripped_bt = block_text.rstrip()
                    # Check if FIRST non-empty span is bold (matches docx behavior)
                    first_bold = False
                    for s in block_spans:
                        if s['text'].strip():
                            first_bold = is_bold_span(s)
                            break

                    if first_bold and stripped_bt:
                        cite_found_bc = False
                        raw_cite_bc = None
                        cite_start_pos_bc = None

                        if stripped_bt.endswith(')'):
                            depth = 0
                            paren_start = None
                            for i in range(len(stripped_bt) - 1, -1, -1):
                                if stripped_bt[i] == ')':
                                    depth += 1
                                elif stripped_bt[i] == '(':
                                    depth -= 1
                                    if depth == 0:
                                        paren_start = i
                                        break
                            if paren_start is not None:
                                cite_content = stripped_bt[paren_start + 1:-1].strip()
                                if re.search(r'\d+:\d+', cite_content):
                                    cite_found_bc = True
                                    raw_cite_bc = cite_content
                                    cite_start_pos_bc = paren_start

                        if cite_found_bc:
                            current_html = []
                            char_pos = 0
                            for s in block_spans:
                                s_text = replace_unicode_chars(s['text'])
                                s_end = char_pos + len(s_text)
                                if char_pos >= cite_start_pos_bc:
                                    break
                                eff_end = min(len(s_text), cite_start_pos_bc - char_pos)
                                keep = s_text[:eff_end]
                                if keep:
                                    current_html.append(format_span_as_html(
                                        {**s, 'text': keep}
                                    ))
                                char_pos = s_end

                            if bold_cite_html:
                                bold_cite_html.append('<br>')
                            bold_cite_html.extend(current_html)

                            cite_name, cite_chapter, cite_verse, cite_verse_end, cite_note = parse_cite(raw_cite_bc)
                            cite_hebrew, cite_common = split_cite_name(cite_name)
                            full_text = consolidate_html("".join(bold_cite_html))

                            if (cite_name or cite_chapter) and full_text.strip():
                                page_num = bold_cite_start_page if bold_cite_start_page is not None else footer_page
                                translation = {
                                    "book": book_name,
                                    "page": page_num,
                                    "text_word": full_text,
                                    "cite": cite_name,
                                    "cite_hebrew": cite_hebrew,
                                    "cite_common": cite_common,
                                    "cite_chapter": cite_chapter,
                                    "cite_verse": cite_verse,
                                    "cite_verse_end": cite_verse_end,
                                    "cite_note": cite_note,
                                    "_pdf_page": pdf_page_idx,
                                }
                                translations.append(translation)
                                logging.info(f"  Translation #{len(translations)} bold-cite (page {page_num})")

                            bold_cite_html = []
                            bold_cite_start_page = None
                        else:
                            # Bold block without cite - accumulate
                            if not bold_cite_html:
                                bold_cite_start_page = footer_page
                            else:
                                bold_cite_html.append('<br>')
                            for s in block_spans:
                                bold_cite_html.append(format_span_as_html(s))
                    elif not first_bold:
                        bold_cite_html = []
                        bold_cite_start_page = None
                elif state != ExtractionState.SEARCHING:
                    bold_cite_html = []
                    bold_cite_start_page = None

        # Handle document end
        if state == ExtractionState.FOUND_QUOTE and accumulated_html:
            raw_cite = extract_cite(cite_search_text)
            if raw_cite:
                cite_name, cite_chapter, cite_verse, cite_verse_end, cite_note = parse_cite(raw_cite)
                cite_hebrew, cite_common = split_cite_name(cite_name)
                full_text = consolidate_html("".join(accumulated_html))
                translations.append({
                    "book": book_name,
                    "page": start_page,
                    "text_word": full_text,
                    "cite": cite_name,
                    "cite_hebrew": cite_hebrew,
                    "cite_common": cite_common,
                    "cite_chapter": cite_chapter,
                    "cite_verse": cite_verse,
                    "cite_verse_end": cite_verse_end,
                    "cite_note": cite_note,
                })

        if state == ExtractionState.EXTRACTING:
            logging.warning(f"  Unclosed translation at end of {book_name}")

        if detect_chapters:
            chapter_boundaries.sort(key=lambda x: x[0])
            for t in translations:
                pdf_pg = t.get('_pdf_page')
                if pdf_pg is not None:
                    ch_num = None
                    for bound_pg, c_num in chapter_boundaries:
                        if pdf_pg >= bound_pg:
                            ch_num = c_num
                        else:
                            break
                    t['_chapter_num'] = ch_num
            translations.append({'_chapters': chapter_boundaries})

        pdf.close()
        return translations

    except Exception as e:
        logging.error(f"Error processing PDF {pdf_path}: {e}")
        import traceback
        traceback.print_exc()
        return []


# ── Database ────────────────────────────────────────────────────────

def setup_logging(verbose: bool = False) -> None:
    logging.basicConfig(
        level=logging.DEBUG if verbose else logging.INFO,
        format='%(asctime)s - %(levelname)s - %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S',
    )


def get_db_connection():
    load_dotenv()
    host = os.getenv('POSTGRES_HOST', 'localhost')
    port = os.getenv('POSTGRES_PORT', '5432')
    user = os.getenv('POSTGRES_USER', 'postgres')
    password = os.getenv('POSTGRES_PASSWORD')
    db = os.getenv('POSTGRES_DB', 'yada')
    if not password:
        print("ERROR: POSTGRES_PASSWORD not set in .env")
        sys.exit(1)
    return psycopg2.connect(host=host, port=port, user=user, password=password, dbname=db)


def ensure_translation_table(conn):
    """Create the translation staging table if it doesn't exist."""
    cursor = conn.cursor()
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS translation (
            translation_id SERIAL PRIMARY KEY,
            translation_book VARCHAR(255) NOT NULL,
            translation_page INTEGER,
            translation_text_word TEXT NOT NULL,
            translation_cite VARCHAR(500),
            translation_cite_hebrew VARCHAR(255),
            translation_cite_common VARCHAR(255),
            translation_cite_chapter INTEGER,
            translation_cite_verse INTEGER,
            translation_cite_verse_end SMALLINT,
            translation_cite_note VARCHAR(500),
            translation_cite_book_id INTEGER,
            translation_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            yy_volume_id INTEGER
        )
    """)
    cursor.execute("CREATE INDEX IF NOT EXISTS idx_book ON translation(translation_book)")
    cursor.execute("CREATE INDEX IF NOT EXISTS idx_page ON translation(translation_page)")
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS cite (
            id SERIAL PRIMARY KEY,
            label VARCHAR(500) NOT NULL UNIQUE,
            sort SMALLINT NOT NULL DEFAULT 0
        )
    """)
    conn.commit()
    cursor.close()


def save_translation(conn, translation_data: Dict) -> bool:
    try:
        cursor = conn.cursor()
        cursor.execute("""
            INSERT INTO translation (translation_book, translation_page, translation_text_word,
                                     translation_cite, translation_cite_hebrew, translation_cite_common,
                                     translation_cite_chapter, translation_cite_verse,
                                     translation_cite_verse_end, translation_cite_note)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """, (
            translation_data['book'],
            translation_data['page'],
            translation_data['text_word'],
            translation_data['cite'],
            translation_data.get('cite_hebrew'),
            translation_data.get('cite_common'),
            translation_data.get('cite_chapter'),
            translation_data.get('cite_verse'),
            translation_data.get('cite_verse_end'),
            translation_data.get('cite_note')
        ))
        conn.commit()
        cursor.close()
        return True
    except psycopg2.Error as e:
        logging.error(f"Database insertion failed: {e}")
        conn.rollback()
        return False


def populate_cite_table(conn) -> int:
    try:
        cursor = conn.cursor()
        cursor.execute("""
            INSERT INTO cite (label)
            SELECT DISTINCT translation_cite FROM translation
            WHERE translation_cite IS NOT NULL
            ON CONFLICT (label) DO NOTHING
        """)
        count = cursor.rowcount
        conn.commit()
        cursor.close()
        return count
    except psycopg2.Error as e:
        logging.error(f"Failed to populate cite table: {e}")
        conn.rollback()
        return 0


def normalize_unicode_text(conn) -> int:
    try:
        cursor = conn.cursor()
        total = 0
        for col in ['translation_text_word', 'translation_cite', 'translation_cite_hebrew', 'translation_cite_common']:
            cursor.execute(f"""
                UPDATE translation
                SET {col} = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                    {col},
                    E'\u2019', ''''),
                    E'\u2018', ''''),
                    E'\u201C', '"'),
                    E'\u201D', '"'),
                    E'\u2013', '-')
                WHERE {col} LIKE '%' || E'\u2019' || '%'
                   OR {col} LIKE '%' || E'\u2018' || '%'
                   OR {col} LIKE '%' || E'\u201C' || '%'
                   OR {col} LIKE '%' || E'\u201D' || '%'
                   OR {col} LIKE '%' || E'\u2013' || '%'
            """)
            total += cursor.rowcount
        cursor.execute("""
            UPDATE translation
            SET translation_text_word = REPLACE(translation_text_word, E'\u2014', '-')
            WHERE translation_text_word LIKE '%' || E'\u2014' || '%'
        """)
        total += cursor.rowcount
        conn.commit()
        cursor.close()
        return total
    except psycopg2.Error as e:
        logging.error(f"Unicode normalization failed: {e}")
        conn.rollback()
        return 0


def update_cite_book_ids(conn) -> int:
    try:
        cursor = conn.cursor()
        cursor.execute("""
            UPDATE translation t
            SET translation_cite_book_id = cbm.cite_book_key
            FROM yy_cite_book_map cbm
            WHERE (t.translation_cite_hebrew = cbm.cite_book_map_hebrew
                   OR REPLACE(REPLACE(t.translation_cite_hebrew, E'\u2019', ''''), E'\u2018', '''') = cbm.cite_book_map_hebrew)
              AND t.translation_cite_book_id IS NULL
        """)
        count = cursor.rowcount
        conn.commit()
        cursor.close()
        return count
    except psycopg2.Error as e:
        logging.error(f"Failed to update cite_book_id: {e}")
        conn.rollback()
        return 0


# ── Main ────────────────────────────────────────────────────────────

def parse_directory(directory_path: Path, conn, dry_run: bool = False) -> Dict[str, int]:
    stats = {
        "files_processed": 0,
        "translations_found": 0,
        "translations_saved": 0,
    }

    pdf_files = [f for f in directory_path.glob("YY*.pdf")
                 if not f.name.startswith("YY-s07")]
    pdf_files.sort()

    if not pdf_files:
        logging.warning(f"No YY PDF files found in {directory_path}")
        return stats

    logging.info(f"Found {len(pdf_files)} PDF(s) to process")

    for pdf_path in pdf_files:
        logging.info(f"\n{'='*60}")
        translations = extract_translations_from_pdf(pdf_path)
        stats["files_processed"] += 1
        stats["translations_found"] += len(translations)

        if dry_run:
            logging.info(f"[DRY RUN] {len(translations)} translation(s) from {pdf_path.name}")
            for i, t in enumerate(translations[:5], 1):
                logging.info(f"  #{i}: cite={t.get('cite')}, page={t.get('page')}, text={t.get('text_word', '')[:80]}...")
        else:
            for t in translations:
                t.pop('_pdf_page', None)
                if save_translation(conn, t):
                    stats["translations_saved"] += 1

    return stats


def main():
    parser = argparse.ArgumentParser(description="Extract translations from PDF files to PostgreSQL")
    parser.add_argument("--directory", type=str, default=DEFAULT_DIRECTORY,
                        help=f"Directory containing PDF files (default: {DEFAULT_DIRECTORY})")
    parser.add_argument("--dry-run", action="store_true", help="Preview without saving to DB")
    parser.add_argument("--verbose", action="store_true", help="Debug logging")
    args = parser.parse_args()

    setup_logging(args.verbose)

    if sys.stdout.encoding != 'utf-8':
        sys.stdout.reconfigure(encoding='utf-8')

    directory = Path(args.directory)
    if not directory.exists():
        logging.error(f"Directory not found: {directory}")
        sys.exit(1)

    if args.dry_run:
        stats = parse_directory(directory, conn=None, dry_run=True)
    else:
        conn = get_db_connection()
        logging.info("Connected to PostgreSQL")

        # Ensure tables exist and clear existing translations
        ensure_translation_table(conn)
        cursor = conn.cursor()
        cursor.execute("DELETE FROM translation")
        conn.commit()
        logging.info("Cleared existing translations")

        stats = parse_directory(directory, conn)

        # Post-processing
        logging.info("\n=== Post-processing ===")
        normalize_unicode_text(conn)
        populate_cite_table(conn)
        update_cite_book_ids(conn)

        conn.close()

    logging.info(f"\n{'='*60}")
    logging.info("SUMMARY")
    logging.info(f"  Files processed:    {stats['files_processed']}")
    logging.info(f"  Translations found: {stats['translations_found']}")
    logging.info(f"  Translations saved: {stats['translations_saved']}")


if __name__ == '__main__':
    main()
