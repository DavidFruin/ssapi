<?php
// config.php - Application Configuration

$CONFIG = [
    // Media upload limits (media.php). Adjust these to change what's
    // accepted without touching code.
    'media_max_seconds' => 10,             // video/audio can't be longer than this
    'media_max_fps' => 60,                 // video above this frame rate is resampled down
    'media_max_side' => 1920,              // longest side (px) images and video frames are scaled to
    'media_max_image_bytes' => 10 * 1024 * 1024,
    'media_max_video_bytes' => 100 * 1024 * 1024,
    'media_max_audio_bytes' => 50 * 1024 * 1024,

    // Max distinct @[id] mentions allowed in a single post or comment.
    'max_mentions' => 10,

    // Sessions. An access token is short-lived and refreshed silently; the
    // session is the durable login and only expires after this long with no
    // use at all - it slides forward every time a client refreshes, so an
    // active device never has to re-enter a password.
    'session_access_ttl' => 86400,          // 1 day
    'session_refresh_ttl' => 30 * 86400,    // 30 days, sliding
    // Logging in past this many live sessions revokes the least recently
    // used one. Raise it if you legitimately use more devices than this -
    // each browser counts as one, and so does each of the three C clients
    // on each machine.
    'session_max_per_user' => 10,
];

function loadDotEnv($dir) {
    $path = $dir . '/.env';
    if (!file_exists($path) || !is_readable($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));
        if (strlen($val) >= 2 && (($val[0] === '"' && $val[strlen($val)-1] === '"') || ($val[0] === "'" && $val[strlen($val)-1] === "'"))) {
            $val = substr($val, 1, -1);
        }
        if ($key !== '' && getenv($key) === false && !isset($_ENV[$key])) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

// Only private/, never the docroot or its parent -- a missing private/
// directory used to silently fall back to creating the DB and logs inside
// the docroot itself (below), so secrets have no fallback path either.
$privateDir = dirname(__DIR__) . '/private';
loadDotEnv($privateDir);

$envSecret = getenv('JWT_SECRET') ?: ($_ENV['JWT_SECRET'] ?? '');
if ($envSecret !== '') {
    $CONFIG['jwt_secret'] = $envSecret;
} else {
    $CONFIG['jwt_secret'] = null;
}

$CONFIG['vapid_public'] = getenv('VAPID_PUBLIC_KEY') ?: ($_ENV['VAPID_PUBLIC_KEY'] ?? null);
$CONFIG['vapid_private'] = getenv('VAPID_PRIVATE_KEY') ?: ($_ENV['VAPID_PRIVATE_KEY'] ?? null);
// Read by pushNotification() (api.php), which until now had no way to
// actually set it -- it fell back to its own hard-coded default every time.
$CONFIG['vapid_subject'] = getenv('VAPID_SUBJECT') ?: ($_ENV['VAPID_SUBJECT'] ?? null);

// Off by default (S10) -- was hard-coded true, which meant every request
// logged emails, IPs, post snippets and session ids into api.log forever
// (no rotation until S10 below), on every host including prod.
$CONFIG['debug'] = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);

// No docroot fallback: db() (schema.php) checks private/ actually exists
// before ever opening a connection and fails closed (500) if it doesn't,
// rather than quietly creating userdata.db where it's web-reachable.
$CONFIG['db_path'] = $privateDir . '/userdata.db';
$CONFIG['log_dir'] = $privateDir . '/logs';
