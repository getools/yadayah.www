#!/usr/bin/env python3
"""
PDF-based Paragraph Extraction Script

Parses PDF files to extract ALL content paragraphs with:
  - Full formatting metadata (paragraph_text_raw as JSON)
  - HTML+CSS rendering (paragraph_text_html)
  - Page number (directly from PDF page position)
  - Chapter assignment (via heading/style detection)

Also generates a global CSS stylesheet from all formatting encountered.

Replaces parse_paragraphs.py by reading PDFs directly via pymupdf,
eliminating the need for python-docx, Word COM, and VBScript.

Usage:
    python parse_pdf_paragraphs.py
    python parse_pdf_paragraphs.py --dry-run --verbose
    python parse_pdf_paragraphs.py --single "YY-s01v01-An Intro to God-Dabarym-Words"
"""

import os
import sys
import json
import re
import argparse
import logging
from pathlib import Path
from datetime import datetime
from typing import Dict, List, Optional, Tuple

try:
    import fitz  # pymupdf
except ImportError:
    print("ERROR: pymupdf not installed. Run: pip install pymupdf")
    sys.exit(1)

try:
    import psycopg2
    from psycopg2.extras import execute_values
except ImportError:
    print("ERROR: psycopg2 not installed. Run: pip install psycopg2-binary")
    sys.exit(1)

try:
    from dotenv import load_dotenv
except ImportError:
    print("ERROR: python-dotenv not installed. Run: pip install python-dotenv")
    sys.exit(1)

DEFAULT_DIRECTORY = r"C:\Users\Joe\Work\dev\yada\docs\PDF"


# ── Utility functions ────────────────────────────────────────────────

def replace_unicode_chars(text: str) -> str:
    """Replace private use area Unicode characters."""
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


# ── Database connection ──────────────────────────────────────────────

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


# ── Phase 1: FK Lookups ─────────────────────────────────────────────

def load_volume_mapping(conn):
    """Load filename stem -> (yy_volume_key, yy_series_key) mapping."""
    cur = conn.cursor()
    cur.execute("SELECT yy_volume_key, yy_volume_file, yy_series_key FROM yy_volume WHERE yy_volume_file IS NOT NULL")
    mapping = {}
    for vol_key, filename, series_key in cur.fetchall():
        if filename:
            mapping[filename] = (vol_key, series_key)
            # Also add PDF filename variant (same stem)
            mapping[filename] = (vol_key, series_key)
            norm = filename.replace('\u2019', "'").replace('\u2018', "'")
            if norm != filename:
                mapping[norm] = (vol_key, series_key)
    cur.close()
    return mapping


def load_yy_chapter_mapping(conn):
    """Load (yy_volume_key, chapter_number) -> yy_chapter_key."""
    cur = conn.cursor()
    cur.execute("SELECT yy_chapter_key, yy_volume_key, yy_chapter_number FROM yy_chapter")
    mapping = {}
    for ch_key, vol_key, ch_num in cur.fetchall():
        mapping[(vol_key, ch_num)] = ch_key
    cur.close()
    return mapping


# ── PDF helpers ──────────────────────────────────────────────────────

def is_bold_span(span: dict) -> bool:
    if span['flags'] & (1 << 4):
        return True
    font = span.get('font', '')
    return 'Bold' in font or 'bold' in font


def is_italic_span(span: dict) -> bool:
    if span['flags'] & (1 << 1):
        return True
    font = span.get('font', '')
    return 'Italic' in font or 'italic' in font


def is_superscript_span(span: dict) -> bool:
    return bool(span['flags'] & (1 << 0))


def get_footer_page_number(page) -> Optional[int]:
    """Extract the footer page number from a PDF page."""
    h = page.rect.height
    blocks = page.get_text('dict')['blocks']
    for block in blocks:
        if block['type'] != 0:
            continue
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


# ── Formatting helpers ───────────────────────────────────────────────

def slugify_font(font_name):
    """Convert font name to CSS-safe slug."""
    return re.sub(r'[^a-z0-9-]', '-', font_name.lower()).strip('-')


def pt_to_slug(pt_value):
    """Convert point value to CSS class slug."""
    if pt_value is None:
        return '0'
    if pt_value == int(pt_value):
        return str(int(pt_value))
    return str(pt_value).replace('.', '-')


def clean_font_name(font_name: str) -> str:
    """Extract base font family from PDF font name like TimesNewRomanPS-BoldMT."""
    if not font_name:
        return ''
    # Remove style suffixes
    name = font_name
    for suffix in ['-BoldMT', '-BoldItal', 'PS-ItalicMT', 'PSMT', 'PS-', '-Regular', '-Bold', '-Italic']:
        name = name.replace(suffix, '')
    # Remove trailing MT
    if name.endswith('MT'):
        name = name[:-2]
    return name or font_name


# ── Paragraph extraction from PDF ────────────────────────────────────

def extract_block_paragraphs(block: dict, css_collector: dict) -> Tuple[dict, str]:
    """
    Extract paragraph data from a PDF text block.

    Returns:
        (raw_data_dict, html_string)
    """
    # Build run data from spans across all lines
    runs = []
    for line in block['lines']:
        for span in line['spans']:
            text = replace_unicode_chars(span['text'])
            if not text:
                continue

            font_name = span.get('font', '')
            font_size = round(span.get('size', 0), 1) if span.get('size') else None
            color_int = span.get('color', 0)
            color_hex = f'{color_int:06x}' if color_int else None

            bold = is_bold_span(span)
            italic = is_italic_span(span)
            superscript = is_superscript_span(span)

            run_data = {
                'text': text,
                'bold': bold,
                'italic': italic,
                'underline': False,  # PDF doesn't reliably expose underline
                'superscript': superscript,
                'subscript': False,
                'font': font_name,
                'size': font_size,
                'color': color_hex if color_hex and color_hex != '000000' else None,
            }
            runs.append(run_data)

    raw = {
        'style': None,  # PDF doesn't have paragraph styles
        'alignment': None,
        'indent': {'first_line': None, 'left': None, 'right': None},
        'spacing': {'before': None, 'after': None, 'line': None},
        'runs': runs,
    }

    # Build HTML
    run_htmls = []
    for run in runs:
        text = run['text']
        text = text.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')

        span_classes = []

        font_name = run.get('font', '')
        if font_name:
            font_slug = slugify_font(font_name)
            span_classes.append(f'yy-font-{font_slug}')
            base_font = clean_font_name(font_name)
            css_collector['fonts'].add((font_slug, base_font or font_name))

        size_pt = run.get('size')
        if size_pt is not None:
            span_classes.append(f'yy-size-{pt_to_slug(size_pt)}')
            css_collector['sizes'].add(size_pt)

        color_hex = run.get('color')
        if color_hex:
            span_classes.append(f'yy-color-{color_hex}')
            css_collector['colors'].add(color_hex)

        if span_classes:
            html_frag = f'<span class="{" ".join(span_classes)}">{text}</span>'
        else:
            html_frag = text

        if run.get('subscript'):
            html_frag = f'<sub>{html_frag}</sub>'
        if run.get('superscript'):
            html_frag = f'<sup>{html_frag}</sup>'
        if run.get('underline'):
            html_frag = f'<u>{html_frag}</u>'
        if run.get('italic'):
            html_frag = f'<i>{html_frag}</i>'
        if run.get('bold'):
            html_frag = f'<b>{html_frag}</b>'

        run_htmls.append(html_frag)

    inner_html = consolidate_html(''.join(run_htmls))
    html = f'<p>{inner_html}</p>'

    return raw, html


def extract_paragraphs_from_pdf(pdf_path, css_collector, content_start=0, last_content_page=None, page_map=None):
    """
    Extract paragraphs from a PDF file, filtering by content page range.

    Returns:
        (paragraphs_list, chapter_boundaries)
    """
    pdf = fitz.open(str(pdf_path))
    paragraphs = []
    chapter_boundaries = []
    para_idx = 0

    if last_content_page is None:
        last_content_page = len(pdf) - 1

    for pdf_page_idx in range(content_start, last_content_page + 1):
        page = pdf[pdf_page_idx]
        footer_page = page_map.get(pdf_page_idx) if page_map else None
        h = page.rect.height

        blocks = page.get_text('dict')['blocks']

        for block in blocks:
            if block['type'] != 0:
                continue

            bbox = block['bbox']
            # Skip header (top 55pt) and footer (bottom 50pt)
            if bbox[1] < 55 or bbox[3] > h - 50:
                continue

            # Get block text
            block_text = ''
            for line in block['lines']:
                for span in line['spans']:
                    block_text += span['text']
            block_text = block_text.strip()

            if not block_text:
                continue

            # Detect chapter boundaries: standalone digit blocks near top of page
            if bbox[1] < 120 and block_text.isdigit() and 1 <= int(block_text) <= 50:
                chapter_boundaries.append((para_idx, int(block_text)))
                logging.debug(f"  Chapter {block_text} at paragraph {para_idx}")

            # Extract paragraph data
            raw_data, html = extract_block_paragraphs(block, css_collector)

            paragraphs.append({
                'paragraph_number': para_idx,
                'text_raw': json.dumps(raw_data, ensure_ascii=False),
                'text_html': html,
                'page': footer_page,
                'pdf_page': pdf_page_idx,
            })
            para_idx += 1

    pdf.close()
    return paragraphs, chapter_boundaries


# ── Chapter assignment ───────────────────────────────────────────────

def get_chapter_for_paragraph(para_idx, chapter_boundaries):
    """Return chapter number for a paragraph based on boundary list."""
    current_chapter = None
    for boundary_idx, chapter_num in chapter_boundaries:
        if para_idx >= boundary_idx:
            current_chapter = chapter_num
        else:
            break
    return current_chapter


# ── CSS generation ───────────────────────────────────────────────────

def new_css_collector():
    return {
        'fonts': set(),
        'sizes': set(),
        'colors': set(),
        'aligns': set(),
        'indents_first': set(),
        'indents_left': set(),
        'indents_right': set(),
        'spaces_before': set(),
        'spaces_after': set(),
        'line_spacings': set(),
    }


def generate_css(css_collector, output_path):
    """Generate CSS stylesheet from collected formatting data."""
    lines = []
    lines.append('/* YY Books Global Stylesheet (from PDF) */')
    lines.append(f'/* Generated: {datetime.now().strftime("%Y-%m-%d %H:%M:%S")} */')
    total_classes = sum(len(v) for v in css_collector.values())
    lines.append(f'/* Unique formatting classes: {total_classes} */')
    lines.append('')

    lines.append('/* -- Fonts -- */')
    for font_slug, font_name in sorted(css_collector['fonts']):
        lines.append(f'.yy-font-{font_slug} {{ font-family: "{font_name}"; }}')
    lines.append('')

    lines.append('/* -- Sizes -- */')
    for size in sorted(css_collector['sizes']):
        slug = pt_to_slug(size)
        lines.append(f'.yy-size-{slug} {{ font-size: {size}pt; }}')
    lines.append('')

    lines.append('/* -- Colors -- */')
    for color in sorted(css_collector['colors']):
        lines.append(f'.yy-color-{color} {{ color: #{color}; }}')
    lines.append('')

    with open(output_path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(lines) + '\n')

    logging.info(f"CSS stylesheet written: {output_path} ({total_classes} classes)")


# ── Main ─────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description='Parse YY PDF files into yy_paragraph table')
    parser.add_argument('--directory', default=DEFAULT_DIRECTORY, help='PDF directory')
    parser.add_argument('--dry-run', action='store_true', help='Extract but do not write to DB')
    parser.add_argument('--verbose', action='store_true', help='Debug logging')
    parser.add_argument('--single', help='Process only one document (filename stem)')
    args = parser.parse_args()

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format='%(asctime)s - %(levelname)s - %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S',
    )

    if sys.stdout.encoding != 'utf-8':
        sys.stdout.reconfigure(encoding='utf-8')

    directory = Path(args.directory)
    if not directory.exists():
        logging.error(f"Directory not found: {directory}")
        sys.exit(1)

    conn = None
    if not args.dry_run:
        conn = get_db_connection()
        logging.info("Connected to PostgreSQL")

    stats = {
        'docs_processed': 0,
        'docs_skipped': [],
        'total_paragraphs': 0,
        'content_paragraphs': 0,
        'paragraphs_inserted': 0,
        'page_numbers_found': 0,
        'page_numbers_null': 0,
        'chapters_assigned': 0,
        'chapters_null': 0,
    }

    # ── Phase 1: Load FK lookups ──
    volume_map = {}
    yy_chapter_map = {}
    if conn:
        logging.info("\n=== Phase 1: Loading FK lookup tables ===")
        volume_map = load_volume_mapping(conn)
        logging.info(f"  Volume mappings: {len(volume_map)}")
        yy_chapter_map = load_yy_chapter_mapping(conn)
        logging.info(f"  YY chapter mappings: {len(yy_chapter_map)}")

    # ── Phase 2: Discover PDFs ──
    logging.info("\n=== Phase 2: Discovering PDF files ===")
    pdf_files = [f for f in directory.glob("YY*.pdf")]
    pdf_files.sort()

    matched_files = []
    for pdf_path in pdf_files:
        stem = pdf_path.stem
        vol_info = volume_map.get(stem)
        if vol_info is None:
            norm_stem = stem.replace('\u2019', "'").replace('\u2018', "'")
            vol_info = volume_map.get(norm_stem)
        if args.single and stem != args.single:
            continue
        if vol_info is not None:
            matched_files.append((pdf_path, vol_info[0], vol_info[1]))
        elif args.dry_run:
            matched_files.append((pdf_path, None, None))
        else:
            logging.debug(f"  Skipping {stem} (no volume mapping)")
            stats['docs_skipped'].append(stem)

    logging.info(f"  Found {len(matched_files)} PDFs to process")
    if not matched_files:
        logging.error("No PDFs matched. Check --directory and --single.")
        sys.exit(1)

    # ── Phase 3: Extract paragraphs ──
    logging.info("\n=== Phase 3: Extracting content paragraphs ===")
    css_collector = new_css_collector()
    all_data = []

    for i, (pdf_path, vol_key, series_key) in enumerate(matched_files):
        logging.info(f"  [{i+1}/{len(matched_files)}] {pdf_path.stem} (vol={vol_key}, series={series_key})")
        try:
            pdf = fitz.open(str(pdf_path))
            total_pages = len(pdf)
            pdf.close()

            content_start, last_content, page_map = get_content_page_range(
                fitz.open(str(pdf_path))
            )

            # Determine last page number to exclude (contact info page)
            last_footer = page_map.get(last_content, 0)
            # Exclude the last content page (usually contact/social media)
            effective_last = last_content - 1 if last_content > content_start else last_content

            # Check how many blocks are on the last page to safeguard
            pdf_temp = fitz.open(str(pdf_path))
            if last_content < len(pdf_temp):
                last_page = pdf_temp[last_content]
                h = last_page.rect.height
                last_blocks = [b for b in last_page.get_text('dict')['blocks']
                              if b['type'] == 0 and b['bbox'][1] >= 55 and b['bbox'][3] <= h - 50]
                if len(last_blocks) > 20:
                    # Suspicious - don't skip last page
                    effective_last = last_content
                    logging.warning(f"    Last page has {len(last_blocks)} blocks; not skipping")
            pdf_temp.close()

            paragraphs, chapter_boundaries = extract_paragraphs_from_pdf(
                pdf_path, css_collector,
                content_start=content_start,
                last_content_page=effective_last,
                page_map=page_map,
            )

            stats['total_paragraphs'] += total_pages  # approximate
            stats['content_paragraphs'] += len(paragraphs)
            stats['docs_processed'] += 1

            logging.info(f"    {total_pages} pages, content pages {content_start+1}-{effective_last+1}, "
                        f"{len(paragraphs)} paragraphs, {len(chapter_boundaries)} chapters")

            all_data.append((pdf_path, vol_key, series_key, paragraphs, chapter_boundaries))
        except Exception as e:
            logging.error(f"    Failed to extract: {e}")
            import traceback
            traceback.print_exc()
            stats['docs_skipped'].append(pdf_path.stem)

    # ── Phase 4: Insert into yy_paragraph ──
    if not args.dry_run and conn:
        logging.info("\n=== Phase 4: Inserting into yy_paragraph ===")
        cur = conn.cursor()

        cur.execute("TRUNCATE yy_paragraph CASCADE")
        cur.execute("ALTER SEQUENCE yy_paragraph_paragraph_key_seq RESTART WITH 1")
        conn.commit()
        logging.info("  Truncated yy_paragraph and reset sequence")

        rows = []
        for pdf_path, vol_key, series_key, paragraphs, chapter_boundaries in all_data:
            for p in paragraphs:
                para_num = p['paragraph_number']

                ch_num = get_chapter_for_paragraph(para_num, chapter_boundaries)
                chapter_key = yy_chapter_map.get((vol_key, ch_num)) if ch_num and vol_key else None
                if chapter_key:
                    stats['chapters_assigned'] += 1
                else:
                    stats['chapters_null'] += 1

                page = p.get('page')
                if page is not None:
                    stats['page_numbers_found'] += 1
                else:
                    stats['page_numbers_null'] += 1

                rows.append((
                    series_key,
                    vol_key,
                    chapter_key,
                    page,
                    para_num,
                    p['text_raw'],
                    p['text_html'],
                ))

        logging.info(f"  Inserting {len(rows)} rows...")
        execute_values(cur, """
            INSERT INTO yy_paragraph (
                yy_series_key, yy_volume_key, yy_chapter_key,
                paragraph_page, paragraph_number,
                paragraph_text_raw, paragraph_text_html
            ) VALUES %s
        """, rows, page_size=500)
        conn.commit()
        stats['paragraphs_inserted'] = len(rows)
        logging.info(f"  Inserted {len(rows)} rows into yy_paragraph")
    else:
        logging.info("\n=== Phase 4: Dry run - no DB writes ===")
        for pdf_path, vol_key, series_key, paragraphs, chapter_boundaries in all_data:
            for p in paragraphs:
                para_num = p['paragraph_number']
                ch_num = get_chapter_for_paragraph(para_num, chapter_boundaries)
                chapter_key = yy_chapter_map.get((vol_key, ch_num)) if ch_num and vol_key else None
                if chapter_key:
                    stats['chapters_assigned'] += 1
                else:
                    stats['chapters_null'] += 1
                if p.get('page') is not None:
                    stats['page_numbers_found'] += 1
                else:
                    stats['page_numbers_null'] += 1

    # ── Phase 5: Generate CSS ──
    logging.info("\n=== Phase 5: Generating CSS stylesheet ===")
    css_dir = Path(__file__).parent / 'public' / 'css'
    css_dir.mkdir(parents=True, exist_ok=True)
    css_filename = f"yy.books.pdf.{datetime.now().strftime('%Y%m%d')}.css"
    css_path = css_dir / css_filename
    generate_css(css_collector, css_path)

    # ── Summary ──
    if conn:
        conn.close()
    logging.info("\n" + "=" * 60)
    logging.info("SUMMARY")
    logging.info("=" * 60)
    logging.info(f"Documents processed:   {stats['docs_processed']}")
    if stats['docs_skipped']:
        logging.info(f"Documents skipped:     {stats['docs_skipped']}")
    logging.info(f"Content paragraphs:    {stats['content_paragraphs']}")
    logging.info(f"Paragraphs inserted:   {stats['paragraphs_inserted']}")
    logging.info(f"Page numbers found:    {stats['page_numbers_found']}")
    logging.info(f"Page numbers null:     {stats['page_numbers_null']}")
    logging.info(f"Chapters assigned:     {stats['chapters_assigned']}")
    logging.info(f"Chapters null:         {stats['chapters_null']}")
    logging.info(f"CSS file:              {css_path}")


if __name__ == '__main__':
    main()
