<?php
// src/Notifications/handlers.php - Notifications module: the in-app
// notification list/count/mark-seen endpoints, plus push-subscription
// registration (getVapidPublicKey/savePushSubscription/
// deletePushSubscription) -- grouped together since they're both about
// how a user finds out something happened, in-app vs. push.
//
// getUnseenNotificationCount() is also called from api.php's
// pushNotification() (via createNotification(), used by Posts/Comments/
// Follows for like/comment/follow notifications) so a push payload's count
// matches the notifications page's own count exactly. That's fine: this
// file loads unconditionally via Composer's autoload.files, before any
// handler runs, so the function is globally available regardless of which
// file defines it -- same as every other moved module.
//
// Loaded via Composer's "files" autoload (see composer.json), same as the
// other modules - global-namespace functions, not classes.
//
// Depends on shared Core helpers still defined in api.php: bad(), good(),
// respond().

function handle_getNotifications($pdo, $user) {
    $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
    $limit = 25;
    // Every other type can't target yourself in the first place (you can't
    // follow/like/comment-notify yourself), but a mention can - self-mentions
    // are meant to notify like any other, so they're exempted here rather
    // than excluded by the general actor_id != recipient_id noise filter.
    $stmt = $pdo->prepare('SELECT n.id, n.recipient_id, n.actor_id, COALESCE(u.email, n.actor_email) AS actor_email, n.type, n.post_id, n.created_at FROM notifications n LEFT JOIN users u ON n.actor_id = u.id WHERE n.recipient_id = ? AND (n.actor_id != ? OR n.type = \'mention\') ORDER BY n.created_at DESC LIMIT ? OFFSET ?');
    $stmt->execute([$user['sub'], $user['sub'], $limit, $offset]);
    respond(good(['notifications' => $stmt->fetchAll(PDO::FETCH_ASSOC)]));
}

// Shared with pushNotification() so a push payload's embedded count is
// computed the exact same way the notifications page's own count is -- the
// service worker re-asserts this value against the OS badge on every
// notification interaction it sees, so it has to match.
function getUnseenNotificationCount($pdo, $userId) {
    $stmt = $pdo->prepare('SELECT last_notifications_seen_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $lastSeen = $stmt->fetchColumn();

    if (!$lastSeen) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND (actor_id != ? OR type = \'mention\')');
        $stmt->execute([$userId, $userId]);
    } else {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND (actor_id != ? OR type = \'mention\') AND created_at > ?');
        $stmt->execute([$userId, $userId, $lastSeen]);
    }

    return (int)$stmt->fetchColumn();
}

function handle_getUnseenNotificationCount($pdo, $user) {
    respond(good(['count' => getUnseenNotificationCount($pdo, $user['sub'])]));
}

function handle_markNotificationsSeen($pdo, $user) {
    $stmt = $pdo->prepare('UPDATE users SET last_notifications_seen_at = ? WHERE id = ?');
    $stmt->execute([date('Y-m-d H:i:s'), $user['sub']]);
    respond(good(['message' => 'Notifications marked as seen']));
}

function handle_getVapidPublicKey($pdo, $user) {
    global $CONFIG;
    respond(good(['key' => $CONFIG['vapid_public'] ?? '']));
}

function handle_savePushSubscription($pdo, $user) {
    $endpoint = trim($_POST['endpoint'] ?? '');
    $p256dh = trim($_POST['p256dh'] ?? '');
    $auth = trim($_POST['auth'] ?? '');
    if (!$endpoint || !$p256dh || !$auth) bad('Missing subscription details', 400);
    if (!filter_var($endpoint, FILTER_VALIDATE_URL)) bad('Invalid endpoint', 400);

    // Recorded against the session that enabled it, so revoking a device
    // also silences its notifications.
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO push_subscriptions (user_id, endpoint, p256dh, auth, created_at, session_id) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$user['sub'], $endpoint, $p256dh, $auth, date('Y-m-d H:i:s'), $user['sid'] ?? null]);
    respond(good(['message' => 'Push enabled']));
}

function handle_deletePushSubscription($pdo, $user) {
    $endpoint = trim($_POST['endpoint'] ?? '');
    if (!$endpoint) bad('Missing endpoint', 400);

    $stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?');
    $stmt->execute([$endpoint, $user['sub']]);
    respond(good(['message' => 'Push disabled']));
}
