"""
Parse DSS English Bible website (dssenglishbible.com) into dss_scroll / dss_verse tables.

Crawls:
  1. chapterview.htm -> book links ("Book - # Scrolls")
  2. Each book page -> chapter links ("Book #")
  3. Each chapter page -> <div class=WordSection1> content:
     - Scroll headers: bold/italic "Book N from Scroll ScrollCode"
     - Verse numbers: <span class=verse> or inline "N\xa0"
     - Verse text: English translation with markup (italic, color)
"""

import re
import sys
import os
import time
import requests
from bs4 import BeautifulSoup, NavigableString, Tag
import psycopg2
from dotenv import load_dotenv
from urllib.parse import quote, urljoin

load_dotenv()

BASE_URL = "https://dssenglishbible.com/"
DELAY = 0.5  # seconds between requests

DB_HOST = os.environ.get("PG_HOST", "localhost")
DB_PORT = os.environ.get("PG_PORT", "5433")
DB_NAME = "yada"
DB_USER = "postgres"
DB_PASS = os.environ.get("POSTGRES_PASSWORD", "postgres")

BOOK_LINK_RE = re.compile(r'^(.+?)\s*-\s*\d+\s+Scrolls?$')
CHAPTER_LINK_RE = re.compile(r'^(.+?)\s+(\d+)$')
SCROLL_HEADER_RE = re.compile(r'.+?\s+\d+\s+from\s+Scroll\s+(.+)', re.IGNORECASE)
VERSE_NUM_RE = re.compile(r'(\d{1,3})\xa0')

# Map inline color styles to CSS classes
COLOR_CLASS_MAP = {
    '#1f497d': 'dss-eng-partial',
    '#1F497D': 'dss-eng-partial',
    'blue': 'dss-eng-partial',
    '#3282e6': 'dss-eng-partial',
    'green': 'dss-eng-spelling',
    '#00b050': 'dss-eng-spelling',
    '#00B050': 'dss-eng-spelling',
    'red': 'dss-eng-red',
    'purple': 'dss-eng-purple',
}


def fetch(url):
    """Fetch a URL and return BeautifulSoup object."""
    time.sleep(DELAY)
    resp = requests.get(url, timeout=30)
    resp.encoding = 'windows-1252'
    return BeautifulSoup(resp.text, 'html.parser')


def get_book_links(soup):
    """Extract book links from chapterview.htm sidebar."""
    books = []
    for a in soup.find_all('a'):
        text = a.get_text(strip=True)
        m = BOOK_LINK_RE.match(text)
        if m:
            book_name = m.group(1).strip()
            href = a.get('href', '')
            books.append((book_name, href))
    return books


def get_chapter_links(soup, book_name):
    """Extract chapter links from a book page."""
    chapters = []
    seen = set()
    for a in soup.find_all('a'):
        text = a.get_text(strip=True)
        m = CHAPTER_LINK_RE.match(text)
        if m:
            link_book = m.group(1).strip()
            chapter_num = int(m.group(2))
            href = a.get('href', '')
            if chapter_num not in seen:
                seen.add(chapter_num)
                chapters.append((chapter_num, href))
    return sorted(chapters, key=lambda x: x[0])


def is_scroll_header(p_tag):
    """Check if a <p> tag is a scroll header (bold+italic with 'from Scroll')."""
    text = p_tag.get_text()
    if 'from Scroll' not in text:
        return False
    if p_tag.find('b') and p_tag.find('i'):
        return True
    return False


def extract_scroll_code(p_tag):
    """Extract scroll code from a scroll header <p> tag."""
    text = p_tag.get_text()
    m = SCROLL_HEADER_RE.match(text.strip())
    if m:
        return m.group(1).strip()
    return None


def get_color_class(style_str):
    """Extract a CSS color class from an inline style string."""
    if not style_str:
        return None
    m = re.search(r'color:\s*([^;\"\'>\s]+)', style_str)
    if m:
        color = m.group(1).strip()
        return COLOR_CLASS_MAP.get(color)
    return None


def node_to_html(node, inherited_italic=False, inherited_color_class=None,
                 inherited_strikethrough=False, inherited_underline=False):
    """
    Recursively convert a BeautifulSoup node to simplified HTML.
    Returns a list of (verse_num_or_none, html_fragment) tuples.
    When verse_num_or_none is an int, it marks the start of a new verse.
    """
    results = []

    if isinstance(node, NavigableString):
        text = str(node)
        text = text.replace('\n', ' ')

        # Check for verse number patterns in the text
        parts = VERSE_NUM_RE.split(text)
        # parts: [text_before, verse_num, text_after, verse_num, text_after, ...]

        for idx, part in enumerate(parts):
            if idx % 2 == 1:
                # This is a verse number
                results.append((int(part), ''))
            else:
                # This is text content
                if part:
                    frag = part.replace('<', '&lt;').replace('>', '&gt;').replace('&', '&amp;')
                    # Wrap with current styling
                    frag = _wrap_fragment(frag, inherited_italic, inherited_color_class,
                                         inherited_strikethrough, inherited_underline)
                    results.append((None, frag))
        return results

    if isinstance(node, Tag):
        # Skip verse-number spans (they just contain "N\xa0")
        if node.name == 'span' and 'verse' in node.get('class', []):
            text = node.get_text()
            m = re.match(r'(\d{1,3})\xa0?', text.strip())
            if m:
                results.append((int(m.group(1)), ''))
                # There might be trailing text after the number
                trailing = text[m.end():]
                if trailing.strip():
                    frag = trailing.replace('<', '&lt;').replace('>', '&gt;')
                    frag = _wrap_fragment(frag, inherited_italic, inherited_color_class,
                                         inherited_strikethrough, inherited_underline)
                    results.append((None, frag))
                return results

        # Determine styling for this element
        is_italic = inherited_italic or node.name == 'i'
        is_strikethrough = inherited_strikethrough or node.name == 's'
        is_underline = inherited_underline or node.name == 'u'
        color_class = inherited_color_class

        # Check for color in style attribute
        if node.name == 'span' and node.get('style'):
            new_color = get_color_class(node['style'])
            if new_color:
                color_class = new_color

        # Recurse into children
        for child in node.children:
            results.extend(node_to_html(child, is_italic, color_class,
                                        is_strikethrough, is_underline))

    return results


def _wrap_fragment(text, is_italic, color_class,
                   is_strikethrough=False, is_underline=False):
    """Wrap a text fragment with appropriate HTML tags."""
    if not text or text.isspace():
        return text
    frag = text
    # Combine red+strikethrough or red+underline into unified CSS classes
    if is_strikethrough and color_class == 'dss-eng-red':
        frag = f'<span class="dss-eng-missing">{frag}</span>'
    elif is_underline and color_class == 'dss-eng-red':
        frag = f'<span class="dss-eng-differs">{frag}</span>'
    else:
        if color_class:
            frag = f'<span class="{color_class}">{frag}</span>'
        if is_strikethrough:
            frag = f'<s>{frag}</s>'
        if is_underline:
            frag = f'<u>{frag}</u>'
    if is_italic:
        frag = f'<span class="dss-eng-filler">{frag}</span>'
    return frag


def _merge_html(fragments):
    """
    Merge adjacent HTML fragments, collapsing redundant nested tags.
    e.g., '</i><i>' -> '' and '</span><span class="x">' with same class -> merged.
    """
    html = ''.join(fragments)
    # Collapse adjacent identical inline tags
    html = re.sub(r'</s>(\s*)<s>', r'\1', html)
    html = re.sub(r'</u>(\s*)<u>', r'\1', html)
    # Collapse adjacent identical span tags with same class
    html = re.sub(r'<span class="([^"]+)">([^<]*)</span>(\s*)<span class="\1">', r'<span class="\1">\2\3', html)
    # Run again to catch chains of 3+
    html = re.sub(r'<span class="([^"]+)">([^<]*)</span>(\s*)<span class="\1">', r'<span class="\1">\2\3', html)
    # Collapse adjacent inline tags again after span merging
    html = re.sub(r'</s>(\s*)<s>', r'\1', html)
    html = re.sub(r'</u>(\s*)<u>', r'\1', html)
    return html.strip()


def parse_chapter_page(html, book_name, chapter_num, source_code):
    """Parse a chapter page and return list of verse tuples with HTML markup."""
    soup = BeautifulSoup(html, 'html.parser')

    ws = soup.find('div', class_='WordSection1')
    if not ws:
        return []

    verses = []
    current_scroll = None

    for p in ws.find_all('p'):
        if is_scroll_header(p):
            current_scroll = extract_scroll_code(p)
            continue

        if not current_scroll:
            continue

        # Check for empty/whitespace paragraphs
        text = p.get_text()
        if not text.strip() or text.strip() == '\xa0':
            continue

        # Walk the DOM and extract (verse_num, html_fragment) tuples
        raw_parts = node_to_html(p)

        # Group fragments by verse number
        current_verse_num = None
        current_fragments = []

        for verse_num, frag in raw_parts:
            if verse_num is not None:
                # Save previous verse
                if current_verse_num is not None and current_fragments:
                    verse_html = _merge_html(current_fragments)
                    verse_html = re.sub(r'\s+', ' ', verse_html).strip()
                    verse_html = verse_html.replace('\xa0', ' ')
                    if verse_html:
                        verses.append((
                            current_scroll, book_name, chapter_num,
                            current_verse_num, verse_html, source_code
                        ))
                current_verse_num = verse_num
                current_fragments = []
            else:
                if frag:
                    current_fragments.append(frag)

        # Save last verse in paragraph
        if current_verse_num is not None and current_fragments:
            verse_html = _merge_html(current_fragments)
            verse_html = re.sub(r'\s+', ' ', verse_html).strip()
            verse_html = verse_html.replace('\xa0', ' ')
            if verse_html:
                verses.append((
                    current_scroll, book_name, chapter_num,
                    current_verse_num, verse_html, source_code
                ))

    return verses


def parse_chapter_url(url, book_name, chapter_num):
    """Fetch and parse a chapter page."""
    time.sleep(DELAY)
    resp = requests.get(url, timeout=30)
    resp.encoding = 'windows-1252'
    source_code = url.replace('https://', '')
    return parse_chapter_page(resp.text, book_name, chapter_num, source_code)


def insert_to_db(all_verses):
    """Insert verses into dss_scroll and dss_verse tables."""
    conn = psycopg2.connect(host=DB_HOST, port=DB_PORT, dbname=DB_NAME,
                            user=DB_USER, password=DB_PASS)
    cur = conn.cursor()

    # Collect unique scroll codes
    scroll_codes = set(v[0] for v in all_verses)

    # Insert scroll codes
    scroll_key_map = {}
    for sc in sorted(scroll_codes):
        cur.execute(
            "INSERT INTO dss_scroll (scroll_code) VALUES (%s) "
            "ON CONFLICT DO NOTHING RETURNING scroll_key",
            (sc,)
        )
        row = cur.fetchone()
        if row:
            scroll_key_map[sc] = row[0]
        else:
            cur.execute("SELECT scroll_key FROM dss_scroll WHERE scroll_code = %s", (sc,))
            scroll_key_map[sc] = cur.fetchone()[0]

    # Insert verses
    inserted = 0
    for scroll_code, book_name, chapter_num, verse_num, verse_text, source_code in all_verses:
        cur.execute("""
            INSERT INTO dss_verse (scroll_key, scroll_code, book_code_input, book_code_english,
                                   chapter_number, verse_number, verse_text_english, source_code)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        """, (
            scroll_key_map[scroll_code], scroll_code, book_name, book_name,
            chapter_num, verse_num, verse_text, source_code
        ))
        inserted += 1

    conn.commit()
    cur.close()
    conn.close()
    return inserted


def main():
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

    # Step 1: Get book links from chapterview.htm
    print("Fetching chapter view page...")
    soup = fetch(BASE_URL + "chapterview.htm")
    books = get_book_links(soup)
    print(f"Found {len(books)} books")

    all_verses = []

    for book_name, book_href in books:
        # Step 2: Get chapter links from book page
        book_url = urljoin(BASE_URL, book_href)
        print(f"\n{'='*50}")
        print(f"Book: {book_name} ({book_href})")
        book_soup = fetch(book_url)
        chapters = get_chapter_links(book_soup, book_name)
        print(f"  Chapters: {len(chapters)}")

        for chapter_num, chapter_href in chapters:
            # Step 3: Parse chapter page
            chapter_url = urljoin(BASE_URL, chapter_href)
            verses = parse_chapter_url(chapter_url, book_name, chapter_num)
            all_verses.extend(verses)

            scrolls = set(v[0] for v in verses)
            print(f"  Ch {chapter_num}: {len(verses)} verses from {len(scrolls)} scroll(s)")

    print(f"\n{'='*50}")
    print(f"TOTAL: {len(all_verses)} verses across {len(books)} books")

    if all_verses:
        print("Inserting into database...")
        inserted = insert_to_db(all_verses)
        print(f"Inserted: {inserted} records")
    else:
        print("No verses found!")


if __name__ == "__main__":
    main()
