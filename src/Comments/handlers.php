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

function handle_createComment($pdo, $user) {
    $postId = trim($_POST['postId'] ?? '');
    $text = trim($_POST['text'] ?? '');
    if (!$postId) bad('Missing post ID', 400);
    if (!$text) bad('Comment text required', 400);
    if (strlen($text) > 5000) bad('Comment too long (max 5000 chars)', 400);
    validateContent($text, 'Illegal characters in comment');
    $mentionIds = extractMentions($text);

    $stmt = $pdo->prepare('INSERT INTO comments (post_id, user_id, comment_text, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$postId, $user['sub'], $text, date('Y-m-d H:i:s')]);
    $commentId = $pdo->lastInsertId();

    $ownerId = (int)explode('.', $postId)[0];
    if ($mentionIds || $ownerId != $user['sub']) {
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$user['sub']]);
        $actorEmail = $stmt->fetchColumn();
        if ($ownerId != $user['sub']) {
            createNotification($pdo, $ownerId, $user['sub'], $actorEmail, 'comment', $postId);
        }
        notifyMentions($pdo, $mentionIds, $user['sub'], $actorEmail, $postId);
    }

    respond(good(['commentId' => $commentId]));
}

function handle_getPostComments($pdo, $user) {
    $postId = trim($_POST['postId'] ?? '');
    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 25;
    $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
    if (!$postId) bad('Missing post ID', 400);

    $stmt = $pdo->prepare('SELECT c.id, c.post_id, c.user_id, c.comment_text as text, c.created_at, u.email as user_email FROM comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.post_id = ? ORDER BY c.created_at DESC LIMIT ? OFFSET ?');
    $stmt->execute([$postId, $limit, $offset]);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($comments as &$comment) $comment['mentions'] = hydrateMentions($pdo, $comment['text']);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE post_id = ?');
    $stmt->execute([$postId]);
    $totalCount = $stmt->fetchColumn();
    $hasMore = ($offset + $limit) < $totalCount;

    respond(good(['comments' => $comments, 'hasMore' => $hasMore, 'totalCount' => $totalCount]));
}

function handle_deleteComment($pdo, $user) {
    $commentId = (int)($_POST['commentId'] ?? 0);
    if (!$commentId) bad('Missing comment ID', 400);

    $stmt = $pdo->prepare('SELECT user_id FROM comments WHERE id = ?');
    $stmt->execute([$commentId]);
    $ownerId = $stmt->fetchColumn();
    if (!$ownerId) bad('Comment not found', 404);
    if ($ownerId != $user['sub']) bad('Can only delete your own comments', 403);

    $stmt = $pdo->prepare('DELETE FROM comments WHERE id = ?');
    $stmt->execute([$commentId]);

    respond(good(['deleted' => true]));
}

function handle_getPostCommentCounts($pdo, $user) {
    $postIdsJson = $_POST['postIds'] ?? '[]';
    $postIds = json_decode($postIdsJson, true);
    if (!is_array($postIds) || empty($postIds)) respond(good(['counts' => []]));

    $counts = [];
    foreach ($postIds as $postId) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE post_id = ?');
        $stmt->execute([$postId]);
        $counts[$postId] = (int)$stmt->fetchColumn();
    }

    respond(good(['counts' => $counts]));
}
