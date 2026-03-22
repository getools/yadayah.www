"""
Populate yy_word_translation mapping table by matching yy_word.word_yt
against italicized word instances found in yy_translation.yy_translation_copy.

Quote normalization: single quotes ('), left curly quotes (\u2018),
and right curly quotes (\u2019) are all treated as equivalent.
"""

import re
import psycopg2

DB_CONFIG = dict(host="localhost", port=5433, dbname="yada",
                 user="postgres", password="yada_password")

# Two-step: find parenthetical groups, then extract italic contents
PAREN_GROUP_RE = re.compile(r'\([^)]+\)')
ITALIC_TAG_RE = re.compile(r'<i[^>]*>([^<]+)</i>', re.IGNORECASE)


def normalize_quotes(s):
    """Replace curly quotes and half-ring modifiers with straight apostrophe."""
    return (s.replace('\u2018', "'").replace('\u2019', "'").replace('\u2032', "'")
             .replace('\u02BE', "'").replace('\u02BF', "'"))


def build_word_lookup(cur):
    """Build a lookup dict: normalized text -> list of word_ids (from word_translit and spellings)."""
    cur.execute("""
        SELECT word_key, word_translit
        FROM yy_word
        WHERE word_translit IS NOT NULL
          AND word_translit <> ''
          AND word_translit NOT LIKE '%%<%%'
          AND word_translit NOT LIKE '%% %%'
    """)
    lookup = {}
    valid_word_ids = set()
    for word_id, word_yt in cur.fetchall():
        key = normalize_quotes(word_yt).lower().strip()
        if key:
            if key not in lookup:
                lookup[key] = []
            lookup[key].append(word_id)
            valid_word_ids.add(word_id)
    # Also include alternate spellings (slash variants) for matching
    cur.execute("SELECT word_id, word_translit_text FROM yy_word_translit")
    for word_id, spelling_text in cur.fetchall():
        if word_id not in valid_word_ids:
            continue
        key = normalize_quotes(spelling_text).lower().strip()
        if key:
            if key not in lookup:
                lookup[key] = []
            if word_id not in lookup[key]:
                lookup[key].append(word_id)
    return lookup


def extract_italic_words(html_text):
    """Extract individual words from <i> tags within parenthetical groups."""
    words = set()
    for paren_match in PAREN_GROUP_RE.finditer(html_text):
        group = paren_match.group(0)
        for italic_match in ITALIC_TAG_RE.finditer(group):
            content = italic_match.group(1).strip()
            if not content:
                continue
            for token in content.split():
                cleaned = token.strip('.,;:!?()-/[]{}"\u2013\u2014\u2015')
                if cleaned:
                    words.add(cleaned)
    return words


def main():
    conn = psycopg2.connect(**DB_CONFIG)
    cur = conn.cursor()

    # Build word_translit lookup
    print("Building word_translit lookup...")
    lookup = build_word_lookup(cur)
    print(f"  {len(lookup)} unique normalized word_translit keys")

    # Load all translations
    print("Loading translations...")
    cur.execute("SELECT yy_translation_key, yy_translation_copy FROM yy_translation")
    translations = cur.fetchall()
    print(f"  {len(translations)} translations loaded")

    # Build mapping pairs
    print("Matching words...")
    pairs = set()  # (word_id, translation_key)
    match_stats = {}  # word_yt -> count of translations matched

    for trans_key, trans_text in translations:
        if not trans_text:
            continue

        italic_words = extract_italic_words(trans_text)
        for word in italic_words:
            normalized = normalize_quotes(word).lower()
            if normalized in lookup:
                for word_id in lookup[normalized]:
                    pairs.add((word_id, trans_key))
                    match_stats[normalized] = match_stats.get(normalized, 0) + 1

    print(f"  {len(pairs)} word-translation pairs found")
    print(f"  {len(match_stats)} unique word_yt values matched")

    # Show top matched words
    top_words = sorted(match_stats.items(), key=lambda x: -x[1])[:20]
    print("\nTop 20 most matched words:")
    for word, count in top_words:
        print(f"  {word}: {count} translations")

    # Clear existing mappings and insert new ones
    print("\nClearing existing yy_word_translation rows...")
    cur.execute("DELETE FROM yy_word_translation")
    print(f"  Deleted existing rows")

    print("Inserting new mappings...")
    batch = list(pairs)
    batch_size = 1000
    inserted = 0
    for i in range(0, len(batch), batch_size):
        chunk = batch[i:i + batch_size]
        args = ','.join(
            cur.mogrify("(%s,%s)", (wid, tid)).decode()
            for wid, tid in chunk
        )
        cur.execute(f"INSERT INTO yy_word_translation (word_key, translation_key) VALUES {args}")
        inserted += len(chunk)
        if inserted % 10000 == 0 or inserted == len(batch):
            print(f"  {inserted}/{len(batch)} rows inserted")

    conn.commit()
    print(f"\nDone! {inserted} mappings inserted into yy_word_translation")

    # Verify
    cur.execute("SELECT COUNT(*) FROM yy_word_translation")
    total = cur.fetchone()[0]
    print(f"Total rows in yy_word_translation: {total}")

    cur.execute("""
        SELECT w.word_translit, COUNT(wt.translation_key) as trans_count
        FROM yy_word_translation wt
        JOIN yy_word w ON w.word_key = wt.word_key
        GROUP BY w.word_translit
        ORDER BY trans_count DESC
        LIMIT 10
    """)
    print("\nTop 10 words by translation count:")
    for row in cur.fetchall():
        print(f"  {row[0]}: {row[1]} translations")

    cur.close()
    conn.close()


if __name__ == "__main__":
    main()
