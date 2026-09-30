-- ═══════════════════════════════════════════════════════════════════════════
-- yy_word_gloss — every place the books PRESENT a lexicon word, with the
-- meaning printed there.
--
-- A "parsed reference" is an italic word inside a parenthesis in one of the
-- books' gloss forms:  (wa ha nabʿym – those who …),  (… tahowr / tohorah –
-- purifying …),  (wa ha nabyʾ ha huwʾ),  (… al-Shaitan | the Adversary …).
-- A mention anywhere else (a title, running English, "the Mowʿabite
-- territory") is not a parsed reference.  See glossEntries() in
-- api/_word_harvest.php.
--
-- Derived index, not user data: rebuilt wholesale, in the same transaction
-- as yy_word_occurrence, by
--     php api/_word_harvest.php --index --apply
-- No _rev table on purpose (a rebuild would write a revision row per line).
-- Location is read from yy_paragraph at query time, and ON DELETE CASCADE
-- from both parents keeps a volume re-parse from leaving orphans.
--
-- word_gloss_seq       order of the presented word within its paragraph
-- word_gloss_translit  the spelling as printed there
-- word_gloss_text      the meaning printed after the dash / bar; NULL for a
--                      bare "(italic words)" parenthesis
--
-- Idempotent.
-- ═══════════════════════════════════════════════════════════════════════════

BEGIN;

CREATE TABLE IF NOT EXISTS yy_word_gloss (
    word_gloss_key      bigserial PRIMARY KEY,
    word_key            integer   NOT NULL
        REFERENCES yy_word(word_key)           ON DELETE CASCADE,
    paragraph_key       integer   NOT NULL
        REFERENCES yy_paragraph(paragraph_key) ON DELETE CASCADE,
    word_gloss_seq      smallint  NOT NULL,
    word_gloss_translit text      NOT NULL,
    word_gloss_text     text
);

CREATE INDEX IF NOT EXISTS ix_word_gloss_word      ON yy_word_gloss (word_key);
CREATE INDEX IF NOT EXISTS ix_word_gloss_paragraph ON yy_word_gloss (paragraph_key);

COMMIT;

-- Verify
SELECT
    (SELECT count(*) FROM yy_word_gloss) AS rows_now,
    (SELECT count(*) FROM information_schema.columns
      WHERE table_name = 'yy_word_gloss')  AS cols;
