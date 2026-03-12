<?php
require_once __DIR__ . '/config.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

// --- Rate limiting ---
$now = time();

// Per-minute burst limit: max 10 requests per 60 seconds
if (!isset($_SESSION['ask_times'])) $_SESSION['ask_times'] = [];
$_SESSION['ask_times'] = array_filter($_SESSION['ask_times'], function($t) use ($now) {
    return $t > $now - 60;
});
if (count($_SESSION['ask_times']) >= 10) {
    errorResponse('Rate limit exceeded. Please wait a moment before asking again.', 429);
}
$_SESSION['ask_times'][] = $now;

// Daily limit: max 10 questions per session per day
$today = date('Y-m-d');
if (($_SESSION['ask_daily_date'] ?? '') !== $today) {
    $_SESSION['ask_daily_date'] = $today;
    $_SESSION['ask_daily_count'] = 0;
}
if ($_SESSION['ask_daily_count'] >= 10) {
    errorResponse('You have reached the daily limit of 10 questions. Please come back tomorrow.', 429);
}
$_SESSION['ask_daily_count']++;

// --- Parse request ---
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['question'])) {
    errorResponse('Question is required', 400);
}
$question = trim($input['question']);
if (strlen($question) > 2000) {
    errorResponse('Question too long (max 2000 characters)', 400);
}
$history = $input['history'] ?? [];
if (count($history) > 20) {
    $history = array_slice($history, -20);
}

// --- Retrieve relevant context from yy_paragraph via FTS ---
$stripChars = "\u{02BF}\u{02BE}\u{02BC}\u{02BB}\u{02B9}\u{02BA}\u{2018}\u{2019}\u{201C}\u{201D}\u{2013}\u{2014}'";
$cleanQ = str_replace(str_split($stripChars), '', $question);
$cleanQ = preg_replace('/\s{2,}/', ' ', trim($cleanQ));

$pdo = getDb();
$EXCLUDE_SERIES = 8;

// Build OR-based tsquery for broader matching (any word matches, ranked by relevance)
function buildOrTsquery(string $text): string {
    // Remove stop words and short words, join with OR
    $words = preg_split('/\s+/', strtolower(trim($text)));
    $stopWords = ['is','the','a','an','of','to','in','for','on','at','by','with','from','as','it','that','this','are','was','be','has','had','do','does','did','what','which','who','whom','how','why','when','where','can','could','would','should','will','shall','may','might','and','or','but','not','no','so','if','than','too','very','just','about','into','over','after','before','between','out','up','down','all','each','every','both','few','more','most','other','some','such','only','own','same','then','there','these','those','your','my','his','her','its','our','their','any','i','you','he','she','we','they','me','him','us','them'];
    $terms = [];
    foreach ($words as $w) {
        $w = preg_replace('/[^a-z0-9\'-]/', '', $w);
        if (strlen($w) >= 2 && !in_array($w, $stopWords)) {
            $terms[] = preg_replace('/[^a-z0-9]/', '', $w); // strip special chars for tsquery safety
        }
    }
    if (empty($terms)) return '';
    return implode(' | ', $terms);
}

$orQuery = buildOrTsquery($cleanQ);

// --- Session tracking ---
if (empty($_SESSION['ask_session_key'])) {
    $ipAddr = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0]) ?: ($_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');

    $sessStmt = $pdo->prepare("
        INSERT INTO yy_ask_session (session_id, ip_address, user_agent, referer, accept_language)
        VALUES (?, ?, ?, ?, ?)
        RETURNING ask_session_key
    ");
    $sessStmt->execute([
        session_id(),
        $ipAddr,
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $_SERVER['HTTP_REFERER'] ?? '',
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
    ]);
    $_SESSION['ask_session_key'] = (int)$sessStmt->fetchColumn();

    // IP geolocation lookup (non-blocking, best-effort)
    if ($ipAddr && !in_array($ipAddr, ['127.0.0.1', '::1'])) {
        $geoJson = @file_get_contents("http://ip-api.com/json/" . urlencode($ipAddr) . "?fields=status,city,regionName,countryCode", false, stream_context_create(['http' => ['timeout' => 2]]));
        if ($geoJson !== false) {
            $geo = json_decode($geoJson, true);
            if (($geo['status'] ?? '') === 'success') {
                $geoStmt = $pdo->prepare("UPDATE yy_ask_session SET ip_city = ?, ip_region = ?, ip_country = ? WHERE ask_session_key = ?");
                $geoStmt->execute([$geo['city'] ?? null, $geo['regionName'] ?? null, $geo['countryCode'] ?? null, $_SESSION['ask_session_key']]);
            }
        }
    }
}
$askSessionKey = $_SESSION['ask_session_key'];

// Try AND-based search first, fall back to OR if too few results
$stmt = $pdo->prepare("
    SELECT p.paragraph_text_plain,
           v.volume_label,
           s.series_label,
           p.paragraph_page,
           ts_rank(p.paragraph_tsv, plainto_tsquery('english', ?)) AS rank
    FROM yy_paragraph p
    JOIN yy_volume v ON v.volume_key = p.volume_key
    JOIN yy_series s ON s.series_key = p.series_key
    WHERE p.paragraph_tsv @@ plainto_tsquery('english', ?)
      AND p.paragraph_active_flag = true
      AND v.volume_active_flag = true
      AND p.series_key != ?
    ORDER BY rank DESC
    LIMIT 20
");
$stmt->execute([$cleanQ, $cleanQ, $EXCLUDE_SERIES]);
$contextRows = $stmt->fetchAll();

// If AND search returns < 5 results and we have an OR query, supplement with OR matches
if (count($contextRows) < 5 && $orQuery) {
    $orStmt = $pdo->prepare("
        SELECT p.paragraph_text_plain,
               v.volume_label,
               s.series_label,
               p.paragraph_page,
               ts_rank(p.paragraph_tsv, to_tsquery('english', ?)) AS rank
        FROM yy_paragraph p
        JOIN yy_volume v ON v.volume_key = p.volume_key
        JOIN yy_series s ON s.series_key = p.series_key
        WHERE p.paragraph_tsv @@ to_tsquery('english', ?)
          AND p.paragraph_active_flag = true
          AND v.volume_active_flag = true
          AND p.series_key != ?
        ORDER BY rank DESC
        LIMIT 20
    ");
    $orStmt->execute([$orQuery, $orQuery, $EXCLUDE_SERIES]);
    $orRows = $orStmt->fetchAll();
    // Merge, avoiding duplicates (by text prefix)
    $seen = [];
    foreach ($contextRows as $r) $seen[substr($r['paragraph_text_plain'] ?? '', 0, 100)] = true;
    foreach ($orRows as $r) {
        $key = substr($r['paragraph_text_plain'] ?? '', 0, 100);
        if (!isset($seen[$key])) { $contextRows[] = $r; $seen[$key] = true; }
        if (count($contextRows) >= 20) break;
    }
}

$contextBlock = "";
foreach ($contextRows as $row) {
    $text = trim($row['paragraph_text_plain'] ?? '');
    if (!$text) continue;
    if (strlen($text) > 800) $text = substr($text, 0, 800) . '...';
    $contextBlock .= "[{$row['series_label']} / {$row['volume_label']}, p.{$row['paragraph_page']}]\n{$text}\n\n";
}

// --- Search video transcripts ---
$tStmt = $pdo->prepare("
    SELECT transcript_title, transcript_yearmonth, transcript_text,
           ts_rank(transcript_tsv, plainto_tsquery('english', ?)) AS rank
    FROM yy_transcript
    WHERE transcript_tsv @@ plainto_tsquery('english', ?)
      AND transcript_active_flag = true
    ORDER BY rank DESC
    LIMIT 10
");
$tStmt->execute([$cleanQ, $cleanQ]);
$transcriptRows = $tStmt->fetchAll();

// If AND search returns < 5 transcript results, supplement with OR matches
if (count($transcriptRows) < 5 && $orQuery) {
    $tOrStmt = $pdo->prepare("
        SELECT transcript_title, transcript_yearmonth, transcript_text,
               ts_rank(transcript_tsv, to_tsquery('english', ?)) AS rank
        FROM yy_transcript
        WHERE transcript_tsv @@ to_tsquery('english', ?)
          AND transcript_active_flag = true
        ORDER BY rank DESC
        LIMIT 10
    ");
    $tOrStmt->execute([$orQuery, $orQuery]);
    $tOrRows = $tOrStmt->fetchAll();
    $seen = [];
    foreach ($transcriptRows as $r) $seen[substr($r['transcript_text'] ?? '', 0, 100)] = true;
    foreach ($tOrRows as $r) {
        $key = substr($r['transcript_text'] ?? '', 0, 100);
        if (!isset($seen[$key])) { $transcriptRows[] = $r; $seen[$key] = true; }
        if (count($transcriptRows) >= 10) break;
    }
}

$transcriptBlock = "";
foreach ($transcriptRows as $row) {
    $text = trim($row['transcript_text'] ?? '');
    if (!$text) continue;
    if (strlen($text) > 800) $text = substr($text, 0, 800) . '...';
    $ym = $row['transcript_yearmonth'];
    $date = $ym ? substr($ym, 0, 4) . '/' . substr($ym, 4, 2) : '';
    $transcriptBlock .= "[Video: {$row['transcript_title']}" . ($date ? ", $date" : "") . "]\n{$text}\n\n";
}

// --- Build system prompt ---
// Fetch source counts for system prompt
$bookCount = $pdo->query("SELECT COUNT(DISTINCT volume_key) FROM yy_paragraph WHERE paragraph_active_flag = true")->fetchColumn();
$transcriptCount = $pdo->query("SELECT COUNT(DISTINCT transcript_source) FROM yy_transcript WHERE transcript_active_flag = true")->fetchColumn();

$systemPrompt = <<<SYSPROMPT
You are Yada, the pen name of Craig Winn. Yada and Craig Winn are the same person — any reference to or statement by one is equally attributable to the other. You have written nearly 40 books exploring scripture, prophecy, history, archaeology, and religion. You have spent decades translating the original Hebrew text of the Towrah, Naby', and Mizmowr, producing what you consider the most accurate and comprehensive translations available.

You have access to a searchable database of {$bookCount} book volumes (~7 million words) and {$transcriptCount} video presentation transcripts (~3.3 million words, Feb 2025 – Mar 2026). For each question, the most relevant excerpts from these sources are provided below. Not all sources are shown for every question — only those matching the topic.

YOUR BOOK SERIES (7 series, 32 volumes):
- An Introduction to God (3 volumes) — foundational introduction
- Yada Yahowah (9 volumes) — comprehensive exploration of Yahowah's message
- Observations (4 volumes) — analytical commentary
- Coming Home (3 volumes) — the return to Yahowah
- Babel (3 volumes) — confusion and deception in religion
- Twistianity (5 volumes) — critique of Christianity's distortions
- God Damn Religion (5 volumes) — exposing religious corruption

YOUR VIDEO TRANSCRIPT SERIES (339 transcripts):
- Qatsyr / Harvest (55 episodes) — Shabuw'ah and harvest themes
- Appalling Twistianity (41 episodes) — extended critique of Christianity
- Coming Home / A Voice (36 episodes) — Mizmowr/Psalms exploration
- Islam / Muhammad's Criminal Enterprise (32 episodes) — exposing Islam
- Messiah and Son (30 episodes) — Dowd as Messiah
- Final Word Picture (26 episodes, Jan–Mar 2026) — recent prophetic analysis
- Chakmah Choter (25 episodes) — wisdom and the Branch
- Anti-Christ (21 episodes) — identifying the man of lawlessness
- Returning to the Garden (19 episodes, Dec 2025–Jan 2026) — Eden and restoration
- Gibowr / Confident and Courageous (16 episodes) — strength and valor
- Shabbat Family Shows (12 episodes) — extended multi-topic discussions
- Vlog on a Blog (11 episodes) — current events commentary
- Liberty (5 episodes) — freedom themes
- Mother Goddess (4 episodes) — Asherah, Mary, goddess worship
- Man of Edom (3 episodes) — Esau/Edom prophecy

TOPICS COVERED MORE DEEPLY IN TRANSCRIPTS THAN IN BOOKS:
- Final Word Picture (5.4x more transcript content) — a recent video-only series
- Anti-Christ / Man of Lawlessness (2.7x more) — extensive video series
- Kippur / Atonement (1.9x more) — deeper video exploration
- Returning to the Garden / Eden (1.9x more) — recent video-only series
- Gibowr / Confident and Courageous — video-only series (no book equivalent)
- Mother Goddess / Asherah — dedicated video series expanding on book references
- Liberty / Freedom — video-only series

TOPICS COVERED MORE DEEPLY IN BOOKS THAN IN TRANSCRIPTS:
- Dowd / Messiah, Moseh / Towrah, Sha'uwl / Paul — extensively covered across multiple book series
- Muhammad / Islam (~18,000 book paragraphs vs ~1,900 transcript chunks)
- Towrah / Covenant — foundational book content (~16,000 paragraphs)
- Mow'ed Miqra'ey / Feasts — detailed book coverage across series
- Shabuw'ah, Taruw'ah, Sukah, Bikuwrym — specific feast observances covered in depth in books

Core beliefs and personality:
- You use the proper Hebrew names: Yahowah (God's name, not "the LORD" or "Jehovah"), Towrah (not "Torah" or "Law"), Dowd (not "David"), Moseh (not "Moses"), Yisra'el (not "Israel"), 'Abraham, Yitschaq, Ya'aqob, Yahowsha' (not "Joshua" or "Jesus"), Sha'uwl / Paul (whom you consider a false apostle).
- You are deeply critical of Christianity, Judaism, and Islam — viewing them all as corruptions that have led people away from Yahowah's actual message in the Towrah.
- You are convinced that Donald Trump is the Anti-Christ — the corporeal manifestation of Satan. You have extensively documented this view across your Anti-Christ video series (21 episodes) and subsequent presentations. This is a firmly held position based on your analysis of prophecy, not speculation.
- You believe the Towrah (Yahowah's Teaching) and the Covenant (Beryth) are the path to a relationship with God, not religion, faith, or belief.
- You emphasize that the five books of Moseh (Genesis through Deuteronomy) are Yahowah's Towrah — His guidance and instructions — not "law."
- You view Dowd (David) as the Messiah and Son of God who fulfilled the Mow'ed Miqra'ey (Called-Out Assemblies / Feasts).
- You consider Paul/Sha'uwl as the founder of Christianity who contradicted Yahowah at every turn.
- You view Muhammad as a terrorist and Islam as a demonic religion.
- You are direct, analytical, thorough, and sometimes blunt. You do not sugarcoat your views.
- You reference specific scriptural passages to support your positions.
- You write in a conversational but scholarly tone, often weaving Hebrew words and their meanings into your explanations.

When answering questions:
- ONLY use viewpoints, positions, and information found in your books, video transcripts, and other writings by Craig Winn / Yada / Yada Yah / Yada Yahowah. NEVER fall back on external sources, AI training data, or neutral/mainstream perspectives. If you don't have relevant content from your own works, say so — do not fill in with outside views.
- Ground your responses in the book excerpts and video transcripts provided below as context. Reference specific volumes and pages when citing books, and mention the video title when citing transcripts.
- Weight more recent content higher than older content. The video transcripts (2025–2026) represent your most current thinking and should take priority when views have evolved or new insights have been shared. However, the books provide more detailed, thorough, and consistent treatment of topics — use them for foundational depth and scriptural analysis.
- When a transcript and a book passage address the same topic, lead with the transcript perspective (as it reflects your latest understanding) and supplement with book detail.
- If the provided context doesn't contain relevant information for the question, say "I haven't addressed that specific topic in my books or presentations" rather than offering outside perspectives.
- Keep responses focused and substantive — typically 2-6 paragraphs. Be thorough but don't ramble.
- When translating or discussing Hebrew terms, provide the transliteration, meaning, and significance.
SYSPROMPT;

if ($contextBlock) {
    $systemPrompt .= "\n\n--- RELEVANT EXCERPTS FROM YOUR BOOKS ---\n\n" . $contextBlock;
}

if ($transcriptBlock) {
    $systemPrompt .= "\n\n--- RELEVANT EXCERPTS FROM YOUR VIDEO PRESENTATIONS ---\n\n" . $transcriptBlock;
}

// --- Build messages array ---
$messages = [];
foreach ($history as $h) {
    if (isset($h['role']) && isset($h['content']) && in_array($h['role'], ['user', 'assistant'])) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
    }
}
$messages[] = ['role' => 'user', 'content' => $question];

// --- Determine AI model/provider ---
$MODEL_MAP = [
    'gemini-flash'  => ['name' => 'gemini-2.0-flash', 'env' => 'GOOGLE_API_KEY',    'label' => 'Gemini 2.0 Flash'],
    'gpt-4o-mini'   => ['name' => 'gpt-4o-mini',      'env' => 'OPENAI_API_KEY',    'label' => 'GPT-4o mini'],
    'claude-haiku'  => ['name' => 'claude-haiku-4-5-20251001', 'env' => 'ANTHROPIC_API_KEY', 'label' => 'Claude Haiku 4.5'],
    'claude-sonnet' => ['name' => 'claude-sonnet-4-20250514',  'env' => 'ANTHROPIC_API_KEY', 'label' => 'Claude Sonnet 4'],
];

$modelSetting = getAskModel($pdo);
$modelInfo = $MODEL_MAP[$modelSetting];
$modelName = $modelInfo['name'];
$apiKey = readEnvKey($modelInfo['env']);

if (!$apiKey) {
    errorResponse('API key (' . $modelInfo['env'] . ') not configured for ' . $modelInfo['label'], 500);
}

// --- Insert log row ---
$logStmt = $pdo->prepare("
    INSERT INTO yy_ask_session_log (ask_session_key, ask_log_question, ask_log_context_count, ask_log_model)
    VALUES (?, ?, ?, ?)
    RETURNING ask_session_log_key
");
$logStmt->execute([$askSessionKey, $question, count($contextRows) + count($transcriptRows), $modelName]);
$askLogKey = (int)$logStmt->fetchColumn();
$startTime = microtime(true);

// Tracking variables for streaming
$fullResponse = '';
$inputTokens = 0;
$outputTokens = 0;
$streamError = '';

// --- Build provider-specific request ---
switch ($modelSetting) {
    case 'gemini-flash':
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $modelName . ':streamGenerateContent?alt=sse';
        $headers = [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ];
        $geminiContents = [];
        foreach ($messages as $msg) {
            $geminiContents[] = [
                'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $msg['content']]],
            ];
        }
        $body = json_encode([
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => $geminiContents,
            'generationConfig' => ['maxOutputTokens' => 2048],
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

        $parseEvent = function($event) use (&$fullResponse, &$inputTokens, &$outputTokens, &$streamError) {
            if (isset($event['error'])) {
                $streamError = $event['error']['message'] ?? 'Gemini API error';
                echo 'data: ' . json_encode(['error' => $streamError]) . "\n\n";
                echo "data: [DONE]\n\n";
                flush();
                return;
            }
            $cand = $event['candidates'][0] ?? null;
            if ($cand) {
                $text = $cand['content']['parts'][0]['text'] ?? '';
                if ($text !== '') {
                    $fullResponse .= $text;
                    echo 'data: ' . json_encode(['text' => $text]) . "\n\n";
                    flush();
                }
                if (($cand['finishReason'] ?? '') === 'STOP') {
                    echo "data: [DONE]\n\n";
                    flush();
                }
            }
            if (isset($event['usageMetadata'])) {
                $inputTokens = $event['usageMetadata']['promptTokenCount'] ?? 0;
                $outputTokens = $event['usageMetadata']['candidatesTokenCount'] ?? 0;
            }
        };
        $sendDoneAfter = true; // Gemini may not always send finishReason
        break;

    case 'gpt-4o-mini':
        $url = 'https://api.openai.com/v1/chat/completions';
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        $oaiMessages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($messages as $msg) {
            $oaiMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
        $body = json_encode([
            'model' => $modelName,
            'max_tokens' => 2048,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'messages' => $oaiMessages,
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

        $parseEvent = function($event) use (&$fullResponse, &$inputTokens, &$outputTokens, &$streamError) {
            if (isset($event['error'])) {
                $streamError = $event['error']['message'] ?? 'OpenAI API error';
                echo 'data: ' . json_encode(['error' => $streamError]) . "\n\n";
                echo "data: [DONE]\n\n";
                flush();
                return;
            }
            if (!empty($event['choices'])) {
                $text = $event['choices'][0]['delta']['content'] ?? '';
                if ($text !== '') {
                    $fullResponse .= $text;
                    echo 'data: ' . json_encode(['text' => $text]) . "\n\n";
                    flush();
                }
            }
            if (isset($event['usage'])) {
                $inputTokens = $event['usage']['prompt_tokens'] ?? 0;
                $outputTokens = $event['usage']['completion_tokens'] ?? 0;
            }
        };
        $sendDoneAfter = false; // OpenAI sends [DONE] natively
        break;

    default: // claude-haiku, claude-sonnet
        $url = 'https://api.anthropic.com/v1/messages';
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ];
        $body = json_encode([
            'model' => $modelName,
            'max_tokens' => 2048,
            'stream' => true,
            'system' => $systemPrompt,
            'messages' => $messages,
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

        $parseEvent = function($event) use (&$fullResponse, &$inputTokens, &$outputTokens, &$streamError) {
            $type = $event['type'] ?? '';
            if ($type === 'message_start') {
                $inputTokens = $event['message']['usage']['input_tokens'] ?? 0;
            } elseif ($type === 'content_block_delta') {
                $text = $event['delta']['text'] ?? '';
                if ($text !== '') {
                    $fullResponse .= $text;
                    echo 'data: ' . json_encode(['text' => $text]) . "\n\n";
                    flush();
                }
            } elseif ($type === 'message_delta') {
                $outputTokens = $event['usage']['output_tokens'] ?? 0;
            } elseif ($type === 'message_stop') {
                echo "data: [DONE]\n\n";
                flush();
            } elseif ($type === 'error') {
                $streamError = $event['error']['message'] ?? 'Claude API error';
                echo 'data: ' . json_encode(['error' => $streamError]) . "\n\n";
                flush();
            }
        };
        $sendDoneAfter = false;
        break;
}

// Release session lock before streaming
session_write_close();

// Switch to SSE output
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
while (ob_get_level()) ob_end_flush();

// --- Common cURL streaming with provider-specific parsing ---
$sseBuffer = '';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$sseBuffer, $parseEvent) {
        $sseBuffer .= $data;
        while (($pos = strpos($sseBuffer, "\n")) !== false) {
            $line = trim(substr($sseBuffer, 0, $pos));
            $sseBuffer = substr($sseBuffer, $pos + 1);
            if ($line === '' || strpos($line, 'data: ') !== 0) continue;
            $payload = substr($line, 6);
            if ($payload === '[DONE]') {
                echo "data: [DONE]\n\n";
                flush();
                continue;
            }
            $event = json_decode($payload, true);
            if ($event) $parseEvent($event);
        }
        return strlen($data);
    },
]);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// For providers that don't send [DONE] explicitly
if (!empty($sendDoneAfter) && $fullResponse && !$streamError) {
    echo "data: [DONE]\n\n";
    flush();
}

if ($curlError) {
    $streamError = 'Connection error: ' . $curlError;
    echo 'data: ' . json_encode(['error' => $streamError]) . "\n\n";
    echo "data: [DONE]\n\n";
    flush();
} elseif ($fullResponse === '' && !$streamError) {
    if ($httpCode >= 400) {
        $streamError = 'API error (HTTP ' . $httpCode . '). Please check API key and billing.';
    } else {
        $streamError = 'No response received from AI service. Please try again.';
    }
    echo 'data: ' . json_encode(['error' => $streamError]) . "\n\n";
    echo "data: [DONE]\n\n";
    flush();
}

// --- Update log and session ---
try {
    $durationMs = (int)((microtime(true) - $startTime) * 1000);
    $updateLog = $pdo->prepare("
        UPDATE yy_ask_session_log
        SET ask_log_response = ?,
            ask_log_prompt_tokens = ?,
            ask_log_completion_tokens = ?,
            ask_log_error = NULLIF(?, ''),
            ask_log_duration_ms = ?
        WHERE ask_session_log_key = ?
    ");
    $updateLog->execute([$fullResponse, $inputTokens, $outputTokens, $streamError, $durationMs, $askLogKey]);

    $updateSess = $pdo->prepare("
        UPDATE yy_ask_session
        SET ask_session_end_dtime = CURRENT_TIMESTAMP,
            ask_session_question_count = ask_session_question_count + 1
        WHERE ask_session_key = ?
    ");
    $updateSess->execute([$askSessionKey]);
} catch (Exception $e) {
    error_log('Ask Yada post-stream DB error: ' . $e->getMessage());
}

// --- Helper functions ---

function readEnvKey(string $name): string {
    $val = getenv($name);
    if ($val) return $val;
    $envPaths = [
        dirname(__DIR__) . '/.env',
        realpath(__DIR__ . '/..') . '/.env',
    ];
    foreach ($envPaths as $envFile) {
        if ($envFile && file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (strpos($line, '#') === 0) continue;
                if (strpos($line, $name . '=') === 0) {
                    return trim(substr($line, strlen($name) + 1));
                }
            }
        }
    }
    return '';
}

function getAskModel(PDO $pdo): string {
    try {
        $stmt = $pdo->query("SELECT setting_value FROM yy_setting WHERE setting_key = 'ask_model'");
        $val = $stmt->fetchColumn();
        if ($val && in_array($val, ['gemini-flash', 'gpt-4o-mini', 'claude-haiku', 'claude-sonnet'])) {
            return $val;
        }
    } catch (Exception $e) {}
    return 'claude-sonnet';
}
