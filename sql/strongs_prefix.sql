-- ════════════════════════════════════════════════════════════════════════
--  word_strongs — prefix the language letter
--
--  Every Strong's number in yy_word is Hebrew: all 987 have a Hebrew
--  spelling, and the range 0001-8668 sits entirely above where Greek
--  numbering stops (~5624), so the prefix is H for all of them. Padding is
--  kept, so 0001 becomes H0001 and the column still sorts as plain text.
--
--  ⚠ The column is character(4) on BOTH yy_word and yy_word_rev. Widening
--  only yy_word would make the rev INSERT fail on the longer value — and
--  because that insert is wrapped in EXCEPTION WHEN OTHERS / RAISE WARNING,
--  it would fail SILENTLY and history would just stop being recorded. So
--  both sides widen here, before any value changes.
--
--  char(4) also blank-pads on read, which is why every caller wraps this
--  column in trim(); varchar drops that wart.
-- ════════════════════════════════════════════════════════════════════════

BEGIN;

ALTER TABLE yy_word     ALTER COLUMN word_strongs TYPE varchar(8);
ALTER TABLE yy_word_rev ALTER COLUMN word_strongs TYPE varchar(8);

-- Idempotent: only touches values that are still bare digits.
UPDATE yy_word
   SET word_strongs = 'H' || trim(word_strongs)
 WHERE trim(COALESCE(word_strongs, '')) ~ '^[0-9]+$';

COMMIT;
