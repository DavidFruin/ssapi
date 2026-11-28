<?php
// src/Follows/handlers.php - Follows module: follower/following lists and
// the follow/unfollow toggle, on the follows table (one row per follow).
//
// Loaded via Composer's "files" autoload (see composer.json), same as the
// other modules - global-namespace functions, not classes.
//
// Depends on shared Core helpers defined in api.php: bad(), good(),
// respond(), createNotification(), hiddenFilter(), isHiddenFrom().

// The people on one side of $targetId's follows, without anyone hidden from
// the viewer. $side is 'followers' (who follows the target) or 'follows' (who
// the target follows). 'timestamp' is "Unknown" for follows older than the
// time being recorded.
function followList($pdo, $viewerId, $targetId, $side) {
    [$mine, $theirs] = $side === 'followers' ? ['followed_id', 'follower_id'] : ['follower_id', 'followed_id'];
    [$hf, $hp] = hiddenFilter($pdo, $viewerId, 'u.id');
    $stmt = $pdo->prepare("SELECT u.id, COALESCE(u.username, u.email) AS email, f.created_at, f.created_tz FROM follows f JOIN users u ON u.id = f.$theirs
        WHERE f.$mine = ?$hf ORDER BY u.id");
    $stmt->execute(array_merge([$targetId], $hp));
    return array_map(fn($r) => ['id' => (int)$r['id'], 'email' => $r['email'], 'timestamp' => $r['created_at'] ?? 'Unknown', 'timestampTz' => $r['created_tz']],
        $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function followTarget($pdo, $user) {
    $targetId = isset($_POST['userId']) ? (int)$_POST['userId'] : (int)$user['sub'];
    if ($targetId <= 0) bad('Invalid user ID', 400);
    if (isHiddenFrom($pdo, $user['sub'], $targetId)) bad('User not found', 404);
    return $targetId;
}

function handle_getMyFollowers($pdo, $user) {
    respond(good(['followers' => followList($pdo, $user['sub'], followTarget($pdo, $user), 'followers')]));
}

function handle_getMyFollows($pdo, $user) {
    respond(good(['follows' => followList($pdo, $user['sub'], followTarget($pdo, $user), 'follows')]));
}

function handle_followUser($pdo, $user) {
    $targetId = (int)($_POST['userId'] ?? 0);
    $uid = (int)$user['sub'];
    if ($targetId <= 0 || $targetId === $uid) bad('Invalid user ID', 400);
    if (isHiddenFrom($pdo, $uid, $targetId)) bad('User not found', 404);
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
    $stmt->execute([$targetId]);
    if (!$stmt->fetchColumn()) bad('User not found', 404);

    // The primary key makes a second follow a no-op, so only a new follow
    // notifies.
    $insert = $pdo->prepare('INSERT IGNORE INTO follows (follower_id, followed_id, created_at, created_tz) VALUES (?, ?, ?, ?)');
    $insert->execute([$uid, $targetId, date('Y-m-d H:i:s'), clientTzOffset()]);
    if ($insert->rowCount() > 0) createNotification($pdo, $targetId, $uid, $user['email'], 'follow');

    respond(good(['following' => true]));
}

function handle_unfollowUser($pdo, $user) {
    $targetId = (int)($_POST['userId'] ?? 0);
    $uid = (int)$user['sub'];
    if ($targetId <= 0 || $targetId === $uid) bad('Invalid user ID', 400);

    // Only an unfollow that removed something notifies.
    $delete = $pdo->prepare('DELETE FROM follows WHERE follower_id = ? AND followed_id = ?');
    $delete->execute([$uid, $targetId]);
    if ($delete->rowCount() > 0) createNotification($pdo, $targetId, $uid, $user['email'], 'unfollow');

    respond(good(['following' => false]));
}

function handle_isFollowing($pdo, $user) {
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0 || $targetId == $user['sub']) bad('Invalid user ID', 400);

    $stmt = $pdo->prepare('SELECT 1 FROM follows WHERE follower_id = ? AND followed_id = ?');
    $stmt->execute([$user['sub'], $targetId]);
    respond(good(['following' => (bool)$stmt->fetchColumn()]));
}
