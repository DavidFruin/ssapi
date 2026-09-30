<?php
// src/Posts/handlers.php - Posts module: post CRUD, feed assembly, likes,
// and post previews. Likes (post_likes table) are grouped in here rather
// than a separate module since they're exclusively post-scoped data and
// share getLikesForPostIds()/postRowToApi() with every other posts
// handler - splitting them out would just recreate the same coupling
// through a different file. NOTE: this corrects an omission in the
// original split-backend-modules.md module list, which named the other
// six modules but never assigned likePost/unlikePost/getPostLikes
// anywhere; they belong here, not left behind in api.php.
//
// Loaded via Composer's "files" autoload (see composer.json), same as
// the other modules - global-namespace functions, not classes.
//
// Depends on shared Core helpers still defined in api.php: bad(), good(),
// respond(), validateContent(), extractMentions(), notifyMentions(),
// hydrateMentions(), createNotification() (via notifyMentions/likePost/
// unlikePost). Those are cross-module (comments use several of them too),
// so they aren't moving here.
//
// handle_deletePost reaches directly into the media table/filesystem to
// clean up a deleted post's attachment - a cross-module touch, same kind
// of thing deleteAccount does across nearly every module. Left as-is for
// this pass (folder reorg, not a rewrite); revisit if/when module
// boundaries need to be enforced for real.

const PREVIEW_MAX_CHARS = 25;

// Swaps @[id] tokens for @email across a batch of texts, in one query for the
// whole batch rather than one per text. Used where a mention has to survive as
// plain readable text (post previews) instead of being linkified client-side.
// An id with no user left behind reads as "@someone".
function resolveMentionTokens($pdo, $texts) {
    $ids = [];
    foreach ($texts as $text) {
        preg_match_all('/@\[(\d+)\]/', $text, $matches);
        foreach ($matches[1] as $id) $ids[(int)$id] = true;
    }
    if (!$ids) return $texts;

    $ids = array_keys($ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $emails = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $emails[(int)$row['id']] = $row['email'];
    }

    foreach ($texts as $key => $text) {
        $texts[$key] = preg_replace_callback('/@\[(\d+)\]/', function ($m) use ($emails) {
            return '@' . ($emails[(int)$m[1]] ?? 'someone');
        }, $text);
    }
    return $texts;
}

// Cuts on a character boundary, not a byte boundary: posts may contain the
// Latin-1 accented letters, which are two bytes in UTF-8, and a byte-wise
// substr() can split one in half and produce invalid UTF-8 that json_encode
// then refuses to encode.
//
// Done with a /u regex rather than mb_substr on purpose -- this site's
// php.ini has ";extension=mbstring", so mbstring can't be relied on in the
// web SAPI, whereas PCRE's UTF-8 support is always compiled in.
//
// Only appends the ellipsis when something was actually removed; the old
// version put "..." after every preview, including complete short ones.
function truncatePreview($text) {
    $text = trim($text);
    if (!preg_match('/^.{0,' . PREVIEW_MAX_CHARS . '}/us', $text, $m)) return $text;
    if ($m[0] === $text) return $text;
    return rtrim($m[0]) . '...';
}

// ============== POST HELPERS (posts/post_likes tables) ==============
// Likes used to live inside each post's JSON, as `likes: [{userId, timestamp}]`.
// Handlers below still hand clients that exact shape -- these two functions
// are what rebuilds it from the real post_likes table.

// One query for a whole page of posts, not one query per post. Grouped by
// post id, ordered oldest-first like the old JSON array naturally was
// (likes were always appended, never reordered).
function getLikesForPostIds($pdo, $postIds) {
    if (empty($postIds)) return [];
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $stmt = $pdo->prepare("SELECT post_id, user_id, created_at FROM post_likes WHERE post_id IN ($placeholders) ORDER BY created_at ASC");
    $stmt->execute($postIds);
    $byPost = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byPost[$row['post_id']][] = ['userId' => (int)$row['user_id'], 'timestamp' => $row['created_at']];
    }
    return $byPost;
}

// A posts-table row, in the shape handlers have always returned. Callers
// still add userID/userEmail/mentions themselves, same as before.
function postRowToApi($row, $likesByPost) {
    return [
        'id' => $row['id'],
        'text' => $row['text'],
        'timestamp' => $row['created_at'],
        'likes' => $likesByPost[$row['id']] ?? [],
        'mediaUrl' => $row['media_url'],
    ];
}

function handle_getPostById($pdo, $user) {
    $postId = trim($_POST['postId'] ?? '');
    if (!$postId) bad('Post ID required', 400);

    $stmt = $pdo->prepare('SELECT id, user_id, text, media_url, created_at FROM posts WHERE id = ?');
    $stmt->execute([$postId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) bad('Post not found', 404);

    $ownerStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $ownerStmt->execute([$row['user_id']]);
    $ownerEmail = $ownerStmt->fetchColumn() ?: '';

    $post = postRowToApi($row, getLikesForPostIds($pdo, [$postId]));
    $post['userID'] = (int)$row['user_id'];
    $post['userEmail'] = $ownerEmail;
    $post['mentions'] = hydrateMentions($pdo, $post['text']);
    respond(good(['post' => $post]));
}

function handle_getPostPreviews($pdo, $user) {
    $postIdsRaw = $_POST['postIds'] ?? '[]';
    $postIds = json_decode($postIdsRaw, true) ?? [];
    if (!is_array($postIds) || empty($postIds)) {
        respond(good(['previews' => []]));
        return;
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $stmt = $pdo->prepare("SELECT id, text FROM posts WHERE id IN ($placeholders)");
    $stmt->execute($postIds);

    $texts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($row['text'])) $texts[$row['id']] = $row['text'];
    }

    // Resolve the @[id] tokens before cutting, not after. Cutting first can
    // slice a token in half and leave "@[1" sitting in the preview, and even
    // an intact token means nothing to whoever reads it.
    $texts = resolveMentionTokens($pdo, $texts);

    $previews = [];
    foreach ($texts as $id => $text) {
        $previews[$id] = truncatePreview($text);
    }

    respond(good(['previews' => $previews]));
}

function handle_post($pdo, $user) {
    $text = trim($_POST['postText'] ?? '');
    if (!$text) bad('Post text required', 400);
    if (strlen($text) > 5000) bad('You are trying to make a post that is longer than 5K characters', 400);
    validateContent($text, 'You are trying to post illegal characters');
    $mentionIds = extractMentions($text);

    $uid = $user['sub'];

    $rawMedia = $_POST['mediaUrl'] ?? null;
    if ($rawMedia === 'null' || $rawMedia === '') $rawMedia = null;
    if ($rawMedia !== null) {
        $stmt = $pdo->prepare('SELECT id FROM media WHERE path = ? AND user_id = ?');
        $stmt->execute([$rawMedia, $uid]);
        $mediaRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mediaRow) bad('Invalid mediaUrl or not owned by user', 400);
    }

    // Two posts made in the same second used to get the same id
    // ("$uid.$time") and deleting either one deleted both, since
    // comments/media/likes all reference a post by this one string. Now that
    // posts.id is a PRIMARY KEY, a collision fails the INSERT itself -
    // atomically, even between two genuinely concurrent requests - so
    // bumping the second forward and retrying is enough on its own; no
    // read-check-write lock needed the way the JSON array required.
    $createdAt = date('Y-m-d H:i:s');
    $newTime = time();
    while (true) {
        $postId = $uid . '.' . $newTime;
        try {
            // Re-prepared each attempt: PDO/SQLite leaves a statement in a
            // "General error: 21 bad parameter or other API misuse" state
            // after a constraint violation, so re-executing the same
            // PDOStatement on the next loop fails even with fresh values.
            $insert = $pdo->prepare('INSERT INTO posts (id, user_id, text, media_url, created_at) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$postId, $uid, $text, $rawMedia, $createdAt]);
            break;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e; // not a uniqueness failure
            $newTime++;
        }
    }

    if ($rawMedia !== null) {
        $stmt = $pdo->prepare('UPDATE media SET post_id = ? WHERE path = ? AND user_id = ?');
        $stmt->execute([$postId, $rawMedia, $uid]);
    }
    notifyMentions($pdo, $mentionIds, $uid, $user['email'], $postId);
    respond(good(['postId' => $postId]));
}

function handle_getMyPosts($pdo, $user) {
    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 25;
    $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
    $uid = $user['sub'];

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE user_id = ?');
    $countStmt->execute([$uid]);
    $totalCount = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id, user_id, text, media_url, created_at FROM posts WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $likesByPost = getLikesForPostIds($pdo, array_column($rows, 'id'));
    $posts = [];
    foreach ($rows as $row) {
        $post = postRowToApi($row, $likesByPost);
        $post['userID'] = $uid;
        $post['userEmail'] = $user['email'];
        $post['mentions'] = hydrateMentions($pdo, $post['text']);
        $posts[] = $post;
    }
    $hasMore = ($offset + $limit) < $totalCount;

    respond(good(['posts' => $posts, 'hasMore' => $hasMore, 'totalCount' => $totalCount]));
}

function handle_getUserPosts($pdo, $user) {
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0) bad('Invalid user ID', 400);

    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 25;
    $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE user_id = ?');
    $countStmt->execute([$targetId]);
    $totalCount = (int)$countStmt->fetchColumn();

    $emailStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $emailStmt->execute([$targetId]);
    $targetEmail = $emailStmt->fetchColumn() ?: 'User ' . $targetId;

    $stmt = $pdo->prepare("SELECT id, user_id, text, media_url, created_at FROM posts WHERE user_id = ? ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute([$targetId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $likesByPost = getLikesForPostIds($pdo, array_column($rows, 'id'));
    $posts = [];
    foreach ($rows as $row) {
        $post = postRowToApi($row, $likesByPost);
        $post['userID'] = $targetId;
        $post['userEmail'] = $targetEmail;
        $post['mentions'] = hydrateMentions($pdo, $post['text']);
        $posts[] = $post;
    }
    $hasMore = ($offset + $limit) < $totalCount;

    respond(good(['posts' => $posts, 'hasMore' => $hasMore, 'totalCount' => $totalCount]));
}

function handle_fetchFollowedPosts($pdo, $user) {
    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 25;
    $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
    $uid = $user['sub'];

    $stmt = $pdo->prepare('SELECT follows FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $followsJson = $stmt->fetchColumn() ?: '[]';
    $followedData = json_decode($followsJson, true) ?? [];

    // Always includes $uid, so this is never empty and the IN (...) below
    // always has at least one placeholder.
    $followedIds = array_values(array_unique(array_merge(array_map(fn($f) => is_array($f) ? $f['id'] : $f, $followedData), [$uid])));
    $placeholders = implode(',', array_fill(0, count($followedIds), '?'));

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id IN ($placeholders)");
    $countStmt->execute($followedIds);
    $totalCount = (int)$countStmt->fetchColumn();

    // One query across every followed user, ordered and paged in SQL,
    // instead of pulling each user's whole post list into PHP to merge and
    // sort by hand.
    $stmt = $pdo->prepare("SELECT posts.id, posts.user_id, posts.text, posts.media_url, posts.created_at, users.email
        FROM posts JOIN users ON users.id = posts.user_id
        WHERE posts.user_id IN ($placeholders)
        ORDER BY posts.created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($followedIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $likesByPost = getLikesForPostIds($pdo, array_column($rows, 'id'));
    $allPosts = [];
    foreach ($rows as $row) {
        $post = postRowToApi($row, $likesByPost);
        $post['userID'] = (int)$row['user_id'];
        $post['userEmail'] = $row['email'];
        $post['mentions'] = hydrateMentions($pdo, $post['text']);
        $allPosts[] = $post;
    }
    $hasMore = ($offset + $limit) < $totalCount;

    respond(good(['posts' => $allPosts, 'hasMore' => $hasMore, 'totalCount' => $totalCount]));
}

function handle_likePost($pdo, $user) {
    $postId = trim($_POST['postId'] ?? '');
    if (!$postId) bad('Missing post ID', 400);

    // Checked against the id's own prefix, before touching the posts table,
    // same as before the tables existed - so this still rejects a self-like
    // even for a postId that turns out not to exist.
    $ownerId = (int)explode('.', $postId)[0];
    if ($ownerId == $user['sub']) bad('Cannot like your own post', 400);

    $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $stmt->execute([$user['sub']]);
    $actorEmail = $stmt->fetchColumn() ?: 'Unknown';

    $stmt = $pdo->prepare('SELECT user_id FROM posts WHERE id = ?');
    $stmt->execute([$postId]);
    $realOwnerId = $stmt->fetchColumn();
    if ($realOwnerId === false) bad('Post not found', 404);

    $insert = $pdo->prepare('INSERT OR IGNORE INTO post_likes (post_id, user_id, created_at) VALUES (?, ?, ?)');
    $insert->execute([$postId, $user['sub'], date('Y-m-d H:i:s')]);
    if ($insert->rowCount() > 0) {
        createNotification($pdo, (int)$realOwnerId, $user['sub'], $actorEmail, 'like', $postId);
    }

    respond(good(['liked' => true]));
}

function handle_unlikePost($pdo, $user) {
    $postId = trim($_POST['postId'] ?? '');
    if (!$postId) bad('Missing post ID', 400);

    $stmt = $pdo->prepare('SELECT user_id FROM posts WHERE id = ?');
    $stmt->execute([$postId]);
    $ownerId = $stmt->fetchColumn();
    if ($ownerId === false) bad('Post not found', 404);
    $ownerId = (int)$ownerId;

    $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $stmt->execute([$user['sub']]);
    $actorEmail = $stmt->fetchColumn() ?: 'Unknown';

    $delete = $pdo->prepare('DELETE FROM post_likes WHERE post_id = ? AND user_id = ?');
    $delete->execute([$postId, $user['sub']]);
    $wasLiked = $delete->rowCount() > 0;
    if ($wasLiked && $ownerId != $user['sub']) {
        createNotification($pdo, $ownerId, $user['sub'], $actorEmail, 'unlike', $postId);
    }

    respond(good(['liked' => false]));
}

function handle_getPostLikes($pdo, $user) {
    $postId = trim($_POST['postId'] ?? '');
    if (!$postId) bad('Missing post ID', 400);

    $stmt = $pdo->prepare('SELECT 1 FROM posts WHERE id = ?');
    $stmt->execute([$postId]);
    if (!$stmt->fetchColumn()) bad('Post not found', 404);

    $likes = getLikesForPostIds($pdo, [$postId])[$postId] ?? [];
    respond(good(['likes' => $likes]));
}

function handle_deletePost($pdo, $user) {
    global $action;
    $postId = trim($_POST['postId'] ?? '');
    if (!$postId) bad('Missing post ID', 400);

    $stmt = $pdo->prepare('SELECT user_id, media_url FROM posts WHERE id = ?');
    $stmt->execute([$postId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) bad('Post not found', 404);
    if ((int)$row['user_id'] != $user['sub']) bad('You can only delete your own posts', 403);

    $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
    $pdo->prepare('DELETE FROM post_likes WHERE post_id = ?')->execute([$postId]);
    $pdo->prepare('DELETE FROM notifications WHERE post_id = ?')->execute([$postId]);

    $mediaUrl = $row['media_url'];
    if (!empty($mediaUrl) && $mediaUrl !== 'null') {
        if (strpos($mediaUrl, '..') === false && strpos($mediaUrl, '/') === 0) {
            $stmt = $pdo->prepare('SELECT id, path FROM media WHERE path = ? AND user_id = ?');
            $stmt->execute([$mediaUrl, $user['sub']]);
            $mediaRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($mediaRow) {
                $mediaFile = __DIR__ . '/../../' . ltrim($mediaRow['path'], '/');
                if (file_exists($mediaFile)) unlink($mediaFile);
                if (strpos($mediaRow['path'], '/video/') !== false) {
                    $thumbFile = preg_replace('#/video/([^/]+)\.[^./]+$#', '/video/thumb_$1.webp', $mediaFile);
                    if (file_exists($thumbFile)) unlink($thumbFile);
                }
                $stmt = $pdo->prepare('DELETE FROM media WHERE id = ?');
                $stmt->execute([$mediaRow['id']]);
            }
        }
    }

    respond(good(['deleted' => true]));
}
