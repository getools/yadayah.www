"""Check if any words that match yy_word_map_modifier 'old' values still
exist in the documents with single-quote characters (i.e., were missed)."""

import os
import re
import psycopg2
from docx import Document
from docx.oxml.ns import qn

DOCS_DIR = r'C:\Users\Joe\Work\dev\yada\docs'
QUOTE_CHARS = frozenset("'\u2018\u2019\u0060\u00b4\u02bc\u2032\u201b")

TOKEN_RE = re.compile(
    r"[a-zA-Z'\u2018\u2019\u0060\u00b4\u02bc\u2032\u201b\u02be\u02bf\-\}\{]+"
)


def normalize_quotes(s):
    for ch in QUOTE_CHARS:
        if ch != "'":
            s = s.replace(ch, "'")
    return s


def main():
    conn = psycopg2.connect(
        host='localhost', port=5433, dbname='yada',
        user='postgres', password='yada_password'
    )
    cur = conn.cursor()
    cur.execute("SELECT word_map_modifier_old FROM yy_word_map_modifier")
    old_words = set(normalize_quotes(r[0]).lower() for r in cur.fetchall())
    cur.close()
    conn.close()
    print(f"Loaded {len(old_words)} old-value patterns to check against")

    docs = sorted([
        os.path.join(DOCS_DIR, f)
        for f in os.listdir(DOCS_DIR)
        if f.startswith('YY-') and f.endswith('.docx')
        and '_backup' not in f and '_updated' not in f
        and '_fixed' not in f and '_final' not in f
        and not f.startswith('~$')
    ])

    total_remaining = 0
    for fp in docs:
        fname = os.path.basename(fp)
        doc = Document(fp)
        remaining = {}

        for p in doc.element.body.iter(qn('w:p')):
            text = ''
            for r in p.iter(qn('w:r')):
                for t in r.findall(qn('w:t')):
                    text += t.text or ''

            for m in TOKEN_RE.finditer(text):
                token = m.group()
                if not any(ch in token for ch in QUOTE_CHARS):
                    continue
                key = normalize_quotes(token).lower()
                if key in old_words:
                    remaining[key] = remaining.get(key, 0) + 1

        if remaining:
            total_remaining += sum(remaining.values())
            print(f"\n{fname}: {sum(remaining.values())} remaining matches")
            for word, count in sorted(remaining.items(), key=lambda x: -x[1])[:10]:
                try:
                    print(f"  {word}: {count}")
                except UnicodeEncodeError:
                    print(f"  (cannot display): {count}")

    if total_remaining == 0:
        print("\nAll clear! No remaining single-quote words that match mappings.")
    else:
        print(f"\nTotal remaining: {total_remaining}")


if __name__ == '__main__':
    main()
