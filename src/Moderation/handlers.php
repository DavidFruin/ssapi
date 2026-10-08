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

const REPORT_REASONS = ['spam', 'harassment', 'hate', 'sexual', 'violence', 'self_harm', 'illegal', 'other'];

// Any logged-in user can report a post, a comment or a user. One report per
// reporter per target (a repeat is accepted silently, no second row). The
// reported post/comment is hidden from the reporter at once (the visibility
// filter reads the reports table), and the admin is emailed so the roughly
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

// ============== ADMIN ==============
// users.is_admin is set by hand with sqlite3 on the server; there is no API
// to grant it.
function requireAdmin($pdo, $user) {
    $s = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
    $s->execute([$user['sub']]);
    if ((int)$s->fetchColumn() !== 1) bad('Admins only', 403);
}

// status: open (default), resolved (dismissed or actioned), dismissed, actioned.
// Newest first. reportCount is how many open reports the same target has.
function handle_adminListReports($pdo, $user) {
    requireAdmin($pdo, $user);
    [$limit, $offset] = pageParams();
    $status = $_POST['status'] ?? 'open';
    $where = ['open' => "r.status = 'open'", 'resolved' => "r.status != 'open'",
        'dismissed' => "r.status = 'dismissed'", 'actioned' => "r.status = 'actioned'"][$status] ?? null;
    if ($where === null) bad('Invalid status', 400);

    $total = (int)$pdo->query("SELECT COUNT(*) FROM reports r WHERE $where")->fetchColumn();
    $stmt = $pdo->prepare("SELECT r.*, rep.email AS reporter_email, tu.email AS target_user_email, tu.frozen_at AS target_frozen_at,
            res.email AS resolved_by_email,
            (SELECT COUNT(*) FROM reports o WHERE o.target_type = r.target_type AND o.target_id = r.target_id AND o.status = 'open') AS open_count,
            CASE r.target_type
                WHEN 'post' THEN EXISTS (SELECT 1 FROM posts p WHERE p.id = r.target_id)
                WHEN 'comment' THEN EXISTS (SELECT 1 FROM comments c WHERE c.id = CAST(r.target_id AS INTEGER))
                ELSE EXISTS (SELECT 1 FROM users u WHERE u.id = CAST(r.target_id AS INTEGER)) END AS target_exists
        FROM reports r
        LEFT JOIN users rep ON rep.id = r.reporter_id
        LEFT JOIN users tu ON tu.id = r.target_user_id
        LEFT JOIN users res ON res.id = r.resolved_by
        WHERE $where ORDER BY r.created_at DESC, r.id DESC LIMIT ? OFFSET ?");
    $stmt->execute([$limit, $offset]);

    $reports = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $reports[] = [
            'id' => (int)$r['id'],
            'targetType' => $r['target_type'],
            'targetId' => $r['target_id'],
            'targetUserId' => $r['target_user_id'] !== null ? (int)$r['target_user_id'] : null,
            'targetUserEmail' => $r['target_user_email'],
            'targetUserFrozen' => $r['target_frozen_at'] !== null,
            'targetExists' => (bool)$r['target_exists'],
            'reporterEmail' => $r['reporter_email'],
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

// action: dismiss | delete_content | freeze_user | delete_and_freeze.
// Resolving one report resolves every open report on the same target the
// same way.
function handle_adminResolveReport($pdo, $user) {
    requireAdmin($pdo, $user);
    $reportId = (int)($_POST['reportId'] ?? 0);
    // 'resolution', not 'action': every request's action field is already the endpoint name.
    $act = $_POST['resolution'] ?? '';
    $note = trim((string)($_POST['note'] ?? ''));
    if (!in_array($act, ['dismiss', 'delete_content', 'freeze_user', 'delete_and_freeze'], true)) bad('Invalid action', 400);
    if (strlen($note) > 500) bad('Note is too long (max 500 characters)', 400);

    $s = $pdo->prepare('SELECT * FROM reports WHERE id = ?');
    $s->execute([$reportId]);
    $report = $s->fetch(PDO::FETCH_ASSOC);
    if (!$report) bad('Report not found', 404);

    $deletes = in_array($act, ['delete_content', 'delete_and_freeze'], true);
    $freezes = in_array($act, ['freeze_user', 'delete_and_freeze'], true);
    if ($deletes && $report['target_type'] === 'user') bad('A user report has no content to delete. Freeze the user instead.', 400);
    $targetUserId = $report['target_user_id'] !== null ? (int)$report['target_user_id'] : null;
    if ($freezes && $targetUserId === null) bad('That account no longer exists', 400);
    if ($freezes && $targetUserId === (int)$user['sub']) bad("You can't freeze your own account", 400);

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

    writeLog('WARN', 'api', "moderation: admin {$user['sub']} resolved report #$reportId ({$report['target_type']} {$report['target_id']}) with $act");
    respond(good(['resolved' => true]));
}

function handle_adminFreezeUser($pdo, $user) {
    requireAdmin($pdo, $user);
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0) bad('Invalid user ID', 400);
    if ($targetId === (int)$user['sub']) bad("You can't freeze your own account", 400);
    if (!freezeUser($pdo, $targetId)) bad('User not found', 404);
    writeLog('WARN', 'api', "moderation: admin {$user['sub']} froze user $targetId");
    respond(good(['frozen' => true]));
}

function handle_adminUnfreezeUser($pdo, $user) {
    requireAdmin($pdo, $user);
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0) bad('Invalid user ID', 400);
    $s = $pdo->prepare('UPDATE users SET frozen_at = NULL WHERE id = ?');
    $s->execute([$targetId]);
    if ($s->rowCount() === 0) bad('User not found', 404);
    writeLog('WARN', 'api', "moderation: admin {$user['sub']} unfroze user $targetId");
    respond(good(['frozen' => false]));
}
