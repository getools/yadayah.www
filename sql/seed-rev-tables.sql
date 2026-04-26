-- Seed _rev tables with current data from source tables
DO $$
DECLARE
  t RECORD;
  rev_table text;
  src_table text;
  rev_del_col text;
  col_list text;
  cnt int;
BEGIN
  FOR t IN
    SELECT table_name FROM information_schema.tables
    WHERE table_schema='public' AND table_name LIKE '%\_rev'
    AND table_name NOT LIKE 'rev\_%'
    ORDER BY table_name
  LOOP
    rev_table := t.table_name;
    src_table := regexp_replace(rev_table, '_rev$', '');

    EXECUTE format('SELECT COUNT(*) FROM %I', rev_table) INTO cnt;
    IF cnt > 0 THEN
      RAISE NOTICE 'SKIP % (already has % rows)', rev_table, cnt;
      CONTINUE;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name=src_table) THEN
      RAISE NOTICE 'SKIP % (no source table %)', rev_table, src_table;
      CONTINUE;
    END IF;

    SELECT string_agg(format('%I', column_name), ', ' ORDER BY ordinal_position) INTO col_list
    FROM information_schema.columns WHERE table_schema='public' AND table_name=src_table;

    SELECT column_name INTO rev_del_col
    FROM information_schema.columns WHERE table_schema='public' AND table_name=rev_table
    ORDER BY ordinal_position OFFSET 1 LIMIT 1;

    BEGIN
      EXECUTE format('INSERT INTO %I (%I, %s) SELECT NULL, %s FROM %I', rev_table, rev_del_col, col_list, col_list, src_table);
      GET DIAGNOSTICS cnt = ROW_COUNT;
      RAISE NOTICE 'Seeded % with % rows', rev_table, cnt;
    EXCEPTION WHEN OTHERS THEN
      RAISE NOTICE 'ERROR seeding %: %', rev_table, SQLERRM;
    END;
  END LOOP;
END $$;
