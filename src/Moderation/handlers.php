<?php
// src/Moderation/handlers.php - Moderation module: blocking, reporting, the
// admin actions on reports, and terms acceptance (access-and-public-launch
// plan, Step 1B). Apple's guideline 1.2 requires report/block/terms for any
// app with user posts, the Unlisted App Store listing included.
//
// Loaded via Composer's "files" autoload (see composer.json), same as the
// other modules - global-namespace functions, not classes.
//
// Depends on shared Core helpers still defined in api.php: bad(), good(),
// respond(), hiddenUserIds()/isHiddenFrom() (the visibility filter every
// listing handler applies), defer().

// Word filter (access-and-public-launch plan, Step 1C.2). Built once per
// request from private/blocked-words.txt. Null when the file is missing or
// empty: no filtering, and posting never breaks over the file. Lines are
// literal (no regex syntax); a run of spaces inside a phrase matches any
// whitespace run; a listed word does not match inside a longer word.
function blockedWordsRegex(): ?string {
    global $CONFIG;
    static $cache = null;
    if ($cache === null) {
        $file = $CONFIG['blocked_words_file'] ?? '';
        $lines = ($file !== '' && is_readable($file)) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $parts = [];
        foreach ($lines as $line) {
            $word = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line));
            if ($word === '') continue;
            $pieces = preg_split('/\s+/u', $word, -1, PREG_SPLIT_NO_EMPTY);
            if (!$pieces) continue;
            $parts[] = implode('\s+', array_map(fn($p) => preg_quote($p, '/'), $pieces));
        }
        $cache = $parts ? '/(?<![\p{L}\p{N}])(?:' . implode('|', $parts) . ')(?![\p{L}\p{N}])/iu' : '';
    }
    return $cache === '' ? null : $cache;
}

function containsBlockedWord(string $text): bool {
    $re = blockedWordsRegex();
    return $re !== null && preg_match($re, $text) === 1;
}

const REPORT_REASONS = ['spam', 'harassment', 'hate', 'sexual', 'violence', 'self_harm', 'illegal', 'other'];

// Any logged-in user can report a post, a comment or a user. One report per
// reporter per target; a repeat is refused with "You already reported this".
// The reported post/comment stays visible to the reporter, marked reportedByMe
// (the apps label it), and the admin is emailed so the roughly
// 24-hour response Apple expects can be met. The email never names the
// reporter.
function handle_reportContent($pdo, $user) {
    global $CONFIG;
    $uid = (int)$user['sub'];
    $type = $_POST['targetType'] ?? '';
    $targetId = trim((string)($_POST['targetId'] ?? ''));
    $reason = $_POST['reason'] ?? '';
    $details = trim((string)($_POST['details'] ?? ''));

    if (!in_array($type, ['post', 'comment', 'user'], true)) bad('Invalid report type', 400);
    if ($targetId === '') bad('Missing target', 400);
    if (!in_array($reason, REPORT_REASONS, true)) bad('Invalid reason', 400);
    if (strlen($details) > 500) bad('Details are too long (max 500 characters)', 400);
    if ($details !== '') validateContent($details, 'Illegal characters in details');

    if ($type === 'post') {
        $s = $pdo->prepare('SELECT user_id, text FROM posts WHERE id = ?');
        $s->execute([$targetId]);
    } elseif ($type === 'comment') {
        $s = $pdo->prepare('SELECT user_id, comment_text FROM comments WHERE id = ?');
        $s->execute([(int)$targetId]);
        $targetId = (string)(int)$targetId;
    } else {
        $s = $pdo->prepare('SELECT id, email FROM users WHERE id = ?');
        $s->execute([(int)$targetId]);
        $targetId = (string)(int)$targetId;
    }
    $target = $s->fetch(PDO::FETCH_NUM);
    if (!$target) {
        $notFound = match ($type) {
            'post' => tr('Post not found'),
            'comment' => tr('Comment not found'),
            default => tr('User not found'),
        };
        bad($notFound, 404);
    }
    [$targetUserId, $snapshot] = [(int)$target[0], (string)$target[1]];
    if ($targetUserId === $uid) bad("You can't report yourself", 400);

    $dup = $pdo->prepare('SELECT 1 FROM reports WHERE reporter_id = ? AND target_type = ? AND target_id = ?');
    $dup->execute([$uid, $type, $targetId]);
    if ($dup->fetchColumn()) bad('You already reported this', 409);

    throttleSend($pdo, "report:$uid", 20, 86400, 'Too many reports today. Try again tomorrow.');

    $ins = $pdo->prepare('INSERT OR IGNORE INTO reports (reporter_id, target_type, target_id, target_user_id, reason, details, snapshot, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$uid, $type, $targetId, $targetUserId, $reason, $details !== '' ? $details : null, $snapshot, date('Y-m-d H:i:s')]);

    if ($ins->rowCount() > 0) {
        $reportId = (int)$pdo->lastInsertId();
        $to = $CONFIG['admin_report_email'] ?? '';
        if ($to === '') {
            writeLog('WARN', 'api', "report #$reportId saved but ADMIN_REPORT_EMAIL is not set, so no email was sent");
        } else {
            $host = preg_replace('/[^A-Za-z0-9.:-]/', '', $_SERVER['HTTP_HOST'] ?? '');
            $link = $host !== '' ? "https://$host/admin" : '/admin';
            $authorStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
            $authorStmt->execute([$targetUserId]);
            $author = $authorStmt->fetchColumn() ?: "user $targetUserId";
            $subject = "Simple Social report #$reportId: $type ($reason)";
            $body = "A $type was reported for: $reason\n\n"
                . "Author: $author\n"
                . "Content at the time of the report:\n$snapshot\n\n"
                . ($details !== '' ? "Details from the reporter:\n$details\n\n" : '')
                . "Review it on the admin page: $link\n";
            $headers = "From: no-reply@app.davidfruin.com\r\nReply-To: no-reply@app.davidfruin.com\r\n";
            defer(fn() => mail($to, $subject, $body, $headers));
        }
    }
    respond(good());
}

// Removes $removeId from $userId's users.follows JSON list. Follows aren't a
// table yet, so this is the same decode/filter/re-encode the Follows module
// does.
function removeFollow($pdo, $userId, $removeId) {
    $stmt = $pdo->prepare('SELECT follows FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $follows = json_decode($stmt->fetchColumn() ?: '[]', true) ?? [];
    $kept = array_values(array_filter($follows, fn($f) => (is_array($f) ? $f['id'] : $f) != $removeId));
    if (count($kept) < count($follows)) {
        $pdo->prepare('UPDATE users SET follows = ? WHERE id = ?')->execute([json_encode($kept), $userId]);
    }
}

// Two-way invisibility: neither user sees the other's posts, comments, likes
// or profile, and neither can follow, comment on or notify the other (the
// visibility filter does that part). Here: the block row, follows in both
// directions removed, and existing notifications between them deleted. The
// blocked user isn't told.
function handle_blockUser($pdo, $user) {
    $targetId = (int)($_POST['userId'] ?? 0);
    $uid = (int)$user['sub'];
    if ($targetId <= 0) bad('Invalid user ID', 400);
    if ($targetId === $uid) bad("You can't block yourself", 400);

    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
    $stmt->execute([$targetId]);
    if (!$stmt->fetchColumn()) bad('User not found', 404);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT OR IGNORE INTO blocks (blocker_id, blocked_id, created_at) VALUES (?, ?, ?)')
            ->execute([$uid, $targetId, date('Y-m-d H:i:s')]);
        removeFollow($pdo, $uid, $targetId);
        removeFollow($pdo, $targetId, $uid);
        $pdo->prepare('DELETE FROM notifications WHERE (recipient_id = ? AND actor_id = ?) OR (recipient_id = ? AND actor_id = ?)')
            ->execute([$uid, $targetId, $targetId, $uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    respond(good(['blocked' => true]));
}

// Restores visibility only; follows removed by the block stay removed.
function handle_unblockUser($pdo, $user) {
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0) bad('Invalid user ID', 400);
    $pdo->prepare('DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?')->execute([$user['sub'], $targetId]);
    respond(good(['blocked' => false]));
}

// The people *I* blocked. Never who blocked me.
function handle_getBlockedUsers($pdo, $user) {
    $stmt = $pdo->prepare('SELECT b.blocked_id AS id, u.email, b.created_at FROM blocks b
        JOIN users u ON u.id = b.blocked_id WHERE b.blocker_id = ? ORDER BY b.created_at DESC');
    $stmt->execute([$user['sub']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['id'] = (int)$r['id'];
    respond(good(['users' => $rows]));
}

// Records that the user accepted the current Terms of Use. version must be
// the current one, so an app showing outdated terms can't record acceptance
// of the new ones.
function handle_acceptTerms($pdo, $user) {
    global $CONFIG;
    $version = (int)($_POST['version'] ?? 0);
    if ($version !== $CONFIG['terms_version']) bad('Those terms are out of date. Reload and try again.', 400);
    $pdo->prepare('UPDATE users SET terms_version_accepted = ?, terms_accepted_at = ? WHERE id = ?')
        ->execute([$version, date('Y-m-d H:i:s'), $user['sub']]);
    respond(good(['termsVersionAccepted' => $version]));
}

// ============== STAFF: THE MODERATION PAGE ==============
// Staff roles plan. Everything below is what the website's /admin page calls.
// Freezing happens only here, by resolving a report (R10): there is no direct
// freeze endpoint. Roles come from src/Staff/handlers.php; the server checks
// every rule, the apps only hide buttons.

// First 200 characters, for the activity log.
function snapshotText($text) {
    if (!preg_match('/^.{0,200}/us', (string)$text, $m)) return (string)$text;
    return $m[0];
}

// status: open (default), resolved (dismissed or actioned), dismissed, actioned.
// Newest first. reportCount is how many open reports the same target has.
// Staff only see reports about people BELOW their rank (plus reports whose
// account is gone); the owner also sees reports about the owner itself, where
// the only option is Dismiss (R1, R2). Moderators don't see who reported (R6).
function handle_adminListReports($pdo, $user) {
    $me = requireRole($pdo, $user, 'moderator');
    [$limit, $offset] = pageParams();
    $status = $_POST['status'] ?? 'open';
    $where = ['open' => "r.status = 'open'", 'resolved' => "r.status != 'open'",
        'dismissed' => "r.status = 'dismissed'", 'actioned' => "r.status = 'actioned'"][$status] ?? null;
    if ($where === null) bad('Invalid status', 400);

    $rank = ROLE_RANK[$me['role']];
    $seen = array_keys(array_filter(ROLE_RANK, fn($r) => $r < $rank));
    if ($me['role'] === 'owner') $seen[] = 'owner';
    $roleList = "'" . implode("','", $seen) . "'";   // fixed role names, never input
    $rankWhere = "$where AND (tu.id IS NULL OR tu.role IN ($roleList))";

    $total = (int)$pdo->query("SELECT COUNT(*) FROM reports r LEFT JOIN users tu ON tu.id = r.target_user_id WHERE $rankWhere")->fetchColumn();
    $stmt = $pdo->prepare("SELECT r.*, rep.email AS reporter_email, tu.email AS target_user_email, tu.frozen_at AS target_frozen_at,
            tu.role AS target_role, res.email AS resolved_by_email,
            (SELECT COUNT(*) FROM reports o WHERE o.target_type = r.target_type AND o.target_id = r.target_id AND o.status = 'open') AS open_count,
            CASE r.target_type
                WHEN 'post' THEN EXISTS (SELECT 1 FROM posts p WHERE p.id = r.target_id)
                WHEN 'comment' THEN EXISTS (SELECT 1 FROM comments c WHERE c.id = CAST(r.target_id AS INTEGER))
                ELSE EXISTS (SELECT 1 FROM users u WHERE u.id = CAST(r.target_id AS INTEGER)) END AS target_exists,
            CASE r.target_type
                WHEN 'post' THEN (SELECT p.frozen_at FROM posts p WHERE p.id = r.target_id)
                WHEN 'comment' THEN (SELECT c.frozen_at FROM comments c WHERE c.id = CAST(r.target_id AS INTEGER))
                ELSE NULL END AS content_frozen_at
        FROM reports r
        LEFT JOIN users rep ON rep.id = r.reporter_id
        LEFT JOIN users tu ON tu.id = r.target_user_id
        LEFT JOIN users res ON res.id = r.resolved_by
        WHERE $rankWhere ORDER BY r.created_at DESC, r.id DESC LIMIT ? OFFSET ?");
    $stmt->execute([$limit, $offset]);

    $showReporter = $rank >= ROLE_RANK['admin'];
    $reports = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $reports[] = [
            'id' => (int)$r['id'],
            'targetType' => $r['target_type'],
            'targetId' => $r['target_id'],
            'targetUserId' => $r['target_user_id'] !== null ? (int)$r['target_user_id'] : null,
            'targetUserEmail' => $r['target_user_email'],
            'targetUserRole' => $r['target_user_email'] !== null && isset(ROLE_RANK[$r['target_role'] ?? '']) ? $r['target_role'] : ($r['target_user_email'] !== null ? 'user' : null),
            'targetUserFrozen' => $r['target_frozen_at'] !== null,
            'targetExists' => (bool)$r['target_exists'],
            'contentFrozen' => $r['content_frozen_at'] !== null,
            'reporterEmail' => $showReporter ? $r['reporter_email'] : null,
            'reason' => $r['reason'],
            'details' => $r['details'],
            'snapshot' => $r['snapshot'],
            'createdAt' => $r['created_at'],
            'status' => $r['status'],
            'reportCount' => (int)$r['open_count'],
            'resolvedAt' => $r['resolved_at'],
            'resolvedByEmail' => $r['resolved_by_email'],
            'resolution' => $r['resolution'],
        ];
    }
    respond(good(['reports' => $reports, 'hasMore' => ($offset + $limit) < $total, 'totalCount' => $total]));
}

// Suspends an account: it can't log in, every session ends now, and its posts
// and comments are hidden from everyone (frozen users are part of the
// visibility filter). Returns false for an unknown user.
function freezeUser($pdo, $userId) {
    $s = $pdo->prepare('UPDATE users SET frozen_at = COALESCE(frozen_at, ?) WHERE id = ?');
    $s->execute([date('Y-m-d H:i:s'), $userId]);
    if ($s->rowCount() === 0) return false;
    sessionRevokeAllForUser($pdo, $userId);
    return true;
}

// Freezes one post or comment (hidden from everyone but its author).
// Returns false if it doesn't exist.
function freezeContent($pdo, $type, $id, $byUserId) {
    $now = date('Y-m-d H:i:s');
    if ($type === 'post') {
        $s = $pdo->prepare('UPDATE posts SET frozen_at = COALESCE(frozen_at, ?), frozen_by = COALESCE(frozen_by, ?) WHERE id = ?');
        $s->execute([$now, $byUserId, $id]);
    } else {
        $s = $pdo->prepare('UPDATE comments SET frozen_at = COALESCE(frozen_at, ?), frozen_by = COALESCE(frozen_by, ?) WHERE id = ?');
        $s->execute([$now, $byUserId, (int)$id]);
    }
    return $s->rowCount() > 0;
}

// resolution: dismiss | freeze_content | freeze_user | delete_content |
// delete_and_freeze. The only way to freeze anything (R10). Moderators may
// dismiss and freeze; deleting is admin and up. Every resolution first checks
// the caller outranks the reported account (R1-R3) -- the one exception is
// the owner dismissing a report about the owner. Resolving one report
// resolves every open report on the same target the same way.
function handle_adminResolveReport($pdo, $user) {
    $me = requireRole($pdo, $user, 'moderator');
    $reportId = (int)($_POST['reportId'] ?? 0);
    // 'resolution', not 'action': every request's action field is already the endpoint name.
    $act = $_POST['resolution'] ?? '';
    $note = trim((string)($_POST['note'] ?? ''));
    if (!in_array($act, ['dismiss', 'freeze_content', 'freeze_user', 'delete_content', 'delete_and_freeze'], true)) bad('Invalid action', 400);
    if (strlen($note) > 500) bad('Note is too long (max 500 characters)', 400);

    $s = $pdo->prepare('SELECT * FROM reports WHERE id = ?');
    $s->execute([$reportId]);
    $report = $s->fetch(PDO::FETCH_ASSOC);
    if (!$report) bad('Report not found', 404);

    $deletes = in_array($act, ['delete_content', 'delete_and_freeze'], true);
    $freezes = in_array($act, ['freeze_user', 'delete_and_freeze'], true);
    if ($deletes && ROLE_RANK[$me['role']] < ROLE_RANK['admin']) bad('Admins only', 403);

    $targetUserId = $report['target_user_id'] !== null ? (int)$report['target_user_id'] : null;
    $target = $targetUserId !== null ? userRole($pdo, $targetUserId) : null;
    $ownerDismissingOwn = $act === 'dismiss' && $me['role'] === 'owner' && $target && $target['id'] === $me['id'];
    if (!$ownerDismissingOwn) requireOutranks($me, $target);

    if (($deletes || $act === 'freeze_content') && $report['target_type'] === 'user') {
        bad($act === 'freeze_content'
            ? 'A user report has no post or comment to freeze. Freeze the account instead.'
            : 'A user report has no content to delete. Freeze the user instead.', 400);
    }
    if ($freezes && $target === null) bad('That account no longer exists', 400);

    if ($act === 'freeze_content' && !freezeContent($pdo, $report['target_type'], $report['target_id'], $me['id'])) {
        bad('That content no longer exists', 400);
    }
    if ($deletes) {
        if ($report['target_type'] === 'post') deletePostById($pdo, $report['target_id']);
        else deleteCommentById($pdo, $report['target_id']);
    }
    if ($freezes) freezeUser($pdo, $targetUserId);

    $resolution = $act . ($note !== '' ? ": $note" : '');
    $pdo->prepare("UPDATE reports SET status = ?, resolved_at = ?, resolved_by = ?, resolution = ?
        WHERE target_type = ? AND target_id = ? AND (status = 'open' OR id = ?)")
        ->execute([$act === 'dismiss' ? 'dismissed' : 'actioned', date('Y-m-d H:i:s'), $user['sub'], $resolution,
            $report['target_type'], $report['target_id'], $reportId]);

    logStaffAction($pdo, $me, 'resolve_report', $target, [
        'reportId' => $reportId, 'resolution' => $act, 'type' => $report['target_type'],
        'text' => snapshotText($report['snapshot'] ?? ''),
    ]);
    writeLog('WARN', 'api', "moderation: {$me['role']} {$user['sub']} resolved report #$reportId ({$report['target_type']} {$report['target_id']}) with $act");
    respond(good(['resolved' => true]));
}

function handle_adminUnfreezeUser($pdo, $user) {
    $me = requireRole($pdo, $user, 'moderator');
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0) bad('Invalid user ID', 400);
    $target = userRole($pdo, $targetId);
    if ($target === null) bad('User not found', 404);
    requireOutranks($me, $target);
    $pdo->prepare('UPDATE users SET frozen_at = NULL WHERE id = ?')->execute([$targetId]);
    logStaffAction($pdo, $me, 'unfreeze_user', $target);
    writeLog('WARN', 'api', "moderation: {$me['role']} {$user['sub']} unfroze user $targetId");
    respond(good(['frozen' => false]));
}

// ---- The Frozen tab ----

// Roles strictly below $me's, as a quoted SQL list (fixed names, never input).
function rolesBelowSql(array $me): string {
    $below = array_keys(array_filter(ROLE_RANK, fn($r) => $r < ROLE_RANK[$me['role']]));
    return "'" . implode("','", $below) . "'";
}

// Frozen accounts, posts and comments the caller outranks, newest first.
// {type, id, userId, email, text, mediaUrl, frozenAt, frozenByEmail}; an
// account has no text, media or frozen-by (users.frozen_at is all there is).
function handle_adminListFrozen($pdo, $user) {
    $me = requireRole($pdo, $user, 'moderator');
    [$limit, $offset] = pageParams();
    $below = rolesBelowSql($me);
    $union = "SELECT 'user' AS type, CAST(u.id AS TEXT) AS id, u.id AS user_id, u.email AS email, NULL AS text, NULL AS media_url,
            u.frozen_at AS frozen_at, NULL AS frozen_by_email
        FROM users u WHERE u.frozen_at IS NOT NULL AND u.role IN ($below)
      UNION ALL
        SELECT 'post', p.id, p.user_id, u.email, p.text, p.media_url, p.frozen_at, fb.email
        FROM posts p JOIN users u ON u.id = p.user_id LEFT JOIN users fb ON fb.id = p.frozen_by
        WHERE p.frozen_at IS NOT NULL AND u.role IN ($below)
      UNION ALL
        SELECT 'comment', CAST(c.id AS TEXT), c.user_id, u.email, c.comment_text, NULL, c.frozen_at, fb.email
        FROM comments c JOIN users u ON u.id = c.user_id LEFT JOIN users fb ON fb.id = c.frozen_by
        WHERE c.frozen_at IS NOT NULL AND u.role IN ($below)";
    $total = (int)$pdo->query("SELECT COUNT(*) FROM ($union)")->fetchColumn();
    $stmt = $pdo->prepare("SELECT * FROM ($union) ORDER BY frozen_at DESC, type, id LIMIT ? OFFSET ?");
    $stmt->execute([$limit, $offset]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'type' => $r['type'],
            'id' => $r['type'] === 'comment' || $r['type'] === 'user' ? (int)$r['id'] : $r['id'],
            'userId' => (int)$r['user_id'],
            'email' => $r['email'],
            'text' => $r['text'] !== null ? snapshotText($r['text']) : null,
            'mediaUrl' => ($r['media_url'] ?? null) && $r['media_url'] !== 'null' ? $r['media_url'] : null,
            'frozenAt' => $r['frozen_at'],
            'frozenByEmail' => $r['frozen_by_email'],
        ];
    }
    respond(good(['items' => $items, 'hasMore' => ($offset + $limit) < $total, 'totalCount' => $total]));
}

// A post or comment by type and id: ['type', 'id', 'userId', 'text', 'frozen'],
// or null. The id is a string for posts and an int for comments.
function contentRow($pdo, $type, $id): ?array {
    if ($type === 'post') {
        $s = $pdo->prepare('SELECT id, user_id, text, frozen_at FROM posts WHERE id = ?');
        $s->execute([(string)$id]);
    } elseif ($type === 'comment') {
        $s = $pdo->prepare('SELECT id, user_id, comment_text AS text, frozen_at FROM comments WHERE id = ?');
        $s->execute([(int)$id]);
    } else {
        bad('Invalid content type', 400);
    }
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return ['type' => $type, 'id' => $r['id'], 'userId' => (int)$r['user_id'], 'text' => (string)$r['text'], 'frozen' => $r['frozen_at'] !== null];
}

function handle_adminUnfreezeContent($pdo, $user) {
    $me = requireRole($pdo, $user, 'moderator');
    $type = $_POST['type'] ?? '';
    $row = contentRow($pdo, $type, $_POST['id'] ?? '');
    if ($row === null) bad($type === 'post' ? 'Post not found' : 'Comment not found', 404);
    $author = userRole($pdo, $row['userId']);
    requireOutranks($me, $author);
    if ($type === 'post') $pdo->prepare('UPDATE posts SET frozen_at = NULL, frozen_by = NULL WHERE id = ?')->execute([$row['id']]);
    else $pdo->prepare('UPDATE comments SET frozen_at = NULL, frozen_by = NULL WHERE id = ?')->execute([$row['id']]);
    logStaffAction($pdo, $me, 'unfreeze_content', $author, ['type' => $type, 'text' => snapshotText($row['text'])]);
    respond(good(['frozen' => false]));
}

// Permanent. Frozen posts and comments only (R10): report it first.
function handle_adminDeleteContent($pdo, $user) {
    $me = requireRole($pdo, $user, 'admin');
    $type = $_POST['type'] ?? '';
    $row = contentRow($pdo, $type, $_POST['id'] ?? '');
    if ($row === null) bad($type === 'post' ? 'Post not found' : 'Comment not found', 404);
    if (!$row['frozen']) bad('Only frozen posts and comments can be deleted here. Report it first.', 400);
    $author = userRole($pdo, $row['userId']);
    requireOutranks($me, $author);
    logStaffAction($pdo, $me, 'delete_content', $author, ['type' => $type, 'text' => snapshotText($row['text'])]);
    if ($type === 'post') deletePostById($pdo, $row['id']);
    else deleteCommentById($pdo, $row['id']);
    respond(good(['deleted' => true]));
}

// Permanent (R5): the account must be frozen first, and the admin types its
// email to confirm.
function handle_adminDeleteAccount($pdo, $user) {
    $me = requireRole($pdo, $user, 'admin');
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0) bad('Invalid user ID', 400);
    $target = userRole($pdo, $targetId);
    if ($target === null) bad('User not found', 404);
    requireOutranks($me, $target);
    if (!$target['frozen']) bad('Freeze this account before deleting it', 400);
    if (strcasecmp(trim((string)($_POST['confirmEmail'] ?? '')), $target['email']) !== 0) bad("The email doesn't match", 400);
    // Logged before deleting, so the email is still on record.
    logStaffAction($pdo, $me, 'delete_account', $target);
    deleteUserAndData($pdo, $targetId);
    respond(good(['deleted' => true]));
}
