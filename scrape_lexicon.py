"""
Scrape Hebrew words and transliterations from lexiconcordance.com
and update yy_word table in PostgreSQL.
"""

import re
import html
import time
import urllib.request
import psycopg2

BASE_URL = "http://lexiconcordance.com/hebrew/"
USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"

# Pattern: <B>#NNNN</B> <A CLASS="u">HEBREW</A> TRANSLITERATION {PRONUNCIATION}
ENTRY_RE = re.compile(
    r'<B>#(\d{4})</B>\s*<A CLASS="u">([^<]+)</A>\s*(.+?)\s*\{[^}]+\}'
)


def fetch_page(page_num):
    """Fetch a listing page and return its HTML content."""
    url = f"{BASE_URL}{page_num:03d}.html"
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return resp.read().decode("utf-8", errors="replace")
    except Exception as e:
        print(f"  Error fetching {url}: {e}")
        return None


def parse_entries(html_text):
    """Parse all Strong's entries from a page, returning dict of strongs -> (hebrew, transliteration)."""
    entries = {}
    for match in ENTRY_RE.finditer(html_text):
        strongs = match.group(1)  # e.g., "0001"
        hebrew_raw = match.group(2)  # HTML entities for Hebrew
        translit_raw = match.group(3).strip()  # e.g., "'ab" or "'ab (Aramaic)"

        # Decode HTML entities to get actual Hebrew characters
        hebrew = html.unescape(hebrew_raw).strip()

        # Remove "(Aramaic)" or "(Chaldee)" annotations from transliteration
        translit = re.sub(r'\s*\((?:Aramaic|Chaldee)\)\s*', '', translit_raw).strip()

        if hebrew and translit:
            entries[strongs] = (hebrew, translit)

    return entries


def load_cached_entries(cache_file):
    """Load previously scraped entries from cache file."""
    entries = {}
    try:
        with open(cache_file, 'r', encoding='utf-8') as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith('#'):
                    continue
                parts = line.split('\t', 2)
                if len(parts) == 3:
                    entries[parts[0]] = (parts[1], parts[2])
    except FileNotFoundError:
        pass
    return entries


def save_cache(entries, cache_file):
    """Save scraped entries to cache file."""
    with open(cache_file, 'w', encoding='utf-8') as f:
        f.write("# strongs\thebrew\ttransliteration\n")
        for strongs in sorted(entries.keys()):
            hebrew, translit = entries[strongs]
            f.write(f"{strongs}\t{hebrew}\t{translit}\n")


def main():
    import os
    cache_file = os.path.join(os.path.dirname(__file__), 'lexicon_cache.tsv')

    # Try to load from cache first
    all_entries = load_cached_entries(cache_file)
    if all_entries:
        print(f"Loaded {len(all_entries)} entries from cache")
    else:
        # Scrape from website
        total_pages = 175
        print(f"Scraping {total_pages} pages from lexiconcordance.com...")
        for i in range(total_pages):
            page_num = i * 5
            strongs_start = i * 50
            strongs_end = strongs_start + 49

            page_html = fetch_page(page_num)
            if page_html:
                entries = parse_entries(page_html)
                all_entries.update(entries)
                if entries:
                    print(f"  Page {page_num:03d}.html (H{strongs_start:04d}-H{strongs_end:04d}): {len(entries)} entries")
                else:
                    print(f"  Page {page_num:03d}.html (H{strongs_start:04d}-H{strongs_end:04d}): no entries parsed")
            else:
                print(f"  Page {page_num:03d}.html: FAILED")

            time.sleep(0.3)

        print(f"\nTotal entries scraped: {len(all_entries)}")
        save_cache(all_entries, cache_file)
        print(f"Saved cache to {cache_file}")

    # Check for any very long values
    max_heb = max((len(v[0]) for v in all_entries.values()), default=0)
    max_translit = max((len(v[1]) for v in all_entries.values()), default=0)
    print(f"Max hebrew length: {max_heb}, max transliteration length: {max_translit}")

    # Connect to PostgreSQL and update
    conn = psycopg2.connect(
        host="localhost", port=5433, dbname="yada",
        user="postgres", password="yada_password"
    )
    cur = conn.cursor()

    # Disable the word_translit auto-generation trigger so we can set word_translit directly
    cur.execute("ALTER TABLE yy_word DISABLE TRIGGER trg_word_yt_generate")
    print("Disabled trg_word_yt_generate trigger")

    # Get all word_strongs from yy_word
    cur.execute("SELECT word_key, word_strongs FROM yy_word WHERE word_strongs ~ '^\\d+$'")
    db_words = cur.fetchall()
    print(f"Database words with numeric strongs: {len(db_words)}")

    updated = 0
    not_found = 0
    for word_id, strongs in db_words:
        strongs_key = strongs.strip().zfill(4)
        if strongs_key in all_entries:
            hebrew, translit = all_entries[strongs_key]
            cur.execute(
                "UPDATE yy_word SET word_hebrew = %s, word_translit = %s WHERE word_id = %s",
                (hebrew[:250], translit[:250], word_id)
            )
            updated += 1
        else:
            not_found += 1

    # Re-enable the trigger
    cur.execute("ALTER TABLE yy_word ENABLE TRIGGER trg_word_yt_generate")
    print("Re-enabled trg_word_yt_generate trigger")

    conn.commit()
    print(f"\nUpdated: {updated} rows")
    print(f"Not found in lexicon: {not_found} rows")

    # Show a few samples
    cur.execute("""
        SELECT word_strongs, word_hebrew, word_translit
        FROM yy_word
        WHERE word_strongs IN ('0001','0005','0100','1000','3068')
        ORDER BY word_strongs::int
    """)
    print("\nSample updated values:")
    for row in cur.fetchall():
        print(f"  H{row[0].strip()}: hebrew={row[1]}, translit={row[2]}")

    cur.close()
    conn.close()


if __name__ == "__main__":
    main()
