CREATE OR REPLACE FUNCTION public.trg_yy_word_rev()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
      BEGIN
        IF TG_OP = 'DELETE' THEN
          OLD.word_revision_num := COALESCE(OLD.word_revision_num, 0) + 1;
          OLD.word_revision_dtime := NOW();
          INSERT INTO yy_word_rev (word_revision_delete_dtime, word_key, word_source_code, word_strongs, word_hebrew, word_yt, word_translit, word_flag_noun, word_flag_verb, word_flag_adjective, word_flag_adverb, word_flag_preposition, word_flag_conjunction, word_flag_subst, word_flag_pronoun, word_definition_kirk, word_definition_yy, word_definition_external, word_count_yy, word_active_flag, user_key, word_dtime, word_revision_dtime, word_revision_user_key, word_revision_num) VALUES (NOW(), OLD.*);
          RETURN OLD;
        END IF;
        IF TG_OP = 'UPDATE' THEN
          NEW.word_revision_num := COALESCE(OLD.word_revision_num, 0) + 1;
          NEW.word_revision_dtime := NOW();
        ELSIF TG_OP = 'INSERT' THEN
          IF NEW.word_revision_num IS NULL THEN NEW.word_revision_num := 1; END IF;
          IF NEW.word_revision_dtime IS NULL THEN NEW.word_revision_dtime := NOW(); END IF;
        END IF;
        INSERT INTO yy_word_rev (word_revision_delete_dtime, word_key, word_source_code, word_strongs, word_hebrew, word_yt, word_translit, word_flag_noun, word_flag_verb, word_flag_adjective, word_flag_adverb, word_flag_preposition, word_flag_conjunction, word_flag_subst, word_flag_pronoun, word_definition_kirk, word_definition_yy, word_definition_external, word_count_yy, word_active_flag, user_key, word_dtime, word_revision_dtime, word_revision_user_key, word_revision_num) VALUES (NULL, NEW.*);
        RETURN NEW;
      END; $function$

;
