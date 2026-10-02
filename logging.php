<?php
// logging.php - Log writing, rotation, and formatting

if (!defined('LOG_DIR')) {
    global $CONFIG;
    $cfgLogDir = $CONFIG['log_dir'] ?? null;
    define('LOG_DIR', $cfgLogDir ?: (__DIR__ . '/logs'));
}
define('LOG_MAX_SIZE', 5 * 1024 * 1024); // 5MB
define('LOG_ROTATE_COUNT', 3);

define('LOG_LEVELS', [
    'DEBUG' => 0,
    'INFO'  => 1,
    'WARN'  => 2,
    'ERROR' => 3,
]);

function getLogFile($category) {
    return LOG_DIR . '/' . $category . '.log';
}

function ensureLogDir() {
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0755, true);
    }
}

function rotateLog($filePath) {
    if (!file_exists($filePath) || filesize($filePath) < LOG_MAX_SIZE) return;

    // Delete oldest
    $oldest = $filePath . '.' . LOG_ROTATE_COUNT;
    if (file_exists($oldest)) @unlink($oldest);

    // Shift existing rotated files
    for ($i = LOG_ROTATE_COUNT - 1; $i >= 1; $i--) {
        $src = $filePath . '.' . $i;
        $dst = $filePath . '.' . ($i + 1);
        if (file_exists($src)) @rename($src, $dst);
    }

    // Rotate current
    @rename($filePath, $filePath . '.1');
}

function writeLog($level, $category, $message, $context = []) {
    global $CONFIG;

    $minLevel = (!empty($CONFIG['debug'])) ? 'DEBUG' : 'WARN';
    if ((LOG_LEVELS[$level] ?? 0) < (LOG_LEVELS[$minLevel] ?? 0)) return;

    ensureLogDir();

    $logFile = getLogFile($category);
    rotateLog($logFile);

    $timestamp = date('Y-m-d H:i:s');
    $userId = $context['user_id'] ?? '-';
    $url = $context['url'] ?? '-';
    $ua = $context['user_agent'] ?? '-';
    $ip = $context['ip'] ?? '-';
    $contextStr = $context['extra'] ?? '';

    $entry = "[$timestamp] [$level] [$category] $message | user=$userId | ip=$ip | url=$url | ua=$ua";
    if ($contextStr) $entry .= " | $contextStr";
    $entry .= "\n";

    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

// logFrontendError/logFrontendInfo/logApiAccess/logPhpError (plus
// jwtClaimsUnverified, removed in S6) were never called anywhere --
// deleted rather than kept as unused surface. logApiError stays: bad()
// calls it on every error response.
function logApiError($action, $message, $context = []) {
    $context['extra'] = 'action=' . $action;
    writeLog('ERROR', 'api', $message, $context);
}

function handle_log_request($pdo, $user) {
    // No $user check: 'log' isn't in api.php's $PUBLIC_ENDPOINTS, so
    // requireAuth() already guarantees a user or has responded 401 and
    // exited before this handler is ever reached.
    $level = $_POST['level'] ?? 'ERROR';
    // One line per entry: strip line breaks so a client can't forge log lines.
    $clean = fn($v) => substr(str_replace(["\r", "\n"], ' ', (string)$v), 0, 2000);
    $message = $clean($_POST['message'] ?? '');
    $extra = $clean($_POST['extra'] ?? '');

    $context = [
        'user_id' => $user['sub'] ?? '-',
        'url' => $clean($_SERVER['HTTP_REFERER'] ?? $_POST['url'] ?? '-'),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '-',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '-',
        'extra' => $extra,
    ];

    $validLevels = ['DEBUG', 'INFO', 'WARN', 'ERROR'];
    $level = in_array(strtoupper($level), $validLevels) ? strtoupper($level) : 'ERROR';

    // Always 'frontend': this endpoint exists for the client to report its
    // own errors, not to let a caller pick which log file (api/access/php)
    // their entry lands in.
    writeLog($level, 'frontend', $message, $context);

    respond(good(['message' => 'Logged']));
}
