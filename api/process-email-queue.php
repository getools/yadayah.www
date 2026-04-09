<?php
/**
 * Process pending emails from the queue.
 * Run via cron every 10-30 seconds, or call from CLI.
 * Processes up to 10 emails per run to avoid long-running processes.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/send-mail.php';

$db = getDb();

// Fetch pending emails (max 10 per run, oldest first, max 3 attempts)
$stmt = $db->query("
    SELECT email_queue_key, to_email, subject, body_html, attempts
    FROM yy_email_queue
    WHERE sent_dtime IS NULL AND attempts < 3
    ORDER BY created_dtime ASC
    LIMIT 10
");
$emails = $stmt->fetchAll();

if (!$emails) exit;

$sent = 0;
$failed = 0;

foreach ($emails as $e) {
    $key = $e['email_queue_key'];
    $attempts = (int)$e['attempts'] + 1;

    $ok = sendMail($db, $e['to_email'], $e['subject'], $e['body_html']);

    if ($ok) {
        $db->prepare("UPDATE yy_email_queue SET sent_dtime = NOW(), attempts = ? WHERE email_queue_key = ?")
           ->execute([$attempts, $key]);
        $sent++;
    } else {
        $db->prepare("UPDATE yy_email_queue SET attempts = ?, error = 'Send failed' WHERE email_queue_key = ?")
           ->execute([$attempts, $key]);
        $failed++;
    }
}

if (php_sapi_name() === 'cli') {
    echo "Processed: $sent sent, $failed failed\n";
}
