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
