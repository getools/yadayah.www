-- Perry lexicon import (YY_Lexicon.csv) — schema side.
--
-- Adds 'perry' as a word/definition source, gives yy_word a Perry default
-- definition column alongside _kirk/_yy/_external, and creates the staging
-- table that holds the CSV verbatim so every imported value stays auditable.
--
-- Idempotent.  Run:  psql -U postgres -d yada -f perry_lexicon.sql
--
-- ⚠ No ON CONFLICT here on purpose: the _rev triggers fire even for
--   DO NOTHING, which would leave junk revision rows behind.  Guard with
--   NOT EXISTS instead.

BEGIN;

-- 1. Perry as a source.  Sort 2 puts it after kirk/books in the picker.
INSERT INTO yy_word_source (word_source_code, word_source_label, word_source_sort)
SELECT 'perry', 'Perry', 2
WHERE NOT EXISTS (SELECT 1 FROM yy_word_source WHERE word_source_code = 'perry');

-- 2. Perry's default definition, so it shows in the Words list Definition
--    cell and its filters the same way the other three sources do.
--    Safe: trg_yy_word_rev uses explicit column lists, not NEW.*.
ALTER TABLE yy_word     ADD COLUMN IF NOT EXISTS word_definition_perry text;
ALTER TABLE yy_word_rev ADD COLUMN IF NOT EXISTS word_definition_perry text;

-- 3. Carry the new column through the revision trigger.
CREATE OR REPLACE FUNCTION public.trg_yy_word_rev()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
  IF TG_OP = 'DELETE' THEN
    OLD.word_revision_num   := COALESCE(OLD.word_revision_num, 0) + 1;
    OLD.word_revision_dtime := NOW();
    BEGIN
      INSERT INTO yy_word_rev (
        word_revision_delete_dtime, word_key, word_source_code, word_strongs,
        word_hebrew, word_yt, word_translit, word_flag_noun, word_flag_verb,
        word_flag_adjective, word_flag_adverb, word_flag_preposition,
        word_flag_conjunction, word_flag_subst, word_flag_pronoun,
        word_definition_kirk, word_definition_yy, word_definition_external, word_definition_perry,
        word_count_yy, word_active_flag, user_key, word_dtime,
        word_revision_dtime, word_revision_user_key, word_revision_num,
        word_pronunciation_strongs, word_pronunciation_yy,
        word_pronunciation_ipa, word_pronunciation_phonetic, word_gender_key)
      VALUES (
        NOW(), OLD.word_key, OLD.word_source_code, OLD.word_strongs,
        OLD.word_hebrew, OLD.word_yt, OLD.word_translit, OLD.word_flag_noun, OLD.word_flag_verb,
        OLD.word_flag_adjective, OLD.word_flag_adverb, OLD.word_flag_preposition,
        OLD.word_flag_conjunction, OLD.word_flag_subst, OLD.word_flag_pronoun,
        OLD.word_definition_kirk, OLD.word_definition_yy, OLD.word_definition_external, OLD.word_definition_perry,
        OLD.word_count_yy, OLD.word_active_flag, OLD.user_key, OLD.word_dtime,
        OLD.word_revision_dtime, OLD.word_revision_user_key, OLD.word_revision_num,
        OLD.word_pronunciation_strongs, OLD.word_pronunciation_yy,
        OLD.word_pronunciation_ipa, OLD.word_pronunciation_phonetic, OLD.word_gender_key);
    EXCEPTION WHEN OTHERS THEN
      RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
    END;
    RETURN OLD;
  END IF;

  IF TG_OP = 'UPDATE' THEN
    NEW.word_revision_num   := COALESCE(OLD.word_revision_num, 0) + 1;
    NEW.word_revision_dtime := NOW();
  ELSIF TG_OP = 'INSERT' THEN
    IF NEW.word_revision_num   IS NULL THEN NEW.word_revision_num   := 1;     END IF;
    IF NEW.word_revision_dtime IS NULL THEN NEW.word_revision_dtime := NOW(); END IF;
  END IF;

  BEGIN
    INSERT INTO yy_word_rev (
      word_revision_delete_dtime, word_key, word_source_code, word_strongs,
      word_hebrew, word_yt, word_translit, word_flag_noun, word_flag_verb,
      word_flag_adjective, word_flag_adverb, word_flag_preposition,
      word_flag_conjunction, word_flag_subst, word_flag_pronoun,
      word_definition_kirk, word_definition_yy, word_definition_external, word_definition_perry,
      word_count_yy, word_active_flag, user_key, word_dtime,
      word_revision_dtime, word_revision_user_key, word_revision_num,
      word_pronunciation_strongs, word_pronunciation_yy,
      word_pronunciation_ipa, word_pronunciation_phonetic, word_gender_key)
    VALUES (
      NULL, NEW.word_key, NEW.word_source_code, NEW.word_strongs,
      NEW.word_hebrew, NEW.word_yt, NEW.word_translit, NEW.word_flag_noun, NEW.word_flag_verb,
      NEW.word_flag_adjective, NEW.word_flag_adverb, NEW.word_flag_preposition,
      NEW.word_flag_conjunction, NEW.word_flag_subst, NEW.word_flag_pronoun,
      NEW.word_definition_kirk, NEW.word_definition_yy, NEW.word_definition_external, NEW.word_definition_perry,
      NEW.word_count_yy, NEW.word_active_flag, NEW.user_key, NEW.word_dtime,
      NEW.word_revision_dtime, NEW.word_revision_user_key, NEW.word_revision_num,
      NEW.word_pronunciation_strongs, NEW.word_pronunciation_yy,
      NEW.word_pronunciation_ipa, NEW.word_pronunciation_phonetic, NEW.word_gender_key);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;
  RETURN NEW;
END;
$function$

;

-- 4. Staging table: YY_Lexicon.csv verbatim, one row per CSV line.
--    Keeps the columns that have no home on yy_word (occurrence count, match
--    score, confidence, match method, sample verses) and records which word
--    each line produced, so any imported value can be traced back and the
--    whole import rolled back or re-run.
--
--    ⚠ Deliberately has NO _rev trigger: it is bulk-loaded and re-runnable,
--    so revision rows would just be a second copy of the CSV (same reasoning
--    as yy_word_occurrence).
CREATE TABLE IF NOT EXISTS yy_word_perry_import (
    word_perry_import_key         serial PRIMARY KEY,
    word_key                      integer REFERENCES yy_word(word_key) ON DELETE CASCADE,
    word_perry_import_row         integer,        -- 1-based CSV line, header excluded
    word_perry_import_strongs_raw varchar(16),    -- StrongsNumber as written: 'H1'
    word_perry_import_strongs     varchar(8),     -- normalised: 'H0001'
    word_perry_import_hebrew      varchar(250),   -- StrongsHebrew, POINTED as in the CSV
    word_perry_import_pronunciation varchar(250), -- StrongsTransliteration
    word_perry_import_gloss_strongs text,         -- StrongsGloss
    word_perry_import_translit    varchar(250),   -- CraigTransliteration
    word_perry_import_occurrences integer,        -- OccurrenceCount
    word_perry_import_score       numeric(6,3),   -- AvgMatchScore
    word_perry_import_confidence  varchar(16),    -- High | Medium
    word_perry_import_method      varchar(100),   -- Source: Verse-matched / Dictionary-recovered
    word_perry_import_gloss       text,           -- SampleCraigGloss (Perry's definition)
    word_perry_import_verses      text,           -- SampleVerses
    -- CSV clipped 629 SampleCraigGloss and 75 StrongsGloss values at exactly
    -- 100 chars, mid-word.  Flagged so they can be re-exported later; the
    -- imported text gets a trailing ellipsis.
    word_perry_import_gloss_truncated         boolean NOT NULL DEFAULT false,
    word_perry_import_gloss_strongs_truncated boolean NOT NULL DEFAULT false,
    word_perry_import_dtime       timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_word_perry_import_word    ON yy_word_perry_import (word_key);
CREATE INDEX IF NOT EXISTS idx_word_perry_import_strongs ON yy_word_perry_import (word_perry_import_strongs);

COMMIT;
