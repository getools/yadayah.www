-- ════════════════════════════════════════════════════════════════════════
--  Glossary — Words
--
--  yy_glossary_word  standalone glossary terms (term / definition /
--                    see-also). Independent of yy_word (the Strong's
--                    Hebrew lexicon) and of yy_letter (the Hebrew-letter
--                    "Web" section) — this is a plain terms glossary.
--
--  Column prefix = table name minus the yy_ prefix, matching
--  yy_moon_location / yy_feed_item_link. Carries the standard revision
--  trio and a *_rev history table fed by a BEFORE trigger.
-- ════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS yy_glossary_word (
    glossary_word_key               serial PRIMARY KEY,
    glossary_word_term              varchar(200) NOT NULL,
    glossary_word_definition        text,
    glossary_word_see_also          varchar(500),
    glossary_word_sort              smallint NOT NULL DEFAULT 0,
    glossary_word_active_flag       boolean NOT NULL DEFAULT true,
    glossary_word_dtime             timestamptz NOT NULL DEFAULT now(),
    glossary_word_revision_dtime    timestamptz DEFAULT now(),
    glossary_word_revision_user_key integer DEFAULT 0,
    glossary_word_revision_num      integer DEFAULT 1
);

CREATE TABLE IF NOT EXISTS yy_glossary_word_rev (
    glossary_word_revision_id           bigserial PRIMARY KEY,
    glossary_word_revision_delete_dtime timestamptz,
    glossary_word_key                   integer,
    glossary_word_term                  varchar(200),
    glossary_word_definition            text,
    glossary_word_see_also              varchar(500),
    glossary_word_sort                  smallint,
    glossary_word_active_flag           boolean,
    glossary_word_dtime                 timestamptz,
    glossary_word_revision_dtime        timestamptz,
    glossary_word_revision_user_key     integer,
    glossary_word_revision_num          integer
);

-- One row per term, case-insensitively. A duplicate save is rejected by the
-- API with a readable message rather than silently creating a second entry.
CREATE UNIQUE INDEX IF NOT EXISTS uq_yy_glossary_word_term
    ON yy_glossary_word (lower(glossary_word_term));

-- List order: manual sort first, then alphabetical.
CREATE INDEX IF NOT EXISTS idx_yy_glossary_word_sort
    ON yy_glossary_word (glossary_word_sort, lower(glossary_word_term));

CREATE INDEX IF NOT EXISTS idx_yy_glossary_word_rev_key
    ON yy_glossary_word_rev (glossary_word_key);

-- ════════════════════════════════════════════════════════════════════════
--  Revision trigger. Explicit column lists (never NEW.*) so a later
--  ALTER TABLE on one side cannot silently shift values into the wrong
--  column. The rev insert is wrapped so a history failure warns instead
--  of killing the write.
-- ════════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION trg_yy_glossary_word_rev() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    OLD.glossary_word_revision_num   := COALESCE(OLD.glossary_word_revision_num, 0) + 1;
    OLD.glossary_word_revision_dtime := NOW();
    BEGIN
      INSERT INTO yy_glossary_word_rev (
        glossary_word_revision_delete_dtime, glossary_word_key, glossary_word_term,
        glossary_word_definition, glossary_word_see_also, glossary_word_sort,
        glossary_word_active_flag, glossary_word_dtime, glossary_word_revision_dtime,
        glossary_word_revision_user_key, glossary_word_revision_num)
      VALUES (
        NOW(), OLD.glossary_word_key, OLD.glossary_word_term,
        OLD.glossary_word_definition, OLD.glossary_word_see_also, OLD.glossary_word_sort,
        OLD.glossary_word_active_flag, OLD.glossary_word_dtime, OLD.glossary_word_revision_dtime,
        OLD.glossary_word_revision_user_key, OLD.glossary_word_revision_num);
    EXCEPTION WHEN OTHERS THEN
      RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
    END;
    RETURN OLD;
  END IF;

  IF TG_OP = 'UPDATE' THEN
    NEW.glossary_word_revision_num   := COALESCE(OLD.glossary_word_revision_num, 0) + 1;
    NEW.glossary_word_revision_dtime := NOW();
  ELSIF TG_OP = 'INSERT' THEN
    IF NEW.glossary_word_revision_num   IS NULL THEN NEW.glossary_word_revision_num   := 1;     END IF;
    IF NEW.glossary_word_revision_dtime IS NULL THEN NEW.glossary_word_revision_dtime := NOW(); END IF;
  END IF;
  NEW.glossary_word_revision_user_key := COALESCE(NULLIF(current_setting('app.user_key', true), '')::int, 0);

  BEGIN
    INSERT INTO yy_glossary_word_rev (
      glossary_word_revision_delete_dtime, glossary_word_key, glossary_word_term,
      glossary_word_definition, glossary_word_see_also, glossary_word_sort,
      glossary_word_active_flag, glossary_word_dtime, glossary_word_revision_dtime,
      glossary_word_revision_user_key, glossary_word_revision_num)
    VALUES (
      NULL, NEW.glossary_word_key, NEW.glossary_word_term,
      NEW.glossary_word_definition, NEW.glossary_word_see_also, NEW.glossary_word_sort,
      NEW.glossary_word_active_flag, NEW.glossary_word_dtime, NEW.glossary_word_revision_dtime,
      NEW.glossary_word_revision_user_key, NEW.glossary_word_revision_num);
  EXCEPTION WHEN OTHERS THEN
    RAISE WARNING 'Rev trigger % on % failed: %', TG_NAME, TG_TABLE_NAME, SQLERRM;
  END;
  RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_yy_glossary_word_rev ON yy_glossary_word;
CREATE TRIGGER trg_yy_glossary_word_rev
    BEFORE INSERT OR UPDATE OR DELETE ON yy_glossary_word
    FOR EACH ROW EXECUTE FUNCTION trg_yy_glossary_word_rev();
