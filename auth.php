<?php
// auth.php - JWT and session handling, shared by api.php and media.php.
//
// Both entry points previously carried their own near-identical copies of
// jwtDecode()/verifyUser(). Session validation would have made that three
// copies of increasingly security-sensitive logic, so it lives here once.
// Each file keeps its own requireAuth(), since they differ in signature and
// in how they report failure.
//
// The model: a JWT is a short-lived bearer credential that names a session
// ("sid"); the session row is the durable thing. Every protected request
// verifies the JWT's signature *and* confirms its session is still live, so
// revoking a session takes effect immediately rather than whenever the token
// would have expired on its own.
//
// NOTE ON SIGNATURES: the previous implementation never verified the HMAC.
// It got away with it because verifyUser() compared the whole token string
// against the one stored in users.jwt, so a forged token failed that match.
// Session lookup replaces that comparison, which makes explicit signature
// verification load-bearing - jwtVerify() below is the only thing standing
// between a hand-crafted token and a valid session id.

function b64urlEncode($bin) {
    return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($bin));
}

function b64urlDecode($str) {
    $str = str_replace(['-', '_'], ['+', '/'], $str);
    $pad = strlen($str) % 4;
    if ($pad) $str .= str_repeat('=', 4 - $pad);
    return base64_decode($str);
}

function jwtEncode($payload) {
    global $CONFIG;
    $secret = $CONFIG['jwt_secret'] ?? null;
    if (!$secret) { error_log('JWT secret not configured'); respond(['valid' => false, 'error' => 'Server misconfigured'], 500); }
    $header = b64urlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payloadStr = b64urlEncode(json_encode($payload));
    $sig = b64urlEncode(hash_hmac('sha256', "$header.$payloadStr", $secret, true));
    return "$header.$payloadStr.$sig";
}

// Verifies signature and expiry. Returns the payload, or false.
// This is the only decode path any authorisation decision may use.
function jwtVerify($jwt) {
    global $CONFIG;
    $secret = $CONFIG['jwt_secret'] ?? null;
    if (!$secret || !$jwt) return false;

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;
    list($header, $payloadStr, $sig) = $parts;

    $expected = b64urlEncode(hash_hmac('sha256', "$header.$payloadStr", $secret, true));
    if (!hash_equals($expected, $sig)) return false;

    $payload = json_decode(b64urlDecode($payloadStr), true);
    if (!$payload || !isset($payload['sub'])) return false;
    if (isset($payload['exp']) && time() > $payload['exp']) return false;
    return $payload;
}

// Reads a token's claims WITHOUT verifying anything. Only for cases where
// the claim is a hint rather than a permission - logout uses it to work out
// which session to revoke, and revoking someone else's session on a forged
// token is harmless since the revoke is scoped by the signature check anyway.
function jwtClaimsUnverified($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;
    $payload = json_decode(b64urlDecode($parts[1]), true);
    return $payload ?: false;
}

function bearerToken() {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) return $m[1];
    return '';
}

// ============== SESSIONS ==============

function refreshTokenHash($token) {
    // SHA-256, not password_hash(): these are 256-bit random tokens, so
    // there's nothing to brute-force and no need for a slow KDF.
    return hash('sha256', $token);
}

// Turns a User-Agent into something recognisable in a device list. Crude by
// design - it only has to be good enough to tell your phone from your laptop.
function deviceNameFromUserAgent($ua) {
    $ua = trim((string)$ua);
    if ($ua === '') return 'Unknown device';

    // The C clients identify themselves directly.
    if (stripos($ua, 'simple-social-tui') !== false) return 'Terminal (TUI)';
    if (stripos($ua, 'simple-social-cli-interactive') !== false) return 'Terminal (wizard)';
    if (stripos($ua, 'simple-social-cli') !== false) return 'Terminal (CLI)';

    $os = 'Unknown';
    if (stripos($ua, 'iPhone') !== false) $os = 'iPhone';
    elseif (stripos($ua, 'iPad') !== false) $os = 'iPad';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) $os = 'Mac';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

    // Order matters: Edge and Chrome both claim Safari, Edge claims Chrome.
    $browser = 'Browser';
    if (stripos($ua, 'Edg') !== false) $browser = 'Edge';
    elseif (stripos($ua, 'Firefox') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'Chrome') !== false) $browser = 'Chrome';
    elseif (stripos($ua, 'Safari') !== false) $browser = 'Safari';

    return "$browser on $os";
}

// Deletes this user's dead rows. Called on login and refresh rather than
// from cron: it costs one indexed DELETE on a request that's already
// happening, and there's no scheduled-job infrastructure on the server to
// hang a sweep off. A dormant account keeps a few dead rows, which is fine.
function sessionSweep($pdo, $userId) {
    $stmt = $pdo->prepare('DELETE FROM sessions WHERE user_id = ? AND (revoked_at IS NOT NULL OR expires_at < ?)');
    $stmt->execute([$userId, date('Y-m-d H:i:s')]);
}

// Keeps a user under the session cap by revoking least-recently-used
// sessions. Counts only live ones, so a user at the cap who logs out
// somewhere frees a slot immediately.
function sessionEnforceCap($pdo, $userId) {
    global $CONFIG;
    $max = $CONFIG['session_max_per_user'] ?? 10;

    $stmt = $pdo->prepare('SELECT id FROM sessions
        WHERE user_id = ? AND revoked_at IS NULL AND expires_at >= ?
        ORDER BY COALESCE(last_used_at, created_at) ASC');
    $stmt->execute([$userId, date('Y-m-d H:i:s')]);
    $live = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // -1 because the caller is about to add one more.
    $excess = count($live) - ($max - 1);
    for ($i = 0; $i < $excess; $i++) {
        if (!isset($live[$i])) break;
        sessionRevoke($pdo, $live[$i]);
    }
}

// Creates a session and its first access token. Returns
// ['jwt' => ..., 'refreshToken' => ..., 'sessionId' => ...].
// The raw refresh token is returned to the caller and never stored.
function sessionCreate($pdo, $userId) {
    global $CONFIG;
    $accessTtl = $CONFIG['session_access_ttl'] ?? 86400;
    $refreshTtl = $CONFIG['session_refresh_ttl'] ?? 2592000;

    sessionSweep($pdo, $userId);
    sessionEnforceCap($pdo, $userId);

    $sessionId = bin2hex(random_bytes(16));
    $refreshToken = bin2hex(random_bytes(32));
    $now = date('Y-m-d H:i:s');
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);

    $stmt = $pdo->prepare('INSERT INTO sessions
        (id, user_id, refresh_hash, created_at, last_used_at, expires_at, revoked_at, device_name, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)');
    $stmt->execute([
        $sessionId, $userId, refreshTokenHash($refreshToken), $now, $now,
        date('Y-m-d H:i:s', time() + $refreshTtl),
        deviceNameFromUserAgent($ua), $ua,
    ]);

    return [
        'jwt' => jwtEncode(['sub' => $userId, 'sid' => $sessionId, 'iat' => time(), 'exp' => time() + $accessTtl]),
        'refreshToken' => $refreshToken,
        'sessionId' => $sessionId,
    ];
}

// Returns the session row if it's live and belongs to $userId, else false.
function sessionLookup($pdo, $sessionId, $userId) {
    if (!$sessionId) return false;
    $stmt = $pdo->prepare('SELECT * FROM sessions WHERE id = ?');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) return false;
    if ((int)$session['user_id'] !== (int)$userId) return false;
    if ($session['revoked_at'] !== null) return false;
    if ($session['expires_at'] < date('Y-m-d H:i:s')) return false;
    return $session;
}

// Deliberately coarse. last_used_at only has to be good enough to order
// sessions for the cap and to show "last active" in a device list, so it's
// written at most once every few minutes rather than on every request -
// otherwise every authenticated call would take a SQLite write lock, and
// the feed alone fires several in parallel.
const SESSION_TOUCH_INTERVAL = 300;

function sessionTouch($pdo, $session) {
    $last = $session['last_used_at'] ?? null;
    if ($last !== null && strtotime($last) > time() - SESSION_TOUCH_INTERVAL) return;
    $stmt = $pdo->prepare('UPDATE sessions SET last_used_at = ? WHERE id = ?');
    $stmt->execute([date('Y-m-d H:i:s'), $session['id']]);
}

// Revoking a session also drops the push subscription it registered -
// otherwise a device you've signed out of keeps receiving notifications
// until its browser happens to unsubscribe on its own.
function sessionRevoke($pdo, $sessionId) {
    $stmt = $pdo->prepare('UPDATE sessions SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
    $stmt->execute([date('Y-m-d H:i:s'), $sessionId]);
    $pdo->prepare('DELETE FROM push_subscriptions WHERE session_id = ?')->execute([$sessionId]);
}

function sessionRevokeAllForUser($pdo, $userId) {
    $stmt = $pdo->prepare('UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL');
    $stmt->execute([date('Y-m-d H:i:s'), $userId]);
    $pdo->prepare('DELETE FROM push_subscriptions WHERE user_id = ?')->execute([$userId]);
}

// Exchanges a refresh token for a fresh access token, continuing the same
// session rather than starting a new one. The session's expiry slides
// forward on each use, so an actively-used device never has to re-enter a
// password. No rotation: the refresh token itself stays the same for the
// life of the session.
function sessionRefresh($pdo, $refreshToken) {
    global $CONFIG;
    $accessTtl = $CONFIG['session_access_ttl'] ?? 86400;
    $refreshTtl = $CONFIG['session_refresh_ttl'] ?? 2592000;
    if (!$refreshToken) return false;

    $stmt = $pdo->prepare('SELECT * FROM sessions WHERE refresh_hash = ?');
    $stmt->execute([refreshTokenHash($refreshToken)]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) return false;
    if ($session['revoked_at'] !== null) return false;
    if ($session['expires_at'] < date('Y-m-d H:i:s')) return false;

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare('UPDATE sessions SET last_used_at = ?, expires_at = ? WHERE id = ?');
    $stmt->execute([$now, date('Y-m-d H:i:s', time() + $refreshTtl), $session['id']]);

    sessionSweep($pdo, $session['user_id']);

    return [
        'jwt' => jwtEncode([
            'sub' => (int)$session['user_id'],
            'sid' => $session['id'],
            'iat' => time(),
            'exp' => time() + $accessTtl,
        ]),
        'userId' => (int)$session['user_id'],
        'sessionId' => $session['id'],
    ];
}

// The check every protected request runs. Returns the JWT payload (with the
// session row attached) on success, false otherwise.
function verifyUser($jwt, $pdo) {
    $payload = jwtVerify($jwt);
    if (!$payload) return false;

    // A token minted before sessions existed has no sid and is no longer
    // valid - those users log in once to get a session.
    if (empty($payload['sid'])) return false;

    $session = sessionLookup($pdo, $payload['sid'], $payload['sub']);
    if (!$session) return false;

    sessionTouch($pdo, $session);
    $payload['session'] = $session;
    return $payload;
}
