<?php
/**
 * Process pending emails from the queue.
 * Run via cron every 10-30 seconds, or call from CLI.
 * Processes up to 10 emails per run to avoid long-running processes.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/send-mail.php';

$db = getDb();

// Process one email at a time with row locking to prevent duplicates
$sent = 0;
$failed = 0;

for ($i = 0; $i < 10; $i++) {
    $db->beginTransaction();
    $stmt = $db->query("
        SELECT email_queue_key, to_email, subject, body_html, attempts
        FROM yy_email_queue
        WHERE sent_dtime IS NULL AND attempts < 3
        ORDER BY created_dtime ASC
        LIMIT 1
        FOR UPDATE SKIP LOCKED
    ");
    $e = $stmt->fetch();
    if (!$e) { $db->rollBack(); break; }

    $key = $e['email_queue_key'];
    $attempts = (int)$e['attempts'] + 1;

    // Mark as processing immediately to prevent other workers from picking it up
    $db->prepare("UPDATE yy_email_queue SET attempts = ? WHERE email_queue_key = ?")->execute([$attempts, $key]);
    $db->commit();

    $ok = sendMail($db, $e['to_email'], $e['subject'], $e['body_html']);

    if ($ok) {
        $db->prepare("UPDATE yy_email_queue SET sent_dtime = NOW() WHERE email_queue_key = ?")->execute([$key]);
        $sent++;
    } else {
        $db->prepare("UPDATE yy_email_queue SET error = 'Send failed' WHERE email_queue_key = ?")->execute([$key]);
        $failed++;
    }
}

if (php_sapi_name() === 'cli') {
    echo "Processed: $sent sent, $failed failed\n";
}
