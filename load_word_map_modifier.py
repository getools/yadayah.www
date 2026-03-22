"""Load yy_word_map_modifier table from hebrew_modifier_conversions_refined.txt"""
import psycopg2

def main():
    mappings = []
    in_data = False
    with open(r'C:\Users\Joe\Work\dev\yada\translations\hebrew_modifier_conversions_refined.txt', 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            # Skip header line
            if line.startswith('Original'):
                in_data = True
                continue
            if line.startswith('-' * 10):
                continue
            # Stop at analysis section
            if line.startswith('=' * 10):
                break
            if not in_data:
                continue
            if not line:
                continue
            # Parse "old, new" format
            parts = line.split(', ', 1)
            if len(parts) == 2:
                old_val = parts[0].strip()
                new_val = parts[1].strip()
                if old_val and new_val and old_val != new_val:
                    mappings.append((old_val, new_val))

    print(f"Parsed {len(mappings)} mappings")

    conn = psycopg2.connect(host='localhost', port=5433, dbname='yada', user='postgres', password='yada_password')
    cur = conn.cursor()

    cur.executemany(
        "INSERT INTO yy_word_map_modifier (word_map_modifier_old, word_map_modifier_new) VALUES (%s, %s) ON CONFLICT (word_map_modifier_old) DO NOTHING",
        mappings
    )

    conn.commit()
    cur.execute("SELECT count(*) FROM yy_word_map_modifier")
    count = cur.fetchone()[0]
    print(f"Inserted {count} rows into yy_word_map_modifier")

    # Show a few samples
    cur.execute("SELECT word_map_modifier_old, word_map_modifier_new FROM yy_word_map_modifier ORDER BY word_map_modifier_key LIMIT 10")
    for row in cur.fetchall():
        print(f"  {row[0]} -> {row[1]}")

    cur.close()
    conn.close()

if __name__ == '__main__':
    main()
