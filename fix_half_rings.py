"""
Replace single quotes/apostrophes in Hebrew transliterations with the correct
half-ring modifier characters:
  ʾ (U+02BE) for Aleph (א)
  ʿ (U+02BF) for Ayin (ע)

Uses multiple strategies:
1. YY word spelling -> Hebrew lookup
2. BLB transliteration -> Hebrew lookup
3. Hebrew consonant pattern matching (converts YY translit to Hebrew, matches BLB)
4. Position-based heuristic fallback
"""
import psycopg2
import re
import unicodedata
from itertools import product

INPUT = r'C:\Users\Joe\Work\dev\yada\translations\italic_quote_words.txt'
OUTPUT = r'C:\Users\Joe\Work\dev\yada\translations\italic_quote_words_fixed.txt'

ALEPH = '\u05D0'  # א
AYIN = '\u05E2'    # ע
HALF_RING_ALEPH = '\u02BE'  # ʾ
HALF_RING_AYIN = '\u02BF'   # ʿ

QUOTE_CHARS = set("'\u2018\u2019`\u00B4\u02BC")

# YY transliteration digraphs/consonants -> Hebrew letter(s)
# Returns list of possible Hebrew letters for each consonant
YY_CONSONANT_MAP = {
    'sh': ['ש'],
    'ch': ['ח'],
    'ts': ['צ'],
    'th': ['ת'],
    'ph': ['פ'],
    'b': ['ב'],
    'g': ['ג'],
    'd': ['ד'],
    'h': ['ה', 'ח'],  # ambiguous
    'w': ['ו'],
    'v': ['ו'],
    'z': ['ז'],
    't': ['ת', 'ט'],  # ambiguous
    'y': ['י'],
    'k': ['כ'],
    'l': ['ל'],
    'm': ['מ'],
    'n': ['נ'],
    's': ['ס', 'שׂ'],  # ambiguous
    'p': ['פ'],
    'q': ['ק'],
    'r': ['ר'],
}


def strip_nikkud(hebrew):
    return ''.join(ch for ch in hebrew if not (0x0591 <= ord(ch) <= 0x05C7))


def strip_diacritics(text):
    nfkd = unicodedata.normalize('NFKD', text)
    return ''.join(ch for ch in nfkd if unicodedata.category(ch) != 'Mn')


def get_aleph_ayin_sequence(hebrew):
    """Return ordered list of ʾ/ʿ for each א/ע in the Hebrew word."""
    cleaned = strip_nikkud(hebrew)
    seq = []
    for ch in cleaned:
        if ch == ALEPH:
            seq.append(HALF_RING_ALEPH)
        elif ch == AYIN:
            seq.append(HALF_RING_AYIN)
    return seq


def replace_quotes(word, replacements):
    result = ''
    idx = 0
    for ch in word:
        if ch in QUOTE_CHARS or ch == HALF_RING_ALEPH or ch == HALF_RING_AYIN:
            if idx < len(replacements):
                result += replacements[idx]
                idx += 1
            else:
                result += ch
        else:
            result += ch
    return result


def count_quotes(word):
    return sum(1 for ch in word if ch in QUOTE_CHARS)


def normalize_quotes(word):
    """Normalize all quote types to straight apostrophe, lowercase."""
    w = word.lower()
    w = w.replace(HALF_RING_ALEPH, "'").replace(HALF_RING_AYIN, "'")
    for q in QUOTE_CHARS:
        w = w.replace(q, "'")
    return w


def normalize_blb(translit):
    t = translit.lower().strip()
    t = strip_diacritics(t)
    for q in QUOTE_CHARS:
        t = t.replace(q, "'")
    t = t.replace(HALF_RING_ALEPH, "'").replace(HALF_RING_AYIN, "'")
    return t


FINAL_FORMS = {
    '\u05DB': '\u05DA',  # כ → ך
    '\u05DE': '\u05DD',  # מ → ם
    '\u05E0': '\u05DF',  # נ → ן
    '\u05E4': '\u05E3',  # פ → ף
    '\u05E6': '\u05E5',  # צ → ץ
}


def apply_final_form(hebrew_seq):
    """Convert the last character to its final form if applicable."""
    if not hebrew_seq:
        return hebrew_seq
    last = hebrew_seq[-1]
    if last in FINAL_FORMS:
        return hebrew_seq[:-1] + FINAL_FORMS[last]
    return hebrew_seq


def yy_to_hebrew_patterns(word):
    """Convert YY transliteration to possible Hebrew consonant sequences.
    Returns list of tuples: (hebrew_consonants, aleph_ayin_sequence)."""
    w = normalize_quotes(word).replace('-', '').replace(' ', '')

    # Parse into tokens: consonant mappings and quote markers
    tokens = []  # list of (type, possible_hebrew_chars)
    i = 0
    while i < len(w):
        if w[i] == "'":
            tokens.append(('quote', [ALEPH, AYIN]))
            i += 1
            continue

        # Try digraphs first
        matched = False
        if i + 1 < len(w):
            digraph = w[i:i+2]
            if digraph in YY_CONSONANT_MAP:
                tokens.append(('consonant', YY_CONSONANT_MAP[digraph]))
                i += 2
                matched = True
        if not matched:
            ch = w[i]
            if ch in YY_CONSONANT_MAP:
                tokens.append(('consonant', YY_CONSONANT_MAP[ch]))
            # else: vowel, skip
            i += 1

    if not tokens:
        return []

    # Collect ambiguous positions (consonants with multiple options + quotes)
    ambig_indices = []
    fixed_parts = []
    for idx, (typ, options) in enumerate(tokens):
        if typ == 'quote':
            ambig_indices.append((len(fixed_parts), options))
            fixed_parts.append(None)
        elif len(options) > 1:
            ambig_indices.append((len(fixed_parts), options))
            fixed_parts.append(None)
        else:
            fixed_parts.append(options[0])

    if not ambig_indices:
        return []

    # Limit total combinations to avoid explosion
    # Each ambiguous position has 2 options; cap at 6 ambiguous = 64 combos
    if len(ambig_indices) > 6:
        # Only vary quote positions, fix consonants to primary
        new_ambig = []
        for pos, options in ambig_indices:
            if ALEPH in options:  # it's a quote position
                new_ambig.append((pos, options))
            else:
                fixed_parts[pos] = options[0]
        ambig_indices = new_ambig

    # Generate all combinations
    all_options = [opts for _, opts in ambig_indices]
    all_positions = [pos for pos, _ in ambig_indices]
    quote_token_indices = set()
    for orig_idx, (typ, _) in enumerate(tokens):
        if typ == 'quote':
            # Find its position in fixed_parts
            fp_idx = 0
            for j in range(orig_idx):
                fp_idx += 1
            quote_token_indices.add(orig_idx)

    # Identify which ambig_indices entries are quote positions
    quote_ambig = []
    for i, (pos, options) in enumerate(ambig_indices):
        if ALEPH in options and AYIN in options:
            quote_ambig.append(i)

    patterns = set()
    results = []
    for combo in product(*all_options):
        parts = fixed_parts[:]
        for i, letter in enumerate(combo):
            parts[all_positions[i]] = letter
        hebrew_seq = ''.join(p for p in parts if p)

        # Try both with and without final form
        for seq in [hebrew_seq, apply_final_form(hebrew_seq)]:
            if seq in patterns:
                continue
            patterns.add(seq)

            # Extract Aleph/Ayin sequence for the quote positions
            aleph_ayin = []
            for qi in quote_ambig:
                letter = combo[qi]
                if letter == ALEPH:
                    aleph_ayin.append(HALF_RING_ALEPH)
                elif letter == AYIN:
                    aleph_ayin.append(HALF_RING_AYIN)

            results.append((seq, aleph_ayin))

    return results


def main():
    conn = psycopg2.connect(
        host='localhost', port=5433, dbname='yada',
        user='postgres', password='yada_password'
    )
    cur = conn.cursor()

    # --- Load YY word spelling -> Hebrew ---
    cur.execute('''
        SELECT ws.word_translit_text, w.word_hebrew
        FROM yy_word_translit ws
        JOIN yy_word w ON ws.word_id = w.word_key
        WHERE w.word_hebrew IS NOT NULL AND w.word_hebrew != ''
    ''')
    spelling_map = {}
    for spelling, hebrew in cur.fetchall():
        key = normalize_quotes(spelling.strip())
        if key not in spelling_map:
            spelling_map[key] = hebrew.strip()

    # --- Load BLB translit -> Hebrew ---
    cur.execute('''
        SELECT word_import_translit, word_import_hebrew
        FROM yy_word_import
        WHERE word_import_translit IS NOT NULL AND word_import_hebrew IS NOT NULL
    ''')
    blb_map = {}
    for translit, hebrew in cur.fetchall():
        key = normalize_blb(translit.strip())
        if key not in blb_map:
            blb_map[key] = hebrew.strip()

    # --- Build Hebrew consonant lookup from ALL BLB entries ---
    # Map: stripped Hebrew consonants -> aleph/ayin sequence
    hebrew_lookup = {}
    cur.execute('''
        SELECT word_import_hebrew FROM yy_word_import
        WHERE word_import_hebrew IS NOT NULL AND word_import_hebrew != ''
    ''')
    for (heb_raw,) in cur.fetchall():
        heb = strip_nikkud(heb_raw.strip())
        if heb and heb not in hebrew_lookup:
            seq = get_aleph_ayin_sequence(heb_raw.strip())
            if seq:  # only store entries that have Aleph or Ayin
                hebrew_lookup[heb] = seq

    conn.close()
    print(f'Loaded: {len(spelling_map)} YY spellings, {len(blb_map)} BLB translits, {len(hebrew_lookup)} Hebrew patterns')

    # --- Read input ---
    lines = open(INPUT, encoding='utf-8').readlines()
    header = lines[0]
    sep = lines[1]
    data = lines[2:]

    results = []
    matched = 0
    unmatched_list = []
    method_counts = {'spelling': 0, 'blb': 0, 'hebrew-pattern': 0, 'heuristic': 0}

    for line in data:
        word = line[:40].strip()
        rest = line[40:]
        file_part = rest[:65].strip()
        page_part = rest[65:].strip()

        n_quotes = count_quotes(word)
        if n_quotes == 0:
            results.append((word, word, file_part, page_part, 'no-quotes'))
            continue

        normalized = normalize_quotes(word)
        hebrew = None
        method = None

        # --- Strategy 1: YY spelling exact match ---
        if normalized in spelling_map:
            hebrew = spelling_map[normalized]
            method = 'spelling'

        # --- Strategy 2: Hyphenated compound - match parts ---
        if not hebrew and '-' in normalized:
            parts = normalized.split('-')
            for part in parts:
                if count_quotes(part) > 0 and part in spelling_map:
                    hebrew = spelling_map[part]
                    method = 'spelling'
                    break

        # --- Strategy 3: BLB translit match ---
        if not hebrew:
            blb_key = normalize_blb(word)
            if blb_key in blb_map:
                hebrew = blb_map[blb_key]
                method = 'blb'

        # --- Strategy 3b: BLB match on hyphen parts ---
        if not hebrew and '-' in normalized:
            for part in normalized.split('-'):
                if count_quotes(part) > 0 and part in blb_map:
                    hebrew = blb_map[part]
                    method = 'blb'
                    break

        # If found Hebrew, apply
        if hebrew:
            seq = get_aleph_ayin_sequence(hebrew)
            if len(seq) >= n_quotes:
                fixed = replace_quotes(word, seq)
                results.append((fixed, word, file_part, page_part, method))
                method_counts[method] += 1
                matched += 1
                continue
            elif len(seq) > 0:
                fixed = replace_quotes(word, seq)
                results.append((fixed, word, file_part, page_part, method + '-partial'))
                method_counts[method] += 1
                matched += 1
                continue

        # --- Strategy 4: Hebrew consonant pattern matching ---
        patterns = yy_to_hebrew_patterns(normalized)
        found_pattern = False
        for heb_pattern, aleph_ayin_seq in patterns:
            if heb_pattern in hebrew_lookup:
                db_seq = hebrew_lookup[heb_pattern]
                if len(db_seq) >= n_quotes:
                    fixed = replace_quotes(word, db_seq)
                    results.append((fixed, word, file_part, page_part, 'hebrew-pattern'))
                    method_counts['hebrew-pattern'] += 1
                    matched += 1
                    found_pattern = True
                    break
        if found_pattern:
            continue

        # Strategy 4b: pattern match on hyphen parts
        if '-' in normalized:
            for part in normalized.split('-'):
                if count_quotes(part) > 0:
                    part_patterns = yy_to_hebrew_patterns(part)
                    for heb_pattern, aleph_ayin_seq in part_patterns:
                        if heb_pattern in hebrew_lookup:
                            db_seq = hebrew_lookup[heb_pattern]
                            part_quotes = count_quotes(part)
                            if len(db_seq) >= part_quotes:
                                # Apply just this part's replacements for the whole word
                                fixed = replace_quotes(word, db_seq)
                                results.append((fixed, word, file_part, page_part, 'hebrew-pattern'))
                                method_counts['hebrew-pattern'] += 1
                                matched += 1
                                found_pattern = True
                                break
                    if found_pattern:
                        break
        if found_pattern:
            continue

        # --- Strategy 5: Heuristic fallback ---
        # Use BLB frequency data to determine: for initial ', is Aleph or Ayin more common
        # for that consonant context?
        replacements = []
        in_word = normalized
        for i, ch in enumerate(in_word):
            if ch == "'":
                if i == 0:
                    # Initial ': check what consonant follows after vowels
                    rest_lower = in_word[1:].lstrip('aeiou')
                    # Look for any BLB entry starting with this pattern
                    found_init = False
                    for bk, bh in blb_map.items():
                        if bk.startswith("'") and rest_lower and bk[1:].lstrip('aeiou').startswith(rest_lower[:2]):
                            seq = get_aleph_ayin_sequence(bh)
                            if seq:
                                replacements.append(seq[0])
                                found_init = True
                                break
                    if not found_init:
                        replacements.append(HALF_RING_ALEPH)  # default initial
                elif i == len(in_word) - 1:
                    # Final ': look at preceding consonants
                    found_final = False
                    for bk, bh in blb_map.items():
                        if bk.endswith("'") and len(bk) > 2:
                            bk_stem = bk[:-1].rstrip('aeiou')
                            word_stem = in_word[:i].rstrip('aeiou')
                            if bk_stem and word_stem and bk_stem[-2:] == word_stem[-2:]:
                                seq = get_aleph_ayin_sequence(bh)
                                if seq:
                                    replacements.append(seq[-1])
                                    found_final = True
                                    break
                    if not found_final:
                        replacements.append(HALF_RING_ALEPH)  # default final
                else:
                    # Internal ': more often Ayin in Hebrew
                    replacements.append(HALF_RING_AYIN)

        fixed = replace_quotes(word, replacements)
        results.append((fixed, word, file_part, page_part, 'heuristic'))
        method_counts['heuristic'] += 1
        unmatched_list.append(word)

    # --- Write output ---
    with open(OUTPUT, 'w', encoding='utf-8') as f:
        f.write(f'{"Fixed Word":<40} {"Original Word":<30} {"File":<65} {"Page":<6} {"Method"}\n')
        f.write(f'{"-"*40} {"-"*30} {"-"*65} {"-"*6} {"-"*20}\n')
        for fixed, original, fname, page, method in results:
            f.write(f'{fixed:<40} {original:<30} {fname:<65} {page:<6} {method}\n')

    print(f'\nTotal: {len(results)} words')
    print(f'DB-matched: {matched} ({matched*100//max(len(results),1)}%)')
    print(f'Heuristic: {len(unmatched_list)}')
    print(f'Methods: {method_counts}')
    print(f'Saved to: {OUTPUT}')

    if unmatched_list:
        print(f'\nHeuristic words (first 30):')
        for w in unmatched_list[:30]:
            print(f'  {w}')


if __name__ == '__main__':
    main()
