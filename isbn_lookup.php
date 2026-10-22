<?php
/**
 * ISBN autofill endpoint (used by the "Look up" button in Add Book).
 *   isbn_lookup.php?isbn=978-0-13-235088-4
 * Returns JSON: {ok, exists?, title?, author?, genre?, source?, error?}
 * Staff who can manage the catalog only; rate limited.
 */
define('NO_SESSION_REGEN', true);   // several requests can overlap; never rotate the session id here

require __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/isbn_online.php';

function lookup_reply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$who = current_user($pdo);
if (!$who) lookup_reply(401, ['ok' => false, 'error' => 'Your session has ended. Please sign in again.']);
if (!can('catalog.manage', $who)) lookup_reply(403, ['ok' => false, 'error' => 'You do not have permission to add books.']);

$uid = (int) $who['id'];
$allowed = rl_hit($pdo, 'isbn_lookup', 'u' . $uid, 60, 600);   // 60 lookups per 10 minutes
session_write_close();                                          // don't hold the session lock during slow web requests
if (!$allowed) lookup_reply(429, ['ok' => false, 'error' => 'Too many lookups. Please wait a few minutes.']);

$isbn = isbn_normalize(get_str('isbn', '', 30));
if ($isbn === '')   lookup_reply(400, ['ok' => false, 'error' => 'Type an ISBN first.']);
if ($isbn === null) lookup_reply(400, ['ok' => false, 'error' => 'That ISBN is not valid. Check the digits (10 or 13, hyphens are fine).']);

// Already ours? Answer from the database - no internet needed.
$stmt = $pdo->prepare('SELECT id, title, copies FROM books WHERE isbn = ?');
$stmt->execute([$isbn]);
if ($row = $stmt->fetch()) {
    lookup_reply(200, [
        'ok' => true, 'exists' => true, 'isbn' => $isbn, 'title' => $row['title'], 'copies' => (int) $row['copies'],
        'error' => '"' . $row['title'] . '" is already in the catalog. Edit it to add more copies instead.',
    ]);
}

$res = isbn_lookup_online($isbn);
if (!$res['ok']) {
    audit('isbn_lookup_failed', $isbn . ' ' . $res['error']);
    lookup_reply(200, ['ok' => false, 'error' => isbn_lookup_error_text($res['error']), 'code' => $res['error']]);
}
lookup_reply(200, [
    'ok' => true, 'exists' => false, 'isbn' => $isbn,
    'title' => $res['title'], 'author' => $res['author'], 'genre' => $res['genre'], 'source' => $res['source'],
]);
