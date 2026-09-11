-- ════════════════════════════════════════════════════════════════════════
--  Parts of Speech (many per word) + Gender (one per word)
--
--  PoS: a junction table rather than a bitmask. yy_word_pos is the live
--  lookup (11 rows, and its keys already have a gap — there is no 9), so bit
--  positions would be a second thing to keep in step with it; a junction
--  gets FK integrity for free, has no width ceiling, matches the house
--  satellite pattern (yy_word_translit, yy_word_definition, yy_word_twot),
--  and avoids another ALTER on the rev-audited yy_word.
--
--  ⚠ yy_word already carries word_flag_noun/verb/adjective/... booleans.
--  They cover only 8 of the 11 parts of speech, are NULL on most rows, and
--  NOTHING reads them (grepped across api/, public/, js/, parsers/). They
--  are left in place rather than dropped — dropping columns on a
--  rev-audited table is its own hazard — but they are seeded into the new
--  table below and should be considered superseded.
--
--  Gender: one per word, so a plain FK column on yy_word. NULL = unknown.
--  ⚠ That is an ALTER on yy_word, whose rev trigger now uses EXPLICIT column
--  lists (rewritten 2026-09-11). Explicit lists cannot break writes the way
--  NEW.* did, but a new column is silently NOT recorded in history unless it
--  is added to yy_word_rev and to both lists — so all three happen here.
-- ════════════════════════════════════════════════════════════════════════

BEGIN;

-- ── Parts of speech, many per word ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS yy_word_pos_map (
    word_pos_map_key               serial PRIMARY KEY,
    word_key                       integer NOT NULL REFERENCES yy_word(word_key) ON DELETE CASCADE,
    word_pos_key                   integer NOT NULL REFERENCES yy_word_pos(word_pos_key),
    word_pos_map_sort              smallint NOT NULL DEFAULT 0,
    word_pos_map_dtime             timestamptz NOT NULL DEFAULT now(),
    word_pos_map_revision_dtime    timestamptz DEFAULT now(),
    word_pos_map_revision_user_key integer DEFAULT 0,
    word_pos_map_revision_num      integer DEFAULT 1,
    CONSTRAINT yy_word_pos_map_uniq UNIQUE (word_key, word_pos_key)
);

CREATE TABLE IF NOT EXISTS yy_word_pos_map_rev (
    word_pos_map_revision_id           bigserial PRIMARY KEY,
    word_pos_map_revision_delete_dtime timestamptz,
    word_pos_map_key                   integer,
    word_key                           integer,
    word_pos_key                       integer,
    word_pos_map_sort                  smallint,
    word_pos_map_dtime                 timestamptz,
    word_pos_map_revision_dtime        timestamptz,
    word_pos_map_revision_user_key     integer,
    word_pos_map_revision_num          integer
);

CREATE INDEX IF NOT EXISTS idx_yy_word_pos_map_word ON yy_word_pos_map (word_key);
CREATE INDEX IF NOT EXISTS idx_yy_word_pos_map_pos  ON yy_word_pos_map (word_pos_key);
CREATE INDEX IF NOT EXISTS idx_yy_word_pos_map_rev_key ON yy_word_pos_map_rev (word_pos_map_key);

CREATE OR REPLACE FUNCTION trg_yy_word_pos_map_rev() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    OLD.word_pos_map_revision_num   := COALESCE(OLD.word_pos_map_revision_num, 0) + 1;
    OLD.word_pos_map_revision_dtime := NOW();
    BEGIN
      INSERT INTO yy_word_pos_map_rev (
        word_pos_map_revision_delete_dtime, word_pos_map_key, word_key, word_pos_key,
        word_pos_map_sort, word_pos_map_dtime, word_pos_map_revision_dtime,
        word_pos_map_revision_user_key, word_pos_map_revision_num)
      VALUES (
        NOW(), OLD.word_pos_map_key, OLD.word_key, OLD.word_pos_key,
        OLD.word_pos_map_sort, OLD.word_pos_map_dtime, OLD.word_pos_map_revision_dtime,
        OLD.word_pos_map_revision_user_key, OLD.word_pos_map_revision_num);
    EXCEPTION WHEN OTHERS THEN
      RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
    END;
    RETURN OLD;
  END IF;

  IF TG_OP = 'UPDATE' THEN
    NEW.word_pos_map_revision_num   := COALESCE(OLD.word_pos_map_revision_num, 0) + 1;
    NEW.word_pos_map_revision_dtime := NOW();
  ELSIF TG_OP = 'INSERT' THEN
    IF NEW.word_pos_map_revision_num   IS NULL THEN NEW.word_pos_map_revision_num   := 1;     END IF;
    IF NEW.word_pos_map_revision_dtime IS NULL THEN NEW.word_pos_map_revision_dtime := NOW(); END IF;
  END IF;
  NEW.word_pos_map_revision_user_key := COALESCE(NULLIF(current_setting('app.user_key', true), '')::int, 0);

  BEGIN
    INSERT INTO yy_word_pos_map_rev (
      word_pos_map_revision_delete_dtime, word_pos_map_key, word_key, word_pos_key,
      word_pos_map_sort, word_pos_map_dtime, word_pos_map_revision_dtime,
      word_pos_map_revision_user_key, word_pos_map_revision_num)
    VALUES (
      NULL, NEW.word_pos_map_key, NEW.word_key, NEW.word_pos_key,
      NEW.word_pos_map_sort, NEW.word_pos_map_dtime, NEW.word_pos_map_revision_dtime,
      NEW.word_pos_map_revision_user_key, NEW.word_pos_map_revision_num);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_yy_word_pos_map_rev ON yy_word_pos_map;
CREATE TRIGGER trg_yy_word_pos_map_rev
    BEFORE INSERT OR UPDATE OR DELETE ON yy_word_pos_map
    FOR EACH ROW EXECUTE FUNCTION trg_yy_word_pos_map_rev();

-- ── Seed from what the data already knows ───────────────────────────────
-- Two existing sources, unioned: the dead word_flag_* booleans on yy_word,
-- and the part of speech recorded on each definition. NOT EXISTS rather than
-- ON CONFLICT DO NOTHING, which would still fire the rev trigger and litter
-- the history table.
INSERT INTO yy_word_pos_map (word_key, word_pos_key)
SELECT src.word_key, src.word_pos_key
  FROM (
        SELECT word_key, 1 AS word_pos_key FROM yy_word WHERE word_flag_noun
        UNION SELECT word_key, 2 FROM yy_word WHERE word_flag_verb
        UNION SELECT word_key, 3 FROM yy_word WHERE word_flag_adjective
        UNION SELECT word_key, 4 FROM yy_word WHERE word_flag_adverb
        UNION SELECT word_key, 5 FROM yy_word WHERE word_flag_preposition
        UNION SELECT word_key, 6 FROM yy_word WHERE word_flag_conjunction
        UNION SELECT word_key, 7 FROM yy_word WHERE word_flag_subst
        UNION SELECT word_key, 8 FROM yy_word WHERE word_flag_pronoun
        UNION SELECT DISTINCT d.word_key, d.word_pos_key
          FROM yy_word_definition d
         WHERE d.word_pos_key IS NOT NULL
       ) src
  JOIN yy_word_pos p ON p.word_pos_key = src.word_pos_key
 WHERE NOT EXISTS (SELECT 1 FROM yy_word_pos_map m
                    WHERE m.word_key = src.word_key AND m.word_pos_key = src.word_pos_key);

-- ── Gender, one per word ────────────────────────────────────────────────
-- yy_word_gender already holds Masculine / Feminine / Both / Neuter. "N/A"
-- is the one value the requested set was missing; unknown stays NULL.
INSERT INTO yy_word_gender (word_gender_code, word_gender_label)
SELECT 'x', 'N/A'
 WHERE NOT EXISTS (SELECT 1 FROM yy_word_gender WHERE word_gender_label = 'N/A');

ALTER TABLE yy_word
    ADD COLUMN IF NOT EXISTS word_gender_key integer REFERENCES yy_word_gender(word_gender_key);
ALTER TABLE yy_word_rev
    ADD COLUMN IF NOT EXISTS word_gender_key integer;

-- The rev trigger uses explicit column lists, so the new column has to be
-- named in both or it silently stops being recorded.
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
        word_pronunciation_ipa, word_pronunciation_phonetic, word_gender_key)
      VALUES (
        NOW(), OLD.word_key, OLD.word_source_code, OLD.word_strongs,
        OLD.word_hebrew, OLD.word_yt, OLD.word_translit, OLD.word_flag_noun, OLD.word_flag_verb,
        OLD.word_flag_adjective, OLD.word_flag_adverb, OLD.word_flag_preposition,
        OLD.word_flag_conjunction, OLD.word_flag_subst, OLD.word_flag_pronoun,
        OLD.word_definition_kirk, OLD.word_definition_yy, OLD.word_definition_external,
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
      word_definition_kirk, word_definition_yy, word_definition_external,
      word_count_yy, word_active_flag, user_key, word_dtime,
      word_revision_dtime, word_revision_user_key, word_revision_num,
      word_pronunciation_strongs, word_pronunciation_yy,
      word_pronunciation_ipa, word_pronunciation_phonetic, word_gender_key)
    VALUES (
      NULL, NEW.word_key, NEW.word_source_code, NEW.word_strongs,
      NEW.word_hebrew, NEW.word_yt, NEW.word_translit, NEW.word_flag_noun, NEW.word_flag_verb,
      NEW.word_flag_adjective, NEW.word_flag_adverb, NEW.word_flag_preposition,
      NEW.word_flag_conjunction, NEW.word_flag_subst, NEW.word_flag_pronoun,
      NEW.word_definition_kirk, NEW.word_definition_yy, NEW.word_definition_external,
      NEW.word_count_yy, NEW.word_active_flag, NEW.user_key, NEW.word_dtime,
      NEW.word_revision_dtime, NEW.word_revision_user_key, NEW.word_revision_num,
      NEW.word_pronunciation_strongs, NEW.word_pronunciation_yy,
      NEW.word_pronunciation_ipa, NEW.word_pronunciation_phonetic, NEW.word_gender_key);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;
  RETURN NEW;
END;
$function$;

COMMIT;
