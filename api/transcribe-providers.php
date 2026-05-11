<?php
/**
 * Non-OpenAI transcription providers used by transcript-worker.php.
 *
 * Each function takes an audio file path (and an optional public URL of the
 * same file, for providers that prefer URL-based input over upload) and
 * returns rows in the worker's canonical format:
 *   [ ['segment' => 'HH:MM:SS' or 'HH:MM:SS.mmm', 'text' => '...'], ... ]
 *
 * Errors are returned via the trailing &$err reference rather than thrown,
 * matching the existing whisperApiTranscribe() / whisperApiTranscribeChunked()
 * conventions in transcript-worker.php so the main loop can collect
 * methodFailures uniformly.
 *
 * API keys live in /opt/yada-www/.env:
 *   GROQ_API_KEY        — Groq (whisper-large-v3 / whisper-large-v3-turbo)
 *   DEEPGRAM_API_KEY    — Deepgram (nova-3)
 *   ASSEMBLYAI_API_KEY  — AssemblyAI (universal-2)
 *   ELEVENLABS_API_KEY  — ElevenLabs Scribe
 *
 * Public audio URL: for Deepgram and AssemblyAI we prefer to send the URL
 * of the MP3 stored at /opt/yada-www/public/u/audio/audio_<key>_*.mp3 —
 * served at https://yadayah.com/u/audio/audio_<key>_*.mp3. Avoids uploading
 * the same large file when the provider can fetch it themselves.
 */

if (!function_exists('secsToInterval')) {
    function secsToInterval(int $secs): string {
        return sprintf('%02d:%02d:%02d', (int)($secs / 3600), (int)(($secs % 3600) / 60), $secs % 60);
    }
}
if (!function_exists('secsToIntervalFrac')) {
    function secsToIntervalFrac(float $secs): string {
        $whole = (int)$secs;
        $h = (int)($whole / 3600);
        $m = (int)(($whole % 3600) / 60);
        $s = $whole % 60;
        $ms = (int)round(($secs - $whole) * 1000);
        if ($ms >= 1000) { $ms = 0; $s++; }
        return sprintf('%02d:%02d:%02d.%03d', $h, $m, $s, $ms);
    }
}

/**
 * Groq's transcription API is OpenAI-compatible — same /audio/transcriptions
 * shape, just a different base URL and different model name. The worker's
 * existing whisperApiTranscribe()/Chunked() handles segment parsing for us;
 * this function just sets the endpoint + key + model and dispatches.
 *
 * Groq's free tier currently caps individual uploads at 25 MB, so the worker
 * still chunks via ffmpeg (10-min pieces) when the source is larger.
 */
function groqTranscribeChunked(PDO $db, int $jobKey, string $audioPath, string $apiKey, string $prompt, ?string &$err, string $groqModel): array {
    // The endpoint + key are passed by replacing the curl URL and bearer
    // header. We bounce through a small inline copy of the OpenAI flow
    // because whisperApiTranscribe* in transcript-worker.php is hard-coded
    // to api.openai.com. Same response shape (verbose_json with segments).
    $ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
    if (!$ffmpeg) { $err = 'ffmpeg not available — cannot chunk audio'; return []; }
    $chunkDir = sys_get_temp_dir() . "/transcript_chunks_groq_$jobKey";
    @mkdir($chunkDir, 0700, true);
    $chunkSecs = 600;
    $cmd = escapeshellcmd($ffmpeg) . ' -i ' . escapeshellarg($audioPath)
         . ' -f segment -segment_time ' . $chunkSecs
         . ' -c copy ' . escapeshellarg("$chunkDir/chunk_%03d.mp3") . ' 2>&1';
    shell_exec($cmd);
    $chunks = glob("$chunkDir/chunk_*.mp3");
    sort($chunks);
    if (!$chunks) { $err = 'ffmpeg produced no chunks for Groq'; @rmdir($chunkDir); return []; }

    $allRows = [];
    $failures = [];
    foreach ($chunks as $idx => $chunkPath) {
        if (function_exists('bailIfCancelled')) bailIfCancelled($db, $jobKey, [$chunkDir]);
        if (function_exists('updateJob')) {
            updateJob($db, $jobKey, [
                'job_progress' => 40 + (int)(40 * $idx / max(1, count($chunks))),
                'job_message' => "Groq chunk " . ($idx + 1) . "/" . count($chunks) . "...",
            ]);
        }
        $chunkErr = '';
        $rows = groqApiTranscribeOne($chunkPath, $apiKey, $prompt, $idx * $chunkSecs, $chunkErr, $groqModel);
        if (!$rows && $chunkErr) $failures[] = "chunk " . ($idx + 1) . ": $chunkErr";
        $allRows = array_merge($allRows, $rows);
        @unlink($chunkPath);
    }
    @rmdir($chunkDir);
    if (!$allRows) {
        $err = $failures ? 'all chunks empty: ' . implode('; ', $failures)
                         : 'Groq returned no speech';
    } elseif ($failures) {
        $err = 'partial: ' . implode('; ', $failures);
    }
    return $allRows;
}

function groqApiTranscribeOne(string $audioPath, string $apiKey, string $prompt, int $offsetSecs, ?string &$err, string $groqModel): array {
    $fields = [
        'file' => new CURLFile($audioPath),
        'model' => $groqModel,                 // 'whisper-large-v3' or 'whisper-large-v3-turbo'
        'response_format' => 'verbose_json',
        'timestamp_granularities[]' => 'segment',
    ];
    if ($prompt !== '') $fields['prompt'] = $prompt;
    $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 600,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($code !== 200) {
        $err = "HTTP $code" . ($curlErr ? " ($curlErr)" : '') . ': ' . substr($resp ?? '', 0, 400);
        return [];
    }
    $data = json_decode($resp, true);
    $rows = [];
    foreach ($data['segments'] ?? [] as $seg) {
        $text = trim($seg['text'] ?? '');
        if ($text === '') continue;
        $rows[] = ['segment' => secsToInterval((int)$seg['start'] + $offsetSecs), 'text' => $text];
    }
    if (!$rows && isset($data['text'])) {
        $fallback = trim($data['text']);
        if ($fallback !== '') $rows[] = ['segment' => secsToInterval($offsetSecs), 'text' => $fallback];
    }
    return $rows;
}

/**
 * Deepgram Nova-3: send a URL pointing at the audio file. Response groups
 * recognised speech into paragraphs → sentences; each sentence becomes one
 * row, anchored at the sentence start time. Smart-format + punctuate
 * generate proper case/numbers/punctuation, which is the main advantage
 * over OpenAI Whisper for the YadaYah use case.
 */
function deepgramTranscribe(string $audioUrl, string $apiKey, ?string &$err, array $boostList = []): array {
    $params = http_build_query([
        'model'        => 'nova-3',
        'punctuate'    => 'true',
        'paragraphs'   => 'true',
        'smart_format' => 'true',
        'utterances'   => 'true',
        'language'     => 'en',
    ]);
    // Append keyword-boost entries from the correction dictionary. Nova-3
    // uses the `keyterm` parameter (no per-term weight; previous models'
    // `keywords=word:N` syntax returns HTTP 400). Each phrase is its own
    // query param; rawurlencode handles multi-word phrases ("Yada Yah").
    // The $boost value still influences inclusion order — kept around so
    // higher-confidence terms appear first if Deepgram ever caps quantity.
    foreach ($boostList as $b) {
        $term = trim((string)($b['term'] ?? ''));
        if ($term === '') continue;
        $params .= '&keyterm=' . rawurlencode($term);
    }
    $ch = curl_init("https://api.deepgram.com/v1/listen?$params");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['url' => $audioUrl]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Token ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 900,  // Nova-3 batch jobs can take a few minutes on long audio
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($code !== 200) {
        $err = "HTTP $code" . ($curlErr ? " ($curlErr)" : '') . ': ' . substr($resp ?? '', 0, 400);
        return [];
    }
    $data = json_decode($resp, true);
    $rows = [];
    $alt = $data['results']['channels'][0]['alternatives'][0] ?? null;
    if (!$alt) { $err = 'Deepgram returned no alternatives'; return []; }
    // Prefer paragraphs→sentences (natural phrase rows). Fall back to
    // utterances, then to a single big row with the full transcript.
    if (isset($alt['paragraphs']['paragraphs'])) {
        foreach ($alt['paragraphs']['paragraphs'] as $p) {
            foreach ($p['sentences'] ?? [] as $s) {
                $text = trim((string)($s['text'] ?? ''));
                if ($text === '') continue;
                $rows[] = ['segment' => secsToIntervalFrac((float)($s['start'] ?? 0)), 'text' => $text];
            }
        }
    } elseif (!empty($data['results']['utterances'])) {
        foreach ($data['results']['utterances'] as $u) {
            $text = trim((string)($u['transcript'] ?? ''));
            if ($text === '') continue;
            $rows[] = ['segment' => secsToIntervalFrac((float)($u['start'] ?? 0)), 'text' => $text];
        }
    } elseif (!empty($alt['transcript'])) {
        $rows[] = ['segment' => '00:00:00', 'text' => trim((string)$alt['transcript'])];
    }
    if (!$rows) $err = 'Deepgram returned no speech';
    return $rows;
}

/**
 * AssemblyAI Universal-2: async pipeline.
 *   1. Submit a transcription job pointing at the audio URL
 *   2. Poll the job until status=completed (or error)
 *   3. Map utterances/words → rows
 * Optionally set speaker_labels=true for diarisation — currently off because
 * the row schema doesn't carry a speaker field. Worth revisiting later.
 */
function assemblyaiTranscribe(string $audioUrl, string $apiKey, ?string &$err, array $boostList = []): array {
    // Step 1: submit. word_boost is a flat list of strings; boost_param
    // sets a global strength multiplier ("high" is roughly equivalent to
    // Deepgram's boost=3). Hard-coded to "high" because our boost list is
    // curated from confirmed corrections — we trust each entry.
    $submitBody = [
        'audio_url'    => $audioUrl,
        'speech_model' => 'universal',
        'punctuate'    => true,
        'format_text'  => true,
    ];
    if ($boostList) {
        $submitBody['word_boost']  = array_values(array_unique(array_filter(array_map(
            function ($b) { return trim((string)($b['term'] ?? '')); },
            $boostList
        ))));
        $submitBody['boost_param'] = 'high';
    }
    $ch = curl_init('https://api.assemblyai.com/v2/transcript');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($submitBody),
        CURLOPT_HTTPHEADER => [
            'authorization: ' . $apiKey,
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        $err = "submit HTTP $code: " . substr($resp ?? '', 0, 400);
        return [];
    }
    $submit = json_decode($resp, true);
    $jobId = $submit['id'] ?? '';
    if ($jobId === '') { $err = 'AssemblyAI returned no job id'; return []; }
    // Step 2: poll
    $deadline = time() + 1500;  // 25 min cap
    while (time() < $deadline) {
        sleep(5);
        $ch = curl_init("https://api.assemblyai.com/v2/transcript/$jobId");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['authorization: ' . $apiKey],
            CURLOPT_TIMEOUT => 30,
        ]);
        $pollResp = curl_exec($ch);
        $pollCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($pollCode !== 200) {
            $err = "poll HTTP $pollCode: " . substr($pollResp ?? '', 0, 400);
            return [];
        }
        $poll = json_decode($pollResp, true);
        $status = $poll['status'] ?? '';
        if ($status === 'completed') {
            $rows = [];
            // Prefer utterances → phrase-level rows
            foreach ($poll['utterances'] ?? [] as $u) {
                $text = trim((string)($u['text'] ?? ''));
                if ($text === '') continue;
                $startMs = (int)($u['start'] ?? 0);
                $rows[] = ['segment' => secsToIntervalFrac($startMs / 1000.0), 'text' => $text];
            }
            // No utterances (no diarisation)? Use sentence-level grouping
            // by punctuating the full text on .!?
            if (!$rows && !empty($poll['text'])) {
                $sentences = preg_split('/(?<=[.!?])\s+/u', (string)$poll['text']);
                $words = $poll['words'] ?? [];
                $wordIdx = 0;
                foreach ($sentences as $sentence) {
                    $sentence = trim($sentence);
                    if ($sentence === '') continue;
                    $start = isset($words[$wordIdx]['start']) ? ((int)$words[$wordIdx]['start']) / 1000.0 : 0.0;
                    $wordsInSentence = preg_match_all('/\S+/u', $sentence);
                    $wordIdx += $wordsInSentence;
                    $rows[] = ['segment' => secsToIntervalFrac($start), 'text' => $sentence];
                }
            }
            if (!$rows) $err = 'AssemblyAI completed with no usable text';
            return $rows;
        }
        if ($status === 'error') {
            $err = 'AssemblyAI: ' . ($poll['error'] ?? 'unknown error');
            return [];
        }
        // queued / processing → keep polling
    }
    $err = 'AssemblyAI timed out polling (>25 min)';
    return [];
}

/**
 * ElevenLabs Scribe: synchronous POST with multipart file upload. Returns
 * a flat words[] array with start/end times. Scribe doesn't emit
 * paragraph/utterance grouping, so this function builds phrase rows by
 * splitting on sentence-ending punctuation. ElevenLabs sometimes emits
 * "spacing" entries between words (type != 'word'); skip those.
 */
function elevenlabsScribeTranscribe(string $audioPath, string $apiKey, ?string &$err): array {
    $ch = curl_init('https://api.elevenlabs.io/v1/speech-to-text');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($audioPath),
            'model_id' => 'scribe_v1',
            'timestamps_granularity' => 'word',
        ],
        CURLOPT_HTTPHEADER => ['xi-api-key: ' . $apiKey],
        CURLOPT_TIMEOUT => 1500,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($code !== 200) {
        $err = "HTTP $code" . ($curlErr ? " ($curlErr)" : '') . ': ' . substr($resp ?? '', 0, 400);
        return [];
    }
    $data = json_decode($resp, true);
    $words = $data['words'] ?? [];
    if (!$words) { $err = 'ElevenLabs returned no words'; return []; }
    // Build phrase-level rows by splitting on sentence-ending punctuation
    // attached to a word's text. Each row starts at the first word of its
    // sentence and includes everything up to (and including) the
    // terminator. Carries the original punctuation in place.
    $rows = [];
    $bufWords = [];
    $bufStart = null;
    foreach ($words as $w) {
        if (($w['type'] ?? 'word') !== 'word') continue;
        $text = trim((string)($w['text'] ?? ''));
        if ($text === '') continue;
        if ($bufStart === null) $bufStart = (float)($w['start'] ?? 0);
        $bufWords[] = $text;
        if (preg_match('/[.!?]+$/u', $text)) {
            $rows[] = ['segment' => secsToIntervalFrac($bufStart), 'text' => implode(' ', $bufWords)];
            $bufWords = [];
            $bufStart = null;
        }
    }
    if ($bufWords) {
        $rows[] = ['segment' => secsToIntervalFrac($bufStart ?? 0.0), 'text' => implode(' ', $bufWords)];
    }
    if (!$rows) $err = 'ElevenLabs produced no rows after splitting';
    return $rows;
}

/**
 * Helper: classify a model code into a provider family. The worker uses
 * this to pick which transcribe function to call. Kept here so providers
 * and codes stay co-located.
 */
function providerFamilyForModel(string $code): string {
    if (str_starts_with($code, 'groq-'))        return 'groq';
    if (str_starts_with($code, 'deepgram-'))    return 'deepgram';
    if (str_starts_with($code, 'assemblyai-'))  return 'assemblyai';
    if (str_starts_with($code, 'elevenlabs-'))  return 'elevenlabs';
    if ($code === 'youtube')                    return 'youtube';
    return 'openai';  // whisper-1-*, gpt-4o-*, anything else
}

/**
 * Map a model code to the underlying provider's model id passed to that
 * provider's API. Mostly identity for cloud providers; OpenAI's whisper-1
 * variants share the model id but vary in granularity.
 */
function providerNativeModel(string $code): string {
    switch ($code) {
        case 'groq-whisper-large-v3-turbo': return 'whisper-large-v3-turbo';
        case 'groq-whisper-large-v3':       return 'whisper-large-v3';
        case 'deepgram-nova-3':             return 'nova-3';
        case 'assemblyai-universal-2':      return 'universal';
        case 'elevenlabs-scribe':           return 'scribe_v1';
        default:                            return $code;
    }
}
