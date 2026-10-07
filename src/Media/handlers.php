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

// The formats phones, browsers and normal apps produce are accepted: what
// the client claims (Content-Type, filename) is ignored, the file is
// identified from its own bytes (classifyMedia below), and it's converted to
// WebP / MP4 / MP3. Old desktop/broadcast formats (AVI, FLV, WMV/ASF,
// MPEG-TS/PS, TIFF, ICO) are refused: nobody records in them any more, and
// their demuxers and decoders have the longest CVE history. What keeps this
// safe is the signature check, the ffprobe container allow-list and the
// codec allow-list (both below), not the client's word.

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
        'storageUsedBytes' => mediaBytesUsed($pdo, $user['sub']),
        'storageLimitBytes' => $CONFIG['media_max_user_bytes'],
        // false when ffmpeg isn't working: clients hide video/audio options.
        'videoSupported' => mediaCapabilities()['av'],
        'audioSupported' => mediaCapabilities()['av'],
        'maxSeconds' => $CONFIG['media_max_seconds'],
        'maxFps' => $CONFIG['media_max_fps'],
        'maxSide' => $CONFIG['media_max_side'],
        'maxImageBytes' => $CONFIG['media_max_image_bytes'],
        'maxVideoBytes' => $CONFIG['media_max_video_bytes'],
        'maxAudioBytes' => $CONFIG['media_max_audio_bytes'],
    ]));
}

// Total stored bytes for a user. Rows from before the bytes column (NULL)
// count as 0 until bin/media-backfill.php --bytes fills them.
function mediaBytesUsed($pdo, $userId) {
    $s = $pdo->prepare('SELECT COALESCE(SUM(bytes), 0) FROM media WHERE user_id = ?');
    $s->execute([$userId]);
    return (int)$s->fetchColumn();
}

// "1 GB", "500 MB": the quota in the message follows the config value.
function formatStorageLimit($bytes) {
    if ($bytes >= 1073741824 && $bytes % 1073741824 === 0) return ($bytes / 1073741824) . ' GB';
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    return round($bytes / 1048576) . ' MB';
}

function rejectOverQuota() {
    global $CONFIG;
    $limit = formatStorageLimit($CONFIG['media_max_user_bytes']);
    bad("You have reached your media storage limit of $limit of media.", 413, ['code' => 'media_quota']);
}

// Every file a media row owns: the file itself, a video's thumbnail, and the
// feed variant and poster when set.
function mediaRowFiles($row) {
    $files = [];
    $main = mediaFilePath($row['path']);
    if ($main !== null) {
        $files[] = $main;
        if ($row['type'] === 'video') $files[] = preg_replace('#/video/([^/]+)\.[^./]+$#', '/video/thumb_$1.webp', $main);
    }
    foreach (['variant_path', 'poster_path'] as $col) {
        $f = !empty($row[$col]) ? mediaFilePath($row[$col]) : null;
        if ($f !== null) $files[] = $f;
    }
    return array_values(array_unique($files));
}

// Deletes uploads never attached to a post within 24 h (abandoned drafts,
// crashed clients): files and rows. For one user, or for anyone when
// $userId is null. Returns how many were removed.
function sweepOrphanMedia($pdo, $userId = null, $limit = 50) {
    $cutoff = date('Y-m-d H:i:s', time() - 86400);
    $sql = 'SELECT * FROM media WHERE post_id IS NULL AND created_at < ?' . ($userId !== null ? ' AND user_id = ?' : '') . ' LIMIT ' . (int)$limit;
    $s = $pdo->prepare($sql);
    $s->execute($userId !== null ? [$cutoff, $userId] : [$cutoff]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $del = $pdo->prepare('DELETE FROM media WHERE id = ? AND post_id IS NULL');
    foreach ($rows as $r) {
        $del->execute([$r['id']]);
        if ($del->rowCount() === 0) continue; // attached in the meantime
        foreach (mediaRowFiles($r) as $f) if (file_exists($f)) @unlink($f);
    }
    if ($rows) logMsg('sweepOrphanMedia: removed ' . count($rows) . ' unattached upload(s)' . ($userId !== null ? " for user $userId" : ''));
    return count($rows);
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

// Real container/format from the file's first bytes; ignores whatever the
// client claimed via Content-Type or filename. Needs no extension and no
// fileinfo/mbstring extension, neither of which can be assumed on every
// host this runs on. Returns ['family' => image|av|audio, 'ext' => ...]
// or null if nothing recognized the content.
//
// Only files that start with a known BINARY signature get through. Text-ish
// containers that can make ffmpeg read other files or URLs (HLS/M3U8,
// ffconcat, DASH/XML, SVG, SDP) have no such signature, so they never reach
// ffmpeg at all.
//
// family 'av' means "a container that might hold video, audio or both" --
// classifyMedia asks ffprobe which. ext is 'img' for an image GD can't read
// (converted by ffmpeg first) and 'bin' where the extension doesn't matter.
// For images getimagesize() could read, 'width'/'height' come from the
// header (no pixels are decoded), so classifyMedia can refuse an oversized
// image before anything allocates memory for it.
function sniffMedia($path) {
    $h = @file_get_contents($path, false, null, 0, 512);
    if ($h === false || strlen($h) < 12) return null;

    if (str_starts_with($h, "\x1A\x45\xDF\xA3")) return ['family' => 'av', 'ext' => 'bin'];            // Matroska / WebM
    if (substr($h, 4, 4) === 'ftyp') {                                                                  // MP4 / MOV / M4A / 3GP / HEIC
        $brand = substr($h, 8, 4);
        if (in_array($brand, ['heic', 'heix', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1', 'avif', 'avis'], true)) {
            return ['family' => 'image', 'ext' => 'img'];
        }
        return ['family' => 'av', 'ext' => 'bin'];
    }
    if (str_starts_with($h, 'RIFF')) {
        if (substr($h, 8, 4) === 'WAVE') return ['family' => 'audio', 'ext' => 'bin'];
        // 'WEBP' falls through to the image check below; 'AVI ' is refused.
    }
    if (str_starts_with($h, 'OggS')) return ['family' => 'av', 'ext' => 'bin'];
    if (str_starts_with($h, 'fLaC')) return ['family' => 'audio', 'ext' => 'bin'];
    if (str_starts_with($h, 'ID3') || (ord($h[0]) === 0xFF && (ord($h[1]) & 0xE0) === 0xE0)) return ['family' => 'audio', 'ext' => 'bin']; // MP3 / AAC (ADTS)
    if (str_starts_with($h, 'FORM') && in_array(substr($h, 8, 4), ['AIFF', 'AIFC'], true)) return ['family' => 'audio', 'ext' => 'bin'];
    if (str_starts_with($h, 'caff')) return ['family' => 'audio', 'ext' => 'bin'];
    if (str_starts_with($h, '#!AMR')) return ['family' => 'audio', 'ext' => 'bin'];

    $img = @getimagesize($path);
    $gdExt = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if ($img && isset($gdExt[$img[2]])) return ['family' => 'image', 'ext' => $gdExt[$img[2]], 'width' => $img[0], 'height' => $img[1]];
    // BMP: a real image, but not one GD reads, so ffmpeg decodes it. (HEIC/
    // AVIF were caught by their ftyp brand above.) TIFF, ICO and the rest are refused.
    if ($img && $img[2] === IMAGETYPE_BMP && str_starts_with($h, 'BM')) {
        return ['family' => 'image', 'ext' => 'img'];
    }
    return null;
}

// Everything the pipeline needs to know about a file, from one ffprobe run:
// container and duration, and per stream its kind, codec, size, frame rate,
// colour transfer/primaries (HDR detection) and whether it's a cover
// picture. The result is passed along (classifyMedia -> the upload handler
// -> processVideo) instead of probing the same file again. Returns null when
// ffprobe can't read it (or exec is unavailable).
function probeMedia($path) {
    if (!function_exists('exec')) return null;
    $cmd = resourcePrefix(20) . escapeshellarg(FFPROBE) . ' -v error -protocol_whitelist file'
        . ' -show_entries format=format_name,duration:stream=codec_type,codec_name,width,height,r_frame_rate,color_transfer,color_primaries:stream_disposition=attached_pic'
        . ' -of json ' . escapeshellarg('file:' . $path) . ' 2>/dev/null';
    $out = [];
    exec($cmd, $out);
    $json = json_decode(implode("\n", $out), true);
    if (!is_array($json) || !isset($json['format']['format_name'])) return null;
    return $json;
}

// Containers we're willing to hand to ffmpeg (ffprobe's format_name). A
// belt-and-braces second check after the signature sniff above.
const PROBE_ALLOWED_FORMATS = [
    'mov,mp4,m4a,3gp,3g2,mj2', 'matroska,webm', 'ogg', 'wav', 'mp3', 'flac', 'aac', 'aiff', 'caf', 'amr',
];

// Codecs (ffprobe's codec_name) we accept inside those containers: a trusted
// container can still carry any codec, and each decoder is its own attack
// surface. Cover pictures (mjpeg/png) are allowed only as attached_pic
// streams, which are never decoded (the conversions drop them).
const ALLOWED_VIDEO_CODECS = ['h264', 'hevc', 'vp8', 'vp9', 'av1', 'mpeg4', 'h263'];
const ALLOWED_AUDIO_CODECS = ['aac', 'mp3', 'opus', 'vorbis', 'flac', 'alac', 'amr_nb', 'amr_wb']; // plus pcm_*
const ALLOWED_IMAGE_CODECS = ['hevc', 'av1', 'bmp'];     // images ffmpeg decodes (HEIC, AVIF, BMP)
const ALLOWED_COVER_CODECS = ['mjpeg', 'png'];

// The same allow-list as ffmpeg *decoder* names, for -codec_whitelist (which
// matches decoders, not codecs: MP3 decodes with mp3float, AV1 with libdav1d,
// AMR with amrnb/amrwb, and pcm_* can't be a wildcard there).
const FFMPEG_DECODER_WHITELIST = 'h264,hevc,vp8,vp9,av1,libdav1d,libaom-av1,mpeg4,h263,'
    . 'aac,aac_fixed,mp3float,mp3,opus,libopus,vorbis,libvorbis,flac,alac,amrnb,amrwb,'
    . 'libopencore_amrnb,libopencore_amrwb,bmp,'
    . 'pcm_s8,pcm_u8,pcm_s16le,pcm_s16be,pcm_u16le,pcm_u16be,pcm_s24le,pcm_s24be,pcm_s32le,pcm_s32be,'
    . 'pcm_f32le,pcm_f32be,pcm_f64le,pcm_f64be,pcm_alaw,pcm_mulaw';

// True when every audio/video stream in the probe uses an allowed codec.
// Other stream kinds (data, subtitles, timecode) are ignored: the
// conversions only ever map audio and video.
function probeCodecsAllowed($probe, $imageOnly = false) {
    foreach ($probe['streams'] ?? [] as $stream) {
        $type = $stream['codec_type'] ?? '';
        $codec = $stream['codec_name'] ?? '';
        if ($type === 'video') {
            if (!empty($stream['disposition']['attached_pic'])) $ok = in_array($codec, ALLOWED_COVER_CODECS, true);
            else $ok = in_array($codec, $imageOnly ? ALLOWED_IMAGE_CODECS : ALLOWED_VIDEO_CODECS, true);
        } elseif ($type === 'audio') {
            $ok = !$imageOnly && (in_array($codec, ALLOWED_AUDIO_CODECS, true) || str_starts_with($codec, 'pcm_'));
        } else {
            continue;
        }
        if (!$ok) {
            logMsg("classifyMedia: refused $type codec '$codec'");
            return false;
        }
    }
    return true;
}

// Refusal message when a width x height frame is over the pixel limits, or
// null when it's fine. Checked from headers before anything is decoded: a
// small file can claim a huge size (a 777 KB PNG of 16000x16000 made GD use
// 1.7 GB, outside PHP's memory_limit), and decoding is what costs the memory.
function oversizedFrame($width, $height, $what) {
    global $CONFIG;
    $width = (int)$width;
    $height = (int)$height;
    if ($width * $height <= $CONFIG['media_max_pixels'] && max($width, $height) <= $CONFIG['media_max_dimension']) return null;
    logMsg("classifyMedia: refused {$width}x{$height} $what (over the pixel limits)");
    $mp = round($CONFIG['media_max_pixels'] / 1_000_000);
    return "That $what is too large (max about $mp megapixels).";
}

// The first refusal for any video stream in a probe that's over the pixel
// limits, or null.
function oversizedProbe($probe, $what) {
    foreach ($probe['streams'] ?? [] as $stream) {
        if (($stream['codec_type'] ?? '') !== 'video') continue;
        $why = oversizedFrame($stream['width'] ?? 0, $stream['height'] ?? 0, $what);
        if ($why) return $why;
    }
    return null;
}

// Works out what an uploaded file really is, from its bytes (never from the
// client's say-so). Returns ['type' => image|video|audio, 'ext' => ...,
// 'probe' => ffprobe's result or null], ['reject' => message] for media we
// recognize but refuse (too many pixels), or null when it isn't recognizable
// media we're willing to process.
function classifyMedia($path) {
    $sniffed = sniffMedia($path);
    if (!$sniffed) return null;
    if ($sniffed['family'] === 'image') {
        if (isset($sniffed['width'])) {
            $why = oversizedFrame($sniffed['width'], $sniffed['height'], 'image');
            return $why ? ['reject' => $why] : ['type' => 'image', 'ext' => $sniffed['ext'], 'probe' => null];
        }
        // ffmpeg decodes this one (HEIC, AVIF, ...), so ask ffprobe for its size first.
        $probe = probeMedia($path);
        if (!$probe || !probeCodecsAllowed($probe, true)) return null;
        $why = oversizedProbe($probe, 'image');
        return $why ? ['reject' => $why] : ['type' => 'image', 'ext' => $sniffed['ext'], 'probe' => $probe];
    }

    $probe = probeMedia($path);
    if (!$probe || !in_array($probe['format']['format_name'], PROBE_ALLOWED_FORMATS, true)) return null;
    if (!probeCodecsAllowed($probe)) return null;
    $why = oversizedProbe($probe, 'video');
    if ($why) return ['reject' => $why];

    $hasVideo = false;
    $hasAudio = false;
    foreach ($probe['streams'] ?? [] as $stream) {
        if (($stream['codec_type'] ?? '') === 'video' && empty($stream['disposition']['attached_pic'])) $hasVideo = true;
        if (($stream['codec_type'] ?? '') === 'audio') $hasAudio = true;
    }
    if ($hasVideo) return ['type' => 'video', 'ext' => 'bin', 'probe' => $probe];
    if ($hasAudio) return ['type' => 'audio', 'ext' => 'bin', 'probe' => $probe];
    return null;
}

const FFMPEG = '/usr/bin/ffmpeg';
const FFPROBE = '/usr/bin/ffprobe';

// Duration in seconds from a probe, or 0 when it isn't known (browser WebM
// recordings have none in their header; the -t cap still applies).
function probeDuration($probe) {
    return (float)($probe['format']['duration'] ?? 0);
}

// The main video stream of a probe (not a cover picture), or null.
function probeVideoStream($probe) {
    foreach ($probe['streams'] ?? [] as $s) {
        if (($s['codec_type'] ?? '') === 'video' && empty($s['disposition']['attached_pic'])) return $s;
    }
    return null;
}

// Frame rate as a number -- ffprobe reports it as a fraction like
// "60000/1001". 0 when it isn't known.
function probeFrameRate($probe) {
    $raw = (string)(probeVideoStream($probe)['r_frame_rate'] ?? '');
    if ($raw === '') return 0;
    if (strpos($raw, '/') === false) return (float)$raw;
    [$num, $den] = explode('/', $raw, 2);
    return (float)$den > 0 ? (float)$num / (float)$den : 0;
}

// Whether ffmpeg and ffprobe actually run here (exec allowed, both answer
// -version within 5 s), and whether ffmpeg has the zscale filter (HDR tone
// mapping, D5). Checked at most once an hour; the answer is cached in
// private/tmp/media-capabilities.json. Without ffmpeg nothing but plain
// JPEG/PNG/GIF/WebP images can be converted.
function mediaCapabilities() {
    global $CONFIG;
    static $caps = null;
    if ($caps !== null) return $caps;
    $cacheFile = dirname($CONFIG['db_path']) . '/tmp/media-capabilities.json';
    $cached = @json_decode((string)@file_get_contents($cacheFile), true);
    if (is_array($cached) && ($cached['checkedAt'] ?? 0) > time() - 3600 && ($cached['ffmpeg'] ?? '') === FFMPEG) {
        return $caps = $cached;
    }

    $runs = function ($bin, $args) {
        if (!function_exists('exec') || !is_executable($bin)) return [false, []];
        $out = [];
        $code = 1;
        $timeout = firstExecutable(['/usr/bin/timeout', '/bin/timeout']);
        exec(($timeout ? escapeshellarg($timeout) . ' 5 ' : '') . escapeshellarg($bin) . ' ' . $args . ' 2>/dev/null', $out, $code);
        return [$code === 0, $out];
    };
    [$ffmpegOk] = $runs(FFMPEG, '-hide_banner -version');
    [$ffprobeOk] = $runs(FFPROBE, '-hide_banner -version');
    [, $filters] = $ffmpegOk ? $runs(FFMPEG, '-hide_banner -filters') : [false, []];
    $caps = [
        'ffmpeg' => FFMPEG,
        'checkedAt' => time(),
        'av' => $ffmpegOk && $ffprobeOk,
        'zscale' => (bool)preg_grep('/\szscale\s/', $filters),
    ];
    if (!$caps['av']) writeLog('ERROR', 'media', 'ffmpeg/ffprobe unavailable: video and audio uploads are off');
    $dir = dirname($cacheFile);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    @file_put_contents($cacheFile, json_encode($caps));
    return $caps;
}

function firstExecutable(array $paths) {
    foreach ($paths as $bin) {
        if (is_executable($bin)) return $bin;
    }
    return null;
}

// Command prefix for every ffmpeg/ffprobe run, so one upload can't take over
// the server: lower CPU priority (nice 10), a memory ceiling (prlimit --as,
// media_ffmpeg_max_mem), and a time limit (timeout). Each tool is used only
// if it exists. MALLOC_ARENA_MAX=2 stops glibc reserving an address-space
// arena per thread, which is most of ffmpeg's virtual size.
function resourcePrefix($seconds) {
    global $CONFIG;
    $prefix = 'MALLOC_ARENA_MAX=2 ';
    if ($nice = firstExecutable(['/usr/bin/nice', '/bin/nice'])) $prefix .= escapeshellarg($nice) . ' -n 10 ';
    if ($prlimit = firstExecutable(['/usr/bin/prlimit', '/bin/prlimit'])) {
        $prefix .= escapeshellarg($prlimit) . ' --as=' . (int)($CONFIG['media_ffmpeg_max_mem'] ?? 4294967296) . ' -- ';
    }
    if ($timeout = firstExecutable(['/usr/bin/timeout', '/bin/timeout'])) $prefix .= escapeshellarg($timeout) . ' ' . (int)$seconds . ' ';
    return $prefix;
}

// Takes one of media_max_concurrent conversion slots (lock files under
// private/locks), waiting up to $waitSeconds. Returns the lock handle, held
// until the request ends (or fclose), or null if every slot stayed busy.
function acquireMediaSlot(int $waitSeconds = 20) {
    global $CONFIG;
    $dir = dirname($CONFIG['db_path']) . '/locks';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $deadline = time() + $waitSeconds;
    do {
        for ($i = 0; $i < ($CONFIG['media_max_concurrent'] ?? 2); $i++) {
            $fh = @fopen("$dir/media-$i.lock", 'c');
            if ($fh && flock($fh, LOCK_EX | LOCK_NB)) return $fh;
            if ($fh) fclose($fh);
        }
        usleep(250000);
    } while (time() < $deadline);
    return null;
}

// Runs ffmpeg on one input file with the given output arguments (each
// shell-escaped). Returns true on success. The safe input options are always
// added here rather than left to each caller: only the plain file protocol
// is allowed, so a crafted file can't make ffmpeg fetch a URL or read some
// other path, and -max_pixels makes the decoder itself refuse oversized
// frames (a second line behind classifyMedia's header check), and
// -codec_whitelist makes it refuse any decoder outside the allow-list.
// $inputArgs go before -i (e.g. a -ss seek).
function runFfmpeg(string $inputPath, array $outputArgs, array $inputArgs = []): bool {
    global $CONFIG;
    if (!function_exists('exec')) {
        logMsg("ffmpeg skipped: exec() is disabled");
        return false;
    }
    @set_time_limit(600);
    $args = array_merge($inputArgs, [
        '-max_pixels', (string)$CONFIG['media_max_pixels'],
        '-codec_whitelist', FFMPEG_DECODER_WHITELIST,
        '-threads', '2',   // decoder threads; leaves cores for the website
        '-i', 'file:' . $inputPath,
    ], $outputArgs);
    $cmd = resourcePrefix(300) . escapeshellarg(FFMPEG) . ' -hide_banner -loglevel error -nostdin -y -protocol_whitelist file '
        . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    $output = [];
    $code = 1;
    $start = microtime(true);
    exec($cmd, $output, $code);
    $seconds = round(microtime(true) - $start, 1);
    logMsg("ffmpeg exit=$code time={$seconds}s " . substr(implode(' ', $output), 0, 1000));
    return $code === 0;
}

// Output options that drop the source's global, stream and chapter metadata:
// phone videos carry GPS (location), device and comment tags, and ffmpeg
// copies them into the output by default. Rotation isn't lost: ffmpeg
// applies the display-matrix rotation to the pixels while decoding
// (autorotate), so frames are already upright.
const FFMPEG_STRIP_METADATA = ['-map_metadata', '-1', '-map_chapters', '-1'];

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

// The feed shows images at most this wide/tall (C3); the full 1920 px
// version is for the lightbox.
const MEDIA_VARIANT_SIDE = 960;

// A copy of $src scaled so its longest side is $maxSide. Keeps transparency
// when $alpha is set.
function scaledCopy($src, $maxSide, $alpha) {
    $w = imagesx($src);
    $h = imagesy($src);
    $ratio = $maxSide / max($w, $h);
    $newWidth = max(1, (int)($w * $ratio));
    $newHeight = max(1, (int)($h * $ratio));
    $dst = imagecreatetruecolor($newWidth, $newHeight);
    if ($alpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $w, $h);
    return $dst;
}

// Converts to WebP at most media_max_side on the longest side. With
// $variantPath, also writes a MEDIA_VARIANT_SIDE px WebP there for the feed,
// but only when the image is bigger than that (resampled from the same
// decoded image, not decoded again). Returns true on success.
function processImage($inputPath, $outputPath, $variantPath = null) {
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

    // GIFs (and some PNGs) are palette images, which imagewebp() refuses; make
    // them true-colour first. Without this every GIF upload failed.
    if (!imageistruecolor($src)) {
        imagepalettetotruecolor($src);
        imagealphablending($src, false);
        imagesavealpha($src, true);
    }

    $srcWidth = imagesx($src);
    $srcHeight = imagesy($src);
    logMsg("processImage: original size = {$srcWidth}x{$srcHeight}");

    // Resize so the longest side is at most media_max_side (portrait or landscape)
    $maxSide = $CONFIG['media_max_side'];

    // Keep transparency for every type (WebP and GIF can be transparent too;
    // they used to come out solid black when resized). JPEG has no alpha, so
    // this costs it nothing.
    $alpha = true;

    if (max($srcWidth, $srcHeight) > $maxSide) {
        $dst = scaledCopy($src, $maxSide, $alpha);
        logMsg('processImage: resized to ' . imagesx($dst) . 'x' . imagesy($dst));
    } else {
        $dst = $src;
    }

    // Save as WebP with 85% quality
    $result = imagewebp($dst, $outputPath, 85);

    if ($result && $variantPath !== null && max($srcWidth, $srcHeight) > MEDIA_VARIANT_SIDE) {
        $small = scaledCopy($src, MEDIA_VARIANT_SIDE, $alpha);
        if (!imagewebp($small, $variantPath, 80)) logMsg('processImage: could not write the feed variant');
        imagedestroy($small);
    }

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
// first frame as the thumbnail. No more than media_max_seconds is kept, even
// if the file's own header understated its length. Returns 'mp4', or false
// when ffmpeg can't convert it (the caller rejects the upload; the original
// is never kept, because once any format is accepted an unconverted original
// would be a file nobody can play).
function processVideo($inputPath, $outputBase, $thumbnailPath, $probe = null) {
    global $CONFIG;
    logMsg("processVideo: input=$inputPath output=$outputBase");

    // Only resample when the source is actually above the cap -- forcing the
    // rate unconditionally would duplicate frames on a 30fps clip and inflate
    // it for nothing.
    $maxFps = $CONFIG['media_max_fps'];
    $filters = ffmpegScale($CONFIG['media_max_side']);
    $probe ??= probeMedia($inputPath);
    $sourceFps = probeFrameRate($probe);
    if ($sourceFps > $maxFps) {
        $filters .= ',fps=' . $maxFps;
        logMsg("processVideo: source is {$sourceFps}fps, capping at " . $maxFps);
    }

    $converted = runFfmpeg($inputPath, [
        '-t', (string)$CONFIG['media_max_seconds'],
        '-map', '0:v:0', '-map', '0:a:0?', '-vf', $filters,
        '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p', '-threads', '2',
        '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', ...FFMPEG_STRIP_METADATA, "$outputBase.mp4",
    ]);
    if (!$converted) {
        @unlink("$outputBase.mp4");
        return false;
    }

    $duration = min(probeDuration($probe), (float)$CONFIG['media_max_seconds']);
    createVideoThumbnail("$outputBase.mp4", $thumbnailPath, $duration);
    logMsg("processVideo SUCCESS: saved $outputBase.mp4");
    return 'mp4';
}

// ffmpeg grabs the first frame as PNG; GD converts it to WebP.
// The poster: a frame from 0.5 s in (or halfway through a shorter clip),
// since the very first frame is often black. Seeking before -i is fast and
// lands on the nearest frame. Unknown duration: the first frame.
function createVideoThumbnail($videoPath, $thumbnailPath, $duration = 0.0) {
    $png = "$thumbnailPath.png";
    $seek = $duration > 0 ? min(0.5, $duration / 2) : 0;
    $inputArgs = $seek > 0 ? ['-ss', sprintf('%.2f', $seek)] : [];
    if (!runFfmpeg($videoPath, ['-frames:v', '1', '-vf', ffmpegScale(640), ...FFMPEG_STRIP_METADATA, $png], $inputArgs)) return false;
    $img = @imagecreatefrompng($png);
    @unlink($png);
    if (!$img) return false;
    $ok = imagewebp($img, $thumbnailPath, 80);
    imagedestroy($img);
    return $ok;
}

// Converts to MP3, capped at media_max_seconds. Always re-encoded, MP3s
// included: copying one through kept its tags (artist, comments), any
// embedded cover picture and its full length when the header understated
// it. Returns 'mp3', or false when ffmpeg can't convert it.
function processAudio($inputPath, $outputBase) {
    global $CONFIG;
    logMsg("processAudio: input=$inputPath output=$outputBase");

    if (runFfmpeg($inputPath, ['-t', (string)$CONFIG['media_max_seconds'], '-vn', '-c:a', 'libmp3lame', '-q:a', '2', ...FFMPEG_STRIP_METADATA, "$outputBase.mp3"])) return 'mp3';
    @unlink("$outputBase.mp3");
    return false;
}

// An image GD can't read (HEIC, AVIF, BMP): ffmpeg decodes the
// first frame to a PNG, which processImage then handles like any other.
// Returns the PNG's path, or false.
function convertImageToPng($inputPath, $pngPath) {
    return runFfmpeg($inputPath, ['-frames:v', '1', ...FFMPEG_STRIP_METADATA, $pngPath]) ? $pngPath : false;
}

// Width/height/duration of a finished output file, for the media row.
// Unknown values come back null rather than failing the upload.
function mediaOutputInfo($path, $type) {
    if ($type === 'image') {
        $size = @getimagesize($path);
        return ['width' => $size[0] ?? null, 'height' => $size[1] ?? null, 'duration' => null];
    }
    $probe = probeMedia($path);
    $info = ['width' => null, 'height' => null, 'duration' => isset($probe['format']['duration']) ? round((float)$probe['format']['duration'], 2) : null];
    foreach ($probe['streams'] ?? [] as $s) {
        if (($s['codec_type'] ?? '') === 'video') {
            $info['width'] = $s['width'] ?? null;
            $info['height'] = $s['height'] ?? null;
            break;
        }
    }
    return $info;
}

// Registers a file in the staging folder for removal when the request ends,
// however it ends: success (it was moved away, so nothing to do), bad()
// (which exits) or a fatal error. Returns the path, for inline use.
function stageFile($path) {
    static $files = null;
    if ($files === null) {
        $files = [];
        register_shutdown_function(function () use (&$files) {
            foreach ($files as $f) if (file_exists($f)) @unlink($f);
        });
    }
    $files[] = $path;
    return $path;
}

// rename(), or copy + unlink when the staging folder is on another
// filesystem (rename can't cross filesystems).
function moveIntoPlace($from, $to) {
    if (@rename($from, $to)) return true;
    if (@copy($from, $to)) {
        @unlink($from);
        return true;
    }
    return false;
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

    $pdo = db();
    throttleSend($pdo, "upload:$uid", $CONFIG['media_uploads_per_hour'] ?? 60, 3600, 'Too many uploads. Try again later.');

    // The user's expired drafts first, so they don't hold quota; now and then
    // everyone's, so dormant accounts get cleaned too.
    sweepOrphanMedia($pdo, $uid);
    if (random_int(1, 100) === 1) sweepOrphanMedia($pdo, null, 50);

    // Already full: refuse before spending any CPU on it.
    $used = mediaBytesUsed($pdo, $uid);
    if ($used >= $CONFIG['media_max_user_bytes']) rejectOverQuota();

    $file = $_FILES['file'];
    $tmpPath = $file['tmp_name'];
    $fileSize = $file['size'];
    $fileName = $file['name'];

    logMsg("uploadMedia: file=$fileName claimedMime={$file['type']} size=$fileSize");

    // Cheap size check first, against the biggest limit of any type, so an
    // enormous upload is turned away before anything reads it.
    $maxSizes = mediaMaxSizes();
    if ($fileSize > max($maxSizes)) {
        $maxMb = round(max($maxSizes) / (1024 * 1024), 1);
        bad("That file is larger than the biggest allowed ($maxMb MB).", 400);
    }

    // Video and audio need ffmpeg. Without it, say so plainly rather than
    // calling a perfectly good video an unsupported file type.
    $sniffed = sniffMedia($tmpPath);
    if ($sniffed && $sniffed['family'] !== 'image' && !mediaCapabilities()['av']) {
        writeLog('ERROR', 'media', 'refused a video/audio upload: ffmpeg/ffprobe unavailable');
        bad('Video and audio uploads are temporarily unavailable.', 503);
    }

    // What the file really is, from its own bytes. The Content-Type and the
    // filename the client sent are ignored, so any image, video or audio
    // format works and a renamed non-media file doesn't.
    $classified = classifyMedia($tmpPath);
    if (!$classified) {
        bad("That file type isn't supported. Try a photo (JPEG, PNG, HEIC, WebP, GIF), a video (MP4, MOV, WebM) or audio (MP3, M4A, WAV, Ogg).", 400);
    }
    if (isset($classified['reject'])) bad($classified['reject'], 400);
    $mediaType = $classified['type'];

    if ($fileSize > $maxSizes[$mediaType]) {
        $maxMb = round($maxSizes[$mediaType] / (1024 * 1024), 1);
        $gotMb = round($fileSize / (1024 * 1024), 1);
        bad("That $mediaType is {$gotMb}MB - the max is {$maxMb}MB.", 400);
    }

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
    $tempInput = "{$stageDir}/{$base}.{$classified['ext']}";

    stageFile($tempInput);
    if (!move_uploaded_file($tmpPath, $tempInput)) {
        logMsg("uploadMedia ERROR: move_uploaded_file failed. tmpPath=$tmpPath, tempInput=$tempInput");
        bad('Could not save the upload on the server. Please try again.', 500);
    }

    logMsg("uploadMedia: tempInput=$tempInput exists=" . (file_exists($tempInput) ? "yes" : "no"));

    // Checked here rather than after conversion so an over-long file is
    // rejected before spending minutes transcoding it. A duration of 0 means
    // ffprobe couldn't tell us, so it's let through.
    if ($mediaType === 'video' || $mediaType === 'audio') {
        $duration = probeDuration($classified['probe']);
        $maxSeconds = $CONFIG['media_max_seconds'];
        if ($duration > $maxSeconds) {
            @unlink($tempInput);
            bad("That $mediaType is " . round($duration, 1) . " seconds long. Max: $maxSeconds seconds", 400);
        }
    }

    // Only so many conversions at once, so a burst of uploads can't starve
    // the website of CPU. Held until this request ends.
    $slot = acquireMediaSlot();
    if (!$slot) {
        @unlink($tempInput);
        bad('The server is busy processing other uploads. Please try again in a moment.', 503);
    }

    // Every output is written to the staging folder first and only moved
    // into the public media/ folder after the database row exists, so a
    // half-written or failed conversion is never web-reachable.
    $stagedThumb = "{$stageDir}/thumb_{$base}.webp";
    $stagedVariant = "{$stageDir}/{$base}_960.webp";

    if ($mediaType === 'image') {
        $imageSource = $tempInput;
        if ($classified['ext'] === 'img') {
            // A format GD can't read: ffmpeg decodes it to a PNG first.
            $imageSource = convertImageToPng($tempInput, stageFile("{$stageDir}/{$base}.png"));
        }
        $stagedVariant = stageFile("{$stageDir}/{$base}_960.webp");
        $ext = ($imageSource && processImage($imageSource, stageFile("{$stageDir}/{$base}.webp"), $stagedVariant)) ? 'webp' : false;
        if ($imageSource && $imageSource !== $tempInput) @unlink($imageSource);
    } elseif ($mediaType === 'video') {
        stageFile("{$stageDir}/{$base}.mp4");
        stageFile($stagedThumb);
        stageFile("$stagedThumb.png");
        $ext = processVideo($tempInput, "{$stageDir}/{$base}", $stagedThumb, $classified['probe']);
    } else {
        stageFile("{$stageDir}/{$base}.mp3");
        $ext = processAudio($tempInput, "{$stageDir}/{$base}");
    }

    @unlink($tempInput);
    if (!$ext) bad("Couldn't convert that $mediaType - it may be corrupted or in a format we can't read.", 400);

    $filename = "{$base}.{$ext}";
    $stagedMain = "{$stageDir}/{$filename}";
    // staged path => final path, main file first.
    $moves = [$stagedMain => "{$typeDir}/{$filename}"];
    if ($mediaType === 'video' && file_exists($stagedThumb)) $moves[$stagedThumb] = "{$typeDir}/thumb_{$base}.webp";
    $variantPath = null;
    if ($mediaType === 'image' && file_exists($stagedVariant)) {
        $moves[$stagedVariant] = "{$typeDir}/{$base}_960.webp";
        $variantPath = "/media/{$uid}/image/{$base}_960.webp";
    }

    $info = mediaOutputInfo($stagedMain, $mediaType);
    // Everything this upload stores counts toward the user's quota (B3),
    // the video thumbnail included.
    $bytes = array_sum(array_map(fn($f) => (int)@filesize($f), array_keys($moves)));
    // This one would take the user over: the staged files are removed on exit.
    if ($used + $bytes > $CONFIG['media_max_user_bytes']) rejectOverQuota();

    $path = "/media/{$uid}/{$mediaType}/{$filename}";
    $pdo->beginTransaction();
    try {
        $posterPath = isset($moves[$stagedThumb]) ? "/media/{$uid}/video/thumb_{$base}.webp" : null;
        $stmt = $pdo->prepare('INSERT INTO media (user_id, filename, type, path, created_at, width, height, duration, bytes, poster_path, variant_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$uid, $filename, $mediaType, $path, date('Y-m-d H:i:s'), $info['width'], $info['height'], $info['duration'], $bytes, $posterPath, $variantPath]);
        $mediaId = $pdo->lastInsertId();
        $moved = [];
        foreach ($moves as $from => $to) {
            if (!moveIntoPlace($from, $to)) {
                foreach ($moved as $done) @unlink($done);
                throw new RuntimeException("could not move $from to $to");
            }
            $moved[] = $to;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        logMsg('uploadMedia ERROR: ' . $e->getMessage());
        bad('Could not save the upload on the server. Please try again.', 500);
    }

    $thumbUrl = isset($moves[$stagedThumb]) ? "/media/{$uid}/video/thumb_{$base}.webp" : null;

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
    // Deleting it would leave a post pointing at a missing file. Clients only
    // delete unattached draft uploads; a post's media goes with the post.
    if ($media['post_id'] !== null) bad('That file is attached to a post. Delete the post instead.', 409);

    foreach (mediaRowFiles($media) as $f) if (file_exists($f)) unlink($f);

    $stmt = $pdo->prepare('DELETE FROM media WHERE id = ?');
    $stmt->execute([$mediaId]);

    logMsg("deleteMedia SUCCESS: mediaId=$mediaId");

    respond(good(['deleted' => true]));
}
