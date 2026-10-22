<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_login();
$canManage = can('catalog.manage');

$formAction = post_str('form', '', 30);

/* ---------------------- CSV TEMPLATE DOWNLOAD ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && get_str('template', '', 3) === '1') {
    require_perm('catalog.manage');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="books_import_template.csv"');
    echo "isbn,title,author,genre,copies\r\n";
    echo "9780201633610,Design Patterns,Erich Gamma et al.,Software Engineering,2\r\n";
    echo "9780596517748,,,,1\r\n";   // ISBN only: title/author/genre are looked up online
    exit;
}

/* ---------------------- ADD BOOK ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formAction === 'add_book') {
    csrf_verify();
    require_perm('catalog.manage');
    $title  = post_str('title');
    $author = post_str('author');
    $genre  = post_str('genre', '', 100);
    $copies = max(1, min(100000, post_int('copies', 1)));
    $isbnRaw = post_str('isbn', '', 30);
    $isbn    = isbn_normalize($isbnRaw);

    if (!$title || !$author || !$genre) {
        flash_set('Title, author, and genre are required.');
        old_set(['title' => $title, 'author' => $author, 'genre' => $genre, 'copies' => $copies, 'isbn' => $isbnRaw]);
        header('Location: books.php?open=add');
        exit;
    }
    if ($isbn === '') {
        flash_set('ISBN is required.');
        old_set(['title' => $title, 'author' => $author, 'genre' => $genre, 'copies' => $copies, 'isbn' => $isbnRaw]);
        header('Location: books.php?open=add');
        exit;
    }
    if ($isbn === null) {
        flash_set('That ISBN is not valid. Check the digits (10 or 13, hyphens are fine).');
        old_set(['title' => $title, 'author' => $author, 'genre' => $genre, 'copies' => $copies, 'isbn' => $isbnRaw]);
        header('Location: books.php?open=add');
        exit;
    }
    if (isbn_taken($pdo, $isbn)) {
        flash_set('A book with ISBN ' . $isbn . ' is already in the catalog. Edit it and add copies instead.');
        old_set(['title' => $title, 'author' => $author, 'genre' => $genre, 'copies' => $copies, 'isbn' => $isbnRaw]);
        header('Location: books.php?open=add');
        exit;
    }

    $pdo->prepare('INSERT INTO books (isbn, title, author, genre, copies, available) VALUES (?,?,?,?,?,?)')
        ->execute([$isbn, $title, $author, $genre, $copies, $copies]);
    flash_set('Added "' . $title . '" to the catalog.');
    header('Location: books.php');
    exit;
}

/* ---------------------- EDIT BOOK ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formAction === 'edit_book') {
    csrf_verify();
    require_perm('catalog.manage');
    $id     = post_int('id', 0);
    $title  = post_str('title');
    $author = post_str('author');
    $genre  = post_str('genre', '', 100);
    $copies = max(1, min(100000, post_int('copies', 1)));
    $isbn   = isbn_normalize(post_str('isbn', '', 30));

    if (!$id || !$title || !$author || !$genre) {
        flash_set('Title, author, and genre are required.');
        header('Location: books.php');
        exit;
    }
    if ($isbn === '') {
        flash_set('ISBN is required, so nothing was saved.');
        header('Location: books.php');
        exit;
    }
    if ($isbn === null) {
        flash_set('That ISBN is not valid, so nothing was saved. Check the digits (10 or 13, hyphens are fine).');
        header('Location: books.php');
        exit;
    }
    if (isbn_taken($pdo, $isbn, $id)) {
        flash_set('Another book already uses ISBN ' . $isbn . ', so nothing was saved.');
        header('Location: books.php');
        exit;
    }

    $stmt = $pdo->prepare('SELECT copies, available FROM books WHERE id = ?');
    $stmt->execute([$id]);
    $book = $stmt->fetch();

    if (!$book) {
        flash_set('That book no longer exists.');
    } else {
        // copies currently checked out must stay accounted for
        $onLoan = (int) $book['copies'] - (int) $book['available'];
        if ($copies < $onLoan) {
            flash_set('Cannot drop to ' . $copies . ' copies: ' . $onLoan . ' are still checked out.');
        } else {
            $pdo->prepare('UPDATE books SET isbn = ?, title = ?, author = ?, genre = ?, copies = ?, available = ? WHERE id = ?')
                ->execute([$isbn, $title, $author, $genre, $copies, $copies - $onLoan, $id]);
            flash_set('Updated "' . $title . '".');
        }
    }
    header('Location: books.php');
    exit;
}

/* ---------------------- DELETE BOOK ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formAction === 'delete_book') {
    csrf_verify();
    require_perm('catalog.manage');
    $id = post_int('id', 0);

    $stmt = $pdo->prepare('SELECT title, copies, available FROM books WHERE id = ?');
    $stmt->execute([$id]);
    $book = $stmt->fetch();

    if (!$book) {
        flash_set('That book no longer exists.');
        header('Location: books.php');
        exit;
    }

    $onLoan = (int) $book['copies'] - (int) $book['available'];
    if ($onLoan > 0) {
        flash_set('"' . $book['title'] . '" still has ' . $onLoan . ' copy/copies on loan. Process the returns first.');
        header('Location: books.php');
        exit;
    }

    // transactions reference books via a foreign key, so an old loan history
    // blocks the delete. Tell the user that instead of showing a raw SQL error.
    try {
        $pdo->prepare('DELETE FROM books WHERE id = ?')->execute([$id]);
        flash_set('"' . $book['title'] . '" removed from the catalog.');
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') {
            flash_set('"' . $book['title'] . '" has loan history attached, so it cannot be deleted. Set its copies to the correct number instead.');
        } else {
            error_log('delete_book failed: ' . $ex->getMessage());
            flash_set('Could not remove that book. Please try again.');
        }
    }
    header('Location: books.php');
    exit;
}

/* ---------------------- IMPORT BOOKS FROM CSV ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formAction === 'import_books') {
    csrf_verify();
    require_perm('catalog.manage');
    require __DIR__ . '/includes/isbn_online.php';

    $maxRows    = 1000;
    $lookupCap  = 40;                       // online lookups per import
    $doLookup   = post_str('lookup', '', 1) === '1';
    $file       = $_FILES['csv'] ?? null;

    $fail = function (string $msg) {
        flash_set($msg);
        header('Location: books.php?open=import');
        exit;
    };

    if (!$file || !is_array($file) || !is_string($file['tmp_name'] ?? null)) $fail('Choose a CSV file to import.');
    if ($file['error'] === UPLOAD_ERR_NO_FILE) $fail('Choose a CSV file to import.');
    if ($file['error'] !== UPLOAD_ERR_OK) $fail('The file could not be uploaded (it may be larger than the server allows).');
    if ($file['size'] > 2 * 1024 * 1024) $fail('That file is over 2 MB. Split it into smaller files.');
    if (!is_uploaded_file($file['tmp_name'])) $fail('The file could not be uploaded.');
    if (!preg_match('/\.(csv|txt)$/i', (string) $file['name'])) $fail('Please upload a .csv file (in Excel: Save As > CSV).');

    $fh = fopen($file['tmp_name'], 'rb');
    if (!$fh) $fail('The file could not be read.');

    // Excel in some regions saves with ";" (or tabs) instead of ","
    $firstLine = (string) fgets($fh);
    rewind($fh);
    $delim = ',';
    $best  = substr_count($firstLine, ',');
    foreach ([';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")] as $d => $n) {
        if ($n > $best) { $best = $n; $delim = $d; }
    }

    $toUtf8 = function ($v) {
        $v = (string) $v;
        if (!preg_match('//u', $v)) {                      // Excel's "CSV" is often Windows-1252
            $v = function_exists('iconv') ? (string) @iconv('Windows-1252', 'UTF-8//IGNORE', $v) : '';
        }
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v));
    };

    $header = fgetcsv($fh, 4096, $delim, '"', '');
    if (!$header) { fclose($fh); $fail('That file is empty.'); }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);   // strip BOM
    $col = [];
    foreach ($header as $i => $name) {
        $name = strtolower(preg_replace('/[^a-z]/i', '', $toUtf8($name)));
        $name = ['isbn13' => 'isbn', 'isbn10' => 'isbn', 'bookTitle' => 'title', 'booktitle' => 'title',
                 'authors' => 'author', 'category' => 'genre', 'qty' => 'copies', 'quantity' => 'copies'][$name] ?? $name;
        if (in_array($name, ['isbn', 'title', 'author', 'genre', 'copies'], true) && !isset($col[$name])) $col[$name] = $i;
    }
    if (!isset($col['isbn'])) {
        fclose($fh);
        $fail('The first row must be a header with an "isbn" column (isbn, title, author, genre, copies). Use the template.');
    }

    if (function_exists('set_time_limit')) @set_time_limit(180);

    $added = 0; $skipped = []; $seen = []; $lookups = 0; $netFails = 0; $lookupStopped = false;
    $line = 1;
    $ins = $pdo->prepare('INSERT INTO books (isbn, title, author, genre, copies, available) VALUES (?,?,?,?,?,?)');
    $get = function (array $row, string $name) use ($col, $toUtf8) {
        return isset($col[$name]) && isset($row[$col[$name]]) ? $toUtf8($row[$col[$name]]) : '';
    };
    $skip = function (int $line, string $why) use (&$skipped) {
        if (count($skipped) < 60) $skipped[] = 'Row ' . $line . ': ' . $why;
        else $skipped[] = null;                                    // counted but not listed
    };

    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($fh, 4096, $delim, '"', '')) !== false) {
            $line++;
            if ($line - 1 > $maxRows) { $skip($line, 'stopped here - the limit is ' . $maxRows . ' rows per import.'); break; }
            if (count($row) === 1 && trim((string) $row[0]) === '') continue;           // blank line

            $rawIsbn = $get($row, 'isbn');
            if ($rawIsbn === '') { $skip($line, 'no ISBN.'); continue; }
            if (preg_match('/^\d(\.\d+)?E\+\d+$/i', $rawIsbn)) {
                $skip($line, 'Excel turned the ISBN into a number (' . $rawIsbn . '). Format the ISBN column as Text and re-save.');
                continue;
            }
            $isbn = isbn_normalize($rawIsbn);
            if ($isbn === '' || $isbn === null) { $skip($line, 'ISBN "' . u_cut($rawIsbn, 20) . '" is not valid.'); continue; }
            if (isset($seen[$isbn])) { $skip($line, 'ISBN ' . $isbn . ' appears twice in the file.'); continue; }
            $seen[$isbn] = true;
            if (isbn_taken($pdo, $isbn)) { $skip($line, 'ISBN ' . $isbn . ' is already in the catalog (raise its copies instead).'); continue; }

            $title  = u_cut($get($row, 'title'), 255);
            $author = u_cut($get($row, 'author'), 255);
            $genre  = u_cut($get($row, 'genre'), 100);
            $copiesRaw = $get($row, 'copies');
            $copies = 1;
            if ($copiesRaw !== '') {
                if (!preg_match('/^\d{1,6}$/', $copiesRaw) || (int) $copiesRaw < 1 || (int) $copiesRaw > 100000) {
                    $skip($line, 'copies "' . u_cut($copiesRaw, 12) . '" must be a whole number from 1 to 100000.');
                    continue;
                }
                $copies = (int) $copiesRaw;
            }

            // fill the gaps from the internet (only if asked, only when something is missing)
            if ($doLookup && ($title === '' || $author === '' || $genre === '') && !$lookupStopped) {
                if ($lookups >= $lookupCap) {
                    $lookupStopped = true;
                } else {
                    $lookups++;
                    $res = isbn_lookup_online($isbn);
                    if ($res['ok']) {
                        $netFails = 0;
                        if ($title  === '') $title  = $res['title'];
                        if ($author === '') $author = $res['author'];
                        if ($genre  === '') $genre  = $res['genre'];
                    } elseif (in_array($res['error'], ['network', 'ssl', 'unavailable'], true)) {
                        if (++$netFails >= 3) $lookupStopped = true;       // offline: stop hammering
                    }
                }
            }

            if ($genre === '' && $title !== '' && $author !== '') $genre = 'Uncategorized';
            if ($title === '' || $author === '') {
                $why = 'missing title/author';
                if ($doLookup) $why .= $lookupStopped ? ' (online lookup was not available or hit its limit of ' . $lookupCap . ' per import)' : ' and no online record was found';
                $skip($line, $why . '.');
                continue;
            }

            $ins->execute([$isbn, $title, $author, $genre, $copies, $copies]);
            $added++;
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fclose($fh);
        error_log('import_books failed: ' . $ex->getMessage());
        $fail('The import failed part-way and nothing was saved. Please check the file and try again.');
    }
    fclose($fh);
    audit('books_import', 'added=' . $added . ' skipped=' . count($skipped));

    $_SESSION['import_report'] = [
        'added'   => $added,
        'skipped' => array_values(array_filter($skipped, fn($x) => $x !== null)),
        'hidden'  => count(array_filter($skipped, fn($x) => $x === null)),
        'lookups' => $lookups,
    ];
    flash_set($added > 0
        ? 'Imported ' . $added . ' book' . ($added === 1 ? '' : 's') . (count($skipped) ? ', skipped ' . count($skipped) . '.' : '.')
        : 'No books were imported. See the details on the page.');
    header('Location: books.php');
    exit;
}

/* ---------------------- SEARCH / FILTER ---------------------- */
$q     = get_str('q');
$genre = get_str('genre', '', 100);
$avail = get_str('avail', 'all', 12);
if (!in_array($avail, ['all', 'in', 'out'], true)) $avail = 'all';

$sql    = 'SELECT * FROM books WHERE 1=1';
$params = [];

if ($q !== '') {
    $sql .= ' AND (title LIKE ? OR author LIKE ? OR genre LIKE ? OR isbn LIKE ? OR isbn = ?)';
    $like      = '%' . $q . '%';
    $qClean    = isbn_clean($q);
    $isbnLike  = $qClean !== '' ? '%' . $qClean . '%' : $like;   // partial ISBN, hyphens ignored
    $isbnExact = isbn_normalize($q) ?: '';                       // a full ISBN-10 also finds its ISBN-13
    array_push($params, $like, $like, $like, $isbnLike, $isbnExact);
}
if ($genre !== '') {
    $sql .= ' AND genre = ?';
    $params[] = $genre;
}
if ($avail === 'in')  { $sql .= ' AND available > 0'; }
if ($avail === 'out') { $sql .= ' AND available = 0'; }

$sql .= ' ORDER BY title ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll();

$allGenres   = $pdo->query('SELECT DISTINCT genre FROM books ORDER BY genre ASC')->fetchAll(PDO::FETCH_COLUMN);
$totalBooks  = (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();

$openAdd    = isset($_GET['open']) && $_GET['open'] === 'add';
$openImport = isset($_GET['open']) && $_GET['open'] === 'import';
$importReport = $_SESSION['import_report'] ?? null;     // shown once after an import
unset($_SESSION['import_report']);
$old     = old_get();

$activePage = 'books';
$pageTitle  = 'Book Catalog';
$eyebrow    = 'Catalog';
$flashMsg   = flash_get();
require __DIR__ . '/includes/header.php';
?>

<form class="page-head-row" method="get" action="books.php" role="search">
  <div class="search-bar toolbar-search">
    <input type="search" name="q" data-suggest="books" value="<?= e($q) ?>" aria-label="Search books"
           placeholder="Search by title, author, genre, or ISBN&hellip;">
    <?php if ($q !== ''): ?>
      <?php
        $keep = array_filter(['genre' => $genre, 'avail' => $avail === 'all' ? '' : $avail]);
        $clearHref = 'books.php' . ($keep ? '?' . http_build_query($keep) : '');
      ?>
      <a class="search-clear" href="<?= e($clearHref) ?>" title="Clear search" aria-label="Clear search">&times;</a>
    <?php endif; ?>
    <button type="submit" class="search-submit">Search</button>
  </div>
  <select name="genre" class="status-select" data-autosubmit aria-label="Filter by genre">
    <option value="">All genres</option>
    <?php foreach ($allGenres as $g): ?>
      <option value="<?= e($g) ?>" <?= $genre === $g ? 'selected' : '' ?>><?= e($g) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="avail" class="status-select" data-autosubmit aria-label="Filter by availability">
    <option value="all" <?= $avail === 'all' ? 'selected' : '' ?>>Any availability</option>
    <option value="in"  <?= $avail === 'in'  ? 'selected' : '' ?>>Available now</option>
    <option value="out" <?= $avail === 'out' ? 'selected' : '' ?>>All copies out</option>
  </select>
  <?php if ($canManage): ?><button type="button" class="btn" data-open-modal="importBookModal">Import CSV</button><?php endif; ?>
  <?php if ($canManage): ?><button type="button" class="btn btn-primary" data-open-modal="addBookModal">Add Book</button><?php endif; ?>
</form>

<?php if ($importReport): ?>
<div class="card" style="margin-bottom:16px;">
  <h4>Import finished</h4>
  <p class="muted" style="margin-top:6px;">
    Added <b><?= (int) $importReport['added'] ?></b> book<?= $importReport['added'] === 1 ? '' : 's' ?>,
    skipped <b><?= count($importReport['skipped']) + (int) $importReport['hidden'] ?></b>.
    <?php if ($importReport['lookups'] > 0): ?>Looked up <?= (int) $importReport['lookups'] ?> online.<?php endif; ?>
  </p>
  <?php if ($importReport['skipped']): ?>
    <ul style="margin:10px 0 0 18px;font-size:13px;line-height:1.7;">
      <?php foreach ($importReport['skipped'] as $why): ?><li><?= e($why) ?></li><?php endforeach; ?>
      <?php if ($importReport['hidden'] > 0): ?><li>&hellip;and <?= (int) $importReport['hidden'] ?> more.</li><?php endif; ?>
    </ul>
  <?php endif; ?>
</div>
<?php endif; ?>

<p class="search-meta">
  Showing <b><?= count($books) ?></b> of <b><?= $totalBooks ?></b> titles<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.
</p>

<div class="grid cols-3" id="booksGrid">
  <?php if (!$books): ?>
    <p class="table-empty">No books match your search.</p>
  <?php else: foreach ($books as $b): ?>
    <div class="card book-card-item">
      <div class="book-top">
        <span class="mono muted"><?= e(book_code($b['id'])) ?></span>
        <span class="badge <?= $b['available'] > 0 ? 'ok' : 'danger' ?>">
          <?= $b['available'] > 0 ? 'Available' : 'All Out' ?>
        </span>
      </div>
      <h4><?= e($b['title']) ?></h4>
      <p class="muted"><?= e($b['author']) ?></p>
      <?php if (!empty($b['isbn'])): ?>
        <p class="mono muted" style="margin-top:4px;">ISBN <?= e($b['isbn']) ?></p>
      <?php else: ?>
        <p class="mono muted" style="margin-top:4px;">ISBN missing<?= $canManage ? ' &ndash; click Edit to add it' : '' ?></p>
      <?php endif; ?>
      <p class="mono" style="margin-top:6px;"><?= (int) $b['available'] ?> / <?= (int) $b['copies'] ?> copies</p>
      <p class="badge" style="margin-top:8px;align-self:flex-start;"><?= e($b['genre']) ?></p>
      <?php if ($canManage): ?>
      <div class="book-card-foot">
        <button type="button" class="btn-sm"
                data-edit-book
                data-id="<?= (int) $b['id'] ?>"
                data-isbn="<?= e($b['isbn'] ?? '') ?>"
                data-title="<?= e($b['title']) ?>"
                data-author="<?= e($b['author']) ?>"
                data-genre="<?= e($b['genre']) ?>"
                data-copies="<?= (int) $b['copies'] ?>">Edit</button>
        <form class="inline-form" method="post" action="books.php"
              data-confirm="Remove &quot;<?= e($b['title']) ?>&quot; from the catalog?">
          <?= csrf_field() ?>
          <input type="hidden" name="form" value="delete_book">
          <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
          <button type="submit" class="btn-sm danger">Remove</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>

<?php if ($canManage): ?>
<!-- ---------------------- ADD MODAL ---------------------- -->
<div class="modal <?= $openAdd ? '' : 'hidden' ?>" id="addBookModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Add Book</h3>
      <button type="button" data-close-modal aria-label="Close">&times;</button>
    </div>
    <form method="post" action="books.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="add_book">
      <div class="form-group">
        <label for="bf-title">Title</label>
        <input type="text" id="bf-title" name="title" required value="<?= e($old['title'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="bf-isbn">ISBN</label>
        <div style="display:flex;gap:8px;align-items:center;">
          <input type="text" id="bf-isbn" name="isbn" required inputmode="text" autocomplete="off" maxlength="17"
                 placeholder="978-0-13-235088-4" value="<?= e($old['isbn'] ?? '') ?>" style="flex:1;min-width:0;">
          <button type="button" class="btn-sm" id="bf-lookup">Look up</button>
        </div>
        <p class="field-hint" id="bf-lookup-msg" aria-live="polite">10 or 13 digits; hyphens are fine. Click Look up to fill in the title, author and genre from the internet (needs internet).</p>
      </div>
      <div class="form-group">
        <label for="bf-author">Author</label>
        <input type="text" id="bf-author" name="author" required value="<?= e($old['author'] ?? '') ?>">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="bf-genre">Genre</label>
          <input type="text" id="bf-genre" name="genre" required list="genreList" value="<?= e($old['genre'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="bf-copies">Copies</label>
          <input type="number" id="bf-copies" name="copies" min="1" value="<?= (int) ($old['copies'] ?? 1) ?>" required>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Save Book</button>
    </form>
  </div>
</div>

<!-- ---------------------- IMPORT CSV MODAL ---------------------- -->
<div class="modal <?= $openImport ? '' : 'hidden' ?>" id="importBookModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Import Books from CSV</h3>
      <button type="button" data-close-modal aria-label="Close">&times;</button>
    </div>
    <form method="post" action="books.php" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="import_books">
      <div class="form-group">
        <label for="ib-file">CSV file</label>
        <input type="file" id="ib-file" name="csv" accept=".csv,.txt,text/csv" required>
        <p class="field-hint">
          Columns: <b>isbn, title, author, genre, copies</b>. Only <b>isbn</b> is needed if you tick the box below.
          Max 1000 rows and 2 MB. Books whose ISBN is already in the catalog are skipped.
          <a href="books.php?template=1">Download a template</a>.
        </p>
      </div>
      <div class="form-group">
        <label style="display:flex;gap:8px;align-items:flex-start;font-weight:500;">
          <input type="checkbox" name="lookup" value="1" checked style="margin-top:3px;">
          <span>Fill in missing title, author and genre from the internet (needs internet; up to 40 books per import).</span>
        </label>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Import Books</button>
    </form>
  </div>
</div>

<!-- ---------------------- EDIT MODAL ---------------------- -->
<div class="modal hidden" id="editBookModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Edit Book</h3>
      <button type="button" data-close-modal aria-label="Close">&times;</button>
    </div>
    <form method="post" action="books.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="edit_book">
      <input type="hidden" name="id" id="eb-id">
      <div class="form-group">
        <label for="eb-title">Title</label>
        <input type="text" id="eb-title" name="title" required>
      </div>
      <div class="form-group">
        <label for="eb-isbn">ISBN</label>
        <input type="text" id="eb-isbn" name="isbn" required inputmode="text" autocomplete="off" maxlength="17"
               placeholder="978-0-13-235088-4">
      </div>
      <div class="form-group">
        <label for="eb-author">Author</label>
        <input type="text" id="eb-author" name="author" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="eb-genre">Genre</label>
          <input type="text" id="eb-genre" name="genre" required list="genreList">
        </div>
        <div class="form-group">
          <label for="eb-copies">Total Copies</label>
          <input type="number" id="eb-copies" name="copies" min="1" required>
          <p class="field-hint">Copies already on loan stay checked out.</p>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
    </form>
  </div>
</div>

<datalist id="genreList">
  <?php foreach ($allGenres as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?>
</datalist>
<script nonce="<?= csp_nonce() ?>">
  /* "Look up" button in Add Book: asks isbn_lookup.php (which asks Open Library / Google Books). */
  (function () {
    var isbn = document.getElementById('bf-isbn');
    var btn  = document.getElementById('bf-lookup');
    var msg  = document.getElementById('bf-lookup-msg');
    if (!isbn || !btn || !msg) return;

    var fields = {
      title:  document.getElementById('bf-title'),
      author: document.getElementById('bf-author'),
      genre:  document.getElementById('bf-genre')
    };
    var defaultHint = msg.textContent;

    // once the person edits a field themselves, a later lookup must not overwrite it
    Object.keys(fields).forEach(function (k) {
      fields[k].addEventListener('input', function () { delete fields[k].dataset.auto; });
    });

    function say(text, bad) {
      msg.textContent = text;
      msg.style.color = bad ? 'var(--danger, #b3261e)' : '';
    }

    function fill(name, value) {
      var el = fields[name];
      if (!value) return;
      if (el.value.trim() === '' || el.dataset.auto === '1') {
        el.value = value;
        el.dataset.auto = '1';
      }
    }

    function lookup() {
      var value = isbn.value.trim();
      if (!value) { say('Type an ISBN first.', true); isbn.focus(); return; }

      btn.disabled = true;
      var label = btn.textContent;
      btn.textContent = 'Looking up\u2026';
      say('Searching the book database\u2026', false);

      var ctl = typeof AbortController === 'function' ? new AbortController() : null;
      var timer = ctl ? setTimeout(function () { ctl.abort(); }, 20000) : null;

      fetch('isbn_lookup.php?isbn=' + encodeURIComponent(value), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        signal: ctl ? ctl.signal : undefined
      })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected reply from the server.' }; }); })
        .then(function (d) {
          if (!d.ok) { say(d.error || 'Lookup failed.', true); return; }
          if (d.exists) { say(d.error, true); return; }
          if (d.isbn) isbn.value = d.isbn;
          fill('title', d.title);
          fill('author', d.author);
          fill('genre', d.genre);
          var missing = ['title', 'author', 'genre'].filter(function (k) { return !d[k]; });
          say('Filled in from ' + (d.source || 'the internet') + '.' +
              (missing.length ? ' Not found: ' + missing.join(', ') + ' \u2013 please type ' + (missing.length > 1 ? 'them' : 'it') + '.' : ' Please double-check before saving.'),
              false);
        })
        .catch(function () { say('Could not reach the server. Check your connection and try again.', true); })
        .then(function () {
          if (timer) clearTimeout(timer);
          btn.disabled = false;
          btn.textContent = label;
        });
    }

    btn.addEventListener('click', lookup);
    // Enter in the ISBN box looks it up while the form is still empty, instead of failing "required"
    isbn.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && fields.title.value.trim() === '') { ev.preventDefault(); lookup(); }
    });
    isbn.addEventListener('input', function () { if (msg.textContent !== defaultHint) say(defaultHint, false); });
  })();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
