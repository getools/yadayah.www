<?php
/**
 * Puget AI job queue — admin toolbar widget (js/admin-ai-queue.js).
 *
 *   GET  ?limit=N            → aiqSnapshot(): running jobs, the queue in pick
 *                              order (first N, default 300; 0 = totals only)
 *                              with est. duration / start / finish, per-class
 *                              totals and the job classes
 *   POST {action:'save_classes', classes:[{code, priority?, exclusive?, est_secs?}]}
 *                            → updates only the fields supplied
 *
 * Ordering and the start rule live in _ai_queue.php.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_gpu_gate.php';

$user = requireAuth();
$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($input['action'] ?? '') !== 'save_classes') errorResponse('unknown action');
    $known = aiqClasses($db);
    $n = 0;
    $db->beginTransaction();
    foreach ((array)($input['classes'] ?? []) as $c) {
        $code = (string)($c['code'] ?? '');
        if (!isset($known[$code])) continue;
        $set = []; $params = [];
        if (array_key_exists('priority', $c)) {
            $p = (int)$c['priority'];
            if ($p < 0 || $p > 1000) { $db->rollBack(); errorResponse("priority for $code must be 0-1000"); }
            $set[] = 'ai_job_class_priority = ?'; $params[] = $p;
        }
        if (array_key_exists('exclusive', $c)) { $set[] = 'ai_job_class_exclusive_flag = ?'; $params[] = (int)(bool)$c['exclusive']; }
        if (array_key_exists('est_secs', $c)) {
            $e = (int)$c['est_secs'];
            if ($e < 1 || $e > 86400) { $db->rollBack(); errorResponse("estimate for $code must be 1-86400 seconds"); }
            $set[] = 'ai_job_class_est_secs = ?'; $params[] = $e;
        }
        if (!$set) continue;
        $params[] = (int)$user['user_key'];
        $params[] = $code;
        $db->prepare('UPDATE yy_ai_job_class SET ' . implode(', ', $set) . ',
                             ai_job_class_revision_dtime = now(), ai_job_class_revision_user_key = ?,
                             ai_job_class_revision_num = ai_job_class_revision_num + 1
                       WHERE ai_job_class_code = ?')->execute($params);
        $n++;
    }
    $db->commit();
    // Wake the widgets: any status-less ping on the channel triggers a refresh.
    $db->query("SELECT pg_notify('ai_queue', '{\"key\":null,\"status\":\"classes\"}')");
    jsonResponse(['ok' => true, 'updated' => $n, 'classes' => array_values(aiqClasses($db, true))]);
}

$limit = isset($_GET['limit']) ? max(0, min(2000, (int)$_GET['limit'])) : 300;
jsonResponse(aiqSnapshot($db, $limit));
