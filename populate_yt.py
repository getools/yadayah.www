"""Populate dss_verse.verse_text_yt by mapping Hebrew chars to YT letters."""
import psycopg2
import re
from dotenv import dotenv_values

env = dotenv_values('.env')

conn = psycopg2.connect(
    host=env['POSTGRES_HOST'],
    port=int(env['POSTGRES_PORT']),
    dbname=env['POSTGRES_DB'],
    user=env['POSTGRES_USER'],
    password=env['POSTGRES_PASSWORD']
)

cur = conn.cursor()

# Load letter mappings from yy_letter
cur.execute("SELECT letter_hebrew, letter_yt FROM yy_letter WHERE letter_hebrew IS NOT NULL AND letter_hebrew <> ''")
letter_map = dict(cur.fetchall())
print(f"Loaded {len(letter_map)} letter mappings")

# Fetch all Hebrew verses
cur.execute("SELECT verse_key, verse_text_hebrew FROM dss_verse WHERE verse_text_hebrew IS NOT NULL AND verse_text_hebrew <> ''")
rows = cur.fetchall()
print(f"Processing {len(rows)} verses...")

# Split HTML into tags and text segments, only map text segments
tag_pattern = re.compile(r'(<[^>]+>)')

updated = 0
for verse_key, hebrew_text in rows:
    parts = tag_pattern.split(hebrew_text)
    yt_parts = []
    for part in parts:
        if part.startswith('<'):
            # HTML tag — keep as-is
            yt_parts.append(part)
        else:
            # Text content — map Hebrew chars to YT
            mapped = []
            for ch in part:
                mapped.append(letter_map.get(ch, ch))
            yt_parts.append(''.join(mapped))
    yt_text = ''.join(yt_parts)
    cur.execute("UPDATE dss_verse SET verse_text_yt = %s WHERE verse_key = %s", (yt_text, verse_key))
    updated += 1

conn.commit()
print(f"Updated {updated} verses with verse_text_yt")
cur.close()
conn.close()
