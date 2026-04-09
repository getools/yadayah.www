<?php
/**
 * Shared helper functions for community API endpoints.
 */

/**
 * Sanitize HTML - strip dangerous tags/attributes, allow safe ones.
 */
function sanitizeHtml(string $html): string {
    // Allowed tags and their allowed attributes
    $allowed = [
        'b' => [], 'i' => [], 'em' => [], 'strong' => [], 'u' => [], 's' => [], 'del' => [], 'sub' => [], 'sup' => [],
        'a' => ['href', 'target', 'rel'], 'p' => [], 'br' => [], 'hr' => [], 'span' => ['style'],
        'ul' => [], 'ol' => ['start'], 'li' => [],
        'blockquote' => [],
        'img' => ['src', 'alt', 'width', 'height', 'style'],
        'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [],
        'pre' => ['class'], 'code' => ['class'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'],
        'video' => ['src', 'width', 'height', 'controls', 'poster', 'preload', 'style'],
        'source' => ['src', 'type'],
        'audio' => ['src', 'controls', 'preload'],
        'iframe' => ['src', 'width', 'height', 'frameborder', 'allowfullscreen', 'allow', 'style'],
        'figure' => [], 'figcaption' => [],
        'div' => ['style', 'class'],
    ];

    // Strip all tags except allowed
    $allowedTagStr = '<' . implode('><', array_keys($allowed)) . '>';
    $html = strip_tags($html, $allowedTagStr);

    // Remove event handlers and dangerous attributes via regex
    // Match any tag and filter its attributes
    $html = preg_replace_callback('/<(\w+)((?:\s+[^>]*)?)>/', function($m) use ($allowed) {
        $tag = strtolower($m[1]);
        if (!isset($allowed[$tag])) return '';
        $allowedAttrs = $allowed[$tag];
        if (empty($allowedAttrs) || empty(trim($m[2]))) {
            return '<' . $tag . '>';
        }
        // Parse attributes
        $attrStr = '';
        preg_match_all('/(\w[\w-]*)=(?:"([^"]*)"|\'([^\']*)\'|(\S+))/', $m[2], $attrs, PREG_SET_ORDER);
        foreach ($attrs as $attr) {
            $name = strtolower($attr[1]);
            $val = $attr[2] !== '' ? $attr[2] : ($attr[3] !== '' ? $attr[3] : $attr[4]);
            if (!in_array($name, $allowedAttrs)) continue;
            // Block javascript: URLs
            if (in_array($name, ['href', 'src'])) {
                $cleaned = strtolower(trim(preg_replace('/\s+/', '', $val)));
                if (preg_match('/^javascript:/i', $cleaned)) continue;
                if (preg_match('/^data:/i', $cleaned) && $name === 'href') continue;
                // Restrict iframe src to trusted embed domains
                if ($tag === 'iframe' && $name === 'src') {
                    $host = parse_url($val, PHP_URL_HOST) ?: '';
                    $trustedDomains = ['youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'rumble.com', 'player.vimeo.com', 'open.spotify.com', 'w.soundcloud.com', 'embed.music.apple.com', 'bandcamp.com'];
                    $ok = false;
                    foreach ($trustedDomains as $d) {
                        if ($host === $d || substr($host, -strlen('.' . $d)) === '.' . $d) { $ok = true; break; }
                    }
                    if (!$ok) continue;
                }
            }
            $attrStr .= ' ' . $name . '="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"';
        }
        return '<' . $tag . $attrStr . '>';
    }, $html);

    // Remove on* event handlers that might have slipped through
    $html = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);

    return $html;
}

/**
 * Check if user is banned or muted. Returns error response if banned.
 * Returns 'muted' if muted, null if OK.
 */
function checkBanned(PDO $db, int $userKey): ?string {
    $stmt = $db->prepare("SELECT user_banned_flag, user_muted_flag FROM yy_user WHERE user_key = ?");
    $stmt->execute([$userKey]);
    $user = $stmt->fetch();
    if (!$user) {
        errorResponse('User not found', 404);
    }
    if ($user['user_banned_flag'] === true || $user['user_banned_flag'] === 't') {
        errorResponse('Your account has been suspended', 403);
    }
    if ($user['user_muted_flag'] === true || $user['user_muted_flag'] === 't') {
        return 'muted';
    }
    return null;
}

/**
 * Check text against word filter table. Returns error if blocked word found.
 */
function checkWordFilter(PDO $db, string $text): void {
    $stmt = $db->query("SELECT filter_word, filter_type FROM yy_community_word_filter WHERE filter_active_flag = TRUE");
    $filters = $stmt->fetchAll();
    $lower = mb_strtolower($text);
    foreach ($filters as $f) {
        $word = mb_strtolower($f['filter_word']);
        if (mb_strpos($lower, $word) !== false) {
            if ($f['filter_type'] === 'block') {
                errorResponse('Your post contains a word that is not allowed.');
            }
        }
    }
}

/**
 * Create a notification for a user.
 */
function notifyUser(PDO $db, int $recipientKey, int $actorKey, string $type, string $targetType, int $targetKey, ?int $topicKey, string $text): void {
    if ($recipientKey === $actorKey) return; // Don't notify yourself
    $stmt = $db->prepare("
        INSERT INTO yy_community_notification
            (user_key, actor_key, notification_type, target_type, target_key, topic_key, notification_text)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$recipientKey, $actorKey, $type, $targetType, $targetKey, $topicKey, $text]);
}

/**
 * Send notification email if user preferences allow it.
 */
function sendNotificationEmail(PDO $db, int $recipientKey, string $subject, string $body): bool {
    // Check user email preferences
    $stmt = $db->prepare("SELECT user_email, user_email_notifications FROM yy_user WHERE user_key = ?");
    $stmt->execute([$recipientKey]);
    $user = $stmt->fetch();
    if (!$user || !$user['user_email']) return false;
    if ($user['user_email_notifications'] === false || $user['user_email_notifications'] === 'f') return false;

    $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">'
        . $body
        . '<hr style="border:none;border-top:1px solid #eee;margin:20px 0;">'
        . '<p style="font-size:0.8em;color:#999;">You received this because you have notifications enabled. '
        . '<a href="https://yadayah.com/community#settings">Manage preferences</a></p>'
        . '</div>';

    // Queue the email and trigger async processing
    $stmt = $db->prepare("INSERT INTO yy_email_queue (to_email, subject, body_html) VALUES (?, ?, ?)");
    $stmt->execute([$user['user_email'], $subject, $htmlBody]);

    // Fire-and-forget: kick off the queue processor in the background
    $script = __DIR__ . '/process-email-queue.php';
    if (file_exists($script)) {
        @exec('php ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
    }
    return true;
}

/**
 * Detect @mentions in text and create notifications.
 * Returns array of mentioned user_keys.
 */
function detectMentions(PDO $db, string $text, int $actorKey, string $targetType, int $targetKey, ?int $topicKey): array {
    $mentioned = [];
    if (preg_match_all('/@(\w{3,30})/', $text, $matches)) {
        $handles = array_unique($matches[1]);
        foreach ($handles as $handle) {
            $stmt = $db->prepare("SELECT user_key FROM yy_user WHERE user_handle = ? AND user_active_flag = TRUE");
            $stmt->execute([$handle]);
            $uk = $stmt->fetchColumn();
            if ($uk && (int)$uk !== $actorKey) {
                notifyUser($db, (int)$uk, $actorKey, 'mention', $targetType, $targetKey, $topicKey, '@' . $handle . ' mentioned you');
                $mentioned[] = (int)$uk;
            }
        }
    }
    return $mentioned;
}

/**
 * Check if user has admin or moderator role.
 */
function isModOrAdmin(PDO $db, int $userKey): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM yy_user_role ur
        JOIN yy_role r ON ur.role_key = r.role_key
        WHERE ur.user_key = ? AND r.role_code IN ('admin', 'moderator')
    ");
    $stmt->execute([$userKey]);
    return (bool)$stmt->fetchColumn();
}
