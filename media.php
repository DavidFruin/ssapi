<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logging.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/auth.php';
// Loads src/Media/handlers.php (and every other module's, harmlessly --
// see that file's header comment for why sharing Composer's autoload.files
// with api.php's modules doesn't collide with this file's own logMsg/
// respond/bad/good/requireAuth below -- db() itself is shared, from
// schema.php, not duplicated here).
require_once __DIR__ . '/vendor/autoload.php';

ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Routes through logging.php's writeLog() (S10) -- was always on
// regardless of $CONFIG['debug'], with no rotation and no size limit.
function logMsg($msg) {
    writeLog('DEBUG', 'media', $msg);
}

function respond($data, $code = 200) {
    ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bad($msg, $code = 400) {
    global $action;
    logMsg("ERROR: action=$action msg=$msg");
    respond(['valid' => false, 'message' => $msg], $code);
}

function good($data = []) {
    return array_merge(['valid' => true], $data);
}

// db() now lives in schema.php, shared with api.php -- a static-cached
// connection there means requireAuth() and the upload/delete handler below
// share one PDO handle per request instead of each opening their own.

// jwtVerify/verifyUser live in auth.php, shared with api.php - this file
// used to carry its own near-identical copies, which is exactly how the two
// would have drifted once session checks were added to only one of them.

function requireAuth() {
    $jwt = bearerToken();
    if (!$jwt) bad('Unauthorized', 401);

    $pdo = db();
    $user = verifyUser($jwt, $pdo);
    if (!$user) bad('Unauthorized', 401);

    // email arrives on $user already via verifyUser()/sessionLookup() (P9).
    $user['email'] = $user['email'] ?: 'User';
    return $user;
}

// getMediaDir, ensureMediaDir, getMediaType, originalExtension, the ffmpeg/
// GD processing pipeline, and handle_uploadMedia/handle_deleteMedia all
// moved to src/Media/handlers.php. logMsg/respond/bad/good/db/requireAuth
// stay here -- see that file's header comment for why they couldn't move
// (they'd collide with api.php's own same-named functions the moment both
// entry points load the same Composer autoload.files list).

$handlers = [
    'uploadMedia' => 'handle_uploadMedia',
    'deleteMedia' => 'handle_deleteMedia'
];

logMsg("REQUEST: action=$action");

if (!isset($handlers[$action])) {
    bad('Unknown action', 400);
}

$handlers[$action]();
