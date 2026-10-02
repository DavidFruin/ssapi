<?php
// schema.php - Table definitions and the one db() shared by every entry
// point.
//
// Until P3, api.php and media.php each opened their own PDO handle and each
// ran their own ~15 CREATE TABLE/INDEX/PRAGMA statements on every single
// request -- two full schema scans per request minimum, three for a media
// upload (media.php's requireAuth() and its handler each called db()).
// db() below is now the only place either entry point opens a connection,
// and the schema work underneath it runs exactly once per database via
// PRAGMA user_version, not once per request.
//
// ensureSharedSchema() stays as its own function, not folded into
// migration1() directly, purely for readability -- it groups the tables
// both entry points need (media.php writes some of these rows, api.php
// reads them) separately from everything else migration1() sets up.
// migration1() calls it below so a fresh database still gets the same
// tables as everything else.

const SCHEMA_VERSION = 1;

// The only place either entry point opens a database handle. Static, so a
// single request (e.g. a media upload, which used to open three separate
// connections across requireAuth() and its handler) reuses one connection
// instead of re-running schema setup each time.
function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    global $CONFIG;
    $dbPath = $CONFIG['db_path'] ?? __DIR__ . '/userdata.db';
    // Fails closed (S11) instead of silently creating userdata.db wherever
    // dirname($dbPath) happens to resolve to -- config.php no longer has a
    // docroot fallback, so a missing private/ means something is actually
    // wrong with this deploy, not a signal to improvise a new location.
    if (!is_dir(dirname($dbPath))) {
        error_log('ssapi: private/ directory missing');
        respond(['valid' => false, 'error' => 'Server misconfigured'], 500);
    }
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    ensureSchema($pdo);
    return $pdo;
}

// Runs migration1(), migration2(), etc. in order, but only the ones newer
// than what this database has already applied -- PRAGMA user_version is
// SQLite's own built-in integer for exactly this. The second user_version
// read is deliberate: it re-checks under the write lock in case another
// request's migration finished while this one was waiting for BEGIN
// IMMEDIATE, so two requests racing on a brand-new database can't both try
// to run migration1().
function ensureSchema($pdo) {
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() >= SCHEMA_VERSION) return;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($v < 1) migration1($pdo);
        $pdo->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

// Everything that used to run on every request in api.php's db(), verbatim
// and still idempotent (CREATE TABLE/INDEX IF NOT EXISTS throughout), plus:
// - users/pending_users, which predate this repo and are created in no
//   other source file (see the ssapi improvement plan's Phase 0.1) --
//   harmless CREATE IF NOT EXISTS on every database that already has them.
// - P4's comments/notifications composite indexes.
// - idx_users_email_nocase (S14), skipped with a log line rather than
//   thrown if existing data already has case-insensitive duplicate emails.
//
// Never edit this once it has shipped to any real database -- add
// migration2() and bump SCHEMA_VERSION instead. A migration that changes
// after it may have already run on dev or prod can leave those databases
// permanently out of sync with a fresh install.
function migration1($pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        password TEXT NOT NULL,
        posts TEXT, follows NUMERIC, followers NUMERIC, jwt TEXT,
        created_at TEXT,
        reset_otp TEXT, reset_expires INTEGER DEFAULT 0,
        last_notifications_seen_at TEXT,
        is_admin INTEGER DEFAULT 0)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS pending_users (email TEXT, password TEXT, otp TEXT, dateCreated INTEGER)");

    try {
        $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $hasTheme = false;
        $hasHand = false;
        foreach ($cols as $c) {
            if ($c['name'] === 'theme') $hasTheme = true;
            if ($c['name'] === 'hand') $hasHand = true;
        }
        if (!$hasTheme) $pdo->exec("ALTER TABLE users ADD COLUMN theme TEXT NOT NULL DEFAULT 'light'");
        if (!$hasHand) $pdo->exec("ALTER TABLE users ADD COLUMN hand TEXT NOT NULL DEFAULT 'right'");
    } catch (Exception $e) {}

    // Closes the race finishRegister's own transaction (S14) can't close by
    // itself: two concurrent inserts for the same email can both pass that
    // transaction's own duplicate check before either commits. Skipped (and
    // logged, not thrown) if existing data already has case-insensitive
    // duplicates -- creating the index would just fail outright.
    try {
        $dupes = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT LOWER(email) FROM users GROUP BY 1 HAVING COUNT(*) > 1)")->fetchColumn();
        if ($dupes === 0) {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email_nocase ON users(email COLLATE NOCASE)');
        } else {
            error_log("ssapi migration1: skipped idx_users_email_nocase, $dupes duplicate email(s) exist");
        }
    } catch (Exception $e) {}

    $pdo->exec('CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT, recipient_id INTEGER NOT NULL,
        actor_id INTEGER NOT NULL, actor_email TEXT NOT NULL, type TEXT NOT NULL,
        post_id TEXT, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_recipient ON notifications(recipient_id)');
    // Notifications are listed filtered by recipient_id and sorted by
    // created_at (P4) -- the index above only covers the filter column, so
    // getNotifications still sorted the whole matching set without one.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_recipient_created ON notifications(recipient_id, created_at)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT, post_id TEXT NOT NULL,
        user_id INTEGER NOT NULL, comment_text TEXT NOT NULL, created_at TEXT NOT NULL)');
    // comments.post_id had no index at all (P4) -- every comment count and
    // comment list scanned the whole table.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_post_created ON comments(post_id, created_at)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS auth_attempts (
        attempt_key TEXT PRIMARY KEY, failures INTEGER NOT NULL,
        window_start INTEGER NOT NULL, locked_until INTEGER NOT NULL DEFAULT 0)');

    // `media` and its post_id migration live in ensureSharedSchema() below -
    // media.php needs the same table, and keeping one copy is the whole
    // point of that function. One row per browser/device a user has
    // enabled push on. endpoint is unique so re-subscribing the same
    // browser replaces its row instead of piling up duplicates.
    $pdo->exec('CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
        endpoint TEXT NOT NULL UNIQUE, p256dh TEXT NOT NULL, auth TEXT NOT NULL,
        created_at TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_push_subscriptions_user ON push_subscriptions(user_id)');

    ensureSharedSchema($pdo);
}

// Tables both entry points need (media.php writes some of these rows,
// api.php reads them) -- kept as its own function purely for readability,
// see this file's header comment. Idempotent: safe to call more than once.
function ensureSharedSchema($pdo) {
    // Uploaded files. Both entry points need this: media.php writes the rows,
    // api.php reads them when a post is created or an account is deleted. It
    // used to be declared separately in each, which is what this file exists
    // to stop.
    $pdo->exec('CREATE TABLE IF NOT EXISTS media (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        filename TEXT NOT NULL,
        type TEXT NOT NULL,
        path TEXT NOT NULL,
        created_at TEXT NOT NULL,
        post_id TEXT)');

    // Every read of this table filters on user_id -- on its own, together
    // with path, and when clearing out a deleted account. The index existed
    // on both live databases but in no source file, so it had been created by
    // hand at some point and a fresh install would have quietly gone without
    // it and scanned the table instead.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_media_user_id ON media(user_id)');

    // Databases created before post_id existed.
    try {
        $cols = $pdo->query("PRAGMA table_info(media)")->fetchAll(PDO::FETCH_ASSOC);
        $hasPostId = false;
        foreach ($cols as $c) if ($c['name'] === 'post_id') $hasPostId = true;
        if (!$hasPostId) $pdo->exec('ALTER TABLE media ADD COLUMN post_id TEXT');
    } catch (Exception $e) {}

    // One row per login. Replaces the single `users.jwt` slot, which could
    // only ever hold one token and so logged a user out everywhere as soon
    // as they logged in anywhere else. `users.jwt` is deliberately left in
    // place but no longer read.
    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
        id TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL,
        refresh_hash TEXT NOT NULL,
        created_at TEXT NOT NULL,
        last_used_at TEXT,
        expires_at TEXT NOT NULL,
        revoked_at TEXT,
        device_name TEXT,
        user_agent TEXT)');

    // user_id: listing a user's sessions and enforcing the per-user cap.
    // refresh_hash: the lookup every token refresh does.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_refresh ON sessions(refresh_hash)');

    // Ties a push subscription to the session that registered it, so
    // revoking a device also stops its notifications. Nullable: rows that
    // predate sessions have no session to point at.
    try {
        $cols = $pdo->query("PRAGMA table_info(push_subscriptions)")->fetchAll(PDO::FETCH_ASSOC);
        if ($cols) {
            $hasSessionId = false;
            foreach ($cols as $c) if ($c['name'] === 'session_id') $hasSessionId = true;
            if (!$hasSessionId) $pdo->exec('ALTER TABLE push_subscriptions ADD COLUMN session_id TEXT');
        }
    } catch (Exception $e) {}

    // Real posts table, replacing the legacy users.posts JSON blob (that
    // column is still there, unread, as a fallback -- see the simple-social
    // war-table note's "Open -- database" section for dropping it).
    //
    // id keeps the existing "ownerId.timestamp" shape so comments.post_id,
    // media.post_id and notifications.post_id -- and every client -- don't
    // need to change.
    $pdo->exec('CREATE TABLE IF NOT EXISTS posts (
        id TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL,
        text TEXT NOT NULL,
        media_url TEXT,
        created_at TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_posts_user_created ON posts(user_id, created_at)');

    // One row per like, instead of an array embedded in the post. The
    // primary key doubles as "can't like the same post twice".
    $pdo->exec('CREATE TABLE IF NOT EXISTS post_likes (
        post_id TEXT NOT NULL,
        user_id INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        PRIMARY KEY (post_id, user_id))');
    // deleteAccount removes every like a departing user gave out.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_post_likes_user ON post_likes(user_id)');
}
