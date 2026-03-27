<?php
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();

// GET — list all bans, or check a specific IP
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['check'])) {
        $ip = trim($_GET['check']);
        $stmt = $db->prepare("SELECT ask_ip_ban_key FROM yy_ask_ip_ban WHERE ip_address = ?");
        $stmt->execute([$ip]);
        jsonResponse(['banned' => (bool)$stmt->fetchColumn()]);
    }
    $stmt = $db->query("SELECT * FROM yy_ask_ip_ban ORDER BY ban_dtime DESC");
    jsonResponse(['bans' => $stmt->fetchAll()]);
}

// POST — ban an IP
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $ip = trim($input['ip_address'] ?? '');
    if (!$ip) errorResponse('IP address is required');
    $reason = trim($input['ban_reason'] ?? '');

    // Check if already banned
    $chk = $db->prepare("SELECT ask_ip_ban_key FROM yy_ask_ip_ban WHERE ip_address = ?");
    $chk->execute([$ip]);
    if ($chk->fetchColumn()) {
        jsonResponse(['saved' => true, 'message' => 'IP already banned']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO yy_ask_ip_ban (ip_address, ban_reason) VALUES (?, NULLIF(?, ''))");
    $stmt->execute([$ip, $reason]);
    jsonResponse(['saved' => true]);
}

// DELETE — unban an IP
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $ip = trim($input['ip_address'] ?? '');
    if (!$ip) errorResponse('IP address is required');

    $stmt = $db->prepare("DELETE FROM yy_ask_ip_ban WHERE ip_address = ?");
    $stmt->execute([$ip]);
    jsonResponse(['deleted' => true]);
}

errorResponse('Method not allowed', 405);
