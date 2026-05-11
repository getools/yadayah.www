<?php
// CLI wrapper for buildWhisperWordJoin() — see transcript-helpers.php for
// the full algorithm. Useful for one-off manual rebuilds; the transcript
// worker calls the same helper inline whenever a successful Transcribe
// Audio run leaves both whisper-1-word and youtube populated.
//
// Usage (in the web container):
//   docker exec yada-www-web-1 php /var/www/html/api/build_whisper_word_join.php <feed_item_key> [reference_model]
//   reference_model defaults to 'youtube'.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/transcript-helpers.php';

$itemKey  = (int)($argv[1] ?? 0);
$refModel = trim($argv[2] ?? 'youtube');
if (!$itemKey) {
    fwrite(STDERR, "usage: php build_whisper_word_join.php <feed_item_key> [reference_model=youtube]\n");
    exit(1);
}

$db = getDb();
$count = buildWhisperWordJoin($db, $itemKey, $refModel);
if ($count === 0) {
    fwrite(STDERR, "no rows written — either source data is missing for item $itemKey or the build failed (check error_log).\n");
    exit(2);
}
echo "wrote $count whisper-1-word-join rows (ref=$refModel)\n";
