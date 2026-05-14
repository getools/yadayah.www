<?php
/**
 * Generate a short preview MP3 from arbitrary text using the chosen voice
 * (or the saved category default). Returns audio/mpeg directly so the UI
 * can play it inline with new Audio(url).
 *
 *   POST  { tts_key, text, category?, voice_code?, style?, style_degree?, rate_pct?, pitch_st?, volume? }
 *     - If voice_code is given: synthesise with that voice and the prosody
 *       options from the request body (used by the live preview slider).
 *     - Else: use the saved category default from yy_tts_category_voice.
 *
 * Body limited to 1000 chars to keep previews fast and free-tier-friendly.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';

$user = requireAuth();
$db = getDb();
setCurrentUser($db, (int)$user['user_key']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('POST required');
$data = json_decode(file_get_contents('php://input'), true) ?: [];

$ttsKey = (int)($data['tts_key'] ?? 0);
$text   = trim((string)($data['text'] ?? ''));
if (!$ttsKey) errorResponse('tts_key required');
if ($text === '') errorResponse('text required');
if (mb_strlen($text) > 2000) errorResponse('text too long (2000 char limit for preview)');

// If the caller sends HTML-formatted text (with <b>/<i> from the
// contenteditable preview field), strip outer wrappers like <div> /
// <br> / <p> but keep the inline B/I markup that drives segmentation.
// Anything else (data-font spans, attributes) is dropped so the
// preview matches what segmentParagraph already understands.
$hasFormat = (bool)preg_match('/<\s*(b|i|strong|em)\b/i', $text);
if ($hasFormat) {
    // Normalise common contenteditable artifacts to plain inline B/I.
    $text = preg_replace('/<\s*strong\b[^>]*>/i', '<b>', $text);
    $text = preg_replace('/<\s*\/\s*strong\s*>/i', '</b>', $text);
    $text = preg_replace('/<\s*em\b[^>]*>/i',     '<i>', $text);
    $text = preg_replace('/<\s*\/\s*em\s*>/i',    '</i>', $text);
    // Drop any other tag — keep only <b>, </b>, <i>, </i>.
    $text = preg_replace('/<(?!\/?(?:b|i)\b)[^>]+>/i', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
}

$cfg = loadTtsConfig($db, $ttsKey);
if (!$cfg['system']) errorResponse('Unknown tts_key', 404);

$category = (string)($data['category'] ?? 'main');
$overrideVoice = !empty($data['voice_code']) ? (string)$data['voice_code'] : null;

// Per-row tune override: when the user clicks ▶ next to a specific tune,
// we want THAT tune to fire unconditionally — ignoring its active flag,
// B/I restrictions, and any other tunes that might match the same text.
// Replace the loaded tunes with a single synthetic rule built from the
// caller-supplied print + phonetic + type.
if (!empty($data['tune_override']) && is_array($data['tune_override'])) {
    $to = $data['tune_override'];
    $cfg['tunes'] = [[
        'tts_tune_key'           => 999999,
        'tts_tune_print'         => (string)($to['print'] ?? ''),
        'tts_tune_phonetic'      => '',
        'tts_tune_phonetic_sub'  => (string)($to['sub']  ?? ''),
        'tts_tune_phonetic_ipa'  => (string)($to['ipa']  ?? ''),
        'tts_tune_phonetic_sapi' => '',
        'tts_tune_phonetic_type' => in_array(($to['type'] ?? 'sub'), ['sub','ipa','sapi'], true) ? $to['type'] : 'sub',
        'tts_tune_active_flag'   => true,
        'tts_tune_match_bold'    => false,
        'tts_tune_match_italic'  => false,
    ]];
}

// If the caller passes prosody overrides, splice a synthetic category row in.
if ($overrideVoice) {
    $cfg['categories'][$category] = [
        'tts_voice_code'         => $overrideVoice,
        'tts_voice_style'        => $data['style'] ?? null,
        'tts_voice_style_degree' => $data['style_degree'] ?? 1.0,
        'tts_voice_rate_pct'     => (int)($data['rate_pct'] ?? 0),
        'tts_voice_pitch_st'     => (float)($data['pitch_st'] ?? 0),
        'tts_voice_volume'       => (int)($data['volume'] ?? 100),
    ];
}

// When the text carries <b>/<i> markup, segment it the same way the
// build worker does so each B/I run routes through the matching
// category voice ('translation' for <b>, 'word_definition' for (parens),
// 'main' otherwise) — pronunciation tunes with B/I match flags then
// fire correctly. The caller's voice_code override is applied as the
// main-category voice so the listener still hears their picked voice
// for the body of the sentence.
if ($hasFormat) {
    $segs = segmentParagraph($text);
    if (!$segs) errorResponse('no audible content after segmentation');
    if ($overrideVoice && !isset($cfg['categories']['main'])) {
        $cfg['categories']['main'] = ['tts_voice_code' => $overrideVoice];
    }
    $voiceBlock = '';
    foreach ($segs as $seg) {
        // Override applies only to the main category — translation /
        // word_definition keep their configured category voices so the
        // preview matches what the synthesized book would actually
        // sound like.
        $segOverride = ($seg['category'] === $category) ? $overrideVoice : null;
        $voiceBlock .= buildVoiceBlock($seg['text'], $cfg, $seg['category'], $segOverride);
    }
} else {
    $voiceBlock = buildVoiceBlock($text, $cfg, $category, $overrideVoice);
}
$ssml = wrapSsml($voiceBlock);

$err = '';
$mp3 = azureTtsSynthesize($ssml, $cfg, $err);
if ($mp3 === '') errorResponse('TTS failed: ' . $err, 502);

header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($mp3));
header('Cache-Control: no-store');
echo $mp3;
