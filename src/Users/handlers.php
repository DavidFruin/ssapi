<?php
// src/Users/handlers.php - Users module: profile lookups and the two
// per-account display preferences (theme, hand).
//
// Loaded via Composer's "files" autoload (see composer.json), same as
// src/Auth/handlers.php - global-namespace functions, not classes: this
// is a folder-level reorganization of the existing procedural api.php,
// not a rewrite into an OOP structure.
//
// Depends on shared Core helpers still defined in api.php: bad(), good(),
// respond().

function handle_getUserInfo($pdo, $user) {
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0) bad('Invalid user ID', 400);
    if (isHiddenFrom($pdo, $user['sub'], $targetId)) bad('User not found', 404);

    $stmt = $pdo->prepare('SELECT email, created_at FROM users WHERE id = ?');
    $stmt->execute([$targetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) bad('User not found', 404);

    respond(good(['email' => $row['email'], 'created_at' => $row['created_at'] ?: 'Unknown']));
}

function handle_getUsers($pdo, $user) {
    // Search and @mention suggestions both come from this list, so hidden
    // users (blocked either way, or frozen) can't be found or tagged.
    [$hf, $hp] = hiddenFilter($pdo, $user['sub'], 'id');
    $stmt = $pdo->prepare("SELECT id, email, created_at FROM users WHERE id != ?$hf ORDER BY email ASC");
    $stmt->execute(array_merge([$user['sub']], $hp));
    respond(good(['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]));
}

function handle_getUserEmails($pdo, $user) {
    // 500, not 100 -- a popular post's likers list can legitimately be long.
    $userIds = jsonIdList('userIds', 500, true);
    if (empty($userIds)) respond(good(['emails' => []]));

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    [$hf, $hp] = hiddenFilter($pdo, $user['sub'], 'id');
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id IN ($placeholders)$hf");
    $stmt->execute(array_merge($userIds, $hp));
    $emails = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $emails[$row['id']] = $row['email'];
    respond(good(['emails' => $emails]));
}

function handle_getMyInfo($pdo, $user) {
    global $CONFIG;
    $stmt = $pdo->prepare('SELECT id, email, created_at, theme, hand, is_admin, terms_version_accepted, lang FROM users WHERE id = ?');
    $stmt->execute([$user['sub']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    respond(good(['id' => $user['sub'], 'userId' => $user['sub'], 'email' => $row['email'] ?? 'User', 'created_at' => $row['created_at'] ?? 'Unknown', 'theme' => $row['theme'] ?: 'light', 'hand' => $row['hand'] ?: 'right',
        'lang' => $row['lang'] ?? null,
        'isAdmin' => (int)($row['is_admin'] ?? 0) === 1,
        'termsVersionAccepted' => (int)($row['terms_version_accepted'] ?? 0),
        'termsVersionCurrent' => $CONFIG['terms_version'],
        'termsUrl' => $CONFIG['terms_url'],
        // Additive. 'welcome' is only present until the new member dismisses
        // it, and is the one place their inviter's email is shown to them.
        'registrationMode' => registrationNeedsInvite() ? 'invite' : 'open']
        + (($welcome = welcomeFor($pdo, (int)$user['sub'])) !== null ? ['welcome' => $welcome] : [])));
}

function handle_updateTheme($pdo, $user) {
    $theme = $_POST['theme'] ?? '';
    $allowedThemes = ['light', 'dark', 'red', 'blue', 'hacker', 'gray'];
    if (!in_array($theme, $allowedThemes, true)) bad('Invalid theme', 400);

    $stmt = $pdo->prepare('UPDATE users SET theme = ? WHERE id = ?');
    $stmt->execute([$theme, $user['sub']]);
    respond(good(['message' => 'Theme updated']));
}

function handle_updateLanguage($pdo, $user) {
    $lang = $_POST['lang'] ?? '';
    if (!in_array($lang, SUPPORTED_LANGS, true)) bad('Invalid language', 400);

    $stmt = $pdo->prepare('UPDATE users SET lang = ? WHERE id = ?');
    $stmt->execute([$lang, $user['sub']]);
    respond(good(['message' => 'Language updated']));
}

function handle_updateHand($pdo, $user) {
    $hand = $_POST['hand'] ?? '';
    if (!in_array($hand, ['left', 'right'], true)) bad('Invalid hand', 400);

    $stmt = $pdo->prepare('UPDATE users SET hand = ? WHERE id = ?');
    $stmt->execute([$hand, $user['sub']]);
    respond(good(['message' => 'Hand updated']));
}
