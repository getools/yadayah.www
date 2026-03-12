BEGIN;

-- Drop in reverse dependency order
DROP TABLE IF EXISTS yy_word_definition;
DROP TABLE IF EXISTS yy_word_gender;
DROP TABLE IF EXISTS yy_word_source;
DROP TABLE IF EXISTS yy_word_pos;

-- 1. yy_word_pos
CREATE TABLE yy_word_pos (
    word_pos_key serial PRIMARY KEY,
    word_pos_code varchar(10) NOT NULL UNIQUE,
    word_pos_label varchar(50) NOT NULL
);
INSERT INTO yy_word_pos (word_pos_code, word_pos_label) VALUES
    ('n',     'Noun'),
    ('v',     'Verb'),
    ('adj',   'Adjective'),
    ('adv',   'Adverb'),
    ('prep',  'Preposition'),
    ('conj',  'Conjunction'),
    ('subst', 'Substantive'),
    ('pron',  'Pronoun'),
    ('pl',    'Plural'),
    ('prop',  'Proper Noun'),
    ('part',  'Particle'),
    ('inter', 'Interjection'),
    ('num',   'Numeral');

-- 2. yy_word_source
CREATE TABLE yy_word_source (
    word_source_key serial PRIMARY KEY,
    word_source_code varchar(20) NOT NULL UNIQUE,
    word_source_label varchar(100) NOT NULL
);
INSERT INTO yy_word_source (word_source_code, word_source_label) VALUES
    ('kirk',     'Strong''s Dictionary'),
    ('yy',       'Yada Yahowah'),
    ('external', 'External Source'),
    ('other',    'Other');

-- 3. yy_word_gender
CREATE TABLE yy_word_gender (
    word_gender_key serial PRIMARY KEY,
    word_gender_code varchar(5) NOT NULL UNIQUE,
    word_gender_label varchar(50) NOT NULL
);
INSERT INTO yy_word_gender (word_gender_code, word_gender_label) VALUES
    ('',  'Unknown'),
    ('m', 'Masculine'),
    ('f', 'Feminine'),
    ('b', 'Both'),
    ('n', 'Neuter');

-- 4. yy_word_definition
CREATE TABLE yy_word_definition (
    word_definition_key serial PRIMARY KEY,
    word_key integer NOT NULL REFERENCES yy_word(word_key),
    word_source_key integer NOT NULL REFERENCES yy_word_source(word_source_key),
    word_gender_key integer REFERENCES yy_word_gender(word_gender_key),
    word_pos_key integer REFERENCES yy_word_pos(word_pos_key),
    word_definition_text text,
    word_definition_active_flag boolean NOT NULL DEFAULT true,
    word_definition_dtime timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 5. Populate yy_word_definition from yy_word
-- One row per word per true POS flag, using kirk source and gender mapping

-- noun
INSERT INTO yy_word_definition (word_key, word_source_key, word_gender_key, word_pos_key, word_definition_text)
SELECT w.word_key, s.word_source_key, g.word_gender_key, p.word_pos_key, w.word_definition_kirk
FROM yy_word w
CROSS JOIN yy_word_source s
LEFT JOIN yy_word_gender g ON g.word_gender_code = LOWER(COALESCE(w.word_gender, ''))
JOIN yy_word_pos p ON p.word_pos_code = 'n'
WHERE s.word_source_code = 'kirk'
  AND w.word_flag_noun = true
  AND w.word_definition_kirk IS NOT NULL;

-- verb
INSERT INTO yy_word_definition (word_key, word_source_key, word_gender_key, word_pos_key, word_definition_text)
SELECT w.word_key, s.word_source_key, g.word_gender_key, p.word_pos_key, w.word_definition_kirk
FROM yy_word w
CROSS JOIN yy_word_source s
LEFT JOIN yy_word_gender g ON g.word_gender_code = LOWER(COALESCE(w.word_gender, ''))
JOIN yy_word_pos p ON p.word_pos_code = 'v'
WHERE s.word_source_code = 'kirk'
  AND w.word_flag_verb = true
  AND w.word_definition_kirk IS NOT NULL;

-- adjective
INSERT INTO yy_word_definition (word_key, word_source_key, word_gender_key, word_pos_key, word_definition_text)
SELECT w.word_key, s.word_source_key, g.word_gender_key, p.word_pos_key, w.word_definition_kirk
FROM yy_word w
CROSS JOIN yy_word_source s
LEFT JOIN yy_word_gender g ON g.word_gender_code = LOWER(COALESCE(w.word_gender, ''))
JOIN yy_word_pos p ON p.word_pos_code = 'adj'
WHERE s.word_source_code = 'kirk'
  AND w.word_flag_adjective = true
  AND w.word_definition_kirk IS NOT NULL;

-- adverb
INSERT INTO yy_word_definition (word_key, word_source_key, word_gender_key, word_pos_key, word_definition_text)
SELECT w.word_key, s.word_source_key, g.word_gender_key, p.word_pos_key, w.word_definition_kirk
FROM yy_word w
CROSS JOIN yy_word_source s
LEFT JOIN yy_word_gender g ON g.word_gender_code = LOWER(COALESCE(w.word_gender, ''))
JOIN yy_word_pos p ON p.word_pos_code = 'adv'
WHERE s.word_source_code = 'kirk'
  AND w.word_flag_adverb = true
  AND w.word_definition_kirk IS NOT NULL;

-- preposition
INSERT INTO yy_word_definition (word_key, word_source_key, word_gender_key, word_pos_key, word_definition_text)
SELECT w.word_key, s.word_source_key, g.word_gender_key, p.word_pos_key, w.word_definition_kirk
FROM yy_word w
CROSS JOIN yy_word_source s
LEFT JOIN yy_word_gender g ON g.word_gender_code = LOWER(COALESCE(w.word_gender, ''))
JOIN yy_word_pos p ON p.word_pos_code = 'prep'
WHERE s.word_source_code = 'kirk'
  AND w.word_flag_preposition = true
  AND w.word_definition_kirk IS NOT NULL;

-- conjunction
INSERT INTO yy_word_definition (word_key, word_source_key, word_gender_key, word_pos_key, word_definition_text)
SELECT w.word_key, s.word_source_key, g.word_gender_key, p.word_pos_key, w.word_definition_kirk
FROM yy_word w
CROSS JOIN yy_word_source s
LEFT JOIN yy_word_gender g ON g.word_gender_code = LOWER(COALESCE(w.word_gender, ''))
JOIN yy_word_pos p ON p.word_pos_code = 'conj'
WHERE s.word_source_code = 'kirk'
  AND w.word_flag_conjunction = true
  AND w.word_definition_kirk IS NOT NULL;

-- substantive
INSERT INTO yy_word_definition (word_key, word_source_key, word_gender_key, word_pos_key, word_definition_text)
SELECT w.word_key, s.word_source_key, g.word_gender_key, p.word_pos_key, w.word_definition_kirk
FROM yy_word w
CROSS JOIN yy_word_source s
LEFT JOIN yy_word_gender g ON g.word_gender_code = LOWER(COALESCE(w.word_gender, ''))
JOIN yy_word_pos p ON p.word_pos_code = 'subst'
WHERE s.word_source_code = 'kirk'
  AND w.word_flag_subst = true
  AND w.word_definition_kirk IS NOT NULL;

-- pronoun
INSERT INTO yy_word_definition (word_key, word_source_key, word_gender_key, word_pos_key, word_definition_text)
SELECT w.word_key, s.word_source_key, g.word_gender_key, p.word_pos_key, w.word_definition_kirk
FROM yy_word w
CROSS JOIN yy_word_source s
LEFT JOIN yy_word_gender g ON g.word_gender_code = LOWER(COALESCE(w.word_gender, ''))
JOIN yy_word_pos p ON p.word_pos_code = 'pron'
WHERE s.word_source_code = 'kirk'
  AND w.word_flag_pronoun = true
  AND w.word_definition_kirk IS NOT NULL;

-- plural
INSERT INTO yy_word_definition (word_key, word_source_key, word_gender_key, word_pos_key, word_definition_text)
SELECT w.word_key, s.word_source_key, g.word_gender_key, p.word_pos_key, w.word_definition_kirk
FROM yy_word w
CROSS JOIN yy_word_source s
LEFT JOIN yy_word_gender g ON g.word_gender_code = LOWER(COALESCE(w.word_gender, ''))
JOIN yy_word_pos p ON p.word_pos_code = 'pl'
WHERE s.word_source_code = 'kirk'
  AND w.word_flag_plural = true
  AND w.word_definition_kirk IS NOT NULL;

-- Words with NO flags at all get one row with NULL word_pos_key
INSERT INTO yy_word_definition (word_key, word_source_key, word_gender_key, word_pos_key, word_definition_text)
SELECT w.word_key, s.word_source_key, g.word_gender_key, NULL, w.word_definition_kirk
FROM yy_word w
CROSS JOIN yy_word_source s
LEFT JOIN yy_word_gender g ON g.word_gender_code = LOWER(COALESCE(w.word_gender, ''))
WHERE s.word_source_code = 'kirk'
  AND w.word_definition_kirk IS NOT NULL
  AND COALESCE(w.word_flag_noun, false) = false
  AND COALESCE(w.word_flag_verb, false) = false
  AND COALESCE(w.word_flag_adjective, false) = false
  AND COALESCE(w.word_flag_adverb, false) = false
  AND COALESCE(w.word_flag_preposition, false) = false
  AND COALESCE(w.word_flag_conjunction, false) = false
  AND COALESCE(w.word_flag_subst, false) = false
  AND COALESCE(w.word_flag_pronoun, false) = false
  AND COALESCE(w.word_flag_plural, false) = false;

COMMIT;
