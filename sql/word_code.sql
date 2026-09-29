-- 2026-09-28: yy_word.word_code varchar(250) (user asked for nvarchar(250); PG varchar is Unicode in this UTF8 db).
-- trg_yy_word_rev uses explicit column lists, so it is extended here to carry word_code into yy_word_rev.
BEGIN;
ALTER TABLE yy_word     ADD COLUMN word_code varchar(250) NULL;
ALTER TABLE yy_word_rev ADD COLUMN word_code varchar(250) NULL;
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
        word_pronunciation_ipa, word_pronunciation_phonetic, word_gender_key,
        word_yy_copy_key, word_language, word_code)
      VALUES (
        NOW(), OLD.word_key, OLD.word_source_code, OLD.word_strongs,
        OLD.word_hebrew, OLD.word_yt, OLD.word_translit, OLD.word_flag_noun, OLD.word_flag_verb,
        OLD.word_flag_adjective, OLD.word_flag_adverb, OLD.word_flag_preposition,
        OLD.word_flag_conjunction, OLD.word_flag_subst, OLD.word_flag_pronoun,
        OLD.word_definition_kirk, OLD.word_definition_yy, OLD.word_definition_external, OLD.word_definition_perry,
        OLD.word_count_yy, OLD.word_active_flag, OLD.user_key, OLD.word_dtime,
        OLD.word_revision_dtime, OLD.word_revision_user_key, OLD.word_revision_num,
        OLD.word_pronunciation_strongs, OLD.word_pronunciation_yy,
        OLD.word_pronunciation_ipa, OLD.word_pronunciation_phonetic, OLD.word_gender_key,
        OLD.word_yy_copy_key, OLD.word_language, OLD.word_code);
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
      word_pronunciation_ipa, word_pronunciation_phonetic, word_gender_key,
      word_yy_copy_key, word_language, word_code)
    VALUES (
      NULL, NEW.word_key, NEW.word_source_code, NEW.word_strongs,
      NEW.word_hebrew, NEW.word_yt, NEW.word_translit, NEW.word_flag_noun, NEW.word_flag_verb,
      NEW.word_flag_adjective, NEW.word_flag_adverb, NEW.word_flag_preposition,
      NEW.word_flag_conjunction, NEW.word_flag_subst, NEW.word_flag_pronoun,
      NEW.word_definition_kirk, NEW.word_definition_yy, NEW.word_definition_external, NEW.word_definition_perry,
      NEW.word_count_yy, NEW.word_active_flag, NEW.user_key, NEW.word_dtime,
      NEW.word_revision_dtime, NEW.word_revision_user_key, NEW.word_revision_num,
      NEW.word_pronunciation_strongs, NEW.word_pronunciation_yy,
      NEW.word_pronunciation_ipa, NEW.word_pronunciation_phonetic, NEW.word_gender_key,
      NEW.word_yy_copy_key, NEW.word_language, NEW.word_code);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;
  RETURN NEW;
END;
$function$

;
COMMIT;
