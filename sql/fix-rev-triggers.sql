-- Fix revision triggers: INSERT/UPDATE case was missing rev_del_col in column list
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
    rev_del_col := prefix || '_rev_delete_dtime';
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
      rev_table, rev_del_col, col_list,
      rev_num_col, rev_num_col,
      rev_dtime_col,
      rev_num_col, rev_num_col,
      rev_dtime_col, rev_dtime_col,
      rev_table, rev_del_col, col_list
    );

    EXECUTE format('CREATE TRIGGER trg_%s_rev BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION trg_%s_rev()',
      t.table_name, t.table_name, t.table_name);
    RAISE NOTICE 'Fixed trigger for %', t.table_name;
  END LOOP;
END $$;
