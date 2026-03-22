import psycopg2, unicodedata, sys
sys.stdout.reconfigure(encoding='utf-8')
conn = psycopg2.connect(host='localhost', port=5433, dbname='yada', user='postgres', password='yada_password')
cur = conn.cursor()
cur.execute("SELECT DISTINCT unnest(regexp_split_to_array(translit_hebrew, '')) FROM yy_translit WHERE translit_hebrew IS NOT NULL AND length(translit_hebrew) > 0")
chars = sorted(set(row[0] for row in cur.fetchall()), key=lambda c: ord(c))
for ch in chars:
    cp = ord(ch)
    name = unicodedata.name(ch, '?')
    print(f'{ch}  U+{cp:04X}  {name}')
cur.close()
conn.close()
