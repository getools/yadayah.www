<?php
/**
 * Community reports API.
 * POST: file a report on a topic or reply.
 * GET: list pending reports (moderators/admins only).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/community-helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$userKey = $_SESSION['user_key'] ?? null;
if (!$userKey) errorResponse('Login required', 401);

$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $targetType = $data['target_type'] ?? '';
    $targetKey = (int)($data['target_key'] ?? 0);
    $reason = trim($data['report_reason'] ?? '');
    $detail = trim($data['report_detail'] ?? '');

    if (!in_array($targetType, ['topic', 'reply'])) errorResponse('target_type must be topic or reply');
    if (!$targetKey) errorResponse('target_key is required');
    if (!$reason) errorResponse('report_reason is required');

    // Check for duplicate report
    $stmt = $db->prepare("
        SELECT 1 FROM yy_community_report
        WHERE user_key = ? AND target_type = ? AND target_key = ? AND report_status = 'pending'
    ");
    $stmt->execute([$userKey, $targetType, $targetKey]);
    if ($stmt->fetchColumn()) errorResponse('You have already reported this content');

    $stmt = $db->prepare("
        INSERT INTO yy_community_report (user_key, target_type, target_key, report_reason, report_detail)
        VALUES (?, ?, ?, ?, ?)
        RETURNING report_key
    ");
    $stmt->execute([$userKey, $targetType, $targetKey, $reason, $detail]);

    jsonResponse(['success' => true, 'report_key' => $stmt->fetchColumn()]);
}

if ($method === 'GET') {
    // Moderators/admins only
    if (!isModOrAdmin($db, $userKey)) errorResponse('Not authorized', 403);

    $status = $_GET['status'] ?? 'pending';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 30;
    $offset = ($page - 1) * $limit;

    $stmt = $db->prepare("
        SELECT r.report_key, r.target_type, r.target_key, r.report_reason, r.report_detail,
               r.report_status, r.report_dtime,
               u.user_name_display AS reporter_name, u.user_avatar AS reporter_avatar
        FROM yy_community_report r
        LEFT JOIN yy_user u ON r.user_key = u.user_key
        WHERE r.report_status = ?
        ORDER BY r.report_dtime DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$status, $limit, $offset]);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM yy_community_report WHERE report_status = ?");
    $countStmt->execute([$status]);

    jsonResponse([
        'reports' => $stmt->fetchAll(),
        'total' => (int)$countStmt->fetchColumn(),
        'page' => $page,
    ]);
}

errorResponse('Method not allowed', 405);
