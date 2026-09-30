-- ═══════════════════════════════════════════════════════════════════════════
-- Parsed references: every place the books PRESENT a word, with its meaning.
--
-- A presentation is italic text inside a parenthesis in one of the books'
-- gloss forms:  (wa ha nabʿym – those who …),  (… tahowr / tohorah –
-- purifying …),  (wa ha nabyʾ ha huwʾ),  (… al-Shaitan | the Adversary …).
-- A mention anywhere else (a title, running English, "the Mowʿabite
-- territory") is not a parsed reference.  See glossEntries() in
-- api/_word_harvest.php.
--
-- yy_gloss        one row per presentation: where it is, the phrase as
--                 printed, and the meaning printed after the dash / bar
--                 (NULL for a bare "(italic words)" parenthesis).
-- yy_word_gloss   which words that presentation is a reference for:
--                   'W' the WHOLE — "wa ha nabʿym", or each of "tahowr" and
--                       "tohorah" in "tahowr / tohorah".  The meaning is its.
--                   'P' a PART of a multi-word whole — "wa", "ha", "nabʿym".
--                       Same occurrence, but the meaning is NOT the part's.
--
-- Derived index, not user data: both tables are rebuilt wholesale, in the
-- same transaction as yy_word_occurrence, by
--     php api/_word_harvest.php --index --apply
-- which assigns gloss_key itself (1..n).  No _rev tables on purpose.
-- Location is read from yy_paragraph at query time; ON DELETE CASCADE keeps a
-- volume re-parse or a deleted word from leaving orphans.
--
-- Replaces the first-cut yy_word_gloss (one row per word, meaning copied onto
-- every word) created earlier on 2026-09-30; nothing read it.
-- Idempotent.
-- ═══════════════════════════════════════════════════════════════════════════

BEGIN;

-- The first cut had paragraph_key on yy_word_gloss; drop it if still that shape.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_name = 'yy_word_gloss' AND column_name = 'paragraph_key') THEN
        DROP TABLE yy_word_gloss;
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS yy_gloss (
    gloss_key      bigint    PRIMARY KEY,
    paragraph_key  integer   NOT NULL
        REFERENCES yy_paragraph(paragraph_key) ON DELETE CASCADE,
    gloss_seq      smallint  NOT NULL,          -- order within the paragraph
    gloss_phrase   text      NOT NULL,          -- as printed: "tahowr / tohorah"
    gloss_text     text                         -- the meaning, or NULL
);
CREATE INDEX IF NOT EXISTS ix_gloss_paragraph ON yy_gloss (paragraph_key);

CREATE TABLE IF NOT EXISTS yy_word_gloss (
    word_key             integer NOT NULL
        REFERENCES yy_word(word_key)   ON DELETE CASCADE,
    gloss_key            bigint  NOT NULL
        REFERENCES yy_gloss(gloss_key) ON DELETE CASCADE,
    word_gloss_role      char(1) NOT NULL CHECK (word_gloss_role IN ('W', 'P')),
    word_gloss_translit  text    NOT NULL,      -- the spelling as printed there
    CONSTRAINT yy_word_gloss_pkey PRIMARY KEY (word_key, gloss_key, word_gloss_role)
);
CREATE INDEX IF NOT EXISTS ix_word_gloss_gloss ON yy_word_gloss (gloss_key);

COMMIT;

-- Verify
SELECT
    (SELECT count(*) FROM yy_gloss)      AS glosses,
    (SELECT count(*) FROM yy_word_gloss) AS links,
    (SELECT count(*) FROM information_schema.columns WHERE table_name = 'yy_word_gloss') AS link_cols;
