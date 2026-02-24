"""
Import Hebrew glossary from Excel into yy_word and yy_word_spelling.
Reads from the AllExport sheet in the .xlsx workbook.
Matches entries against existing yy_word rows by transliteration.
Updates matched rows with Strong's, Hebrew, definition, and flags.
Inserts unmatched entries as new words with source_code='kirk'.
"""
import os
import re
import sys
import psycopg2
import openpyxl
from dotenv import load_dotenv

XLSX_PATH = r'C:\Users\Joe\Work\dev\yada\assets\Yada Yahowah-Hebrew Glossary Nouns and Verbs.xlsx'
SHEET_NAME = 'AllExport'


def normalize_quotes(s):
    return s.replace('\u2018', "'").replace('\u2019', "'").replace('\u2032', "'")


def strip_for_match(s):
    """Normalize for matching: lowercase, remove apostrophes/quotes/spaces."""
    if not s:
        return ''
    s = normalize_quotes(s).lower().strip()
    s = re.sub(r"['\"\u201c\u201d\s]", '', s)
    return s


def consonants_only(s):
    """Strip vowels (aeiou) from transliteration to get consonant skeleton."""
    return re.sub(r'[aeiou]', '', s)


def to_gender(row):
    m = bool(row.get('word_flag_gender_m'))
    f = bool(row.get('word_flag_gender_f'))
    if m and f:
        return 'B'
    if m:
        return 'M'
    if f:
        return 'F'
    return None


def to_bool(val):
    if val and val == 1:
        return True
    return None


def load_xlsx_entries():
    """Load entries from AllExport sheet. Returns list of dicts."""
    wb = openpyxl.load_workbook(XLSX_PATH, read_only=True, data_only=True)
    ws = wb[SHEET_NAME]

    header = None
    entries = []
    for row_idx, row in enumerate(ws.iter_rows(values_only=True)):
        if row_idx == 0:
            header = [c.strip() if c else None for c in row]
            continue
        row_dict = {header[i]: row[i] for i in range(len(header)) if header[i]}
        if not row_dict.get('word_id'):
            continue
        entries.append(row_dict)

    wb.close()
    return entries


def main():
    sys.stdout.reconfigure(encoding='utf-8')
    load_dotenv()
    conn = psycopg2.connect(
        host=os.getenv('POSTGRES_HOST', 'localhost'),
        port=os.getenv('POSTGRES_PORT', '5433'),
        user=os.getenv('POSTGRES_USER', 'postgres'),
        password=os.getenv('POSTGRES_PASSWORD', 'yada_password'),
        dbname=os.getenv('POSTGRES_DB', 'yada')
    )
    cur = conn.cursor()

    # --- Clean up previous import ---
    print("Cleaning up previous kirk import...")
    cur.execute("DELETE FROM yy_word_spelling WHERE word_id IN (SELECT word_id FROM yy_word WHERE word_source_code = 'kirk')")
    kirk_sp_del = cur.rowcount
    cur.execute("DELETE FROM yy_word WHERE word_source_code = 'kirk'")
    kirk_del = cur.rowcount
    # Remove kirk-added spellings from matched yy rows (count_yy=0 and not the primary spelling)
    cur.execute("DELETE FROM yy_word_spelling WHERE word_spelling_count_yy = 0 AND word_spelling_sort > 0")
    extra_sp_del = cur.rowcount
    # Clear corrupted Hebrew ('??' values) from previous CSV import
    cur.execute("UPDATE yy_word SET word_hebrew = '' WHERE word_hebrew ~ '^\\?+$'")
    hebrew_cleared = cur.rowcount
    conn.commit()
    print(f"  Deleted {kirk_del} kirk rows, {kirk_sp_del} kirk spellings, {extra_sp_del} extra spellings")
    print(f"  Cleared {hebrew_cleared} corrupted Hebrew values")
    print()

    # --- Load existing yy_word (now clean) ---
    cur.execute("SELECT word_id, word_yt FROM yy_word")
    db_rows = cur.fetchall()

    # Build indexes for matching
    exact_index = {}   # stripped_yt -> (word_id, original_yt)
    skel_index = {}    # consonant_skeleton -> [(word_id, stripped_yt, original_yt)]

    for wid, wyt in db_rows:
        if not wyt:
            continue
        stripped = strip_for_match(wyt)
        if stripped and stripped not in exact_index:
            exact_index[stripped] = (wid, wyt)
        skel = consonants_only(stripped)
        if skel:
            skel_index.setdefault(skel, []).append((wid, stripped, wyt))

    # --- Load Excel ---
    entries = load_xlsx_entries()

    print(f"Existing yy_word: {len(db_rows)} rows ({len(exact_index)} unique normalized)")
    print(f"Excel entries: {len(entries)}")
    print()

    # --- Match entries to existing DB rows ---
    matched = []       # (db_word_id, row, match_type)
    unmatched = []     # row
    used_ids = set()

    for row in entries:
        xlsx_yt = str(row.get('word_yt') or '').strip()
        if not xlsx_yt:
            unmatched.append(row)
            continue

        stripped = strip_for_match(xlsx_yt)
        reversed_yt = stripped[::-1]  # reverse L-to-R reading to get R-to-L

        # Strategy 1: reversed exact match
        if reversed_yt in exact_index:
            wid, _ = exact_index[reversed_yt]
            if wid not in used_ids:
                matched.append((wid, row, 'reversed-exact'))
                used_ids.add(wid)
                continue

        # Strategy 2: unreversed exact match (in case some are already R-to-L)
        if stripped in exact_index:
            wid, _ = exact_index[stripped]
            if wid not in used_ids:
                matched.append((wid, row, 'exact'))
                used_ids.add(wid)
                continue

        # Strategy 3: consonant skeleton of reversed
        skel = consonants_only(reversed_yt)
        if skel and len(skel) >= 2 and skel in skel_index:
            candidates = [(wid, s, yt) for wid, s, yt in skel_index[skel]
                          if wid not in used_ids]
            if len(candidates) == 1:
                wid, _, _ = candidates[0]
                matched.append((wid, row, 'skeleton'))
                used_ids.add(wid)
                continue

        unmatched.append(row)

    # --- Report matching results ---
    print("=== MATCHING RESULTS ===")
    print(f"Matched:   {len(matched)}")
    print(f"Unmatched: {len(unmatched)}")

    by_type = {}
    for _, _, mt in matched:
        by_type[mt] = by_type.get(mt, 0) + 1
    for mt, cnt in sorted(by_type.items()):
        print(f"  {mt}: {cnt}")
    print()

    print("First 30 matches:")
    for wid, row, mt in matched[:30]:
        cur.execute("SELECT word_yt FROM yy_word WHERE word_id = %s", (wid,))
        db_yt = cur.fetchone()[0]
        hebrew = row.get('word_hebrew') or ''
        strongs = row.get('word_strongs')
        print(f"  DB#{wid:>5d} '{db_yt}' <- xlsx#{row['word_id']} "
              f"yt='{row.get('word_yt','')}' hebrew={hebrew} "
              f"H{strongs} [{mt}]")
    print()

    print(f"First 30 unmatched:")
    for row in unmatched[:30]:
        hebrew = row.get('word_hebrew') or ''
        strongs = row.get('word_strongs')
        print(f"  xlsx#{row['word_id']} yt='{row.get('word_yt','')}' "
              f"hebrew={hebrew} H{strongs}")
    print()

    # --- UPDATE matched rows ---
    print(f"Updating {len(matched)} matched rows...")
    for wid, row, mt in matched:
        strongs = row.get('word_strongs')
        if strongs is not None:
            strongs = str(strongs).strip()[:4].ljust(4)
        else:
            strongs = None

        cur.execute("""
            UPDATE yy_word SET
                word_strongs = COALESCE(%s, word_strongs),
                word_hebrew = COALESCE(%s, word_hebrew),
                word_gender = COALESCE(%s, word_gender),
                word_flag_plural = COALESCE(%s, word_flag_plural),
                word_flag_noun = COALESCE(%s, word_flag_noun),
                word_flag_verb = COALESCE(%s, word_flag_verb),
                word_flag_adjective = COALESCE(%s, word_flag_adjective),
                word_flag_adverb = COALESCE(%s, word_flag_adverb),
                word_flag_preposition = COALESCE(%s, word_flag_preposition),
                word_flag_conjunction = COALESCE(%s, word_flag_conjunction),
                word_flag_subst = COALESCE(%s, word_flag_subst),
                word_definition_kirk = COALESCE(%s, word_definition_kirk)
            WHERE word_id = %s
        """, (
            strongs,
            row.get('word_hebrew'),
            to_gender(row),
            to_bool(row.get('word_flag_plural')),
            to_bool(row.get('word_flag_noun')),
            to_bool(row.get('word_flag_verb')),
            to_bool(row.get('word_flag_adjective')),
            to_bool(row.get('word_flag_adverb')),
            to_bool(row.get('word_flag_preposition')),
            to_bool(row.get('word_flag_conjunction')),
            to_bool(row.get('word_flag_subst')),
            row.get('word_definition'),
            wid
        ))
    conn.commit()

    # --- INSERT unmatched as new rows ---
    print(f"Inserting {len(unmatched)} new rows...")
    new_ids = []
    for row in unmatched:
        strongs = row.get('word_strongs')
        if strongs is not None:
            strongs = str(strongs).strip()[:4].ljust(4)
        else:
            strongs = None

        cur.execute("""
            INSERT INTO yy_word (
                word_strongs, word_hebrew, word_yt, word_gender,
                word_flag_plural, word_flag_noun, word_flag_verb, word_flag_adjective,
                word_flag_adverb, word_flag_preposition, word_flag_conjunction, word_flag_subst,
                word_definition_kirk, word_count_yy, word_active_flag, word_source_code
            ) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,0,true,'kirk')
            RETURNING word_id
        """, (
            strongs,
            row.get('word_hebrew'),
            str(row.get('word_yt') or '').lower(),
            to_gender(row),
            to_bool(row.get('word_flag_plural')),
            to_bool(row.get('word_flag_noun')),
            to_bool(row.get('word_flag_verb')),
            to_bool(row.get('word_flag_adjective')),
            to_bool(row.get('word_flag_adverb')),
            to_bool(row.get('word_flag_preposition')),
            to_bool(row.get('word_flag_conjunction')),
            to_bool(row.get('word_flag_subst')),
            row.get('word_definition'),
        ))
        new_ids.append((cur.fetchone()[0], row))
    conn.commit()

    # --- Add yy_word_spelling entries ---
    print("Adding yy_word_spelling entries...")
    sp_count = 0

    # For matched: add word_yt as additional spelling if not already present
    for wid, row, mt in matched:
        yt = str(row.get('word_yt') or '').strip().lower()
        if not yt:
            continue
        cur.execute(
            "SELECT 1 FROM yy_word_spelling WHERE word_id = %s AND word_spelling_text = %s",
            (wid, yt)
        )
        if not cur.fetchone():
            cur.execute("""
                INSERT INTO yy_word_spelling (word_id, word_spelling_text, word_spelling_sort, word_spelling_count_yy)
                VALUES (%s, %s,
                    (SELECT COALESCE(MAX(word_spelling_sort), -1) + 1 FROM yy_word_spelling WHERE word_id = %s),
                    0)
            """, (wid, yt, wid))
            sp_count += 1

    # For new rows: add word_yt as primary spelling
    for new_id, row in new_ids:
        yt = str(row.get('word_yt') or '').strip().lower()
        if yt:
            cur.execute("""
                INSERT INTO yy_word_spelling (word_id, word_spelling_text, word_spelling_sort, word_spelling_count_yy)
                VALUES (%s, %s, 0, 0)
            """, (new_id, yt))
            sp_count += 1

    conn.commit()
    print(f"Added {sp_count} yy_word_spelling entries")

    # --- Reset sequence ---
    cur.execute("SELECT setval('yy_word_word_id_seq', (SELECT MAX(word_id) FROM yy_word))")
    conn.commit()

    # --- Final summary ---
    print()
    print("=== FINAL COUNTS ===")
    for table in ['yy_word', 'yy_word_spelling', 'yy_word_translation']:
        cur.execute(f"SELECT COUNT(*) FROM {table}")
        print(f"  {table}: {cur.fetchone()[0]}")

    print()
    print("Sample updated rows (with Hebrew):")
    cur.execute("""SELECT word_id, word_yt, word_strongs, word_hebrew, word_gender,
                          LEFT(word_definition_kirk, 60)
        FROM yy_word WHERE word_hebrew IS NOT NULL AND word_source_code = 'yy'
        ORDER BY word_id LIMIT 15""")
    for wid, yt, strongs, hebrew, gender, defn in cur.fetchall():
        s = strongs.strip() if strongs else ''
        print(f"  {wid:>5d} {yt:<20s} H{s:<5s} {hebrew or '':<6s} {gender or '-'} {defn or ''}")

    print()
    print("Sample new rows (kirk source):")
    cur.execute("""SELECT word_id, word_yt, word_strongs, word_hebrew, word_gender,
                          LEFT(word_definition_kirk, 60)
        FROM yy_word WHERE word_source_code = 'kirk'
        ORDER BY word_id LIMIT 15""")
    for wid, yt, strongs, hebrew, gender, defn in cur.fetchall():
        s = strongs.strip() if strongs else ''
        print(f"  {wid:>5d} {yt:<20s} H{s:<5s} {hebrew or '':<6s} {gender or '-'} {defn or ''}")

    cur.close()
    conn.close()
    print("\nDone.")


if __name__ == '__main__':
    main()
