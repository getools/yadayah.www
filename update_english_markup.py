"""
Re-crawl DSS English Bible and update verse_text_english with HTML markup.
Matches English scroll codes to PDF scroll codes by normalizing:
  cave prefix (e.g. "4Q") + copy letter (e.g. "b")
Falls back to book + chapter + verse only if no scroll match.
"""

import re
import sys
import os
import time
import requests
from bs4 import BeautifulSoup
import psycopg2
from dotenv import load_dotenv
from urllib.parse import urljoin
from parse_dss_english import (
    get_book_links, get_chapter_links, parse_chapter_page,
    BOOK_LINK_RE, CHAPTER_LINK_RE, SCROLL_HEADER_RE, BASE_URL
)

load_dotenv()

DELAY = 0.5

DB_HOST = os.environ.get("PG_HOST", "localhost")
DB_PORT = os.environ.get("PG_PORT", "5433")
DB_NAME = "yada"
DB_USER = "postgres"
DB_PASS = os.environ.get("POSTGRES_PASSWORD", "postgres")


def normalize_english_scroll(scroll_code, book_name):
    """Extract (cave_prefix, copy_letter) from English scroll code.
    Examples: '4Q2 Genesisb' -> ('4Q', 'b'), '1Q1 Genesis' -> ('1Q', ''),
              '4Q8a Genesish2' -> ('4Q', 'h2'), '4Q483' -> ('4Q', '')"""
    # Extract cave prefix (e.g. "4Q", "1Q", "5Q", "11Q")
    m = re.match(r'(\d+Q)', scroll_code)
    if not m:
        # Handle special prefixes like "Mas", "Mur", etc.
        m = re.match(r'(Mas|Mur|Sdeir|XHev|Wadi)', scroll_code, re.IGNORECASE)
        if not m:
            return (scroll_code, '')
        return (m.group(1), '')

    cave = m.group(1)

    # Try to extract copy letter after the book name
    # e.g. "4Q2 Genesisb" -> after "Genesis" we have "b"
    bk_lower = book_name.lower().replace(' ', '')
    sc_lower = scroll_code.lower().replace(' ', '')
    idx = sc_lower.find(bk_lower)
    if idx >= 0:
        after = scroll_code[idx + len(book_name):].replace(' ', '').strip()
        return (cave, after)

    # No book name in scroll code (e.g. "4Q483") -> no copy letter
    return (cave, '')


def normalize_pdf_scroll(scroll_code):
    """Extract (cave_prefix, copy_letter) from PDF scroll code.
    Examples: '4QGen<sup>b</sup>' -> ('4Q', 'b'), '1QGen' -> ('1Q', ''),
              '4QGen<sup>h2</sup>' -> ('4Q', 'h2'), 'MasGen' -> ('Mas', '')"""
    # Extract superscript content
    sup_match = re.search(r'<sup>(.*?)</sup>', scroll_code)
    letter = sup_match.group(1) if sup_match else ''

    # Extract cave prefix
    m = re.match(r'(?:pap)?(\d+Q)', scroll_code)
    if m:
        return (m.group(1), letter)

    # Handle special prefixes
    m = re.match(r'(Mas|Mur|Sdeir|XHev|Wadi)', scroll_code, re.IGNORECASE)
    if m:
        return (m.group(1), letter)

    return (scroll_code.split('<')[0], letter)


def main():
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

    conn = psycopg2.connect(host=DB_HOST, port=DB_PORT, dbname=DB_NAME,
                            user=DB_USER, password=DB_PASS)
    cur = conn.cursor()

    # Build lookup: (book, cave, letter) -> list of verse_keys for each (chapter, verse)
    # and (book, chapter, verse) -> list of verse_keys
    print("Building scroll code index from database...")
    cur.execute("""
        SELECT verse_key, book_code_english, scroll_code, chapter_number, verse_number
        FROM dss_verse
    """)
    # Index: (book, chapter, verse, cave, letter) -> verse_key
    scroll_index = {}
    # Fallback index: (book, chapter, verse) -> [verse_keys]
    verse_index = {}
    for vk, bk, sc, ch, vn in cur.fetchall():
        cave, letter = normalize_pdf_scroll(sc)
        key = (bk, ch, vn, cave, letter)
        scroll_index[key] = vk
        fk = (bk, ch, vn)
        verse_index.setdefault(fk, []).append(vk)

    print(f"  Indexed {len(scroll_index)} scroll-specific entries, {len(verse_index)} verse entries")

    # Step 1: Get book links from chapterview.htm
    print("Fetching chapter view page...")
    time.sleep(DELAY)
    resp = requests.get(BASE_URL + "chapterview.htm", timeout=30)
    resp.encoding = 'windows-1252'
    soup = BeautifulSoup(resp.text, 'html.parser')
    books = get_book_links(soup)
    print(f"Found {len(books)} books")

    total_updated = 0
    total_not_found = 0
    total_fallback = 0

    for book_name, book_href in books:
        book_url = urljoin(BASE_URL, book_href)
        print(f"\n{'='*50}")
        print(f"Book: {book_name}")

        time.sleep(DELAY)
        resp = requests.get(book_url, timeout=30)
        resp.encoding = 'windows-1252'
        book_soup = BeautifulSoup(resp.text, 'html.parser')
        chapters = get_chapter_links(book_soup, book_name)
        print(f"  Chapters: {len(chapters)}")

        for chapter_num, chapter_href in chapters:
            chapter_url = urljoin(BASE_URL, chapter_href)
            time.sleep(DELAY)
            resp = requests.get(chapter_url, timeout=30)
            resp.encoding = 'windows-1252'
            source_code = chapter_url.replace('https://', '')
            verses = parse_chapter_page(resp.text, book_name, chapter_num, source_code)

            ch_updated = 0
            ch_not_found = 0
            ch_fallback = 0

            for scroll_code, bk, ch, vnum, verse_html, src in verses:
                cave, letter = normalize_english_scroll(scroll_code, book_name)
                key = (book_name, chapter_num, vnum, cave, letter)

                if key in scroll_index:
                    vk = scroll_index[key]
                    cur.execute("UPDATE dss_verse SET verse_text_english = %s WHERE verse_key = %s",
                                (verse_html, vk))
                    ch_updated += 1
                else:
                    # Fallback: match by book + chapter + verse only
                    fk = (book_name, chapter_num, vnum)
                    if fk in verse_index:
                        for vk in verse_index[fk]:
                            cur.execute("""
                                UPDATE dss_verse SET verse_text_english = %s
                                WHERE verse_key = %s AND (verse_text_english IS NULL OR verse_text_english = '')
                            """, (verse_html, vk))
                        ch_fallback += 1
                    else:
                        ch_not_found += 1

            conn.commit()
            total_updated += ch_updated
            total_not_found += ch_not_found
            total_fallback += ch_fallback
            print(f"  Ch {chapter_num}: {ch_updated} matched, {ch_fallback} fallback, {ch_not_found} not found")

    print(f"\n{'='*50}")
    print(f"TOTAL: {total_updated} matched, {total_fallback} fallback, {total_not_found} not found")

    cur.close()
    conn.close()


if __name__ == "__main__":
    main()
