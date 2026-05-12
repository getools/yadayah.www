<?php
/**
 * Rule-based grapheme-to-phoneme for the YadaYah Hebrew transliteration.
 *
 * The transliteration is consistent across the books:
 *   ʾ (U+02BE)  aleph     → /ʔ/ glottal stop (often elided in English approximation)
 *   ʿ (U+02BF)  ayin      → /ʕ/ pharyngeal (usually elided)
 *   b           bet       → /b/   (no spirantisation distinction in this corpus)
 *   g           gimel     → /ɡ/
 *   d           dalet     → /d/
 *   h           he        → /h/
 *   w           waw       → /w/   (not /v/ in this transliteration)
 *   z           zayin     → /z/
 *   ch / kh     chet      → /χ/   (mapped to SAPI HH)
 *   t           tet/taw   → /t/
 *   y           yod       → /j/
 *   k           kaph      → /k/
 *   l           lamed     → /l/
 *   m           mem       → /m/
 *   n           nun       → /n/
 *   s           samekh    → /s/
 *   p / ph      pe        → /p/  (ph optional medial fricative — keep /p/ here)
 *   ts / tz     tsadi     → /ts/
 *   q           qof       → /q/  (mapped to SAPI K)
 *   r           resh      → /ɾ/  (approximated as /r/)
 *   sh          shin      → /ʃ/
 *
 * Vowels: a, e, i, o, u short; ay, ey, oy, ow, uw, iy long/diphthong.
 * Stress: penultimate by default. Ultimate when ends with -ah (mater
 * lectionis often final-stressed) is a 50/50 toss; we default to
 * penultimate which is more common in the books' tradition.
 */

// ---------- IPA mapping ----------
// Multi-char graphemes have to come before single-char ones in the
// tokenizer regex; build the priority list explicitly.
function hebrewToIpa(string $word): string {
    if ($word === '') return '';

    // Treat the half-rings + ASCII apostrophes as the same aleph/ayin
    // family so input variation doesn't change output.
    $word = preg_replace("/[\x{02BE}\x{2019}\x{2018}\x{0027}\x{201B}\x{02BC}]/u", "\u{02BE}", $word);
    // (ʿ ayin is distinct; leave it alone.)

    // Tokenise — greedy multi-char first, fallback to single char.
    $patterns = [
        // digraphs (must precede single letters)
        'sh'   => 'ʃ',
        'ch'   => 'χ',
        'kh'   => 'χ',
        'ts'   => 'ts',
        'tz'   => 'ts',
        'th'   => 'θ',   // rare but seen in some transliterations
        // long-vowel digraphs
        'ay'   => 'aɪ',
        'ey'   => 'eɪ',
        'oy'   => 'ɔɪ',
        'ow'   => 'oː',
        'uw'   => 'uː',
        'iy'   => 'iː',
        // singles
        "\u{02BE}" => 'ʔ',   // aleph
        "\u{02BF}" => 'ʕ',   // ayin
        'a' => 'ɑ',
        'b' => 'b',
        'd' => 'd',
        'e' => 'ɛ',
        'g' => 'ɡ',
        'h' => 'h',
        'i' => 'ɪ',
        'j' => 'dʒ',
        'k' => 'k',
        'l' => 'l',
        'm' => 'm',
        'n' => 'n',
        'o' => 'oʊ',
        'p' => 'p',
        'q' => 'k',
        'r' => 'ɹ',
        's' => 's',
        't' => 't',
        'u' => 'u',
        'v' => 'v',
        'w' => 'w',
        'x' => 'ks',
        'y' => 'j',
        'z' => 'z',
    ];

    $out = '';
    $i = 0;
    $len = mb_strlen($word);
    $lower = mb_strtolower($word);
    while ($i < $len) {
        $matched = false;
        // Try 2-char digraphs first
        if ($i + 1 < $len) {
            $two = mb_substr($lower, $i, 2);
            if (isset($patterns[$two])) {
                $out .= $patterns[$two];
                $i += 2;
                $matched = true;
            }
        }
        if (!$matched) {
            $one = mb_substr($lower, $i, 1);
            $out .= $patterns[$one] ?? $one; // unknown char passes through
            $i += 1;
        }
    }

    // Hebrew word-final 'h' after a vowel is mater lectionis (silent).
    // Same for word-final 'w' after a long vowel. Strip them.
    $out = preg_replace('/(ɑ|ɛ|ɪ|ʊ|ə|u|o|e|oː|uː|iː)h$/u', '$1', $out);
    // Collapse geminated consonants (doubled letters) — Hebrew has no
    // contrastive gemination audible in English approximation.
    $out = preg_replace('/([bdɡkmnprstvz])\1/u', '$1', $out);

    // Apply primary stress (penultimate). Find the last two vowel/diphthong
    // groups; mark the second-to-last.
    return ipaWithStress($out);
}

function ipaWithStress(string $ipa): string {
    // Identify vowel-onset positions. Match either a long vowel/diphthong
    // (aɪ eɪ ɔɪ oʊ uː iː) or a single vowel (ɑ ɛ ɪ ʊ ə u o e).
    $vowel = '/(aɪ|eɪ|ɔɪ|oʊ|uː|iː|ɑ|ɛ|ɪ|ʊ|ə|u|o|e)/u';
    preg_match_all($vowel, $ipa, $m, PREG_OFFSET_CAPTURE);
    $starts = array_map(function($t){ return $t[1]; }, $m[0] ?? []);
    if (count($starts) < 2) return $ipa; // 1 syllable, no stress mark needed
    // Penultimate stress
    $stressAt = $starts[count($starts) - 2];
    // Walk left from stressAt to insert ˈ at the start of the syllable —
    // approximated as just before the vowel (consonants in the onset).
    // For our purposes, drop ˈ immediately before the penultimate vowel.
    return substr($ipa, 0, $stressAt) . 'ˈ' . substr($ipa, $stressAt);
}

// ---------- IPA → SAPI mapping ----------
// SAPI uses ASCII strings like "Y AA1 H OW W AH". Stressed vowels get
// the suffix 1 (primary); unstressed get 0; secondary 2 (rare here).
function ipaToSapi(string $ipa): string {
    // Tokenize IPA into phonemes — multi-char first.
    $map = [
        // long vowels & diphthongs
        'aɪ' => 'AY',
        'eɪ' => 'EY',
        'ɔɪ' => 'OY',
        'oʊ' => 'OW',
        'uː' => 'UW',
        'iː' => 'IY',
        // single vowels
        'ɑ'  => 'AA',
        'ɛ'  => 'EH',
        'ɪ'  => 'IH',
        'ʊ'  => 'UH',
        'ə'  => 'AH',
        'u'  => 'UW',
        'o'  => 'OW',
        'e'  => 'EH',
        // consonants
        'ʃ'  => 'SH',
        'χ'  => 'HH',  // SAPI has no /χ/; closest is HH
        'θ'  => 'TH',
        'ʔ'  => '',    // glottal stop — no SAPI phoneme; elide
        'ʕ'  => '',    // ayin — elide
        'ts' => 'T S',
        'dʒ' => 'JH',
        'b'  => 'B', 'd' => 'D', 'ɡ' => 'G', 'h' => 'HH',
        'k'  => 'K', 'l' => 'L', 'm' => 'M', 'n' => 'N',
        'p'  => 'P', 'r' => 'R', 'ɹ' => 'R',
        's'  => 'S', 't' => 'T', 'v' => 'V', 'w' => 'W',
        'y'  => 'Y', 'j' => 'Y', 'z' => 'Z',
    ];

    // Track stress: a ˈ in IPA marks the next vowel as primary.
    $tokens = [];
    $stressNext = false;
    $i = 0;
    $len = mb_strlen($ipa);
    while ($i < $len) {
        $c = mb_substr($ipa, $i, 1);
        if ($c === 'ˈ') { $stressNext = true; $i++; continue; }
        if ($c === 'ˌ') { $i++; continue; } // skip secondary stress for now
        // Try 2-char IPA symbols
        $two = ($i + 1 < $len) ? mb_substr($ipa, $i, 2) : '';
        if ($two && isset($map[$two])) {
            $phon = $map[$two];
            $i += 2;
        } elseif (isset($map[$c])) {
            $phon = $map[$c];
            $i += 1;
        } else {
            $i += 1; // unknown — skip
            continue;
        }
        if ($phon === '') continue;
        // Mark vowel stress
        if ($stressNext && preg_match('/^(AA|AE|AH|AO|AW|AY|EH|ER|EY|IH|IY|OW|OY|UH|UW)/', $phon)) {
            $phon = preg_replace('/^([A-Z]{2})/', '${1}1', $phon, 1);
            $stressNext = false;
        } elseif (preg_match('/^(AA|AE|AH|AO|AW|AY|EH|ER|EY|IH|IY|OW|OY|UH|UW)/', $phon)) {
            $phon = preg_replace('/^([A-Z]{2})/', '${1}0', $phon, 1);
        }
        $tokens[] = $phon;
    }
    return implode(' ', $tokens);
}

function hebrewToSapi(string $word): string {
    return ipaToSapi(hebrewToIpa($word));
}

// ---------- self-test if run directly ----------
if (PHP_SAPI === 'cli' && isset($argv[0]) && basename($argv[0]) === basename(__FILE__)) {
    $samples = [
        'Yahowah', 'Yisraʾel', 'Shabuwʿah', 'Mosheh', 'Towrah',
        'Mitsraym', 'Yahuwshuah', 'Yada', 'Qatsyr', 'Dowd',
        'Yadaʿ', 'Shabbat', 'Miqraʾey',
    ];
    foreach ($samples as $w) {
        printf("%-15s ipa: %-25s  sapi: %s\n", $w, hebrewToIpa($w), hebrewToSapi($w));
    }
}
