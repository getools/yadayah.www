#!/usr/bin/env python3
"""
Fix NULL page numbers in yy_translation using python-docx XML page mapping.

Root cause: The original parser used Word Find with text snippets, but private
use area Unicode characters (Yada Towrah font) and post-extraction normalization
caused search text to differ from Word's text, so Find failed for ~600 paragraphs.

Strategy (no COM needed - fast):
1. Re-extract translations from each .docx to get paragraph indices
2. Build XML-based page map (lastRenderedPageBreak + pgNumType section restarts)
3. Match extracted translations to DB records by positional order within each volume
4. Assign page numbers from the XML page map
"""

import os
import re
import sys
import logging
from pathlib import Path
from collections import defaultdict

try:
    from docx import Document
except ImportError:
    print("ERROR: pip install python-docx")
    sys.exit(1)

try:
    import psycopg2
except ImportError:
    print("ERROR: pip install psycopg2-binary")
    sys.exit(1)

logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

# Configuration
DOCS_DIR = Path(r"C:\users\joe\work\dev\yada\docs")
DB_CONFIG = {
    'host': 'localhost',
    'port': 5433,
    'dbname': 'yada',
    'user': 'postgres',
    'password': 'yada_password'
}


def strip_html(text):
    """Strip HTML tags. Removes font-specific span content entirely."""
    text = re.sub(r'<span class="[^"]*">[^<]*</span>', '', text)
    return re.sub(r'<[^>]+>', '', text)


def normalize_text(text):
    """Normalize Unicode for comparison: curly quotes -> ASCII, etc."""
    text = text.replace('\u2019', "'").replace('\u2018', "'")
    text = text.replace('\u201C', '"').replace('\u201D', '"')
    text = text.replace('\u2013', '-').replace('\u2014', '-')
    return text


def make_fingerprint(text, length=80):
    """Create lowercase alphanumeric-only fingerprint for matching."""
    return re.sub(r'[^a-z0-9]', '', text.lower())[:length]


def replace_unicode_chars(text):
    """Replace private use area Unicode characters (from Yada Towrah font)."""
    text = text.replace('\uf065', 'e')
    text = text.replace('\uf066', 'f')
    text = text.replace('\uf069', 'i')
    return text


def build_page_map_from_xml(doc_path):
    """
    Build paragraph-to-page map from XML page breaks and section restarts.
    Returns dict mapping python-docx 0-based paragraph index -> page number.
    """
    doc = Document(str(doc_path))
    nsmap = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
    body = doc.element.body

    direct_paras = body.findall('w:p', nsmap)
    all_paras = body.findall('.//w:p', nsmap)
    top_level_ids = set(id(p) for p in direct_paras)

    # Find section page number restarts
    section_starts = {}
    for sect in body.findall('.//w:sectPr', nsmap):
        pg_num = sect.find('w:pgNumType', nsmap)
        if pg_num is not None:
            start_val = pg_num.get(f'{{{nsmap["w"]}}}start')
            if start_val:
                parent = sect.getparent()
                if parent.tag == f'{{{nsmap["w"]}}}pPr':
                    p_elem = parent.getparent()
                    for pi, pe in enumerate(all_paras):
                        if pe is p_elem:
                            section_starts[pi] = int(start_val)
                            break

    page = 1
    para_to_page = {}
    docx_idx = 0

    for abs_idx, para_elem in enumerate(all_paras):
        if abs_idx in section_starts:
            page = section_starts[abs_idx]

        if id(para_elem) in top_level_ids:
            para_to_page[docx_idx] = page
            docx_idx += 1

        for _ in para_elem.findall('.//w:lastRenderedPageBreak', nsmap):
            page += 1

    return para_to_page


def extract_translation_indices(doc_path):
    """
    Re-extract translations from a document, returning paragraph indices and
    fingerprints for matching to DB records.

    Returns list of (para_index, fingerprint) in document order.
    """
    # Import extraction logic from the parsing script
    sys.path.insert(0, str(Path(__file__).parent))
    from parse_word_translations import extract_translations_from_doc

    doc = Document(str(doc_path))
    translations = extract_translations_from_doc(doc_path, doc=doc)

    results = []
    for t in translations:
        para_idx = t.get('_para_idx')
        if para_idx is not None:
            # Build fingerprint from the extracted HTML text
            html = t.get('text_word', '')
            plain = strip_html(normalize_text(replace_unicode_chars(html)))
            fp = make_fingerprint(plain)
            results.append((para_idx, fp))

    return results


def match_by_position(db_records, extracted, page_map):
    """
    Match DB records to extracted translations by position.
    Both lists should be in document order.

    Returns dict mapping translation_key -> page_number.
    """
    results = {}

    if len(db_records) != len(extracted):
        return None  # Count mismatch, can't use positional matching

    for i, (key, _) in enumerate(db_records):
        para_idx, _ = extracted[i]
        page = page_map.get(para_idx)
        if page is not None:
            results[key] = page

    return results


def match_by_fingerprint(db_records, extracted, page_map, min_score=15):
    """
    Match DB records to extracted translations by text fingerprint.
    Uses greedy forward matching to preserve document order.

    Returns dict mapping translation_key -> page_number.
    """
    results = {}
    last_matched_pos = -1

    for key, html_text in db_records:
        db_fp = make_fingerprint(strip_html(normalize_text(html_text)))
        if not db_fp or len(db_fp) < 10:
            continue

        best_page = None
        best_score = 0
        best_pos = -1

        search_start = max(0, last_matched_pos + 1)
        for pos in range(search_start, len(extracted)):
            para_idx, ext_fp = extracted[pos]

            # Compare common prefix length
            common = 0
            for i in range(min(len(db_fp), len(ext_fp))):
                if db_fp[i] == ext_fp[i]:
                    common += 1
                else:
                    break

            if common > best_score:
                best_score = common
                best_page = page_map.get(para_idx)
                best_pos = pos

            if common >= 40:
                break

            if pos > search_start + 5000:
                break

        if best_score >= min_score and best_page is not None:
            results[key] = best_page
            last_matched_pos = best_pos

    return results


def match_global(db_records, extracted, page_map, min_score=20):
    """
    Match DB records to extracted translations using global search (no ordering constraint).
    Each DB record finds its best match anywhere in the extracted list.
    Used as a fallback when greedy forward matching fails due to count mismatches.

    Returns dict mapping translation_key -> page_number.
    """
    results = {}
    used_positions = set()

    for key, html_text in db_records:
        db_fp = make_fingerprint(strip_html(normalize_text(html_text)))
        if not db_fp or len(db_fp) < 10:
            continue

        best_page = None
        best_score = 0
        best_pos = -1

        for pos in range(len(extracted)):
            if pos in used_positions:
                continue

            para_idx, ext_fp = extracted[pos]

            # Compare common prefix length
            common = 0
            for i in range(min(len(db_fp), len(ext_fp))):
                if db_fp[i] == ext_fp[i]:
                    common += 1
                else:
                    break

            if common > best_score:
                best_score = common
                best_page = page_map.get(para_idx)
                best_pos = pos

            if common >= 60:
                break

        if best_score >= min_score and best_page is not None:
            results[key] = best_page
            used_positions.add(best_pos)

    return results


def main():
    logging.info("=== Fix NULL Page Numbers in yy_translation ===\n")

    conn = psycopg2.connect(**DB_CONFIG)
    cursor = conn.cursor()

    # Get ALL translations per volume (for positional matching)
    # and identify which ones have NULL pages
    cursor.execute("""
        SELECT t.yy_translation_key, t.yy_volume_key, v.yy_volume_file,
               t.yy_translation_page, t.yy_translation_copy
        FROM yy_translation t
        JOIN yy_volume v ON v.yy_volume_key = t.yy_volume_key
        ORDER BY t.yy_volume_key, t.yy_translation_key
    """)
    all_records = cursor.fetchall()

    # Group by volume
    by_volume = defaultdict(list)
    null_keys = set()
    for key, vol_key, vol_file, page, copy in all_records:
        by_volume[(vol_key, vol_file)].append((key, copy))
        if page is None:
            null_keys.add(key)

    total_null = len(null_keys)
    logging.info(f"Total translations: {len(all_records)}")
    logging.info(f"NULL page numbers: {total_null}\n")

    if total_null == 0:
        logging.info("Nothing to fix!")
        conn.close()
        return

    total_fixed = 0
    total_failed = 0

    for (vol_key, vol_file), records in sorted(by_volume.items()):
        # Check if this volume has any NULL records
        vol_null_count = sum(1 for key, _ in records if key in null_keys)
        if vol_null_count == 0:
            continue

        doc_path = DOCS_DIR / f"{vol_file}.docx"
        if not doc_path.exists():
            logging.warning(f"  Skipping volume {vol_key}: file not found")
            total_failed += vol_null_count
            continue

        file_size_mb = doc_path.stat().st_size / (1024 * 1024)
        logging.info(f"Volume {vol_key}: {vol_file}")
        logging.info(f"  File: {file_size_mb:.1f} MB, {len(records)} total, {vol_null_count} NULL")

        # Build XML page map (fast, no COM)
        try:
            page_map = build_page_map_from_xml(doc_path)
            logging.info(f"  XML page map: {len(page_map)} paragraphs mapped")
        except Exception as e:
            logging.warning(f"  Failed to build page map: {e}")
            total_failed += vol_null_count
            continue

        # Re-extract translations to get paragraph indices
        try:
            extracted = extract_translation_indices(doc_path)
            logging.info(f"  Re-extracted: {len(extracted)} translations")
        except Exception as e:
            logging.warning(f"  Failed to extract: {e}")
            total_failed += vol_null_count
            continue

        # Try positional matching first (if counts match)
        null_records_only = [(k, c) for k, c in records if k in null_keys]
        matches = None

        if len(records) == len(extracted):
            logging.info(f"  Counts match ({len(records)}), using positional matching")
            matches = match_by_position(records, extracted, page_map)
            # Filter to only NULL records
            if matches:
                matches = {k: v for k, v in matches.items() if k in null_keys}
        else:
            logging.info(f"  Count mismatch (DB={len(records)}, extracted={len(extracted)}), using fingerprint matching")
            matches = match_by_fingerprint(null_records_only, extracted, page_map)

            # Fall back to global matching for any remaining unmatched
            unmatched = [(k, c) for k, c in null_records_only if k not in matches]
            if unmatched:
                logging.info(f"  Greedy matched {len(matches)}, trying global for {len(unmatched)} remaining...")
                global_matches = match_global(unmatched, extracted, page_map)
                matches.update(global_matches)
                logging.info(f"  Global matched {len(global_matches)} more")

        if not matches:
            logging.warning(f"  No matches found")
            total_failed += vol_null_count
            continue

        # Update DB
        fixed = 0
        for key, page in matches.items():
            cursor.execute(
                "UPDATE yy_translation SET yy_translation_page = %s WHERE yy_translation_key = %s",
                (page, key)
            )
            fixed += 1

        conn.commit()
        total_fixed += fixed
        failed = vol_null_count - fixed
        total_failed += failed
        logging.info(f"  Fixed: {fixed}/{vol_null_count}" +
                     (f" ({failed} unmatched)" if failed else "") + "\n")

    logging.info(f"{'='*50}")
    logging.info(f"SUMMARY")
    logging.info(f"  Total fixed:      {total_fixed}")
    logging.info(f"  Total unmatched:  {total_failed}")
    logging.info(f"  Total processed:  {total_null}")

    cursor.close()
    conn.close()


if __name__ == '__main__':
    main()
