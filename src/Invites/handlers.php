<?php
// Invite codes (access-and-public-launch plan, Step 1). Every member can
// invite exactly one person; the owner (users.role = 'owner', set by hand)
// can invite as many as they like. A code is 8 characters, valid for 7 days,
// works once, and is only spent when someone completes registration with it.
// Used and expired codes are deleted, so nothing is left to guess.
// users.invited_by is never returned by any endpoint.
//
// Depends on shared Core helpers still defined in api.php: bad(), good(),
// respond(), and on the Auth module's attempt limiter (attemptKeys,
// checkAttemptLimit, recordFailedAttempt).

const INVITE_UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';   // no I, O
const INVITE_LOWER = 'abcdefghijkmnpqrstuvwxyz';   // no l, o
const INVITE_DIGITS = '23456789';                  // no 0, 1
const INVITE_SYMBOLS = '!$&@?';
const INVITE_LENGTH = 8;
const INVITE_DAYS = 7;

// 8 characters with at least one of each class; symbols never first or last.
function newInviteCode(): string {
    $all = INVITE_UPPER . INVITE_LOWER . INVITE_DIGITS . INVITE_SYMBOLS;
    $letters = INVITE_UPPER . INVITE_LOWER . INVITE_DIGITS;
    $pick = fn($set) => $set[random_int(0, strlen($set) - 1)];
    while (true) {
        $code = '';
        for ($i = 0; $i < INVITE_LENGTH; $i++) {
            $code .= $pick(($i === 0 || $i === INVITE_LENGTH - 1) ? $letters : $all);
        }
        if (preg_match('/[A-Z]/', $code) && preg_match('/[a-z]/', $code) && preg_match('/[0-9]/', $code)
            && strpbrk($code, INVITE_SYMBOLS) !== false) return $code;
    }
}

function deleteExpiredInvites($pdo): void {
    $pdo->prepare('DELETE FROM invite_codes WHERE expires_at <= ?')->execute([date('Y-m-d H:i:s')]);
}

function isOwnerRow(array $row): bool {
    return ($row['role'] ?? 'user') === 'owner';
}

// The caller's live codes, newest first.
function liveInviteCodes($pdo, int $userId): array {
    deleteExpiredInvites($pdo);
    $s = $pdo->prepare('SELECT code, expires_at FROM invite_codes WHERE created_by = ? ORDER BY created_at DESC, expires_at DESC');
    $s->execute([$userId]);
    return array_map(fn($r) => ['code' => $r['code'], 'expiresAt' => $r['expires_at']], $s->fetchAll(PDO::FETCH_ASSOC));
}

function inviteOwnerState($pdo, int $userId): array {
    $s = $pdo->prepare('SELECT role, invite_used_at FROM users WHERE id = ?');
    $s->execute([$userId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: ['role' => 'user', 'invite_used_at' => null];
}

function handle_getMyInvites($pdo, $user) {
    $me = inviteOwnerState($pdo, (int)$user['sub']);
    $unlimited = isOwnerRow($me);
    $used = $me['invite_used_at'] !== null && !$unlimited;
    $codes = liveInviteCodes($pdo, (int)$user['sub']);
    respond(good([
        'unlimited' => $unlimited,
        'canGenerate' => $unlimited || (!$used && !$codes),
        'used' => $used,
        'codes' => $codes,
    ]));
}

function handle_generateInviteCode($pdo, $user) {
    $uid = (int)$user['sub'];
    $me = inviteOwnerState($pdo, $uid);
    $unlimited = isOwnerRow($me);

    if (!$unlimited) {
        if ($me['invite_used_at'] !== null) bad("You've already used your invite.", 403);
        if (liveInviteCodes($pdo, $uid)) bad('You already have an active code. Cancel it to make a new one.', 400);
    }

    $now = time();
    $created = date('Y-m-d H:i:s', $now);
    $expires = date('Y-m-d H:i:s', $now + INVITE_DAYS * 86400);
    $ins = $pdo->prepare('INSERT INTO invite_codes (code, created_by, created_at, expires_at) VALUES (?, ?, ?, ?)');
    // A collision on the primary key is astronomically unlikely, but retrying
    // is free.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = newInviteCode();
        try {
            $ins->execute([$code, $uid, $created, $expires]);
            respond(good(['code' => $code, 'expiresAt' => $expires]));
        } catch (PDOException $e) {
            if ($attempt === 4) throw $e;
        }
    }
}

function handle_cancelInviteCode($pdo, $user) {
    $code = trim((string)($_POST['code'] ?? ''));
    $del = $pdo->prepare('DELETE FROM invite_codes WHERE code = ? AND created_by = ?');
    $del->execute([$code, (int)$user['sub']]);
    if ($del->rowCount() === 0) bad("That invite code isn't valid.", 400);
    respond(good(['cancelled' => true]));
}

function registrationNeedsInvite(): bool {
    return strtolower(getenv('REGISTRATION_MODE') ?: 'invite') !== 'open';
}

// The live code's row (with the inviter's email), or exits with the one
// generic message. Rate-limited per IP: every wrong guess counts, and the
// message never says whether the code was wrong, expired or used. Codes made
// by a frozen or deleted account don't count (the JOIN finds no inviter).
// Codes are case-sensitive, so the lookup trims whitespace and nothing else.
function requireValidInvite($pdo, string $raw): array {
    $keys = ['ip' => attemptKeys('invite', '-')['ip']];
    checkAttemptLimit($pdo, $keys);
    deleteExpiredInvites($pdo);
    $s = $pdo->prepare('SELECT i.code, i.created_by, i.expires_at, u.email AS inviter_email, u.role AS inviter_role
        FROM invite_codes i JOIN users u ON u.id = i.created_by AND u.frozen_at IS NULL WHERE i.code = ?');
    $s->execute([trim($raw)]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        recordFailedAttempt($pdo, $keys);
        bad("That invite code isn't valid.", 400);
    }
    return $row;
}

// Public: the Register page's first step. Only a valid code reveals who made
// it, and whoever holds one was given it by that person.
function handle_checkInviteCode($pdo) {
    $row = requireValidInvite($pdo, (string)($_POST['inviteCode'] ?? ''));
    respond(good(['expiresAt' => $row['expires_at'], 'inviterEmail' => $row['inviter_email']]));
}

// The one-time welcome for a member who joined with an invite, or null. The
// inviter's account may be gone by now (inviterEmail then null: the apps show
// a plain welcome). Shown only to the new member, in their own getMyInfo.
function welcomeFor($pdo, int $userId): ?array {
    $s = $pdo->prepare('SELECT u.welcome_pending, i.email AS inviter_email, i.role AS inviter_role
        FROM users u LEFT JOIN users i ON i.id = u.invited_by WHERE u.id = ?');
    $s->execute([$userId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['welcome_pending'] !== 1) return null;
    return ['inviterEmail' => $row['inviter_email'], 'inviterIsOwner' => ($row['inviter_role'] ?? '') === 'owner'];
}

function handle_dismissWelcome($pdo, $user) {
    $pdo->prepare('UPDATE users SET welcome_pending = 0 WHERE id = ?')->execute([(int)$user['sub']]);
    respond(good());
}
