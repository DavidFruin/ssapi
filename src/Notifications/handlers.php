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
    // Fixed page size here (unlike the post/comment lists) -- only offset
    // needs clamping, via pageParams()'s own max(0, ...).
    [, $offset] = pageParams();
    $limit = 25;
    // Every other type can't target yourself in the first place (you can't
    // follow/like/comment-notify yourself), but a mention can - self-mentions
    // are meant to notify like any other, so they're exempted here rather
    // than excluded by the general actor_id != recipient_id noise filter.
    // Nothing from users hidden from the viewer (blocked either way, or frozen).
    [$hf, $hp] = hiddenFilter($pdo, $user['sub'], 'n.actor_id');
    $stmt = $pdo->prepare("SELECT n.id, n.recipient_id, n.actor_id, COALESCE(u.email, n.actor_email) AS actor_email, n.type, n.post_id, n.created_at FROM notifications n LEFT JOIN users u ON n.actor_id = u.id WHERE n.recipient_id = ? AND (n.actor_id != ? OR n.type = 'mention')$hf ORDER BY n.created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute(array_merge([$user['sub'], $user['sub']], $hp, [$limit, $offset]));
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Per-row `seen`, computed the same way getUnseenNotificationCount()
    // computes its total -- there's no per-notification seen column, just
    // the one last_notifications_seen_at timestamp on the user, so a row
    // counts as seen when it's no newer than that. Added so a client can
    // highlight unseen rows individually instead of only showing a total
    // count.
    $stmt = $pdo->prepare('SELECT last_notifications_seen_at FROM users WHERE id = ?');
    $stmt->execute([$user['sub']]);
    $lastSeen = $stmt->fetchColumn();
    foreach ($notifications as &$n) {
        $n['seen'] = $lastSeen ? ($n['created_at'] <= $lastSeen) : false;
    }

    respond(good(['notifications' => $notifications]));
}

// Shared with pushNotification() so a push payload's embedded count is
// computed the exact same way the notifications page's own count is -- the
// service worker re-asserts this value against the OS badge on every
// notification interaction it sees, so it has to match.
function getUnseenNotificationCount($pdo, $userId) {
    $stmt = $pdo->prepare('SELECT last_notifications_seen_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $lastSeen = $stmt->fetchColumn();

    [$hf, $hp] = hiddenFilter($pdo, $userId, 'actor_id');
    if (!$lastSeen) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND (actor_id != ? OR type = 'mention')$hf");
        $stmt->execute(array_merge([$userId, $userId], $hp));
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND (actor_id != ? OR type = 'mention') AND created_at > ?$hf");
        $stmt->execute(array_merge([$userId, $userId, $lastSeen], $hp));
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

// Keeps at most 10 push targets per user, newest first, so a misbehaving or
// malicious client can't pile up an unbounded number of them.
function capPushSubscriptions($pdo, $userId) {
    $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND id NOT IN
        (SELECT id FROM push_subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 10)')
        ->execute([$userId, $userId]);
}

function handle_savePushSubscription($pdo, $user) {
    $endpoint = trim($_POST['endpoint'] ?? '');
    $p256dh = trim($_POST['p256dh'] ?? '');
    $auth = trim($_POST['auth'] ?? '');
    if (!$endpoint || !$p256dh || !$auth) bad('Missing subscription details', 400);
    if (!isAllowedPushEndpoint($endpoint)) bad('Invalid endpoint', 400);
    if (strlen(webpush_b64url_decode($p256dh)) !== 65 || strlen(webpush_b64url_decode($auth)) !== 16) bad('Invalid subscription keys', 400);

    // Recorded against the session that enabled it, so revoking a device
    // also silences its notifications.
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO push_subscriptions (user_id, endpoint, p256dh, auth, created_at, session_id, kind) VALUES (?, ?, ?, ?, ?, ?, 'webpush')");
    $stmt->execute([$user['sub'], $endpoint, $p256dh, $auth, date('Y-m-d H:i:s'), $user['sid'] ?? null]);
    capPushSubscriptions($pdo, $user['sub']);

    respond(good(['message' => 'Push enabled']));
}

function handle_deletePushSubscription($pdo, $user) {
    $endpoint = trim($_POST['endpoint'] ?? '');
    if (!$endpoint) bad('Missing endpoint', 400);

    $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ? AND kind = 'webpush'");
    $stmt->execute([$endpoint, $user['sub']]);
    respond(good(['message' => 'Push disabled']));
}

// The phone app's push token ("ExponentPushToken[...]"), which Expo's push
// service turns into an FCM (Android) or APNs (iOS) message. The token goes in
// the endpoint column (it is unique per device install); the web-push key
// columns are unused and left empty. Like web push, it's tied to the session
// that registered it, so logging out or revoking that device removes it. A
// token already registered by someone else on the same phone moves to the
// account that's logged in now.
function handle_saveExpoPushToken($pdo, $user) {
    $token = trim($_POST['token'] ?? '');
    if (!preg_match('/^ExponentPushToken\[[A-Za-z0-9_-]+\]$/', $token)) bad('Invalid push token', 400);

    $stmt = $pdo->prepare("INSERT OR REPLACE INTO push_subscriptions (user_id, endpoint, p256dh, auth, created_at, session_id, kind) VALUES (?, ?, '', '', ?, ?, 'expo')");
    $stmt->execute([$user['sub'], $token, date('Y-m-d H:i:s'), $user['sid'] ?? null]);
    capPushSubscriptions($pdo, $user['sub']);

    respond(good(['message' => 'Push enabled']));
}

function handle_deleteExpoPushToken($pdo, $user) {
    $token = trim($_POST['token'] ?? '');
    if (!$token) bad('Missing token', 400);

    $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ? AND kind = 'expo'");
    $stmt->execute([$token, $user['sub']]);
    respond(good(['message' => 'Push disabled']));
}
