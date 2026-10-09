<?php
// src/Posts/handlers.php - Posts module: post CRUD, feed assembly, likes,
// and post previews. Likes (post_likes table) are grouped in here rather
// than a separate module since they're exclusively post-scoped data and
// share getLikesForPostIds()/postRowToApi() with every other posts
// handler - splitting them out would just recreate the same coupling
// through a different file.
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
// With $viewerId, likes from users hidden from the viewer (blocked either
// way, or frozen) are left out.
function getLikesForPostIds($pdo, $postIds, $viewerId = null) {
    if (empty($postIds)) return [];
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    [$hf, $hp] = $viewerId ? hiddenFilter($pdo, $viewerId, 'user_id') : ['', []];
    $stmt = $pdo->prepare("SELECT post_id, user_id, created_at FROM post_likes WHERE post_id IN ($placeholders)$hf ORDER BY created_at ASC");
    $stmt->execute(array_merge(array_values($postIds), $hp));
    $byPost = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byPost[$row['post_id']][] = ['userId' => (int)$row['user_id'], 'timestamp' => $row['created_at']];
    }
    return $byPost;
}

// Columns to select (with POST_MEDIA_JOIN) so postRowToApi can describe the
// attached media. Prefixed m_ so they can't clash with the posts columns.
const POST_MEDIA_COLUMNS = 'm.type AS m_type, m.width AS m_width, m.height AS m_height, m.duration AS m_duration,
    m.variant_path AS m_variant, m.poster_path AS m_poster, m."loop" AS m_loop';
const POST_MEDIA_JOIN = 'LEFT JOIN media m ON m.path = posts.media_url';

// The post's attached media for clients: type, full-size url, stored size,
// the 960 px feed variant and the video poster when there are any, length,
// and whether to loop it silently (animated GIFs). null without media. A
// file from before the media table had these columns still gets its type
// (from its folder) and url, with the rest null.
function postMediaToApi($row) {
    $url = $row['media_url'] ?? null;
    if (!$url || $url === 'null') return null;
    $type = $row['m_type'] ?? null;
    if (!$type && preg_match('#^/media/\d+/(image|video|audio)/#', $url, $m)) $type = $m[1];
    return [
        'type' => $type,
        'url' => $url,
        'width' => isset($row['m_width']) ? (int)$row['m_width'] : null,
        'height' => isset($row['m_height']) ? (int)$row['m_height'] : null,
        'variantUrl' => $row['m_variant'] ?? null,
        'posterUrl' => $row['m_poster'] ?? null,
        'duration' => isset($row['m_duration']) ? (float)$row['m_duration'] : null,
        'loop' => !empty($row['m_loop']),
    ];
}

// A posts-table row, in the shape handlers have always returned. Callers
// still add userID/userEmail/mentions themselves, same as before. mediaUrl
// stays for older clients (CLI/TUI, older app builds); media is the richer
// description newer clients use.
function postRowToApi($row, $likesByPost) {
    $post = [
        'id' => $row['id'],
        'text' => $row['text'],
        'timestamp' => $row['created_at'],
        'likes' => $likesByPost[$row['id']] ?? [],
        'mediaUrl' => $row['media_url'],
        'media' => postMediaToApi($row),
    ];
    // Only the author is ever sent a frozen post (frozenFilter): flagged, and
    // with no likes while it is hidden. Callers zero commentCount themselves.
    if (!empty($row['frozen_at'])) {
        $post['frozen'] = true;
        $post['likes'] = [];
    }
    return $post;
}

function handle_getPostById($pdo, $user) {
    $postId = trim($_POST['postId'] ?? '');
    if (!$postId) bad('Post ID required', 400);

    // LEFT, not inner: a post whose owner row is somehow gone should still
    // render (same fallback as before, just folded into one query) rather
    // than 404 as if the post itself didn't exist.
    $stmt = $pdo->prepare('SELECT posts.id, posts.user_id, posts.text, posts.media_url, posts.created_at, posts.frozen_at, users.email, ' . POST_MEDIA_COLUMNS . '
        FROM posts LEFT JOIN users ON users.id = posts.user_id ' . POST_MEDIA_JOIN . ' WHERE posts.id = ?');
    $stmt->execute([$postId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || isHiddenFrom($pdo, $user['sub'], $row['user_id'])) bad('Post not found', 404);
    if ($row['frozen_at'] !== null && (int)$row['user_id'] !== (int)$user['sub']) bad('Post not found', 404);

    $post = postRowToApi($row, getLikesForPostIds($pdo, [$postId], $user['sub']));
    $post['userID'] = (int)$row['user_id'];
    $post['userEmail'] = $row['email'] ?: '';
    $post['mentions'] = hydrateMentions($pdo, $post['text']);
    $post['commentCount'] = !empty($post['frozen']) ? 0 : (getCommentCountsForPostIds($pdo, [$postId], $user['sub'])[$postId] ?? 0);
    $post['reportedByMe'] = isReportedBy($pdo, $user['sub'], 'post', $postId);
    respond(good(['post' => $post]));
}

function handle_getPostPreviews($pdo, $user) {
    $postIds = jsonIdList('postIds', 100);
    if (empty($postIds)) {
        respond(good(['previews' => []]));
        return;
    }

    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    [$hf, $hp] = hiddenFilter($pdo, $user['sub'], 'user_id');
    [$ff, $fp] = frozenFilter('posts', $user['sub']);
    $stmt = $pdo->prepare("SELECT id, text FROM posts WHERE id IN ($placeholders)$hf$ff");
    $stmt->execute(array_merge($postIds, $hp, $fp));

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
    // The message never repeats the word, which keeps the list private.
    if (containsBlockedWord($text)) bad("Your post contains a word that isn't allowed.", 400);
    $mentionIds = extractMentions($text);

    $uid = $user['sub'];

    $rawMedia = $_POST['mediaUrl'] ?? null;
    if ($rawMedia === 'null' || $rawMedia === '') $rawMedia = null;
    if ($rawMedia !== null) {
        // post_id IS NULL: without this, a mediaUrl already attached to
        // another post still passed this check, so two posts could end up
        // sharing one file -- deleting either one then unlinked it out
        // from under the other.
        $stmt = $pdo->prepare('SELECT id FROM media WHERE path = ? AND user_id = ? AND post_id IS NULL');
        $stmt->execute([$rawMedia, $uid]);
        $mediaRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mediaRow) bad('That media is invalid, not yours, or already attached to another post', 400);
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

// Shared by handle_getMyPosts and handle_getUserPosts (C4) -- the two were
// identical except where the target id/email came from (the caller's own
// $user vs. a looked-up $_POST['userId']). getMyPosts passes $user['email']
// straight through rather than looking its own email up again, which a
// literal "getMyPosts just calls getUserPosts with userId=$user['sub']"
// dedup would have reintroduced -- exactly the redundant-query class P9
// just removed elsewhere.
function fetchPostsPageForUser($pdo, $viewerId, $targetId, $targetEmail, $limit, $offset) {
    [$ff, $fp] = frozenFilter('posts', $viewerId);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE posts.user_id = ?$ff");
    $countStmt->execute(array_merge([$targetId], $fp));
    $totalCount = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT posts.id, posts.user_id, posts.text, posts.media_url, posts.created_at, posts.frozen_at, " . POST_MEDIA_COLUMNS . "
        FROM posts " . POST_MEDIA_JOIN . " WHERE posts.user_id = ?$ff ORDER BY posts.created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute(array_merge([$targetId], $fp));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $likesByPost = getLikesForPostIds($pdo, array_column($rows, 'id'), $viewerId);
    $mentions = hydrateMentionsBatch($pdo, array_column($rows, 'text'));
    $commentCounts = getCommentCountsForPostIds($pdo, array_column($rows, 'id'), $viewerId);
    $reported = reportedByViewer($pdo, $viewerId, 'post');
    $posts = [];
    foreach ($rows as $i => $row) {
        $post = postRowToApi($row, $likesByPost);
        $post['userID'] = $targetId;
        $post['userEmail'] = $targetEmail;
        $post['mentions'] = $mentions[$i];
        $post['commentCount'] = !empty($post['frozen']) ? 0 : ($commentCounts[$row['id']] ?? 0);
        $post['reportedByMe'] = in_array((string)$row['id'], $reported, true);
        $posts[] = $post;
    }
    $hasMore = ($offset + $limit) < $totalCount;

    return ['posts' => $posts, 'hasMore' => $hasMore, 'totalCount' => $totalCount];
}

function handle_getMyPosts($pdo, $user) {
    [$limit, $offset] = pageParams();
    respond(good(fetchPostsPageForUser($pdo, $user['sub'], $user['sub'], $user['email'], $limit, $offset)));
}

function handle_getUserPosts($pdo, $user) {
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0) bad('Invalid user ID', 400);
    if (isHiddenFrom($pdo, $user['sub'], $targetId)) bad('User not found', 404);

    [$limit, $offset] = pageParams();

    $emailStmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
    $emailStmt->execute([$targetId]);
    $targetEmail = $emailStmt->fetchColumn() ?: 'User ' . $targetId;

    respond(good(fetchPostsPageForUser($pdo, $user['sub'], $targetId, $targetEmail, $limit, $offset)));
}

function handle_fetchFollowedPosts($pdo, $user) {
    [$limit, $offset] = pageParams();
    $uid = $user['sub'];

    $stmt = $pdo->prepare('SELECT follows FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $followsJson = $stmt->fetchColumn() ?: '[]';
    $followedData = json_decode($followsJson, true) ?? [];

    // Always includes $uid, so this is never empty and the IN (...) below
    // always has at least one placeholder. Hidden users (blocked either way,
    // or frozen) are dropped from the list itself, which keeps the query on
    // the posts index instead of adding a NOT IN.
    $followedIds = array_values(array_unique(array_merge(array_map(fn($f) => (int)(is_array($f) ? $f['id'] : $f), $followedData), [(int)$uid])));
    $followedIds = array_values(array_diff($followedIds, hiddenUserIds($pdo, $uid)));
    if (!$followedIds) $followedIds = [(int)$uid];
    $placeholders = implode(',', array_fill(0, count($followedIds), '?'));

    [$ff, $fp] = frozenFilter('posts', $uid);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE user_id IN ($placeholders)$ff");
    $countStmt->execute(array_merge($followedIds, $fp));
    $totalCount = (int)$countStmt->fetchColumn();

    // One query across every followed user, ordered and paged in SQL,
    // instead of pulling each user's whole post list into PHP to merge and
    // sort by hand.
    $stmt = $pdo->prepare("SELECT posts.id, posts.user_id, posts.text, posts.media_url, posts.created_at, posts.frozen_at, users.email, " . POST_MEDIA_COLUMNS . "
        FROM posts JOIN users ON users.id = posts.user_id " . POST_MEDIA_JOIN . "
        WHERE posts.user_id IN ($placeholders)$ff
        ORDER BY posts.created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute(array_merge($followedIds, $fp));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $likesByPost = getLikesForPostIds($pdo, array_column($rows, 'id'), $uid);
    $mentions = hydrateMentionsBatch($pdo, array_column($rows, 'text'));
    $commentCounts = getCommentCountsForPostIds($pdo, array_column($rows, 'id'), $uid);
    $reported = reportedByViewer($pdo, $uid, 'post');
    $allPosts = [];
    foreach ($rows as $i => $row) {
        $post = postRowToApi($row, $likesByPost);
        $post['userID'] = (int)$row['user_id'];
        $post['userEmail'] = $row['email'];
        $post['mentions'] = $mentions[$i];
        $post['commentCount'] = !empty($post['frozen']) ? 0 : ($commentCounts[$row['id']] ?? 0);
        $post['reportedByMe'] = in_array((string)$row['id'], $reported, true);
        $allPosts[] = $post;
    }
    $hasMore = ($offset + $limit) < $totalCount;

    respond(good(['posts' => $allPosts, 'hasMore' => $hasMore, 'totalCount' => $totalCount]));
}

function handle_likePost($pdo, $user) {
    $postId = trim($_POST['postId'] ?? '');
    if (!$postId) bad('Missing post ID', 400);

    // A frozen post can't be liked by anyone, its author included.
    $stmt = $pdo->prepare('SELECT user_id FROM posts WHERE id = ? AND frozen_at IS NULL');
    $stmt->execute([$postId]);
    $realOwnerId = $stmt->fetchColumn();
    if ($realOwnerId === false || isHiddenFrom($pdo, $user['sub'], $realOwnerId)) bad('Post not found', 404);
    $realOwnerId = (int)$realOwnerId;

    // Checked against the real owner from the posts table, not the id's own
    // prefix -- a made-up postId naming another user's id used to pass this
    // check before ever touching the table.
    if ($realOwnerId == $user['sub']) bad('Cannot like your own post', 400);

    $actorEmail = $user['email'];

    $insert = $pdo->prepare('INSERT OR IGNORE INTO post_likes (post_id, user_id, created_at) VALUES (?, ?, ?)');
    $insert->execute([$postId, $user['sub'], date('Y-m-d H:i:s')]);
    if ($insert->rowCount() > 0) {
        createNotification($pdo, $realOwnerId, $user['sub'], $actorEmail, 'like', $postId);
    }

    respond(good(['liked' => true]));
}

function handle_unlikePost($pdo, $user) {
    $postId = trim($_POST['postId'] ?? '');
    if (!$postId) bad('Missing post ID', 400);

    $stmt = $pdo->prepare('SELECT user_id FROM posts WHERE id = ? AND frozen_at IS NULL');
    $stmt->execute([$postId]);
    $ownerId = $stmt->fetchColumn();
    if ($ownerId === false) bad('Post not found', 404);
    $ownerId = (int)$ownerId;

    $actorEmail = $user['email'];

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

    $stmt = $pdo->prepare('SELECT user_id FROM posts WHERE id = ? AND frozen_at IS NULL');
    $stmt->execute([$postId]);
    $ownerId = $stmt->fetchColumn();
    if ($ownerId === false || isHiddenFrom($pdo, $user['sub'], $ownerId)) bad('Post not found', 404);

    $likes = getLikesForPostIds($pdo, [$postId], $user['sub'])[$postId] ?? [];
    respond(good(['likes' => $likes]));
}

function handle_deletePost($pdo, $user) {
    $postId = trim($_POST['postId'] ?? '');
    if (!$postId) bad('Missing post ID', 400);

    $stmt = $pdo->prepare('SELECT user_id FROM posts WHERE id = ?');
    $stmt->execute([$postId]);
    $ownerId = $stmt->fetchColumn();
    if ($ownerId === false) bad('Post not found', 404);
    if ((int)$ownerId != $user['sub']) bad('You can only delete your own posts', 403);

    deletePostById($pdo, $postId);
    respond(good(['deleted' => true]));
}

// Deletes a post with its likes, comments, notifications and attached media
// (row and files). No ownership check: callers do that (handle_deletePost
// for the owner, adminResolveReport for an admin). Returns false if the post
// doesn't exist.
function deletePostById($pdo, $postId) {
    $stmt = $pdo->prepare('SELECT user_id, media_url FROM posts WHERE id = ?');
    $stmt->execute([$postId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $ownerId = (int)$row['user_id'];

    // Filesystem unlinks happen after commit, not during -- a failed
    // unlink() must never roll back DB rows that already deleted cleanly,
    // and a failed DB statement must never leave a file deleted out from
    // under a row that's still there.
    $filesToUnlink = [];

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$postId]);
        $pdo->prepare('DELETE FROM post_likes WHERE post_id = ?')->execute([$postId]);
        $pdo->prepare('DELETE FROM notifications WHERE post_id = ?')->execute([$postId]);
        // Previously left behind entirely -- a deleted post's comments
        // stayed in the table forever, orphaned.
        $pdo->prepare('DELETE FROM comments WHERE post_id = ?')->execute([$postId]);

        $mediaUrl = $row['media_url'];
        if (!empty($mediaUrl) && $mediaUrl !== 'null') {
            if (strpos($mediaUrl, '..') === false && strpos($mediaUrl, '/') === 0) {
                $stmt = $pdo->prepare('SELECT * FROM media WHERE path = ? AND user_id = ?');
                $stmt->execute([$mediaUrl, $ownerId]);
                $mediaRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($mediaRow) {
                    // The file, a video's thumbnail, and the feed variant
                    // and poster when there are any (mediaRowFiles maps the
                    // stored paths onto $CONFIG['media_dir']).
                    $filesToUnlink = array_merge($filesToUnlink, mediaRowFiles($mediaRow));
                    $pdo->prepare('DELETE FROM media WHERE id = ?')->execute([$mediaRow['id']]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    foreach ($filesToUnlink as $file) {
        if (file_exists($file)) unlink($file);
    }
    return true;
}
