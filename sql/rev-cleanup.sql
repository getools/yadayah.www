-- ============================================================================
-- Rev table cleanup:
-- 1. Rename _rev_delete_dtime → _revision_delete_dtime
-- 2. Convert all timestamp columns to timestamptz
-- 3. Import old rev_ data into new _rev tables
-- 4. Truncate and reseed _rev tables with current source + old rev data
-- 5. Rebuild triggers with updated column names
-- ============================================================================

-- ============================================================================
-- PHASE 1: Rename _rev_delete_dtime → _revision_delete_dtime
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
      AND column_name LIKE '%_rev_delete_dtime'
      AND table_name LIKE '%\_rev'
  LOOP
    new_name := replace(r.column_name, '_rev_delete_dtime', '_revision_delete_dtime');
    EXECUTE format('ALTER TABLE %I RENAME COLUMN %I TO %I', r.table_name, r.column_name, new_name);
    RAISE NOTICE 'Renamed %.% → %', r.table_name, r.column_name, new_name;
  END LOOP;
END $$;

-- ============================================================================
-- PHASE 2: Convert all timestamp without time zone → timestamptz
-- ============================================================================
DO $$
DECLARE
  r RECORD;
BEGIN
  FOR r IN
    SELECT table_name, column_name
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND data_type = 'timestamp without time zone'
    ORDER BY table_name, ordinal_position
  LOOP
    BEGIN
      EXECUTE format('ALTER TABLE %I ALTER COLUMN %I TYPE timestamptz USING %I AT TIME ZONE ''UTC''', r.table_name, r.column_name, r.column_name);
    EXCEPTION WHEN OTHERS THEN
      RAISE NOTICE 'SKIP %.% : %', r.table_name, r.column_name, SQLERRM;
    END;
  END LOOP;
  RAISE NOTICE 'All timestamps converted to timestamptz';
END $$;

-- ============================================================================
-- PHASE 3: Truncate _rev tables, import old rev_ data, then seed current data
-- ============================================================================
DO $$
DECLARE
  t RECORD;
  rev_table text;
  src_table text;
  old_rev_table text;
  del_col text;
  src_cols text;
  old_cols text;
  matched_cols text;
  cnt int;
BEGIN
  FOR t IN
    SELECT t1.table_name
    FROM information_schema.tables t1
    WHERE t1.table_schema = 'public'
      AND t1.table_type = 'BASE TABLE'
      AND t1.table_name LIKE '%\_rev'
      AND t1.table_name NOT LIKE 'rev\_%'
    ORDER BY t1.table_name
  LOOP
    rev_table := t.table_name;
    src_table := regexp_replace(rev_table, '_rev$', '');

    -- Skip if source doesn't exist
    IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name=src_table) THEN
      CONTINUE;
    END IF;

    -- Truncate rev table
    EXECUTE format('TRUNCATE TABLE %I RESTART IDENTITY', rev_table);

    -- Get the delete_dtime column (2nd column)
    SELECT column_name INTO del_col
    FROM information_schema.columns WHERE table_schema='public' AND table_name=rev_table
    ORDER BY ordinal_position OFFSET 1 LIMIT 1;

    -- Get source column list
    SELECT string_agg(format('%I', column_name), ', ' ORDER BY ordinal_position) INTO src_cols
    FROM information_schema.columns WHERE table_schema='public' AND table_name=src_table;

    -- Check for old rev_ table
    -- Try both rev_yy_xxx (for yy_ tables) and rev_xxx patterns
    old_rev_table := NULL;
    IF src_table LIKE 'yy_%' THEN
      IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='rev_' || src_table) THEN
        old_rev_table := 'rev_' || src_table;
      END IF;
    ELSE
      IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='rev_' || src_table) THEN
        old_rev_table := 'rev_' || src_table;
      END IF;
    END IF;

    -- Also check for yah_ prefix tables
    IF old_rev_table IS NULL AND src_table LIKE 'yah_%' THEN
      IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='rev_' || src_table) THEN
        old_rev_table := 'rev_' || src_table;
      END IF;
    END IF;

    -- Import old rev data if available
    IF old_rev_table IS NOT NULL THEN
      -- Find matching columns between old rev and source table
      SELECT string_agg(c.column_name, ', ' ORDER BY c.ordinal_position) INTO matched_cols
      FROM information_schema.columns c
      WHERE c.table_schema = 'public' AND c.table_name = src_table
        AND c.column_name IN (SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name=old_rev_table);

      IF matched_cols IS NOT NULL THEN
        -- Map old rev columns: _remove_dtime → revision_delete_dtime, _revision_dtime → revision_dtime
        BEGIN
          EXECUTE format(
            'INSERT INTO %I (%I, %s) SELECT COALESCE(o._remove_dtime, NULL), %s FROM %I o',
            rev_table, del_col, matched_cols, matched_cols, old_rev_table
          );
          GET DIAGNOSTICS cnt = ROW_COUNT;
          RAISE NOTICE 'Imported % rows from % into %', cnt, old_rev_table, rev_table;
        EXCEPTION WHEN OTHERS THEN
          -- If _remove_dtime doesn't exist, try without it
          BEGIN
            EXECUTE format(
              'INSERT INTO %I (%I, %s) SELECT NULL, %s FROM %I',
              rev_table, del_col, matched_cols, matched_cols, old_rev_table
            );
            GET DIAGNOSTICS cnt = ROW_COUNT;
            RAISE NOTICE 'Imported % rows from % into % (no _remove_dtime)', cnt, old_rev_table, rev_table;
          EXCEPTION WHEN OTHERS THEN
            RAISE NOTICE 'SKIP import from %: %', old_rev_table, SQLERRM;
          END;
        END;
      END IF;
    END IF;

    -- Seed current source data (baseline snapshot)
    BEGIN
      EXECUTE format('INSERT INTO %I (%I, %s) SELECT NULL, %s FROM %I', rev_table, del_col, src_cols, src_cols, src_table);
      GET DIAGNOSTICS cnt = ROW_COUNT;
      RAISE NOTICE 'Seeded % with % current rows', rev_table, cnt;
    EXCEPTION WHEN OTHERS THEN
      RAISE NOTICE 'SEED ERROR %: %', rev_table, SQLERRM;
    END;

  END LOOP;
END $$;

-- ============================================================================
-- PHASE 4: Rebuild triggers with updated _revision_delete_dtime column name
-- ============================================================================
DO $$
DECLARE
  t RECORD;
  pk_col text;
  prefix text;
  rev_table text;
  rev_del_col text;
  rev_dtime_col text;
  rev_num_col text;
  col_list text;
BEGIN
  FOR t IN
    SELECT t1.table_name
    FROM information_schema.tables t1
    WHERE t1.table_schema = 'public'
      AND t1.table_type = 'BASE TABLE'
      AND t1.table_name NOT LIKE 'rev\_%'
      AND t1.table_name NOT LIKE '%\_backup'
      AND t1.table_name NOT LIKE '%\_rev'
      AND EXISTS (SELECT 1 FROM information_schema.tables t2 WHERE t2.table_schema='public' AND t2.table_name = t1.table_name || '_rev')
    ORDER BY t1.table_name
  LOOP
    SELECT kcu.column_name INTO pk_col
    FROM information_schema.table_constraints tc
    JOIN information_schema.key_column_usage kcu USING (constraint_name, table_schema)
    WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = 'public' AND tc.table_name = t.table_name
    LIMIT 1;

    IF pk_col IS NULL THEN CONTINUE; END IF;

    prefix := regexp_replace(pk_col, '_key$|_id$', '');
    rev_table := t.table_name || '_rev';
    rev_del_col := prefix || '_revision_delete_dtime';
    rev_dtime_col := prefix || '_revision_dtime';
    rev_num_col := prefix || '_revision_num';

    SELECT string_agg(format('%I', column_name), ', ' ORDER BY ordinal_position)
    INTO col_list
    FROM information_schema.columns
    WHERE table_schema = 'public' AND table_name = t.table_name;

    EXECUTE format('DROP TRIGGER IF EXISTS trg_%s_rev ON %I', t.table_name, t.table_name);
    EXECUTE format('DROP FUNCTION IF EXISTS trg_%s_rev() CASCADE', t.table_name);

    EXECUTE format($fn$
      CREATE OR REPLACE FUNCTION trg_%s_rev() RETURNS trigger AS $trg$
      BEGIN
        IF TG_OP = 'DELETE' THEN
          OLD.%I := COALESCE(OLD.%I, 0) + 1;
          OLD.%I := NOW();
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
        INSERT INTO %I (%I, %s) VALUES (NULL, NEW.*);
        RETURN NEW;
      END; $trg$ LANGUAGE plpgsql;
    $fn$,
      t.table_name,
      rev_num_col, rev_num_col,
      rev_dtime_col,
      rev_table, rev_del_col, col_list,
      rev_num_col, rev_num_col,
      rev_dtime_col,
      rev_num_col, rev_num_col,
      rev_dtime_col, rev_dtime_col,
      rev_table, rev_del_col, col_list
    );

    EXECUTE format('CREATE TRIGGER trg_%s_rev BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION trg_%s_rev()',
      t.table_name, t.table_name, t.table_name);
    RAISE NOTICE 'Trigger rebuilt for %', t.table_name;
  END LOOP;
END $$;

-- Summary
DO $$
DECLARE
  ts_count int;
  rev_count int;
  trg_count int;
BEGIN
  SELECT COUNT(*) INTO ts_count FROM information_schema.columns WHERE table_schema='public' AND data_type='timestamp without time zone';
  SELECT COUNT(*) INTO rev_count FROM information_schema.tables WHERE table_schema='public' AND table_name LIKE '%\_rev' AND table_name NOT LIKE 'rev\_%';
  SELECT COUNT(*) INTO trg_count FROM information_schema.triggers WHERE trigger_schema='public' AND trigger_name LIKE 'trg\_%\_rev';
  RAISE NOTICE 'Remaining non-UTC timestamps: %', ts_count;
  RAISE NOTICE 'Rev tables: %', rev_count;
  RAISE NOTICE 'Triggers: %', trg_count;
END $$;
