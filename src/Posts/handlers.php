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

// Swaps @[id] tokens for @name (username, else email) across a batch of texts, in one query for the
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
    $stmt = $pdo->prepare('SELECT u.id, ' . nameSql('u') . " AS email FROM users u WHERE u.id IN ($placeholders)");
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

// Every like on the given posts as [postId => [{userId, timestamp}, ...]],
// oldest first, in one query. With $viewerId, likes from users hidden from
// the viewer (blocked either way, or frozen) are left out. This is the list
// someone sees when they open a post's likes (getPostLikes).
function getLikesForPostIds($pdo, $postIds, $viewerId = null) {
    if (empty($postIds)) return [];
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    [$hf, $hp] = $viewerId ? hiddenFilter($pdo, $viewerId, 'user_id') : ['', []];
    $stmt = $pdo->prepare("SELECT post_id, user_id, created_at, created_tz FROM post_likes WHERE post_id IN ($placeholders)$hf ORDER BY created_at ASC");
    $stmt->execute(array_merge(array_values($postIds), $hp));
    $byPost = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byPost[$row['post_id']][] = ['userId' => (int)$row['user_id'], 'timestamp' => $row['created_at'], 'timestampTz' => $row['created_tz']];
    }
    return $byPost;
}

// What a post carries about its likes: [postId => [likeCount, likedByMe]],
// counted in SQL, with the same hidden-user rule as above. Apps older than
// API version 2 read the whole list off every post instead, so for them the
// list is fetched and sent as 'likes' as well.
function likeInfoForPostIds($pdo, $postIds, $viewerId) {
    if (empty($postIds)) return [];
    $info = [];
    if (clientApiVersion() < 2) {
        foreach (getLikesForPostIds($pdo, $postIds, $viewerId) as $postId => $likes) {
            $info[$postId] = [
                'likeCount' => count($likes),
                'likedByMe' => in_array((int)$viewerId, array_column($likes, 'userId'), true),
                'likes' => $likes,
            ];
        }
        return $info;
    }
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    [$hf, $hp] = hiddenFilter($pdo, $viewerId, 'user_id');
    $stmt = $pdo->prepare("SELECT post_id, COUNT(*) AS n, MAX(user_id = ?) AS mine FROM post_likes
        WHERE post_id IN ($placeholders)$hf GROUP BY post_id");
    $stmt->execute(array_merge([(int)$viewerId], array_values($postIds), $hp));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $info[$row['post_id']] = ['likeCount' => (int)$row['n'], 'likedByMe' => (bool)$row['mine']];
    }
    return $info;
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

// A posts row (selected with users.email and the media columns) as the API
// returns it. $likeInfo comes from likeInfoForPostIds(). mediaUrl stays for
// older clients (CLI/TUI, older app builds); media is the richer description
// newer clients use. A frozen post is only ever sent to its author
// (frozenFilter): flagged, and with no likes or comments while it is hidden.
function postRowToApi($row, $likeInfo, $commentCounts = []) {
    $frozen = !empty($row['frozen_at']);
    $likes = $frozen ? [] : ($likeInfo[$row['id']] ?? []);
    $post = [
        'id' => $row['id'],
        'text' => $row['text'],
        'timestamp' => $row['created_at'],
        'timestampTz' => $row['created_tz'] ?? null,
        'likeCount' => $likes['likeCount'] ?? 0,
        'likedByMe' => $likes['likedByMe'] ?? false,
        'mediaUrl' => $row['media_url'],
        'media' => postMediaToApi($row),
        'userID' => (int)$row['user_id'],
        'userEmail' => $row['email'] ?? ('User ' . $row['user_id']),
        'commentCount' => $frozen ? 0 : ($commentCounts[$row['id']] ?? 0),
    ];
    if (clientApiVersion() < 2) $post['likes'] = $likes['likes'] ?? [];
    if ($frozen) $post['frozen'] = true;
    return $post;
}

// Everything a list of posts needs, in one query each for the whole list
// rather than per post: likes, mentions, comment counts, the viewer's reports.
function postRowsToApi($pdo, array $rows, $viewerId) {
    $ids = array_column($rows, 'id');
    $likeInfo = likeInfoForPostIds($pdo, $ids, $viewerId);
    $mentions = hydrateMentionsBatch($pdo, array_column($rows, 'text'));
    $commentCounts = getCommentCountsForPostIds($pdo, $ids, $viewerId);
    $reported = reportedByViewer($pdo, $viewerId, 'post');
    $posts = [];
    foreach ($rows as $i => $row) {
        $post = postRowToApi($row, $likeInfo, $commentCounts);
        $post['mentions'] = $mentions[$i];
        $post['reportedByMe'] = in_array((string)$row['id'], $reported, true);
        $posts[] = $post;
    }
    return $posts;
}

const POST_SELECT = 'SELECT posts.id, posts.user_id, posts.text, posts.media_url, posts.created_at, posts.created_tz, posts.frozen_at, COALESCE(users.username, users.email) AS email, '
    . POST_MEDIA_COLUMNS . ' FROM posts LEFT JOIN users ON users.id = posts.user_id ' . POST_MEDIA_JOIN;

function handle_getPostById($pdo, $user) {
    $postId = trim($_POST['postId'] ?? '');
    if (!$postId) bad('Post ID required', 400);

    // POST_SELECT joins users with LEFT, not inner: a post whose owner row is
    // somehow gone should still render rather than 404 as if the post itself
    // didn't exist.
    $stmt = $pdo->prepare(POST_SELECT . ' WHERE posts.id = ?');
    $stmt->execute([$postId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || isHiddenFrom($pdo, $user['sub'], $row['user_id'])) bad('Post not found', 404);
    if ($row['frozen_at'] !== null && (int)$row['user_id'] !== (int)$user['sub']) bad('Post not found', 404);

    $row['email'] = $row['email'] ?: '';
    respond(good(['post' => postRowsToApi($pdo, [$row], $user['sub'])[0]]));
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
            $insert = $pdo->prepare('INSERT INTO posts (id, user_id, text, media_url, created_at, created_tz) VALUES (?, ?, ?, ?, ?, ?)');
            $insert->execute([$postId, $uid, $text, $rawMedia, $createdAt, clientTzOffset()]);
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

// One page of the posts written by $authorIds, newest first, for getMyPosts,
// getUserPosts and the feed.
//
// Where the page starts: beforeTimestamp + beforeId (the 'timestamp' and
// 'id' of the last post the app already has), or offset for apps that page
// that way. "Before this post" costs the same however far down someone has
// scrolled, and a new post arriving meanwhile can't shift the page and repeat
// a post, both of which offset suffers from.
//
// One row more than asked for is fetched to learn whether there's another
// page, in place of a COUNT(*) on every page. Apps older than API version 2
// still get totalCount (the terminal clients print it).
function fetchPostsPage($pdo, $viewerId, array $authorIds) {
    [$limit, $offset] = pageParams();
    $placeholders = implode(',', array_fill(0, count($authorIds), '?'));
    [$ff, $fp] = frozenFilter('posts', $viewerId);
    $where = "posts.user_id IN ($placeholders)$ff";
    $params = array_merge($authorIds, $fp);

    $cursor = '';
    $cursorParams = [];
    $beforeTime = trim((string)($_POST['beforeTimestamp'] ?? ''));
    $beforeId = trim((string)($_POST['beforeId'] ?? ''));
    if ($beforeTime !== '' && $beforeId !== '') {
        $cursor = ' AND (posts.created_at < ? OR (posts.created_at = ? AND posts.id < ?))';
        $cursorParams = [$beforeTime, $beforeTime, $beforeId];
        $offset = 0;
    }

    $stmt = $pdo->prepare(POST_SELECT . " WHERE $where$cursor
        ORDER BY posts.created_at DESC, posts.id DESC LIMIT " . ($limit + 1) . " OFFSET $offset");
    $stmt->execute(array_merge($params, $cursorParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);

    $page = ['posts' => postRowsToApi($pdo, $rows, $viewerId), 'hasMore' => $hasMore];
    if (clientApiVersion() < 2) {
        $count = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE $where");
        $count->execute($params);
        $page['totalCount'] = (int)$count->fetchColumn();
    }
    return $page;
}

function handle_getMyPosts($pdo, $user) {
    respond(good(fetchPostsPage($pdo, $user['sub'], [(int)$user['sub']])));
}

function handle_getUserPosts($pdo, $user) {
    $targetId = (int)($_POST['userId'] ?? 0);
    if ($targetId <= 0) bad('Invalid user ID', 400);
    if (isHiddenFrom($pdo, $user['sub'], $targetId)) bad('User not found', 404);
    respond(good(fetchPostsPage($pdo, $user['sub'], [$targetId])));
}

// The feed: the viewer's own posts and those of everyone they follow. Hidden
// users (blocked either way, or frozen) are dropped from the list of authors
// itself, which keeps the query on the posts index instead of adding a NOT IN.
function handle_fetchFollowedPosts($pdo, $user) {
    $uid = (int)$user['sub'];
    $stmt = $pdo->prepare('SELECT followed_id FROM follows WHERE follower_id = ?');
    $stmt->execute([$uid]);
    $authorIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $authorIds[] = $uid;
    $authorIds = array_values(array_diff(array_unique($authorIds), hiddenUserIds($pdo, $uid)));
    if (!$authorIds) $authorIds = [$uid];
    respond(good(fetchPostsPage($pdo, $uid, $authorIds)));
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

    $insert = $pdo->prepare('INSERT OR IGNORE INTO post_likes (post_id, user_id, created_at, created_tz) VALUES (?, ?, ?, ?)');
    $insert->execute([$postId, $user['sub'], date('Y-m-d H:i:s'), clientTzOffset()]);
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

    $pdo->exec('BEGIN IMMEDIATE');
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
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }

    foreach ($filesToUnlink as $file) {
        if (file_exists($file)) unlink($file);
    }
    return true;
}
