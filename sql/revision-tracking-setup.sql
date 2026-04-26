-- ============================================================================
-- Revision Tracking System Setup
-- Creates _rev tables, adds revision columns, and installs triggers
-- for every source table in the database.
--
-- Run via: docker exec yada-postgres-prod psql -U postgres -d yada -f /tmp/revision-tracking-setup.sql
-- ============================================================================

-- Tables to EXCLUDE from revision tracking (temp, backup, already-rev, etc.)
-- Add any tables you don't want tracked here.
CREATE TEMP TABLE _rev_exclude (table_name text);
INSERT INTO _rev_exclude VALUES
  ('_translit_modifier_map'),
  ('search_alias'),
  ('yy_email_queue'),        -- transient queue
  ('yy_password_reset'),     -- transient tokens
  ('yy_email_verify'),       -- transient tokens
  ('yy_ask_session_log'),    -- high-volume logs
  ('yy_word_backup'),        -- already a backup
  ('yy_word_translit_backup');

-- ============================================================================
-- PHASE 1: Rename _update_dtime → _revision_dtime columns
-- ============================================================================
DO $$
DECLARE
  r RECORD;
  new_name text;
BEGIN
  FOR r IN
    SELECT table_name, column_name
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND column_name LIKE '%_update_dtime'
      AND table_name NOT LIKE 'rev_%'
      AND table_name NOT LIKE '%_backup'
      AND table_name NOT LIKE '%_rev'
  LOOP
    new_name := replace(r.column_name, '_update_dtime', '_revision_dtime');
    EXECUTE format('ALTER TABLE %I RENAME COLUMN %I TO %I', r.table_name, r.column_name, new_name);
    RAISE NOTICE 'Renamed %.% → %', r.table_name, r.column_name, new_name;
  END LOOP;

  FOR r IN
    SELECT table_name, column_name
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND column_name LIKE '%_update_user_key'
      AND table_name NOT LIKE 'rev_%'
      AND table_name NOT LIKE '%_backup'
      AND table_name NOT LIKE '%_rev'
  LOOP
    new_name := replace(r.column_name, '_update_user_key', '_revision_user_key');
    EXECUTE format('ALTER TABLE %I RENAME COLUMN %I TO %I', r.table_name, r.column_name, new_name);
    RAISE NOTICE 'Renamed %.% → %', r.table_name, r.column_name, new_name;
  END LOOP;
END $$;

-- ============================================================================
-- PHASE 2 + 3 + 4: Add revision columns, create _rev tables, install triggers
-- ============================================================================
DO $$
DECLARE
  t RECORD;
  pk_col text;
  prefix text;
  rev_table text;
  rev_id_col text;
  rev_del_col text;
  rev_dtime_col text;
  rev_user_col text;
  rev_num_col text;
  col_list text;
  has_rev_dtime boolean;
  has_rev_user boolean;
  has_rev_num boolean;
BEGIN
  FOR t IN
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema = 'public'
      AND table_type = 'BASE TABLE'
      AND table_name NOT LIKE 'rev_%'
      AND table_name NOT LIKE '%_backup'
      AND table_name NOT LIKE '%_rev'
      AND table_name NOT IN (SELECT e.table_name FROM _rev_exclude e)
    ORDER BY table_name
  LOOP
    -- Determine PK column
    SELECT kcu.column_name INTO pk_col
    FROM information_schema.table_constraints tc
    JOIN information_schema.key_column_usage kcu USING (constraint_name, table_schema)
    WHERE tc.constraint_type = 'PRIMARY KEY'
      AND tc.table_schema = 'public'
      AND tc.table_name = t.table_name
    LIMIT 1;

    IF pk_col IS NULL THEN
      RAISE NOTICE 'SKIP % (no PK)', t.table_name;
      CONTINUE;
    END IF;

    -- Determine column prefix from PK (e.g., book_key → book, feed_item_key → feed_item)
    prefix := regexp_replace(pk_col, '_key$|_id$', '');

    rev_table := t.table_name || '_rev';
    rev_id_col := prefix || '_rev_id';
    rev_del_col := prefix || '_rev_delete_dtime';
    rev_dtime_col := prefix || '_revision_dtime';
    rev_user_col := prefix || '_revision_user_key';
    rev_num_col := prefix || '_revision_num';

    -- ── Phase 2: Add revision columns to source table ──
    SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name=t.table_name AND column_name=rev_dtime_col) INTO has_rev_dtime;
    SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name=t.table_name AND column_name=rev_user_col) INTO has_rev_user;
    SELECT EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name=t.table_name AND column_name=rev_num_col) INTO has_rev_num;

    IF NOT has_rev_dtime THEN
      EXECUTE format('ALTER TABLE %I ADD COLUMN %I timestamptz DEFAULT NOW()', t.table_name, rev_dtime_col);
      RAISE NOTICE 'Added %.%', t.table_name, rev_dtime_col;
    END IF;
    IF NOT has_rev_user THEN
      EXECUTE format('ALTER TABLE %I ADD COLUMN %I int DEFAULT 0', t.table_name, rev_user_col);
      RAISE NOTICE 'Added %.%', t.table_name, rev_user_col;
    END IF;
    IF NOT has_rev_num THEN
      EXECUTE format('ALTER TABLE %I ADD COLUMN %I int DEFAULT 1', t.table_name, rev_num_col);
      RAISE NOTICE 'Added %.%', t.table_name, rev_num_col;
    END IF;

    -- ── Phase 3: Create _rev table ──
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name=rev_table) THEN
      RAISE NOTICE 'SKIP _rev creation for % (already exists)', rev_table;
    ELSE
      -- Build column list from source table
      SELECT string_agg(format('%I %s', column_name,
        CASE
          WHEN data_type = 'ARRAY' THEN udt_name || '[]'
          WHEN data_type = 'USER-DEFINED' THEN udt_name
          WHEN character_maximum_length IS NOT NULL THEN data_type || '(' || character_maximum_length || ')'
          ELSE data_type
        END
      ), ', ' ORDER BY ordinal_position)
      INTO col_list
      FROM information_schema.columns
      WHERE table_schema = 'public' AND table_name = t.table_name;

      EXECUTE format('CREATE TABLE %I (%I bigserial PRIMARY KEY, %I timestamptz, %s)',
        rev_table, rev_id_col, rev_del_col, col_list);
      RAISE NOTICE 'Created %', rev_table;
    END IF;

    -- ── Phase 4: Create trigger function and trigger ──
    -- Drop existing trigger if any
    EXECUTE format('DROP TRIGGER IF EXISTS trg_%s_rev ON %I', t.table_name, t.table_name);
    EXECUTE format('DROP FUNCTION IF EXISTS trg_%s_rev() CASCADE', t.table_name);

    -- Build the column list for INSERT into _rev (skip rev_id which is serial)
    SELECT string_agg(format('%I', column_name), ', ' ORDER BY ordinal_position)
    INTO col_list
    FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = t.table_name;

    EXECUTE format($fn$
      CREATE OR REPLACE FUNCTION trg_%s_rev() RETURNS trigger AS $trg$
      BEGIN
        IF TG_OP = 'DELETE' THEN
          INSERT INTO %I (%I, %s) VALUES (NOW(), OLD.*);
          RETURN OLD;
        END IF;
        IF TG_OP = 'UPDATE' THEN
          NEW.%I := COALESCE(OLD.%I, 0) + 1;
          NEW.%I := NOW();
        ELSIF TG_OP = 'INSERT' THEN
          IF NEW.%I IS NULL THEN NEW.%I := 1; END IF;
          IF NEW.%I IS NULL THEN NEW.%I := NOW(); END IF;
        END IF;
        INSERT INTO %I (%s) VALUES (NULL, NEW.*);
        RETURN NEW;
      END; $trg$ LANGUAGE plpgsql;
    $fn$,
      t.table_name,                        -- function name
      rev_table, rev_del_col, col_list,    -- DELETE insert
      rev_num_col, rev_num_col,            -- UPDATE: increment revision_num
      rev_dtime_col,                       -- UPDATE: set revision_dtime
      rev_num_col, rev_num_col,            -- INSERT: default revision_num
      rev_dtime_col, rev_dtime_col,        -- INSERT: default revision_dtime
      rev_table, col_list                  -- INSERT into rev (for INSERT/UPDATE)
    );

    EXECUTE format('CREATE TRIGGER trg_%s_rev BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION trg_%s_rev()',
      t.table_name, t.table_name, t.table_name);
    RAISE NOTICE 'Trigger installed for %', t.table_name;

  END LOOP;
END $$;

-- ============================================================================
-- PHASE 5: Seed baseline — copy current rows into _rev tables
-- ============================================================================
DO $$
DECLARE
  t RECORD;
  rev_table text;
  col_list text;
  cnt int;
BEGIN
  FOR t IN
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema = 'public'
      AND table_type = 'BASE TABLE'
      AND table_name LIKE '%_rev'
    ORDER BY table_name
  LOOP
    -- Get the source table name
    rev_table := t.table_name;
    DECLARE
      src_table text := regexp_replace(rev_table, '_rev$', '');
    BEGIN
      -- Only seed if rev table is empty
      EXECUTE format('SELECT COUNT(*) FROM %I', rev_table) INTO cnt;
      IF cnt > 0 THEN
        RAISE NOTICE 'SKIP seed % (already has % rows)', rev_table, cnt;
        CONTINUE;
      END IF;

      -- Check source exists
      IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name=src_table) THEN
        CONTINUE;
      END IF;

      -- Get column list from source table
      SELECT string_agg(format('%I', column_name), ', ' ORDER BY ordinal_position)
      INTO col_list
      FROM information_schema.columns
      WHERE table_schema = 'public' AND table_name = src_table;

      EXECUTE format('INSERT INTO %I (SELECT NULL, NULL, %s FROM %I)', rev_table, col_list, src_table);
      GET DIAGNOSTICS cnt = ROW_COUNT;
      RAISE NOTICE 'Seeded % with % rows', rev_table, cnt;
    END;
  END LOOP;
END $$;

-- Cleanup
DROP TABLE IF EXISTS _rev_exclude;

-- Summary
SELECT 'Source tables' AS metric, COUNT(*) AS count FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' AND table_name NOT LIKE '%_rev' AND table_name NOT LIKE 'rev_%' AND table_name NOT LIKE '%_backup'
UNION ALL
SELECT 'Rev tables', COUNT(*) FROM information_schema.tables WHERE table_schema='public' AND table_name LIKE '%_rev'
UNION ALL
SELECT 'Triggers', COUNT(*) FROM information_schema.triggers WHERE trigger_schema='public' AND trigger_name LIKE 'trg_%_rev';
