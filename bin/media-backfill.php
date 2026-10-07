<?php
// Fills in media columns for rows uploaded before they existed. CLI only.
//
//   php ssapi/bin/media-backfill.php --bytes [--details] [--dry-run]
//
// --bytes: media.bytes = the stored file plus its video thumbnail, for every
// row where it's NULL (B4). Run once right after deploying migration4, before
// the per-user quota matters; the quota counts NULL as 0 until then. Users
// already over the quota are listed: they keep what they have but can't upload
// more.
//
// --details (C3): for rows missing them, image width/height and the 960 px
// feed variant (only for images bigger than that), video width/height/
// duration and poster (the existing thumb_*.webp, or a new one), audio
// duration. New files are added to bytes. Needs the GD extension in the
// CLI PHP, like uploads do in the web PHP.
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
if (!$modes || array_diff($modes, ['--bytes', '--details'])) {
    fwrite(STDERR, "usage: php media-backfill.php [--bytes] [--details] [--dry-run]\n");
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

if (in_array('--details', $modes, true)) {
    if (!function_exists('imagecreatefromwebp')) {
        fwrite(STDERR, "--details needs PHP's GD extension in this (CLI) PHP\n");
        exit(2);
    }
    $rows = $pdo->query("SELECT * FROM media WHERE width IS NULL OR duration IS NULL
        OR (type = 'video' AND poster_path IS NULL) OR (type = 'image' AND variant_path IS NULL)")->fetchAll(PDO::FETCH_ASSOC);
    $set = $pdo->prepare('UPDATE media SET width = ?, height = ?, duration = ?, variant_path = ?, poster_path = ?, bytes = ? WHERE id = ?');
    $changed = 0;
    foreach ($rows as $r) {
        $file = mediaFilePath($r['path']);
        if ($file === null || !file_exists($file)) continue;
        $info = mediaOutputInfo($file, $r['type']);
        $width = $r['width'] ?? $info['width'];
        $height = $r['height'] ?? $info['height'];
        $duration = $r['duration'] ?? $info['duration'];
        $variant = $r['variant_path'];
        $poster = $r['poster_path'];
        $bytes = $r['bytes'];
        $notes = [];

        if ($r['type'] === 'image' && $variant === null && max((int)$width, (int)$height) > MEDIA_VARIANT_SIDE) {
            $variantFile = preg_replace('#\.webp$#', '_960.webp', $file);
            $notes[] = 'variant';
            if (!$dryRun) {
                $src = @imagecreatefromwebp($file);
                if ($src) {
                    $small = scaledCopy($src, MEDIA_VARIANT_SIDE, true);
                    if (imagewebp($small, $variantFile, 80)) {
                        $variant = preg_replace('#\.webp$#', '_960.webp', $r['path']);
                        if ($bytes !== null) $bytes += filesize($variantFile);
                    }
                    imagedestroy($small);
                    imagedestroy($src);
                }
            }
        }
        if ($r['type'] === 'video' && $poster === null) {
            $thumbFile = thumbFileFor($file);
            $thumbUrl = preg_replace('#/video/([^/]+)\.[^./]+$#', '/video/thumb_$1.webp', $r['path']);
            if (file_exists($thumbFile)) {
                $poster = $thumbUrl;
                $notes[] = 'poster (existing)';
            } else {
                $notes[] = 'poster (new)';
                if (!$dryRun && createVideoThumbnail($file, $thumbFile, (float)$duration)) {
                    $poster = $thumbUrl;
                    if ($bytes !== null) $bytes += filesize($thumbFile);
                }
            }
        }
        if ($r['width'] === null && $width !== null) $notes[] = "{$width}x{$height}";
        if ($r['duration'] === null && $duration !== null) $notes[] = "{$duration}s";
        if (!$notes) continue;
        $changed++;
        echo ($dryRun ? '[dry run] ' : '') . "media #{$r['id']} ({$r['type']}): " . implode(', ', $notes) . "\n";
        if (!$dryRun) $set->execute([$width, $height, $duration, $variant, $poster, $bytes, $r['id']]);
    }
    echo ($dryRun ? '[dry run] would update' : 'updated') . " $changed row(s).\n";
}
