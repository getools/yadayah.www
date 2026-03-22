"""
Parse individual Hebrew words from dss_verse.verse_text_hebrew
and insert into dss_verse_word_hebrew.
"""
import re
import psycopg2

DB = dict(host='localhost', port=5433, dbname='yada', user='postgres', password='yada_password')

def strip_html(text):
    """Remove HTML tags and return plain text."""
    return re.sub(r'<[^>]+>', '', text)

def extract_hebrew_words(text):
    """Strip HTML, remove non-Hebrew chars, return list of Hebrew words."""
    plain = strip_html(text)
    # Remove brackets, punctuation, digits, Latin chars
    plain = re.sub(r'[^\u0590-\u05FF\s]', '', plain)
    words = plain.split()
    return [w for w in words if w]

def main():
    conn = psycopg2.connect(**DB)
    cur = conn.cursor()

    # Fetch all verses with Hebrew text
    cur.execute("SELECT verse_key, verse_text_hebrew FROM dss_verse WHERE verse_text_hebrew IS NOT NULL AND verse_text_hebrew != ''")
    rows = cur.fetchall()
    print(f"Processing {len(rows)} verses...")

    total_words = 0
    batch = []
    for verse_key, hebrew_html in rows:
        words = extract_hebrew_words(hebrew_html)
        for word in words:
            batch.append((verse_key, word))
            total_words += 1

        # Insert in batches of 5000
        if len(batch) >= 5000:
            cur.executemany(
                "INSERT INTO dss_verse_word_hebrew (verse_key, verse_word_hebrew_text) VALUES (%s, %s)",
                batch
            )
            conn.commit()
            print(f"  Inserted {total_words} words so far...")
            batch = []

    # Final batch
    if batch:
        cur.executemany(
            "INSERT INTO dss_verse_word_hebrew (verse_key, verse_word_hebrew_text) VALUES (%s, %s)",
            batch
        )
        conn.commit()

    print(f"Done. Total words inserted: {total_words}")

    # Show stats
    cur.execute("SELECT COUNT(*) FROM dss_verse_word_hebrew")
    print(f"Rows in dss_verse_word_hebrew: {cur.fetchone()[0]}")
    cur.execute("SELECT COUNT(DISTINCT verse_word_hebrew_text) FROM dss_verse_word_hebrew")
    print(f"Distinct Hebrew words: {cur.fetchone()[0]}")

    cur.close()
    conn.close()

if __name__ == '__main__':
    main()
