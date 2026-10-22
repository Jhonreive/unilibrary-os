<?php
/**
 * Live search suggestions (the dropdown under the search bars).
 *   suggest.php?q=intro&scope=books|members|all
 * Returns a small JSON list: [{type, label, detail, value}, ...]
 */

// Many of these requests can be in flight while someone types. Never rotate
// the session id here, or two overlapping requests could log the user out.
define('NO_SESSION_REGEN', true);

require __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

// Same rules as every page: must be logged in AND still an active account.
$__who = current_user($pdo);
// done with the session: release the lock so page loads aren't held up
session_write_close();
if (!$__who) {
    http_response_code(401);
    echo '[]';
    exit;
}

$q     = get_str('q');
$scope = get_str('scope', 'books', 10);
if (!in_array($scope, ['books', 'members', 'all'], true)) $scope = 'books';

if ($q === '') {
    echo '[]';
    exit;
}

// Escape LIKE wildcards in what the user typed ('|' is the escape char).
function like_esc($s) { return str_replace(['|', '%', '_'], ['||', '|%', '|_'], $s); }

$esc      = like_esc($q);
$has      = '%' . $esc . '%';
$starts   = $esc . '%';
$clean    = isbn_clean($q);                       // "978-0-13" -> "978013"
$hasIsbn  = $clean !== '' ? '%' . like_esc($clean) . '%' : $has;

$out = [];

try {
    if ($scope === 'books' || $scope === 'all') {
        $stmt = $pdo->prepare("SELECT title, author, isbn FROM books
            WHERE title  LIKE ? ESCAPE '|'
               OR author LIKE ? ESCAPE '|'
               OR genre  LIKE ? ESCAPE '|'
               OR isbn   LIKE ? ESCAPE '|'
               OR CONCAT('BK-', LPAD(id, 3, '0')) LIKE ? ESCAPE '|'
            ORDER BY (title LIKE ? ESCAPE '|') DESC, title ASC
            LIMIT 6");
        $stmt->execute([$has, $has, $has, $hasIsbn, $has, $starts]);
        foreach ($stmt->fetchAll() as $b) {
            $detail = $b['author'] . (!empty($b['isbn']) ? ' · ISBN ' . $b['isbn'] : '');
            $out[] = ['type' => 'book', 'label' => $b['title'], 'detail' => $detail, 'value' => $b['title']];
        }

        $stmt = $pdo->prepare("SELECT DISTINCT author FROM books
            WHERE author LIKE ? ESCAPE '|'
            ORDER BY (author LIKE ? ESCAPE '|') DESC, author ASC
            LIMIT 2");
        $stmt->execute([$has, $starts]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $a) {
            $out[] = ['type' => 'author', 'label' => $a, 'detail' => 'Author', 'value' => $a];
        }
    }

    if ($scope === 'members' || $scope === 'all') {
        $stmt = $pdo->prepare("SELECT name, school_id, type FROM members
            WHERE name      LIKE ? ESCAPE '|'
               OR school_id LIKE ? ESCAPE '|'
               OR email     LIKE ? ESCAPE '|'
            ORDER BY (name LIKE ? ESCAPE '|') DESC, name ASC
            LIMIT 4");
        $stmt->execute([$has, $has, $has, $starts]);
        foreach ($stmt->fetchAll() as $m) {
            $out[] = ['type' => 'member', 'label' => $m['name'],
                      'detail' => $m['school_id'] . ' · ' . $m['type'], 'value' => $m['name']];
        }
    }
} catch (PDOException $ex) {
    error_log('suggest.php failed: ' . $ex->getMessage());
    $out = [];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
