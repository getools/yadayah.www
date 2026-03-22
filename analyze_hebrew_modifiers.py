"""
Analyze Hebrew transliterations with apostrophes using word definitions
to determine the most appropriate Unicode modifier (Aleph ʾ vs Ayin ʿ).

Uses linguistic clues from word_definition_yy and word_definition_kirk fields
along with Hebrew text and Strong's numbers.
"""

import sys, os, re
sys.stdout.reconfigure(encoding='utf-8')
from dotenv import load_dotenv
load_dotenv(r'C:\Users\Joe\Work\dev\yada\translations\.env')
import psycopg2

# Read the previously generated conversions
conversions_file = r'C:\Users\Joe\Work\dev\yada\translations\hebrew_modifier_conversions.txt'
with open(conversions_file, 'r', encoding='utf-8') as f:
    lines = f.readlines()[2:]  # Skip header

simple_conversions = {}
for line in lines:
    if ', ' in line:
        orig, conv = line.strip().split(', ', 1)
        simple_conversions[orig.lower()] = conv

print(f"Loaded {len(simple_conversions)} simple conversions")

# Connect to database
conn = psycopg2.connect(
    host=os.getenv('POSTGRES_HOST', 'localhost'),
    port=os.getenv('POSTGRES_PORT', '5432'),
    user=os.getenv('POSTGRES_USER', 'postgres'),
    password=os.getenv('POSTGRES_PASSWORD'),
    dbname=os.getenv('POSTGRES_DB', 'yada')
)
cur = conn.cursor()

# Get all words with definitions
cur.execute("""
    SELECT w.word_key, w.word_translit, w.word_hebrew, w.word_strongs,
           w.word_definition_yy, w.word_definition_kirk,
           ws.word_translit_text
    FROM yy_word w
    LEFT JOIN yy_word_translit ws ON ws.word_id = w.word_key
    WHERE w.word_translit != '' OR ws.word_translit_text != ''
    ORDER BY w.word_key, ws.word_translit_sort
""")

rows = cur.fetchall()
print(f"Loaded {len(rows)} word/spelling records from database\n")

# Group by word_id
from collections import defaultdict
word_data = defaultdict(lambda: {'yt': '', 'hebrew': '', 'strongs': '', 'def_yy': '', 'def_kirk': '', 'spellings': []})

for wid, yt, heb, strongs, def_yy, def_kirk, spelling in rows:
    if yt and not word_data[wid]['yt']:
        word_data[wid]['yt'] = yt
        word_data[wid]['hebrew'] = heb or ''
        word_data[wid]['strongs'] = (strongs or '').strip()
        word_data[wid]['def_yy'] = def_yy or ''
        word_data[wid]['def_kirk'] = def_kirk or ''
    if spelling:
        word_data[wid]['spellings'].append(spelling)

# Linguistic markers for Ayin (ʿ) vs Aleph (ʾ)
# Ayin appears in roots meaning: see, know, eye, gate, help, work, answer, stand
AYIN_ROOTS = {
    'see', 'saw', 'seeing', 'sight', 'look', 'watch', 'observe', 'gaze',
    'eye', 'eyes', 'fountain', 'spring', 'well',
    'know', 'knowledge', 'wise', 'wisdom', 'understand',
    'gate', 'door', 'entrance', 'opening',
    'help', 'aid', 'assist', 'support', 'strength',
    'work', 'labor', 'deed', 'act', 'make', 'do',
    'answer', 'respond', 'testify', 'witness',
    'stand', 'stood', 'standing', 'rise', 'arise',
    'humble', 'afflict', 'oppress', 'bow',
    'naked', 'bare', 'uncovered',
    'flock', 'sheep', 'goat',
    'עין', 'ע', 'ayin', 'ע'  # Hebrew Ayin letter
}

# Aleph appears in roots meaning: father, brother, lord, god, man, say, eat, thousand
ALEPH_ROOTS = {
    'father', 'ancestor', 'forefather',
    'brother', 'kinsman',
    'lord', 'master', 'sovereign', 'adon',
    'god', 'deity', 'elohim', 'divine',
    'man', 'mankind', 'adam', 'human',
    'say', 'said', 'speak', 'utter', 'declare',
    'eat', 'food', 'consume', 'devour',
    'thousand', 'chief', 'clan',
    'not', 'no', 'nothing',
    'אלף', 'א', 'aleph', 'א'  # Hebrew Aleph letter
}

def analyze_word(yt, hebrew, strongs, def_yy, def_kirk, spellings):
    """Determine Aleph vs Ayin based on definitions and context."""
    # Combine all text for analysis
    text = f"{yt} {hebrew} {def_yy} {def_kirk}".lower()

    ayin_score = sum(1 for marker in AYIN_ROOTS if marker in text)
    aleph_score = sum(1 for marker in ALEPH_ROOTS if marker in text)

    # Check Strong's number patterns
    # Ayin words often have Strong's H5869 (ayin=eye), H5707 (ed=witness), etc.
    # Aleph words often have Strong's H1 (ab=father), H251 (ach=brother), etc.
    if strongs:
        if strongs in ('5869', '5707', '5712', '5749', '5750', '5753', '5756'):
            ayin_score += 2
        if strongs in ('1', '251', '113', '120', '430', '505', '559'):
            aleph_score += 2

    return ayin_score, aleph_score

# Analyze and refine conversions
refined_conversions = {}
corrections_made = 0

for wid, data in word_data.items():
    yt = data['yt']
    spellings = [yt] + data['spellings'] if yt else data['spellings']

    for spelling in spellings:
        if "'" not in spelling:
            continue

        # Get simple conversion
        simple = simple_conversions.get(spelling.lower(), '')
        if not simple:
            continue

        # Analyze based on definitions
        ayin_score, aleph_score = analyze_word(
            yt, data['hebrew'], data['strongs'],
            data['def_yy'], data['def_kirk'], spellings
        )

        # If we have strong evidence contrary to simple rule, note it
        if ayin_score > aleph_score + 2:
            # Definitions suggest Ayin
            refined = spelling.replace("'", "ʿ")
            if refined != simple:
                corrections_made += 1
                print(f"CORRECTION: {spelling} → {refined} (was {simple})")
                print(f"  Evidence: Ayin score={ayin_score}, Aleph score={aleph_score}")
                print(f"  Def: {data['def_kirk'][:80] if data['def_kirk'] else data['def_yy'][:80]}")
                print()
        elif aleph_score > ayin_score + 2:
            # Definitions suggest Aleph
            # First apostrophe should be Aleph
            if spelling.startswith("'"):
                refined = "ʾ" + spelling[1:].replace("'", "ʿ")
            else:
                # Mid-word but strong Aleph evidence - rare
                refined = simple
        else:
            # Use simple rule
            refined = simple

        refined_conversions[spelling.lower()] = refined

print(f"\n{corrections_made} corrections made based on definitions")
print(f"Total refined conversions: {len(refined_conversions)}")

# Write refined conversions
output_file = r'C:\Users\Joe\Work\dev\yada\translations\hebrew_modifier_conversions_refined.txt'
with open(output_file, 'w', encoding='utf-8') as f:
    f.write("Original, Converted (Definition-Refined)\n")
    f.write("-" * 80 + "\n")
    for orig in sorted(refined_conversions.keys()):
        f.write(f"{orig}, {refined_conversions[orig]}\n")

print(f"\nRefined conversions written to: {output_file}")

cur.close()
conn.close()
