<?php
/**
 * Shared helpers for the TTS admin area.
 *
 *   loadTtsConfig($db, $ttsKey)
 *     → ['system' => row, 'categories' => [cat => row], 'tunes' => [print => row], 'pauses' => [row, …]]
 *
 *   buildSsmlForText($text, $cfg, $category)
 *     → SSML string with category voice + tunes (sub-alias) + pauses (break) applied
 *
 *   azureTtsSynthesize($ssml, $cfg, &$err)
 *     → mp3 bytes (or '' on failure; sets $err)
 *
 *   azureVoiceCatalog()
 *     → ['en-US-BrianMultilingualNeural' => ['label' => 'Brian (Multilingual)', 'gender' => 'M', 'styles' => [...]], …]
 */

if (!function_exists('readEnv')) {
    // Mirror of the one in transcript-worker.php — kept here so helpers can be
    // included from non-worker contexts (preview endpoint, etc.).
    function readEnv(string $name): string {
        $val = getenv($name);
        if ($val) return $val;
        $envFile = dirname(__DIR__) . '/.env';
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos($line, '#') === 0) continue;
                if (strpos($line, $name . '=') === 0) return trim(substr($line, strlen($name) + 1));
            }
        }
        return '';
    }
}

function loadTtsConfig(PDO $db, int $ttsKey): array {
    $sysStmt = $db->prepare("SELECT * FROM yy_tts WHERE tts_key = ?");
    $sysStmt->execute([$ttsKey]);
    $system = $sysStmt->fetch();
    if (!$system) return ['system' => null, 'categories' => [], 'tunes' => [], 'pauses' => []];

    $catStmt = $db->prepare("SELECT * FROM yy_tts_category_voice WHERE tts_key = ? AND tts_category_voice_active_flag = TRUE");
    $catStmt->execute([$ttsKey]);
    $categories = [];
    foreach ($catStmt->fetchAll() as $r) {
        $categories[$r['tts_category']] = $r;
    }

    $tuneStmt = $db->prepare("SELECT * FROM yy_tts_tune WHERE tts_key = ? AND tts_tune_active_flag = TRUE ORDER BY length(tts_tune_print) DESC");
    $tuneStmt->execute([$ttsKey]);
    $tunes = $tuneStmt->fetchAll();

    $pauseStmt = $db->prepare("SELECT * FROM yy_tts_pause WHERE tts_key = ? AND tts_pause_active_flag = TRUE ORDER BY length(tts_pause_search) DESC, tts_pause_sort");
    $pauseStmt->execute([$ttsKey]);
    $pauses = $pauseStmt->fetchAll();

    return ['system' => $system, 'categories' => $categories, 'tunes' => $tunes, 'pauses' => $pauses];
}

/**
 * Apply pause substring replacements. Pauses use a placeholder token so the
 * pause stays intact after XML escaping; the placeholder is rewritten back
 * to the SSML <break> tag after escaping.
 */
function applyPauses(string $text, array $pauses, string &$placeholder): string {
    foreach ($pauses as $p) {
        $needle = $p['tts_pause_search'];
        $ms = (int)$p['tts_pause_ms'];
        $token = sprintf("\x01PAUSE_%d_%d\x01", $p['tts_pause_key'], $ms);
        $text = str_replace($needle, $token, $text);
    }
    return $text;
}

function placeholdersToBreaks(string $escaped): string {
    return preg_replace_callback('/\x01PAUSE_(\d+)_(\d+)\x01/', function ($m) {
        return '<break time="' . (int)$m[2] . 'ms"/>';
    }, $escaped);
}

/**
 * Apply pronunciation substitutions. 'sub' type → <sub alias="phonetic">print</sub>,
 * unless the alias contains an ALL-CAPS run (2+ letters), in which case we
 * split the alias and wrap each caps run in <emphasis level="strong"> so
 * the engine actually stresses that syllable. "Yah-HOH-wah" → "Yah-" +
 * <emphasis>hoh</emphasis> + "-wah". 'ipa' / 'sapi' → <phoneme>.
 *
 * Uses a token round-trip so SSML markup isn't double-escaped.
 */
function applyTunes(string $text, array $tunes, array &$tokenMap): string {
    foreach ($tunes as $t) {
        $print = $t['tts_tune_print'];
        if ($print === '') continue;
        // Each row now stores three independent phonetic representations
        // (sub / ipa / sapi). The phonetic_type column picks which one is
        // live. Fall back to the legacy tts_tune_phonetic mirror so this
        // still works on rows that haven't been re-saved since the
        // multi-column migration.
        $type = $t['tts_tune_phonetic_type'] ?? 'sub';
        // Azure neural voices reject the 'sapi' phoneme alphabet (legacy,
        // non-neural voices only). Treat type=sapi as a *reference* setting
        // — at synth time we transparently use the IPA column instead. If
        // the IPA cell is empty, fall back to SUB.
        $synthType = ($type === 'sapi') ? 'ipa' : $type;
        $col  = 'tts_tune_phonetic_' . $synthType;
        $phon = trim((string)($t[$col] ?? ''));
        if ($phon === '' && $synthType === 'ipa') {
            $synthType = 'sub';
            $phon = trim((string)($t['tts_tune_phonetic_sub'] ?? ''));
        }
        if ($phon === '') $phon = (string)($t['tts_tune_phonetic'] ?? '');
        if ($phon === '') continue; // nothing to substitute with — skip rule
        $regex = tunePrintToRegex($print);
        if (!preg_match($regex, $text)) continue;
        $token = sprintf("\x02TUNE_%d\x02", $t['tts_tune_key']);
        if ($synthType === 'ipa') {
            $ph = htmlspecialchars($phon, ENT_QUOTES | ENT_XML1);
            $printEsc = htmlspecialchars($print, ENT_QUOTES | ENT_XML1);
            $repl = "<phoneme alphabet=\"ipa\" ph=\"$ph\">$printEsc</phoneme>";
        } else {
            $repl = buildSubReplSsml($print, $phon);
        }
        $tokenMap[$token] = $repl;
        $text = preg_replace($regex, $token, $text);
    }
    return $text;
}

/**
 * Build a regex that matches the Print field against the source text,
 * treating every single-quote-like or half-ring character as equivalent
 * AND optional. So a Print of "Miqra'ey" matches all of:
 *   Miqra'ey   Miqra’ey   Miqraʾey   Miqra`ey   ...    (any variant)
 *   Miqraey                                            (no apostrophe at all)
 * And a Print of "Miqraey" (no apostrophe) likewise matches "Miqra'ey"
 * — the apostrophe is optional in both directions.
 *
 * Set of equivalent chars covers the most common variants seen in
 * Hebrew / Greek transliterations and English typographic apostrophes:
 *   U+0027 '   ASCII apostrophe
 *   U+0060 `   grave / backtick
 *   U+00B4 ´   acute accent
 *   U+02BC ʼ   modifier letter apostrophe
 *   U+02BE ʾ   modifier letter right half ring (aleph)
 *   U+02BF ʿ   modifier letter left half ring  (ayin)
 *   U+02C0 ʔ   modifier letter glottal stop
 *   U+2018 ‘   left single curly quote
 *   U+2019 ’   right single curly quote
 *   U+201B ‛   high reversed-9 quotation
 *   U+2032 ′   prime
 *   U+05F3 ׳   Hebrew geresh
 *
 * Strategy: strip every apostrophe-class char from Print to get its
 * "core letters", then insert an optional apostrophe-class between every
 * pair of cores. The result matches the cores joined by any number of
 * apostrophe-class chars (0 or 1) at every join point.
 */
function tunePrintToRegex(string $print): string {
    static $APOS_RE       = '/[\x{0027}\x{0060}\x{00B4}\x{02BC}\x{02BE}\x{02BF}\x{02C0}\x{2018}\x{2019}\x{201B}\x{2032}\x{05F3}]/u';
    static $APOS_CLASS_OPT = "[\x{0027}\x{0060}\x{00B4}\x{02BC}\x{02BE}\x{02BF}\x{02C0}\x{2018}\x{2019}\x{201B}\x{2032}\x{05F3}]?";
    // 1) Drop apostrophe-class chars to get the core spelling.
    $core = preg_replace($APOS_RE, '', $print);
    if ($core === '' || $core === null) {
        // Degenerate: Print was entirely apostrophes. Fall back to literal match.
        return '/(?<![A-Za-z])' . preg_quote($print, '/') . '(?![A-Za-z])/iu';
    }
    // 2) Split the core into single Unicode chars, escape each, then join
    //    with an optional apostrophe-class. Outer optional apos at the
    //    very start and end so a leading/trailing half-ring in the source
    //    text (e.g. "ʾadam") matches a Print field without one ("adam").
    // 3) Wrap with ASCII-letter lookbehinds/lookaheads — whole-word match
    //    that doesn't extend Print "Yada" into "Yadayah". The lookaround
    //    is on [A-Za-z] specifically (not \w or \p{L}) so the half-ring
    //    chars (which are Unicode letters) at word edges don't block the
    //    match; they're handled by the outer optional apos class instead.
    // 4) /iu — case-insensitive Unicode; "Yahowah" matches "yahowah" too.
    $chars = preg_split('//u', $core, -1, PREG_SPLIT_NO_EMPTY);
    $escaped = array_map(function($c) { return preg_quote($c, '/'); }, $chars);
    $body = $APOS_CLASS_OPT . implode($APOS_CLASS_OPT, $escaped) . $APOS_CLASS_OPT;
    return '/(?<![A-Za-z])' . $body . '(?![A-Za-z])/iu';
}

/**
 * Build the SSML for a 'sub'-type tune. Three optional in-text markers:
 *   ALL CAPS  → wrap that run in <prosody pitch="+25%" rate="92%">  (stress)
 *   [word]    → wrap "word" in <prosody rate="80%">                  (slow / drawn out)
 *   {word}    → wrap "word" in <prosody rate="130%">                 (fast / clipped)
 * Caps inside brackets/braces still trigger their own nested emphasis
 * prosody (Azure accepts nested <prosody>). When the alias contains
 * none of these markers we fall back to plain <sub alias="…">print</sub>
 * so the historical rules keep working unchanged.
 */
function buildSubReplSsml(string $print, string $alias): string {
    $printEsc = htmlspecialchars($print, ENT_QUOTES | ENT_XML1);
    if (!preg_match('/[A-Z]{2,}|[\[\]{}]/', $alias)) {
        $aliasEsc = htmlspecialchars($alias, ENT_QUOTES | ENT_XML1);
        return "<sub alias=\"$aliasEsc\">$printEsc</sub>";
    }
    return renderSubAlias($alias);
}

/**
 * Walk the alias once, splitting on [...] (slow) and {...} (fast) groups.
 * Plain runs and inner runs both get the CAPS-emphasis transform applied
 * by renderCapsEmphasis(). Bracketed groups are wrapped in a <prosody>
 * rate envelope around their (already emphasis-processed) content.
 */
function renderSubAlias(string $alias): string {
    $out = '';
    $offset = 0;
    $len = strlen($alias);
    while ($offset < $len) {
        if (preg_match('/(\[([^\]]*)\])|(\{([^}]*)\})/', $alias, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $matchStart = $m[0][1];
            if ($matchStart > $offset) {
                $out .= renderCapsEmphasis(substr($alias, $offset, $matchStart - $offset));
            }
            if ($m[1][0] !== '') {
                // [...] = slow
                $out .= '<prosody rate="80%">' . renderCapsEmphasis($m[2][0]) . '</prosody>';
            } else {
                // {...} = fast
                $out .= '<prosody rate="130%">' . renderCapsEmphasis($m[4][0]) . '</prosody>';
            }
            $offset = $matchStart + strlen($m[0][0]);
        } else {
            $out .= renderCapsEmphasis(substr($alias, $offset));
            break;
        }
    }
    return $out;
}

function renderCapsEmphasis(string $s): string {
    if (!preg_match('/[A-Z]{2,}/', $s)) {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1);
    }
    $parts = preg_split('/([A-Z]{2,})/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $piece) {
        if ($piece === '') continue;
        if (preg_match('/^[A-Z]{2,}$/', $piece)) {
            $emph = htmlspecialchars(strtolower($piece), ENT_QUOTES | ENT_XML1);
            $out .= '<prosody pitch="+25%" rate="92%">' . $emph . '</prosody>';
        } else {
            $out .= htmlspecialchars($piece, ENT_QUOTES | ENT_XML1);
        }
    }
    return $out;
}

function tokensToSsml(string $escaped, array $tokenMap): string {
    if (!$tokenMap) return $escaped;
    return strtr($escaped, $tokenMap);
}

/**
 * Build SSML for a single (text, category) pair. The text is run through:
 *   1. pause replacement (substring → placeholder)
 *   2. tune replacement  (substring → placeholder)
 *   3. XML escape
 *   4. placeholder → SSML tag
 * Wrapped in a <voice> element with prosody from the category config.
 */
function buildVoiceBlock(string $text, array $cfg, string $category, ?string $overrideVoice = null): string {
    $cat = $cfg['categories'][$category] ?? null;
    $voiceCode = $overrideVoice ?: ($cat['tts_voice_code'] ?? 'en-US-BrianMultilingualNeural');

    $placeholder = '';
    $text = applyPauses($text, $cfg['pauses'], $placeholder);
    $tokenMap = [];
    $text = applyTunes($text, $cfg['tunes'], $tokenMap);
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $escaped = placeholdersToBreaks($escaped);
    $escaped = tokensToSsml($escaped, $tokenMap);

    $inner = $escaped;
    if ($cat) {
        $rate   = (int)$cat['tts_voice_rate_pct'];
        $pitch  = (float)$cat['tts_voice_pitch_st'];
        $volume = (int)$cat['tts_voice_volume'];
        $prosodyAttrs = [];
        if ($rate   !== 0)   $prosodyAttrs[] = 'rate="'   . ($rate >= 0 ? "+$rate%" : "$rate%") . '"';
        if (abs($pitch) > 0.005) {
            // Trim trailing zeros so "1.50" emits "1.5", "2.00" emits "2".
            $pitchStr = rtrim(rtrim(number_format($pitch, 2, '.', ''), '0'), '.');
            $prosodyAttrs[] = 'pitch="' . ($pitch >= 0 ? "+{$pitchStr}st" : "{$pitchStr}st") . '"';
        }
        if ($volume !== 100) $prosodyAttrs[] = 'volume="' . $volume . '"';
        if ($prosodyAttrs) {
            $inner = '<prosody ' . implode(' ', $prosodyAttrs) . '>' . $inner . '</prosody>';
        }
        if (!empty($cat['tts_voice_style'])) {
            $style       = htmlspecialchars($cat['tts_voice_style'], ENT_QUOTES | ENT_XML1);
            $styleDegree = htmlspecialchars((string)($cat['tts_voice_style_degree'] ?? '1.0'), ENT_QUOTES | ENT_XML1);
            $inner = "<mstts:express-as style=\"$style\" styledegree=\"$styleDegree\">$inner</mstts:express-as>";
        }
    }
    $voiceCodeEsc = htmlspecialchars($voiceCode, ENT_QUOTES | ENT_XML1);
    return "<voice name=\"$voiceCodeEsc\">$inner</voice>";
}

function wrapSsml(string $voiceBlocks): string {
    return '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xmlns:mstts="http://www.w3.org/2001/mstts" xml:lang="en-US">'
        . $voiceBlocks
        . '</speak>';
}

function azureTtsSynthesize(string $ssml, array $cfg, ?string &$err = null): string {
    $key = readEnv('AZURE_SPEECH_KEY');
    if (!$key) { $err = 'AZURE_SPEECH_KEY not set'; return ''; }
    $region = $cfg['system']['tts_region'] ?? (readEnv('AZURE_SPEECH_REGION') ?: 'brazilsouth');
    $format = $cfg['system']['tts_output_format'] ?? 'audio-24khz-48kbitrate-mono-mp3';

    $ch = curl_init("https://{$region}.tts.speech.microsoft.com/cognitiveservices/v1");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Ocp-Apim-Subscription-Key: ' . $key,
            'Content-Type: application/ssml+xml',
            'X-Microsoft-OutputFormat: ' . $format,
            'User-Agent: yada-tts',
        ],
        CURLOPT_POSTFIELDS     => $ssml,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $code >= 400) {
        $err = "Azure TTS HTTP $code: " . ($cerr ?: substr((string)$resp, 0, 300));
        return '';
    }
    return (string)$resp;
}

/**
 * Static catalog of Azure neural voices we expose in the UI. Curated rather
 * than fetched from /voices/list so the admin sees a sensible subset (~30
 * English, plus key Hebrew/Greek/Arabic for scripture-quote categories).
 * Add/remove entries as needed.
 */
function azureVoiceCatalog(): array {
    return [
        // ── American English — male, narration-style ──
        ['code' => 'en-US-BrianMultilingualNeural',    'label' => 'Brian (Multilingual, authoritative male, US)',     'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-AndrewMultilingualNeural',   'label' => 'Andrew (Multilingual, warm male, US)',             'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-DavisNeural',                'label' => 'Davis (deep male, US)',                            'lang' => 'en-US', 'gender' => 'M', 'styles' => ['chat', 'angry', 'cheerful', 'excited', 'friendly', 'hopeful', 'sad', 'shouting', 'terrified', 'unfriendly', 'whispering']],
        ['code' => 'en-US-TonyNeural',                 'label' => 'Tony (gravelly male, US)',                         'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-RogerNeural',                'label' => 'Roger (older male, US)',                           'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-SteffanNeural',              'label' => 'Steffan (resonant male, US)',                      'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-ChristopherNeural',          'label' => 'Christopher (mature male, US)',                    'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-GuyNeural',                  'label' => 'Guy (newscaster-style male, US)',                  'lang' => 'en-US', 'gender' => 'M', 'styles' => ['newscast']],
        ['code' => 'en-US-JasonNeural',                'label' => 'Jason (younger male, US)',                         'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-US-EricNeural',                 'label' => 'Eric (clear male, US)',                            'lang' => 'en-US', 'gender' => 'M', 'styles' => ['general']],

        // ── American English — female ──
        ['code' => 'en-US-EmmaMultilingualNeural',     'label' => 'Emma (Multilingual female, US)',                   'lang' => 'en-US', 'gender' => 'F', 'styles' => ['general']],
        ['code' => 'en-US-AvaMultilingualNeural',      'label' => 'Ava (Multilingual female, US)',                    'lang' => 'en-US', 'gender' => 'F', 'styles' => ['general']],
        ['code' => 'en-US-JennyMultilingualNeural',    'label' => 'Jenny (Multilingual female, US)',                  'lang' => 'en-US', 'gender' => 'F', 'styles' => ['chat', 'newscast', 'friendly', 'assistant']],
        ['code' => 'en-US-AriaNeural',                 'label' => 'Aria (newscast female, US)',                       'lang' => 'en-US', 'gender' => 'F', 'styles' => ['newscast', 'chat', 'friendly']],
        ['code' => 'en-US-NancyNeural',                'label' => 'Nancy (clear female, US)',                         'lang' => 'en-US', 'gender' => 'F', 'styles' => ['general']],
        ['code' => 'en-US-SaraNeural',                 'label' => 'Sara (friendly female, US)',                       'lang' => 'en-US', 'gender' => 'F', 'styles' => ['general']],

        // ── UK English — male/female ──
        ['code' => 'en-GB-RyanNeural',                 'label' => 'Ryan (male, UK)',                                  'lang' => 'en-GB', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-GB-ThomasNeural',               'label' => 'Thomas (male, UK)',                                'lang' => 'en-GB', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'en-GB-SoniaNeural',                'label' => 'Sonia (female, UK)',                               'lang' => 'en-GB', 'gender' => 'F', 'styles' => ['general']],

        // ── Hebrew ──
        ['code' => 'he-IL-AvriNeural',                 'label' => 'Avri (male, Hebrew)',                              'lang' => 'he-IL', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'he-IL-HilaNeural',                 'label' => 'Hila (female, Hebrew)',                            'lang' => 'he-IL', 'gender' => 'F', 'styles' => ['general']],

        // ── Greek ──
        ['code' => 'el-GR-NestorasNeural',             'label' => 'Nestoras (male, Greek)',                           'lang' => 'el-GR', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'el-GR-AthinaNeural',               'label' => 'Athina (female, Greek)',                           'lang' => 'el-GR', 'gender' => 'F', 'styles' => ['general']],

        // ── Arabic (MSA) ──
        ['code' => 'ar-SA-HamedNeural',                'label' => 'Hamed (male, Arabic MSA)',                         'lang' => 'ar-SA', 'gender' => 'M', 'styles' => ['general']],
        ['code' => 'ar-SA-ZariyahNeural',              'label' => 'Zariyah (female, Arabic MSA)',                     'lang' => 'ar-SA', 'gender' => 'F', 'styles' => ['general']],
    ];
}

function azureOutputFormats(): array {
    return [
        ['code' => 'audio-16khz-32kbitrate-mono-mp3',  'label' => 'MP3 16 kHz / 32 kbps (smallest)'],
        ['code' => 'audio-24khz-48kbitrate-mono-mp3',  'label' => 'MP3 24 kHz / 48 kbps (default)'],
        ['code' => 'audio-24khz-96kbitrate-mono-mp3',  'label' => 'MP3 24 kHz / 96 kbps'],
        ['code' => 'audio-48khz-96kbitrate-mono-mp3',  'label' => 'MP3 48 kHz / 96 kbps'],
        ['code' => 'audio-48khz-192kbitrate-mono-mp3', 'label' => 'MP3 48 kHz / 192 kbps (highest mp3)'],
        ['code' => 'riff-24khz-16bit-mono-pcm',        'label' => 'WAV 24 kHz / 16-bit'],
        ['code' => 'riff-48khz-16bit-mono-pcm',        'label' => 'WAV 48 kHz / 16-bit'],
        ['code' => 'ogg-48khz-16bit-mono-opus',        'label' => 'OGG Opus 48 kHz'],
    ];
}

function ttsCategories(): array {
    return [
        ['code' => 'main',            'label' => 'Main narration (body text)'],
        ['code' => 'translation',     'label' => 'Translation prose (bold text)'],
        ['code' => 'word_definition', 'label' => 'Word definition (parenthesized italic + definition)'],
        ['code' => 'bible',           'label' => 'Bible quotation'],
        ['code' => 'islam',           'label' => 'Islamic scripture quotation'],
    ];
}
