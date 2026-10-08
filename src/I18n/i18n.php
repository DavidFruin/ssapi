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
