#!/usr/bin/env python3
"""Update yy_word_translit.word_translit_count_yy with actual occurrence counts
from yy_translation text. Counts each spelling as a whole word within <i> tags."""

import re
import sys
from collections import Counter

import psycopg2

DB_CONFIG = {
    'host': 'localhost',
    'port': 5433,
    'dbname': 'yada',
    'user': 'postgres',
    'password': 'yada_password'
}

# Pattern to extract text inside <i>...</i> tags
ITALIC_RE = re.compile(r'<i>(.*?)</i>', re.DOTALL)

# Tokenize: split on anything that's not a letter or apostrophe
TOKEN_RE = re.compile(r"[a-zA-Z']+")


def main():
    conn = psycopg2.connect(**DB_CONFIG)
    cur = conn.cursor()

    # Load all translation texts
    cur.execute("SELECT yy_translation_copy FROM yy_translation")
    translations = [row[0] for row in cur.fetchall()]
    print(f"Loaded {len(translations)} translations")

    # Extract all words from italic text and count them
    word_counts = Counter()
    for text in translations:
        # Find all italic spans
        for match in ITALIC_RE.finditer(text):
            italic_text = match.group(1)
            # Strip any nested HTML tags within italic text
            clean = re.sub(r'<[^>]+>', '', italic_text)
            # Tokenize into words
            tokens = TOKEN_RE.findall(clean.lower())
            word_counts.update(tokens)

    print(f"Counted {len(word_counts)} unique words, {sum(word_counts.values())} total occurrences")

    # Load spellings
    cur.execute("SELECT word_translit_key, word_translit_text, word_translit_count_yy FROM yy_word_translit")
    spellings = cur.fetchall()
    print(f"Loaded {len(spellings)} spellings")

    # Update counts
    updated = 0
    for spelling_id, text, old_count in spellings:
        lookup = text.lower().strip()
        new_count = word_counts.get(lookup, 0)

        if new_count != (old_count or 0):
            cur.execute(
                "UPDATE yy_word_translit SET word_translit_count_yy = %s WHERE word_translit_key = %s",
                (new_count, spelling_id)
            )
            updated += 1

    conn.commit()
    print(f"Updated {updated} spelling counts")

    # Show top 10
    cur.execute("SELECT word_translit_text, word_translit_count_yy FROM yy_word_translit ORDER BY word_translit_count_yy DESC LIMIT 10")
    print("\nTop 10 spellings:")
    for text, count in cur.fetchall():
        print(f"  {text}: {count}")

    cur.close()
    conn.close()


if __name__ == '__main__':
    main()
