#!/usr/bin/env python3
"""
Reparse YY Word documents and repopulate yy_translation + yy_word_translation.

Phases:
  1. Load FK lookup tables from PostgreSQL
  2. Extract translations from Word documents (reuses parse_word_translations.py)
  3. Get page numbers via COM/VBScript (reuses parse_word_translations.py)
  4. Resolve foreign keys for each translation
  5. Clear and insert into yy_translation
  6. Rebuild yy_word_translation mapping

Usage:
    python reparse_translations.py
    python reparse_translations.py --dry-run --verbose
    python reparse_translations.py --skip-pages --skip-word-translations
"""

import os
import sys
import argparse
import logging
from pathlib import Path

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

from parse_word_translations import extract_translations_from_doc, get_all_page_numbers
from populate_word_translations import normalize_quotes, build_word_lookup, extract_italic_words

DEFAULT_DIRECTORY = r"C:\users\joe\work\dev\yada\docs"

# ── Database connection ──────────────────────────────────────────────

def get_db_connection():
    """Create PostgreSQL database connection using .env."""
    load_dotenv()
    host = os.getenv('POSTGRES_HOST', 'localhost')
    port = os.getenv('POSTGRES_PORT', '5432')
    user = os.getenv('POSTGRES_USER', 'postgres')
    password = os.getenv('POSTGRES_PASSWORD')
    db = os.getenv('POSTGRES_DB', 'yada')

    if not password:
        print("ERROR: POSTGRES_PASSWORD environment variable not set")
        sys.exit(1)

    conn = psycopg2.connect(host=host, port=port, user=user, password=password, dbname=db)
    return conn


# ── Phase 1: Load FK lookup tables ──────────────────────────────────

def fix_cite_book_scroll_keys(conn):
    """Fix yy_cite_book entries with NULL yah_scroll_key."""
    fixes = [
        (32, 24),   # Chagay -> Haggai
        (33, 36),   # Acts -> Acts
        (34, 37),   # Galatians -> Galatians
        (35, 38),   # 2 Peter -> 2 Peter
    ]
    cur = conn.cursor()
    for cite_book_key, scroll_key in fixes:
        cur.execute(
            "UPDATE yy_cite_book SET yah_scroll_key = %s WHERE cite_book_key = %s AND yah_scroll_key IS NULL",
            (scroll_key, cite_book_key)
        )
    conn.commit()
    cur.close()
    logging.info("Fixed yy_cite_book NULL yah_scroll_key entries")


def load_volume_mapping(conn):
    """Load filename stem -> (yy_volume_key, yy_series_key) mapping."""
    cur = conn.cursor()
    cur.execute("SELECT yy_volume_key, yy_volume_file, yy_series_key FROM yy_volume WHERE yy_volume_file IS NOT NULL")
    mapping = {}
    for vol_key, filename, series_key in cur.fetchall():
        if filename:
            mapping[filename] = (vol_key, series_key)
            norm = filename.replace('\u2019', "'").replace('\u2018', "'")
            if norm != filename:
                mapping[norm] = (vol_key, series_key)
    cur.close()
    return mapping


def load_cite_book_mapping(conn):
    """Load normalized cite_hebrew -> yah_scroll_key mapping via cite_book_map + cite_book."""
    cur = conn.cursor()
    cur.execute("""
        SELECT cbm.cite_book_map_hebrew, cb.yah_scroll_key
        FROM yy_cite_book_map cbm
        JOIN yy_cite_book cb ON cb.cite_book_key = cbm.cite_book_key
        WHERE cb.yah_scroll_key IS NOT NULL
    """)
    mapping = {}
    for cite_hebrew, scroll_key in cur.fetchall():
        # Store both original and normalized forms
        mapping[cite_hebrew] = scroll_key
        norm = normalize_quotes(cite_hebrew)
        if norm != cite_hebrew:
            mapping[norm] = scroll_key
    cur.close()
    return mapping


def load_chapter_mapping(conn):
    """Load (yah_scroll_key, chapter_number) -> yah_chapter_key."""
    cur = conn.cursor()
    cur.execute("SELECT yah_chapter_key, yah_scroll_key, yah_chapter_number FROM yah_chapter")
    mapping = {}
    for ch_key, scroll_key, ch_num in cur.fetchall():
        mapping[(scroll_key, ch_num)] = ch_key
    cur.close()
    return mapping


def load_verse_mapping(conn):
    """Load (yah_chapter_key, verse_number) -> yah_verse_key."""
    cur = conn.cursor()
    cur.execute("SELECT yah_verse_key, yah_chapter_key, yah_verse_number FROM yah_verse")
    mapping = {}
    for verse_key, ch_key, verse_num in cur.fetchall():
        mapping[(ch_key, verse_num)] = verse_key
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


# ── Phase 4: FK resolution with auto-creation ───────────────────────

def ensure_chapter(conn, scroll_key, ch_num, chapter_map):
    """Auto-create a yah_chapter row if missing. Returns yah_chapter_key."""
    key = (scroll_key, ch_num)
    if key in chapter_map:
        return chapter_map[key]

    cur = conn.cursor()
    cur.execute(
        "INSERT INTO yah_chapter (yah_scroll_key, yah_chapter_number, yah_chapter_sort) "
        "VALUES (%s, %s, %s) RETURNING yah_chapter_key",
        (scroll_key, ch_num, ch_num * 10)
    )
    new_key = cur.fetchone()[0]
    conn.commit()
    cur.close()
    chapter_map[key] = new_key
    logging.debug(f"  Auto-created yah_chapter: scroll={scroll_key} ch={ch_num} -> key={new_key}")
    return new_key


def ensure_verse(conn, ch_key, verse_num, verse_map):
    """Auto-create a yah_verse row if missing. Returns yah_verse_key."""
    key = (ch_key, verse_num)
    if key in verse_map:
        return verse_map[key]

    cur = conn.cursor()
    cur.execute(
        "INSERT INTO yah_verse (yah_chapter_key, yah_verse_number, yah_verse_sort) "
        "VALUES (%s, %s, %s) RETURNING yah_verse_key",
        (ch_key, verse_num, verse_num * 10)
    )
    new_key = cur.fetchone()[0]
    conn.commit()
    cur.close()
    verse_map[key] = new_key
    logging.debug(f"  Auto-created yah_verse: ch_key={ch_key} verse={verse_num} -> key={new_key}")
    return new_key


def resolve_translation_fks(t, vol_key, series_key, lookups, conn, stats):
    """
    Resolve all FK references for a translation.

    Returns dict with resolved keys, or None if critical FKs can't be resolved.
    """
    cite_hebrew = t.get('cite_hebrew')
    cite_name = t.get('cite')
    cite_chapter = t.get('cite_chapter')
    cite_verse = t.get('cite_verse')
    chapter_num = t.get('_chapter_num')

    cite_book_map = lookups['cite_book_map']
    chapter_map = lookups['chapter_map']
    verse_map = lookups['verse_map']
    yy_chapter_map = lookups['yy_chapter_map']

    # Step 1: cite_hebrew -> yah_scroll_key
    yah_scroll_key = None
    if cite_hebrew:
        norm_hebrew = normalize_quotes(cite_hebrew)
        yah_scroll_key = cite_book_map.get(norm_hebrew)
        if yah_scroll_key is None:
            # Try the full cite name (sometimes cite_hebrew is partial)
            if cite_name:
                norm_name = normalize_quotes(cite_name)
                yah_scroll_key = cite_book_map.get(norm_name)

    if yah_scroll_key is None:
        if cite_hebrew:
            stats['new_cite_variants'].add(cite_hebrew)
        stats['skip_reasons']['no_scroll_key'] += 1
        return None

    # Step 2: yah_scroll_key + cite_chapter -> yah_chapter_key
    if cite_chapter is None:
        stats['skip_reasons']['no_chapter'] += 1
        return None

    yah_chapter_key = ensure_chapter(conn, yah_scroll_key, cite_chapter, chapter_map)

    # Step 3: yah_chapter_key + cite_verse -> yah_verse_key
    if cite_verse is None:
        stats['skip_reasons']['no_verse'] += 1
        return None

    yah_verse_key = ensure_verse(conn, yah_chapter_key, cite_verse, verse_map)

    # Step 4: yy_chapter_key (nullable)
    yy_chapter_key = None
    if chapter_num is not None:
        yy_chapter_key = yy_chapter_map.get((vol_key, chapter_num))

    return {
        'yah_scroll_key': yah_scroll_key,
        'yah_chapter_key': yah_chapter_key,
        'yah_verse_key': yah_verse_key,
        'yy_series_key': series_key,
        'yy_volume_key': vol_key,
        'yy_chapter_key': yy_chapter_key,
    }


# ── Main ─────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description="Reparse YY translations and rebuild yy_word_translation"
    )
    parser.add_argument("--directory", type=str, default=DEFAULT_DIRECTORY)
    parser.add_argument("--dry-run", action="store_true",
                        help="Extract and resolve but don't modify database")
    parser.add_argument("--verbose", action="store_true", help="Debug-level logging")
    parser.add_argument("--skip-pages", action="store_true",
                        help="Skip COM/XML page number extraction (faster)")
    parser.add_argument("--skip-word-translations", action="store_true",
                        help="Skip yy_word_translation rebuild")
    args = parser.parse_args()

    level = logging.DEBUG if args.verbose else logging.INFO
    logging.basicConfig(level=level, format='%(asctime)s - %(levelname)s - %(message)s',
                        datefmt='%Y-%m-%d %H:%M:%S')

    # Get root logger for temporary suppression during extraction
    root_logger = logging.getLogger()

    directory = Path(args.directory)
    if not directory.exists():
        logging.error(f"Directory not found: {directory}")
        sys.exit(1)

    stats = {
        'docs_processed': 0,
        'docs_skipped': [],
        'translations_extracted': 0,
        'translations_inserted': 0,
        'translations_skipped': 0,
        'skip_reasons': {
            'no_scroll_key': 0,
            'no_chapter': 0,
            'no_verse': 0,
            'no_cite': 0,
        },
        'new_cite_variants': set(),
        'chapters_created': 0,
        'verses_created': 0,
        'word_translation_pairs': 0,
        'page_numbers_found': 0,
        'page_numbers_null': 0,
    }

    conn = get_db_connection()
    logging.info("Connected to PostgreSQL")

    # ── Phase 1: Load lookups ──
    logging.info("\n=== Phase 1: Loading FK lookup tables ===")

    fix_cite_book_scroll_keys(conn)

    volume_map = load_volume_mapping(conn)
    logging.info(f"  Volume mappings: {len(volume_map)}")

    cite_book_map = load_cite_book_mapping(conn)
    logging.info(f"  Cite book mappings: {len(cite_book_map)}")

    chapter_map = load_chapter_mapping(conn)
    chapters_before = len(chapter_map)
    logging.info(f"  Chapter mappings: {chapters_before}")

    verse_map = load_verse_mapping(conn)
    verses_before = len(verse_map)
    logging.info(f"  Verse mappings: {verses_before}")

    yy_chapter_map = load_yy_chapter_mapping(conn)
    logging.info(f"  YY chapter mappings: {len(yy_chapter_map)}")

    lookups = {
        'cite_book_map': cite_book_map,
        'chapter_map': chapter_map,
        'verse_map': verse_map,
        'yy_chapter_map': yy_chapter_map,
    }

    # ── Phase 2: Extract translations ──
    logging.info("\n=== Phase 2: Extracting translations from Word documents ===")

    docx_files = [f for f in directory.glob("YY*.docx")
                  if not f.name.startswith("~$") and not f.name.startswith("YY-s07")]
    docx_files.sort()
    logging.info(f"Found {len(docx_files)} documents to process")

    # all_data: list of (doc_path, vol_key, series_key, translations_list)
    all_data = []

    for doc_path in docx_files:
        stem = doc_path.stem
        vol_info = volume_map.get(stem)
        if vol_info is None:
            norm_stem = stem.replace('\u2019', "'").replace('\u2018', "'")
            vol_info = volume_map.get(norm_stem)
        if vol_info is None:
            logging.warning(f"  No volume mapping for {stem}, skipping")
            stats['docs_skipped'].append(stem)
            continue

        vol_key, series_key = vol_info

        logging.info(f"  {stem} (vol={vol_key}, series={series_key})")

        # Temporarily suppress verbose extraction logging
        original_level = root_logger.level
        root_logger.setLevel(logging.WARNING)
        results = extract_translations_from_doc(doc_path, detect_chapters=True)
        root_logger.setLevel(original_level)

        # Separate chapter metadata from translations
        chapter_meta = results[-1] if results and isinstance(results[-1], dict) and '_chapters' in results[-1] else None
        if chapter_meta:
            translations = results[:-1]
        else:
            translations = results

        logging.info(f"    {len(translations)} translations extracted")
        stats['translations_extracted'] += len(translations)
        stats['docs_processed'] += 1

        all_data.append((doc_path, vol_key, series_key, translations))

    # ── Phase 3: Get page numbers ──
    if not args.skip_pages:
        logging.info("\n=== Phase 3: Getting page numbers via COM/XML ===")

        doc_para_map = {}
        for doc_path, vol_key, series_key, translations in all_data:
            if translations:
                para_indices = list(set(t["_para_idx"] for t in translations if "_para_idx" in t))
                if para_indices:
                    doc_para_map[str(doc_path.absolute())] = para_indices

        if doc_para_map:
            all_page_numbers = get_all_page_numbers(doc_para_map)

            # Apply page numbers
            for doc_path, vol_key, series_key, translations in all_data:
                abs_path = str(doc_path.absolute())
                page_map = all_page_numbers.get(abs_path, {})
                for t in translations:
                    pidx = t.get("_para_idx")
                    if pidx is not None and pidx in page_map:
                        t["page"] = page_map[pidx]
                        stats['page_numbers_found'] += 1
                    else:
                        stats['page_numbers_null'] += 1

            logging.info(f"  Page numbers found: {stats['page_numbers_found']}, null: {stats['page_numbers_null']}")
        else:
            logging.info("  No paragraphs to get page numbers for")
    else:
        logging.info("\n=== Phase 3: Skipped (--skip-pages) ===")
        stats['page_numbers_null'] = stats['translations_extracted']

    # ── Phase 4: Resolve FKs ──
    logging.info("\n=== Phase 4: Resolving foreign keys ===")

    resolved_rows = []  # list of tuples ready for INSERT

    for doc_path, vol_key, series_key, translations in all_data:
        doc_inserted = 0
        doc_skipped = 0

        for t in translations:
            # Check basic cite info exists
            if not t.get('cite') and not t.get('cite_hebrew'):
                stats['skip_reasons']['no_cite'] += 1
                stats['translations_skipped'] += 1
                doc_skipped += 1
                continue

            fks = resolve_translation_fks(t, vol_key, series_key, lookups, conn, stats)
            if fks is None:
                stats['translations_skipped'] += 1
                doc_skipped += 1
                continue

            row = (
                fks['yah_scroll_key'],
                fks['yah_chapter_key'],
                fks['yah_verse_key'],
                fks['yy_series_key'],
                fks['yy_volume_key'],
                fks['yy_chapter_key'],
                t.get('page'),          # yy_translation_page
                None,                   # yy_translation_paragraph
                t.get('text_word', ''), # yy_translation_copy
                0,                      # yy_translation_sort
            )
            resolved_rows.append(row)
            doc_inserted += 1

        logging.info(f"  {doc_path.stem}: {doc_inserted} resolved, {doc_skipped} skipped")

    stats['chapters_created'] = len(chapter_map) - chapters_before
    stats['verses_created'] = len(verse_map) - verses_before

    logging.info(f"\nTotal resolved: {len(resolved_rows)}")
    logging.info(f"Total skipped: {stats['translations_skipped']}")
    if stats['chapters_created']:
        logging.info(f"Auto-created chapters: {stats['chapters_created']}")
    if stats['verses_created']:
        logging.info(f"Auto-created verses: {stats['verses_created']}")

    # ── Phase 5: Clear and insert yy_translation ──
    if args.dry_run:
        logging.info("\n=== Phase 5: Skipped (--dry-run) ===")
        logging.info(f"  Would insert {len(resolved_rows)} rows into yy_translation")
        stats['translations_inserted'] = len(resolved_rows)
    else:
        logging.info("\n=== Phase 5: Inserting into yy_translation ===")

        cur = conn.cursor()

        # Disable revision triggers
        for trig in ('trg_yy_translation_ai', 'trg_yy_translation_au', 'trg_yy_translation_bd'):
            cur.execute(f"ALTER TABLE yy_translation DISABLE TRIGGER {trig}")
        logging.info("  Disabled revision triggers")

        # Truncate (cascades to yy_word_translation)
        cur.execute("TRUNCATE yy_translation CASCADE")
        cur.execute("ALTER SEQUENCE yy_translation_yy_translation_key_seq RESTART WITH 1")
        logging.info("  Truncated yy_translation (cascade)")

        # Batch insert
        if resolved_rows:
            execute_values(cur, """
                INSERT INTO yy_translation (
                    yah_scroll_key, yah_chapter_key, yah_verse_key,
                    yy_series_key, yy_volume_key, yy_chapter_key,
                    yy_translation_page, yy_translation_paragraph,
                    yy_translation_copy, yy_translation_sort
                ) VALUES %s
            """, resolved_rows, page_size=500)
            stats['translations_inserted'] = len(resolved_rows)
            logging.info(f"  Inserted {len(resolved_rows)} rows")

        # Re-enable triggers
        for trig in ('trg_yy_translation_ai', 'trg_yy_translation_au', 'trg_yy_translation_bd'):
            cur.execute(f"ALTER TABLE yy_translation ENABLE TRIGGER {trig}")
        logging.info("  Re-enabled revision triggers")

        conn.commit()
        cur.close()
        logging.info("  Committed")

    # ── Phase 6: Rebuild yy_word_translation ──
    if args.skip_word_translations or args.dry_run:
        logging.info(f"\n=== Phase 6: Skipped ({'--dry-run' if args.dry_run else '--skip-word-translations'}) ===")
    else:
        logging.info("\n=== Phase 6: Rebuilding yy_word_translation ===")

        cur = conn.cursor()

        # Build word lookup
        word_lookup = build_word_lookup(cur)
        logging.info(f"  {len(word_lookup)} unique word_translit keys")

        # Load translations
        cur.execute("SELECT yy_translation_key, yy_translation_copy FROM yy_translation")
        translations = cur.fetchall()
        logging.info(f"  {len(translations)} translations loaded")

        # Match words
        pairs = set()
        for trans_key, trans_text in translations:
            if not trans_text:
                continue
            italic_words = extract_italic_words(trans_text)
            for word in italic_words:
                normalized = normalize_quotes(word).lower()
                if normalized in word_lookup:
                    for word_id in word_lookup[normalized]:
                        pairs.add((word_id, trans_key))

        logging.info(f"  {len(pairs)} word-translation pairs found")

        # Clear and insert
        cur.execute("DELETE FROM yy_word_translation")
        logging.info(f"  Cleared yy_word_translation")

        if pairs:
            batch = list(pairs)
            execute_values(cur, """
                INSERT INTO yy_word_translation (word_key, translation_key) VALUES %s
            """, batch, page_size=1000)
            logging.info(f"  Inserted {len(batch)} pairs")

        conn.commit()
        cur.close()
        stats['word_translation_pairs'] = len(pairs)

    # ── Summary ──
    logging.info(f"\n{'='*60}")
    logging.info("SUMMARY")
    logging.info(f"{'='*60}")
    logging.info(f"Documents processed: {stats['docs_processed']}")
    if stats['docs_skipped']:
        logging.info(f"Documents skipped (no volume mapping): {stats['docs_skipped']}")
    logging.info(f"Translations extracted: {stats['translations_extracted']}")
    logging.info(f"Translations inserted: {stats['translations_inserted']}")
    logging.info(f"Translations skipped: {stats['translations_skipped']}")

    if any(stats['skip_reasons'].values()):
        logging.info("Skip reasons:")
        for reason, count in stats['skip_reasons'].items():
            if count > 0:
                logging.info(f"  {reason}: {count}")

    if stats['new_cite_variants']:
        logging.warning(f"\nUnresolved cite_hebrew variants ({len(stats['new_cite_variants'])}):")
        for v in sorted(stats['new_cite_variants']):
            logging.warning(f"  {v}")

    if stats['chapters_created']:
        logging.info(f"Auto-created yah_chapter rows: {stats['chapters_created']}")
    if stats['verses_created']:
        logging.info(f"Auto-created yah_verse rows: {stats['verses_created']}")

    logging.info(f"Page numbers: {stats['page_numbers_found']} found, {stats['page_numbers_null']} null")

    if stats['word_translation_pairs']:
        logging.info(f"Word-translation pairs: {stats['word_translation_pairs']}")

    conn.close()
    logging.info("Done.")


if __name__ == "__main__":
    main()
