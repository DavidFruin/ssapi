<?php
// src/Staff/handlers.php - Staff roles (staff roles plan): user < moderator <
// admin < owner. Roles are public (every profile shows one) and are read from
// the database on every request, so a change takes effect on the person's next
// request with no new login. The server enforces every rule; the apps only
// hide buttons people can't use. The owner is set by hand with sqlite3 and no
// endpoint ever sets or removes it.
//
// Loaded via Composer's "files" autoload (see composer.json). Depends on
// shared Core helpers in api.php: bad(), good(), respond(), pageParams().

const ROLES = ['user', 'moderator', 'admin', 'owner'];
const ROLE_RANK = ['user' => 0, 'moderator' => 1, 'admin' => 2, 'owner' => 3];

// ['id', 'email', 'role', 'frozen'], or null for an unknown id. An unknown
// role value in the database counts as 'user'.
function userRole($pdo, int $userId): ?array {
    $s = $pdo->prepare('SELECT id, email, role, frozen_at FROM users WHERE id = ?');
    $s->execute([$userId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return [
        'id' => (int)$r['id'],
        'email' => $r['email'],
        'role' => isset(ROLE_RANK[$r['role'] ?? '']) ? $r['role'] : 'user',
        'frozen' => $r['frozen_at'] !== null,
    ];
}

// Stops the request unless the caller has at least $min. Returns the caller.
function requireRole($pdo, $user, string $min): array {
    $me = userRole($pdo, (int)$user['sub']);
    if (!$me || ROLE_RANK[$me['role']] < ROLE_RANK[$min]) {
        bad(['moderator' => 'Moderators only', 'admin' => 'Admins only', 'owner' => 'Only the owner can do that'][$min], 403);
    }
    return $me;
}

// Rules R1-R3 for any action on another person, their posts or their
// comments. Equal rank is refused, so this also covers yourself.
function requireOutranks(array $me, ?array $target): void {
    if ($target === null) return;   // the account is already gone
    if ($target['role'] === 'owner') bad("The owner's account, posts and comments can't be changed.", 403);
    if (ROLE_RANK[$me['role']] <= ROLE_RANK[$target['role']]) bad('You can only act on people below your own role.', 403);
}

// One row in the activity log. $details is stored as JSON. Emails are copied
// in so the log stays readable after an account is deleted.
function logStaffAction($pdo, array $me, string $action, ?array $target, array $details = []): void {
    $pdo->prepare('INSERT INTO staff_actions (actor_id, actor_email, action, target_user_id, target_email, details, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$me['id'], $me['email'], $action, $target['id'] ?? null, $target['email'] ?? null,
            $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, date('Y-m-d H:i:s')]);
}

// ---- Roles, the staff list, the activity log ----

// Appoint or remove staff. role: user | moderator | admin (never owner: that
// is set by hand on the server). The caller must outrank the target's current
// role AND the new role must be below the caller's: admins switch people
// between user and moderator, the owner also appoints and removes admins.
function handle_adminSetRole($pdo, $user) {
    $me = requireRole($pdo, $user, 'admin');
    $role = (string)($_POST['role'] ?? '');
    if ($role === 'owner') bad('Ownership can only be changed on the server.', 403);
    if (!in_array($role, ['user', 'moderator', 'admin'], true)) bad('Invalid role', 400);

    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0 && trim((string)($_POST['email'] ?? '')) !== '') {
        $s = $pdo->prepare('SELECT id FROM users WHERE lower(email) = lower(?)');
        $s->execute([trim($_POST['email'])]);
        $targetId = (int)$s->fetchColumn();
    }
    if ($targetId <= 0) bad('User not found', 404);
    $target = userRole($pdo, $targetId);
    if ($target === null) bad('User not found', 404);

    if ($target['id'] === $me['id']) bad("You can't change your own role", 400);
    if ($target['role'] === 'owner') bad('Ownership can only be changed on the server.', 403);
    $myRank = ROLE_RANK[$me['role']];
    if (ROLE_RANK[$target['role']] >= $myRank || ROLE_RANK[$role] >= $myRank) {
        $adminInvolved = $target['role'] === 'admin' || $role === 'admin';
        bad($adminInvolved ? 'Only the owner can appoint or remove admins.' : 'You can only act on people below your own role.', 403);
    }
    if ($target['frozen'] && $role !== 'user') bad('Unfreeze this account before giving it a role', 400);

    if ($target['role'] !== $role) {
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $targetId]);
        logStaffAction($pdo, $me, 'set_role', $target, ['from' => $target['role'], 'to' => $role]);
    }
    respond(good(['userId' => $targetId, 'email' => $target['email'], 'role' => $role]));
}

// Roles are public (R7), so any signed-in member can list the staff. Staff
// callers also see frozen staff (flagged), for the Team tab.
function handle_getStaff($pdo, $user) {
    $me = userRole($pdo, (int)$user['sub']);
    $isStaff = $me && ROLE_RANK[$me['role']] >= ROLE_RANK['moderator'];
    $stmt = $pdo->query("SELECT id, email, role, frozen_at FROM users
        WHERE role IN ('moderator', 'admin', 'owner')" . ($isStaff ? '' : ' AND frozen_at IS NULL') . "
        ORDER BY CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END, lower(email)");
    $staff = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $row = ['userId' => (int)$r['id'], 'email' => $r['email'], 'role' => $r['role']];
        if ($r['frozen_at'] !== null) $row['frozen'] = true;
        $staff[] = $row;
    }
    respond(good(['staff' => $staff]));
}

function handle_adminListActivity($pdo, $user) {
    requireRole($pdo, $user, 'admin');
    [$limit, $offset] = pageParams();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM staff_actions')->fetchColumn();
    $stmt = $pdo->prepare('SELECT * FROM staff_actions ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
    $stmt->execute([$limit, $offset]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'id' => (int)$r['id'],
            'actorEmail' => $r['actor_email'],
            'action' => $r['action'],
            'targetEmail' => $r['target_email'],
            'details' => $r['details'] !== null ? json_decode($r['details'], true) : null,
            'createdAt' => $r['created_at'],
        ];
    }
    respond(good(['items' => $items, 'hasMore' => ($offset + $limit) < $total, 'totalCount' => $total]));
}
