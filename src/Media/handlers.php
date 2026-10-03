<?php
// src/Media/handlers.php - Media module: upload/delete handling, image/
// video/audio processing (GD + ffmpeg), and the media directory layout.
//
// IMPORTANT, unlike every other module: media.php is a SEPARATE entry
// point from api.php with its own copies of logMsg()/respond()/bad()/
// good()/requireAuth() (different implementations, same names as api.php's
// -- they were never shared to begin with, see media.php's own comments).
// db() is the one exception -- it's shared, from schema.php (P3), not
// duplicated. Those stay in media.php, NOT here -- moving them here would
// load them unconditionally via Composer's autoload.files on every
// request, api.php's included, and api.php already defines its own
// versions of those exact names. That's a straight "Cannot redeclare"
// fatal the moment both entry points share one autoload.files list.
//
// Two things could NOT just be copy-pasted from media.php as-is:
//
// 1. __DIR__ in getMediaDir() meant media.php's directory (repo root).
//    Originally fixed with a __DIR__ . '/../../media/...' relative path;
//    now reads $CONFIG['media_dir'] instead (deploy layout: L1) since a
//    code-relative path broke again the moment this code could live
//    outside public_html entirely, not just one level deeper inside it.
//
// 2. $allowedImageTypes/$allowedVideoTypes/$allowedAudioTypes/$maxSizes
//    were plain global variables assigned at the top level of media.php.
//    Composer's "files" autoload runs each file's top level inside its
//    own internal loader function, so a plain `$var = ...;` there becomes
//    local to THAT function and is gone by the time a handler tries
//    `global $var` -- only const/function/class declarations survive
//    that. The three type lists don't depend on anything at runtime, so
//    they're consts now (ALLOWED_IMAGE_TYPES etc). $maxSizes reads
//    $CONFIG, so it couldn't become a const -- it's the function
//    mediaMaxSizes() below instead, called where the old array was
//    indexed directly.

const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const ALLOWED_VIDEO_TYPES = ['video/quicktime', 'video/mp4', 'video/m4v', 'video/webm'];
const ALLOWED_AUDIO_TYPES = ['audio/wav', 'audio/mpeg', 'audio/mp3', 'audio/webm'];

// Limits read from $CONFIG (config.php) so they can be adjusted without
// touching this file. A function, not a precomputed global array -- see
// this file's header comment for why.
function mediaMaxSizes() {
    global $CONFIG;
    return [
        'image' => $CONFIG['media_max_image_bytes'],
        'video' => $CONFIG['media_max_video_bytes'],
        'audio' => $CONFIG['media_max_audio_bytes'],
    ];
}

// Exposes the subset of $CONFIG a client needs to self-enforce limits
// before even trying an upload -- e.g. ssreact reads maxSeconds to cap a
// recording client-side and to reject an over-long picked file before
// spending a round trip on it, rather than hardcoding its own copy of
// media_max_seconds that could drift from this file's. Auth'd (not in
// api.php's $PUBLIC_ENDPOINTS) since nothing outside the app needs it.
function handle_getMediaLimits($pdo, $user) {
    global $CONFIG;
    respond(good([
        'maxSeconds' => $CONFIG['media_max_seconds'],
        'maxFps' => $CONFIG['media_max_fps'],
        'maxSide' => $CONFIG['media_max_side'],
        'maxImageBytes' => $CONFIG['media_max_image_bytes'],
        'maxVideoBytes' => $CONFIG['media_max_video_bytes'],
        'maxAudioBytes' => $CONFIG['media_max_audio_bytes'],
    ]));
}

function getMediaDir($userId) {
    global $CONFIG;
    // $CONFIG['media_dir'] (deploy layout: L1), not a path relative to this
    // file -- that broke the moment the code moved out of public_html.
    return $CONFIG['media_dir'] . '/' . $userId;
}

function ensureMediaDir($userId, $type) {
    $dir = getMediaDir($userId) . '/' . $type;
    if (!is_dir($dir)) {
        if (!is_dir(dirname($dir))) {
            mkdir(dirname($dir), 0755, true);
        }
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function getMediaType($mimeType) {
    if (in_array($mimeType, ALLOWED_IMAGE_TYPES)) return 'image';
    if (in_array($mimeType, ALLOWED_VIDEO_TYPES)) return 'video';
    if (in_array($mimeType, ALLOWED_AUDIO_TYPES)) return 'audio';
    return null;
}

// Real container/format from the file's first bytes; ignores whatever the
// client claimed via Content-Type or filename. Needs no extension and no
// fileinfo/mbstring extension, neither of which can be assumed on every
// host this runs on. Returns ['family' => image|av|audio, 'ext' => ...]
// or null if nothing recognized the content.
function sniffMedia($path) {
    $h = @file_get_contents($path, false, null, 0, 16);
    if ($h === false || strlen($h) < 12) return null;
    if (str_starts_with($h, "\x1A\x45\xDF\xA3")) return ['family' => 'av', 'ext' => 'webm'];
    if (substr($h, 4, 4) === 'ftyp') return ['family' => 'av', 'ext' => substr($h, 8, 4) === 'qt  ' ? 'mov' : 'mp4'];
    if (str_starts_with($h, 'RIFF') && substr($h, 8, 4) === 'WAVE') return ['family' => 'audio', 'ext' => 'wav'];
    if (str_starts_with($h, 'ID3') || (ord($h[0]) === 0xFF && (ord($h[1]) & 0xE0) === 0xE0)) return ['family' => 'audio', 'ext' => 'mp3'];
    $img = @getimagesize($path);
    $imgExt = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if ($img && isset($imgExt[$img[2]])) return ['family' => 'image', 'ext' => $imgExt[$img[2]]];
    return null;
}

const FFMPEG = '/usr/bin/ffmpeg';
const FFPROBE = '/usr/bin/ffprobe';

// Reads one ffprobe field. Returns '' when exec is unavailable or the probe
// fails, which callers treat as "unknown" rather than as a failure.
function ffprobeValue($path, $entries, $stream = false) {
    if (!function_exists('exec')) return '';
    $cmd = escapeshellarg(FFPROBE) . ' -v error'
        . ($stream ? ' -select_streams v:0' : '')
        . ' -show_entries ' . escapeshellarg($entries)
        . ' -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null';
    $out = [];
    exec($cmd, $out);
    return trim($out[0] ?? '');
}

// Duration in seconds, or 0 when it can't be determined.
function mediaDuration($path) {
    return (float)ffprobeValue($path, 'format=duration');
}

// Frame rate as a number -- ffprobe reports it as a fraction like "60000/1001".
// Returns 0 when it can't be determined.
function videoFrameRate($path) {
    $raw = ffprobeValue($path, 'stream=r_frame_rate', true);
    if ($raw === '') return 0;
    if (strpos($raw, '/') === false) return (float)$raw;
    [$num, $den] = explode('/', $raw, 2);
    return (float)$den > 0 ? (float)$num / (float)$den : 0;
}

// Runs ffmpeg with the given arguments (each shell-escaped). Returns true on success.
function runFfmpeg($args) {
    if (!function_exists('exec')) {
        logMsg("ffmpeg skipped: exec() is disabled");
        return false;
    }
    @set_time_limit(600);
    $cmd = escapeshellarg(FFMPEG) . ' -hide_banner -loglevel error -y '
        . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $output = [];
    $code = 1;
    $start = microtime(true);
    exec($cmd, $output, $code);
    $seconds = round(microtime(true) - $start, 1);
    logMsg("ffmpeg exit=$code time={$seconds}s " . substr(implode(' ', $output), 0, 1000));
    return $code === 0;
}

// Scale filter keeping the longest side at most $max px, with even dimensions for H.264.
function ffmpegScale($max) {
    return "scale='trunc(min(1,$max/max(iw,ih))*iw/2)*2':'trunc(min(1,$max/max(iw,ih))*ih/2)*2'";
}

// Phones save photos sideways plus an EXIF tag saying which way is up. GD
// ignores that tag (and it's lost on WebP conversion), so apply it to the
// pixels. Returns the image unchanged if EXIF can't be read.
function applyExifOrientation($img, $inputPath) {
    if (!function_exists('exif_read_data')) return $img;
    $exif = @exif_read_data($inputPath);
    $orientation = (int)($exif['Orientation'] ?? 1);
    if ($orientation === 2) imageflip($img, IMG_FLIP_HORIZONTAL);
    if ($orientation === 4) imageflip($img, IMG_FLIP_VERTICAL);
    if ($orientation === 5 || $orientation === 7) imageflip($img, IMG_FLIP_VERTICAL);
    $angles = [3 => 180, 5 => -90, 6 => -90, 7 => 90, 8 => 90];
    if (!isset($angles[$orientation])) return $img;
    $rotated = imagerotate($img, $angles[$orientation], 0);
    if (!$rotated) return $img;
    imagedestroy($img);
    logMsg("processImage: applied EXIF orientation $orientation");
    return $rotated;
}

function processImage($inputPath, $outputPath) {
    global $CONFIG;
    logMsg("processImage: input=$inputPath output=$outputPath");

    if (!file_exists($inputPath)) {
        logMsg("processImage ERROR: input file does not exist");
        return false;
    }

    $inputExt = strtolower(pathinfo($inputPath, PATHINFO_EXTENSION));
    logMsg("processImage: input extension = $inputExt");

    // Load image using GD
    switch ($inputExt) {
        case "jpg":
        case "jpeg":
            $src = @imagecreatefromjpeg($inputPath);
            break;
        case "png":
            $src = @imagecreatefrompng($inputPath);
            break;
        case "gif":
            $src = @imagecreatefromgif($inputPath);
            break;
        case "webp":
            $src = @imagecreatefromwebp($inputPath);
            break;
        default:
            logMsg("processImage ERROR: unsupported format: $inputExt");
            return false;
    }

    if (!$src) {
        logMsg("processImage ERROR: failed to load image");
        return false;
    }

    if ($inputExt === "jpg" || $inputExt === "jpeg") {
        $src = applyExifOrientation($src, $inputPath);
    }

    $srcWidth = imagesx($src);
    $srcHeight = imagesy($src);
    logMsg("processImage: original size = {$srcWidth}x{$srcHeight}");

    // Resize so the longest side is at most media_max_side (portrait or landscape)
    $maxSide = $CONFIG['media_max_side'];

    if (max($srcWidth, $srcHeight) > $maxSide) {
        $ratio = $maxSide / max($srcWidth, $srcHeight);
        $newWidth = (int)($srcWidth * $ratio);
        $newHeight = (int)($srcHeight * $ratio);

        $dst = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG
        if ($inputExt === "png") {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
        logMsg("processImage: resized to {$newWidth}x{$newHeight}");
    } else {
        $dst = $src;
    }

    // Save as WebP with 85% quality
    $result = imagewebp($dst, $outputPath, 85);

    if ($dst !== $src) {
        imagedestroy($dst);
    }
    imagedestroy($src);

    if (!$result) {
        logMsg("processImage ERROR: failed to save webp");
        return false;
    }

    if (!file_exists($outputPath)) {
        logMsg("processImage ERROR: output file not created");
        return false;
    }

    logMsg("processImage SUCCESS: saved to $outputPath");
    return true;
}

// Converts to MP4 (H.264/AAC) so it plays everywhere, and saves a WebP of the
// first frame as the thumbnail. If ffmpeg can't convert, keeps the original
// file. Returns the saved file's extension, or false on failure.
function processVideo($inputPath, $outputBase, $originalExt, $thumbnailPath) {
    global $CONFIG;
    logMsg("processVideo: input=$inputPath output=$outputBase");

    // Only resample when the source is actually above the cap -- forcing the
    // rate unconditionally would duplicate frames on a 30fps clip and inflate
    // it for nothing.
    $maxFps = $CONFIG['media_max_fps'];
    $filters = ffmpegScale($CONFIG['media_max_side']);
    $sourceFps = videoFrameRate($inputPath);
    if ($sourceFps > $maxFps) {
        $filters .= ',fps=' . $maxFps;
        logMsg("processVideo: source is {$sourceFps}fps, capping at " . $maxFps);
    }

    $converted = runFfmpeg([
        '-i', $inputPath, '-vf', $filters,
        '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
        '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', "$outputBase.mp4",
    ]);
    if ($converted) {
        $ext = 'mp4';
    } else {
        @unlink("$outputBase.mp4");
        if (!copy($inputPath, "$outputBase.$originalExt")) return false;
        $ext = $originalExt;
    }

    createVideoThumbnail("$outputBase.$ext", $thumbnailPath);
    logMsg("processVideo SUCCESS: saved $outputBase.$ext");
    return $ext;
}

// ffmpeg grabs the first frame as PNG; GD converts it to WebP.
function createVideoThumbnail($videoPath, $thumbnailPath) {
    $png = "$thumbnailPath.png";
    if (!runFfmpeg(['-i', $videoPath, '-frames:v', '1', '-vf', ffmpegScale(640), $png])) return false;
    $img = @imagecreatefrompng($png);
    @unlink($png);
    if (!$img) return false;
    $ok = imagewebp($img, $thumbnailPath, 80);
    imagedestroy($img);
    return $ok;
}

// Converts to MP3. Files that are already MP3 are kept as-is, and if ffmpeg
// can't convert, the original is kept. Returns the saved extension, or false.
function processAudio($inputPath, $outputBase, $originalExt) {
    logMsg("processAudio: input=$inputPath output=$outputBase");

    if ($originalExt !== 'mp3') {
        if (runFfmpeg(['-i', $inputPath, '-vn', '-c:a', 'libmp3lame', '-q:a', '2', "$outputBase.mp3"])) return 'mp3';
        @unlink("$outputBase.mp3");
    }
    return copy($inputPath, "$outputBase.$originalExt") ? $originalExt : false;
}

function handle_uploadMedia() {
    global $CONFIG;

    $user = requireAuth();
    $uid = $user['sub'];

    if (!isset($_FILES['file'])) {
        bad('No file was selected', 400);
    }

    // PHP's own upload_max_filesize/post_max_size reject an oversized file
    // before it ever reaches this code, so that's the most likely cause here
    // -- but each error code is a different situation, not all size-related.
    $uploadError = $_FILES['file']['error'];
    if ($uploadError !== UPLOAD_ERR_OK) {
        logMsg("uploadMedia ERROR: PHP upload error code $uploadError");
        switch ($uploadError) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                bad('That file is larger than the server accepts. Try a smaller file.', 400);
            case UPLOAD_ERR_PARTIAL:
                bad('The upload was interrupted partway through. Check your connection and try again.', 400);
            case UPLOAD_ERR_NO_FILE:
                bad('No file was selected', 400);
            default:
                bad('The server could not accept that upload. Please try again.', 500);
        }
    }

    $file = $_FILES['file'];
    $tmpPath = $file['tmp_name'];
    // Browsers may add codec parameters, e.g. "video/mp4;codecs=avc1".
    $mimeType = strtolower(trim(explode(';', $file['type'])[0]));
    $fileSize = $file['size'];
    $fileName = $file['name'];

    logMsg("uploadMedia: file=$fileName mime=$mimeType size=$fileSize");

    $mediaType = getMediaType($mimeType);
    if (!$mediaType) {
        $shownType = $mimeType !== '' ? $mimeType : 'unknown';
        bad("That file type ($shownType) isn't supported. Allowed: jpg, png, gif, webp, mov, mp4, m4v, wav, mp3", 400);
    }

    $maxSizes = mediaMaxSizes();
    if ($fileSize > $maxSizes[$mediaType]) {
        $maxMb = round($maxSizes[$mediaType] / (1024 * 1024), 1);
        $gotMb = round($fileSize / (1024 * 1024), 1);
        bad("That $mediaType is {$gotMb}MB - the max is {$maxMb}MB.", 400);
    }

    // Real format from the file's own bytes, not the Content-Type the client
    // claimed. A mismatch (e.g. a renamed text file claiming video/mp4)
    // means either a bug or a deliberate attempt to get an unsafe file
    // served from /media/ -- video and audio aren't re-encoded from a
    // trusted decoder the way images are via GD, and when ffmpeg fails
    // (always, on a host with no ffmpeg) the original bytes get kept as-is.
    $sniffed = sniffMedia($tmpPath);
    $compatible = $sniffed && (
        ($mediaType === 'image' && $sniffed['family'] === 'image') ||
        ($mediaType === 'video' && $sniffed['family'] === 'av') ||
        ($mediaType === 'audio' && in_array($sniffed['family'], ['av', 'audio'], true)) // MediaRecorder audio is WebM/MP4
    );
    if (!$compatible) bad("That file doesn't look like a valid $mediaType.", 400);

    $timestamp = date('YmdHis');
    $random = bin2hex(random_bytes(8));
    $base = "{$uid}_{$mediaType}_{$timestamp}_{$random}";

    ensureMediaDir($uid, $mediaType);
    $mediaDir = getMediaDir($uid);
    $typeDir = $mediaDir . '/' . $mediaType;
    logMsg("uploadMedia: mediaDir=$mediaDir typeDir=$typeDir exists=" . (is_dir($typeDir) ? "yes" : "no"));

    // Staged outside the docroot, never under a web-served media/ path --
    // the whole point of sniffing above is that this file isn't trustworthy
    // yet, and it sits here while ffprobe/ffmpeg run (up to minutes).
    $stageDir = dirname($CONFIG['db_path']) . '/tmp';
    if (!is_dir($stageDir)) mkdir($stageDir, 0700, true);
    $tempInput = "{$stageDir}/{$base}.{$sniffed['ext']}";

    if (!move_uploaded_file($tmpPath, $tempInput)) {
        logMsg("uploadMedia ERROR: move_uploaded_file failed. tmpPath=$tmpPath, tempInput=$tempInput");
        bad('Could not save the upload on the server. Please try again.', 500);
    }

    logMsg("uploadMedia: tempInput=$tempInput exists=" . (file_exists($tempInput) ? "yes" : "no"));

    // Checked here rather than after conversion so an over-long file is
    // rejected before spending minutes transcoding it. A duration of 0 means
    // ffprobe couldn't tell us, so it's let through.
    if ($mediaType === 'video' || $mediaType === 'audio') {
        $duration = mediaDuration($tempInput);
        $maxSeconds = $CONFIG['media_max_seconds'];
        if ($duration > $maxSeconds) {
            @unlink($tempInput);
            bad("That $mediaType is " . round($duration, 1) . " seconds long. Max: $maxSeconds seconds", 400);
        }
    }

    $originalExt = $sniffed['ext'];
    $thumbnailPath = "{$typeDir}/thumb_{$base}.webp";

    if ($mediaType === 'image') {
        $ext = processImage($tempInput, "{$typeDir}/{$base}.webp") ? 'webp' : false;
    } elseif ($mediaType === 'video') {
        $ext = processVideo($tempInput, "{$typeDir}/{$base}", $originalExt, $thumbnailPath);
    } else {
        $ext = processAudio($tempInput, "{$typeDir}/{$base}", $originalExt);
    }

    @unlink($tempInput);
    if (!$ext) bad("Couldn't process that $mediaType - it may be corrupted or in an unsupported format.", 500);

    $filename = "{$base}.{$ext}";
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO media (user_id, filename, type, path, created_at) VALUES (?, ?, ?, ?, ?)');
    $path = "/media/{$uid}/{$mediaType}/{$filename}";
    $stmt->execute([$uid, $filename, $mediaType, $path, date('Y-m-d H:i:s')]);
    $mediaId = $pdo->lastInsertId();

    $thumbUrl = ($mediaType === 'video' && file_exists($thumbnailPath)) ? "/media/{$uid}/video/thumb_{$base}.webp" : null;

    logMsg("uploadMedia SUCCESS: mediaId=$mediaId path=$path");

    respond(good([
        'mediaId' => $mediaId,
        'mediaUrl' => $path,
        'thumbnailUrl' => $thumbUrl,
        'type' => $mediaType
    ]));
}

function handle_deleteMedia() {
    $user = requireAuth();
    $uid = $user['sub'];

    $mediaId = (int)($_POST['mediaId'] ?? 0);
    if (!$mediaId) bad('Missing mediaId', 400);

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM media WHERE id = ? AND user_id = ?');
    $stmt->execute([$mediaId, $uid]);
    $media = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$media) bad('Media not found', 404);

    $mediaDir = getMediaDir($uid);
    $filePath = $mediaDir . str_replace('/media/' . $uid, '', $media['path']);
    if (file_exists($filePath)) unlink($filePath);

    if ($media['type'] === 'video') {
        $fullThumbPath = $mediaDir . '/video/thumb_' . pathinfo($media['path'], PATHINFO_FILENAME) . '.webp';
        if (file_exists($fullThumbPath)) unlink($fullThumbPath);
    }

    $stmt = $pdo->prepare('DELETE FROM media WHERE id = ?');
    $stmt->execute([$mediaId]);

    logMsg("deleteMedia SUCCESS: mediaId=$mediaId");

    respond(good(['deleted' => true]));
}
