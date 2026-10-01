-- 2026-10-01: yy_word.word_excluded_flag — "Not a word".
-- An entry the harvest should never have created (a fragment, an English word,
-- a citation). The row is KEPT so its spellings stay in the harvest's known set
-- and it is never imported again; the flag hides it from the public glossary
-- (api/glossary.php, api/word-lookup.php) and from the admin Words list by
-- default (?excluded=only|all to see it). Set by PUT ?key=N&action=exclude,
-- which never forks a non-YY word.
-- trg_yy_word_rev uses explicit column lists, so it is extended here to carry
-- the flag into yy_word_rev. Rollback: sql/_rollback_trg_yy_word_rev_20261001.sql
-- then DROP COLUMN on both tables.
BEGIN;
ALTER TABLE yy_word_rev ADD COLUMN word_excluded_flag boolean NULL;
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
        word_hebrew, word_yt, word_translit,
        word_definition_kirk, word_definition_yy, word_definition_external, word_definition_perry,
        word_count_yy, word_active_flag, user_key, word_dtime,
        word_revision_dtime, word_revision_user_key, word_revision_num,
        word_pronunciation_strongs, word_pronunciation_yy,
        word_pronunciation_ipa, word_pronunciation_phonetic, word_gender_key,
        word_yy_copy_key, word_language, word_code, word_strongs_key,
        word_excluded_flag)
      VALUES (
        NOW(), OLD.word_key, OLD.word_source_code, OLD.word_strongs,
        OLD.word_hebrew, OLD.word_yt, OLD.word_translit,
        OLD.word_definition_kirk, OLD.word_definition_yy, OLD.word_definition_external, OLD.word_definition_perry,
        OLD.word_count_yy, OLD.word_active_flag, OLD.user_key, OLD.word_dtime,
        OLD.word_revision_dtime, OLD.word_revision_user_key, OLD.word_revision_num,
        OLD.word_pronunciation_strongs, OLD.word_pronunciation_yy,
        OLD.word_pronunciation_ipa, OLD.word_pronunciation_phonetic, OLD.word_gender_key,
        OLD.word_yy_copy_key, OLD.word_language, OLD.word_code, OLD.word_strongs_key,
        OLD.word_excluded_flag);
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
      word_hebrew, word_yt, word_translit,
      word_definition_kirk, word_definition_yy, word_definition_external, word_definition_perry,
      word_count_yy, word_active_flag, user_key, word_dtime,
      word_revision_dtime, word_revision_user_key, word_revision_num,
      word_pronunciation_strongs, word_pronunciation_yy,
      word_pronunciation_ipa, word_pronunciation_phonetic, word_gender_key,
      word_yy_copy_key, word_language, word_code, word_strongs_key,
      word_excluded_flag)
    VALUES (
      NULL, NEW.word_key, NEW.word_source_code, NEW.word_strongs,
      NEW.word_hebrew, NEW.word_yt, NEW.word_translit,
      NEW.word_definition_kirk, NEW.word_definition_yy, NEW.word_definition_external, NEW.word_definition_perry,
      NEW.word_count_yy, NEW.word_active_flag, NEW.user_key, NEW.word_dtime,
      NEW.word_revision_dtime, NEW.word_revision_user_key, NEW.word_revision_num,
      NEW.word_pronunciation_strongs, NEW.word_pronunciation_yy,
      NEW.word_pronunciation_ipa, NEW.word_pronunciation_phonetic, NEW.word_gender_key,
      NEW.word_yy_copy_key, NEW.word_language, NEW.word_code, NEW.word_strongs_key,
      NEW.word_excluded_flag);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;
  RETURN NEW;
END;
$function$;
ALTER TABLE yy_word ADD COLUMN word_excluded_flag boolean NOT NULL DEFAULT false;
CREATE INDEX idx_word_excluded ON yy_word (word_key) WHERE word_excluded_flag;
COMMIT;
