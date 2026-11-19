<?php
// English is the source language: the English sentence is the key, and
// es.php maps it to Spanish. Anything not in es.php comes back unchanged.
// (language plan L4-L5)
const SUPPORTED_LANGS = ['en', 'es'];

// The language the app asked for with X-SS-Lang, or null when it didn't
// (the CLI/TUI and old app builds), which means English.
function explicitRequestLang(): ?string {
    $lang = strtolower(trim($_SERVER['HTTP_X_SS_LANG'] ?? ''));
    return in_array($lang, SUPPORTED_LANGS, true) ? $lang : null;
}

function requestLang(): string {
    return explicitRequestLang() ?? 'en';
}

// Translates an English sentence, then fills {placeholders} from $params.
// $lang defaults to the request's language; pass one explicitly for text
// that goes to someone else (a push to another user).
function tr(string $text, array $params = [], ?string $lang = null): string {
    static $es = null;
    if (($lang ?? requestLang()) === 'es') {
        $es ??= require __DIR__ . '/es.php';
        $text = $es[$text] ?? $text;
    }
    foreach ($params as $name => $value) $text = str_replace('{' . $name . '}', (string)$value, $text);
    return $text;
}

// Mail needs the character set declared once a message has accents (Spanish),
// and a non-ASCII Subject must be encoded. English-only mail is left exactly
// as it was.
function mailSubject(string $subject): string {
    return preg_match('/[^\x20-\x7E]/', $subject) ? '=?UTF-8?B?' . base64_encode($subject) . '?=' : $subject;
}

// Another site running this code for its accounts (aprender.davidfruin.com)
// sets SITE_NAME and MAIL_FROM in its private/.env; unset, nothing changes.
function siteName(): string {
    return getenv('SITE_NAME') ?: 'Simple Social';
}

// A translated sentence that names the site, with this site's own name.
function trSite(string $text, array $params = []): string {
    return str_replace('Simple Social', siteName(), tr($text, $params));
}

function mailHeaders(string $body): string {
    $from = getenv('MAIL_FROM') ?: 'no-reply@app.davidfruin.com';
    $headers = "From: $from\r\nReply-To: $from\r\n";
    if (preg_match('/[^\x20-\x7E\r\n]/', $body)) {
        $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    }
    return $headers;
}
