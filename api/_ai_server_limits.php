<?php
/**
 * Shared logic for Manage → AI Server transfer limits.
 *
 * Settings live in yy_setting (scope 'ai_server'). The Puget box's
 * yada-ailimit agent polls ai-server-policy.php, which uses aiLimitPolicy()
 * to decide whether limits are active right now:
 *
 *     active = manual_limit OR (limit_during_shows AND show is LIVE)
 *
 * "Show is live" is the same signal that draws the upper-left LIVE popover
 * (api/live-check.php). UPCOMING streams do not count — YouTube keeps a
 * scheduled stream "upcoming" for hours, sometimes after its start time.
 */

const AI_LIMIT_SCOPE = 'ai_server';
const AI_LIMIT_AGENT_FILE = '/tmp/yada_ai_server_agent.json';
const AI_LIMIT_SHOW_FILE  = '/tmp/yada_ai_server_show.json';

// code => [label, sort, rule]. rule: 'bool' or [min, max]; 0 = no limit.
const AI_LIMIT_FIELDS = [
    'limit_during_shows' => ['Limit During Shows',      1, 'bool'],
    'manual_limit'       => ['Manually Limit',          2, 'bool'],
    'upload_limit_mb'    => ['Upload Limit (MB)',       3, [0, 1000000]],
    'download_limit_mb'  => ['Download Limit (MB)',     4, [0, 1000000]],
    'upload_kbps'        => ['Upload Speed (KB/s)',     5, [0, 10000000]],
    'download_kbps'      => ['Download Speed (KB/s)',   6, [0, 10000000]],
];

function aiLimitSettings(PDO $db): array {
    $out = [];
    foreach (AI_LIMIT_FIELDS as $code => $f) $out[$code] = 0;
    $st = $db->prepare("SELECT setting_code, setting_value FROM yy_setting WHERE setting_scope_code = ?");
    $st->execute([AI_LIMIT_SCOPE]);
    foreach ($st->fetchAll() as $r) {
        if (array_key_exists($r['setting_code'], $out)) $out[$r['setting_code']] = (int)$r['setting_value'];
    }
    return $out;
}

/** Save whitelisted fields; inserts a row the first time a code is saved. */
function aiLimitSave(PDO $db, array $input): array {
    $sel = $db->prepare("SELECT setting_key FROM yy_setting WHERE setting_scope_code = ? AND setting_code = ?");
    $upd = $db->prepare("UPDATE yy_setting SET setting_value = ? WHERE setting_key = ?");
    $ins = $db->prepare("INSERT INTO yy_setting (setting_scope_code, setting_group_code, setting_code,
                                                 setting_value_code, setting_sort, setting_value, setting_label)
                         VALUES (?, ?, ?, ?, ?, ?, ?)");
    $saved = [];
    foreach (AI_LIMIT_FIELDS as $code => [$label, $sort, $rule]) {
        if (!array_key_exists($code, $input)) continue;
        $raw = $input[$code];
        if ($rule === 'bool') {
            $val = (!empty($raw) && $raw !== '0') ? 1 : 0;
        } else {
            if ($raw === '' || $raw === null) $raw = 0;
            if (!is_numeric($raw)) errorResponse("Invalid value for $label");
            $val = max($rule[0], min($rule[1], (int)round((float)$raw)));
        }
        $sel->execute([AI_LIMIT_SCOPE, $code]);
        $key = $sel->fetchColumn();
        if ($key !== false) {
            $upd->execute([(string)$val, (int)$key]);
        } else {
            $ins->execute([AI_LIMIT_SCOPE, AI_LIMIT_SCOPE, $code, $rule === 'bool' ? 'bool' : 'int', $sort, (string)$val, $label]);
        }
        $saved[$code] = $val;
    }
    return $saved;
}

/**
 * Current show state from live-check.php (which caches YouTube polls itself).
 * Our own 20s cache keeps the agent's 15s poll from stacking HTTP calls; if
 * live-check is unreachable the last known state is reused for 10 minutes.
 */
function aiLimitShowState(): array {
    $cached = @json_decode((string)@file_get_contents(AI_LIMIT_SHOW_FILE), true);
    $age = is_array($cached) ? time() - (int)($cached['checked'] ?? 0) : PHP_INT_MAX;
    if ($age < 20) return $cached;

    $ch = curl_init('http://localhost/api/live-check.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = ($http === 200) ? json_decode((string)$resp, true) : null;

    if (!is_array($d)) {
        if (is_array($cached) && $age < 600) return $cached;
        return ['live' => false, 'upcoming' => false, 'title' => null, 'checked' => time(), 'error' => 'live-check unavailable'];
    }
    $state = [
        'live'      => !empty($d['live']) && empty($d['upcoming']),
        'upcoming'  => !empty($d['live']) && !empty($d['upcoming']),
        'title'     => $d['title'] ?? null,
        'simulated' => !empty($d['simulated']),
        'checked'   => time(),
    ];
    @file_put_contents(AI_LIMIT_SHOW_FILE, json_encode($state));
    return $state;
}

function aiLimitPolicy(PDO $db): array {
    $s = aiLimitSettings($db);
    $show = aiLimitShowState();
    $reason = null;
    if ($s['manual_limit']) $reason = 'manual';
    elseif ($s['limit_during_shows'] && $show['live']) $reason = 'show';
    return $s + ['active' => $reason !== null, 'reason' => $reason, 'show' => $show];
}

function aiLimitAgentStatus(): ?array {
    $d = @json_decode((string)@file_get_contents(AI_LIMIT_AGENT_FILE), true);
    if (!is_array($d)) return null;
    $d['age_secs'] = time() - (int)($d['received'] ?? 0);
    return $d;
}
