-- Widen yy_word_translit.word_translit_count_yy smallint -> integer.
--
-- Why: the column holds "times this spelling occurs in the books". Since the
-- 2026-09-17 Perry import (2,116 more words, deliberately duplicating kirk/
-- books spellings) at least one spelling collides with a common English word
-- and now counts 39,350 -- past smallint's 32,767 ceiling. Every recount has
-- died on it since:
--   PDOException SQLSTATE[22003] value "39350" is out of range for type smallint
-- and because api/config.php sets display_errors=0 and installs a
-- set_exception_handler that only logs to yy_monitor_event, _word_harvest.php
-- printed nothing and exited 0. It LOOKED like a clean run while leaving
-- word_count_yy half-written and yy_word_occurrence untouched.
--
-- yy_word.word_count_yy is already integer, so only the translit side moves.
--
-- The _rev table MUST be widened in the SAME transaction: trg_yy_word_translit_rev
-- inserts with VALUES (NOW(), OLD.*) / (NULL, NEW.*) -- star expansion, the
-- hazard that has bitten this schema before. Widening the base column alone
-- would keep the column COUNT aligned (so the trigger still compiles) but feed
-- an integer into a smallint rev column, and every big write would fail with
-- the very same 22003. ALTER TYPE does not change column order or count, so
-- unlike ADD COLUMN the trigger itself needs no rewrite -- verified below by
-- actually exercising it.

-- ⚠ trg_translit_count_recalc is declared UPDATE OF word_translit_count_yy, so
-- Postgres refuses to alter the column while it exists ("cannot alter type of a
-- column used in a trigger definition"). It is dropped and recreated VERBATIM
-- in the same transaction -- captured from pg_get_triggerdef, not retyped from
-- memory, so the column list and timing come back exactly as they were. The
-- other two triggers on the table do not name the column and are left alone.

BEGIN;

DROP TRIGGER trg_translit_count_recalc ON yy_word_translit;

ALTER TABLE yy_word_translit_rev ALTER COLUMN word_translit_count_yy TYPE integer;
ALTER TABLE yy_word_translit     ALTER COLUMN word_translit_count_yy TYPE integer;

CREATE TRIGGER trg_translit_count_recalc
    AFTER INSERT OR DELETE OR UPDATE OF word_translit_count_yy, word_key
    ON public.yy_word_translit
    FOR EACH ROW EXECUTE FUNCTION trg_recalc_word_count_yy();

\echo '--- column types after widening (expect integer / integer) ---'
SELECT table_name, data_type
  FROM information_schema.columns
 WHERE column_name = 'word_translit_count_yy'
   AND table_name IN ('yy_word_translit','yy_word_translit_rev')
 ORDER BY table_name;

\echo '--- trigger restored ---'
SELECT pg_get_triggerdef(t.oid) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
 WHERE NOT t.tgisinternal AND c.relname='yy_word_translit'
   AND t.tgname='trg_translit_count_recalc';

COMMIT;
