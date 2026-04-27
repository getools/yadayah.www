<?php
/**
 * Process pending emails from the queue.
 * Run via cron every 10-30 seconds, or call from CLI.
 * Processes up to 10 emails per run to avoid long-running processes.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/send-mail.php';

$db = getDb();

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

    // Mark as sent AND increment attempts inside the transaction
    // This prevents any other worker from picking it up
    $db->prepare("UPDATE yy_email_queue SET sent_dtime = NOW(), attempts = ? WHERE email_queue_key = ?")->execute([$attempts, $key]);
    $db->commit();

    // Now actually send — row is already marked as sent so no other worker will touch it
    $ok = sendMail($db, $e['to_email'], $e['subject'], $e['body_html']);

    if (!$ok) {
        // Send failed — clear sent_dtime so it can be retried (up to 3 attempts)
        $db->prepare("UPDATE yy_email_queue SET sent_dtime = NULL, error = 'Send failed' WHERE email_queue_key = ?")->execute([$key]);
        $failed++;
    } else {
        $sent++;
    }
}

if (php_sapi_name() === 'cli') {
    echo "Processed: $sent sent, $failed failed\n";
}
