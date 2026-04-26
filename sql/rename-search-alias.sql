-- Rename search_alias → yy_search_alias with revision tracking

ALTER TABLE search_alias RENAME TO yy_search_alias;
ALTER SEQUENCE search_alias_alias_key_seq RENAME TO yy_search_alias_alias_key_seq;

ALTER TABLE yy_search_alias ADD COLUMN alias_revision_dtime timestamptz DEFAULT NOW();
ALTER TABLE yy_search_alias ADD COLUMN alias_revision_user_key int DEFAULT 0;
ALTER TABLE yy_search_alias ADD COLUMN alias_revision_num int DEFAULT 1;

CREATE TABLE yy_search_alias_rev (
  alias_revision_id bigserial PRIMARY KEY,
  alias_revision_delete_dtime timestamptz,
  LIKE yy_search_alias INCLUDING DEFAULTS
);

CREATE OR REPLACE FUNCTION trg_yy_search_alias_rev() RETURNS trigger AS $trg$
BEGIN
  IF TG_OP = 'DELETE' THEN
    OLD.alias_revision_num := COALESCE(OLD.alias_revision_num, 0) + 1;
    OLD.alias_revision_dtime := NOW();
    INSERT INTO yy_search_alias_rev (alias_revision_delete_dtime, alias_key, alias_term, alias_target, alias_revision_dtime, alias_revision_user_key, alias_revision_num)
    VALUES (NOW(), OLD.*);
    RETURN OLD;
  END IF;
  IF TG_OP = 'UPDATE' THEN
    NEW.alias_revision_num := COALESCE(OLD.alias_revision_num, 0) + 1;
    NEW.alias_revision_dtime := NOW();
  ELSIF TG_OP = 'INSERT' THEN
    IF NEW.alias_revision_num IS NULL THEN NEW.alias_revision_num := 1; END IF;
    IF NEW.alias_revision_dtime IS NULL THEN NEW.alias_revision_dtime := NOW(); END IF;
  END IF;
  INSERT INTO yy_search_alias_rev (alias_revision_delete_dtime, alias_key, alias_term, alias_target, alias_revision_dtime, alias_revision_user_key, alias_revision_num)
  VALUES (NULL, NEW.*);
  RETURN NEW;
END; $trg$ LANGUAGE plpgsql;

CREATE TRIGGER trg_yy_search_alias_rev
  BEFORE INSERT OR UPDATE OR DELETE ON yy_search_alias
  FOR EACH ROW EXECUTE FUNCTION trg_yy_search_alias_rev();

-- Seed baseline
INSERT INTO yy_search_alias_rev (alias_revision_delete_dtime, alias_key, alias_term, alias_target, alias_revision_dtime, alias_revision_user_key, alias_revision_num)
SELECT NULL, alias_key, alias_term, alias_target, alias_revision_dtime, alias_revision_user_key, alias_revision_num
FROM yy_search_alias;
