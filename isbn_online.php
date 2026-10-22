<?php
/**
 * Online ISBN lookup (Open Library first, Google Books as a fallback).
 *
 * Runs on the SERVER (PHP), because the browser is not allowed to call other
 * websites (CSP: connect-src 'self'). Only fixed https URLs are ever
 * requested - the only user-supplied part is a validated 13-digit ISBN - and
 * redirects are not followed, so this cannot be turned into a request to
 * some other address.
 *
 * Needs: internet on the server + PHP cURL (or allow_url_fopen).
 */

/** Fetch a URL and decode JSON. Returns [array|null, errorCode|null]. */
function isbn_http_json(string $url, int $timeout = 6): array {
    $ua = 'UniLibraryOS/1.0 (school library catalog)';
    $body = false;
    $code = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_MAXFILESIZE    => 1048576,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false) {
            // 60/77 = HTTPS certificate problems (common on a fresh WAMP/XAMPP: no CA bundle)
            return [null, in_array($errno, [51, 58, 60, 77, 83], true) ? 'ssl' : 'network'];
        }
    } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
            'follow_location' => 0, 'header' => "Accept: application/json\r\nUser-Agent: {$ua}\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx, 0, 1048576);
        if ($body === false) return [null, 'network'];
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
        }
    } else {
        return [null, 'unavailable'];
    }

    if ($code === 429) return [null, 'busy'];
    if ($code >= 400)  return [null, 'http'];
    $data = json_decode((string) $body, true);
    return is_array($data) ? [$data, null] : [null, 'http'];
}

/** Tidy a text value from an outside source. */
function isbn_clean_text($v, int $max): string {
    if (!is_string($v)) return '';
    $v = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $v);
    $v = trim(preg_replace('/\s+/', ' ', $v));
    if (!preg_match('//u', $v)) return '';
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}

function isbn_join_names(array $list, string $key = ''): string {
    $names = [];
    foreach ($list as $item) {
        $n = $key !== '' ? (is_array($item) ? ($item[$key] ?? '') : '') : $item;
        $n = isbn_clean_text($n, 120);
        if ($n !== '') $names[] = $n;
        if (count($names) >= 4) break;
    }
    return implode(', ', $names);
}

/**
 * Look up one canonical 13-digit ISBN.
 * Returns ['ok' => true,  'title' =>, 'author' =>, 'genre' =>, 'source' =>]
 *      or ['ok' => false, 'error' => 'notfound'|'network'|'ssl'|'busy'|'http'|'unavailable']
 */
function isbn_lookup_online(string $isbn): array {
    $found = ['title' => '', 'author' => '', 'genre' => ''];
    $sources = [];
    $lastError = null;
    $reachedAny = false;

    // 1) Open Library
    [$ol, $err] = isbn_http_json('https://openlibrary.org/api/books?bibkeys=ISBN:' . $isbn . '&format=json&jscmd=data');
    if ($err) {
        $lastError = $err;
    } else {
        $reachedAny = true;
        $rec = $ol['ISBN:' . $isbn] ?? null;
        if (is_array($rec)) {
            $found['title']  = isbn_clean_text($rec['title'] ?? '', 255);
            $found['author'] = isbn_join_names((array) ($rec['authors'] ?? []), 'name');
            $subjects = (array) ($rec['subjects'] ?? []);
            $found['genre']  = isbn_clean_text($subjects[0]['name'] ?? '', 100);
            if ($found['title'] !== '') $sources[] = 'Open Library';
        }
    }

    // 2) Google Books - only if something is still missing
    if ($found['title'] === '' || $found['author'] === '' || $found['genre'] === '') {
        [$gb, $err] = isbn_http_json('https://www.googleapis.com/books/v1/volumes?maxResults=1&q=isbn:' . $isbn);
        if ($err) {
            $lastError = $lastError ?? $err;
        } else {
            $reachedAny = true;
            $vi = $gb['items'][0]['volumeInfo'] ?? null;
            if (is_array($vi)) {
                $g = [
                    'title'  => isbn_clean_text($vi['title'] ?? '', 255),
                    'author' => isbn_join_names((array) ($vi['authors'] ?? [])),
                    'genre'  => isbn_clean_text(((array) ($vi['categories'] ?? []))[0] ?? '', 100),
                ];
                $used = false;
                foreach ($g as $k => $val) {
                    if ($found[$k] === '' && $val !== '') { $found[$k] = $val; $used = true; }
                }
                if ($used) $sources[] = 'Google Books';
            }
        }
    }

    if ($found['title'] === '' && $found['author'] === '' && $found['genre'] === '') {
        return ['ok' => false, 'error' => $reachedAny ? 'notfound' : ($lastError ?? 'network')];
    }
    return ['ok' => true] + $found + ['source' => implode(' + ', array_unique($sources))];
}

/** Human-readable text for a lookup error code. */
function isbn_lookup_error_text(string $code): string {
    switch ($code) {
        case 'notfound':    return 'No online record found for that ISBN. Type the details in yourself.';
        case 'ssl':         return 'Could not make a secure connection from this computer. In php.ini set curl.cainfo to a CA bundle (cacert.pem), restart Apache, and try again.';
        case 'busy':        return 'The book database is busy right now. Wait a moment and try again.';
        case 'unavailable': return 'This PHP install cannot make web requests. Enable the cURL extension (or allow_url_fopen) in php.ini.';
        case 'http':        return 'The book database sent an unexpected reply. Try again in a moment.';
        default:            return 'Could not reach the book database. Check that this computer is online.';
    }
}
