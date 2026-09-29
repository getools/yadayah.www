-- ════════════════════════════════════════════════════════════════════════
--  yy_word_strongs — many Strong's entries per word (2026-09-29)
--
--  One row per Strong's entry associated with a yy_word. yy_word.word_strongs_key
--  names the preferred one, and yy_word.word_strongs stays as the MIRROR of that
--  row's code (the same pattern as word_translit ↔ yy_word_translit), so the
--  public glossary, words.php, word-lookup.php and the list sort keep reading
--  what they always have. NULL vs '' on word_strongs keeps its meaning for a
--  word with no rows: NULL = not yet determined, '' = has no Strong's entry.
--
--  word_strongs_code is DERIVED (language + 4-digit number + suffix) by
--  trg_word_strongs_code, so it can never disagree with its parts. That trigger
--  sorts before trg_yy_word_strongs_rev (triggers fire in name order), so the
--  history row records the derived code.
--
--  Parts of speech move here as 23 booleans. Seeded from the UNION of the dead
--  yy_word.word_flag_* columns AND yy_word_pos_map (the junction the editor has
--  written since 2026-09-11 — skipping it would lose those edits). The flags are
--  then DROPPED from yy_word; yy_word_rev keeps its copies as history.
--  yy_word_pos_map is left in place, no longer read or written by the editor.
--
--  ⚠ trg_yy_word_rev uses explicit column lists, so it is rewritten here to add
--    word_strongs_key and drop the flags, in the same transaction as the ALTERs.
-- ════════════════════════════════════════════════════════════════════════

BEGIN;

CREATE TABLE yy_word_strongs (
    word_strongs_key                          serial PRIMARY KEY,
    word_key                                  integer NOT NULL REFERENCES yy_word(word_key) ON DELETE CASCADE,
    word_strongs_code                         varchar(8) NOT NULL,          -- derived: H0430a
    word_strongs_number                       smallint NOT NULL CHECK (word_strongs_number BETWEEN 1 AND 9999),
    word_strongs_language                     char(1) NOT NULL CHECK (word_strongs_language IN ('H', 'G')),
    word_strongs_suffix                       varchar(1) CHECK (word_strongs_suffix ~ '^[a-z]$'),
    word_strongs_original                     varchar(64),                  -- Hebrew / Greek / Aramaic letters
    word_strongs_translit                     varchar(64),
    word_strongs_pronunciation                varchar(100),
    word_strongs_phonetic                     varchar(100),
    word_strongs_definition                   text,
    word_strongs_origin                       varchar(64),
    word_strongs_gender                       char(1) CHECK (word_strongs_gender IN ('m', 'f', 'n', 'x')),
    word_strongs_pos_adjective                boolean NOT NULL DEFAULT false,
    word_strongs_pos_adverb                   boolean NOT NULL DEFAULT false,
    word_strongs_pos_article                  boolean NOT NULL DEFAULT false,
    word_strongs_pos_conjunction              boolean NOT NULL DEFAULT false,
    word_strongs_pos_gentilic                 boolean NOT NULL DEFAULT false,
    word_strongs_pos_interjection             boolean NOT NULL DEFAULT false,
    word_strongs_pos_noun                     boolean NOT NULL DEFAULT false,
    word_strongs_pos_particle                 boolean NOT NULL DEFAULT false,
    word_strongs_pos_patronymic               boolean NOT NULL DEFAULT false,
    word_strongs_pos_preposition              boolean NOT NULL DEFAULT false,
    word_strongs_pos_pronoun                  boolean NOT NULL DEFAULT false,
    word_strongs_pos_substantive              boolean NOT NULL DEFAULT false,
    word_strongs_pos_verb                     boolean NOT NULL DEFAULT false,
    word_strongs_pos_infinitive               boolean NOT NULL DEFAULT false,
    word_strongs_pos_noun_proper              boolean NOT NULL DEFAULT false,
    word_strongs_pos_participle               boolean NOT NULL DEFAULT false,
    word_strongs_pos_participle_interrogative boolean NOT NULL DEFAULT false,
    word_strongs_pos_participle_relative      boolean NOT NULL DEFAULT false,
    word_strongs_pos_pronoun_demonstrative    boolean NOT NULL DEFAULT false,
    word_strongs_pos_pronoun_indefinite       boolean NOT NULL DEFAULT false,
    word_strongs_pos_pronoun_interrogative    boolean NOT NULL DEFAULT false,
    word_strongs_pos_pronoun_personal         boolean NOT NULL DEFAULT false,
    word_strongs_pos_pronoun_relative         boolean NOT NULL DEFAULT false,
    word_strongs_dtime                        timestamptz NOT NULL DEFAULT now(),
    word_strongs_revision_dtime               timestamptz DEFAULT now(),
    word_strongs_revision_user_key            integer DEFAULT 0,
    word_strongs_revision_num                 integer DEFAULT 1,
    -- A word lists each Strong's entry once.
    CONSTRAINT yy_word_strongs_word_code_uniq UNIQUE (word_key, word_strongs_code),
    -- Target of yy_word's composite FK: the preferred row must belong to that word.
    CONSTRAINT yy_word_strongs_key_word_uniq  UNIQUE (word_strongs_key, word_key)
);
CREATE INDEX idx_yy_word_strongs_code ON yy_word_strongs (word_strongs_code);

CREATE TABLE yy_word_strongs_rev (
    word_strongs_revision_id                  bigserial PRIMARY KEY,
    word_strongs_revision_delete_dtime        timestamptz,
    word_strongs_key                          integer,
    word_key                                  integer,
    word_strongs_code                         varchar(8),
    word_strongs_number                       smallint,
    word_strongs_language                     char(1),
    word_strongs_suffix                       varchar(1),
    word_strongs_original                     varchar(64),
    word_strongs_translit                     varchar(64),
    word_strongs_pronunciation                varchar(100),
    word_strongs_phonetic                     varchar(100),
    word_strongs_definition                   text,
    word_strongs_origin                       varchar(64),
    word_strongs_gender                       char(1),
    word_strongs_pos_adjective                boolean,
    word_strongs_pos_adverb                   boolean,
    word_strongs_pos_article                  boolean,
    word_strongs_pos_conjunction              boolean,
    word_strongs_pos_gentilic                 boolean,
    word_strongs_pos_interjection             boolean,
    word_strongs_pos_noun                     boolean,
    word_strongs_pos_particle                 boolean,
    word_strongs_pos_patronymic               boolean,
    word_strongs_pos_preposition              boolean,
    word_strongs_pos_pronoun                  boolean,
    word_strongs_pos_substantive              boolean,
    word_strongs_pos_verb                     boolean,
    word_strongs_pos_infinitive               boolean,
    word_strongs_pos_noun_proper              boolean,
    word_strongs_pos_participle               boolean,
    word_strongs_pos_participle_interrogative boolean,
    word_strongs_pos_participle_relative      boolean,
    word_strongs_pos_pronoun_demonstrative    boolean,
    word_strongs_pos_pronoun_indefinite       boolean,
    word_strongs_pos_pronoun_interrogative    boolean,
    word_strongs_pos_pronoun_personal         boolean,
    word_strongs_pos_pronoun_relative         boolean,
    word_strongs_dtime                        timestamptz,
    word_strongs_revision_dtime               timestamptz,
    word_strongs_revision_user_key            integer,
    word_strongs_revision_num                 integer
);
CREATE INDEX idx_yy_word_strongs_rev_key ON yy_word_strongs_rev (word_strongs_key);

-- ── Derived code ─────────────────────────────────────────────────────────
CREATE OR REPLACE FUNCTION trg_word_strongs_code() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  NEW.word_strongs_suffix := NULLIF(lower(btrim(NEW.word_strongs_suffix)), '');
  NEW.word_strongs_code   := NEW.word_strongs_language
                          || lpad(NEW.word_strongs_number::text, 4, '0')
                          || COALESCE(NEW.word_strongs_suffix, '');
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_word_strongs_code
    BEFORE INSERT OR UPDATE ON yy_word_strongs
    FOR EACH ROW EXECUTE FUNCTION trg_word_strongs_code();

-- ── History ──────────────────────────────────────────────────────────────
CREATE OR REPLACE FUNCTION trg_yy_word_strongs_rev() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE r yy_word_strongs;
BEGIN
  IF TG_OP = 'DELETE' THEN
    OLD.word_strongs_revision_num   := COALESCE(OLD.word_strongs_revision_num, 0) + 1;
    OLD.word_strongs_revision_dtime := NOW();
    r := OLD;
  ELSE
    IF TG_OP = 'UPDATE' THEN
      NEW.word_strongs_revision_num   := COALESCE(OLD.word_strongs_revision_num, 0) + 1;
      NEW.word_strongs_revision_dtime := NOW();
    ELSE
      IF NEW.word_strongs_revision_num   IS NULL THEN NEW.word_strongs_revision_num   := 1;     END IF;
      IF NEW.word_strongs_revision_dtime IS NULL THEN NEW.word_strongs_revision_dtime := NOW(); END IF;
    END IF;
    NEW.word_strongs_revision_user_key := COALESCE(NULLIF(current_setting('app.user_key', true), '')::int, 0);
    r := NEW;
  END IF;

  BEGIN
    INSERT INTO yy_word_strongs_rev (
      word_strongs_revision_delete_dtime, word_strongs_key, word_key, word_strongs_code,
      word_strongs_number, word_strongs_language, word_strongs_suffix, word_strongs_original,
      word_strongs_translit, word_strongs_pronunciation, word_strongs_phonetic,
      word_strongs_definition, word_strongs_origin, word_strongs_gender,
      word_strongs_pos_adjective, word_strongs_pos_adverb, word_strongs_pos_article,
      word_strongs_pos_conjunction, word_strongs_pos_gentilic, word_strongs_pos_interjection,
      word_strongs_pos_noun, word_strongs_pos_particle, word_strongs_pos_patronymic,
      word_strongs_pos_preposition, word_strongs_pos_pronoun, word_strongs_pos_substantive,
      word_strongs_pos_verb, word_strongs_pos_infinitive, word_strongs_pos_noun_proper,
      word_strongs_pos_participle, word_strongs_pos_participle_interrogative,
      word_strongs_pos_participle_relative, word_strongs_pos_pronoun_demonstrative,
      word_strongs_pos_pronoun_indefinite, word_strongs_pos_pronoun_interrogative,
      word_strongs_pos_pronoun_personal, word_strongs_pos_pronoun_relative,
      word_strongs_dtime, word_strongs_revision_dtime, word_strongs_revision_user_key,
      word_strongs_revision_num)
    VALUES (
      CASE WHEN TG_OP = 'DELETE' THEN NOW() END, r.word_strongs_key, r.word_key, r.word_strongs_code,
      r.word_strongs_number, r.word_strongs_language, r.word_strongs_suffix, r.word_strongs_original,
      r.word_strongs_translit, r.word_strongs_pronunciation, r.word_strongs_phonetic,
      r.word_strongs_definition, r.word_strongs_origin, r.word_strongs_gender,
      r.word_strongs_pos_adjective, r.word_strongs_pos_adverb, r.word_strongs_pos_article,
      r.word_strongs_pos_conjunction, r.word_strongs_pos_gentilic, r.word_strongs_pos_interjection,
      r.word_strongs_pos_noun, r.word_strongs_pos_particle, r.word_strongs_pos_patronymic,
      r.word_strongs_pos_preposition, r.word_strongs_pos_pronoun, r.word_strongs_pos_substantive,
      r.word_strongs_pos_verb, r.word_strongs_pos_infinitive, r.word_strongs_pos_noun_proper,
      r.word_strongs_pos_participle, r.word_strongs_pos_participle_interrogative,
      r.word_strongs_pos_participle_relative, r.word_strongs_pos_pronoun_demonstrative,
      r.word_strongs_pos_pronoun_indefinite, r.word_strongs_pos_pronoun_interrogative,
      r.word_strongs_pos_pronoun_personal, r.word_strongs_pos_pronoun_relative,
      r.word_strongs_dtime, r.word_strongs_revision_dtime, r.word_strongs_revision_user_key,
      r.word_strongs_revision_num);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;

  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_yy_word_strongs_rev
    BEFORE INSERT OR UPDATE OR DELETE ON yy_word_strongs
    FOR EACH ROW EXECUTE FUNCTION trg_yy_word_strongs_rev();

-- ── yy_word: the preferred-entry pointer ────────────────────────────────
ALTER TABLE yy_word     ADD COLUMN word_strongs_key integer;
ALTER TABLE yy_word_rev ADD COLUMN word_strongs_key integer;
-- Composite, so the preferred row must belong to THIS word. Deleting that row
-- clears only the pointer (PG15 column-list SET NULL), never word_key.
ALTER TABLE yy_word ADD CONSTRAINT yy_word_strongs_preferred_fk
    FOREIGN KEY (word_strongs_key, word_key)
    REFERENCES yy_word_strongs (word_strongs_key, word_key)
    ON DELETE SET NULL (word_strongs_key);

-- Rev trigger: + word_strongs_key, − the eight word_flag_* columns.
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
        word_yy_copy_key, word_language, word_code, word_strongs_key)
      VALUES (
        NOW(), OLD.word_key, OLD.word_source_code, OLD.word_strongs,
        OLD.word_hebrew, OLD.word_yt, OLD.word_translit,
        OLD.word_definition_kirk, OLD.word_definition_yy, OLD.word_definition_external, OLD.word_definition_perry,
        OLD.word_count_yy, OLD.word_active_flag, OLD.user_key, OLD.word_dtime,
        OLD.word_revision_dtime, OLD.word_revision_user_key, OLD.word_revision_num,
        OLD.word_pronunciation_strongs, OLD.word_pronunciation_yy,
        OLD.word_pronunciation_ipa, OLD.word_pronunciation_phonetic, OLD.word_gender_key,
        OLD.word_yy_copy_key, OLD.word_language, OLD.word_code, OLD.word_strongs_key);
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
      word_yy_copy_key, word_language, word_code, word_strongs_key)
    VALUES (
      NULL, NEW.word_key, NEW.word_source_code, NEW.word_strongs,
      NEW.word_hebrew, NEW.word_yt, NEW.word_translit,
      NEW.word_definition_kirk, NEW.word_definition_yy, NEW.word_definition_external, NEW.word_definition_perry,
      NEW.word_count_yy, NEW.word_active_flag, NEW.user_key, NEW.word_dtime,
      NEW.word_revision_dtime, NEW.word_revision_user_key, NEW.word_revision_num,
      NEW.word_pronunciation_strongs, NEW.word_pronunciation_yy,
      NEW.word_pronunciation_ipa, NEW.word_pronunciation_phonetic, NEW.word_gender_key,
      NEW.word_yy_copy_key, NEW.word_language, NEW.word_code, NEW.word_strongs_key);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;
  RETURN NEW;
END;
$function$;

-- ── Seed: one row per word that carries a Strong's code ─────────────────
-- Every stored code is canonical (^[HG][0-9]{4}[a-z]?$, checked: 3,103 of
-- 3,103). '' (has none) and NULL (unknown) get no row.
INSERT INTO yy_word_strongs (
    word_key, word_strongs_code, word_strongs_number, word_strongs_language, word_strongs_suffix,
    word_strongs_original, word_strongs_translit, word_strongs_pronunciation,
    word_strongs_gender,
    word_strongs_pos_noun, word_strongs_pos_verb, word_strongs_pos_adjective,
    word_strongs_pos_adverb, word_strongs_pos_preposition, word_strongs_pos_conjunction,
    word_strongs_pos_substantive, word_strongs_pos_pronoun, word_strongs_pos_noun_proper,
    word_strongs_pos_participle, word_strongs_pos_interjection)
SELECT w.word_key,
       w.word_strongs,                                  -- overwritten by trg_word_strongs_code
       substr(w.word_strongs, 2, 4)::smallint,
       left(w.word_strongs, 1),
       NULLIF(substr(w.word_strongs, 6, 1), ''),
       NULLIF(btrim(w.word_hebrew), ''),
       NULLIF(btrim(w.word_translit), ''),
       NULLIF(btrim(w.word_pronunciation_strongs), ''),
       CASE g.word_gender_code WHEN 'm' THEN 'm' WHEN 'f' THEN 'f' WHEN 'n' THEN 'n' WHEN 'x' THEN 'x' END,
       COALESCE(w.word_flag_noun, false)        OR EXISTS (SELECT 1 FROM yy_word_pos_map m WHERE m.word_key = w.word_key AND m.word_pos_key = 1),
       COALESCE(w.word_flag_verb, false)        OR EXISTS (SELECT 1 FROM yy_word_pos_map m WHERE m.word_key = w.word_key AND m.word_pos_key = 2),
       COALESCE(w.word_flag_adjective, false)   OR EXISTS (SELECT 1 FROM yy_word_pos_map m WHERE m.word_key = w.word_key AND m.word_pos_key = 3),
       COALESCE(w.word_flag_adverb, false)      OR EXISTS (SELECT 1 FROM yy_word_pos_map m WHERE m.word_key = w.word_key AND m.word_pos_key = 4),
       COALESCE(w.word_flag_preposition, false) OR EXISTS (SELECT 1 FROM yy_word_pos_map m WHERE m.word_key = w.word_key AND m.word_pos_key = 5),
       COALESCE(w.word_flag_conjunction, false) OR EXISTS (SELECT 1 FROM yy_word_pos_map m WHERE m.word_key = w.word_key AND m.word_pos_key = 6),
       COALESCE(w.word_flag_subst, false)       OR EXISTS (SELECT 1 FROM yy_word_pos_map m WHERE m.word_key = w.word_key AND m.word_pos_key = 7),
       COALESCE(w.word_flag_pronoun, false)     OR EXISTS (SELECT 1 FROM yy_word_pos_map m WHERE m.word_key = w.word_key AND m.word_pos_key = 8),
       EXISTS (SELECT 1 FROM yy_word_pos_map m WHERE m.word_key = w.word_key AND m.word_pos_key = 10),
       EXISTS (SELECT 1 FROM yy_word_pos_map m WHERE m.word_key = w.word_key AND m.word_pos_key = 11),
       EXISTS (SELECT 1 FROM yy_word_pos_map m WHERE m.word_key = w.word_key AND m.word_pos_key = 12)
  FROM yy_word w
  LEFT JOIN yy_word_gender g ON g.word_gender_key = w.word_gender_key
 WHERE w.word_strongs ~ '^[HG][0-9]{4}[a-z]?$'
 ORDER BY w.word_key;

UPDATE yy_word w
   SET word_strongs_key = s.word_strongs_key
  FROM yy_word_strongs s
 WHERE s.word_key = w.word_key;

-- ── Retire the flags (history keeps them in yy_word_rev) ────────────────
ALTER TABLE yy_word
    DROP COLUMN word_flag_noun,
    DROP COLUMN word_flag_verb,
    DROP COLUMN word_flag_adjective,
    DROP COLUMN word_flag_adverb,
    DROP COLUMN word_flag_preposition,
    DROP COLUMN word_flag_conjunction,
    DROP COLUMN word_flag_subst,
    DROP COLUMN word_flag_pronoun;

COMMIT;
