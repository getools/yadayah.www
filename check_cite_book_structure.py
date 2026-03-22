"""
Check yy_cite_book table structure and data.
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

conn = get_db_connection()
cur = conn.cursor()

# Get column names
cur.execute("""
    SELECT column_name, data_type
    FROM information_schema.columns
    WHERE table_name = 'yy_cite_book'
    ORDER BY ordinal_position
""")

print("yy_cite_book columns:")
for col_name, data_type in cur.fetchall():
    print(f"  {col_name}: {data_type}")

print("\nyy_cite_book data (first 10 rows):")
cur.execute("SELECT * FROM yy_cite_book LIMIT 10")
rows = cur.fetchall()
col_names = [desc[0] for desc in cur.description]
print(f"  Columns: {', '.join(col_names)}")
for row in rows:
    print(f"  {row}")

print("\n\nyy_cite_book_map columns:")
cur.execute("""
    SELECT column_name, data_type
    FROM information_schema.columns
    WHERE table_name = 'yy_cite_book_map'
    ORDER BY ordinal_position
""")
for col_name, data_type in cur.fetchall():
    print(f"  {col_name}: {data_type}")

print("\nyy_cite_book_map data (first 10 rows):")
cur.execute("SELECT * FROM yy_cite_book_map LIMIT 10")
rows = cur.fetchall()
col_names = [desc[0] for desc in cur.description]
print(f"  Columns: {', '.join(col_names)}")
for row in rows:
    print(f"  {row}")

# Check for scroll names to see if we can map by name
print("\n\nChecking yah_scroll for book names:")
cur.execute("SELECT yah_scroll_key, yah_scroll_name FROM yah_scroll ORDER BY yah_scroll_key LIMIT 20")
for scroll_key, scroll_name in cur.fetchall():
    print(f"  {scroll_key}: {scroll_name}")

cur.close()
conn.close()
