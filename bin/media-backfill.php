<?php
// Fills in media columns for rows uploaded before they existed. CLI only.
//
//   php ssapi/bin/media-backfill.php --bytes [--dry-run]
//
// --bytes: media.bytes = the stored file plus its video thumbnail, for every
// row where it's NULL (B4). Run once right after deploying migration4, before
// the per-user quota matters; the quota counts NULL as 0 until then. Users
// already over the quota are listed: they keep what they have but can't upload
// more.
//
// Later plan steps (C3) add more modes to this same script.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

function respond($data, $code = 200) {
    fwrite(STDERR, json_encode($data) . "\n");
    exit(1);
}
function logMsg($m) {
    fwrite(STDERR, "$m\n");
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../schema.php';
require __DIR__ . '/../src/Media/handlers.php';

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$modes = array_values(array_filter($args, fn($a) => $a !== '--dry-run'));
if (!$modes || array_diff($modes, ['--bytes'])) {
    fwrite(STDERR, "usage: php media-backfill.php --bytes [--dry-run]\n");
    exit(2);
}

$pdo = db();

// Path of a video's thumbnail (thumb_<base>.webp next to it).
function thumbFileFor($file) {
    return preg_replace('#/video/([^/]+)\.[^./]+$#', '/video/thumb_$1.webp', $file);
}

if (in_array('--bytes', $modes, true)) {
    $rows = $pdo->query('SELECT id, user_id, type, path FROM media WHERE bytes IS NULL')->fetchAll(PDO::FETCH_ASSOC);
    $update = $pdo->prepare('UPDATE media SET bytes = ? WHERE id = ?');
    $filled = 0;
    $missing = 0;
    foreach ($rows as $r) {
        $file = mediaFilePath($r['path']);
        if ($file === null || !file_exists($file)) {
            $missing++;
            echo "missing file: media #{$r['id']} {$r['path']}\n";
            continue;
        }
        $bytes = filesize($file);
        if ($r['type'] === 'video' && file_exists(thumbFileFor($file))) $bytes += filesize(thumbFileFor($file));
        if (!$dryRun) $update->execute([$bytes, $r['id']]);
        $filled++;
    }
    echo ($dryRun ? '[dry run] would fill' : 'filled') . " bytes on $filled row(s); $missing row(s) had no file.\n";

    $limit = $CONFIG['media_max_user_bytes'] ?? 1_073_741_824;
    $over = $pdo->prepare('SELECT user_id, SUM(bytes) AS used FROM media GROUP BY user_id HAVING SUM(bytes) > ?');
    $over->execute([$limit]);
    foreach ($over->fetchAll(PDO::FETCH_ASSOC) as $u) {
        echo "over the quota: user {$u['user_id']} uses " . round($u['used'] / 1048576) . " MB\n";
    }
}
