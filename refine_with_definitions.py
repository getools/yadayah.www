"""
Refine Hebrew modifier conversions using word definitions from the database.
Analyzes word_definition_yy and word_definition_kirk to determine whether
apostrophes represent Aleph (ʾ) or Ayin (ʿ).
"""

import sys, os, re
sys.stdout.reconfigure(encoding='utf-8')
from dotenv import load_dotenv
load_dotenv(r'C:\Users\Joe\Work\dev\yada\translations\.env')
import psycopg2
from collections import Counter

conn = psycopg2.connect(
    host=os.getenv('POSTGRES_HOST', 'localhost'),
    port=os.getenv('POSTGRES_PORT', '5432'),
    user=os.getenv('POSTGRES_USER', 'postgres'),
    password=os.getenv('POSTGRES_PASSWORD'),
    dbname=os.getenv('POSTGRES_DB', 'yada')
)
cur = conn.cursor()

# Get all words - filter for quotes in Python since SQL LIKE has issues with curly quotes
cur.execute("""
    SELECT DISTINCT w.word_translit, w.word_hebrew, w.word_strongs,
           w.word_definition_yy, w.word_definition_kirk
    FROM yy_word w
    WHERE w.word_translit != ''
    ORDER BY w.word_translit
""")

all_word_rows = cur.fetchall()
# Check for curly quotes (U+2018, U+2019) and straight apostrophe
LEFT_CURLY = chr(0x2018)
RIGHT_CURLY = chr(0x2019)
STRAIGHT_QUOTE = "'"
words_with_defs = [(yt, heb, s, dy, dk) for yt, heb, s, dy, dk in all_word_rows
                   if LEFT_CURLY in yt or RIGHT_CURLY in yt or STRAIGHT_QUOTE in yt]
print(f"Found {len(words_with_defs)} words with quotes in database (from {len(all_word_rows)} total)\n")

# Also get all spellings
cur.execute("""
    SELECT DISTINCT ws.word_translit_text, w.word_hebrew, w.word_strongs,
           w.word_definition_yy, w.word_definition_kirk
    FROM yy_word_translit ws
    JOIN yy_word w ON w.word_key = ws.word_id
    ORDER BY ws.word_translit_text
""")

all_spelling_rows = cur.fetchall()
spellings_with_defs = [(sp, heb, s, dy, dk) for sp, heb, s, dy, dk in all_spelling_rows
                       if LEFT_CURLY in sp or RIGHT_CURLY in sp or STRAIGHT_QUOTE in sp]
print(f"Found {len(spellings_with_defs)} spellings with quotes in database (from {len(all_spelling_rows)} total)\n")

# Combine - normalize curly quotes to straight apostrophe for analysis
def has_quote(s):
    return LEFT_CURLY in s or RIGHT_CURLY in s or STRAIGHT_QUOTE in s

all_words = {}
for word_translit, heb, strongs, def_yy, def_kirk in words_with_defs:
    if has_quote(word_translit):
        # Normalize to straight apostrophe for analysis
        normalized = word_translit.replace(LEFT_CURLY, "'").replace(RIGHT_CURLY, "'")
        all_words[normalized] = {
            'original': word_translit,
            'hebrew': heb or '',
            'strongs': (strongs or '').strip(),
            'def_yy': def_yy or '',
            'def_kirk': def_kirk or ''
        }

for spelling, heb, strongs, def_yy, def_kirk in spellings_with_defs:
    normalized = spelling.replace(LEFT_CURLY, "'").replace(RIGHT_CURLY, "'")
    if has_quote(spelling) and normalized not in all_words:
        all_words[normalized] = {
            'original': spelling,
            'hebrew': heb or '',
            'strongs': (strongs or '').strip(),
            'def_yy': def_yy or '',
            'def_kirk': def_kirk or ''
        }

print(f"Total unique words/spellings with quotes: {len(all_words)}\n")

# Linguistic patterns indicating Ayin (ע) - pharyngeal fricative
# Common in words meaning: see, know, eye, gate, help, work, people, stand
AYIN_PATTERNS = [
    # Visual/perception
    r'\b(see|saw|seen|seeing|sight|look|watch|observe|gaze|vision|appear)',
    r'\b(eye|eyes)',
    # Knowledge/wisdom
    r'\b(know|knowledge|knowing|wise|wisdom|understand|discern)',
    # Physical structures
    r'\b(gate|gates|door|entrance|opening)',
    r'\b(fountain|spring|well|source)',
    # Action/work
    r'\b(help|aid|assist|support)',
    r'\b(work|works|labor|deed|deeds|act|acts|do|did|done|make|made)',
    r'\b(answer|answers|respond|testify|witness|testimony)',
    # Standing/rising
    r'\b(stand|stood|standing|rise|arose|risen)',
    # Affliction/humility
    r'\b(humble|humbled|afflict|afflicted|oppress|bow|bowed)',
    # Exposure
    r'\b(naked|bare|exposed|uncover)',
    # Animals/people
    r'\b(flock|sheep|goat|people|nation)',
    # Hebrew letter name
    r'\bayin\b',
    # Common Ayin words
    r'\b(ba.?al|ya.?aqob|ra.?ah|na.?ar|sha.?ar|ga.?al|ma.?al|pa.?al)',
]

# Linguistic patterns indicating Aleph (א) - glottal stop
# Common in words meaning: father, brother, lord, god, man, say, eat, not
ALEPH_PATTERNS = [
    # Family
    r'\b(father|fathers|ancestor|forefather)',
    r'\b(brother|brothers|kinsman)',
    # Deity/lordship
    r'\b(lord|master|sovereign|adon)',
    r'\b(god|gods|deity|divine|elohim)',
    # Humanity
    r'\b(man|men|mankind|adam|human|person|people)',
    # Speech
    r'\b(say|says|said|speak|speaks|spoke|utter|declare)',
    # Consumption
    r'\b(eat|eats|ate|food|consume|devour)',
    # Negation
    r'\b(not|no|none|nothing|never)',
    # Quantities
    r'\b(thousand|thousands|chief|clan)',
    # Hebrew letter name
    r'\baleph\b',
    # Common Aleph-initial words
    r'\b(abraham|adam|adon|elohim)',
]

def score_word(word, data):
    """Score likelihood of Ayin vs Aleph based on definitions."""
    # Combine all text
    text = f"{word} {data['hebrew']} {data['def_yy']} {data['def_kirk']}".lower()

    ayin_score = 0
    aleph_score = 0

    # Check patterns
    for pattern in AYIN_PATTERNS:
        if re.search(pattern, text, re.IGNORECASE):
            ayin_score += 1

    for pattern in ALEPH_PATTERNS:
        if re.search(pattern, text, re.IGNORECASE):
            aleph_score += 1

    # Strong's number hints
    strongs = data['strongs']
    if strongs:
        # Famous Ayin words
        if strongs in ('5869', '5707', '5712', '5749', '5750', '5753', '5756',  # ayin, ed (witness)
                       '5971',  # am (people)
                       '6213',  # asah (do/make)
                       '5975',  # amad (stand)
                       '5030'):  # nabi (prophet - has ayin)
            ayin_score += 3

        # Famous Aleph words
        if strongs in ('1',      # ab (father)
                       '251',    # ach (brother)
                       '113',    # adon (lord)
                       '120',    # adam (man)
                       '430',    # elohim (god)
                       '505',    # eleph (thousand)
                       '559',    # amar (say)
                       '398'):   # akal (eat)
            aleph_score += 3

    return ayin_score, aleph_score

def convert_word_refined(word, ayin_score, aleph_score):
    """Convert apostrophes based on scores."""
    if "'" not in word:
        return word

    # Word-initial apostrophe is ALWAYS Aleph (glottal stop at beginning)
    if word.startswith("'"):
        result = "ʾ" + word[1:]
        # Remaining mid-word apostrophes: use scores
        if "'" in result:
            if ayin_score > aleph_score:
                result = result.replace("'", "ʿ")
            elif aleph_score > ayin_score:
                result = result.replace("'", "ʾ")
            else:
                # Default mid-word = Ayin
                result = result.replace("'", "ʿ")
        return result

    # Mid-word only: use scores
    if ayin_score > aleph_score:
        return word.replace("'", "ʿ")
    elif aleph_score > ayin_score:
        return word.replace("'", "ʾ")
    else:
        # Default mid-word = Ayin
        return word.replace("'", "ʿ")

# Process all words
conversions = []
score_distribution = Counter()
corrections_from_simple = []

for word, data in sorted(all_words.items()):
    ayin_score, aleph_score = score_word(word, data)
    score_distribution[(ayin_score, aleph_score)] += 1

    refined = convert_word_refined(word, ayin_score, aleph_score)

    # Simple rule for comparison
    if word.startswith("'"):
        simple = "ʾ" + word[1:].replace("'", "ʿ")
    else:
        simple = word.replace("'", "ʿ")

    conversions.append((word, refined))

    # Note if definition-based differs from simple rule
    if refined != simple and (ayin_score > 0 or aleph_score > 0):
        corrections_from_simple.append({
            'word': word,
            'simple': simple,
            'refined': refined,
            'ayin_score': ayin_score,
            'aleph_score': aleph_score,
            'def_kirk': data['def_kirk'][:100] if data['def_kirk'] else '',
            'def_yy': data['def_yy'][:100] if data['def_yy'] else ''
        })

# Write output
output_file = r'C:\Users\Joe\Work\dev\yada\translations\hebrew_modifier_conversions_refined.txt'
with open(output_file, 'w', encoding='utf-8') as f:
    f.write("Original, Converted (Definition-Refined)\n")
    f.write("-" * 80 + "\n")
    for orig, conv in conversions:
        f.write(f"{orig}, {conv}\n")

    # Append analysis
    f.write("\n" + "=" * 80 + "\n")
    f.write("REFINEMENT ANALYSIS\n")
    f.write("=" * 80 + "\n\n")

    f.write(f"Total words analyzed: {len(conversions)}\n")
    f.write(f"Words with definition-based corrections: {len(corrections_from_simple)}\n\n")

    if corrections_from_simple:
        f.write("Corrections from simple rule:\n")
        f.write("-" * 80 + "\n")
        for c in corrections_from_simple[:50]:  # First 50
            f.write(f"\n{c['word']}\n")
            f.write(f"  Simple:  {c['simple']}\n")
            f.write(f"  Refined: {c['refined']}\n")
            f.write(f"  Scores:  Ayin={c['ayin_score']}, Aleph={c['aleph_score']}\n")
            if c['def_kirk']:
                f.write(f"  Kirk: {c['def_kirk']}...\n")
            if c['def_yy']:
                f.write(f"  YY: {c['def_yy']}...\n")

    f.write("\n\nScore distribution (ayin_score, aleph_score): count\n")
    for (a_score, b_score), count in sorted(score_distribution.items()):
        f.write(f"  ({a_score}, {b_score}): {count}\n")

print(f"\nRefined conversions written to:\n{output_file}")
print(f"\nTotal: {len(conversions)} words")
print(f"Definition-based corrections: {len(corrections_from_simple)}")

print("\nTop 10 corrections:")
for c in corrections_from_simple[:10]:
    print(f"  {c['word']} → {c['refined']} (was {c['simple']}) [Ayin={c['ayin_score']}, Aleph={c['aleph_score']}]")

cur.close()
conn.close()
