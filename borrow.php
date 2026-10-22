<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_login();
require_perm('loans.process');

$settings = get_settings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (post_str('form', '', 30)) === 'borrow') {
    csrf_verify();
    $memberId = post_int('member_id', 0);
    $bookId   = post_int('book_id', 0);
    $dueDate  = post_str('due_date');

    if (!$memberId || !$bookId || !$dueDate) {
        flash_set('Pick a member, pick a book, and set a due date.');
        header('Location: borrow.php');
        exit;
    }

    $dt = DateTime::createFromFormat('!Y-m-d', $dueDate);
    if (!$dt || $dt->format('Y-m-d') !== $dueDate || $dueDate < date('Y-m-d') || $dueDate > date('Y-m-d', strtotime('+365 days'))) {
        flash_set('Pick a real due date between today and one year from now.');
        header('Location: borrow.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT id, name, status FROM members WHERE id = ?');
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();

        $stmt = $pdo->prepare('SELECT id, title, available FROM books WHERE id = ? FOR UPDATE');
        $stmt->execute([$bookId]);
        $book = $stmt->fetch();

        if (!$member) {
            $pdo->rollBack();
            flash_set('That member could not be found.');
        } elseif ($member['status'] !== 'active') {
            $pdo->rollBack();
            flash_set($member['name'] . ' is suspended and cannot borrow right now.');
        } elseif (!$book || (int) $book['available'] <= 0) {
            $pdo->rollBack();
            flash_set('That title has no copies available.');
        } elseif (open_loans_for_member($pdo, $member['id'], $member['name']) >= (int) $settings['max_loans']) {
            $pdo->rollBack();
            flash_set($member['name'] . ' already has the maximum of ' . (int) $settings['max_loans'] . ' books out.');
        } else {
            $pdo->prepare('UPDATE books SET available = available - 1 WHERE id = ?')->execute([$bookId]);
            $pdo->prepare("INSERT INTO transactions (book_id, member_id, member_name, borrow_date, due_date, status)
                            VALUES (?,?,?,CURDATE(),?,'active')")
                ->execute([$bookId, $member['id'], $member['name'], $dueDate]);
            $pdo->commit();
            audit('loan_created', 'book=' . (int) $bookId . ' member=' . (int) $member['id']);
            flash_set('"' . $book['title'] . '" checked out to ' . $member['name'] . '.');
            header('Location: history.php');
            exit;
        }
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('borrow failed: ' . $ex->getMessage());
        flash_set('Could not create the loan. Please try again.');
    }
    header('Location: borrow.php');
    exit;
}

$members = $pdo->query("SELECT m.*,
                          (SELECT COUNT(*) FROM transactions t
                            WHERE t.status != 'returned'
                              AND (t.member_id = m.id OR (t.member_id IS NULL AND t.member_name = m.name))
                          ) AS open_loans
                        FROM members m
                        ORDER BY m.type ASC, m.name ASC")->fetchAll();

$books = $pdo->query('SELECT * FROM books ORDER BY title ASC')->fetchAll();

$defaultDue  = date('Y-m-d', strtotime('+' . (int) $settings['loan_period'] . ' days'));
$maxLoans    = (int) $settings['max_loans'];
$anyBookFree = (bool) array_filter($books, fn($b) => (int) $b['available'] > 0);

$activePage = 'borrow';
$pageTitle  = 'Borrow Book';
$eyebrow    = 'Transactions';
$flashMsg   = flash_get();
require __DIR__ . '/includes/header.php';
?>

<div class="card" style="max-width:620px;">
  <h4 style="margin-top:0;">Issue a New Loan</h4>
  <p class="card-sub" style="margin-bottom:20px;">
    Start with the borrower, then find the title. Loan limit is <?= $maxLoans ?> books per member.
  </p>

  <noscript>
    <p class="form-error">The searchable pickers on this page need JavaScript. Please enable it to issue a loan.</p>
  </noscript>

  <form method="post" action="borrow.php" id="borrowForm">
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="borrow">

    <!-- ---------- STEP 1: the student / faculty member ---------- -->
    <div class="form-group combo" data-combo="member">
      <label for="memberSearch">1. Student or Faculty Member</label>
      <div class="search-bar">
        <input type="search" id="memberSearch" autocomplete="off"
               placeholder="Search by School ID or name&hellip;"
               aria-label="Search for a member" aria-expanded="false" aria-controls="memberList">
      </div>
      <input type="hidden" name="member_id" id="memberId" value="">

      <div class="combo-list hidden" id="memberList" role="listbox">
        <?php foreach ($members as $m):
              $atLimit  = (int) $m['open_loans'] >= $maxLoans;
              $blocked  = $atLimit || $m['status'] !== 'active';
              $reason   = $m['status'] !== 'active' ? 'suspended'
                        : ($atLimit ? 'at loan limit' : (int) $m['open_loans'] . ' of ' . $maxLoans . ' out'); ?>
          <button type="button" class="combo-option"
                  data-value="<?= (int) $m['id'] ?>"
                  data-label="<?= e($m['name']) ?>"
                  data-detail="<?= e($m['school_id'] . ' · ' . $m['type'] . ' · ' . $reason) ?>"
                  data-search="<?= e(strtolower($m['school_id'] . ' ' . $m['name'] . ' ' . $m['type'])) ?>"
                  <?= $blocked ? 'disabled' : '' ?>>
            <strong><?= e($m['name']) ?></strong>
            <small><?= e($m['school_id']) ?> &middot; <?= e($m['type']) ?> &middot; <?= e($reason) ?></small>
          </button>
        <?php endforeach; ?>
        <p class="combo-empty hidden">No member matches that search.</p>
      </div>

      <div class="combo-chosen" id="memberChosen">
        <div>
          <strong data-chosen-label></strong>
          <small data-chosen-detail></small>
        </div>
        <button type="button" class="combo-clear" data-combo-clear>Change</button>
      </div>
    </div>

    <!-- ---------- STEP 2: the book ---------- -->
    <div class="form-group combo" data-combo="book">
      <label for="bookSearch">2. Book</label>
      <div class="search-bar">
        <input type="search" id="bookSearch" autocomplete="off"
               placeholder="Search by title, author, ISBN, or book code&hellip;"
               aria-label="Search for a book" aria-expanded="false" aria-controls="bookList">
      </div>
      <input type="hidden" name="book_id" id="bookId" value="">

      <div class="combo-list hidden" id="bookList" role="listbox">
        <?php foreach ($books as $b):
              $free = (int) $b['available']; ?>
          <button type="button" class="combo-option"
                  data-value="<?= (int) $b['id'] ?>"
                  data-label="<?= e($b['title']) ?>"
                  data-detail="<?= e($b['author'] . ' · ' . $free . ' available') ?>"
                  data-search="<?= e(strtolower(book_code($b['id']) . ' ' . $b['title'] . ' ' . $b['author'] . ' ' . $b['genre'] . ' ' . ($b['isbn'] ?? ''))) ?>"
                  <?= $free > 0 ? '' : 'disabled' ?>>
            <strong><?= e($b['title']) ?></strong>
            <small><?= e($b['author']) ?> &middot; <?= e(book_code($b['id'])) ?> &middot;
              <?= $free > 0 ? $free . ' available' : 'all copies out' ?></small>
          </button>
        <?php endforeach; ?>
        <p class="combo-empty hidden">No book matches that search.</p>
      </div>

      <div class="combo-chosen" id="bookChosen">
        <div>
          <strong data-chosen-label></strong>
          <small data-chosen-detail></small>
        </div>
        <button type="button" class="combo-clear" data-combo-clear>Change</button>
      </div>
    </div>

    <!-- ---------- STEP 3: due date ---------- -->
    <div class="form-group">
      <label for="borrowDue">3. Due Date</label>
      <input type="date" id="borrowDue" name="due_date" value="<?= e($defaultDue) ?>"
             min="<?= date('Y-m-d') ?>" required>
      <p class="field-hint">Defaults to the <?= (int) $settings['loan_period'] ?>-day loan period set in Settings.</p>
    </div>

    <button type="submit" class="btn btn-primary btn-block" <?= $anyBookFree ? '' : 'disabled' ?>>
      <?= $anyBookFree ? 'Confirm Borrow' : 'No copies available' ?>
    </button>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
