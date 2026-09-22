-- ═══════════════════════════════════════════════════════════════════════════
-- yy_word_occurrence — where in the books each lexicon word appears.
--
-- Derived index, not user data: one row per (word, paragraph) with how many
-- times that word's spellings occur in that paragraph.  Rebuilt wholesale by
--     php api/_word_harvest.php --index --apply
-- from the SAME tokenizer pass that computes yy_word.word_count_yy, so the
-- drill-down totals and the count column agree by construction.
--
-- Deliberately has NO _rev table or audit trigger — nothing edits it by hand,
-- and a rebuild would otherwise write ~415k revision rows every run.
--
-- The series/volume/chapter/page a row belongs to is NOT stored: it is read
-- from yy_paragraph at query time, so a re-parse that moves a paragraph can
-- never leave a stale location behind.  ON DELETE CASCADE on paragraph_key
-- keeps re-parsed volumes from leaving orphans (which would silently
-- undercount, since the drill-down joins through yy_paragraph).
--
-- Idempotent.
-- ═══════════════════════════════════════════════════════════════════════════

BEGIN;

CREATE TABLE IF NOT EXISTS yy_word_occurrence (
    word_key         integer  NOT NULL
        REFERENCES yy_word(word_key)      ON DELETE CASCADE,
    paragraph_key    integer  NOT NULL
        REFERENCES yy_paragraph(paragraph_key) ON DELETE CASCADE,
    occurrence_count smallint NOT NULL,
    CONSTRAINT yy_word_occurrence_pkey PRIMARY KEY (word_key, paragraph_key)
);

-- The cascade from yy_paragraph needs this to avoid a seq scan per deleted
-- paragraph during a volume re-parse.
CREATE INDEX IF NOT EXISTS ix_word_occurrence_paragraph
    ON yy_word_occurrence (paragraph_key);

COMMIT;

-- Verify
SELECT
    (SELECT count(*) FROM yy_word_occurrence) AS rows_now,
    (SELECT count(*) FROM information_schema.columns
      WHERE table_name = 'yy_word_occurrence')  AS cols;
