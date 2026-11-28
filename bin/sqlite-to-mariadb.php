<?php
// bin/sqlite-to-mariadb.php - the one-time copy of a site's old SQLite
// database (private/userdata.db, at schema 12) into its new MariaDB database.
//
//   php bin/sqlite-to-mariadb.php /path/to/userdata.db            copy, then check
//   php bin/sqlite-to-mariadb.php /path/to/userdata.db --check    check only
//
// Run it from the site's ssapi folder: the target is the database named in
// that site's private/.env (DB_NAME and the rest), the same one the site uses.
// The copy refuses a source that is not at schema 12, a source column that
// has no place in the target (nothing is dropped silently), and a target that
// already holds rows. It copies every table in one transaction, keeping every
// id. The check compares each table's row count and a hash of all its rows,
// read in key order from both sides; it prints one line per table and ends
// with "CHECK OK" (exit 0) or "CHECK FAILED" (exit 1). The source is opened
// read-only and is never changed.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$src = $argv[1] ?? '';
$checkOnly = in_array('--check', $argv, true);
if ($src === '' || !is_file($src)) { fwrite(STDERR, "usage: php bin/sqlite-to-mariadb.php /path/to/userdata.db [--check]\n"); exit(2); }

function respond($d, $c = 200) { fwrite(STDERR, json_encode($d) . "\n"); exit(2); }
require __DIR__ . '/../config.php';
require __DIR__ . '/../schema.php';

// Parents first (follows refers to users). pending_users had no key in SQLite:
// its rows are read in their stored order and get new ids.
const COPY_TABLES = ['users', 'pending_users', 'posts', 'post_likes', 'comments', 'follows', 'notifications', 'blocks',
    'reports', 'media', 'sessions', 'push_subscriptions', 'invite_codes', 'staff_actions', 'auth_attempts'];

$lite = new PDO('sqlite:' . $src, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$liteVersion = (int)$lite->query('PRAGMA user_version')->fetchColumn();
if ($liteVersion !== 12) { fwrite(STDERR, "REFUSED: the source is at schema $liteVersion, not 12 (run it once with ssapi 3.0.0 first)\n"); exit(2); }
$my = db();   // builds the tables if the database is new

function liteColumns(PDO $lite, string $t): array {
    return array_column($lite->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
}
function myColumns(PDO $my, string $t): array {
    $s = $my->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $s->execute([$t]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}
// The columns both sides compare on, and the order rows are read in.
function keyOrder(string $t, array $cols): string {
    $keys = ['post_likes' => 'post_id, user_id', 'follows' => 'follower_id, followed_id', 'blocks' => 'blocker_id, blocked_id',
        'auth_attempts' => 'attempt_key', 'invite_codes' => 'code', 'sessions' => 'id', 'posts' => 'id'];
    return $keys[$t] ?? (in_array('id', $cols, true) ? 'id' : '');
}
function q(string $c): string { return '`' . str_replace('`', '``', $c) . '`'; }

$plan = [];
foreach (COPY_TABLES as $t) {
    $have = $lite->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = " . $lite->quote($t))->fetchColumn();
    if (!(int)$have) { fwrite(STDERR, "REFUSED: the source has no table $t\n"); exit(2); }
    $lc = liteColumns($lite, $t);
    $mc = myColumns($my, $t);
    $missing = array_values(array_diff($lc, $mc));
    if ($missing) { fwrite(STDERR, "REFUSED: $t." . implode(", $t.", $missing) . " has no place in the target\n"); exit(2); }
    $plan[$t] = $lc;
}
$extra = array_diff($lite->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN), COPY_TABLES);
if ($extra) { fwrite(STDERR, 'REFUSED: the source has tables this tool does not know: ' . implode(', ', $extra) . "\n"); exit(2); }

if (!$checkOnly) {
    foreach (COPY_TABLES as $t) {
        if ((int)$my->query("SELECT COUNT(*) FROM $t")->fetchColumn() > 0) { fwrite(STDERR, "REFUSED: the target table $t already has rows\n"); exit(2); }
    }
    $my->exec('SET FOREIGN_KEY_CHECKS = 0');
    $my->beginTransaction();
    try {
        foreach ($plan as $t => $cols) {
            $order = keyOrder($t, $cols) ?: 'rowid';
            $ins = $my->prepare("INSERT INTO $t (" . implode(', ', array_map('q', $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
            $n = 0;
            foreach ($lite->query("SELECT " . implode(', ', array_map(fn($c) => '"' . $c . '"', $cols)) . " FROM $t ORDER BY $order") as $row) {
                $ins->execute(array_values($row));
                $n++;
            }
            echo "copied $t: $n row(s)\n";
        }
        $my->commit();
    } catch (Throwable $e) {
        if ($my->inTransaction()) $my->rollBack();
        $my->exec('SET FOREIGN_KEY_CHECKS = 1');
        fwrite(STDERR, "COPY FAILED, nothing was kept: " . $e->getMessage() . "\n");
        exit(1);
    }
    $my->exec('SET FOREIGN_KEY_CHECKS = 1');
}

// The check: same rows, same values, both sides read in the same key order.
// A value is compared as text (NULL apart), so 7 and "7" are the same, as are
// SQLite's and MariaDB's spellings of one float.
$ok = true;
foreach ($plan as $t => $cols) {
    $order = keyOrder($t, $cols);
    $hash = function (iterable $rows) use ($cols): array {
        $h = hash_init('sha256'); $n = 0;
        foreach ($rows as $row) {
            $vals = [];
            foreach ($cols as $c) $vals[] = $row[$c] === null ? null : (is_float($row[$c]) ? rtrim(rtrim(sprintf('%.10F', $row[$c]), '0'), '.') : (string)$row[$c]);
            hash_update($h, json_encode($vals, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            $n++;
        }
        return [$n, hash_final($h)];
    };
    $liteSel = 'SELECT ' . implode(', ', array_map(fn($c) => '"' . $c . '"', $cols)) . " FROM $t" . ' ORDER BY ' . ($order ?: 'rowid');
    $mySel = 'SELECT ' . implode(', ', array_map('q', $cols)) . " FROM $t" . ' ORDER BY ' . ($order ?: 'id');
    [$ln, $lh] = $hash($lite->query($liteSel)->fetchAll(PDO::FETCH_ASSOC));
    [$mn, $mh] = $hash($my->query($mySel)->fetchAll(PDO::FETCH_ASSOC));
    $same = $ln === $mn && $lh === $mh;
    $ok = $ok && $same;
    printf("%-4s %-19s rows %d / %d  hash %s / %s\n", $same ? 'OK' : 'DIFF', $t, $ln, $mn, substr($lh, 0, 16), substr($mh, 0, 16));
}
echo $ok ? "CHECK OK\n" : "CHECK FAILED\n";
exit($ok ? 0 : 1);
