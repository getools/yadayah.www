#!/usr/bin/env python3
"""
Process a single YY volume and INSERT into yy_translation (no truncate).
Usage: python reparse_single_volume.py YY-s04v04-Coming Home-Basar-Herald
"""

import os, sys, logging, argparse
from pathlib import Path

sys.stdout.reconfigure(encoding='utf-8')

import psycopg2
from psycopg2.extras import execute_values
from dotenv import load_dotenv

from parse_word_translations import extract_translations_from_doc, get_all_page_numbers
from populate_word_translations import normalize_quotes

DEFAULT_DIRECTORY = r"C:\users\joe\work\dev\yada\docs"


def get_db_connection():
    load_dotenv()
    return psycopg2.connect(
        host=os.getenv('POSTGRES_HOST', 'localhost'),
        port=os.getenv('POSTGRES_PORT', '5432'),
        user=os.getenv('POSTGRES_USER', 'postgres'),
        password=os.getenv('POSTGRES_PASSWORD'),
        dbname=os.getenv('POSTGRES_DB', 'yada'),
    )


def main():
    parser = argparse.ArgumentParser(description="Process single volume into yy_translation")
    parser.add_argument("stem", help="Document filename stem (e.g. YY-s04v04-Coming Home-Basar-Herald)")
    parser.add_argument("--skip-pages", action="store_true")
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args()

    level = logging.DEBUG if args.verbose else logging.INFO
    logging.basicConfig(level=level, format='%(asctime)s - %(levelname)s - %(message)s')

    doc_path = Path(DEFAULT_DIRECTORY) / f"{args.stem}.docx"
    if not doc_path.exists():
        logging.error(f"File not found: {doc_path}")
        sys.exit(1)

    conn = get_db_connection()
    cur = conn.cursor()

    # Load volume mapping
    cur.execute("SELECT yy_volume_key, yy_series_key FROM yy_volume WHERE yy_volume_file = %s", (args.stem,))
    row = cur.fetchone()
    if not row:
        logging.error(f"No yy_volume record for '{args.stem}'")
        sys.exit(1)
    vol_key, series_key = row
    logging.info(f"Volume: {args.stem} (vol_key={vol_key}, series_key={series_key})")

    # Check if already has data
    cur.execute("SELECT COUNT(*) FROM yy_translation WHERE yy_volume_key = %s", (vol_key,))
    existing = cur.fetchone()[0]
    if existing > 0:
        logging.warning(f"Volume already has {existing} translations. Skipping to avoid duplicates.")
        sys.exit(0)

    # Load FK lookups
    cur.execute("""
        SELECT cbm.cite_book_map_hebrew, cb.yah_scroll_key
        FROM yy_cite_book_map cbm
        JOIN yy_cite_book cb ON cb.cite_book_key = cbm.cite_book_key
        WHERE cb.yah_scroll_key IS NOT NULL
    """)
    cite_book_map = {}
    for cite_hebrew, scroll_key in cur.fetchall():
        cite_book_map[cite_hebrew] = scroll_key
        norm = normalize_quotes(cite_hebrew)
        if norm != cite_hebrew:
            cite_book_map[norm] = scroll_key

    cur.execute("SELECT yah_chapter_key, yah_scroll_key, yah_chapter_number FROM yah_chapter")
    chapter_map = {(sk, cn): ck for ck, sk, cn in cur.fetchall()}

    cur.execute("SELECT yah_verse_key, yah_chapter_key, yah_verse_number FROM yah_verse")
    verse_map = {(ck, vn): vk for vk, ck, vn in cur.fetchall()}

    cur.execute("SELECT yy_chapter_key, yy_volume_key, yy_chapter_number FROM yy_chapter")
    yy_chapter_map = {(vk, cn): ck for ck, vk, cn in cur.fetchall()}

    # Extract translations
    logging.info("Extracting translations...")
    results = extract_translations_from_doc(doc_path, detect_chapters=True)
    chapter_meta = results[-1] if results and isinstance(results[-1], dict) and '_chapters' in results[-1] else None
    translations = results[:-1] if chapter_meta else results
    logging.info(f"  Extracted {len(translations)} translations")

    # Get page numbers
    if not args.skip_pages and translations:
        logging.info("Getting page numbers...")
        para_indices = list(set(t["_para_idx"] for t in translations if "_para_idx" in t))
        if para_indices:
            doc_para_map = {str(doc_path.absolute()): para_indices}
            all_pages = get_all_page_numbers(doc_para_map)
            page_map = all_pages.get(str(doc_path.absolute()), {})
            found = 0
            for t in translations:
                pidx = t.get("_para_idx")
                if pidx is not None and pidx in page_map:
                    t["page"] = page_map[pidx]
                    found += 1
            logging.info(f"  Page numbers: {found} found")

    # Resolve FKs and build rows
    logging.info("Resolving foreign keys...")
    resolved_rows = []
    skipped = 0

    def ensure_chapter(scroll_key, ch_num):
        key = (scroll_key, ch_num)
        if key not in chapter_map:
            cur.execute(
                "INSERT INTO yah_chapter (yah_scroll_key, yah_chapter_number, yah_chapter_sort) "
                "VALUES (%s, %s, %s) RETURNING yah_chapter_key",
                (scroll_key, ch_num, ch_num * 10))
            chapter_map[key] = cur.fetchone()[0]
            conn.commit()
        return chapter_map[key]

    def ensure_verse(ch_key, verse_num):
        key = (ch_key, verse_num)
        if key not in verse_map:
            cur.execute(
                "INSERT INTO yah_verse (yah_chapter_key, yah_verse_number, yah_verse_sort) "
                "VALUES (%s, %s, %s) RETURNING yah_verse_key",
                (ch_key, verse_num, verse_num * 10))
            verse_map[key] = cur.fetchone()[0]
            conn.commit()
        return verse_map[key]

    for t in translations:
        cite_hebrew = t.get('cite_hebrew')
        if not cite_hebrew and not t.get('cite'):
            skipped += 1
            continue

        scroll_key = None
        if cite_hebrew:
            norm = normalize_quotes(cite_hebrew)
            scroll_key = cite_book_map.get(norm) or cite_book_map.get(cite_hebrew)
        if scroll_key is None and t.get('cite'):
            scroll_key = cite_book_map.get(normalize_quotes(t['cite']))

        if scroll_key is None or t.get('cite_chapter') is None or t.get('cite_verse') is None:
            skipped += 1
            continue

        ch_key = ensure_chapter(scroll_key, t['cite_chapter'])
        verse_key = ensure_verse(ch_key, t['cite_verse'])
        yy_ch_key = yy_chapter_map.get((vol_key, t.get('_chapter_num')))

        resolved_rows.append((
            scroll_key, ch_key, verse_key,
            series_key, vol_key, yy_ch_key,
            t.get('page'), None,
            t.get('text_word', ''), 0,
        ))

    logging.info(f"  Resolved: {len(resolved_rows)}, Skipped: {skipped}")

    # Insert (disable triggers for bulk)
    if resolved_rows:
        for trig in ('trg_yy_translation_ai', 'trg_yy_translation_au', 'trg_yy_translation_bd'):
            cur.execute(f"ALTER TABLE yy_translation DISABLE TRIGGER {trig}")

        execute_values(cur, """
            INSERT INTO yy_translation (
                yah_scroll_key, yah_chapter_key, yah_verse_key,
                yy_series_key, yy_volume_key, yy_chapter_key,
                yy_translation_page, yy_translation_paragraph,
                yy_translation_copy, yy_translation_sort
            ) VALUES %s
        """, resolved_rows, page_size=500)

        for trig in ('trg_yy_translation_ai', 'trg_yy_translation_au', 'trg_yy_translation_bd'):
            cur.execute(f"ALTER TABLE yy_translation ENABLE TRIGGER {trig}")

        conn.commit()
        logging.info(f"  Inserted {len(resolved_rows)} translations")

    # Verify
    cur.execute("SELECT COUNT(*) FROM yy_translation WHERE yy_volume_key = %s", (vol_key,))
    final = cur.fetchone()[0]
    logging.info(f"  Final count for volume {vol_key}: {final}")

    cur.close()
    conn.close()
    logging.info("Done.")


if __name__ == "__main__":
    main()
