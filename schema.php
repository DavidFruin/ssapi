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

const SCHEMA_VERSION = 11;

// The only place either entry point opens a database handle. Static, so a
// single request (e.g. a media upload, which used to open three separate
// connections across requireAuth() and its handler) reuses one connection
// instead of re-running schema setup each time.
function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    global $CONFIG;
    // No fallback: config.php always sets this now (S11), and a
    // code-relative default here would silently resurrect the exact
    // docroot-fallback behavior S11 removed, just one layer further down.
    $dbPath = $CONFIG['db_path'];
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
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BufferedStatement::class]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    // WAL: a write (a like, a comment, a session touch) no longer makes every
    // reader wait. The mode is stored in the database file, so this does
    // nothing after the first time; synchronous is per connection. WAL keeps
    // two side files (userdata.db-wal, -shm) next to the database: back up
    // with sqlite3's .backup, or copy all three files together.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    ensureSchema($pdo);
    // Off by default in SQLite, and per connection. Switched on after the
    // migrations, which may need to rebuild a table without tripping it.
    // Only tables that declare foreign keys are affected (follows, so far).
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

// A statement that reads its whole result the first time anything is fetched
// from it, then hands the rows out from memory.
//
// Why: PDO's SQLite driver keeps a read open on a statement until its last
// row has been fetched or the statement is freed. When the same request then
// writes while one of its own earlier SELECTs is still open, and another
// request has written in between, SQLite refuses at once with "database is
// locked"; the busy timeout doesn't cover that case. (Seen for real: seven
// follows sent together, one answered with a server error.) With the result
// read in one go, no read is ever left open behind a later write. Result sets
// here are small: a page, one row, a list of ids.
//
// For the same reason a transaction that reads and then writes must start
// with BEGIN IMMEDIATE, not beginTransaction(): it takes the write lock up
// front, where the busy timeout does apply.
class BufferedStatement extends PDOStatement {
    private ?array $rows = null;   // numeric rows; null until first fetched
    private array $names = [];
    private int $next = 0;

    protected function __construct() {}

    public function execute(?array $params = null): bool {
        $this->rows = null;
        $this->next = 0;
        return parent::execute($params);
    }

    private function load(): void {
        if ($this->rows !== null) return;
        $this->names = [];
        for ($i = 0, $n = $this->columnCount(); $i < $n; $i++) $this->names[] = $this->getColumnMeta($i)['name'];
        $this->rows = parent::fetchAll(PDO::FETCH_NUM);
        parent::closeCursor();
        $this->next = 0;
    }

    private function shape(array $row, int $mode, int $column = 0): mixed {
        switch ($mode) {
            case PDO::FETCH_DEFAULT:
            case PDO::FETCH_ASSOC: return array_combine($this->names, $row);
            case PDO::FETCH_NUM: return $row;
            case PDO::FETCH_COLUMN: return $row[$column];
        }
        throw new LogicException("BufferedStatement: fetch mode $mode is not supported");
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        $this->load();
        if (!isset($this->rows[$this->next])) return false;
        return $this->shape($this->rows[$this->next++], $mode);
    }

    public function fetchColumn(int $column = 0): mixed {
        $this->load();
        if (!isset($this->rows[$this->next])) return false;
        return $this->rows[$this->next++][$column];
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $this->load();
        $rest = array_slice($this->rows, $this->next);
        $this->next = count($this->rows);
        $column = $mode === PDO::FETCH_COLUMN ? (int)($args[0] ?? 0) : 0;
        return array_map(fn($row) => $this->shape($row, $mode, $column), $rest);
    }

    public function closeCursor(): bool {
        $this->rows = null;
        $this->next = 0;
        return parent::closeCursor();
    }
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
        if ($v < 2) migration2($pdo);
        if ($v < 3) migration3($pdo);
        if ($v < 4) migration4($pdo);
        if ($v < 5) migration5($pdo);
        if ($v < 6) migration6($pdo);
        if ($v < 7) migration7($pdo);
        if ($v < 8) migration8($pdo);
        if ($v < 9) migration9($pdo);
        if ($v < 10) migration10($pdo);
        if ($v < 11) migration11($pdo);
        $pdo->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

// Adds each column in $columns ([name => SQL type]) that $table doesn't have
// yet, so a migration can run again without failing.
function addColumns($pdo, $table, array $columns) {
    $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach ($columns as $col => $type) {
        if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE $table ADD COLUMN \"$col\" $type");
    }
}

// Times in UTC, each with the creator's offset from GMT (review S8).
//
// 1. created_tz: "+HH:MM"/"-HH:MM", the offset of the device of the person who
//    created the row (clientTzOffset). NULL for rows from before this, and when
//    an app doesn't send one.
// 2. Every stored "YYYY-MM-DD HH:MM:SS" time so far was written in the host's
//    PHP time zone ($CONFIG['legacy_timezone']); each is converted to UTC.
//    Each column is rewritten in one UPDATE from a mapping of old to new
//    values, so a converted value can never be converted a second time.
//    Unix-second columns (pending_users.dateCreated, auth_attempts, rotated_at)
//    were never zoned and are left alone.
const TIMESTAMP_COLUMNS = [
    'users' => ['created_at', 'last_notifications_seen_at', 'frozen_at', 'terms_accepted_at', 'invite_used_at'],
    'posts' => ['created_at', 'frozen_at'],
    'comments' => ['created_at', 'deleted_at', 'frozen_at'],
    'post_likes' => ['created_at'],
    'follows' => ['created_at'],
    'notifications' => ['created_at'],
    'blocks' => ['created_at'],
    'reports' => ['created_at', 'resolved_at'],
    'media' => ['created_at'],
    'sessions' => ['created_at', 'last_used_at', 'expires_at', 'revoked_at'],
    'invite_codes' => ['created_at', 'expires_at'],
    'staff_actions' => ['created_at'],
    'push_subscriptions' => ['created_at'],
];

function migration11($pdo) {
    global $CONFIG;
    // The conversion below must happen exactly once. created_tz arriving is
    // the marker: if posts already has it, this already ran.
    $converted = in_array('created_tz', array_column($pdo->query('PRAGMA table_info(posts)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
    foreach (['users', 'posts', 'comments', 'post_likes', 'follows', 'notifications', 'blocks', 'reports', 'media', 'sessions', 'invite_codes', 'staff_actions'] as $table) addColumns($pdo, $table, ['created_tz' => 'TEXT']);

    $from = new DateTimeZone($CONFIG['legacy_timezone'] ?? 'UTC');
    $utc = new DateTimeZone('UTC');
    if ($converted || $from->getName() === $utc->getName() || in_array($from->getName(), ['Etc/UTC', 'UTC', 'Etc/GMT', 'GMT'], true)) return;

    $pdo->exec('CREATE TEMP TABLE IF NOT EXISTS ts_map (old TEXT PRIMARY KEY, new TEXT NOT NULL)');
    foreach (TIMESTAMP_COLUMNS as $table => $columns) {
        $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach ($columns as $col) {
            if (!in_array($col, $have, true)) continue;
            $pdo->exec('DELETE FROM ts_map');
            $ins = $pdo->prepare('INSERT INTO ts_map (old, new) VALUES (?, ?)');
            foreach ($pdo->query("SELECT DISTINCT \"$col\" FROM $table WHERE \"$col\" IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $old) {
                if (!is_string($old) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $old)) continue;
                $when = DateTime::createFromFormat('Y-m-d H:i:s', $old, $from);
                if ($when) $ins->execute([$old, $when->setTimezone($utc)->format('Y-m-d H:i:s')]);
            }
            $pdo->exec("UPDATE $table SET \"$col\" = (SELECT new FROM ts_map WHERE old = $table.\"$col\")
                WHERE \"$col\" IN (SELECT old FROM ts_map)");
        }
    }
    $pdo->exec('DROP TABLE ts_map');
}

// Follows as a table (review F2). They used to be a JSON list inside each
// user's row, so "who follows X" meant reading and decoding every user, and
// two people following at the same moment could overwrite each other. The
// old lists are copied in once (only into an empty table, so running this
// again can't bring back a follow someone has since removed); entries that
// point at a deleted account or at the user themselves are left behind.
// created_at is NULL for the oldest entries, which never had a time.
// users.follows itself is left alone and no longer read.
function migration10($pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS follows (
        follower_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        followed_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        created_at TEXT,
        PRIMARY KEY (follower_id, followed_id))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_follows_followed ON follows(followed_id)');

    $userCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('follows', $userCols, true)) return;
    if ((int)$pdo->query('SELECT COUNT(*) FROM follows')->fetchColumn() > 0) return;

    $exists = array_flip($pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN));
    $rows = $pdo->query("SELECT id, follows FROM users WHERE follows IS NOT NULL AND follows NOT IN ('', '[]')")->fetchAll(PDO::FETCH_ASSOC);
    $insert = $pdo->prepare('INSERT OR IGNORE INTO follows (follower_id, followed_id, created_at) VALUES (?, ?, ?)');
    foreach ($rows as $row) {
        $list = json_decode((string)$row['follows'], true);
        if (!is_array($list)) continue;
        foreach ($list as $entry) {
            $followedId = (int)(is_array($entry) ? ($entry['id'] ?? 0) : $entry);
            if ($followedId <= 0 || $followedId === (int)$row['id'] || !isset($exists[$followedId])) continue;
            $time = is_array($entry) && is_string($entry['timestamp'] ?? null) ? $entry['timestamp'] : null;
            $insert->execute([(int)$row['id'], $followedId, $time]);
        }
    }
}

// Refresh-token rotation (review S3). rotating: this session gets a new
// refresh token on every refresh. prev_refresh_hash and rotated_at: the token
// it replaced and when, so an old token turning up later can be told apart
// from an unknown one (see sessionRefresh).
function migration9($pdo) {
    addColumns($pdo, 'sessions', [
        'rotating' => 'INTEGER NOT NULL DEFAULT 0',
        'prev_refresh_hash' => 'TEXT',
        'rotated_at' => 'INTEGER',
    ]);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_prev_refresh ON sessions(prev_refresh_hash)');
}

// Comment replies (one level). comments.parent_id is the id of the top-level
// comment a reply sits under (NULL for a top-level comment). deleted_at marks a
// placeholder ("Comment deleted") kept so the replies under it have somewhere
// to hang; its text is cleared and user_id is 0. notifications.comment_id is the
// comment that caused a notification, so a tap can scroll to it. Guarded, so
// re-running is harmless.
function migration8($pdo) {
    $add = [
        'comments' => ['parent_id' => 'INTEGER', 'deleted_at' => 'TEXT'],
        'notifications' => ['comment_id' => 'INTEGER'],
    ];
    foreach ($add as $table => $columns) {
        $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach ($columns as $col => $type) {
            if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE $table ADD COLUMN \"$col\" $type");
        }
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_parent ON comments(parent_id)');
}

// Staff roles (staff roles plan 1.1): users.role is 'user', 'moderator',
// 'admin' or 'owner'. The column normally exists already (migration6, invites);
// it is added here only if still missing. Anyone with the old is_admin flag
// becomes an admin, but never over an existing role (so the owner stays the
// owner). is_admin stays in the table and is no longer read. Posts and
// comments get frozen_at/frozen_by (hidden until unfrozen, a staff action),
// and staff_actions is the activity log; emails and a short text snapshot are
// copied in so it stays readable after something is deleted. Guarded, so
// re-running is harmless.
function migration7($pdo) {
    $add = [
        'users' => ['role' => "TEXT NOT NULL DEFAULT 'user'"],
        'posts' => ['frozen_at' => 'TEXT', 'frozen_by' => 'INTEGER'],
        'comments' => ['frozen_at' => 'TEXT', 'frozen_by' => 'INTEGER'],
    ];
    foreach ($add as $table => $columns) {
        $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach ($columns as $col => $type) {
            if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE $table ADD COLUMN \"$col\" $type");
        }
    }
    $pdo->exec("UPDATE users SET role = 'admin' WHERE is_admin = 1 AND role = 'user'");
    $pdo->exec('CREATE TABLE IF NOT EXISTS staff_actions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        actor_id INTEGER,
        actor_email TEXT NOT NULL,
        action TEXT NOT NULL,
        target_user_id INTEGER,
        target_email TEXT,
        details TEXT,
        created_at TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_staff_actions_created ON staff_actions(created_at)');
}

// Invites (access plan Step 1): each member can invite one person with a
// short-lived code, the owner as many as they like. invite_codes holds only
// live codes (used and expired ones are deleted). users.invited_by records
// who invited whom and is only returned to the inviter (getMyInvites' joined
// list, 2026-11-27); users.role is 'owner'
// for Dave (set by hand, see the plan's rollout); invite_used_at marks a
// member whose one invite has been spent; welcome_pending is 1 from
// registration until the new member dismisses their one-time welcome.
// pending_users.invite_code remembers the code a half-finished registration
// started with, which is only spent when the account is created. Every
// column is guarded, so re-running this is harmless.
function migration6($pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS invite_codes (
        code TEXT PRIMARY KEY,
        created_by INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        expires_at TEXT NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_invite_codes_created_by ON invite_codes(created_by)');
    $add = [
        'users' => [
            'role' => "TEXT NOT NULL DEFAULT 'user'",
            'invited_by' => 'INTEGER',
            'invite_used_at' => 'TEXT',
            'welcome_pending' => 'INTEGER NOT NULL DEFAULT 0',
        ],
        'pending_users' => ['invite_code' => 'TEXT'],
    ];
    foreach ($add as $table => $columns) {
        $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach ($columns as $col => $type) {
            if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE $table ADD COLUMN \"$col\" $type");
        }
    }
}

// Language (language plan 2.1): the language each user picked, 'en' or 'es'.
// NULL means they never chose; push notifications then use English until the
// app sends its current language up. Guarded, so re-running is harmless.
function migration5($pdo) {
    $have = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('lang', $have, true)) $pdo->exec('ALTER TABLE users ADD COLUMN lang TEXT');
}

// Media details (media pipeline plan B4): stored size and length, total
// bytes on disk (for the per-user quota), the 960 px feed variant and the
// video poster (C2/C3), and whether a video should loop silently like a GIF
// (D6). Rows from before this have NULLs; bin/media-backfill.php fills them.
// The path index serves the posts -> media join (C3).
function migration4($pdo) {
    $have = array_column($pdo->query('PRAGMA table_info(media)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    $add = [
        'width' => 'INTEGER', 'height' => 'INTEGER', 'duration' => 'REAL', 'bytes' => 'INTEGER',
        'variant_path' => 'TEXT', 'poster_path' => 'TEXT', 'loop' => 'INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($add as $col => $type) {
        if (!in_array($col, $have, true)) $pdo->exec("ALTER TABLE media ADD COLUMN \"$col\" $type");
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_media_path ON media(path)');
}

// Moderation (access-and-public-launch plan, Step 1B): blocks between users,
// reports of posts/comments/users, and three users columns -- frozen_at (an
// admin suspended the account), and which terms version the user accepted
// and when. Guarded like migration2, so re-running it is harmless.
function migration3($pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS blocks (
        blocker_id INTEGER NOT NULL,
        blocked_id INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        PRIMARY KEY (blocker_id, blocked_id))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blocks_blocked ON blocks(blocked_id)');

    // snapshot: a copy of the reported text (or the reported user's email) at
    // report time, so the moderation record survives the content being deleted.
    // target_user_id: the author of the reported content, or the reported user.
    $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        reporter_id INTEGER NOT NULL,
        target_type TEXT NOT NULL CHECK (target_type IN ('post','comment','user')),
        target_id TEXT NOT NULL,
        target_user_id INTEGER,
        reason TEXT NOT NULL,
        details TEXT,
        snapshot TEXT,
        created_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','dismissed','actioned')),
        resolved_at TEXT, resolved_by INTEGER, resolution TEXT,
        UNIQUE (reporter_id, target_type, target_id))");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reports_status ON reports(status, created_at)');

    $have = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('frozen_at', $have, true)) $pdo->exec('ALTER TABLE users ADD COLUMN frozen_at TEXT');
    if (!in_array('terms_version_accepted', $have, true)) $pdo->exec('ALTER TABLE users ADD COLUMN terms_version_accepted INTEGER NOT NULL DEFAULT 0');
    if (!in_array('terms_accepted_at', $have, true)) $pdo->exec('ALTER TABLE users ADD COLUMN terms_accepted_at TEXT');
}

// Adds push_subscriptions.kind: which delivery route a row uses. 'webpush' (the
// default, so every existing row keeps working) is a browser's VAPID push
// endpoint; 'expo' is the phone app's Expo push token, sent through Expo's push
// service. The table itself comes from migration1().
function migration2($pdo) {
    $cols = $pdo->query('PRAGMA table_info(push_subscriptions)')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) if ($c['name'] === 'kind') return;
    $pdo->exec("ALTER TABLE push_subscriptions ADD COLUMN kind TEXT NOT NULL DEFAULT 'webpush'");
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
