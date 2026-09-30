<?php
require_once __DIR__ . '/config.php';
// migrate-posts.php - One-time copy of posts out of the users.posts JSON
// blob into the real posts/post_likes tables (see schema.php).
//
// This is a hand-run script, not part of any request path -- nothing calls
// it automatically. Run it on the server when we're ready to cut over:
//
//   php migrate-posts.php --dry-run   # preview counts, writes nothing
//   php migrate-posts.php             # actually copy
//
// Safe to run more than once: a post or like already present is recognized
// and skipped rather than duplicated. users.posts is never modified or
// deleted by this script -- it stays as a fallback until api.php's handlers
// are switched over in a later change and it's been confirmed working.

if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    die('Forbidden: CLI only');
}

require_once __DIR__ . '/schema.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

$dbPath = $CONFIG['db_path'] ?? __DIR__ . '/userdata.db';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureSharedSchema($pdo); // creates posts/post_likes if this is the first run

echo "Migrating posts" . ($dryRun ? " (dry run -- nothing will be written)" : "") . "...\n\n";

$users = $pdo->query('SELECT id, posts FROM users')->fetchAll(PDO::FETCH_ASSOC);

$postsCopied = 0;      // new rows actually inserted
$postsSkipped = 0;     // already migrated on a previous run
$postsRemapped = 0;    // genuine id collisions (two different posts, same id)
$likesCopied = 0;
$malformed = 0;

$pdo->beginTransaction();
try {
    $findById = $pdo->prepare('SELECT user_id, text, created_at FROM posts WHERE id = ?');
    $insertPost = $pdo->prepare('INSERT INTO posts (id, user_id, text, media_url, created_at) VALUES (?, ?, ?, ?, ?)');
    $insertLike = $pdo->prepare('INSERT OR IGNORE INTO post_likes (post_id, user_id, created_at) VALUES (?, ?, ?)');

    foreach ($users as $userRow) {
        $uid = (int)$userRow['id'];
        $posts = $userRow['posts'] ? json_decode($userRow['posts'], true) : [];
        if (!is_array($posts)) continue;

        foreach ($posts as $post) {
            if (!isset($post['id']) || !isset($post['text'])) { $malformed++; continue; }

            $sourceId = (string)$post['id'];
            $text = (string)$post['text'];
            $createdAt = $post['timestamp'] ?: date('Y-m-d H:i:s');
            $mediaUrl = $post['mediaUrl'] ?? null;
            if ($mediaUrl === 'null' || $mediaUrl === '') $mediaUrl = null;

            // Walk sourceId, sourceId.dup1, sourceId.dup2, ... until we find
            // either a free id or a row that's this exact post already
            // copied in (same owner/text/timestamp -- a prior run of this
            // script). A same-id row that differs is a genuine collision
            // (the known "two posts, same second" bug) and gets the next
            // suffix instead of overwriting or dropping data.
            $targetId = $sourceId;
            $suffix = 0;
            $existing = null;
            while (true) {
                $findById->execute([$targetId]);
                $existing = $findById->fetch(PDO::FETCH_ASSOC);
                if (!$existing) break;
                $sameContent = (int)$existing['user_id'] === $uid
                    && $existing['text'] === $text
                    && $existing['created_at'] === $createdAt;
                if ($sameContent) break;
                $suffix++;
                $targetId = $sourceId . '.dup' . $suffix;
            }

            if (!$existing) {
                $insertPost->execute([$targetId, $uid, $text, $mediaUrl, $createdAt]);
                $postsCopied++;
                if ($targetId !== $sourceId) {
                    $postsRemapped++;
                    echo "  COLLISION: post $sourceId (user $uid) was already taken by a different post -- copied as $targetId instead. Its comments/media/notifications still point at $sourceId and need a manual look.\n";
                }
            } else {
                $postsSkipped++;
            }

            $likes = $post['likes'] ?? [];
            if (is_array($likes)) {
                foreach ($likes as $like) {
                    // Old data stores a like as either a bare user id or
                    // {userId, timestamp} -- deleteAccount already has to
                    // handle both, so this mirrors that.
                    $likeUserId = is_array($like) ? ($like['userId'] ?? null) : $like;
                    if ($likeUserId === null || $likeUserId === '') continue;
                    $likeTime = (is_array($like) ? ($like['timestamp'] ?? null) : null) ?: $createdAt;
                    $insertLike->execute([$targetId, (int)$likeUserId, $likeTime]);
                    if ($insertLike->rowCount() > 0) $likesCopied++;
                }
            }
        }
    }

    if ($dryRun) {
        $pdo->rollBack();
    } else {
        $pdo->commit();
    }
} catch (Exception $e) {
    $pdo->rollBack();
    echo "FAILED, nothing written: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nPosts copied:                     $postsCopied\n";
echo "Posts skipped (already migrated): $postsSkipped\n";
echo "Id collisions remapped:           $postsRemapped\n";
echo "Likes copied:                     $likesCopied\n";
if ($malformed) echo "Malformed post entries skipped:   $malformed\n";
echo "\nusers.posts was not modified.\n";
echo $dryRun ? "Dry run only -- rolled back, nothing was written.\n" : "Done.\n";
