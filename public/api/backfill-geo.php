<?php
/**
 * CLI-only: Backfill ip_city/ip_country for existing ask sessions.
 * Uses ip-api.com (free, 45 req/min). Adds 1.5s delay between requests.
 * Usage: php backfill-geo.php
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
$db = getDb();

$stmt = $db->query("SELECT ask_session_key, ip_address FROM yy_ask_session WHERE ip_region IS NULL AND ip_address IS NOT NULL AND ip_address NOT IN ('127.0.0.1', '::1') ORDER BY ask_session_key");
$rows = $stmt->fetchAll();

echo count($rows) . " sessions to backfill\n";

$updated = 0;
$failed = 0;
$upd = $db->prepare("UPDATE yy_ask_session SET ip_city = ?, ip_region = ?, ip_country = ? WHERE ask_session_key = ?");

foreach ($rows as $i => $row) {
    $ip = $row['ip_address'];
    $key = $row['ask_session_key'];

    $json = @file_get_contents("http://ip-api.com/json/" . urlencode($ip) . "?fields=status,city,regionName,countryCode", false, stream_context_create(['http' => ['timeout' => 5]]));
    if ($json === false) {
        echo "  [$key] $ip - HTTP failed\n";
        $failed++;
        usleep(2000000);
        continue;
    }

    $geo = json_decode($json, true);
    if (($geo['status'] ?? '') === 'success') {
        $upd->execute([$geo['city'] ?? null, $geo['regionName'] ?? null, $geo['countryCode'] ?? null, $key]);
        echo "  [$key] $ip -> " . ($geo['city'] ?? '') . ", " . ($geo['regionName'] ?? '') . ", " . ($geo['countryCode'] ?? '') . "\n";
        $updated++;
    } else {
        echo "  [$key] $ip - lookup failed\n";
        $failed++;
    }

    // Rate limit: ip-api.com allows 45/min, so ~1.4s between requests
    usleep(1500000);
}

echo "\nDone! Updated: $updated, Failed: $failed\n";
