-- ════════════════════════════════════════════════════════════════════════
--  trg_word_yt — derive yy_word.word_yt from word_hebrew.
--
--  Change: Hebrew nonspacing marks (vowel points, dagesh, shin/sin dots,
--  meteg, rafe) are stripped before the letters are translated. They are
--  pointing ON a letter, not letters of their own, so they have no YT
--  equivalent; translate() used to leave them sitting in the YT string.
--
--  Printing punctuation in the same block is deliberately kept: maqaf
--  (U+05BE), paseq (U+05C0), sof pasuq (U+05C3), nun hafukha (U+05C6).
--
--  The mark class is assembled with chr() so no combining character has to
--  survive as a literal in this file. Codepoints:
--    1425-1469 = U+0591-U+05BD   1471 = U+05BF   1473/1474 = U+05C1/U+05C2
--    1476/1477 = U+05C4/U+05C5   1479 = U+05C7
--
--  ⚠ Two triggers on yy_word call this function — trg_word_yt and
--    trg_word_yt_generate. Replacing the function covers both.
--  ⚠ The letter map below is the trigger's own copy and matches yy_letter.
-- ════════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION public.trg_word_yt()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
BEGIN
  IF NEW.word_hebrew IS NOT NULL THEN
    NEW.word_yt := translate(
      regexp_replace(
        replace(NEW.word_hebrew, E'\n', ''),
        '[' || chr(1425) || '-' || chr(1469) || chr(1471) || chr(1473)
            || chr(1474) || chr(1476) || chr(1477) || chr(1479) || ']',
        '', 'g'
      ),
      'אבגדהוזחטיכלמנספעצקרשתךםןףץ',
      'abgdhwzcxyklmnfpieqrstkmnpe'
    );
  END IF;
  RETURN NEW;
END;
$function$;
