"""
Parse all YY Word documents for words containing half-ring modifiers
(ʾ U+02BE right half ring / aleph, ʿ U+02BF left half ring / ayin)
and insert them into _translit_modifier_map.
"""

import re
import sys
from pathlib import Path
from docx import Document
from parse_word_translations import build_footer_page_map
import psycopg2
from dotenv import load_dotenv
import os

load_dotenv()

DOCS_DIR = Path(r'C:\Users\Joe\Work\dev\yada\docs')
MODIFIER_PATTERN = re.compile(r'[\u02BE\u02BF]')
# Match a "word" that contains at least one half-ring modifier
# Words can contain letters, hyphens, apostrophes, and the modifiers themselves
WORD_PATTERN = re.compile(r"[a-zA-Z\u02BE\u02BF'''\-]+")
FILENAME_PATTERN = re.compile(r'YY-s(\d{2})v(\d{2})')


def get_db():
    return psycopg2.connect(
        host='localhost',
        port=5433,
        dbname='yada',
        user='postgres',
        password='yada_password'
    )


def extract_modifier_words(text):
    """Extract all words from text that contain a half-ring modifier."""
    words = []
    for match in WORD_PATTERN.finditer(text):
        word = match.group()
        if MODIFIER_PATTERN.search(word):
            words.append(word)
    return words


def parse_document(doc_path, verbose=False):
    """Parse a single document for modifier words with page numbers."""
    filename = doc_path.name
    m = FILENAME_PATTERN.search(filename)
    if not m:
        print(f"  Skipping {filename} - no series/volume in filename")
        return []

    series = int(m.group(1))
    volume = int(m.group(2))

    if verbose:
        print(f"  Parsing {filename} (s{series:02d}v{volume:02d})...")

    # Get page map
    page_map, content_start_idx, last_page = build_footer_page_map(doc_path)

    # Open document and iterate paragraphs
    doc = Document(str(doc_path))
    results = []

    for idx, para in enumerate(doc.paragraphs):
        text = para.text
        if not text:
            continue

        modifier_words = extract_modifier_words(text)
        if not modifier_words:
            continue

        # TOC and front matter (before content start) = page 0
        # Content pages use footer page number
        if idx < content_start_idx:
            page = 0
        else:
            page = page_map.get(idx, None)

        for word in modifier_words:
            results.append({
                'translit': word,
                'filename': filename,
                'series': series,
                'volume': volume,
                'page': page
            })

    if verbose:
        print(f"    Found {len(results)} modifier word occurrences")

    return results


def main():
    verbose = '--verbose' in sys.argv

    doc_files = sorted(DOCS_DIR.glob('YY-*.docx'))
    print(f"Found {len(doc_files)} YY documents")

    conn = get_db()
    cur = conn.cursor()

    # Truncate existing data
    cur.execute("TRUNCATE TABLE _translit_modifier_map")
    conn.commit()

    total = 0
    for doc_path in doc_files:
        results = parse_document(doc_path, verbose=verbose)
        if results:
            args = [(r['translit'], r['filename'], r['series'], r['volume'], r['page'])
                    for r in results]
            cur.executemany(
                "INSERT INTO _translit_modifier_map (translit, filename, series, volume, page) "
                "VALUES (%s, %s, %s, %s, %s)",
                args
            )
            conn.commit()
            total += len(results)

    print(f"\nDone. Inserted {total} rows into _translit_modifier_map")

    # Summary
    cur.execute("SELECT COUNT(DISTINCT translit) FROM _translit_modifier_map")
    distinct = cur.fetchone()[0]
    print(f"Distinct modifier words: {distinct}")

    cur.close()
    conn.close()


if __name__ == '__main__':
    main()
