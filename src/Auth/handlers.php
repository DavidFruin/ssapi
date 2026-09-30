<?php
// src/Auth/handlers.php - Auth module: login, logout, session/refresh-token
// lifecycle, password reset and registration OTP flow, and the per-account
// attempt-limiting these flows share.
//
// Loaded via Composer's "files" autoload (see composer.json) rather than
// PSR-4 classes: this is a folder-level reorganization of the existing
// procedural api.php, not a rewrite into an OOP structure. Everything here
// is still global-namespace, same calling convention as before the move,
// so api.php's $HANDLERS table and every other module can call these
// exactly as they could when the code lived inline. See
// .claude/commands/split-backend-modules.md for why: namespacing/class
// wrapping is a separate, larger decision than the one this pass makes.
//
// Depends on shared Core helpers still defined in api.php/auth.php: bad(),
// good(), respond(), db(), logMsg(), requireAuth(), bearerToken(),
// jwtClaimsUnverified(), sessionCreate(), sessionRefresh(), sessionRevoke(),
// sessionRevokeAllForUser(), refreshTokenHash(). Those are cross-module
// infrastructure, not Auth-specific, so they aren't moving here.

// Stored in place of an OTP once it has been verified. It can never match a
// submitted code because verify actions only accept 6 digits.
const OTP_VERIFIED = 'VERIFIED';

// ============== ATTEMPT LIMITS ==============
// Failed logins and OTP checks are counted per email and per IP. Too many
// failures inside the window locks that email (or IP) out for the window.
const ATTEMPT_WINDOW = 900;
const ATTEMPT_LIMIT_EMAIL = 5;
const ATTEMPT_LIMIT_IP = 20;

function attemptKeys($scope, $email) {
    return [
        'email' => "$scope:email:" . strtolower(trim($email)),
        'ip' => "$scope:ip:" . ($_SERVER['REMOTE_ADDR'] ?? '-'),
    ];
}

function checkAttemptLimit($pdo, $keys) {
    $stmt = $pdo->prepare('SELECT locked_until FROM auth_attempts WHERE attempt_key = ?');
    foreach ($keys as $key) {
        $stmt->execute([$key]);
        $lockedUntil = (int)$stmt->fetchColumn();
        if ($lockedUntil <= time()) continue;
        $minutes = (int)ceil(($lockedUntil - time()) / 60);
        bad("Too many attempts. Try again in $minutes minute" . ($minutes === 1 ? '' : 's') . '.', 429);
    }
}

// Returns true if this failure locked the email out.
function recordFailedAttempt($pdo, $keys) {
    $now = time();
    $limits = ['email' => ATTEMPT_LIMIT_EMAIL, 'ip' => ATTEMPT_LIMIT_IP];
    $select = $pdo->prepare('SELECT failures, window_start FROM auth_attempts WHERE attempt_key = ?');
    $save = $pdo->prepare('INSERT OR REPLACE INTO auth_attempts (attempt_key, failures, window_start, locked_until) VALUES (?, ?, ?, ?)');
    $emailLocked = false;
    foreach ($keys as $type => $key) {
        $select->execute([$key]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        $inWindow = $row && $now - $row['window_start'] < ATTEMPT_WINDOW;
        $failures = $inWindow ? $row['failures'] + 1 : 1;
        $windowStart = $inWindow ? $row['window_start'] : $now;
        $locked = $failures >= $limits[$type];
        $save->execute([$key, $locked ? 0 : $failures, $locked ? $now : $windowStart, $locked ? $now + ATTEMPT_WINDOW : 0]);
        if ($locked && $type === 'email') $emailLocked = true;
    }
    return $emailLocked;
}

function clearAttempts($pdo, $keys) {
    $pdo->prepare('DELETE FROM auth_attempts WHERE attempt_key = ?')->execute([$keys['email']]);
}

function validatePasswordRules($password) {
    if (strlen($password) < 8 || strlen($password) > 25) bad('Password must be 8-25 characters', 400);
    if (!preg_match('/[a-z]/', $password)) bad('Password must contain a lowercase letter', 400);
    if (!preg_match('/[A-Z]/', $password)) bad('Password must contain an uppercase letter', 400);
    if (!preg_match('/\d/', $password)) bad('Password must contain a number', 400);
    if (!preg_match('/[~!@#$%^&*()\-_+=\[\];\'"\/.,<>?:"{}|]/', $password)) bad('Password must contain a symbol', 400);
}

// ============== AUTH HANDLERS ==============
function handle_login($pdo) {
    global $CONFIG;
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!$email || !$password) bad('Missing credentials', 400);

    $keys = attemptKeys('login', $email);
    checkAttemptLimit($pdo, $keys);

    $stmt = $pdo->prepare('SELECT id, password, email FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($password, $user['password'])) {
        recordFailedAttempt($pdo, $keys);
        bad('Invalid email or password', 401);
    }
    clearAttempts($pdo, $keys);

    // A new login adds a session; it never disturbs the ones already there,
    // which is the whole point - the old code overwrote a single token slot
    // and so logged every other device out.
    $session = sessionCreate($pdo, $user['id']);
    logMsg("LOGIN: user={$user['id']} session={$session['sessionId']}");

    respond(good([
        'message' => 'Login successful',
        'userId' => $user['id'],
        'jwt' => $session['jwt'],
        'refreshToken' => $session['refreshToken'],
        'expiresIn' => $CONFIG['session_access_ttl'] ?? 86400,
    ]));
}

// Public: an expired access token must still be able to log itself out, and
// the session id comes from the token's claims rather than from the caller.
function handle_logout($pdo) {
    $claims = jwtClaimsUnverified(bearerToken());
    if ($claims && !empty($claims['sid'])) {
        // Scoped by user id as well, so a forged token can't revoke someone
        // else's session - it would have to name a real (sid, sub) pair.
        $stmt = $pdo->prepare('UPDATE sessions SET revoked_at = ?
            WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
        $stmt->execute([date('Y-m-d H:i:s'), $claims['sid'], $claims['sub'] ?? 0]);
        logMsg("LOGOUT: session={$claims['sid']}");
    }
    respond(good(['message' => 'Logged out']));
}

// Public: called precisely when the access token is too old to authenticate.
// Rate limited per token and per IP, since the refresh token is the only
// credential involved.
function handle_refreshToken($pdo) {
    global $CONFIG;
    $refreshToken = trim($_POST['refreshToken'] ?? '');
    if (!$refreshToken) bad('Missing refresh token', 400);

    // Keyed on the token's hash, never the token itself - attempt_key rows
    // are long-lived and a raw refresh token has no business sitting in one.
    $keys = attemptKeys('refresh', refreshTokenHash($refreshToken));
    checkAttemptLimit($pdo, $keys);

    $result = sessionRefresh($pdo, $refreshToken);
    if (!$result) {
        recordFailedAttempt($pdo, $keys);
        bad('Session expired. Please log in again.', 401);
    }
    clearAttempts($pdo, $keys);

    logMsg("REFRESH: user={$result['userId']} session={$result['sessionId']}");
    respond(good([
        'jwt' => $result['jwt'],
        'userId' => $result['userId'],
        'expiresIn' => $CONFIG['session_access_ttl'] ?? 86400,
    ]));
}

// The device list. Deliberately never exposes refresh_hash - the whole
// point of hashing it is that not even this endpoint can hand it back.
function handle_getSessions($pdo, $user) {
    global $CONFIG;
    $stmt = $pdo->prepare('SELECT id, device_name, created_at, last_used_at
        FROM sessions
        WHERE user_id = ? AND revoked_at IS NULL AND expires_at >= ?
        ORDER BY COALESCE(last_used_at, created_at) DESC');
    $stmt->execute([$user['sub'], date('Y-m-d H:i:s')]);

    $sessions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sessions[] = [
            'id' => $row['id'],
            'deviceName' => $row['device_name'] ?: 'Unknown device',
            'createdAt' => $row['created_at'],
            'lastUsedAt' => $row['last_used_at'],
            'isCurrent' => $row['id'] === $user['sid'],
        ];
    }
    // Sent rather than hardcoded in the UI so the limit shown always matches
    // the one actually enforced.
    respond(good(['sessions' => $sessions, 'maxSessions' => $CONFIG['session_max_per_user'] ?? 10]));
}

function handle_revokeSession($pdo, $user) {
    $sessionId = trim($_POST['sessionId'] ?? '');
    if (!$sessionId) bad('Missing session id', 400);

    // Scoped to the caller, so a session id belonging to someone else simply
    // matches nothing rather than revoking their login.
    $stmt = $pdo->prepare('SELECT id FROM sessions WHERE id = ? AND user_id = ?');
    $stmt->execute([$sessionId, $user['sub']]);
    if (!$stmt->fetchColumn()) bad('Session not found', 404);

    sessionRevoke($pdo, $sessionId);
    logMsg("REVOKE: user={$user['sub']} session=$sessionId");
    respond(good(['message' => 'Device signed out', 'wasCurrent' => $sessionId === $user['sid']]));
}

function handle_revokeAllOtherSessions($pdo, $user) {
    $stmt = $pdo->prepare('SELECT id FROM sessions
        WHERE user_id = ? AND id != ? AND revoked_at IS NULL AND expires_at >= ?');
    $stmt->execute([$user['sub'], $user['sid'], date('Y-m-d H:i:s')]);
    $others = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($others as $id) sessionRevoke($pdo, $id);
    logMsg("REVOKE ALL: user={$user['sub']} revoked=" . count($others));
    respond(good(['message' => 'Other devices signed out', 'revoked' => count($others)]));
}

function handle_sendOTP($pdo) {
    $email = trim($_POST['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) bad('Valid email required', 400);

    $stmt = $pdo->prepare('SELECT id, email FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) bad('No account found with this email', 404);

    $otp = sprintf("%06d", mt_rand(0, 999999));
    $stmt = $pdo->prepare('UPDATE users SET reset_otp = ?, reset_expires = ? WHERE id = ?');
    $stmt->execute([$otp, time() + 600, $row['id']]);

    $subject = 'Your Simple Social Password Reset OTP';
    $message = "Your 6-digit OTP code is: $otp\n\nValid for 10 minutes.\n\nIf you did not request this, ignore this email.";
    $headers = "From: no-reply@app.davidfruin.com\r\nReply-To: no-reply@app.davidfruin.com\r\n";

    mail($row['email'], $subject, $message, $headers)
        ? respond(good(['message' => 'OTP sent to your email. Check inbox/spam.']))
        : bad('Failed to send email. Try again or contact support.', 500);
}

function handle_verifyOTP($pdo) {
    $email = trim($_POST['email'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    $keys = attemptKeys('otp', $email);
    checkAttemptLimit($pdo, $keys);
    if (!preg_match('/^\d{6}$/', $otp)) bad('Incorrect OTP', 400);

    $stmt = $pdo->prepare('SELECT id, reset_otp, reset_expires FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['reset_otp']) bad('No OTP requested or expired', 400);
    if (time() > $row['reset_expires']) bad('OTP has expired. Please request a new one.', 400);
    if ($otp !== $row['reset_otp']) {
        if (recordFailedAttempt($pdo, $keys)) {
            $pdo->prepare('UPDATE users SET reset_otp = NULL, reset_expires = 0 WHERE id = ?')->execute([$row['id']]);
        }
        bad('Incorrect OTP', 400);
    }
    clearAttempts($pdo, $keys);

    // Mark verified so resetPassword can require it (valid 10 more minutes).
    $stmt = $pdo->prepare('UPDATE users SET reset_otp = ?, reset_expires = ? WHERE id = ?');
    $stmt->execute([OTP_VERIFIED, time() + 600, $row['id']]);
    respond(good(['message' => 'OTP verified! Set your new password.']));
}

function handle_resetPassword($pdo) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    if (!$email || !$password || $password !== $confirm) bad('Passwords do not match or are empty', 400);
    validatePasswordRules($password);

    $stmt = $pdo->prepare('SELECT id, reset_otp, reset_expires FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) bad('User not found', 404);
    if ($row['reset_otp'] !== OTP_VERIFIED || time() > $row['reset_expires']) {
        bad('Verify your OTP before resetting your password', 403);
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password = ?, reset_otp = NULL, reset_expires = 0 WHERE id = ?');
    $stmt->execute([$hashed, $row['id']]);

    // A reset is the standard response to "someone else may have my account",
    // so every existing login dies with the old password.
    sessionRevokeAllForUser($pdo, $row['id']);
    logMsg("RESET: revoked all sessions for user={$row['id']}");

    respond(good(['message' => 'Password reset successful! Please log in.']));
}

function handle_sendRegisterOTP($pdo) {
    $email = trim($_POST['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) bad('Valid email required', 400);

    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) bad('Email already registered', 400);

    $otp = sprintf("%06d", mt_rand(0, 999999));
    $stmt = $pdo->prepare('DELETE FROM pending_users WHERE email = ?');
    $stmt->execute([$email]);
    $stmt = $pdo->prepare('INSERT INTO pending_users (email, password, otp, dateCreated) VALUES (?, ?, ?, ?)');
    $stmt->execute([$email, '', $otp, time()]);

    $subject = 'Your Simple Social Registration OTP';
    $message = "Your 6-digit OTP code is: $otp\n\nValid for 10 minutes.\n\nIf you did not request this, ignore this email.";
    $headers = "From: no-reply@app.davidfruin.com\r\nReply-To: no-reply@app.davidfruin.com\r\n";

    mail($email, $subject, $message, $headers)
        ? respond(good(['message' => 'OTP sent to your email. Check inbox/spam.']))
        : bad('Failed to send email.', 500);
}

function handle_verifyRegisterOTP($pdo) {
    $email = trim($_POST['email'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    if (!$email || strlen($otp) !== 6) bad('Email and 6-digit OTP required', 400);
    $keys = attemptKeys('regotp', $email);
    checkAttemptLimit($pdo, $keys);

    $stmt = $pdo->prepare('SELECT otp, dateCreated FROM pending_users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['otp']) bad('No OTP requested', 400);
    if (time() - $row['dateCreated'] > 600) bad('OTP has expired. Please request a new one.', 400);
    if ($otp !== $row['otp']) {
        if (recordFailedAttempt($pdo, $keys)) {
            $pdo->prepare('DELETE FROM pending_users WHERE email = ?')->execute([$email]);
        }
        bad('Incorrect OTP', 400);
    }
    clearAttempts($pdo, $keys);

    // Mark verified so finishRegister can require it (valid 10 more minutes).
    $stmt = $pdo->prepare('UPDATE pending_users SET otp = ?, dateCreated = ? WHERE email = ?');
    $stmt->execute([OTP_VERIFIED, time(), $email]);
    respond(good(['message' => 'OTP verified! Set your password.']));
}

function handle_finishRegister($pdo) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (!$email || !$password || $password !== $confirm) bad('Passwords do not match or are empty', 400);
    validatePasswordRules($password);

    $stmt = $pdo->prepare('SELECT otp, dateCreated FROM pending_users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['otp'] || time() - $row['dateCreated'] > 600) bad('Session expired. Please start over.', 400);
    if ($row['otp'] !== OTP_VERIFIED) bad('Verify your OTP before creating your account', 403);

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $created_at = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT INTO users (email, password, posts, follows, followers, jwt, created_at) VALUES (?, ?, "[]", "[]", "[]", "", ?)');
    $stmt->execute([$email, $hashed, $created_at]);
    $stmt = $pdo->prepare('DELETE FROM pending_users WHERE email = ?');
    $stmt->execute([$email]);

    respond(good(['message' => 'Account created successfully!']));
}
