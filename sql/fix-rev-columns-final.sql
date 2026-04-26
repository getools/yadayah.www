-- Rename all _rev_id → _revision_id and fix any remaining _rev_delete_dtime
DO $$
DECLARE
  r RECORD;
  new_name text;
BEGIN
  -- Fix _rev_id → _revision_id
  FOR r IN
    SELECT table_name, column_name
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name LIKE '%\_rev'
      AND column_name LIKE '%_rev_id'
      AND column_name NOT LIKE '%revision_id'
  LOOP
    new_name := replace(r.column_name, '_rev_id', '_revision_id');
    EXECUTE format('ALTER TABLE %I RENAME COLUMN %I TO %I', r.table_name, r.column_name, new_name);
    RAISE NOTICE 'Renamed %.% → %', r.table_name, r.column_name, new_name;
  END LOOP;

  -- Fix any remaining _rev_delete_dtime → _revision_delete_dtime
  FOR r IN
    SELECT table_name, column_name
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name LIKE '%\_rev'
      AND column_name LIKE '%_rev_delete_dtime'
      AND column_name NOT LIKE '%revision_delete_dtime'
  LOOP
    new_name := replace(r.column_name, '_rev_delete_dtime', '_revision_delete_dtime');
    EXECUTE format('ALTER TABLE %I RENAME COLUMN %I TO %I', r.table_name, r.column_name, new_name);
    RAISE NOTICE 'Renamed %.% → %', r.table_name, r.column_name, new_name;
  END LOOP;
END $$;
