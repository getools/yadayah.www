<?php
/**
 * Transfer-limit policy for the Puget AI server.
 *
 *   POST /api/ai-server-policy.php   (header X-Agent-Token)
 *        body     = the agent's status (what it has applied, WAN rates, blocks)
 *        response = effective policy — see aiLimitPolicy()
 *
 * Polled every ~15s by /usr/local/sbin/yada-ailimit.py on the box. The token
 * is AI_SERVER_AGENT_TOKEN in public/.env (same value in /etc/yada-ailimit.env
 * on the box).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_ai_server_limits.php';

$token = readEnv('AI_SERVER_AGENT_TOKEN');
if ($token === '' || !hash_equals($token, (string)($_SERVER['HTTP_X_AGENT_TOKEN'] ?? ''))) {
    errorResponse('Forbidden', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') errorResponse('Method not allowed', 405);

$status = json_decode(file_get_contents('php://input'), true);
if (is_array($status)) {
    $status['received'] = time();
    @file_put_contents(AI_LIMIT_AGENT_FILE, json_encode($status), LOCK_EX);
}

header('Cache-Control: no-store');
$p = aiLimitPolicy(getDb());
jsonResponse([
    'active'            => $p['active'],
    'reason'            => $p['reason'],
    'upload_limit_mb'   => $p['upload_limit_mb'],
    'download_limit_mb' => $p['download_limit_mb'],
    'upload_kbps'       => $p['upload_kbps'],
    'download_kbps'     => $p['download_kbps'],
]);
