"""
Rebuild yy_word, yy_word_translit, yy_word_translation from yy_translation_copy.
Extracts italic words from translations, carries over metadata from existing yy_word.
"""
import os, sys, re
from collections import Counter
import psycopg2
from psycopg2.extras import execute_values
from dotenv import load_dotenv

PAREN_GROUP_RE = re.compile(r'\([^)]+\)')
ITALIC_TAG_RE = re.compile(r'<i[^>]*>([^<]+)</i>', re.IGNORECASE)
SPAN_TAG_RE = re.compile(r'<span[^>]*>|</span>', re.IGNORECASE)


def normalize_quotes(s):
    return (s.replace('\u2018', "'").replace('\u2019', "'").replace('\u2032', "'")
             .replace('\u02BE', "'").replace('\u02BF', "'"))


def extract_italic_words(html_text):
    """Extract words from all <i> tags within parenthetical groups.
    Returns list: strings for standalone words, tuples for slash-separated
    alternates (first word is primary)."""
    results = []
    clean_text = SPAN_TAG_RE.sub('', html_text)
    for paren_match in PAREN_GROUP_RE.finditer(clean_text):
        group = paren_match.group(0)
        for italic_match in ITALIC_TAG_RE.finditer(group):
            content = italic_match.group(1).strip()
            if not content:
                continue
            tokens = content.split()
            i = 0
            while i < len(tokens):
                cleaned = tokens[i].strip('.,;:!?()-/[]{}"\u2013\u2014\u2015')
                if not cleaned:
                    i += 1
                    continue
                # Detect slash-separated alternates: word1 / word2 [/ word3 ...]
                slash_group = [cleaned]
                j = i + 1
                while j + 1 < len(tokens) and tokens[j] == '/':
                    next_cleaned = tokens[j + 1].strip('.,;:!?()-/[]{}"\u2013\u2014\u2015')
                    if next_cleaned:
                        slash_group.append(next_cleaned)
                        j += 2
                    else:
                        break
                if len(slash_group) > 1:
                    results.append(tuple(slash_group))
                    i = j
                else:
                    results.append(cleaned)
                    i += 1
    return results


def get_db_connection():
    load_dotenv()
    return psycopg2.connect(
        host=os.getenv('POSTGRES_HOST', 'localhost'),
        port=os.getenv('POSTGRES_PORT', '5432'),
        user=os.getenv('POSTGRES_USER', 'postgres'),
        password=os.getenv('POSTGRES_PASSWORD'),
        dbname=os.getenv('POSTGRES_DB', 'yada')
    )


def main():
    sys.stdout.reconfigure(encoding='utf-8')
    conn = get_db_connection()
    cur = conn.cursor()

    # === Step 1: Read existing yy_word metadata for carry-over ===
    print("Step 1: Loading existing yy_word metadata...")
    cur.execute("""
        SELECT word_key, word_strongs, word_hebrew, word_translit,
               word_definition_kirk, word_definition_yy, word_definition_external,
               word_active_flag, word_gender,
               word_flag_noun, word_flag_verb, word_flag_adjective, word_flag_adverb,
               word_flag_preposition, word_flag_conjunction, word_flag_subst, word_flag_plural
        FROM yy_word
    """)
    old_word_map = {}
    for row in cur.fetchall():
        yt = row[3]
        if yt:
            norm = normalize_quotes(yt).lower().strip()
            if norm and norm not in old_word_map:
                old_word_map[norm] = {
                    'word_strongs': row[1].strip() if row[1] else '',
                    'word_hebrew': row[2] or '',
                    'word_definition_kirk': row[4],
                    'word_definition_yy': row[5],
                    'word_definition_external': row[6],
                    'word_active_flag': row[7],
                    'word_gender': row[8],
                    'word_flag_noun': row[9], 'word_flag_verb': row[10],
                    'word_flag_adjective': row[11], 'word_flag_adverb': row[12],
                    'word_flag_preposition': row[13], 'word_flag_conjunction': row[14],
                    'word_flag_subst': row[15], 'word_flag_plural': row[16],
                }
    print(f"  Loaded {len(old_word_map)} existing word entries for metadata carry-over")

    # === Step 2: Extract italic words from translations ===
    print("Step 2: Extracting italic words from yy_translation_copy...")
    cur.execute("SELECT yy_translation_key, yy_translation_copy FROM yy_translation")
    translations = cur.fetchall()

    # Pass 1: Build merge map from slash-separated pairs (e.g., saphar / sepher)
    # Only pairs (2 words) are treated as alternates; 3+ word chains are compound
    # breakdowns (e.g., midad / ma'ad / min) and are NOT merged.
    merge_map = {}  # alt_normalized -> primary_normalized
    for tkey, tcopy in translations:
        if not tcopy:
            continue
        for item in extract_italic_words(tcopy):
            if isinstance(item, tuple) and len(item) == 2:
                primary_norm = normalize_quotes(item[0]).lower().strip()
                alt_norm = normalize_quotes(item[1]).lower().strip()
                if alt_norm and alt_norm != primary_norm and alt_norm not in merge_map:
                    merge_map[alt_norm] = primary_norm

    # Resolve transitive merges (a->b, b->c => a->c)
    for alt in list(merge_map):
        target = merge_map[alt]
        visited = {alt}
        while target in merge_map and target not in visited:
            visited.add(target)
            target = merge_map[target]
        merge_map[alt] = target
    merge_map = {k: v for k, v in merge_map.items() if k != v}
    print(f"  {len(merge_map)} slash-alternate merges")

    # Pass 2: Build word_data with merging
    word_data = {}  # normalized -> {forms: Counter, trans_keys: set}
    for tkey, tcopy in translations:
        if not tcopy:
            continue
        items = extract_italic_words(tcopy)
        seen_in_this = set()
        for item in items:
            words = list(item) if isinstance(item, tuple) else [item]
            for w in words:
                norm = normalize_quotes(w).lower().strip()
                if not norm:
                    continue
                key = merge_map.get(norm, norm)
                if key not in word_data:
                    word_data[key] = {'forms': Counter(), 'trans_keys': set()}
                word_data[key]['forms'][w] += 1
                if key not in seen_in_this:
                    word_data[key]['trans_keys'].add(tkey)
                    seen_in_this.add(key)

    print(f"  {len(translations)} translations processed")
    print(f"  {len(word_data)} unique normalized words found")
    print(f"  {sum(len(d['forms']) for d in word_data.values())} total variant forms")

    matched = sum(1 for n in word_data if n in old_word_map)
    new_words = sum(1 for n in word_data if n not in old_word_map)
    print(f"  {matched} words match existing yy_word (will carry over metadata)")
    print(f"  {new_words} new words (no existing match)")

    # === Step 3: Disable triggers + TRUNCATE ===
    print("Step 3: Disabling triggers and truncating...")
    cur.execute("ALTER TABLE yy_word DISABLE TRIGGER ALL")
    cur.execute("ALTER TABLE yy_word_translit DISABLE TRIGGER ALL")
    # Null out yy_word_import FK before truncating so CASCADE doesn't wipe it
    cur.execute("UPDATE yy_word_import SET word_key = NULL")
    cur.execute("TRUNCATE yy_word_translation")
    cur.execute("TRUNCATE yy_word_translit")
    cur.execute("TRUNCATE yy_word_twot")
    cur.execute("TRUNCATE rev_yy_word")
    cur.execute("TRUNCATE rev_yy_word_translit")
    cur.execute("TRUNCATE yy_word")
    cur.execute("ALTER SEQUENCE yy_word_word_key_seq RESTART WITH 1")
    cur.execute("ALTER SEQUENCE yy_word_translit_word_translit_key_seq RESTART WITH 1")
    conn.commit()
    print("  Truncated: yy_word, yy_word_translit, yy_word_translation, yy_word_twot")
    print("  Preserved: yy_word_import (FKs nulled)")
    print("  Reset sequences")

    # === Step 4: Insert yy_word entries ===
    print("Step 4: Inserting yy_word entries...")
    sorted_words = sorted(word_data.keys())

    word_rows = []
    for norm in sorted_words:
        d = word_data[norm]
        # Canonical: most common form of the primary word (normalized matches key)
        primary_forms = [(f, c) for f, c in d['forms'].items()
                         if normalize_quotes(f).lower().strip() == norm]
        canonical = max(primary_forms, key=lambda x: x[1])[0] if primary_forms else d['forms'].most_common(1)[0][0]
        count_yy = len(d['trans_keys'])

        old = old_word_map.get(norm, {})
        strongs = old.get('word_strongs', '').ljust(4)[:4] if old.get('word_strongs') else '    '
        hebrew = old.get('word_hebrew', '') if old.get('word_hebrew') else ''

        word_rows.append((
            strongs, hebrew, canonical.lower(), count_yy,
            old.get('word_active_flag', True),
            old.get('word_definition_kirk'),
            old.get('word_definition_yy'),
            old.get('word_definition_external'),
            old.get('word_gender'),
            old.get('word_flag_noun'), old.get('word_flag_verb'),
            old.get('word_flag_adjective'), old.get('word_flag_adverb'),
            old.get('word_flag_preposition'), old.get('word_flag_conjunction'),
            old.get('word_flag_subst'), old.get('word_flag_plural'),
            'yy'
        ))

    execute_values(cur, """
        INSERT INTO yy_word (word_strongs, word_hebrew, word_translit, word_count_yy,
            word_active_flag, word_definition_kirk, word_definition_yy, word_definition_external,
            word_gender, word_flag_noun, word_flag_verb, word_flag_adjective, word_flag_adverb,
            word_flag_preposition, word_flag_conjunction, word_flag_subst, word_flag_plural,
            word_source_code)
        VALUES %s
    """, word_rows, page_size=500)
    conn.commit()

    # Read back word_ids
    word_id_map = {}
    cur.execute("SELECT word_key, word_translit FROM yy_word")
    for wid, wyt in cur.fetchall():
        norm = normalize_quotes(wyt).lower().strip()
        word_id_map[norm] = wid
    print(f"  Inserted {len(word_rows)} yy_word entries")

    # === Step 5: Insert yy_word_translit ===
    print("Step 5: Inserting yy_word_translit...")
    spelling_rows = []
    for norm in sorted_words:
        wid = word_id_map[norm]
        forms = word_data[norm]['forms']
        # Deduplicate forms that become identical after lowercasing
        lowered_forms = {}
        for f, c in forms.items():
            lf = f.lower()
            lowered_forms[lf] = lowered_forms.get(lf, 0) + c
        # Primary forms first (normalized matches key), then slash alternates
        primary = sorted([(f, c) for f, c in lowered_forms.items()
                          if normalize_quotes(f).strip() == norm], key=lambda x: -x[1])
        alternates = sorted([(f, c) for f, c in lowered_forms.items()
                             if normalize_quotes(f).strip() != norm], key=lambda x: -x[1])
        for sort_idx, (form, cnt) in enumerate(primary + alternates):
            spelling_rows.append((wid, form, sort_idx, cnt))

    execute_values(cur, """
        INSERT INTO yy_word_translit (word_key, word_translit_text, word_translit_sort, word_translit_count_yy)
        VALUES %s
    """, spelling_rows, page_size=1000)
    conn.commit()
    print(f"  Inserted {len(spelling_rows)} yy_word_translit entries")

    # === Step 6: Insert yy_word_translation ===
    print("Step 6: Inserting yy_word_translation...")
    wt_pairs = []
    for norm, d in word_data.items():
        wid = word_id_map[norm]
        for tkey in d['trans_keys']:
            wt_pairs.append((wid, tkey))

    execute_values(cur, """
        INSERT INTO yy_word_translation (word_key, translation_key) VALUES %s
    """, wt_pairs, page_size=2000)
    conn.commit()
    print(f"  Inserted {len(wt_pairs)} yy_word_translation pairs")

    # === Step 7: Re-link yy_word_import ===
    print("Step 7: Re-linking yy_word_import...")
    cur.execute("""
        UPDATE yy_word_import wi
        SET word_key = w.word_key
        FROM yy_word w
        WHERE TRIM(w.word_strongs) = TRIM(wi.word_import_strongs)
          AND TRIM(w.word_strongs) != ''
    """)
    linked = cur.rowcount
    cur.execute("SELECT COUNT(*) FROM yy_word_import WHERE word_key IS NULL")
    unlinked = cur.fetchone()[0]
    conn.commit()
    print(f"  Linked {linked} yy_word_import rows to yy_word")
    if unlinked:
        print(f"  {unlinked} rows remain unlinked (no matching Strong's in yy_word)")

    # === Step 8: Re-enable triggers ===
    cur.execute("ALTER TABLE yy_word ENABLE TRIGGER ALL")
    cur.execute("ALTER TABLE yy_word_translit ENABLE TRIGGER ALL")
    conn.commit()
    print("Step 8: Re-enabled triggers")

    # === Summary ===
    print()
    print("=" * 60)
    print("SUMMARY")
    print("=" * 60)
    for t in ['yy_word', 'yy_word_translit', 'yy_word_translation',
              'yy_word_twot', 'yy_word_import', 'rev_yy_word', 'rev_yy_word_translit']:
        cur.execute(f"SELECT COUNT(*) FROM {t}")
        print(f"  {t}: {cur.fetchone()[0]}")

    print()
    print("Top 15 words by translation count:")
    cur.execute("""SELECT w.word_translit, w.word_count_yy, w.word_strongs
        FROM yy_word w ORDER BY w.word_count_yy DESC LIMIT 15""")
    for yt, cnt, strongs in cur.fetchall():
        s = strongs.strip()
        print(f"  {yt:<25s} {cnt:>6d} translations  strongs={s if s else '-'}")

    print()
    print("Sample words with carried-over metadata:")
    cur.execute("""SELECT word_translit, word_strongs, word_hebrew, word_count_yy,
                          LEFT(word_definition_kirk, 60)
        FROM yy_word WHERE TRIM(word_strongs) != ''
        ORDER BY word_count_yy DESC LIMIT 10""")
    for yt, strongs, hebrew, cnt, defn in cur.fetchall():
        print(f"  {yt:<20s} H{strongs.strip():<5s} {hebrew:<10s} {cnt:>5d}  {defn or ''}")

    cur.close()
    conn.close()
    print("\nDone.")


if __name__ == "__main__":
    main()
