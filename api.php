<?php
// api.php - Simple Social API (max 3 levels indentation)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/webpush.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/auth.php';
// Composer's autoload.files loads src/Auth/handlers.php (and any future
// module files) unconditionally, same as the require_once lines above -
// this is a folder-level move, not a switch to lazy/class autoloading.
require_once __DIR__ . '/vendor/autoload.php';

ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ============== HELPER FUNCTIONS ==============
function getRawPostData() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    parse_str($raw, $params);
    return $params;
}

$_POST = array_merge($_POST, getRawPostData());

function logMsg($msg) {
    global $CONFIG;
    if (empty($CONFIG['debug'])) return;
    $logDir = $CONFIG['log_dir'] ?? (__DIR__ . '/logs');
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $logFile = rtrim($logDir, '/') . '/api.log';
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] $msg\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function logRequest($action, $params = [], $isPublic = false) {
    $safeParams = $params;
    if (isset($safeParams['password'])) $safeParams['password'] = '***';
    if (isset($safeParams['confirm'])) $safeParams['confirm'] = '***';
    if (isset($safeParams['otp'])) $safeParams['otp'] = '***';
    if (isset($safeParams['reset_otp'])) $safeParams['reset_otp'] = '***';
    // A refresh token is a 30-day credential for the whole account, so it
    // must never reach the log - more sensitive than the access token, not
    // less, because it long outlives it.
    if (isset($safeParams['refreshToken'])) $safeParams['refreshToken'] = '***';
    if (isset($safeParams['postText'])) $safeParams['postText'] = substr($safeParams['postText'], 0, 50) . (strlen($safeParams['postText']) > 50 ? '...' : '');
    if (isset($safeParams['text'])) $safeParams['text'] = substr($safeParams['text'], 0, 50) . (strlen($safeParams['text']) > 50 ? '...' : '');
    $paramsStr = json_encode($safeParams);
    logMsg("REQUEST: action=$action isPublic=" . ($isPublic ? 'true' : 'false') . " params=$paramsStr");
}

function logResponse($action, $success, $message = '') {
    $status = $success ? 'SUCCESS' : 'FAILED';
    logMsg("RESPONSE: action=$action status=$status message=$message");
}

function logError($action, $error) {
    logMsg("ERROR: action=$action error=$error");
    logApiError($action, $error);
}

function respond($data, $code = 200) {
    global $action;
    $success = ($code >= 200 && $code < 400) || ($data['valid'] ?? false);
    $message = $data['message'] ?? ($data['error'] ?? '');
    logResponse($action ?? 'unknown', $success, $message);
    ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function db() {
    global $CONFIG;
    $dbPath = $CONFIG['db_path'] ?? __DIR__ . '/userdata.db';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT, recipient_id INTEGER NOT NULL,
        actor_id INTEGER NOT NULL, actor_email TEXT NOT NULL, type TEXT NOT NULL,
        post_id TEXT, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_recipient ON notifications(recipient_id)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, post_id TEXT NOT NULL,
        user_id INTEGER NOT NULL, comment_text TEXT NOT NULL, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS auth_attempts (
        attempt_key TEXT PRIMARY KEY, failures INTEGER NOT NULL,
        window_start INTEGER NOT NULL, locked_until INTEGER NOT NULL DEFAULT 0)');
    // `media` and its post_id migration live in schema.php - media.php needs
    // the same table, and keeping one copy is the whole point of that file.
    // One row per browser/device a user has enabled push on. endpoint is
    // unique so re-subscribing the same browser replaces its row instead of
    // piling up duplicates.
    $pdo->exec('CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
        endpoint TEXT NOT NULL UNIQUE, p256dh TEXT NOT NULL, auth TEXT NOT NULL,
        created_at TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_push_subscriptions_user ON push_subscriptions(user_id)');
    try {
        $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $hasTheme = false;
        $hasHand = false;
        foreach ($cols as $c) {
            if ($c['name'] === 'theme') $hasTheme = true;
            if ($c['name'] === 'hand') $hasHand = true;
        }
        if (!$hasTheme) $pdo->exec("ALTER TABLE users ADD COLUMN theme TEXT NOT NULL DEFAULT 'light'");
        if (!$hasHand) $pdo->exec("ALTER TABLE users ADD COLUMN hand TEXT NOT NULL DEFAULT 'right'");
    } catch (Exception $e) {}
    ensureSharedSchema($pdo);
    return $pdo;
}

// jwtEncode/jwtVerify/verifyUser now live in auth.php, shared with media.php.

function requireAuth($pdo, $publicEndpoints) {
    global $action;
    global $CONFIG;
    if (in_array($action, $publicEndpoints)) return null;
    $jwt = bearerToken();
    $user = verifyUser($jwt, $pdo);
    logMsg("AUTH: action=$action result=" . ($user ? 'ok sub=' . $user['sub'] . ' sid=' . $user['sid'] : 'FAILED'));
    if (!$user) respond(['valid' => false, 'error' => 'Unauthorized'], 401);
    $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $stmt->execute([$user['sub']]);
    $user['email'] = $stmt->fetchColumn() ?: 'User';
    return $user;
}

function bad($msg, $code = 400) {
    global $action;
    logError($action ?? 'unknown', $msg);
    respond(['valid' => false, 'message' => $msg], $code);
}

function good($data = []) {
    return array_merge(['valid' => true], $data);
}

// ============== NOTIFICATIONS ==============
// The one place a notification gets created: writes the row the bell icon
// reads, then pushes it to whatever devices the recipient has enabled push
// on. Push failures are swallowed -- a dead subscription must never break
// the like/comment/follow that triggered it.
function createNotification($pdo, $recipientId, $actorId, $actorEmail, $type, $postId = null) {
    $now = date('Y-m-d H:i:s');
    if ($postId === null) {
        $stmt = $pdo->prepare('INSERT INTO notifications (recipient_id, actor_id, actor_email, type, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$recipientId, $actorId, $actorEmail, $type, $now]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO notifications (recipient_id, actor_id, actor_email, type, post_id, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$recipientId, $actorId, $actorEmail, $type, $postId, $now]);
    }

    try {
        pushNotification($pdo, $recipientId, $actorEmail, $type, $postId, $actorId);
    } catch (Exception $e) {
        logMsg("push failed: " . $e->getMessage());
    } catch (Error $e) {
        logMsg("push failed: " . $e->getMessage());
    }
}

function notificationText($actorEmail, $type) {
    switch ($type) {
        case 'like': return "$actorEmail liked your post";
        case 'unlike': return "$actorEmail unliked your post";
        case 'comment': return "$actorEmail commented on your post";
        case 'follow': return "$actorEmail started following you";
        case 'unfollow': return "$actorEmail unfollowed you";
        case 'mention': return "$actorEmail mentioned you in a post";
    }
    return "$actorEmail did something";
}

function pushNotification($pdo, $recipientId, $actorEmail, $type, $postId, $actorId) {
    global $CONFIG;
    if (empty($CONFIG['vapid_public']) || empty($CONFIG['vapid_private'])) return;

    $stmt = $pdo->prepare('SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
    $stmt->execute([$recipientId]);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$subscriptions) return;

    $url = $postId ? "/app.html#/post/$postId" : "/app.html#/profile/$actorId";
    // The service worker re-asserts this count against the OS home-screen
    // badge on every notification event it sees (shown, clicked, swiped
    // away) - the badge is only ever meant to change via "mark as read", so
    // it has to keep reapplying the real count rather than trust whatever
    // the OS did on its own.
    $payload = [
        'title' => 'Simple Social',
        'body' => notificationText($actorEmail, $type),
        'url' => $url,
        'count' => getUnseenNotificationCount($pdo, $recipientId),
    ];
    $subject = $CONFIG['vapid_subject'] ?? 'noreply@davidfruin.com';

    foreach ($subscriptions as $sub) {
        $status = sendWebPush($sub, $payload, $subject);
        logMsg("push to user $recipientId status=$status");
        // The push service says this subscription no longer exists.
        if ($status === 404 || $status === 410) {
            $del = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
            $del->execute([$sub['endpoint']]);
        }
    }
}

// OTP_VERIFIED, attempt-limiting (attemptKeys/checkAttemptLimit/
// recordFailedAttempt/clearAttempts) and validatePasswordRules moved to
// src/Auth/handlers.php - auth-exclusive, unlike validateContent below
// which posts/comments also use.

// Printable ASCII plus the Latin-1 accented letters. No emoji, and no line
// breaks -- posts render them as spaces anyway, so they only ever looked like
// they worked.
function validateContent($text, $errorMsg = 'You are trying to post illegal characters') {
    if (preg_match('/[^\x20-\x7E\xA0-\xFF]/u', $text)) bad($errorMsg, 400);
}

// ============== MENTIONS ==============
// Mentions live in the text itself as @[id] tokens rather than a parallel
// field, so a user's display name can change (they're only ever identified
// by email, which is itself changeable) without rewriting old posts -- the
// id is resolved to whatever email is current at render time.
function extractMentions($text) {
    global $CONFIG;
    preg_match_all('/@\[(\d+)\]/', $text, $matches);
    $ids = array_values(array_unique(array_map('intval', $matches[1])));
    if (count($ids) > $CONFIG['max_mentions']) {
        bad('Too many people tagged. Max: ' . $CONFIG['max_mentions'], 400);
    }
    return $ids;
}

function notifyMentions($pdo, $mentionIds, $actorId, $actorEmail, $postId) {
    foreach ($mentionIds as $id) {
        createNotification($pdo, $id, $actorId, $actorEmail, 'mention', $postId);
    }
}

// PREVIEW_MAX_CHARS, resolveMentionTokens and truncatePreview moved to
// src/Posts/handlers.php - only handle_getPostPreviews used them.

// Resolves @[id] tokens to {id, email} for the API response, so clients
// don't need a separate round trip. A deleted user's id still resolves --
// email comes back null and the caller renders a fallback.
function hydrateMentions($pdo, $text) {
    preg_match_all('/@\[(\d+)\]/', $text, $matches);
    $ids = array_values(array_unique(array_map('intval', $matches[1])));
    if (!$ids) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $emails = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $emails[(int)$row['id']] = $row['email'];
    }

    $result = [];
    foreach ($ids as $id) {
        $result[] = ['id' => $id, 'email' => $emails[$id] ?? null];
    }
    return $result;
}

// AUTH HANDLERS (handle_login, handle_logout, handle_refreshToken,
// handle_getSessions, handle_revokeSession, handle_revokeAllOtherSessions,
// handle_sendOTP, handle_verifyOTP, handle_resetPassword,
// handle_sendRegisterOTP, handle_verifyRegisterOTP, handle_finishRegister)
// moved to src/Auth/handlers.php, loaded via Composer's autoload.files.

// ============== PROTECTED HANDLERS ==============
function handle_deleteAccount($pdo, $user) {
    $password = $_POST['password'] ?? '';
    if (!$password) bad('Password required', 400);

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$user['sub']]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($password, $hash)) bad('Incorrect password', 401);

    $uid = $user['sub'];
    $stmt = $pdo->prepare('DELETE FROM notifications WHERE recipient_id = ? OR actor_id = ?');
    $stmt->execute([$uid, $uid]);

    $stmt = $pdo->prepare('SELECT id, follows FROM users WHERE id != ?');
    $stmt->execute([$uid]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $follows = $row['follows'] ? json_decode($row['follows'], true) : [];
        if (is_array($follows)) {
            $updated = array_filter($follows, fn($f) => (is_array($f) ? $f['id'] : $f) != $uid);
            $stmtUpdate = $pdo->prepare('UPDATE users SET follows = ? WHERE id = ?');
            $stmtUpdate->execute([json_encode(array_values($updated)), $row['id']]);
        }
    }

    // This user's own posts, and every like anyone gave them; plus every
    // like this user gave out on someone else's post.
    $stmt = $pdo->prepare('SELECT id FROM posts WHERE user_id = ?');
    $stmt->execute([$uid]);
    $ownPostIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
    if ($ownPostIds) {
        $placeholders = implode(',', array_fill(0, count($ownPostIds), '?'));
        $pdo->prepare("DELETE FROM post_likes WHERE post_id IN ($placeholders)")->execute($ownPostIds);
    }
    $pdo->prepare('DELETE FROM posts WHERE user_id = ?')->execute([$uid]);
    $pdo->prepare('DELETE FROM post_likes WHERE user_id = ?')->execute([$uid]);

    $stmt = $pdo->prepare('DELETE FROM comments WHERE user_id = ?');
    $stmt->execute([$uid]);
    $stmt = $pdo->prepare('SELECT path FROM media WHERE user_id = ?');
    $stmt->execute([$uid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $f = __DIR__ . $r['path'];
        if (strpos($r['path'], '..') === false && file_exists($f)) @unlink($f);
        $tf = preg_replace('#/video/([^/]+)\.[^./]+$#', '/video/thumb_$1.webp', $f);
        if (file_exists($tf)) @unlink($tf);
    }
    $pdo->prepare('DELETE FROM media WHERE user_id = ?')->execute([$uid]);
    $mediaDir = __DIR__ . '/media/' . $uid;
    if (is_dir($mediaDir)) @rmdir($mediaDir . '/image') && @rmdir($mediaDir . '/video') && @rmdir($mediaDir . '/audio') && @rmdir($mediaDir);
    $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$uid]);
    $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ?')->execute([$uid]);
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    respond(good(['message' => 'Account deleted successfully']));
}

// handle_getMyFollowers, handle_getMyFollows moved to
// src/Follows/handlers.php.

// handle_getNotifications, getUnseenNotificationCount,
// handle_getUnseenNotificationCount, handle_markNotificationsSeen moved to
// src/Notifications/handlers.php.

// getLikesForPostIds, postRowToApi, handle_getPostById, handle_getPostPreviews,
// handle_post, handle_getMyPosts, handle_getUserPosts moved to
// src/Posts/handlers.php.

// handle_getUserInfo, handle_getUsers, handle_getUserEmails,
// handle_getMyInfo, handle_updateTheme, handle_updateHand moved to
// src/Users/handlers.php.

// handle_getVapidPublicKey, handle_savePushSubscription,
// handle_deletePushSubscription moved to src/Notifications/handlers.php.

// handle_updateHand moved to src/Users/handlers.php.

// handle_fetchFollowedPosts moved to src/Posts/handlers.php.

// handle_likePost, handle_unlikePost, handle_getPostLikes moved to
// src/Posts/handlers.php (post_likes is post-scoped data, not a separate
// module -- see that file's header comment; corrects an omission in the
// original split-backend-modules.md).

// handle_followUser, handle_unfollowUser, handle_isFollowing moved to
// src/Follows/handlers.php.

// handle_deletePost moved to src/Posts/handlers.php. NOTE: its __DIR__
// media-path resolution had to be adjusted there, since __DIR__ now means
// src/Posts, not the repo root api.php lived in -- check any other moved
// handler for the same trap before assuming a plain move is safe.

// handle_createComment, handle_getPostComments, handle_deleteComment,
// handle_getPostCommentCounts moved to src/Comments/handlers.php.

// ============== DISPATCHER ==============
$PUBLIC_ENDPOINTS = ['login', 'logout', 'refreshToken', 'sendOTP', 'verifyOTP', 'resetPassword', 'sendRegisterOTP', 'verifyRegisterOTP', 'finishRegister'];

$HANDLERS = [
    'login' => 'handle_login', 'logout' => 'handle_logout', 'refreshToken' => 'handle_refreshToken',
    'sendOTP' => 'handle_sendOTP',
    'verifyOTP' => 'handle_verifyOTP', 'resetPassword' => 'handle_resetPassword',
    'sendRegisterOTP' => 'handle_sendRegisterOTP', 'verifyRegisterOTP' => 'handle_verifyRegisterOTP',
    'finishRegister' => 'handle_finishRegister', 'deleteAccount' => 'handle_deleteAccount',
    'getMyFollowers' => 'handle_getMyFollowers', 'getMyFollows' => 'handle_getMyFollows',
    'getNotifications' => 'handle_getNotifications', 'getUnseenNotificationCount' => 'handle_getUnseenNotificationCount',
    'post' => 'handle_post',
    'getMyPosts' => 'handle_getMyPosts', 'getUserPosts' => 'handle_getUserPosts',
    'getUserInfo' => 'handle_getUserInfo', 'getUsers' => 'handle_getUsers', 'getUserEmails' => 'handle_getUserEmails',
    'getMyInfo' => 'handle_getMyInfo', 'fetchFollowedPosts' => 'handle_fetchFollowedPosts',
    'likePost' => 'handle_likePost', 'unlikePost' => 'handle_unlikePost', 'getPostLikes' => 'handle_getPostLikes',
    'followUser' => 'handle_followUser', 'unfollowUser' => 'handle_unfollowUser',
    'isFollowing' => 'handle_isFollowing', 'deletePost' => 'handle_deletePost',
    'createComment' => 'handle_createComment', 'getPostComments' => 'handle_getPostComments',
    'deleteComment' => 'handle_deleteComment', 'getPostCommentCounts' => 'handle_getPostCommentCounts',
    'markNotificationsSeen' => 'handle_markNotificationsSeen', 'getPostById' => 'handle_getPostById',
    'getPostPreviews' => 'handle_getPostPreviews',
    'updateTheme' => 'handle_updateTheme', 'updateHand' => 'handle_updateHand',
    'getVapidPublicKey' => 'handle_getVapidPublicKey',
    'savePushSubscription' => 'handle_savePushSubscription',
    'deletePushSubscription' => 'handle_deletePushSubscription',
    'getSessions' => 'handle_getSessions', 'revokeSession' => 'handle_revokeSession',
    'revokeAllOtherSessions' => 'handle_revokeAllOtherSessions',
    'log' => 'handle_log_request'
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') bad('Method not allowed', 405);
$action = $_POST['action'] ?? null;
if (!$action) bad('Missing action', 400);
if (!isset($HANDLERS[$action])) bad('Unknown action', 400);

$pdo = db();
$isPublic = in_array($action, $PUBLIC_ENDPOINTS);
logRequest($action, $_POST, $isPublic);

$user = requireAuth($pdo, $PUBLIC_ENDPOINTS);

try {
    $handler = $HANDLERS[$action];
    $handler($pdo, $user);
} catch (Exception $e) {
    logError($action, $e->getMessage());
    respond(['valid' => false, 'error' => 'Server error. Please try again.'], 500);
} catch (Error $e) {
    logError($action, $e->getMessage());
    respond(['valid' => false, 'error' => 'Server error. Please try again.'], 500);
}
