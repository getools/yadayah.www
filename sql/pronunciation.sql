-- ════════════════════════════════════════════════════════════════════════
--  yy_word — Pronunciation fields
--
--    word_pronunciation_strongs   "Strong's"   the Strong's respelling
--    word_pronunciation_yy        "YY"         the Yada Yahowah respelling
--    word_pronunciation_ipa       "IPA"        IPA, fed by the shared helper
--    word_pronunciation_phonetic  "Phonetic"   engine respelling (TTS SUB)
--
--  ⚠ WHY THIS IS ONE TRANSACTION WITH A TRIGGER REWRITE
--  trg_yy_word_rev inserted into yy_word_rev with `VALUES (NULL, NEW.*)`.
--  `NEW.*` expands to every column of yy_word in attnum order, matched
--  positionally against a fixed 25-column target list. ADD COLUMN on yy_word
--  alone therefore makes every INSERT and UPDATE on the table fail — site
--  wide, not just here. So the function is rewritten with explicit column
--  lists (the house pattern, cf. sql/moon_tables.sql) in the same
--  transaction that adds the columns, and the rev table gets them too.
--
--  Note: word_pronunciation_IPA as written unquoted folds to lowercase in
--  Postgres, so the column really is word_pronunciation_ipa.
-- ════════════════════════════════════════════════════════════════════════

BEGIN;

ALTER TABLE yy_word
    ADD COLUMN IF NOT EXISTS word_pronunciation_strongs   text,
    ADD COLUMN IF NOT EXISTS word_pronunciation_yy        text,
    ADD COLUMN IF NOT EXISTS word_pronunciation_ipa       text,
    ADD COLUMN IF NOT EXISTS word_pronunciation_phonetic  text;

ALTER TABLE yy_word_rev
    ADD COLUMN IF NOT EXISTS word_pronunciation_strongs   text,
    ADD COLUMN IF NOT EXISTS word_pronunciation_yy        text,
    ADD COLUMN IF NOT EXISTS word_pronunciation_ipa       text,
    ADD COLUMN IF NOT EXISTS word_pronunciation_phonetic  text;

-- Explicit column lists on both sides, never NEW.* / OLD.*, so a later
-- ALTER TABLE on one side cannot silently shift values into the wrong column
-- or break writes outright. The rev insert is wrapped so a history failure
-- warns instead of killing the write.
CREATE OR REPLACE FUNCTION public.trg_yy_word_rev() RETURNS trigger
LANGUAGE plpgsql AS $function$
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
        word_definition_kirk, word_definition_yy, word_definition_external,
        word_count_yy, word_active_flag, user_key, word_dtime,
        word_revision_dtime, word_revision_user_key, word_revision_num,
        word_pronunciation_strongs, word_pronunciation_yy,
        word_pronunciation_ipa, word_pronunciation_phonetic)
      VALUES (
        NOW(), OLD.word_key, OLD.word_source_code, OLD.word_strongs,
        OLD.word_hebrew, OLD.word_yt, OLD.word_translit, OLD.word_flag_noun, OLD.word_flag_verb,
        OLD.word_flag_adjective, OLD.word_flag_adverb, OLD.word_flag_preposition,
        OLD.word_flag_conjunction, OLD.word_flag_subst, OLD.word_flag_pronoun,
        OLD.word_definition_kirk, OLD.word_definition_yy, OLD.word_definition_external,
        OLD.word_count_yy, OLD.word_active_flag, OLD.user_key, OLD.word_dtime,
        OLD.word_revision_dtime, OLD.word_revision_user_key, OLD.word_revision_num,
        OLD.word_pronunciation_strongs, OLD.word_pronunciation_yy,
        OLD.word_pronunciation_ipa, OLD.word_pronunciation_phonetic);
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
      word_definition_kirk, word_definition_yy, word_definition_external,
      word_count_yy, word_active_flag, user_key, word_dtime,
      word_revision_dtime, word_revision_user_key, word_revision_num,
      word_pronunciation_strongs, word_pronunciation_yy,
      word_pronunciation_ipa, word_pronunciation_phonetic)
    VALUES (
      NULL, NEW.word_key, NEW.word_source_code, NEW.word_strongs,
      NEW.word_hebrew, NEW.word_yt, NEW.word_translit, NEW.word_flag_noun, NEW.word_flag_verb,
      NEW.word_flag_adjective, NEW.word_flag_adverb, NEW.word_flag_preposition,
      NEW.word_flag_conjunction, NEW.word_flag_subst, NEW.word_flag_pronoun,
      NEW.word_definition_kirk, NEW.word_definition_yy, NEW.word_definition_external,
      NEW.word_count_yy, NEW.word_active_flag, NEW.user_key, NEW.word_dtime,
      NEW.word_revision_dtime, NEW.word_revision_user_key, NEW.word_revision_num,
      NEW.word_pronunciation_strongs, NEW.word_pronunciation_yy,
      NEW.word_pronunciation_ipa, NEW.word_pronunciation_phonetic);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;
  RETURN NEW;
END;
$function$;

COMMIT;
