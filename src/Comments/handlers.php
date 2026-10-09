<?php
// src/Comments/handlers.php - Comments module: create/list/delete comments
// and per-post comment counts.
//
// Loaded via Composer's "files" autoload (see composer.json), same as the
// other modules - global-namespace functions, not classes.
//
// Depends on shared Core helpers still defined in api.php: bad(), good(),
// respond(), validateContent(), extractMentions(), notifyMentions(),
// hydrateMentions(), createNotification() (via notifyMentions). Those are
// cross-module (Posts uses several of them too), so they aren't moving
// here.

// Replies are one level deep: a reply has parent_id = the id of a top-level
// comment on the same post; a reply can't be replied to. A deleted comment
// that still has replies under it stays as a placeholder row (deleted_at set,
// text cleared, user_id 0) so the replies have somewhere to hang.

// The comment (id, post_id, user_id, parent_id, deleted_at, frozen_at), or null.
function commentRow($pdo, $id) {
    $s = $pdo->prepare('SELECT id, post_id, user_id, parent_id, deleted_at, frozen_at FROM comments WHERE id = ?');
    $s->execute([(int)$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function handle_createComment($pdo, $user) {
    $postId = trim($_POST['postId'] ?? '');
    $text = trim($_POST['text'] ?? '');
    $parentId = (int)($_POST['parentId'] ?? 0);
    if (!$postId) bad('Missing post ID', 400);
    if (!$text) bad('Comment text required', 400);
    if (strlen($text) > 5000) bad('Comment too long (max 5000 chars)', 400);
    validateContent($text, 'Illegal characters in comment');
    if (containsBlockedWord($text)) bad("Your comment contains a word that isn't allowed.", 400);
    $mentionIds = extractMentions($text);

    // No new comments on a frozen post, its author included.
    $stmt = $pdo->prepare('SELECT user_id FROM posts WHERE id = ? AND frozen_at IS NULL');
    $stmt->execute([$postId]);
    $ownerId = $stmt->fetchColumn();
    if ($ownerId === false || isHiddenFrom($pdo, $user['sub'], $ownerId)) bad('Post not found', 404);
    $ownerId = (int)$ownerId;

    // A reply goes under a live, top-level comment of this post.
    $parent = null;
    if ($parentId > 0) {
        $parent = commentRow($pdo, $parentId);
        if (!$parent || $parent['post_id'] !== $postId || $parent['parent_id'] !== null
            || $parent['deleted_at'] !== null || $parent['frozen_at'] !== null
            || isHiddenFrom($pdo, $user['sub'], $parent['user_id'])) {
            bad('Comment not found', 404);
        }
    }

    $stmt = $pdo->prepare('INSERT INTO comments (post_id, user_id, comment_text, created_at, parent_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$postId, $user['sub'], $text, date('Y-m-d H:i:s'), $parent ? $parentId : null]);
    $commentId = (int)$pdo->lastInsertId();

    // One notification per person per comment, the most specific kind: a reply
    // to you, then a reply in a thread you replied in, then a comment on your
    // post, then a mention. Nobody is told about their own comment.
    $actor = (int)$user['sub'];
    $actorEmail = $user['email'];
    $told = [];
    if ($parent) {
        $parentAuthor = (int)$parent['user_id'];
        if ($parentAuthor !== $actor) {
            createNotification($pdo, $parentAuthor, $actor, $actorEmail, 'reply', $postId, $commentId);
            $told[$parentAuthor] = true;
        }
        $s = $pdo->prepare('SELECT DISTINCT user_id FROM comments WHERE parent_id = ? AND deleted_at IS NULL AND user_id > 0 AND id != ?');
        $s->execute([$parentId, $commentId]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $uid = (int)$uid;
            if ($uid === $actor || isset($told[$uid]) || $uid === $parentAuthor) continue;
            createNotification($pdo, $uid, $actor, $actorEmail, 'thread_reply', $postId, $commentId);
            $told[$uid] = true;
        }
    }
    if ($ownerId !== $actor && !isset($told[$ownerId])) {
        createNotification($pdo, $ownerId, $actor, $actorEmail, 'comment', $postId, $commentId);
        $told[$ownerId] = true;
    }
    foreach ($mentionIds as $id) {
        if (isset($told[(int)$id])) continue;
        createNotification($pdo, $id, $actor, $actorEmail, 'mention', $postId, $commentId);
        $told[(int)$id] = true;
    }

    respond(good(['commentId' => $commentId]));
}

// How a comment shows for this viewer: 'real', 'deleted' (placeholder),
// 'frozen' (placeholder for others; the author sees the real thing, flagged),
// or 'omit'. $hidden is hiddenUserIds() for the viewer.
function commentDisplayState(array $row, int $viewerId, array $hidden): string {
    if ($row['deleted_at'] !== null) return 'deleted';
    if (in_array((int)$row['user_id'], $hidden, true)) return $row['parent_id'] === null ? 'deleted' : 'omit';
    if ($row['frozen_at'] !== null && (int)$row['user_id'] !== $viewerId) return 'frozen';
    return 'real';
}

function handle_getPostComments($pdo, $user) {
    $postId = trim($_POST['postId'] ?? '');
    [$limit, $offset] = pageParams();
    if (!$postId) bad('Missing post ID', 400);
    $viewer = (int)$user['sub'];

    // A frozen post shows no comments at all.
    $frozenPost = $pdo->prepare('SELECT 1 FROM posts WHERE id = ? AND frozen_at IS NOT NULL');
    $frozenPost->execute([$postId]);
    if ($frozenPost->fetchColumn()) respond(good(['comments' => [], 'hasMore' => false, 'totalCount' => 0]));

    $reported = reportedByViewer($pdo, $viewer, 'comment');

    if (($_POST['threaded'] ?? '') !== '1') {
        // Older apps: the flat list, newest first, every real comment (replies
        // included as ordinary comments; placeholders left out). Comments by
        // users hidden from the viewer (blocked either way, or frozen) are
        // left out, frozen comments show only to their author, flagged.
        [$hf, $hp] = hiddenFilter($pdo, $viewer, 'c.user_id');
        [$ff, $fp] = frozenFilter('c', $viewer);
        $stmt = $pdo->prepare("SELECT c.id, c.post_id, c.user_id, c.comment_text as text, c.created_at, c.frozen_at, c.parent_id, u.email as user_email FROM comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.post_id = ? AND c.deleted_at IS NULL$hf$ff ORDER BY c.created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute(array_merge([$postId], $hp, $fp, [$limit, $offset]));
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $mentions = hydrateMentionsBatch($pdo, array_column($comments, 'text'));
        foreach ($comments as $i => &$comment) {
            $comment['mentions'] = $mentions[$i];
            $comment['reportedByMe'] = in_array((string)$comment['id'], $reported, true);
            if ($comment['frozen_at'] !== null) $comment['frozen'] = true;
            unset($comment['frozen_at']);
        }
        unset($comment);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments c WHERE c.post_id = ? AND c.deleted_at IS NULL$hf$ff");
        $stmt->execute(array_merge([$postId], $hp, $fp));
        $totalCount = (int)$stmt->fetchColumn();
        respond(good(['comments' => $comments, 'hasMore' => ($offset + $limit) < $totalCount, 'totalCount' => $totalCount]));
    }

    // Threaded: top-level comments (paged, newest first), each with its
    // replies (oldest first) and how many there are. A deleted, frozen or
    // hidden-author comment stays as a placeholder when something shows under it.
    $hidden = hiddenUserIds($pdo, $viewer);
    $stmt = $pdo->prepare('SELECT id, user_id, parent_id, deleted_at, frozen_at, created_at FROM comments WHERE post_id = ? ORDER BY created_at ASC, id ASC');
    $stmt->execute([$postId]);
    $tops = [];
    $kids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['state'] = commentDisplayState($row, $viewer, $hidden);
        if ($row['parent_id'] === null) $tops[(int)$row['id']] = $row;
        elseif ($row['state'] !== 'omit') $kids[(int)$row['parent_id']][] = $row;
    }
    $shown = [];   // top-level rows to list, newest first
    foreach (array_reverse($tops, true) as $id => $row) {
        // A placeholder (deleted, frozen, hidden author) only shows when
        // something shows under it.
        if ($row['state'] === 'omit' || ($row['state'] !== 'real' && empty($kids[$id]))) continue;
        $shown[] = $row;
    }
    $totalCount = count($shown);

    $offset0 = $offset;
    $around = (int)($_POST['aroundCommentId'] ?? 0);
    if ($around > 0) {
        $target = commentRow($pdo, $around);
        if ($target && $target['post_id'] === $postId) {
            $topId = (int)($target['parent_id'] ?? $target['id']);
            foreach ($shown as $i => $row) {
                if ((int)$row['id'] === $topId) { $offset0 = intdiv($i, $limit) * $limit; break; }
            }
        }
    }
    $page = array_slice($shown, $offset0, $limit);

    // Text, emails and mentions only for what is actually sent.
    $ids = [];
    foreach ($page as $row) {
        $ids[] = (int)$row['id'];
        foreach (array_slice($kids[(int)$row['id']] ?? [], 0, 200) as $k) $ids[] = (int)$k['id'];
    }
    $details = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT c.id, c.post_id, c.user_id, c.comment_text AS text, c.created_at, c.parent_id, u.email AS user_email FROM comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.id IN ($ph)");
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $details[(int)$r['id']] = $r;
    }
    $mentionTexts = [];
    foreach ($details as $id => $r) $mentionTexts[$id] = $r['text'];
    $mentionIds = array_keys($mentionTexts);
    $mentionList = hydrateMentionsBatch($pdo, array_values($mentionTexts));
    $mentionById = array_combine($mentionIds, $mentionList) ?: [];

    $build = function (array $row) use ($details, $mentionById, $reported) {
        $r = $details[(int)$row['id']];
        $out = [
            'id' => (int)$r['id'], 'post_id' => $r['post_id'],
            'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            'created_at' => $r['created_at'],
        ];
        if ($row['state'] === 'deleted' || $row['state'] === 'frozen') {
            return $out + ['user_id' => 0, 'user_email' => '', 'text' => '', 'mentions' => [], 'reportedByMe' => false]
                + ($row['state'] === 'deleted' ? ['deleted' => true] : ['frozenByStaff' => true]);
        }
        $out += ['user_id' => (int)$r['user_id'], 'user_email' => $r['user_email'], 'text' => $r['text'],
            'mentions' => $mentionById[(int)$r['id']] ?? [],
            'reportedByMe' => in_array((string)$r['id'], $reported, true)];
        if ($row['frozen_at'] !== null) $out['frozen'] = true;
        return $out;
    };
    $comments = [];
    foreach ($page as $row) {
        $c = $build($row);
        $replyRows = $kids[(int)$row['id']] ?? [];
        $c['replyCount'] = count($replyRows);
        $c['replies'] = array_map($build, array_slice($replyRows, 0, 200));
        $comments[] = $c;
    }
    respond(good(['comments' => $comments, 'hasMore' => ($offset0 + $limit) < $totalCount, 'totalCount' => $totalCount, 'offset' => $offset0]));
}

function handle_deleteComment($pdo, $user) {
    $commentId = (int)($_POST['commentId'] ?? 0);
    if (!$commentId) bad('Missing comment ID', 400);

    $stmt = $pdo->prepare('SELECT user_id FROM comments WHERE id = ?');
    $stmt->execute([$commentId]);
    $ownerId = $stmt->fetchColumn();
    if (!$ownerId) bad('Comment not found', 404);
    if ($ownerId != $user['sub']) bad('Can only delete your own comments', 403);

    deleteCommentById($pdo, $commentId);
    respond(good(['deleted' => true]));
}

// Removes a placeholder parent, and its placeholder replies, once nothing live
// is left under it.
function cleanUpPlaceholderThread($pdo, $parentId) {
    $p = commentRow($pdo, $parentId);
    if (!$p || $p['parent_id'] !== null || $p['deleted_at'] === null) return;
    $s = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE parent_id = ? AND deleted_at IS NULL');
    $s->execute([(int)$parentId]);
    if ((int)$s->fetchColumn() === 0) {
        $pdo->prepare('DELETE FROM comments WHERE parent_id = ? OR id = ?')->execute([(int)$parentId, (int)$parentId]);
    }
}

// No ownership check: callers do that (handle_deleteComment for the author,
// adminResolveReport / adminDeleteContent for staff). A top-level comment with
// nothing live under it is removed (with any placeholder replies); one with
// live replies stays as a "Comment deleted" placeholder. A reply always leaves
// its placeholder, and its placeholder parent goes if that was the last live
// reply.
function deleteCommentById($pdo, $commentId) {
    $row = commentRow($pdo, $commentId);
    if (!$row) return false;
    if ($row['deleted_at'] !== null) return false;
    $tomb = $pdo->prepare("UPDATE comments SET comment_text = '', user_id = 0, deleted_at = ? WHERE id = ?");
    if ($row['parent_id'] === null) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE parent_id = ? AND deleted_at IS NULL');
        $s->execute([(int)$commentId]);
        if ((int)$s->fetchColumn() === 0) {
            $pdo->prepare('DELETE FROM comments WHERE parent_id = ? OR id = ?')->execute([(int)$commentId, (int)$commentId]);
        } else {
            $tomb->execute([date('Y-m-d H:i:s'), (int)$commentId]);
        }
        return true;
    }
    $tomb->execute([date('Y-m-d H:i:s'), (int)$commentId]);
    cleanUpPlaceholderThread($pdo, $row['parent_id']);
    return true;
}

// An account is being deleted: their replies go outright; their top-level
// comments go too, unless other people's replies hang under them (then they
// become "Comment deleted" placeholders with no author). Runs inside
// deleteUserAndData's transaction.
function purgeUserComments($pdo, int $uid) {
    $s = $pdo->prepare('SELECT DISTINCT parent_id FROM comments WHERE user_id = ? AND parent_id IS NOT NULL');
    $s->execute([$uid]);
    $parents = $s->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare('DELETE FROM comments WHERE user_id = ? AND parent_id IS NOT NULL')->execute([$uid]);
    foreach ($parents as $p) cleanUpPlaceholderThread($pdo, $p);

    $s = $pdo->prepare('SELECT id FROM comments WHERE user_id = ? AND parent_id IS NULL');
    $s->execute([$uid]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) deleteCommentById($pdo, $id);
}

function handle_getPostCommentCounts($pdo, $user) {
    $postIds = jsonIdList('postIds', 100);
    if (empty($postIds)) respond(good(['counts' => []]));

    respond(good(['counts' => getCommentCountsForPostIds($pdo, $postIds, $user['sub'])]));
}
