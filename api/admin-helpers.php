<?php
/**
 * Helpers list — used by admin-helper.html to render the helper cards.
 *
 * GET → JSON array of active helpers, joined to the bound report (if any)
 *       and a quick row-count for the report's queue. Queue count is
 *       computed by running the report's SQL with default parameter
 *       values; if the SQL fails we return null for the count rather
 *       than blocking the page render.
 */
require_once __DIR__ . '/config.php';
requireAuth();

$db = getDb();

$rows = $db->query("
    SELECT h.helper_key,
           h.helper_code,
           h.helper_label,
           h.helper_sort,
           h.helper_report_code,
           r.report_key,
           r.report_label
      FROM yy_helper h
      LEFT JOIN yy_report r ON r.report_code = h.helper_report_code
     WHERE h.helper_active_flag = TRUE
     ORDER BY h.helper_sort, h.helper_label
")->fetchAll();

// For each helper with a bound report, compute the queue size by running
// the report's query with default parameter values substituted in.
foreach ($rows as &$row) {
    $row['queue_count'] = null;
    if (!$row['report_key']) continue;
    try {
        $rq = $db->prepare("SELECT report_query FROM yy_report WHERE report_key = ?");
        $rq->execute([(int)$row['report_key']]);
        $sql = (string)$rq->fetchColumn();
        if ($sql === '') continue;

        // Pull default param values for substitution.
        $pq = $db->prepare("
            SELECT report_param_code, report_param_default, report_param_datatype
              FROM yy_report_param
             WHERE report_key = ?
        ");
        $pq->execute([(int)$row['report_key']]);
        $params = $pq->fetchAll();

        // Substitute :code → coerced-default for each param. We don't run
        // the original SQL — we wrap it as a sub-query and SELECT COUNT(*)
        // so we get a single integer regardless of the report's row shape.
        // Strip trailing ; if any, and any LIMIT clause that would mask the
        // true queue size.
        $bodySql = preg_replace('/;\s*$/', '', $sql);
        $bodySql = preg_replace('/\bLIMIT\s+\S+\s*$/i', '', $bodySql);

        foreach ($params as $p) {
            $code = $p['report_param_code'];
            $val  = $p['report_param_default'];
            $dt   = strtolower($p['report_param_datatype']);
            // Coerce default to a SQL literal. Empty default → NULL.
            if ($val === null || $val === '') {
                $lit = 'NULL';
            } elseif (in_array($dt, ['int','decimal'])) {
                $lit = is_numeric($val) ? $val : 'NULL';
            } elseif ($dt === 'boolean') {
                $lit = (strtolower($val) === 'true' || $val === '1') ? 'TRUE' : 'FALSE';
            } else {
                $lit = $db->quote($val);
            }
            $bodySql = preg_replace('/:' . preg_quote($code, '/') . '\b/', $lit, $bodySql);
        }

        // Anything left looking like ":word" is an unbound param — the
        // count would error out. Fall back to NULL so the card just hides
        // the badge instead of blowing up the page.
        if (preg_match('/:\w+/', $bodySql)) {
            continue;
        }

        $cq = $db->query("SELECT COUNT(*) FROM ($bodySql) AS _q");
        $row['queue_count'] = (int)$cq->fetchColumn();
    } catch (Throwable $e) {
        // Swallow — count is best-effort.
        $row['queue_count'] = null;
    }
}

jsonResponse(['helpers' => $rows]);
