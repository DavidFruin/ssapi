<?php
// webpush.php - Hand-rolled Web Push: RFC 8291 message encryption (aes128gcm)
// and RFC 8292 VAPID request signing. No external push service or Composer
// dependency, matching the hand-rolled JWT auth already in api.php.
//
// The two DER encoders below (webpushPublicKeyPem/webpushPrivateKeyPem) wrap
// a raw P-256 key in just enough ASN.1 for OpenSSL to load it -- there's no
// PHP API to build an EC key resource from raw bytes directly.

function webpush_b64url_encode($bin) {
    return rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode($bin)), '=');
}

function webpush_b64url_decode($str) {
    $pad = strlen($str) % 4;
    if ($pad) $str .= str_repeat('=', 4 - $pad);
    return base64_decode(str_replace(['-', '_'], ['+', '/'], $str));
}

// SubjectPublicKeyInfo prefix for a P-256 (prime256v1) EC public key, through
// the BIT STRING's "00 unused bits" byte. Append the raw uncompressed point
// (0x04||X||Y, 65 bytes) after this.
const WEBPUSH_EC_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

function webpushPem($der, $label) {
    return "-----BEGIN $label-----\n" . trim(chunk_split(base64_encode($der), 64, "\n")) . "\n-----END $label-----\n";
}

function webpushPublicKeyPem($rawPoint) {
    return webpushPem(hex2bin(WEBPUSH_EC_SPKI_PREFIX) . $rawPoint, 'PUBLIC KEY');
}

// SEC1 ECPrivateKey: version, the raw 32-byte scalar, the curve OID, and the
// public point (included so OpenSSL doesn't need to recompute it).
function webpushPrivateKeyPem($d, $rawPoint) {
    $inner = "\x02\x01\x01" . "\x04\x20" . $d
        . "\xa0\x0a\x06\x08" . hex2bin('2a8648ce3d030107')
        . "\xa1\x44\x03\x42\x00" . $rawPoint;
    return webpushPem("\x30" . chr(strlen($inner)) . $inner, 'EC PRIVATE KEY');
}

function webpushHkdf($salt, $ikm, $info, $len) {
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $len);
}

// ECDSA signatures from openssl_sign() come out as DER (SEQUENCE of two
// INTEGERs); a JWS wants them as raw r||s, 32 bytes each.
function webpushDerSigToRaw($der) {
    $offset = 2; // skip the outer SEQUENCE tag+length
    $readInt = function($der, &$offset) {
        $len = ord($der[$offset + 1]);
        $val = substr($der, $offset + 2, $len);
        $offset += 2 + $len;
        return ltrim($val, "\x00");
    };
    $r = $readInt($der, $offset);
    $s = $readInt($der, $offset);
    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

// The VAPID auth header: proves to the push service that whoever is sending
// this also controls the public key the browser subscribed with.
function webpushVapidJwt($audience, $vapidPublicRaw, $vapidPrivateRaw, $subjectEmail) {
    $header = webpush_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = webpush_b64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => "mailto:$subjectEmail",
    ]));
    $signingInput = "$header.$claims";

    $privPem = webpushPrivateKeyPem($vapidPrivateRaw, $vapidPublicRaw);
    $derSig = '';
    openssl_sign($signingInput, $derSig, $privPem, OPENSSL_ALGO_SHA256);

    return "$signingInput." . webpush_b64url_encode(webpushDerSigToRaw($derSig));
}

// Encrypts $payload for one subscription per RFC 8291. Returns the raw body
// to POST to the push endpoint: a 16-byte salt, a 4-byte record size, the
// sender's one-time-use public key, then the ciphertext (with its GCM tag).
function webpushEncrypt($payload, $p256dhRaw, $authRaw) {
    $uaPubKey = openssl_pkey_get_public(webpushPublicKeyPem($p256dhRaw));
    if (!$uaPubKey) return false;

    $asRes = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $asDetails = openssl_pkey_get_details($asRes);
    $asPoint = "\x04"
        . str_pad($asDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad($asDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    $sharedSecret = openssl_pkey_derive($uaPubKey, $asRes, 256);
    $sharedSecret = str_pad($sharedSecret, 32, "\x00", STR_PAD_LEFT);

    $keyInfo = "WebPush: info\x00" . $p256dhRaw . $asPoint;
    $ikm = webpushHkdf($authRaw, $sharedSecret, $keyInfo, 32);

    $salt = random_bytes(16);
    $cek = webpushHkdf($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = webpushHkdf($salt, $ikm, "Content-Encoding: nonce\x00", 12);

    // "\x02" marks this as the only (last) record -- Web Push messages are
    // always small enough to fit in one.
    $tag = '';
    $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    $ciphertext .= $tag;

    $header = $salt . pack('N', 4096) . chr(strlen($asPoint)) . $asPoint;
    return $header . $ciphertext;
}

// Sends one push message. $subscription is ['endpoint', 'p256dh', 'auth']
// (the last two base64url, as the browser hands them over). $payloadArray
// is JSON-encoded and encrypted; sw.js's push handler expects
// {title, body, url}. Returns the HTTP status so the caller can drop
// subscriptions the push service reports as gone (404/410).
function sendWebPush($subscription, $payloadArray, $vapidEmail) {
    global $CONFIG;
    $vapidPublicRaw = webpush_b64url_decode($CONFIG['vapid_public'] ?? '');
    $vapidPrivateRaw = webpush_b64url_decode($CONFIG['vapid_private'] ?? '');
    if (!$vapidPublicRaw || !$vapidPrivateRaw) return 0;

    $p256dh = webpush_b64url_decode($subscription['p256dh']);
    $auth = webpush_b64url_decode($subscription['auth']);
    $body = webpushEncrypt(json_encode($payloadArray), $p256dh, $auth);
    if ($body === false) return 0;

    $endpoint = $subscription['endpoint'];
    $parsed = parse_url($endpoint);
    $audience = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
    $jwt = webpushVapidJwt($audience, $vapidPublicRaw, $vapidPrivateRaw, $vapidEmail);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Authorization: vapid t=' . $jwt . ', k=' . webpush_b64url_encode($vapidPublicRaw),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $status;
}
