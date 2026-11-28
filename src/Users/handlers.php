<?php
// src/Users/handlers.php - Users module: profile lookups, the username, and
// the two per-account display preferences (theme, hand).
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

    $stmt = $pdo->prepare('SELECT ' . nameSql('u') . ' AS email, u.created_at, u.role FROM users u WHERE u.id = ?');
    $stmt->execute([$targetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) bad('User not found', 404);

    // Roles are public (staff roles plan R7): every profile shows one.
    respond(good(['email' => $row['email'], 'created_at' => $row['created_at'] ?: 'Unknown',
        'role' => isset(ROLE_RANK[$row['role'] ?? '']) ? $row['role'] : 'user']));
}

function handle_getUsers($pdo, $user) {
    // Search and @mention suggestions both come from this list, so hidden
    // users (blocked either way, or frozen) can't be found or tagged.
    [$hf, $hp] = hiddenFilter($pdo, $user['sub'], 'id');
    $name = nameSql('users');
    $stmt = $pdo->prepare("SELECT id, $name AS email, created_at FROM users WHERE id != ?$hf ORDER BY LOWER($name) ASC");
    $stmt->execute(array_merge([$user['sub']], $hp));
    respond(good(['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]));
}

function handle_getUserEmails($pdo, $user) {
    // 500, not 100 -- a popular post's likers list can legitimately be long.
    $userIds = jsonIdList('userIds', 500, true);
    if (empty($userIds)) respond(good(['emails' => []]));

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    [$hf, $hp] = hiddenFilter($pdo, $user['sub'], 'id');
    $stmt = $pdo->prepare('SELECT id, ' . nameSql('users') . " AS email FROM users WHERE id IN ($placeholders)$hf");
    $stmt->execute(array_merge($userIds, $hp));
    $emails = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $emails[$row['id']] = $row['email'];
    respond(good(['emails' => $emails]));
}

function handle_getMyInfo($pdo, $user) {
    global $CONFIG;
    $stmt = $pdo->prepare('SELECT id, email, username, created_at, created_tz, theme, hand, role, terms_version_accepted, lang FROM users WHERE id = ?');
    $stmt->execute([$user['sub']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    respond(good(['id' => $user['sub'], 'userId' => $user['sub'], 'email' => $row['email'] ?? 'User', 'created_at' => $row['created_at'] ?? 'Unknown', 'created_tz' => $row['created_tz'] ?? null, 'theme' => $row['theme'] ?: 'light', 'hand' => $row['hand'] ?: 'right',
        'lang' => $row['lang'] ?? null,
        // Usernames: your own email stays as it is; username is null until
        // you choose one, and then the apps stop asking.
        'username' => $row['username'] ?? null,
        'needsUsername' => ($row['username'] ?? null) === null,
        // Staff roles plan: role is public; isAdmin stays for older app builds
        // (true for admin and owner).
        'role' => isset(ROLE_RANK[$row['role'] ?? '']) ? $row['role'] : 'user',
        'isAdmin' => in_array($row['role'] ?? '', ['admin', 'owner'], true),
        'termsVersionAccepted' => (int)($row['terms_version_accepted'] ?? 0),
        'termsVersionCurrent' => $CONFIG['terms_version'],
        'termsUrl' => $CONFIG['terms_url'],
        // Additive. 'welcome' is only present until the new member dismisses
        // it, and is the one place their inviter's name is shown to them.
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

// The username rule (Dave, 2026-11-20 and 2026-11-26): letters and
// underscores, 3 to 20, unique ignoring case. No reserved names (Dave,
// 2026-11-27). Stops the request with the reason when the name can't be used.
function requireUsableUsername($pdo, string $name, int $exceptUserId = 0): void {
    if (!preg_match('/^[A-Za-z_]{3,20}$/', $name)) bad('A username is 3 to 20 letters or underscores.', 400);
    $s = $pdo->prepare('SELECT 1 FROM users WHERE username = ? AND id != ?');
    $s->execute([$name, $exceptUserId]);
    if ($s->fetchColumn()) bad('That username is taken.', 409);
}

// Choose or change your username, any time. Mentions are stored as @[id],
// so they show the new name at once.
function handle_setUsername($pdo, $user) {
    $name = trim((string)($_POST['username'] ?? ''));
    requireUsableUsername($pdo, $name, (int)$user['sub']);
    try {
        $pdo->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$name, (int)$user['sub']]);
    } catch (PDOException $e) {
        // Someone took it between the check and the write (the unique index).
        bad('That username is taken.', 409);
    }
    respond(good(['username' => $name]));
}
