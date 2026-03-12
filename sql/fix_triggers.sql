-- 1. trg_yy_word_revision: word_id -> word_key
CREATE OR REPLACE FUNCTION trg_yy_word_revision() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    rev_count INT;
    user_key INT;
    entity_key_val INT;
BEGIN
    user_key := COALESCE(NULLIF(current_setting('app.current_user_key', true), ''), '0')::INT;

    IF TG_OP = 'DELETE' THEN
        entity_key_val := OLD.word_key;
    ELSE
        entity_key_val := NEW.word_key;
    END IF;

    SELECT COALESCE(MAX(_revision_count), 0) + 1 INTO rev_count
    FROM rev_yy_word WHERE word_key = entity_key_val;

    IF TG_OP = 'DELETE' THEN
        INSERT INTO rev_yy_word (word_key, word_strongs, word_hebrew, word_yt, word_gender, word_flag_plural, word_flag_noun, word_flag_verb, word_flag_adjective, word_flag_adverb, word_flag_preposition, word_flag_conjunction, word_flag_subst, word_definition_kirk, word_definition_yy, word_definition_external, word_count_yy, word_active_flag, user_id, _remove_dtime, _revision_count, _revision_user_key, _revision_dtime)
        VALUES (OLD.word_key, OLD.word_strongs, OLD.word_hebrew, OLD.word_yt, OLD.word_gender, OLD.word_flag_plural, OLD.word_flag_noun, OLD.word_flag_verb, OLD.word_flag_adjective, OLD.word_flag_adverb, OLD.word_flag_preposition, OLD.word_flag_conjunction, OLD.word_flag_subst, OLD.word_definition_kirk, OLD.word_definition_yy, OLD.word_definition_external, OLD.word_count_yy, OLD.word_active_flag, OLD.user_id, NOW(), rev_count, user_key, NOW());
        RETURN OLD;
    ELSE
        INSERT INTO rev_yy_word (word_key, word_strongs, word_hebrew, word_yt, word_gender, word_flag_plural, word_flag_noun, word_flag_verb, word_flag_adjective, word_flag_adverb, word_flag_preposition, word_flag_conjunction, word_flag_subst, word_definition_kirk, word_definition_yy, word_definition_external, word_count_yy, word_active_flag, user_id, _revision_count, _revision_user_key, _revision_dtime)
        VALUES (NEW.word_key, NEW.word_strongs, NEW.word_hebrew, NEW.word_yt, NEW.word_gender, NEW.word_flag_plural, NEW.word_flag_noun, NEW.word_flag_verb, NEW.word_flag_adjective, NEW.word_flag_adverb, NEW.word_flag_preposition, NEW.word_flag_conjunction, NEW.word_flag_subst, NEW.word_definition_kirk, NEW.word_definition_yy, NEW.word_definition_external, NEW.word_count_yy, NEW.word_active_flag, NEW.user_id, rev_count, user_key, NOW());
        RETURN NEW;
    END IF;
END;
$$;

-- 2. trg_yy_word_spelling_revision: word_spelling_id -> word_spelling_key, word_id -> word_key
CREATE OR REPLACE FUNCTION trg_yy_word_spelling_revision() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    rev_count INT;
    user_key INT;
    entity_key_val INT;
BEGIN
    user_key := COALESCE(NULLIF(current_setting('app.current_user_key', true), ''), '0')::INT;

    IF TG_OP = 'DELETE' THEN
        entity_key_val := OLD.word_spelling_key;
    ELSE
        entity_key_val := NEW.word_spelling_key;
    END IF;

    SELECT COALESCE(MAX(_revision_count), 0) + 1 INTO rev_count
    FROM rev_yy_word_spelling WHERE word_spelling_key = entity_key_val;

    IF TG_OP = 'DELETE' THEN
        INSERT INTO rev_yy_word_spelling (word_spelling_key, word_key, word_spelling_text, word_spelling_sort, word_spelling_count_yy, user_id, _remove_dtime, _revision_count, _revision_user_key, _revision_dtime)
        VALUES (OLD.word_spelling_key, OLD.word_key, OLD.word_spelling_text, OLD.word_spelling_sort, OLD.word_spelling_count_yy, OLD.user_id, NOW(), rev_count, user_key, NOW());
        RETURN OLD;
    ELSE
        INSERT INTO rev_yy_word_spelling (word_spelling_key, word_key, word_spelling_text, word_spelling_sort, word_spelling_count_yy, user_id, _revision_count, _revision_user_key, _revision_dtime)
        VALUES (NEW.word_spelling_key, NEW.word_key, NEW.word_spelling_text, NEW.word_spelling_sort, NEW.word_spelling_count_yy, NEW.user_id, rev_count, user_key, NOW());
        RETURN NEW;
    END IF;
END;
$$;

-- 3. trg_recalc_word_count_yy: word_id -> word_key
CREATE OR REPLACE FUNCTION trg_recalc_word_count_yy() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    target_word_key INTEGER;
BEGIN
    IF TG_OP = 'DELETE' THEN
        target_word_key := OLD.word_key;
    ELSE
        target_word_key := NEW.word_key;
    END IF;

    UPDATE yy_word
    SET word_count_yy = COALESCE(
        (SELECT SUM(word_spelling_count_yy) FROM yy_word_spelling WHERE word_key = target_word_key), 0)
    WHERE word_key = target_word_key;

    IF TG_OP = 'UPDATE' AND OLD.word_key IS DISTINCT FROM NEW.word_key THEN
        UPDATE yy_word
        SET word_count_yy = COALESCE(
            (SELECT SUM(word_spelling_count_yy) FROM yy_word_spelling WHERE word_key = OLD.word_key), 0)
        WHERE word_key = OLD.word_key;
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;
