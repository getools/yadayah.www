<?php
/**
 * Parse VTT transcript files into yy_transcript_20260503 table.
 * Run via CLI: php parse-transcripts.php
 * Expects files in ../transcripts/*.vtt with naming pattern: yyyyMM-Title.vtt
 */
require_once __DIR__ . '/config.php';

$TRANSCRIPTS_DIR = __DIR__ . '/../transcripts';
$CHUNK_WORDS = 500;

$db = getDb();

// Clear existing data for re-import
$db->exec('TRUNCATE yy_transcript_20260503 RESTART IDENTITY');
echo "Cleared existing transcript data.\n";

$files = glob("$TRANSCRIPTS_DIR/*.vtt");
sort($files);
echo "Found " . count($files) . " VTT files.\n\n";

$totalChunks = 0;

foreach ($files as $filepath) {
    $filename = basename($filepath);

    // Extract yyyyMM prefix and title
    $yearmonth = null;
    $title = $filename;
    if (preg_match('/^(\d{6})-(.+)\.vtt$/i', $filename, $m)) {
        $yearmonth = $m[1];
        $title = str_replace('_', "'", $m[2]);
    } elseif (preg_match('/^(.+)\.vtt$/i', $filename, $m)) {
        $title = str_replace('_', "'", $m[1]);
    }

    // Parse VTT cues
    $content = file_get_contents($filepath);
    $lines = explode("\n", str_replace("\r", "", $content));

    $cues = [];
    $currentTime = null;
    $currentEndTime = null;
    $currentText = [];

    foreach ($lines as $line) {
        $line = trim($line);

        // Timestamp line
        if (preg_match('/^(\d{2}:\d{2}:\d{2}\.\d{3})\s+-->\s+(\d{2}:\d{2}:\d{2}\.\d{3})/', $line, $tm)) {
            // Save previous cue
            if ($currentTime !== null && count($currentText) > 0) {
                $cues[] = ['start' => $currentTime, 'end' => $currentEndTime, 'text' => implode(' ', $currentText)];
            }
            $currentTime = $tm[1];
            $currentEndTime = $tm[2];
            $currentText = [];
        } elseif ($line === '' || $line === 'WEBVTT' || preg_match('/^\d+$/', $line)) {
            // Skip blank lines, WEBVTT header, cue numbers
            continue;
        } elseif ($currentTime !== null) {
            // Strip HTML tags from cue text
            $currentText[] = strip_tags($line);
        }
    }
    // Last cue
    if ($currentTime !== null && count($currentText) > 0) {
        $cues[] = ['start' => $currentTime, 'end' => $currentEndTime, 'text' => implode(' ', $currentText)];
    }

    if (empty($cues)) {
        echo "  SKIP (no cues): $filename\n";
        continue;
    }

    // Chunk cues into ~500-word segments
    $chunks = [];
    $chunkText = [];
    $chunkWordCount = 0;
    $chunkStart = $cues[0]['start'];
    $chunkEnd = $cues[0]['end'];

    foreach ($cues as $cue) {
        $words = str_word_count($cue['text']);
        $chunkText[] = $cue['text'];
        $chunkWordCount += $words;
        $chunkEnd = $cue['end'];

        if ($chunkWordCount >= $CHUNK_WORDS) {
            $chunks[] = ['start' => $chunkStart, 'end' => $chunkEnd, 'text' => implode(' ', $chunkText)];
            $chunkText = [];
            $chunkWordCount = 0;
            $chunkStart = $chunkEnd;
        }
    }
    // Remaining text
    if (count($chunkText) > 0) {
        $chunks[] = ['start' => $chunkStart, 'end' => $chunkEnd, 'text' => implode(' ', $chunkText)];
    }

    // Insert chunks
    $stmt = $db->prepare("
        INSERT INTO yy_transcript_20260503 (transcript_source, transcript_title, transcript_yearmonth, transcript_chunk_num, transcript_start_time, transcript_end_time, transcript_text, transcript_tsv)
        VALUES (?, ?, ?, ?, ?, ?, ?, to_tsvector('english', ?))
    ");

    foreach ($chunks as $i => $chunk) {
        $stmt->execute([
            $filename,
            $title,
            $yearmonth,
            $i + 1,
            $chunk['start'],
            $chunk['end'],
            $chunk['text'],
            $chunk['text'],
        ]);
    }

    $totalChunks += count($chunks);
    echo "  $filename: " . count($cues) . " cues -> " . count($chunks) . " chunks\n";
}

echo "\nDone. Total: " . count($files) . " files, $totalChunks chunks.\n";
