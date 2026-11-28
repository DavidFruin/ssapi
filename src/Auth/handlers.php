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
// exactly as they could when the code lived inline. Namespacing/class
// wrapping is a separate, larger decision than the one this pass makes.
//
// Depends on shared Core helpers still defined in api.php/auth.php: bad(),
// good(), respond(), db(), logMsg(), requireAuth(), bearerToken(),
// jwtVerify(), sessionCreate(), sessionRefresh(), sessionRevoke(),
// sessionRevokeAllForUser(), refreshTokenHash(). Those are cross-module
// infrastructure, not Auth-specific, so they aren't moving here.

// ============== ONE-TIME CODES ==============
// What sits in users.reset_otp and pending_users.otp is never readable:
//   'c:<hash>'  an emailed 6-digit code that hasn't been entered yet
//   't:<hash>'  the code was entered; the hash is of the verify token that
//               was handed back to whoever entered it
// The last step (set the password) needs that token, so knowing someone's
// email is not enough to finish their reset or registration for them.
//
// A 6-digit code has only a million values, so a plain hash could be undone
// at once by anyone holding the database or a backup. It is keyed with the
// server secret, which lives in private/.env and not in the database. The
// token is 256 random bits, so plain SHA-256 is enough for it.
function otpCodeHash($email, $otp) {
    global $CONFIG;
    $secret = $CONFIG['jwt_secret'] ?? null;
    if (!$secret) { error_log('ssapi: JWT_SECRET not configured'); respond(['valid' => false, 'error' => 'Server misconfigured'], 500); }
    return 'c:' . hash_hmac('sha256', strtolower(trim($email)) . ':' . $otp, $secret);
}

function verifyTokenHash($token) {
    return 't:' . hash('sha256', $token);
}

function storedHashMatches($stored, $expected) {
    return is_string($stored) && $stored !== '' && hash_equals($stored, $expected);
}

// The last step of a reset or registration is limited per IP only. The token
// can't be guessed, so a per-email lock would add nothing except a way for a
// stranger to block someone else's reset.
function finalStepKeys($scope) {
    return ['ip' => attemptKeys($scope, '-')['ip']];
}

// ============== ATTEMPT LIMITS ==============
// Failures are counted per key inside a 15-minute window; reaching a key's
// limit locks that key for the window.
//
// One-time codes and refresh tokens: per email (or token) and per IP. A
// 6-digit code needs a hard cap on guesses, whoever is guessing.
//
// Login is different, because a hard per-email lock lets anyone keep a
// member out just by typing wrong passwords for their email. So:
//   pair   5 failures from one IP for one email: that IP is locked for that
//          email. The owner, on another connection, is not affected.
//   ip     20 failures from one IP, whatever the emails.
//   email  100 failures for one email from all IPs together. Reaching it
//          takes 20 or more addresses working together; it keeps a ceiling
//          on guessing spread across many addresses.
const ATTEMPT_WINDOW = 900;
const ATTEMPT_LIMIT_EMAIL = 5;
const ATTEMPT_LIMIT_IP = 20;
const ATTEMPT_LIMIT_LOGIN_EMAIL = 100;

function attemptKeys($scope, $email) {
    $email = strtolower(trim($email));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    $keys = ['email' => "$scope:email:$email", 'ip' => "$scope:ip:$ip"];
    if ($scope === 'login') $keys['pair'] = "login:pair:$email|$ip";
    return $keys;
}

function attemptLimit($type, $key) {
    if ($type === 'ip') return ATTEMPT_LIMIT_IP;
    if ($type === 'email' && str_starts_with($key, 'login:')) return ATTEMPT_LIMIT_LOGIN_EMAIL;
    return ATTEMPT_LIMIT_EMAIL;
}

function checkAttemptLimit($pdo, $keys) {
    $stmt = $pdo->prepare('SELECT locked_until FROM auth_attempts WHERE attempt_key = ?');
    foreach ($keys as $key) {
        $stmt->execute([$key]);
        $lockedUntil = (int)$stmt->fetchColumn();
        if ($lockedUntil <= time()) continue;
        $minutes = (int)ceil(($lockedUntil - time()) / 60);
        bad($minutes === 1
            ? tr('Too many attempts. Try again in {minutes} minute.', ['minutes' => $minutes])
            : tr('Too many attempts. Try again in {minutes} minutes.', ['minutes' => $minutes]), 429);
    }
}

// Returns true if this failure locked the email out.
function recordFailedAttempt($pdo, $keys) {
    $now = time();
    $select = $pdo->prepare('SELECT failures, window_start FROM auth_attempts WHERE attempt_key = ?');
    $save = $pdo->prepare('REPLACE INTO auth_attempts (attempt_key, failures, window_start, locked_until) VALUES (?, ?, ?, ?)');
    $emailLocked = false;
    foreach ($keys as $type => $key) {
        $select->execute([$key]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        $inWindow = $row && $now - $row['window_start'] < ATTEMPT_WINDOW;
        $failures = $inWindow ? $row['failures'] + 1 : 1;
        $windowStart = $inWindow ? $row['window_start'] : $now;
        $locked = $failures >= attemptLimit($type, $key);
        $save->execute([$key, $locked ? 0 : $failures, $locked ? $now : $windowStart, $locked ? $now + ATTEMPT_WINDOW : 0]);
        if ($locked && $type === 'email') $emailLocked = true;
    }
    return $emailLocked;
}

// After a success: everything counted against this email (and this IP's own
// count for it) is forgiven. The IP's overall count is left to run out.
function clearAttempts($pdo, $keys) {
    $del = $pdo->prepare('DELETE FROM auth_attempts WHERE attempt_key = ?');
    foreach (['email', 'pair'] as $type) {
        if (isset($keys[$type])) $del->execute([$keys[$type]]);
    }
}

// Counts sends (not failures) per key per ATTEMPT_WINDOW -- reuses the same
// auth_attempts table as the failed-login/OTP-check throttle above, but
// against a distinct key scope ('otpsend:...') so the two never share a
// counter. Guards sendOTP/sendRegisterOTP, which are public and send a real
// email on every call -- unthrottled, either can be used to flood any
// inbox, which has already cost real mail deliverability once (see the TUI
// registration note in simple-social). Also used with a longer window and
// its own message for other per-user caps (reports: 20 a day).
function throttleSend($pdo, $key, $limit, $window = ATTEMPT_WINDOW, $message = 'Too many codes requested. Try again later.') {
    $now = time();
    $sel = $pdo->prepare('SELECT failures, window_start FROM auth_attempts WHERE attempt_key = ?');
    $sel->execute([$key]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    $inWindow = $row && $now - $row['window_start'] < $window;
    $count = $inWindow ? $row['failures'] + 1 : 1;
    if ($count > $limit) bad($message, 429);
    $pdo->prepare('REPLACE INTO auth_attempts (attempt_key, failures, window_start, locked_until) VALUES (?, ?, ?, 0)')
        ->execute([$key, $count, $inWindow ? $row['window_start'] : $now]);
}

function validatePasswordRules($password) {
    // bcrypt only reads the first 72 bytes, so that's the real ceiling --
    // 25 blocked legitimate password-manager-generated passwords for no
    // reason.
    if (strlen($password) < 8 || strlen($password) > 72) bad('Password must be 8-72 characters', 400);
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

    $stmt = $pdo->prepare('SELECT id, password, email, frozen_at FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    // An email with no account still costs one password hash, so how long
    // the answer takes doesn't show which emails are registered.
    $passwordOk = $user ? password_verify($password, $user['password'])
        : (password_hash($password, PASSWORD_DEFAULT) && false);
    if (!$passwordOk) {
        recordFailedAttempt($pdo, $keys);
        bad('Invalid email or password', 401);
    }
    clearAttempts($pdo, $keys);
    // Hashes made with an older cost are brought up to the current one, which
    // also keeps a real check and the stand-in above taking the same time.
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    // Checked only after the password, so it can't be used to probe which
    // emails have accounts.
    if ($user['frozen_at'] !== null) {
        $contact = $CONFIG['contact_email'] ?? '';
        bad($contact !== ''
            ? tr('This account has been suspended. Contact {contact} if you think this is a mistake.', ['contact' => $contact])
            : tr('This account has been suspended. Contact the site admin if you think this is a mistake.'), 403);
    }

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
    // allowExpired=true: an access token that aged out minutes ago is still
    // a legitimate reason to revoke its session, and the signature check
    // (unlike the old jwtClaimsUnverified() path this replaces) means the
    // session id is actually trustworthy, not just a hint.
    $claims = jwtVerify(bearerToken(), true);
    if ($claims && !empty($claims['sid'])) {
        $stmt = $pdo->prepare('SELECT id FROM sessions WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
        $stmt->execute([$claims['sid'], $claims['sub']]);
        if ($stmt->fetchColumn()) sessionRevoke($pdo, $claims['sid']);
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
    $out = [
        'jwt' => $result['jwt'],
        'userId' => $result['userId'],
        'expiresIn' => $CONFIG['session_access_ttl'] ?? 86400,
    ];
    // Present only when the token was replaced; the app must store it, the
    // one it sent has stopped working.
    if ($result['refreshToken'] !== null) $out['refreshToken'] = $result['refreshToken'];
    respond(good($out));
}

// The device list. Deliberately never exposes refresh_hash - the whole
// point of hashing it is that not even this endpoint can hand it back.
function handle_getSessions($pdo, $user) {
    global $CONFIG;
    $stmt = $pdo->prepare('SELECT id, device_name, created_at, created_tz, last_used_at
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
            'createdTz' => $row['created_tz'],
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

    $k = attemptKeys('otpsend', $email);
    throttleSend($pdo, $k['email'], 3);   // 3 codes per address per 15 min
    throttleSend($pdo, $k['ip'], 10);     // 10 per IP per 15 min

    // The answer is the same whether or not the email has an account, and
    // the mail goes out after the response, so neither the words nor the
    // time taken shows which emails are registered.
    $answer = good(['message' => 'If that email has an account, a code is on its way. Check inbox/spam.']);

    $stmt = $pdo->prepare('SELECT id, email, reset_expires FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    // No account: nothing is sent. A code issued (or entered) under a minute
    // ago: no second one yet. reset_expires is the issue time plus 600.
    if (!$row || ($row['reset_expires'] ?? 0) - 600 > time() - 60) respond($answer);

    $otp = sprintf("%06d", random_int(0, 999999));
    $stmt = $pdo->prepare('UPDATE users SET reset_otp = ?, reset_expires = ? WHERE id = ?');
    $stmt->execute([otpCodeHash($row['email'], $otp), time() + 600, $row['id']]);

    $subject = mailSubject(trSite('Your Simple Social Password Reset OTP'));
    $message = tr('Your 6-digit OTP code is: {otp}', ['otp' => $otp]) . "\n\n" . tr('Valid for 10 minutes.') . "\n\n" . tr('If you did not request this, ignore this email.');
    $to = $row['email'];
    defer(function () use ($to, $subject, $message) {
        if (!mail($to, $subject, $message, mailHeaders($message))) logApiError('sendOTP', 'mail() failed');
    });
    respond($answer);
}

function handle_verifyOTP($pdo) {
    $email = trim($_POST['email'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    $keys = attemptKeys('otp', $email);
    checkAttemptLimit($pdo, $keys);
    // One answer for every failure (no account, no code asked for, expired,
    // wrong), counted the same way, so this can't be used to find out which
    // emails have accounts either.
    $wrong = 'Incorrect or expired code. Request a new one if you need to.';
    if (!preg_match('/^\d{6}$/', $otp)) bad($wrong, 400);

    $stmt = $pdo->prepare('SELECT id, email, reset_otp, reset_expires FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || time() > $row['reset_expires']
        || !storedHashMatches($row['reset_otp'], otpCodeHash($row['email'], $otp))) {
        if (recordFailedAttempt($pdo, $keys) && $row) {
            $pdo->prepare('UPDATE users SET reset_otp = NULL, reset_expires = 0 WHERE id = ?')->execute([$row['id']]);
        }
        bad($wrong, 400);
    }
    clearAttempts($pdo, $keys);

    // The code is used up. In its place goes the hash of a token that only
    // this caller receives; resetPassword requires it (valid 10 more minutes).
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('UPDATE users SET reset_otp = ?, reset_expires = ? WHERE id = ?');
    $stmt->execute([verifyTokenHash($token), time() + 600, $row['id']]);
    respond(good(['message' => 'OTP verified! Set your new password.', 'verifyToken' => $token]));
}

function handle_resetPassword($pdo) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    $token = trim($_POST['verifyToken'] ?? '');
    if (!$email || !$password || $password !== $confirm) bad('Passwords do not match or are empty', 400);

    $keys = finalStepKeys('reset');
    checkAttemptLimit($pdo, $keys);

    $stmt = $pdo->prepare('SELECT id, reset_otp, reset_expires FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    // One answer for every way this can fail (no such account, no code
    // entered, wrong or missing token, too late), so it says nothing about
    // which emails have accounts.
    if (!$row || $token === '' || time() > $row['reset_expires']
        || !storedHashMatches($row['reset_otp'], verifyTokenHash($token))) {
        recordFailedAttempt($pdo, $keys);
        bad('Verify your OTP before resetting your password', 403);
    }
    // After the token check: a password that breaks the rules doesn't use
    // the token up, so the person can simply try a better one.
    validatePasswordRules($password);

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
    requireRoomForNewUser($pdo);

    // Invite-only: the code is checked first, before the send throttles and
    // before any email. It is only read here; nothing is spent until the
    // account is created (handle_finishRegister).
    $inviteCode = null;
    if (registrationNeedsInvite()) {
        $inviteCode = requireValidInvite($pdo, (string)($_POST['inviteCode'] ?? ''))['code'];
    }

    $k = attemptKeys('otpsend', $email);
    throttleSend($pdo, $k['email'], 3);   // 3 codes per address per 15 min
    throttleSend($pdo, $k['ip'], 10);     // 10 per IP per 15 min

    $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) bad('Email already registered', 400);

    $stmt = $pdo->prepare('SELECT dateCreated FROM pending_users WHERE email = ?');
    $stmt->execute([$email]);
    $existingSentAt = $stmt->fetchColumn();
    if ($existingSentAt !== false && (int)$existingSentAt > time() - 60) {
        bad('Please wait a minute before requesting another code.', 429);
    }

    $otp = sprintf("%06d", random_int(0, 999999));
    $stmt = $pdo->prepare('DELETE FROM pending_users WHERE email = ?');
    $stmt->execute([$email]);
    $stmt = $pdo->prepare('INSERT INTO pending_users (email, password, otp, dateCreated, invite_code) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$email, '', otpCodeHash($email, $otp), time(), $inviteCode]);

    $subject = trSite('Your Simple Social Registration OTP');
    $message = tr('Your 6-digit OTP code is: {otp}', ['otp' => $otp]) . "\n\n" . tr('Valid for 10 minutes.') . "\n\n" . tr('If you did not request this, ignore this email.');
    $headers = mailHeaders($message);

    mail($email, mailSubject($subject), $message, $headers)
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
    if (!storedHashMatches($row['otp'], otpCodeHash($email, $otp))) {
        if (recordFailedAttempt($pdo, $keys)) {
            $pdo->prepare('DELETE FROM pending_users WHERE email = ?')->execute([$email]);
        }
        bad('Incorrect OTP', 400);
    }
    clearAttempts($pdo, $keys);

    // The code is used up; finishRegister requires the token handed back
    // here (valid 10 more minutes).
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('UPDATE pending_users SET otp = ?, dateCreated = ? WHERE email = ?');
    $stmt->execute([verifyTokenHash($token), time(), $email]);
    respond(good(['message' => 'OTP verified! Set your password.', 'verifyToken' => $token]));
}

function handle_finishRegister($pdo) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    $token = trim($_POST['verifyToken'] ?? '');
    if (!$email || !$password || $password !== $confirm) bad('Passwords do not match or are empty', 400);

    $keys = finalStepKeys('register');
    checkAttemptLimit($pdo, $keys);
    requireRoomForNewUser($pdo);

    $stmt = $pdo->prepare('SELECT otp, dateCreated, invite_code FROM pending_users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['otp'] || time() - $row['dateCreated'] > 600) bad('Session expired. Please start over.', 400);
    if ($token === '' || !storedHashMatches($row['otp'], verifyTokenHash($token))) {
        recordFailedAttempt($pdo, $keys);
        bad('Verify your OTP before creating your account', 403);
    }
    validatePasswordRules($password);
    // The web and phone sign-up always send a username. Older terminal builds
    // don't; their members are asked for one the next time they open an app.
    $username = trim((string)($_POST['username'] ?? ''));
    if ($username !== '') requireUsableUsername($pdo, $username);

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $created_at = date('Y-m-d H:i:s');

    beginWrite($pdo);
    try {
        $s = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)');
        $s->execute([$email]);
        if ($s->fetchColumn()) { rollbackWrite($pdo); bad('Email already registered', 400); }
        if ($username !== '') {
            $s = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
            $s->execute([$username]);
            if ($s->fetchColumn()) { rollbackWrite($pdo); bad('That username is taken.', 409); }
        }

        // The invite is spent here and only here, in the same transaction as
        // the new account, so two people finishing with one code can't both
        // get in (BEGIN IMMEDIATE makes the check-then-delete atomic), and an
        // abandoned registration leaves the code live.
        $inviterId = null;
        if (registrationNeedsInvite()) {
            $s = $pdo->prepare('SELECT i.created_by FROM invite_codes i
                JOIN users u ON u.id = i.created_by AND u.frozen_at IS NULL
                WHERE i.code = ? AND i.expires_at > ?');
            $s->execute([(string)($row['invite_code'] ?? ''), date('Y-m-d H:i:s')]);
            $inviterId = $s->fetchColumn();
            if ($inviterId === false) {
                rollbackWrite($pdo);
                bad('That invite code has expired or was already used. Ask for a new one.', 400);
            }
            $inviterId = (int)$inviterId;
            $pdo->prepare('DELETE FROM invite_codes WHERE code = ?')->execute([$row['invite_code']]);
            $pdo->prepare('UPDATE users SET invite_used_at = ? WHERE id = ? AND role != ?')
                ->execute([date('Y-m-d H:i:s'), $inviterId, 'owner']);
        }
        // Standard single-quoted SQL string literals, not SQLite's legacy
        // double-quoted-identifier-as-string fallback -- a build compiled
        // without that fallback would reject this INSERT outright.
        $pdo->prepare("INSERT INTO users (email, username, password, posts, follows, followers, jwt, created_at, invited_by, welcome_pending, created_tz)
            VALUES (?, ?, ?, '[]', '[]', '[]', '', ?, ?, ?, ?)")->execute([$email, $username !== '' ? $username : null, $hashed, $created_at, $inviterId, $inviterId !== null ? 1 : 0, clientTzOffset()]);
        $pdo->prepare('DELETE FROM pending_users WHERE email = ?')->execute([$email]);
        commitWrite($pdo);
    } catch (Throwable $e) {
        rollbackWrite($pdo);
        throw $e;
    }

    respond(good(['message' => 'Account created successfully!']));
}
