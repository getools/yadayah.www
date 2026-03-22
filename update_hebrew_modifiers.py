"""
Update yy_word and yy_word_translit tables to use proper Hebrew modifiers
(Aleph ʾ and Ayin ʿ) instead of curly quotes, based on refined conversions.
"""

import sys, os
sys.stdout.reconfigure(encoding='utf-8')
from dotenv import load_dotenv
load_dotenv(r'C:\Users\Joe\Work\dev\yada\translations\.env')
import psycopg2

# Load refined conversions
conversions_file = r'C:\Users\Joe\Work\dev\yada\translations\hebrew_modifier_conversions_refined.txt'
conversions = {}

print("Loading refined conversions...")
with open(conversions_file, 'r', encoding='utf-8') as f:
    lines = f.readlines()
    for line in lines[2:]:  # Skip header
        if ', ' in line and not line.startswith('==='):
            parts = line.strip().split(', ', 1)
            if len(parts) == 2:
                orig, conv = parts
                conversions[orig] = conv

print(f"Loaded {len(conversions)} conversions\n")

# Connect to database
conn = psycopg2.connect(
    host=os.getenv('POSTGRES_HOST', 'localhost'),
    port=os.getenv('POSTGRES_PORT', '5432'),
    user=os.getenv('POSTGRES_USER', 'postgres'),
    password=os.getenv('POSTGRES_PASSWORD'),
    dbname=os.getenv('POSTGRES_DB', 'yada')
)
conn.autocommit = False
cur = conn.cursor()

# Normalize curly quotes for matching
LEFT_CURLY = chr(0x2018)
RIGHT_CURLY = chr(0x2019)

def normalize_for_lookup(s):
    """Normalize curly quotes to straight apostrophe for lookup."""
    return s.replace(LEFT_CURLY, "'").replace(RIGHT_CURLY, "'")

# === Update yy_word ===
print("Step 1: Updating yy_word.word_translit...")
cur.execute("SELECT word_key, word_translit FROM yy_word WHERE word_translit != ''")
word_rows = cur.fetchall()

word_updates = []
for word_id, word_translit in word_rows:
    if LEFT_CURLY in word_translit or RIGHT_CURLY in word_translit:
        normalized = normalize_for_lookup(word_translit)
        if normalized in conversions:
            new_yt = conversions[normalized]
            word_updates.append((new_yt, word_id))

print(f"  Found {len(word_updates)} yy_word records to update")

if word_updates:
    cur.executemany("UPDATE yy_word SET word_translit = %s WHERE word_id = %s", word_updates)
    print(f"  Updated {cur.rowcount} yy_word records")

# === Update yy_word_translit ===
print("\nStep 2: Updating yy_word_translit.word_translit_text...")
cur.execute("SELECT word_translit_key, word_translit_text FROM yy_word_translit WHERE word_translit_text != ''")
spelling_rows = cur.fetchall()

spelling_updates = []
for spelling_id, spelling_text in spelling_rows:
    if LEFT_CURLY in spelling_text or RIGHT_CURLY in spelling_text:
        normalized = normalize_for_lookup(spelling_text)
        if normalized in conversions:
            new_text = conversions[normalized]
            spelling_updates.append((new_text, spelling_id))

print(f"  Found {len(spelling_updates)} yy_word_translit records to update")

if spelling_updates:
    cur.executemany("UPDATE yy_word_translit SET word_translit_text = %s WHERE word_translit_key = %s", spelling_updates)
    print(f"  Updated {cur.rowcount} yy_word_translit records")

# Commit database changes
conn.commit()
print("\nDatabase updates committed successfully!")

# Show sample results
print("\nSample updated words:")
cur.execute("""
    SELECT word_translit FROM yy_word
    WHERE word_translit LIKE '%ʾ%' OR word_translit LIKE '%ʿ%'
    ORDER BY word_translit
    LIMIT 10
""")
for row in cur.fetchall():
    print(f"  {row[0]}")

cur.close()
conn.close()

print("\n" + "="*80)
print("DATABASE UPDATE COMPLETE")
print("="*80)
print(f"  yy_word updated: {len(word_updates)} records")
print(f"  yy_word_translit updated: {len(spelling_updates)} records")
print("\nNext: Update Word documents with update_word_documents.py")
