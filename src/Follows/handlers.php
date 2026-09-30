<?php
// src/Follows/handlers.php - Follows module: follower/following lists and
// the follow/unfollow toggle. Follows are still stored as a JSON array on
// users.follows (never migrated to a real table the way posts/likes were),
// so every handler here does its own decode/filter/re-encode round trip.
//
// Loaded via Composer's "files" autoload (see composer.json), same as the
// other modules - global-namespace functions, not classes.
//
// Depends on shared Core helpers still defined in api.php: bad(), good(),
// respond(), createNotification().

function handle_getMyFollowers($pdo, $user) {
    $targetId = isset($_POST['userId']) ? (int)$_POST['userId'] : $user['sub'];
    if ($targetId <= 0) bad('Invalid user ID', 400);

    $stmt = $pdo->prepare('SELECT id, email, follows FROM users WHERE id != ?');
    $stmt->execute([$targetId]);
    $followers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $followsData = $row['follows'] ? json_decode($row['follows'], true) : [];
        foreach ($followsData as $f) {
            if ((is_array($f) ? $f['id'] : $f) == $targetId) {
                $followers[] = ['id' => $row['id'], 'email' => $row['email'], 'timestamp' => is_array($f) ? $f['timestamp'] : 'Unknown'];
                break;
            }
        }
    }
    respond(good(['followers' => $followers]));
}

function handle_getMyFollows($pdo, $user) {
    $targetId = isset($_POST['userId']) ? (int)$_POST['userId'] : $user['sub'];
    if ($targetId <= 0) bad('Invalid user ID', 400);

    $stmt = $pdo->prepare('SELECT follows FROM users WHERE id = ?');
    $stmt->execute([$targetId]);
    $followsJson = $stmt->fetchColumn() ?: '[]';
    $followsData = json_decode($followsJson, true) ?? [];
    $result = [];
    $ids = array_map(fn($f) => is_array($f) ? $f['id'] : $f, $followsData);

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $u) {
            foreach ($followsData as $f) {
                if ((is_array($f) ? $f['id'] : $f) == $u['id']) {
                    $result[] = ['id' => $u['id'], 'email' => $u['email'], 'timestamp' => is_array($f) ? $f['timestamp'] : 'Unknown'];
                    break;
                }
            }
        }
    }
    respond(good(['follows' => $result]));
}

function handle_followUser($pdo, $user) {
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0 || $targetId == $user['sub']) bad('Invalid user ID', 400);

    $uid = $user['sub'];
    $stmt = $pdo->prepare('SELECT follows, email FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $followsJson = $row['follows'] ?: '[]';
    $actorEmail = $row['email'];
    $follows = json_decode($followsJson, true) ?? [];

    $already = false;
    foreach ($follows as $f) if ((is_array($f) ? $f['id'] : $f) == $targetId) $already = true;
    if (!$already) {
        $follows[] = ['id' => $targetId, 'timestamp' => date('Y-m-d H:i:s')];
        $stmt = $pdo->prepare('UPDATE users SET follows = ? WHERE id = ?');
        $stmt->execute([json_encode($follows), $uid]);
        createNotification($pdo, $targetId, $uid, $actorEmail, 'follow');
    }

    respond(good(['following' => true]));
}

function handle_unfollowUser($pdo, $user) {
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0 || $targetId == $user['sub']) bad('Invalid user ID', 400);

    $uid = $user['sub'];
    $stmt = $pdo->prepare('SELECT follows, email FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $followsJson = $row['follows'] ?: '[]';
    $actorEmail = $row['email'];
    $follows = json_decode($followsJson, true) ?? [];

    $follows = array_filter($follows, fn($f) => (is_array($f) ? $f['id'] : $f) != $targetId);
    $follows = array_values($follows);
    $stmt = $pdo->prepare('UPDATE users SET follows = ? WHERE id = ?');
    $stmt->execute([json_encode($follows), $uid]);

    createNotification($pdo, $targetId, $uid, $actorEmail, 'unfollow');

    respond(good(['following' => false]));
}

function handle_isFollowing($pdo, $user) {
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0 || $targetId == $user['sub']) bad('Invalid user ID', 400);

    $stmt = $pdo->prepare('SELECT follows FROM users WHERE id = ?');
    $stmt->execute([$user['sub']]);
    $followsJson = $stmt->fetchColumn() ?: '[]';
    $follows = json_decode($followsJson, true) ?? [];

    $is = false;
    foreach ($follows as $f) if ((is_array($f) ? $f['id'] : $f) == $targetId) $is = true;

    respond(good(['following' => $is]));
}
