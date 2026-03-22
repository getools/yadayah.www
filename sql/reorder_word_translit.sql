BEGIN;

-- 1. Drop FK constraints referencing yy_word
ALTER TABLE yy_word_import DROP CONSTRAINT yy_word_import_word_key_fkey;
ALTER TABLE yy_word_translation DROP CONSTRAINT yy_word_translation_word_key_fkey;
ALTER TABLE yy_word_translit DROP CONSTRAINT yy_word_translit_word_key_fkey;
ALTER TABLE yy_word_twot DROP CONSTRAINT yy_word_twot_word_key_fkey;

-- 2. Drop triggers on yy_word
DROP TRIGGER IF EXISTS trg_word_set_user ON yy_word;
DROP TRIGGER IF EXISTS trg_word_yt ON yy_word;
DROP TRIGGER IF EXISTS trg_word_yt_generate ON yy_word;
DROP TRIGGER IF EXISTS trg_yy_word_ai ON yy_word;
DROP TRIGGER IF EXISTS trg_yy_word_au ON yy_word;
DROP TRIGGER IF EXISTS trg_yy_word_bd ON yy_word;

-- 3. Drop constraints on yy_word
ALTER TABLE yy_word DROP CONSTRAINT yy_word_pkey;
ALTER TABLE yy_word DROP CONSTRAINT yy_word_user_key_fkey;

-- 4. Rename old table
ALTER TABLE yy_word RENAME TO yy_word_old;

-- 5. Create new table with word_translit after word_yt
CREATE TABLE yy_word (
    word_key integer NOT NULL DEFAULT nextval('yy_word_word_key_seq'::regclass),
    word_source_code character varying(250),
    word_strongs character(4),
    word_hebrew character varying(250),
    word_yt character varying(250),
    word_translit character varying(250),
    word_gender character(1),
    word_flag_plural boolean,
    word_flag_noun boolean,
    word_flag_verb boolean,
    word_flag_adjective boolean,
    word_flag_adverb boolean,
    word_flag_preposition boolean,
    word_flag_conjunction boolean,
    word_flag_subst boolean,
    word_flag_pronoun boolean,
    word_definition_kirk text,
    word_definition_yy text,
    word_definition_external text,
    word_count_yy integer,
    word_active_flag boolean NOT NULL DEFAULT true,
    user_key integer,
    word_dtime timestamp without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 6. Copy data
INSERT INTO yy_word (word_key, word_source_code, word_strongs, word_hebrew, word_yt, word_translit,
    word_gender, word_flag_plural, word_flag_noun, word_flag_verb, word_flag_adjective,
    word_flag_adverb, word_flag_preposition, word_flag_conjunction, word_flag_subst, word_flag_pronoun,
    word_definition_kirk, word_definition_yy, word_definition_external, word_count_yy,
    word_active_flag, user_key, word_dtime)
SELECT word_key, word_source_code, word_strongs, word_hebrew, word_yt, word_translit,
    word_gender, word_flag_plural, word_flag_noun, word_flag_verb, word_flag_adjective,
    word_flag_adverb, word_flag_preposition, word_flag_conjunction, word_flag_subst, word_flag_pronoun,
    word_definition_kirk, word_definition_yy, word_definition_external, word_count_yy,
    word_active_flag, user_key, word_dtime
FROM yy_word_old;

-- 7. Detach sequence from old table, then drop it
ALTER SEQUENCE yy_word_word_key_seq OWNED BY NONE;
DROP TABLE yy_word_old;

-- 8. Restore PK and own FK
ALTER TABLE yy_word ADD CONSTRAINT yy_word_pkey PRIMARY KEY (word_key);
ALTER TABLE yy_word ADD CONSTRAINT yy_word_user_key_fkey FOREIGN KEY (user_key) REFERENCES yy_user(yy_user_key);

-- 9. Restore referencing FKs
ALTER TABLE yy_word_import ADD CONSTRAINT yy_word_import_word_key_fkey FOREIGN KEY (word_key) REFERENCES yy_word(word_key);
ALTER TABLE yy_word_translation ADD CONSTRAINT yy_word_translation_word_key_fkey FOREIGN KEY (word_key) REFERENCES yy_word(word_key) ON UPDATE CASCADE ON DELETE CASCADE;
ALTER TABLE yy_word_translit ADD CONSTRAINT yy_word_translit_word_key_fkey FOREIGN KEY (word_key) REFERENCES yy_word(word_key);
ALTER TABLE yy_word_twot ADD CONSTRAINT yy_word_twot_word_key_fkey FOREIGN KEY (word_key) REFERENCES yy_word(word_key);

-- 10. Reassign sequence ownership
ALTER SEQUENCE yy_word_word_key_seq OWNED BY yy_word.word_key;

-- 11. Recreate triggers
CREATE TRIGGER trg_word_set_user BEFORE INSERT OR UPDATE ON yy_word FOR EACH ROW EXECUTE FUNCTION trg_set_word_user_key();
CREATE TRIGGER trg_word_yt BEFORE INSERT OR UPDATE OF word_hebrew ON yy_word FOR EACH ROW EXECUTE FUNCTION trg_word_yt();
CREATE TRIGGER trg_word_yt_generate BEFORE INSERT OR UPDATE OF word_hebrew ON yy_word FOR EACH ROW EXECUTE FUNCTION trg_word_yt();
CREATE TRIGGER trg_yy_word_ai AFTER INSERT ON yy_word FOR EACH ROW EXECUTE FUNCTION trg_yy_word_revision();
CREATE TRIGGER trg_yy_word_au AFTER UPDATE ON yy_word FOR EACH ROW EXECUTE FUNCTION trg_yy_word_revision();
CREATE TRIGGER trg_yy_word_bd BEFORE DELETE ON yy_word FOR EACH ROW EXECUTE FUNCTION trg_yy_word_revision();

COMMIT;
