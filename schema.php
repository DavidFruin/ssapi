<?php
// schema.php - The one database connection, db(), shared by every entry
// point, and the tables it needs.
//
// The database is MariaDB (Dave, 2026-11-27; it was one SQLite file,
// private/userdata.db, until then). db() is the only place a connection is
// opened; the table setup runs once per database, not once per request
// (ss_schema holds the version). bin/sqlite-to-mariadb.php copies an old
// SQLite file (at schema 12) into a new, empty database once.
//
// How the move keeps SQLite's behaviour:
// - Text compares exactly, as SQLite did: every table defaults to
//   utf8mb4_nopad_bin (case-sensitive, and "a" is not "a "). email and
//   username alone ignore case (utf8mb4_general_nopad_ci), as their NOCASE
//   indexes did in SQLite.
// - Times stay "YYYY-MM-DD HH:MM:SS" strings in UTC, so no answer changes.
// - An UPDATE's rowCount() is the rows it matched (MYSQL_ATTR_FOUND_ROWS), as
//   in SQLite, not only the rows whose values changed.
// - A unit that reads and then writes (SQLite's BEGIN IMMEDIATE) runs between
//   beginWrite() and commitWrite(): one such unit at a time per database.

const SCHEMA_VERSION = 12;

// Exact text, the default of every table and of the connection.
const DB_COLLATE = 'utf8mb4_nopad_bin';
// Text that ignores case (email, username).
const DB_COLLATE_NOCASE = 'utf8mb4_general_nopad_ci';

// The only place either entry point opens a database handle. Static, so one
// request reuses one connection.
function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    global $CONFIG;
    $db = $CONFIG['db'] ?? [];
    if (empty($db['name']) || empty($db['user'])) {
        // Fails closed (S11): no database settings means this deploy is wrong.
        error_log('ssapi: database settings missing (DB_NAME, DB_USER in private/.env)');
        respond(['valid' => false, 'error' => 'Server misconfigured'], 500);
    }
    $where = !empty($db['socket']) ? 'unix_socket=' . $db['socket'] : 'host=' . ($db['host'] ?: 'localhost') . (!empty($db['port']) ? ';port=' . (int)$db['port'] : '');
    $pdo = new PDO("mysql:$where;dbname={$db['name']};charset=utf8mb4", $db['user'], $db['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,     // real prepared statements: LIMIT ? and native int/float results
        PDO::ATTR_TIMEOUT => 5,
        PDO::MYSQL_ATTR_FOUND_ROWS => true,      // rowCount() of an UPDATE = rows matched, as in SQLite
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE " . DB_COLLATE);
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE'");
    $pdo->exec("SET SESSION time_zone = '+00:00'");
    ensureSchema($pdo);
    return $pdo;
}

// A unit that reads and then writes (registration, posting with media, ...):
// at most one at a time per database, the rule SQLite's BEGIN IMMEDIATE gave.
// The lock name holds the database name, so two sites on one server (dev and
// production) never wait for each other. GET_LOCK holds on one server only;
// a cluster would need another way.
function writeLockName(PDO $pdo): string {
    return 'ssapi_write:' . $pdo->query('SELECT DATABASE()')->fetchColumn();
}

function beginWrite(PDO $pdo): void {
    $s = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $s->execute([writeLockName($pdo)]);
    if ((int)$s->fetchColumn() !== 1) throw new RuntimeException('ssapi: the write lock was not free within 10 s');
    try {
        $pdo->beginTransaction();
    } catch (Throwable $e) {
        releaseWriteLock($pdo);
        throw $e;
    }
}

function commitWrite(PDO $pdo): void {
    try {
        $pdo->commit();
    } finally {
        releaseWriteLock($pdo);
    }
}

function rollbackWrite(PDO $pdo): void {
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
    } finally {
        releaseWriteLock($pdo);
    }
}

function releaseWriteLock(PDO $pdo): void {
    $s = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $s->execute([writeLockName($pdo)]);
}

// Maps a stored media URL path ("/media/<uid>/<type>/<file>") to its file
// on disk under $CONFIG['media_dir'] (deploy layout: L1). Returns null for
// anything that isn't a plain path under /media/ -- callers skip rather
// than guess, since a wrong path here means either corrupt data or a
// deliberate attempt to make the server touch a file outside media/.
function mediaFilePath($urlPath) {
    global $CONFIG;
    if (!is_string($urlPath) || !str_starts_with($urlPath, '/media/') || str_contains($urlPath, '..')) return null;
    return $CONFIG['media_dir'] . substr($urlPath, strlen('/media'));
}

function schemaVersion(PDO $pdo): int {
    $has = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ss_schema'")->fetchColumn();
    if (!(int)$has) return 0;
    return (int)$pdo->query('SELECT COALESCE(MAX(version), 0) FROM ss_schema')->fetchColumn();
}

// Creates the tables of a new database, and later runs migration13() and on
// for one that is behind. A table change in MariaDB commits by itself, so the
// work runs under a named lock (and re-reads the version under it): two
// requests on a brand-new database cannot both build the tables.
function ensureSchema(PDO $pdo) {
    if (schemaVersion($pdo) >= SCHEMA_VERSION) return;
    $lock = 'ssapi_schema:' . $pdo->query('SELECT DATABASE()')->fetchColumn();
    $s = $pdo->prepare('SELECT GET_LOCK(?, 30)');
    $s->execute([$lock]);
    if ((int)$s->fetchColumn() !== 1) throw new RuntimeException('ssapi: the schema lock was not free within 30 s');
    try {
        $v = schemaVersion($pdo);
        if ($v < 12) createTables($pdo);
        // Later: if ($v < 13) migration13($pdo); ... each one re-runnable.
        $pdo->exec('CREATE TABLE IF NOT EXISTS ss_schema (version INT NOT NULL PRIMARY KEY, applied_at VARCHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=' . DB_COLLATE);
        $pdo->prepare('INSERT IGNORE INTO ss_schema (version, applied_at) VALUES (?, ?)')->execute([SCHEMA_VERSION, gmdate('Y-m-d H:i:s')]);
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}

// Every table at schema 12, in MariaDB terms. Column for column the same as
// the SQLite tables after their migrations 1 to 12 (so the copy tool can move
// rows over unchanged). Re-runnable: CREATE TABLE IF NOT EXISTS throughout.
// Text that is a key or indexed is VARCHAR; free text is TEXT; unix seconds
// are BIGINT. Times are VARCHAR(32) "YYYY-MM-DD HH:MM:SS" (UTC).
//
// Notes carried over from the SQLite history:
// - users.posts, follows, followers, jwt and is_admin are no longer read
//   (posts, follows and sessions have their own tables; role replaced
//   is_admin). They are kept so a copy of an old database loses nothing.
// - users.role is 'user', 'moderator', 'admin' or 'owner'; invited_by is only
//   returned to the inviter; welcome_pending is 1 until the one-time welcome
//   is dismissed; username is NULL until chosen (others see the email).
// - created_tz is the creator's offset from GMT ("-08:00"), NULL when unknown.
// - comments.parent_id: the top-level comment a reply sits under; deleted_at
//   marks a placeholder kept for its replies (text cleared, user_id 0).
// - media: width/height/duration/bytes, the 960 px variant and video poster,
//   and loop (a video that plays silently like a GIF).
// - sessions: one row per login; rotating/prev_refresh_hash/rotated_at are
//   the refresh-token rotation (review S3).
// - push_subscriptions.kind: 'webpush' (a browser) or 'expo' (the phone app).
function createTables(PDO $pdo) {
    $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=' . DB_COLLATE;
    $ci = 'CHARACTER SET utf8mb4 COLLATE ' . DB_COLLATE_NOCASE;
    $time = 'VARCHAR(32)';

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) $ci NOT NULL,
        password VARCHAR(255) NOT NULL,
        posts LONGTEXT, follows LONGTEXT, followers LONGTEXT, jwt TEXT,
        created_at $time,
        reset_otp VARCHAR(255), reset_expires BIGINT DEFAULT 0,
        last_notifications_seen_at $time,
        is_admin INT DEFAULT 0,
        theme VARCHAR(255) NOT NULL DEFAULT 'light',
        hand VARCHAR(255) NOT NULL DEFAULT 'right',
        lang VARCHAR(255),
        frozen_at $time,
        terms_version_accepted INT NOT NULL DEFAULT 0,
        terms_accepted_at $time,
        role VARCHAR(16) NOT NULL DEFAULT 'user',
        invited_by INT,
        invite_used_at $time,
        welcome_pending INT NOT NULL DEFAULT 0,
        created_tz VARCHAR(16),
        username VARCHAR(64) $ci,
        UNIQUE KEY idx_users_email_nocase (email),
        UNIQUE KEY idx_users_username (username),
        KEY idx_users_role (role))$t");

    // No key in SQLite; id is new here (the copy tool fills it).
    $pdo->exec("CREATE TABLE IF NOT EXISTS pending_users (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email TEXT, password TEXT, otp TEXT, dateCreated BIGINT, invite_code TEXT)$t");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        recipient_id INT NOT NULL,
        actor_id INT NOT NULL,
        actor_email TEXT NOT NULL,
        type VARCHAR(32) NOT NULL,
        post_id VARCHAR(64),
        created_at $time NOT NULL,
        comment_id INT,
        created_tz VARCHAR(16),
        KEY idx_notifications_recipient (recipient_id),
        KEY idx_notifications_recipient_created (recipient_id, created_at))$t");

    $pdo->exec("CREATE TABLE IF NOT EXISTS comments (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        post_id VARCHAR(64) NOT NULL,
        user_id INT NOT NULL,
        comment_text TEXT NOT NULL,
        created_at $time NOT NULL,
        parent_id INT,
        deleted_at $time,
        frozen_at $time,
        frozen_by INT,
        created_tz VARCHAR(16),
        KEY idx_comments_post_created (post_id, created_at),
        KEY idx_comments_parent (parent_id))$t");

    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (
        attempt_key VARCHAR(700) NOT NULL PRIMARY KEY,
        failures INT NOT NULL,
        window_start BIGINT NOT NULL,
        locked_until BIGINT NOT NULL DEFAULT 0)$t");

    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        endpoint VARCHAR(768) NOT NULL,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        created_at $time NOT NULL,
        kind VARCHAR(16) NOT NULL DEFAULT 'webpush',
        session_id VARCHAR(128),
        UNIQUE KEY idx_push_subscriptions_endpoint (endpoint),
        KEY idx_push_subscriptions_user (user_id))$t");

    $pdo->exec("CREATE TABLE IF NOT EXISTS media (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        filename TEXT NOT NULL,
        type VARCHAR(16) NOT NULL,
        path VARCHAR(512) NOT NULL,
        created_at $time NOT NULL,
        post_id VARCHAR(64),
        width INT, height INT, duration DOUBLE, bytes BIGINT,
        variant_path TEXT, poster_path TEXT,
        `loop` INT NOT NULL DEFAULT 0,
        created_tz VARCHAR(16),
        KEY idx_media_user_id (user_id),
        KEY idx_media_path (path))$t");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
        id VARCHAR(128) NOT NULL PRIMARY KEY,
        user_id INT NOT NULL,
        refresh_hash VARCHAR(128) NOT NULL,
        created_at $time NOT NULL,
        last_used_at $time,
        expires_at $time NOT NULL,
        revoked_at $time,
        device_name TEXT,
        user_agent TEXT,
        rotating INT NOT NULL DEFAULT 0,
        prev_refresh_hash VARCHAR(128),
        rotated_at BIGINT,
        created_tz VARCHAR(16),
        KEY idx_sessions_user (user_id),
        KEY idx_sessions_refresh (refresh_hash),
        KEY idx_sessions_prev_refresh (prev_refresh_hash))$t");

    // id keeps the "ownerId.timestamp" shape that comments.post_id,
    // media.post_id, notifications.post_id and every client use.
    $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
        id VARCHAR(64) NOT NULL PRIMARY KEY,
        user_id INT NOT NULL,
        text TEXT NOT NULL,
        media_url TEXT,
        created_at $time NOT NULL,
        frozen_at $time,
        frozen_by INT,
        created_tz VARCHAR(16),
        KEY idx_posts_user_created (user_id, created_at))$t");

    // The primary key doubles as "can't like the same post twice".
    $pdo->exec("CREATE TABLE IF NOT EXISTS post_likes (
        post_id VARCHAR(64) NOT NULL,
        user_id INT NOT NULL,
        created_at $time NOT NULL,
        created_tz VARCHAR(16),
        PRIMARY KEY (post_id, user_id),
        KEY idx_post_likes_user (user_id))$t");

    $pdo->exec("CREATE TABLE IF NOT EXISTS blocks (
        blocker_id INT NOT NULL,
        blocked_id INT NOT NULL,
        created_at $time NOT NULL,
        created_tz VARCHAR(16),
        PRIMARY KEY (blocker_id, blocked_id),
        KEY idx_blocks_blocked (blocked_id))$t");

    // snapshot: the reported text (or user's email) at report time, so the
    // record survives the content being deleted.
    $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        reporter_id INT NOT NULL,
        target_type VARCHAR(16) NOT NULL CHECK (target_type IN ('post','comment','user')),
        target_id VARCHAR(64) NOT NULL,
        target_user_id INT,
        reason TEXT NOT NULL,
        details TEXT,
        snapshot TEXT,
        created_at $time NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'open' CHECK (status IN ('open','dismissed','actioned')),
        resolved_at $time, resolved_by INT, resolution TEXT,
        created_tz VARCHAR(16),
        UNIQUE KEY uq_reports_target (reporter_id, target_type, target_id),
        KEY idx_reports_status (status, created_at))$t");

    // Only live codes (used and expired ones are deleted).
    $pdo->exec("CREATE TABLE IF NOT EXISTS invite_codes (
        code VARCHAR(64) NOT NULL PRIMARY KEY,
        created_by INT NOT NULL,
        created_at $time NOT NULL,
        expires_at $time NOT NULL,
        created_tz VARCHAR(16),
        KEY idx_invite_codes_created_by (created_by))$t");

    // The staff activity log; emails and a text snapshot are copied in so it
    // stays readable after something is deleted.
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_actions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        actor_id INT,
        actor_email TEXT NOT NULL,
        action VARCHAR(64) NOT NULL,
        target_user_id INT,
        target_email TEXT,
        details TEXT,
        created_at $time NOT NULL,
        created_tz VARCHAR(16),
        KEY idx_staff_actions_created (created_at))$t");

    // Follows: a removed account takes its follows with it.
    $pdo->exec("CREATE TABLE IF NOT EXISTS follows (
        follower_id INT NOT NULL,
        followed_id INT NOT NULL,
        created_at $time,
        created_tz VARCHAR(16),
        PRIMARY KEY (follower_id, followed_id),
        KEY idx_follows_followed (followed_id),
        CONSTRAINT fk_follows_follower FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_follows_followed FOREIGN KEY (followed_id) REFERENCES users(id) ON DELETE CASCADE)$t");
}
