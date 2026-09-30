<?php
// src/Users/handlers.php - Users module: profile lookups and the two
// per-account display preferences (theme, hand).
//
// Loaded via Composer's "files" autoload (see composer.json), same as
// src/Auth/handlers.php - global-namespace functions, not classes. See
// .claude/commands/split-backend-modules.md for why.
//
// Depends on shared Core helpers still defined in api.php: bad(), good(),
// respond().

function handle_getUserInfo($pdo, $user) {
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0) bad('Invalid user ID', 400);

    $stmt = $pdo->prepare('SELECT email, created_at FROM users WHERE id = ?');
    $stmt->execute([$targetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) bad('User not found', 404);

    respond(good(['email' => $row['email'], 'created_at' => $row['created_at'] ?: 'Unknown']));
}

function handle_getUsers($pdo, $user) {
    $stmt = $pdo->prepare('SELECT id, email, created_at FROM users WHERE id != ? ORDER BY email ASC');
    $stmt->execute([$user['sub']]);
    respond(good(['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]));
}

function handle_getUserEmails($pdo, $user) {
    $userIdsJson = $_POST['userIds'] ?? '[]';
    $userIds = json_decode($userIdsJson, true);
    if (!is_array($userIds) || empty($userIds)) respond(good(['emails' => []]));

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id IN ($placeholders)");
    $stmt->execute($userIds);
    $emails = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $emails[$row['id']] = $row['email'];
    respond(good(['emails' => $emails]));
}

function handle_getMyInfo($pdo, $user) {
    $stmt = $pdo->prepare('SELECT id, email, created_at, theme, hand FROM users WHERE id = ?');
    $stmt->execute([$user['sub']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    respond(good(['id' => $user['sub'], 'userId' => $user['sub'], 'email' => $row['email'] ?? 'User', 'created_at' => $row['created_at'] ?? 'Unknown', 'theme' => $row['theme'] ?: 'light', 'hand' => $row['hand'] ?: 'right']));
}

function handle_updateTheme($pdo, $user) {
    $theme = $_POST['theme'] ?? '';
    $allowedThemes = ['light', 'dark', 'red', 'blue', 'hacker'];
    if (!in_array($theme, $allowedThemes, true)) bad('Invalid theme', 400);

    $stmt = $pdo->prepare('UPDATE users SET theme = ? WHERE id = ?');
    $stmt->execute([$theme, $user['sub']]);
    respond(good(['message' => 'Theme updated']));
}

function handle_updateHand($pdo, $user) {
    $hand = $_POST['hand'] ?? '';
    if (!in_array($hand, ['left', 'right'], true)) bad('Invalid hand', 400);

    $stmt = $pdo->prepare('UPDATE users SET hand = ? WHERE id = ?');
    $stmt->execute([$hand, $user['sub']]);
    respond(good(['message' => 'Hand updated']));
}
