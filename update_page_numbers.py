#!/usr/bin/env python3
"""
Update translation_page values using corrected page number extraction.

Changes from original:
- COM VBScript uses Information(1) (footer page number) instead of Information(3) (physical page)
- XML fallback: no longer double-counts explicit page breaks
- XML fallback: no longer subtracts 6 from page numbers

Re-extracts translations to get paragraph indices, gets corrected page numbers,
then updates existing DB rows by matching (translation_book, translation_text_word).
"""

import sys
import os
import logging
from pathlib import Path

# Add parent dir so we can import from parse_word_translations
sys.path.insert(0, str(Path(__file__).parent))

from parse_word_translations import (
    extract_translations_from_doc,
    get_all_page_numbers,
    setup_logging,
    DEFAULT_DIRECTORY,
)

try:
    import psycopg2
except ImportError:
    print("ERROR: psycopg2 not installed")
    sys.exit(1)

try:
    from dotenv import load_dotenv
except ImportError:
    print("ERROR: python-dotenv not installed")
    sys.exit(1)


def main():
    setup_logging(verbose=False)
    load_dotenv()

    logging.info("Page Number Update Script")
    logging.info("=" * 60)
    logging.info("Using Information(1) for footer page numbers via COM")
    logging.info("XML fallback: lastRenderedPageBreak + pgNumType restarts (no -6 hack)")

    # Connect to yada database
    conn = psycopg2.connect(
        host=os.getenv('POSTGRES_HOST', 'localhost'),
        port=os.getenv('POSTGRES_PORT', '5432'),
        user=os.getenv('POSTGRES_USER', 'postgres'),
        password=os.getenv('POSTGRES_PASSWORD'),
        database=os.getenv('POSTGRES_DB', 'yada'),
    )
    logging.info("Connected to database")

    # Get distinct books from DB
    cur = conn.cursor()
    cur.execute("SELECT DISTINCT translation_book FROM translation ORDER BY translation_book")
    books = [row[0] for row in cur.fetchall()]
    logging.info(f"Found {len(books)} distinct books in database")

    # Find matching .docx files
    doc_dir = Path(DEFAULT_DIRECTORY)
    docx_files = {f.stem: f for f in doc_dir.glob("YY*.docx") if not f.name.startswith("~$")}

    # Phase 1: Extract translations from each document to get paragraph indices
    all_translations = {}  # doc_path -> list of translations (with _para_idx)
    for book_name in books:
        if book_name not in docx_files:
            logging.warning(f"  No .docx file found for book: {book_name}")
            continue
        doc_path = docx_files[book_name]
        logging.info(f"  Extracting translations from {doc_path.name}...")
        translations = extract_translations_from_doc(doc_path)
        if translations:
            all_translations[doc_path] = translations
            logging.info(f"    Found {len(translations)} translations")

    # Phase 2: Build doc_para_map and get page numbers
    doc_para_map = {}
    for doc_path, translations in all_translations.items():
        para_indices = list(set(t["_para_idx"] for t in translations))
        doc_para_map[str(doc_path.absolute())] = para_indices

    logging.info(f"\nGetting page numbers for {len(doc_para_map)} documents...")
    all_page_numbers = get_all_page_numbers(doc_para_map)

    # Phase 3: Apply page numbers to translations and update DB
    total_updated = 0
    total_matched = 0
    total_skipped = 0

    for doc_path, translations in all_translations.items():
        abs_path = str(doc_path.absolute())
        page_map = all_page_numbers.get(abs_path, {})
        book_name = doc_path.stem

        if not page_map:
            logging.warning(f"  No page numbers for {book_name}")
            continue

        for t in translations:
            para_idx = t["_para_idx"]
            new_page = page_map.get(para_idx)
            if new_page is None:
                total_skipped += 1
                continue

            text_word = t["text_word"]

            # Update DB row by matching book + text_word
            cur.execute("""
                UPDATE translation
                SET translation_page = %s
                WHERE translation_book = %s
                  AND translation_text_word = %s
                  AND (translation_page IS DISTINCT FROM %s)
            """, (new_page, book_name, text_word, new_page))

            if cur.rowcount > 0:
                total_updated += cur.rowcount
            total_matched += 1

        conn.commit()
        logging.info(f"  {book_name}: {len(translations)} translations processed")

    logging.info(f"\n{'=' * 60}")
    logging.info(f"SUMMARY")
    logging.info(f"{'=' * 60}")
    logging.info(f"Translations matched: {total_matched}")
    logging.info(f"Pages updated: {total_updated}")
    logging.info(f"Skipped (no page): {total_skipped}")

    # Show sample of changes for verification
    cur.execute("""
        SELECT translation_id, translation_book, translation_page,
               LEFT(translation_text_word, 80) as text_preview
        FROM translation
        WHERE translation_book = 'YY-s01v01-An Intro to God-Dabarym-Words'
          AND translation_text_word LIKE '%wa hayah%achar%maweth%'
        LIMIT 5
    """)
    rows = cur.fetchall()
    if rows:
        logging.info(f"\nVerification - s01v01 'wa hayah' translation:")
        for row in rows:
            logging.info(f"  ID={row[0]}, page={row[2]}, text={row[3]}")

    cur.close()
    conn.close()
    logging.info("\nDone.")


if __name__ == "__main__":
    main()
