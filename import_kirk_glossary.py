"""
Kirk Glossary Import

Imports Hebrew glossary data from the Kirk Excel spreadsheet:
  1. Loads XLSX into yy_word_import (source='kirk'), replacing existing kirk rows
  2. Creates yy_word records (source_code='kirk') from kirk import rows
  3. Creates yy_word_translit entries for each transliteration variant

Only touches kirk records. Non-kirk yy_word_import rows are never affected.

Source: Yada Yahowah-Hebrew Glossary Nouns and Verbs.xlsx (AllExport sheet)

Usage:
    python -X utf8 import_kirk_glossary.py [--skip-xlsx] [--dry-run]

    --skip-xlsx   Skip XLSX reload, use existing kirk rows in yy_word_import
    --dry-run     Show what would be done without writing to DB
"""

import os
import argparse
import psycopg2
from dotenv import load_dotenv

XLSX_PATH = os.path.join(os.path.dirname(__file__), '..', 'assets',
                         'Yada Yahowah-Hebrew Glossary Nouns and Verbs.xlsx')
SHEET_NAME = 'AllExport'
SOURCE = 'kirk'


def get_db_connection():
    load_dotenv()
    return psycopg2.connect(
        host=os.getenv('POSTGRES_HOST', 'localhost'),
        port=os.getenv('POSTGRES_PORT', '5432'),
        user=os.getenv('POSTGRES_USER', 'postgres'),
        password=os.getenv('POSTGRES_PASSWORD'),
        dbname=os.getenv('POSTGRES_DB', 'yada')
    )


def load_xlsx(cur, dry_run=False):
    """Load XLSX AllExport sheet into yy_word_import."""
    import openpyxl

    print("Loading XLSX into yy_word_import...")

    wb = openpyxl.load_workbook(XLSX_PATH, read_only=True, data_only=True)
    ws = wb[SHEET_NAME]

    if not dry_run:
        cur.execute("DELETE FROM yy_word_import WHERE word_import_source = %s", (SOURCE,))
        print(f"  Deleted {cur.rowcount} existing kirk import records")

    INSERT_SQL = """
    INSERT INTO yy_word_import (
        word_import_strongs, word_import_hebrew, word_import_translit,
        word_import_definition_main,
        word_import_pos_gender, word_import_pos_plural,
        word_import_pos_noun, word_import_pos_verb, word_import_pos_adjective,
        word_import_pos_preposition, word_import_pos_conjunction, word_import_pos_subst,
        word_import_source
    ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
    """

    def to_bool(val):
        if val is None or val == '' or val == 0:
            return None
        return True

    count = 0
    for row in ws.iter_rows(min_row=2, values_only=True):
        if len(row) < 17:
            continue
        if row[12] is None and row[15] is None and row[16] is None:
            continue

        strongs = str(row[12]).strip() if row[12] is not None else None
        hebrew = str(row[13]).strip() if row[13] not in (None, '', 'None') else None
        translit = str(row[15]).strip() if row[15] not in (None, '', 'None') else None
        definition = str(row[16]).strip() if row[16] not in (None, '', 'None') else None

        gender = str(row[1]).strip() if row[1] not in (None, '') else None
        if gender and len(gender) > 1:
            gender = gender[0]

        if not dry_run:
            cur.execute(INSERT_SQL, (
                strongs, hebrew, translit, definition,
                gender, to_bool(row[4]), to_bool(row[5]), to_bool(row[6]),
                to_bool(row[7]), to_bool(row[9]), to_bool(row[10]), to_bool(row[11]),
                SOURCE
            ))
        count += 1

    wb.close()
    print(f"  Inserted {count} kirk rows into yy_word_import")
    return count


def create_words(cur, dry_run=False):
    """Create yy_word and yy_word_translit records from kirk imports."""
    print("Creating yy_word and yy_word_translit from kirk imports...")

    cur.execute("""
        SELECT word_import_key, word_import_strongs, word_import_hebrew,
               word_import_translit, word_import_definition_main,
               word_import_pos_gender, word_import_pos_plural,
               word_import_pos_noun, word_import_pos_verb, word_import_pos_adjective,
               word_import_pos_preposition, word_import_pos_conjunction, word_import_pos_subst
        FROM yy_word_import
        WHERE word_import_source = %s
        ORDER BY word_import_key
    """, (SOURCE,))
    imports = cur.fetchall()

    if not imports:
        print("  No kirk records found in yy_word_import. Use --load-xlsx first.")
        return 0, 0, 0, 0

    print(f"  Processing {len(imports)} kirk import records...")

    if not dry_run:
        cur.execute("ALTER TABLE yy_word DISABLE TRIGGER ALL")
        cur.execute("ALTER TABLE yy_word_translit DISABLE TRIGGER ALL")

    updated = 0
    inserted = 0
    translit_count = 0
    linked = 0

    for row in imports:
        (imp_key, strongs, hebrew, translit_raw, definition,
         gender, plural, noun, verb, adjective,
         preposition, conjunction, subst) = row

        strongs_trimmed = strongs.strip() if strongs else None

        # Check if kirk yy_word already exists for this strongs
        cur.execute("""
            SELECT word_key FROM yy_word
            WHERE TRIM(word_strongs) = %s AND word_source_code = %s
        """, (strongs_trimmed, SOURCE))
        existing = cur.fetchone()

        if existing:
            word_key = existing[0]
            if not dry_run:
                cur.execute("""
                    UPDATE yy_word SET
                        word_hebrew = COALESCE(%s, word_hebrew),
                        word_definition_kirk = COALESCE(%s, word_definition_kirk),
                        word_gender = COALESCE(%s, word_gender),
                        word_flag_plural = COALESCE(%s, word_flag_plural),
                        word_flag_noun = COALESCE(%s, word_flag_noun),
                        word_flag_verb = COALESCE(%s, word_flag_verb),
                        word_flag_adjective = COALESCE(%s, word_flag_adjective),
                        word_flag_preposition = COALESCE(%s, word_flag_preposition),
                        word_flag_conjunction = COALESCE(%s, word_flag_conjunction),
                        word_flag_subst = COALESCE(%s, word_flag_subst)
                    WHERE word_key = %s
                """, (hebrew, definition, gender, plural, noun, verb, adjective,
                      preposition, conjunction, subst, word_key))
            updated += 1
        else:
            if not dry_run:
                cur.execute("""
                    INSERT INTO yy_word (
                        word_source_code, word_strongs, word_hebrew,
                        word_definition_kirk,
                        word_gender, word_flag_plural,
                        word_flag_noun, word_flag_verb, word_flag_adjective,
                        word_flag_preposition, word_flag_conjunction, word_flag_subst,
                        word_active_flag
                    ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 't')
                    RETURNING word_key
                """, (SOURCE, strongs_trimmed, hebrew, definition, gender, plural,
                      noun, verb, adjective, preposition, conjunction, subst))
                word_key = cur.fetchone()[0]
            inserted += 1

        # Link yy_word_import to yy_word
        if not dry_run:
            cur.execute("UPDATE yy_word_import SET word_key = %s WHERE word_import_key = %s",
                        (word_key, imp_key))
        linked += 1

        # Parse transliterations and create yy_word_translit entries
        if translit_raw:
            translits = [t.strip() for t in translit_raw.split('\n') if t.strip()]
            for ttext in translits:
                if not dry_run:
                    cur.execute("""
                        SELECT 1 FROM yy_word_translit
                        WHERE word_key = %s AND word_translit_text = %s
                    """, (word_key, ttext))
                    if not cur.fetchone():
                        cur.execute("""
                            SELECT COALESCE(MAX(word_translit_sort), -1) + 1
                            FROM yy_word_translit WHERE word_key = %s
                        """, (word_key,))
                        next_sort = cur.fetchone()[0]
                        cur.execute("""
                            INSERT INTO yy_word_translit
                                (word_key, word_translit_text, word_translit_sort, word_translit_count_yy)
                            VALUES (%s, %s, %s, 0)
                        """, (word_key, ttext, next_sort))
                        translit_count += 1

    # Set word_translit on yy_word from first translit entry
    if not dry_run:
        cur.execute("""
            UPDATE yy_word w
            SET word_translit = (
                SELECT wt.word_translit_text
                FROM yy_word_translit wt
                WHERE wt.word_key = w.word_key
                ORDER BY wt.word_translit_sort, wt.word_translit_key
                LIMIT 1
            )
            WHERE w.word_source_code = %s
              AND EXISTS (SELECT 1 FROM yy_word_translit wt WHERE wt.word_key = w.word_key)
        """, (SOURCE,))
        translit_set = cur.rowcount
        print(f"  yy_word.word_translit set: {translit_set}")

    if not dry_run:
        cur.execute("ALTER TABLE yy_word ENABLE TRIGGER ALL")
        cur.execute("ALTER TABLE yy_word_translit ENABLE TRIGGER ALL")

    print(f"  yy_word updated: {updated}")
    print(f"  yy_word inserted: {inserted}")
    print(f"  yy_word_translit created: {translit_count}")
    print(f"  yy_word_import linked: {linked}")
    return updated, inserted, translit_count, linked


def main():
    parser = argparse.ArgumentParser(description='Kirk Glossary Import')
    parser.add_argument('--skip-xlsx', action='store_true',
                        help='Skip XLSX reload, use existing kirk rows in yy_word_import')
    parser.add_argument('--dry-run', action='store_true',
                        help='Show what would be done without writing')
    args = parser.parse_args()

    print("=== Kirk Glossary Import ===\n")

    conn = get_db_connection()
    cur = conn.cursor()

    try:
        if not args.skip_xlsx:
            load_xlsx(cur, args.dry_run)
            print()
        else:
            cur.execute("SELECT COUNT(*) FROM yy_word_import WHERE word_import_source = %s", (SOURCE,))
            print(f"Skipping XLSX reload ({cur.fetchone()[0]} existing kirk import records)\n")

        create_words(cur, args.dry_run)

        if not args.dry_run:
            conn.commit()

        # Summary counts
        print("\n--- Summary ---")
        for tbl in ['yy_word', 'yy_word_translit', 'yy_word_import']:
            cur.execute(f"SELECT COUNT(*) FROM {tbl}")
            print(f"  {tbl}: {cur.fetchone()[0]} total")
        cur.execute("SELECT COUNT(*) FROM yy_word WHERE word_source_code = %s", (SOURCE,))
        print(f"  yy_word (kirk): {cur.fetchone()[0]}")
        cur.execute("SELECT COUNT(*) FROM yy_word_import WHERE word_import_source = %s AND word_key IS NOT NULL", (SOURCE,))
        print(f"  yy_word_import (kirk, linked): {cur.fetchone()[0]}")

    finally:
        cur.close()
        conn.close()

    print("\nDone.")


if __name__ == '__main__':
    main()
