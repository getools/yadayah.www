"""
Batch lookup Hebrew word definitions via Claude API.
Processes distinct words from dss_word_hebrew, stores results
in dss_word_hebrew_definition.
Resumable: skips words that already have definitions.
"""
import json
import time
import re
import psycopg2
import anthropic
from dotenv import load_dotenv

load_dotenv()

DB = dict(host='localhost', port=5433, dbname='yada', user='postgres', password='yada_password')
BATCH_SIZE = 50
MODEL = 'claude-haiku-4-5-20251001'

PROMPT_TEMPLATE = """You are a Biblical Hebrew lexicon. For each Hebrew word below, provide:
- "literal": the literal/root meaning of the word
- "common": the common English translation used in most Bibles

Reply ONLY with a JSON array of objects, one per word, in the same order as the input.
Each object must have: "word", "literal", "common"
If a word is a single letter fragment or unrecognizable, use "fragment" for both fields.
If a word appears to be reversed (RTL storage issue), try to interpret it in the correct reading direction.

Words:
{words}"""


def get_pending_words(cur):
    """Get distinct words not yet defined."""
    cur.execute("""
        SELECT DISTINCT w.word_hebrew_text
        FROM dss_word_hebrew w
        LEFT JOIN dss_word_hebrew_definition d
            ON d.word_hebrew_key = w.word_hebrew_key
        WHERE d.word_hebrew_definition_key IS NULL
        ORDER BY w.word_hebrew_text
    """)
    return [row[0] for row in cur.fetchall()]


def get_word_keys(cur, words):
    """Get all word_hebrew_key values for a list of words."""
    cur.execute("""
        SELECT word_hebrew_key, word_hebrew_text
        FROM dss_word_hebrew
        WHERE word_hebrew_text = ANY(%s)
    """, (words,))
    result = {}
    for key, text in cur.fetchall():
        result.setdefault(text, []).append(key)
    return result


def lookup_batch(client, words):
    """Call Claude API with a batch of words, return parsed definitions."""
    word_list = "\n".join(f"{i+1}. {w}" for i, w in enumerate(words))
    prompt = PROMPT_TEMPLATE.format(words=word_list)

    response = client.messages.create(
        model=MODEL,
        max_tokens=4096,
        messages=[{"role": "user", "content": prompt}]
    )

    text = response.content[0].text.strip()
    # Extract JSON array from response
    match = re.search(r'\[.*\]', text, re.DOTALL)
    if not match:
        print(f"  WARNING: Could not parse JSON from response: {text[:200]}")
        return None
    return json.loads(match.group())


def main():
    conn = psycopg2.connect(**DB)
    cur = conn.cursor()

    client = anthropic.Anthropic()

    pending = get_pending_words(cur)
    print(f"Words pending definitions: {len(pending)}")

    if not pending:
        print("All words already have definitions.")
        return

    # Limit for testing — remove or increase for full run
    MAX_WORDS = 100
    if len(pending) > MAX_WORDS:
        print(f"Limiting to first {MAX_WORDS} words for test run.")
        pending = pending[:MAX_WORDS]

    total_defined = 0
    total_batches = (len(pending) + BATCH_SIZE - 1) // BATCH_SIZE

    for batch_num in range(0, len(pending), BATCH_SIZE):
        batch = pending[batch_num:batch_num + BATCH_SIZE]
        batch_idx = batch_num // BATCH_SIZE + 1
        print(f"Batch {batch_idx}/{total_batches} ({len(batch)} words)...")

        try:
            definitions = lookup_batch(client, batch)
        except Exception as e:
            print(f"  ERROR on batch {batch_idx}: {e}")
            time.sleep(5)
            continue

        if not definitions:
            continue

        # Build word→definition map from response
        def_map = {}
        for d in definitions:
            word = d.get('word', '')
            def_map[word] = (d.get('literal', ''), d.get('common', ''))

        # Get all word keys for this batch
        word_keys = get_word_keys(cur, batch)

        inserts = 0
        for word in batch:
            literal, common = def_map.get(word, ('unknown', 'unknown'))
            keys = word_keys.get(word, [])
            for key in keys:
                cur.execute("""
                    INSERT INTO dss_word_hebrew_definition
                    (word_hebrew_key, word_hebrew_definition_source,
                     word_hebrew_definition_literal, word_hebrew_definition_common)
                    VALUES (%s, %s, %s, %s)
                """, (key, MODEL, literal, common))
                inserts += 1

        conn.commit()
        total_defined += len(batch)
        print(f"  Inserted {inserts} definition rows. Progress: {total_defined}/{len(pending)}")

        # Rate limiting - small pause between batches
        time.sleep(1)

    # Final stats
    cur.execute("SELECT COUNT(*) FROM dss_word_hebrew_definition")
    print(f"\nDone. Total rows in dss_word_hebrew_definition: {cur.fetchone()[0]}")

    cur.close()
    conn.close()


if __name__ == '__main__':
    main()
