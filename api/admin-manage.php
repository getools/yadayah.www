<?php
/**
 * Manage page API.
 *
 *   GET  /api/admin-manage.php   — AI-server limit settings, show state,
 *                                  effective policy, and the box agent's status
 *   POST /api/admin-manage.php   — save AI-server limit settings (JSON body)
 *
 * Auth: admin session.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_ai_server_limits.php';
requireAuth();
$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $p = aiLimitPolicy($db);
    jsonResponse([
        'settings' => aiLimitSettings($db),
        'active'   => $p['active'],
        'reason'   => $p['reason'],
        'show'     => $p['show'],
        'agent'    => aiLimitAgentStatus(),
        'now'      => time(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) errorResponse('Invalid JSON body');
    $saved = aiLimitSave($db, $input);
    if (!$saved) errorResponse('No recognized settings in request');
    jsonResponse(['saved' => true, 'values' => $saved]);
}

errorResponse('Method not allowed', 405);
