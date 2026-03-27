<?php
/**
 * SMTP email sender using Gmail via fsockopen.
 * No external libraries needed.
 *
 * Usage: sendMail($db, $to, $subject, $htmlBody)
 */

function sendMail(PDO $db, string $to, string $subject, string $htmlBody): bool {
    // Load SMTP settings from DB
    $stmt = $db->query("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = 'app' AND setting_group_code = 'smtp'");
    $cfg = [];
    foreach ($stmt->fetchAll() as $r) $cfg[$r['setting_code']] = $r['setting_value'];

    $host = $cfg['smtp-host'] ?? 'smtp.gmail.com';
    $port = (int)($cfg['smtp-port'] ?? 587);
    $user = $cfg['smtp-user'] ?? '';
    $pass = $cfg['smtp-pass'] ?? '';
    $fromEmail = $cfg['smtp-from-email'] ?? $user;
    $fromName = $cfg['smtp-from-name'] ?? 'Yada Yahowah';

    if (!$user || !$pass) return false;

    // Connect
    $sock = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$sock) return false;

    $resp = function() use ($sock) { return fgets($sock, 512); };
    $send = function(string $cmd) use ($sock, $resp) {
        fwrite($sock, $cmd . "\r\n");
        return $resp();
    };

    $resp(); // greeting
    $send("EHLO yadayah.com");
    // Read all EHLO lines
    while (true) {
        $line = $resp();
        if (!$line || substr($line, 3, 1) === ' ') break;
    }

    $send("STARTTLS");
    // Enable TLS
    stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);

    $send("EHLO yadayah.com");
    while (true) {
        $line = $resp();
        if (!$line || substr($line, 3, 1) === ' ') break;
    }

    // Auth
    $send("AUTH LOGIN");
    $send(base64_encode($user));
    $send(base64_encode($pass));

    $send("MAIL FROM:<{$fromEmail}>");
    $send("RCPT TO:<{$to}>");
    $send("DATA");

    // Build message
    $boundary = md5(uniqid());
    $headers = "From: {$fromName} <{$fromEmail}>\r\n"
        . "To: {$to}\r\n"
        . "Subject: {$subject}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n";

    fwrite($sock, $headers . "\r\n" . $htmlBody . "\r\n.\r\n");
    $result = $resp();

    $send("QUIT");
    fclose($sock);

    return strpos($result, '250') === 0;
}
