#!/usr/bin/env python3
"""
Update yy_translation records with yy_chapter_key by re-parsing Word documents.

For each Word document (single pass):
  1. Extracts translations AND detects chapter boundaries in one iteration
  2. Determines which chapter each translation belongs to
  3. Matches to yy_translation records by position or text
  4. Updates yy_chapter_key

Usage:
    python update_yy_chapters.py
    python update_yy_chapters.py --dry-run
    python update_yy_chapters.py --verbose
"""

import os
import re
import sys
import argparse
import logging
from pathlib import Path

try:
    import psycopg2
except ImportError:
    print("ERROR: psycopg2 not installed. Run: pip install psycopg2-binary")
    sys.exit(1)

try:
    from dotenv import load_dotenv
except ImportError:
    print("ERROR: python-dotenv not installed. Run: pip install python-dotenv")
    sys.exit(1)

from parse_word_translations import extract_translations_from_doc

DEFAULT_DIRECTORY = r"C:\users\joe\work\dev\yada\docs"


def get_db_connection():
    """Create PostgreSQL database connection."""
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


def normalize_text(html_text):
    """Strip HTML tags and normalize dashes/quotes/whitespace for text comparison."""
    if not html_text:
        return ''
    text = re.sub(r'<[^>]+>', '', html_text)
    text = text.replace('\u2013', '-').replace('\u2014', '-')
    text = text.replace('\u2018', "'").replace('\u2019', "'")
    text = text.replace('\u201c', '"').replace('\u201d', '"')
    text = ' '.join(text.split())
    return text


def load_volume_mapping(conn):
    """Load filename stem -> yy_volume_key mapping from yy_volume table."""
    cur = conn.cursor()
    cur.execute("SELECT yy_volume_key, yy_volume_file FROM yy_volume WHERE yy_volume_file IS NOT NULL")
    mapping = {}
    for key, filename in cur.fetchall():
        if filename:
            norm = filename.replace('\u2019', "'").replace('\u2018', "'")
            mapping[norm] = key
            mapping[filename] = key
    cur.close()
    return mapping


def load_chapter_mapping(conn):
    """Load (yy_volume_key, yy_chapter_number) -> yy_chapter_key mapping."""
    cur = conn.cursor()
    cur.execute("SELECT yy_chapter_key, yy_volume_key, yy_chapter_number FROM yy_chapter")
    mapping = {}
    for ch_key, vol_key, ch_num in cur.fetchall():
        mapping[(vol_key, ch_num)] = ch_key
    cur.close()
    return mapping


def load_yy_translations_for_volume(conn, volume_key):
    """Load yy_translation records for a volume, ordered by key (insertion order)."""
    cur = conn.cursor()
    cur.execute("""
        SELECT yy_translation_key, yy_translation_copy, yy_chapter_key
        FROM yy_translation
        WHERE yy_volume_key = %s
        ORDER BY yy_translation_key
    """, (volume_key,))
    rows = cur.fetchall()
    cur.close()
    return rows


def build_yy_lookup(yy_records):
    """Build dict from normalized text prefix -> (yy_key, existing_ch_key) for matching."""
    lookup = {}
    for yy_key, copy_text, existing_ch in yy_records:
        norm = normalize_text(copy_text or '')
        for prefix_len in [80, 60, 40]:
            prefix = norm[:prefix_len]
            if prefix and prefix not in lookup:
                lookup[prefix] = (yy_key, existing_ch)
    return lookup


def main():
    parser = argparse.ArgumentParser(
        description="Update yy_translation records with yy_chapter_key"
    )
    parser.add_argument("--directory", type=str, default=DEFAULT_DIRECTORY)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args()

    level = logging.DEBUG if args.verbose else logging.INFO
    logging.basicConfig(level=level, format='%(asctime)s - %(levelname)s - %(message)s',
                        datefmt='%Y-%m-%d %H:%M:%S')

    directory = Path(args.directory)
    if not directory.exists():
        logging.error(f"Directory not found: {directory}")
        sys.exit(1)

    conn = get_db_connection()

    volume_map = load_volume_mapping(conn)
    chapter_map = load_chapter_mapping(conn)
    logging.info(f"Loaded {len(volume_map)} volume mappings, {len(chapter_map)} chapter mappings")

    docx_files = [f for f in directory.glob("YY*.docx")
                  if not f.name.startswith("~$") and not f.name.startswith("YY-s07")]
    docx_files.sort()
    logging.info(f"Found {len(docx_files)} documents to process")

    total_updated = 0
    total_matched = 0
    total_extracted = 0
    total_no_chapter = 0
    total_no_match = 0

    for doc_path in docx_files:
        stem = doc_path.stem
        logging.info(f"\n{'='*60}")
        logging.info(f"Processing: {stem}")

        # Map filename to volume_key
        volume_key = volume_map.get(stem)
        if volume_key is None:
            norm_stem = stem.replace('\u2019', "'").replace('\u2018', "'")
            volume_key = volume_map.get(norm_stem)
        if volume_key is None:
            logging.warning(f"  No volume mapping for {stem}, skipping")
            continue

        logging.info(f"  Volume key: {volume_key}")

        # Single pass: extract translations AND detect chapters
        results = extract_translations_from_doc(doc_path, detect_chapters=True)

        # Last element is the chapter metadata
        chapter_meta = results[-1] if results and '_chapters' in results[-1] else None
        if chapter_meta:
            boundaries = chapter_meta['_chapters']
            translations = results[:-1]
        else:
            logging.warning(f"  No chapter boundaries found, skipping")
            continue

        logging.info(f"  Chapters: {[b[1] for b in boundaries]}")
        logging.info(f"  Extracted {len(translations)} translations")
        total_extracted += len(translations)

        # Assign chapter keys
        chapter_keys = []
        no_ch = 0
        for t in translations:
            ch_num = t.get('_chapter_num')
            if ch_num is None:
                no_ch += 1
                chapter_keys.append(None)
                continue
            ch_key = chapter_map.get((volume_key, ch_num))
            if ch_key is None:
                logging.warning(f"  No yy_chapter for vol={volume_key} ch={ch_num}")
                chapter_keys.append(None)
            else:
                chapter_keys.append(ch_key)
        total_no_chapter += no_ch
        assigned = sum(1 for ck in chapter_keys if ck is not None)
        logging.info(f"  Assigned chapters: {assigned}, before ch1: {no_ch}")

        # Load yy_translation records
        yy_records = load_yy_translations_for_volume(conn, volume_key)
        logging.info(f"  yy_translation records: {len(yy_records)}")

        # Match and build updates
        updates = []

        if len(translations) == len(yy_records):
            logging.info(f"  Counts match, using positional mapping")
            for i, ch_key in enumerate(chapter_keys):
                if ch_key is None:
                    continue
                yy_key = yy_records[i][0]
                existing_ch = yy_records[i][2]
                total_matched += 1
                if existing_ch != ch_key:
                    updates.append((ch_key, yy_key))
        else:
            logging.info(f"  Count mismatch (extracted={len(translations)}, db={len(yy_records)}), text matching")
            yy_lookup = build_yy_lookup(yy_records)
            used_keys = set()

            for i, t in enumerate(translations):
                ch_key = chapter_keys[i]
                if ch_key is None:
                    continue

                ext_norm = normalize_text(t.get('text_word', ''))
                match = None
                for prefix_len in [80, 60, 40]:
                    prefix = ext_norm[:prefix_len]
                    if prefix and prefix in yy_lookup:
                        yy_key, existing_ch = yy_lookup[prefix]
                        if yy_key not in used_keys:
                            match = (yy_key, existing_ch)
                        break

                if match:
                    yy_key, existing_ch = match
                    used_keys.add(yy_key)
                    total_matched += 1
                    if existing_ch != ch_key:
                        updates.append((ch_key, yy_key))
                else:
                    total_no_match += 1
                    if args.verbose:
                        logging.debug(f"  No match: {ext_norm[:50]}...")

        # Apply updates
        if updates:
            if not args.dry_run:
                cur = conn.cursor()
                for ch_key, trans_key in updates:
                    cur.execute(
                        "UPDATE yy_translation SET yy_chapter_key = %s WHERE yy_translation_key = %s",
                        (ch_key, trans_key)
                    )
                conn.commit()
                cur.close()
                total_updated += len(updates)
                logging.info(f"  Updated {len(updates)} records")
            else:
                total_updated += len(updates)
                logging.info(f"  [DRY RUN] Would update {len(updates)} records")
        else:
            logging.info(f"  No updates needed")

    # Summary
    logging.info(f"\n{'='*60}")
    logging.info("SUMMARY")
    logging.info(f"{'='*60}")
    logging.info(f"Total translations extracted: {total_extracted}")
    logging.info(f"Total matched to yy_translation: {total_matched}")
    logging.info(f"Total yy_chapter_key updates: {total_updated}")
    logging.info(f"Before first chapter (no assignment): {total_no_chapter}")
    if total_no_match:
        logging.warning(f"Unmatched (text mismatch): {total_no_match}")

    conn.close()


if __name__ == "__main__":
    main()
