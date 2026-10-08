<?php
// api.php - Simple Social API
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

// Now routes through logging.php's writeLog(), same rotating (5MB x 3),
// off-by-default (debug-gated) logger as everything else -- this used to
// write straight to api.log with no rotation and no size limit, which is
// how it grew to ~10MB sitting in a web-served folder on prod (S2).
function logMsg($msg) {
    writeLog('DEBUG', 'api', $msg);
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

// Work queued to run AFTER the response has already been sent to the
// client -- currently just push notifications (P2), which used to run
// serially inside the request (fresh ECDH key generation, encryption, and
// a blocking curl per device, before the caller ever saw a response).
$DEFERRED = [];
function defer(callable $fn) { global $DEFERRED; $DEFERRED[] = $fn; }

function runDeferred() {
    global $DEFERRED;
    $jobs = $DEFERRED;
    $DEFERRED = [];
    foreach ($jobs as $fn) {
        try { $fn(); } catch (Throwable $e) { logMsg('deferred failed: ' . $e->getMessage()); }
    }
}

function respond($data, $code = 200) {
    global $action, $DEFERRED;
    $success = ($code >= 200 && $code < 400) || ($data['valid'] ?? false);
    $message = $data['message'] ?? ($data['error'] ?? '');
    logResponse($action ?? 'unknown', $success, $message);
    // Language plan L5: the English sentence is the key. Only when the app
    // asked for Spanish (X-SS-Lang); everyone else gets the English as-is.
    foreach (['message', 'error'] as $field) {
        if (isset($data[$field]) && is_string($data[$field])) $data[$field] = tr($data[$field]);
    }
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($body));
    // S12: a second line of defence for the JWT/refresh token sitting in
    // localStorage -- nosniff stops a browser from ever reinterpreting this
    // JSON as something executable; no-store keeps auth responses out of
    // any cache.
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $body;
    if (!empty($DEFERRED)) {
        while (ob_get_level() > 0) ob_end_flush();
        flush();
        // PHP-FPM (react.davidfruin.com): the client is released right
        // here. Under mod_fcgid (app/dev), Content-Length + flush() above
        // lets the client finish reading the response, but this worker
        // process stays busy until the deferred jobs below finish -- S1's
        // tighter curl timeouts (2s connect / 4s total) cap how long that
        // can drag on.
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        ignore_user_abort(true);
        runDeferred();
    }
    exit;
}

// db() now lives in schema.php, shared with media.php -- see that file's
// header comment. Everything that used to run here on every request moved
// into migration1(), which runs once per database via PRAGMA user_version.

// jwtEncode/jwtVerify/verifyUser now live in auth.php, shared with media.php.

function requireAuth($pdo, $publicEndpoints) {
    global $action;
    global $CONFIG;
    if (in_array($action, $publicEndpoints)) return null;
    $jwt = bearerToken();
    $user = verifyUser($jwt, $pdo);
    logMsg("AUTH: action=$action result=" . ($user ? 'ok sub=' . $user['sub'] . ' sid=' . $user['sid'] : 'FAILED'));
    if (!$user) respond(['valid' => false, 'error' => 'Unauthorized'], 401);
    // email now arrives on $user already -- verifyUser()/sessionLookup()
    // (P9) join it from the same session-row query, so this used to be a
    // second "SELECT email" on every single authenticated request.
    $user['email'] = $user['email'] ?: 'User';
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

// Clamps limit/offset from $_POST. SQLite treats a negative LIMIT as "no
// limit", so an unclamped value (e.g. limit=-1) could return every row
// with everything hydrated in one call.
function pageParams($default = 25, $max = 50) {
    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : $default;
    $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
    return [max(1, min($max, $limit)), max(0, $offset)];
}

// Decodes a JSON array of scalar ids from $_POST[$key], deduplicated and
// capped at $max -- an uncapped list has no size limit and can also exceed
// SQLite's bound-variable limit, which turns into a 500 instead of a 400.
function jsonIdList($key, $max = 100, $ints = false) {
    $list = json_decode($_POST[$key] ?? '[]', true);
    if (!is_array($list)) return [];
    $list = array_values(array_unique(array_filter($list, 'is_scalar')));
    if (count($list) > $max) bad(tr('Too many ids (max {max})', ['max' => $max]), 400);
    if ($ints) return array_values(array_filter(array_map('intval', $list), fn($i) => $i > 0));
    return array_map('strval', $list);
}

// ============== VISIBILITY (blocks, frozen accounts) ==============
// Users whose content the viewer must not see, and who must not interact
// with the viewer: anyone the viewer blocked, anyone who blocked the viewer,
// and every frozen account. Computed once per viewer per request.
function hiddenUserIds($pdo, $viewerId) {
    static $cache = [];
    $viewerId = (int)$viewerId;
    if (isset($cache[$viewerId])) return $cache[$viewerId];
    $stmt = $pdo->prepare('SELECT blocked_id FROM blocks WHERE blocker_id = ?
        UNION SELECT blocker_id FROM blocks WHERE blocked_id = ?
        UNION SELECT id FROM users WHERE frozen_at IS NOT NULL');
    $stmt->execute([$viewerId, $viewerId]);
    return $cache[$viewerId] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// [" AND <col> NOT IN (?,?,..)", [ids]], or ['', []] when nothing is hidden --
// append the first to a WHERE clause and merge the second into its
// parameters, in the same position. $column is always an identifier written
// in the code, never user input.
function hiddenFilter($pdo, $viewerId, $column) {
    $ids = hiddenUserIds($pdo, $viewerId);
    if (!$ids) return ['', []];
    return [" AND $column NOT IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

function isHiddenFrom($pdo, $viewerId, $userId) {
    return in_array((int)$userId, hiddenUserIds($pdo, $viewerId), true);
}

// Ids of the posts or comments ($type) the viewer has reported. Reporting
// hides that content from the reporter straight away (one of the ways
// Apple's "filter objectionable content" requirement is met).
function reportedByViewer($pdo, $viewerId, $type) {
    static $cache = [];
    $key = (int)$viewerId . ':' . $type;
    if (isset($cache[$key])) return $cache[$key];
    $s = $pdo->prepare('SELECT target_id FROM reports WHERE reporter_id = ? AND target_type = ?');
    $s->execute([$viewerId, $type]);
    return $cache[$key] = array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
}

// Same shape as hiddenFilter(), for the viewer's reported posts/comments.
function reportedFilter($pdo, $viewerId, $type, $column) {
    $ids = reportedByViewer($pdo, $viewerId, $type);
    if (!$ids) return ['', []];
    return [" AND $column NOT IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

function isReportedBy($pdo, $viewerId, $type, $id) {
    return in_array((string)$id, reportedByViewer($pdo, $viewerId, $type), true);
}

// ============== NOTIFICATIONS ==============
// The one place a notification gets created: writes the row the bell icon
// reads, then pushes it to whatever devices the recipient has enabled push
// on. Push failures are swallowed -- a dead subscription must never break
// the like/comment/follow that triggered it.
function createNotification($pdo, $recipientId, $actorId, $actorEmail, $type, $postId = null) {
    // Blocked either way, or a frozen actor: nothing is stored or pushed.
    if (isHiddenFrom($pdo, $recipientId, $actorId)) return;
    $now = date('Y-m-d H:i:s');
    if ($postId === null) {
        $stmt = $pdo->prepare('INSERT INTO notifications (recipient_id, actor_id, actor_email, type, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$recipientId, $actorId, $actorEmail, $type, $now]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO notifications (recipient_id, actor_id, actor_email, type, post_id, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$recipientId, $actorId, $actorEmail, $type, $postId, $now]);
    }

    // Deferred (P2) rather than run inline: each device is a fresh ECDH key
    // generation, encryption and a blocking curl, all before respond()
    // used to be reachable. defer()'s own try/catch (runDeferred) is what
    // now does the job the try/catch here used to do -- a dead subscription
    // or a slow push service must never affect the response the caller
    // already got.
    defer(fn() => pushNotification($pdo, $recipientId, $actorEmail, $type, $postId, $actorId));
}

// $lang is the RECIPIENT's language (users.lang), not the request's: a push
// goes to another person (language plan L6).
function notificationText($actorEmail, $type, $lang = 'en') {
    $p = ['actor' => $actorEmail];
    switch ($type) {
        case 'like': return tr('{actor} liked your post', $p, $lang);
        case 'unlike': return tr('{actor} unliked your post', $p, $lang);
        case 'comment': return tr('{actor} commented on your post', $p, $lang);
        case 'follow': return tr('{actor} started following you', $p, $lang);
        case 'unfollow': return tr('{actor} unfollowed you', $p, $lang);
        case 'mention': return tr('{actor} mentioned you in a post', $p, $lang);
    }
    return tr('{actor} did something', $p, $lang);
}

function pushNotification($pdo, $recipientId, $actorEmail, $type, $postId, $actorId) {
    global $CONFIG;

    $stmt = $pdo->prepare('SELECT endpoint, p256dh, auth, kind FROM push_subscriptions WHERE user_id = ?');
    $stmt->execute([$recipientId]);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$subscriptions) return;

    $url = $postId ? "/app.html#/post/$postId" : "/app.html#/profile/$actorId";
    $langStmt = $pdo->prepare('SELECT lang FROM users WHERE id = ?');
    $langStmt->execute([$recipientId]);
    $recipientLang = $langStmt->fetchColumn();
    $text = notificationText($actorEmail, $type, in_array($recipientLang, SUPPORTED_LANGS, true) ? $recipientLang : 'en');
    $count = getUnseenNotificationCount($pdo, $recipientId);

    // The phone app's tokens go through Expo's push service. This branches on
    // kind BEFORE sendWebPush(), whose endpoint check would reject them.
    $expoTokens = [];
    foreach ($subscriptions as $sub) {
        if (($sub['kind'] ?? 'webpush') === 'expo') $expoTokens[] = $sub['endpoint'];
    }
    if ($expoTokens) sendExpoPush($pdo, $expoTokens, 'Simple Social', $text, $url, $count);

    if (empty($CONFIG['vapid_public']) || empty($CONFIG['vapid_private'])) return;

    // The service worker re-asserts this count against the OS home-screen
    // badge on every notification event it sees (shown, clicked, swiped
    // away) - the badge is only ever meant to change via "mark as read", so
    // it has to keep reapplying the real count rather than trust whatever
    // the OS did on its own.
    $payload = [
        'title' => 'Simple Social',
        'body' => $text,
        'url' => $url,
        'count' => $count,
    ];
    $subject = $CONFIG['vapid_subject'] ?? 'noreply@davidfruin.com';

    foreach ($subscriptions as $sub) {
        if (($sub['kind'] ?? 'webpush') !== 'webpush') continue;
        $status = sendWebPush($sub, $payload, $subject);
        logMsg("push to user $recipientId status=$status");
        // The push service says this subscription no longer exists.
        if ($status === 404 || $status === 410) {
            $del = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
            $del->execute([$sub['endpoint']]);
        }
    }
}

// Sends one notification to every Expo token in $tokens with a single request
// to Expo's push service (which forwards to FCM/APNs). Expo answers with one
// ticket per message, in order; a "DeviceNotRegistered" ticket means the app
// was uninstalled or the token is dead, so that row is deleted. exp.host is
// the only host this ever talks to, same restriction as web push's allow-list.
// 'badge' is the unseen count, so the app icon shows the real number.
function sendExpoPush($pdo, array $tokens, $title, $body, $url, $count) {
    global $CONFIG;
    // Overridable for tests only (a local mock); never set in production.
    $endpoint = $CONFIG['expo_push_url'] ?? 'https://exp.host/--/api/v2/push/send';

    $messages = array_map(fn($token) => [
        'to' => $token,
        'title' => $title,
        'body' => $body,
        'data' => ['url' => $url],
        'badge' => $count,
        'sound' => 'default',
        'priority' => 'high',
    ], $tokens);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($messages),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PROTOCOLS => isset($CONFIG['expo_push_url']) ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 4,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logMsg("expo push to " . count($tokens) . " token(s) status=$status");

    $tickets = json_decode((string)$response, true)['data'] ?? [];
    foreach ($tickets as $i => $ticket) {
        if (($ticket['details']['error'] ?? '') === 'DeviceNotRegistered' && isset($tokens[$i])) {
            $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND kind = 'expo'")->execute([$tokens[$i]]);
        }
    }
}

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
        bad(tr('Too many people tagged. Max: {max}', ['max' => $CONFIG['max_mentions']]), 400);
    }
    return $ids;
}

function notifyMentions($pdo, $mentionIds, $actorId, $actorEmail, $postId) {
    foreach ($mentionIds as $id) {
        createNotification($pdo, $id, $actorId, $actorEmail, 'mention', $postId);
    }
}

// Resolves @[id] tokens to {id, email} for the API response, so clients
// don't need a separate round trip. A deleted user's id still resolves --
// email comes back null and the caller renders a fallback.
// [key => [{id,email},...]] for every text in $texts, using a single users
// query -- a 25-post feed page used to run one of these per row (25 extra
// queries just for mentions), since hydrateMentions() below used to do its
// own lookup every time it was called in a loop.
function hydrateMentionsBatch($pdo, array $texts) {
    $idsByKey = [];
    $all = [];
    foreach ($texts as $k => $t) {
        preg_match_all('/@\[(\d+)\]/', (string)$t, $m);
        $ids = array_values(array_unique(array_map('intval', $m[1])));
        $idsByKey[$k] = $ids;
        foreach ($ids as $id) $all[$id] = true;
    }

    $emails = [];
    if ($all) {
        $ids = array_keys($all);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $emails[(int)$row['id']] = $row['email'];
        }
    }

    $out = [];
    foreach ($idsByKey as $k => $ids) {
        $out[$k] = array_map(fn($id) => ['id' => $id, 'email' => $emails[$id] ?? null], $ids);
    }
    return $out;
}

function hydrateMentions($pdo, $text) {
    return hydrateMentionsBatch($pdo, [$text])[0];
}

// One COUNT(*) GROUP BY instead of one query per post id -- a 25-post feed
// page used to ask getPostCommentCounts for 25 individual COUNTs.
// With $viewerId, comments the viewer can't see (blocked/frozen authors)
// aren't counted.
function getCommentCountsForPostIds($pdo, array $postIds, $viewerId = null) {
    if (!$postIds) return [];
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    [$hf, $hp] = $viewerId ? hiddenFilter($pdo, $viewerId, 'user_id') : ['', []];
    [$rf, $rp] = $viewerId ? reportedFilter($pdo, $viewerId, 'comment', 'id') : ['', []];
    $stmt = $pdo->prepare("SELECT post_id, COUNT(*) AS n FROM comments WHERE post_id IN ($placeholders)$hf$rf GROUP BY post_id");
    $stmt->execute(array_merge(array_values($postIds), $hp, $rp));
    $counts = array_fill_keys($postIds, 0);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[$row['post_id']] = (int)$row['n'];
    return $counts;
}

// ============== PROTECTED HANDLERS ==============
function handle_deleteAccount($pdo, $user) {
    $password = $_POST['password'] ?? '';
    if (!$password) bad('Password required', 400);

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$user['sub']]);
    $hash = $stmt->fetchColumn();
    if (!$hash || !password_verify($password, $hash)) bad('Incorrect password', 401);

    $uid = $user['sub'];

    // Filesystem unlinks happen after commit (S13) -- same reasoning as
    // handle_deletePost: a failed unlink() must never roll back DB rows
    // that already deleted cleanly, and vice versa.
    $filesToUnlink = [];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM posts WHERE user_id = ?');
        $stmt->execute([$uid]);
        $ownPostIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

        // recipient_id/actor_id cover every notification this user sent or
        // received directly. post_id covers the rest: e.g. bob comments on
        // alice's post mentioning carol creates a 'mention' notification to
        // carol, naming neither alice nor bob as recipient/actor -- if
        // alice deletes her account, that row would otherwise be left
        // pointing at a post_id that no longer exists.
        $notifParams = [$uid, $uid];
        $postIdFilter = '';
        if ($ownPostIds) {
            $placeholders = implode(',', array_fill(0, count($ownPostIds), '?'));
            $postIdFilter = " OR post_id IN ($placeholders)";
            $notifParams = array_merge($notifParams, $ownPostIds);
        }
        $pdo->prepare("DELETE FROM notifications WHERE recipient_id = ? OR actor_id = ?$postIdFilter")
            ->execute($notifParams);

        $stmt = $pdo->prepare('SELECT id, follows FROM users WHERE id != ?');
        $stmt->execute([$uid]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $follows = $row['follows'] ? json_decode($row['follows'], true) : [];
            if (is_array($follows)) {
                $updated = array_filter($follows, fn($f) => (is_array($f) ? $f['id'] : $f) != $uid);
                $pdo->prepare('UPDATE users SET follows = ? WHERE id = ?')
                    ->execute([json_encode(array_values($updated)), $row['id']]);
            }
        }

        // This user's own posts, and every like anyone gave them; plus
        // every like this user gave out on someone else's post.
        if ($ownPostIds) {
            $placeholders = implode(',', array_fill(0, count($ownPostIds), '?'));
            $pdo->prepare("DELETE FROM post_likes WHERE post_id IN ($placeholders)")->execute($ownPostIds);
            // Comments other people left ON this user's posts -- previously
            // left behind entirely once the post itself was gone.
            $pdo->prepare("DELETE FROM comments WHERE post_id IN ($placeholders)")->execute($ownPostIds);
        }
        $pdo->prepare('DELETE FROM posts WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM post_likes WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM comments WHERE user_id = ?')->execute([$uid]);

        $stmt = $pdo->prepare('SELECT * FROM media WHERE user_id = ?');
        $stmt->execute([$uid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // Every file the row owns (file, thumbnail, variant, poster);
            // mediaFilePath() underneath rejects anything outside media/.
            $filesToUnlink = array_merge($filesToUnlink, mediaRowFiles($r));
        }
        $pdo->prepare('DELETE FROM media WHERE user_id = ?')->execute([$uid]);
        // Moderation: their blocks both ways and the reports they filed go;
        // reports *about* them stay (with the snapshot) as the moderation
        // record, no longer pointing at an account.
        $pdo->prepare('DELETE FROM blocks WHERE blocker_id = ? OR blocked_id = ?')->execute([$uid, $uid]);
        $pdo->prepare('DELETE FROM reports WHERE reporter_id = ?')->execute([$uid]);
        $pdo->prepare('UPDATE reports SET target_user_id = NULL WHERE target_user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ?')->execute([$uid]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    foreach ($filesToUnlink as $file) {
        if (file_exists($file)) @unlink($file);
    }
    // rmdir only succeeds on an empty directory, so the old chain
    // (@rmdir(image) && @rmdir(video) && ...) stopped at the first type
    // folder that didn't exist on this particular user (most users don't
    // have all three), short-circuiting past the rest -- the user's media
    // folder was then never removed. Each rmdir now stands alone.
    // getMediaDir() (src/Media/handlers.php, deploy layout: L1) reads
    // $CONFIG['media_dir'] instead of a path relative to this file.
    $mediaDir = getMediaDir($uid);
    foreach (['image', 'video', 'audio'] as $type) {
        $typeDir = "$mediaDir/$type";
        if (is_dir($typeDir)) @rmdir($typeDir);
    }
    if (is_dir($mediaDir)) @rmdir($mediaDir);

    respond(good(['message' => 'Account deleted successfully']));
}

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
    'updateTheme' => 'handle_updateTheme', 'updateHand' => 'handle_updateHand', 'updateLanguage' => 'handle_updateLanguage',
    'getMediaLimits' => 'handle_getMediaLimits',
    'getVapidPublicKey' => 'handle_getVapidPublicKey',
    'savePushSubscription' => 'handle_savePushSubscription',
    'deletePushSubscription' => 'handle_deletePushSubscription',
    'saveExpoPushToken' => 'handle_saveExpoPushToken',
    'deleteExpoPushToken' => 'handle_deleteExpoPushToken',
    'reportContent' => 'handle_reportContent', 'acceptTerms' => 'handle_acceptTerms',
    'blockUser' => 'handle_blockUser', 'unblockUser' => 'handle_unblockUser',
    'getBlockedUsers' => 'handle_getBlockedUsers',
    'adminListReports' => 'handle_adminListReports', 'adminResolveReport' => 'handle_adminResolveReport',
    'adminFreezeUser' => 'handle_adminFreezeUser', 'adminUnfreezeUser' => 'handle_adminUnfreezeUser',
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
