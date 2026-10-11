<?php
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();

// GET ?session=N — single session with Q&A logs
if (isset($_GET['session'])) {
    $key = intval($_GET['session']);
    $stmt = $db->prepare('SELECT s.*, u.user_display_name AS ask_user_name, u.user_email AS ask_user_email FROM yy_ask_session s LEFT JOIN yy_user u ON s.user_key = u.user_key WHERE s.ask_session_key = ?');
    $stmt->execute([$key]);
    $session = $stmt->fetch();
    if (!$session) errorResponse('Session not found', 404);

    $stmt = $db->prepare('SELECT * FROM yy_ask_session_log WHERE ask_session_key = ? ORDER BY ask_log_dtime ASC');
    $stmt->execute([$key]);
    $logs = $stmt->fetchAll();

    jsonResponse(['session' => $session, 'logs' => $logs]);
}

// PUT — update admin note on a session log entry
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $logKey = (int)($data['ask_session_log_key'] ?? 0);
    $note = $data['admin_note'] ?? '';
    if (!$logKey) errorResponse('ask_session_log_key required');
    $db->prepare("UPDATE yy_ask_session_log SET ask_log_admin_note = ?, ask_log_admin_note_dtime = NOW() WHERE ask_session_log_key = ?")
       ->execute([$note, $logKey]);
    jsonResponse(['saved' => true]);
}

// GET — list sessions (paginated; sortable via sorts=col:dir,…; per-column f_* filters)
$page  = max(1, intval($_GET['page'] ?? 1));
$limit = min(100, max(10, intval($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

$total = $db->query('SELECT COUNT(*) FROM yy_ask_session')->fetchColumn();

// "Review" = each question needs an admin note AND a rating, so a session has
// 2 × questions review items; review_pending = how many are still missing.
$base = <<<SQL
    SELECT s.*,
           u.user_display_name AS ask_user_name, u.user_email AS ask_user_email,
           concat_ws(', ', NULLIF(s.ip_city, ''), NULLIF(s.ip_region, ''), NULLIF(s.ip_country, '')) AS location,
           COALESCE(SUM(l.ask_log_prompt_tokens), 0) AS total_prompt_tokens,
           COALESCE(SUM(l.ask_log_completion_tokens), 0) AS total_completion_tokens,
           COALESCE(SUM(l.ask_log_prompt_tokens), 0) + COALESCE(SUM(l.ask_log_completion_tokens), 0) AS total_tokens,
           COALESCE(SUM(l.ask_log_duration_ms), 0) AS total_duration_ms,
           (SELECT ask_log_model FROM yy_ask_session_log WHERE ask_session_key = s.ask_session_key ORDER BY ask_log_dtime DESC LIMIT 1) AS model,
           COUNT(l.ask_session_log_key) AS total_questions,
           COUNT(l.ask_session_log_key) FILTER (WHERE l.ask_log_admin_note IS NULL OR l.ask_log_admin_note = '') AS pending_notes,
           COUNT(l.ask_session_log_key) FILTER (WHERE l.ask_log_admin_note IS NOT NULL AND l.ask_log_admin_note <> '') AS notes_done,
           COUNT(l.ask_log_rating) AS ratings_done
    FROM yy_ask_session s
    LEFT JOIN yy_ask_session_log l ON l.ask_session_key = s.ask_session_key
    LEFT JOIN yy_user u ON s.user_key = u.user_key
    GROUP BY s.ask_session_key, u.user_display_name, u.user_email
SQL;
$outer = <<<SQL
    SELECT a.*,
           a.total_questions * 2 - a.notes_done - a.ratings_done AS review_pending,
           CASE WHEN a.total_questions * 2 - a.notes_done - a.ratings_done = 0 THEN 'all'
                WHEN a.notes_done + a.ratings_done = 0 THEN 'none'
                ELSE 'some' END AS review_state
    FROM a
SQL;

// ── Filters ──
$where = [];
$params = [];
$likeArg = function ($v) { return '%' . addcslashes($v, '%_\\') . '%'; };
$textFilters = [
    'f_date'     => "to_char(ask_session_dtime, 'YYYY-MM-DD HH24:MI')",
    'f_user'     => "concat_ws(' ', ask_user_name, ask_user_email, CASE WHEN user_key IS NOT NULL THEN 'User #' || user_key END)",
    'f_ip'       => "COALESCE(ip_address, '')",
    'f_location' => "location",
];
foreach ($textFilters as $param => $expr) {
    $v = trim((string)($_GET[$param] ?? ''));
    if ($v === '') continue;
    $where[] = "$expr ILIKE ?";
    $params[] = $likeArg($v);
}
$model = trim((string)($_GET['f_model'] ?? ''));
if ($model !== '') {
    if ($model === '(none)') $where[] = "(model IS NULL OR model = '')";
    else { $where[] = 'model = ?'; $params[] = $model; }
}
// Numeric filters accept "5", ">5", ">=5", "<5", "<=5", "=5" or a range "3-8".
$numFilters = [
    'f_qs'       => 'total_questions',
    'f_tokens'   => 'total_tokens',
    'f_duration' => '(total_duration_ms / 1000.0)',   // seconds
];
foreach ($numFilters as $param => $expr) {
    $v = str_replace([',', ' '], '', (string)($_GET[$param] ?? ''));
    if ($v === '') continue;
    if (preg_match('/^(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/', $v, $m)) {
        $where[] = "$expr BETWEEN ? AND ?";
        $params[] = (float)$m[1]; $params[] = (float)$m[2];
    } elseif (preg_match('/^(<=|>=|<|>|=)?(\d+(?:\.\d+)?)$/', $v, $m)) {
        $where[] = "$expr " . ($m[1] ?: '=') . ' ?';
        $params[] = (float)$m[2];
    } else {
        errorResponse("Invalid number filter for $param: use 5, >5, <=10 or 3-8");
    }
}
$review = (string)($_GET['f_review'] ?? '');
if (in_array($review, ['none', 'some', 'all'], true)) { $where[] = 'review_state = ?'; $params[] = $review; }
elseif ($review === 'pending') $where[] = "review_state <> 'all'";
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Sorting ── sorts=col:dir,… (first = primary); "review" sorts by the number
// of pending notes+ratings, then newest first.
$sortMap = [
    'date'     => ['ask_session_dtime'],
    'user'     => ["COALESCE(NULLIF(ask_user_name, ''), CASE WHEN user_key IS NOT NULL THEN 'User #' || user_key END)"],
    'ip'       => ['ip_address'],
    'location' => ["NULLIF(location, '')"],
    'model'    => ["NULLIF(model, '')"],
    'qs'       => ['total_questions'],
    'tokens'   => ['total_tokens'],
    'duration' => ['total_duration_ms'],
    'review'   => ['review_pending', 'ask_session_dtime DESC'],
];
$order = [];
foreach (explode(',', (string)($_GET['sorts'] ?? '')) as $part) {
    $bits = explode(':', trim($part));
    $col = $bits[0] ?? '';
    if (!isset($sortMap[$col])) continue;
    $dir = strtolower($bits[1] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    foreach ($sortMap[$col] as $j => $expr) {
        $order[] = $j === 0 ? "$expr $dir NULLS LAST" : $expr;
    }
}
$order[] = 'ask_session_dtime DESC';
$order[] = 'ask_session_key DESC';
$orderSql = implode(', ', $order);

$cnt = $db->prepare("WITH a AS ($base), b AS ($outer) SELECT COUNT(*) FROM b $whereSql");
$cnt->execute($params);
$filtered = (int)$cnt->fetchColumn();

$stmt = $db->prepare("WITH a AS ($base), b AS ($outer) SELECT * FROM b $whereSql ORDER BY $orderSql LIMIT ? OFFSET ?");
$i = 1;
foreach ($params as $p) $stmt->bindValue($i++, $p, PDO::PARAM_STR);
$stmt->bindValue($i++, $limit, PDO::PARAM_INT);
$stmt->bindValue($i++, $offset, PDO::PARAM_INT);
$stmt->execute();
$sessions = $stmt->fetchAll();

// Distinct models (latest model per session) for the Model filter dropdown.
$models = $db->query(<<<SQL
    SELECT DISTINCT m.model FROM yy_ask_session s
    CROSS JOIN LATERAL (SELECT ask_log_model AS model FROM yy_ask_session_log
                        WHERE ask_session_key = s.ask_session_key ORDER BY ask_log_dtime DESC LIMIT 1) m
    WHERE m.model IS NOT NULL AND m.model <> '' ORDER BY 1
SQL)->fetchAll(PDO::FETCH_COLUMN);

jsonResponse([
    'sessions'    => $sessions,
    'page'        => $page,
    'limit'       => $limit,
    'total'       => intval($total),
    'filtered'    => $filtered,
    'total_pages' => max(1, intval(ceil($filtered / $limit))),
    'models'      => $models,
]);
