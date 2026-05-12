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
if (mb_strlen($text) > 1000) errorResponse('text too long (1000 char limit for preview)');

$cfg = loadTtsConfig($db, $ttsKey);
if (!$cfg['system']) errorResponse('Unknown tts_key', 404);

$category = (string)($data['category'] ?? 'main');
$overrideVoice = !empty($data['voice_code']) ? (string)$data['voice_code'] : null;

// If the caller passes prosody overrides, splice a synthetic category row in.
if ($overrideVoice) {
    $cfg['categories'][$category] = [
        'tts_voice_code'         => $overrideVoice,
        'tts_voice_style'        => $data['style'] ?? null,
        'tts_voice_style_degree' => $data['style_degree'] ?? 1.0,
        'tts_voice_rate_pct'     => (int)($data['rate_pct'] ?? 0),
        'tts_voice_pitch_st'     => (int)($data['pitch_st'] ?? 0),
        'tts_voice_volume'       => (int)($data['volume'] ?? 100),
    ];
}

$voiceBlock = buildVoiceBlock($text, $cfg, $category, $overrideVoice);
$ssml = wrapSsml($voiceBlock);

$err = '';
$mp3 = azureTtsSynthesize($ssml, $cfg, $err);
if ($mp3 === '') errorResponse('TTS failed: ' . $err, 502);

header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($mp3));
header('Cache-Control: no-store');
echo $mp3;
