"""
Add missing cite_book_map entries for unresolved cite_hebrew variants.
"""

import sys
sys.stdout.reconfigure(encoding='utf-8')

import psycopg2
from dotenv import load_dotenv
import os

def get_db_connection():
    """Connect to PostgreSQL on port 5433."""
    load_dotenv()
    return psycopg2.connect(
        host='localhost',
        port=5433,
        database='yada',
        user='postgres',
        password=os.getenv('POSTGRES_PASSWORD', 'postgres')
    )

# Mappings of unresolved cite_hebrew to book names
# Format: (cite_hebrew_variant, canonical_book_name)
NEW_MAPPINGS = [
    # 1 Samuel variations
    ("1 Shamuw'fel", "1 Samuel"),
    ("1 Shamuwʿfel", "1 Samuel"),
    ("1 Shamuwʿfel 3", "1 Samuel"),
    ("Shamuw'fel", "1 Samuel"),
    ("Shamuwʿfel", "1 Samuel"),

    # 2 Samuel variations
    ("2 Shamuw'fel", "2 Samuel"),
    ("2 Shamuwʿfel", "2 Samuel"),
    ("Shamuw'fel 2", "2 Samuel"),
    ("Shamuwʿfel 2", "2 Samuel"),

    # Genesis
    ("Bare'syth", "Genesis"),
    ("Bareʿsyth", "Genesis"),
    ("Bare'syth Genesis", "Genesis"),
    ("Bareʿsyth Genesis", "Genesis"),

    # Isaiah
    ("Yasha'yah", "Isaiah"),
    ("Yashaʿyah", "Isaiah"),
    ("6 Yasha'yah", "Isaiah"),
    ("6 Yashaʿyah", "Isaiah"),
    ("Yasha'yahuw", "Isaiah"),
    ("Yashaʿyahuw", "Isaiah"),

    # Daniel
    ("Dany'fel", "Daniel"),
    ("Danyʾfel", "Daniel"),

    # Hosea
    ("Howsha'", "Hosea"),
    ("Howshaʿ", "Hosea"),
    ("Howshaʾ", "Hosea"),

    # Malachi
    ("Mal'aky", "Malachi"),
    ("Malʿaky", "Malachi"),

    # Ezekiel
    ("Yachezq'fel", "Ezekiel"),
    ("Yachezqʾfel", "Ezekiel"),

    # Joshua
    ("Yahowsha'", "Joshua"),
    ("Yahowshaʿ", "Joshua"),
    ("Yahowshaʾ", "Joshua"),

    # Jeremiah
    ("Yirma'yah", "Jeremiah"),
    ("Yirmaʿyah", "Jeremiah"),
    ("Yirmaʾyah", "Jeremiah"),

    # Joel
    ("Yow'fel", "Joel"),
    ("Yowʾfel", "Joel"),
    ("Yow'fel Joel", "Joel"),
    ("Yowʾfel Joel", "Joel"),

    # Amos
    ("'amows", "Amos"),
    ("ʾamows", "Amos"),

    # Leviticus (for Qara' references)
    ("Qara'", "Leviticus"),
    ("Qaraʾ", "Leviticus"),

    # Deuteronomy variations
    ("Dabarym 5: after 15 and before 16 from 1QDeut4", "Deuteronomy"),

    # AYIN modifier versions (these are what's actually in the documents)
    ("1 Shamuwʿel", "1 Samuel"),
    ("2 Shamuwʿel", "2 Samuel"),
    ("Shamuwʿel", "1 Samuel"),  # Without number, assume 1 Samuel
    ("Shamuwʿel 2", "2 Samuel"),
    ("1 Shamuwʿel 3", "1 Samuel"),  # Chapter reference in citation
    ("Danyʿel", "Daniel"),
    ("Yowʿel", "Joel"),
    ("Yachezqʿel", "Ezekiel"),  # With AYIN (though might be ALEPH in some)
]

def main():
    conn = get_db_connection()
    cur = conn.cursor()

    print("Adding missing cite_book_map entries...\n")

    # Get existing cite_book entries
    cur.execute("""
        SELECT cb.cite_book_key, cb.cite_book_common, cb.yah_scroll_key
        FROM yy_cite_book cb
        WHERE cb.yah_scroll_key IS NOT NULL
    """)

    cite_books = {}
    for cite_book_key, cite_book_common, scroll_key in cur.fetchall():
        cite_books[cite_book_common] = (cite_book_key, scroll_key)

    added = 0
    skipped = 0
    errors = []

    for cite_hebrew, book_name in NEW_MAPPINGS:
        # Check if mapping already exists
        cur.execute("""
            SELECT COUNT(*) FROM yy_cite_book_map
            WHERE cite_book_map_hebrew = %s
        """, (cite_hebrew,))

        if cur.fetchone()[0] > 0:
            print(f"  SKIP: '{cite_hebrew}' already exists")
            skipped += 1
            continue

        # Get cite_book_key for the canonical book name
        if book_name not in cite_books:
            errors.append(f"'{book_name}' not found in yy_cite_book")
            print(f"  ERROR: '{cite_hebrew}' → '{book_name}' (book not found)")
            continue

        cite_book_key, scroll_key = cite_books[book_name]

        # Insert the mapping
        try:
            cur.execute("""
                INSERT INTO yy_cite_book_map (cite_book_key, cite_book_map_hebrew)
                VALUES (%s, %s)
            """, (cite_book_key, cite_hebrew))

            print(f"  ✓ Added: '{cite_hebrew}' → {book_name} (cite_book_key={cite_book_key})")
            added += 1
        except Exception as e:
            errors.append(f"'{cite_hebrew}': {e}")
            print(f"  ERROR: '{cite_hebrew}' - {e}")

    conn.commit()
    cur.close()
    conn.close()

    print(f"\n{'='*60}")
    print("SUMMARY")
    print(f"{'='*60}")
    print(f"Mappings added: {added}")
    print(f"Skipped (already exist): {skipped}")
    print(f"Errors: {len(errors)}")

    if errors:
        print("\nErrors:")
        for err in errors:
            print(f"  - {err}")

if __name__ == '__main__':
    main()
