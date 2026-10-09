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
