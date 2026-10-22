<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_login();
require_perm('loans.process');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (post_str('form', '', 30)) === 'return') {
    csrf_verify();
    $txId = post_int('tx_id', 0);
    $back = post_str('back', '', 100);

    if (!$txId) {
        flash_set('Transaction not found.');
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT book_id, member_name, status FROM transactions WHERE id = ? FOR UPDATE');
            $stmt->execute([$txId]);
            $tx = $stmt->fetch();

            if (!$tx) {
                $pdo->rollBack();
                flash_set('Transaction not found.');
            } elseif ($tx['status'] === 'returned') {
                $pdo->rollBack();
                flash_set('That loan was already closed.');
            } else {
                $pdo->prepare("UPDATE transactions SET status = 'returned', return_date = CURDATE() WHERE id = ?")
                    ->execute([$txId]);
                $pdo->prepare('UPDATE books SET available = LEAST(copies, available + 1) WHERE id = ?')
                    ->execute([$tx['book_id']]);
                $pdo->commit();
                audit('loan_returned', 'tx=' . $txId);
                flash_set('Loan ' . tx_code($txId) . ' returned by ' . $tx['member_name'] . '.');
            }
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('return failed: ' . $ex->getMessage());
            flash_set('Could not process the return. Please try again.');
        }
    }
    // come back to the same filtered view the user was looking at
    header('Location: return.php' . ($back !== '' ? '?q=' . urlencode($back) : ''));
    exit;
}

refresh_overdue($pdo);
$settings = get_settings($pdo);

/* ---------------------- SEARCH ---------------------- */
$q      = get_str('q');
$filter = get_str('filter', 'all', 12);

$sql = "SELECT t.*, b.title AS book_title, m.school_id,
               DATEDIFF(CURDATE(), t.due_date) AS days_late
        FROM transactions t
        LEFT JOIN books b   ON b.id = t.book_id
        LEFT JOIN members m ON m.id = t.member_id
        WHERE t.status != 'returned'";
$params = [];

if ($filter === 'overdue') {
    $sql .= " AND t.status = 'overdue'";
}
if ($q !== '') {
    $sql .= " AND (t.member_name LIKE ? OR b.title LIKE ? OR m.school_id LIKE ?
                   OR CONCAT('LN-', 8000 + t.id) LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$sql .= ' ORDER BY t.due_date ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$openTx = $stmt->fetchAll();

$totalOpen = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE status != 'returned'")->fetchColumn();

$activePage = 'return';
$pageTitle  = 'Return Book';
$eyebrow    = 'Transactions';
$flashMsg   = flash_get();
require __DIR__ . '/includes/header.php';
?>

<form class="page-head-row" method="get" action="return.php" role="search">
  <div class="search-bar toolbar-search">
    <input type="search" name="q" value="<?= e($q) ?>" autofocus aria-label="Search loans to return"
           placeholder="Search by loan ID, book, member, or School ID&hellip;">
    <?php if ($q !== ''): ?>
      <a class="search-clear" href="return.php<?= $filter !== 'all' ? '?filter=' . urlencode($filter) : '' ?>" title="Clear search" aria-label="Clear search">&times;</a>
    <?php endif; ?>
    <button type="submit" class="search-submit">Search</button>
  </div>
  <select name="filter" class="status-select" data-autosubmit aria-label="Filter loans">
    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All open loans</option>
    <option value="overdue" <?= $filter === 'overdue' ? 'selected' : '' ?>>Overdue only</option>
  </select>
</form>

<p class="search-meta">
  Showing <b><?= count($openTx) ?></b> of <b><?= $totalOpen ?></b> open loans<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.
</p>

<div class="card table-card">
  <div class="card-head">
    <div>
      <h4>Books Currently On Loan</h4>
      <p class="card-sub">Marking a loan returned closes it out and frees up a copy</p>
    </div>
  </div>
  <table>
    <thead>
      <tr>
        <th>Loan ID</th><th>Book</th><th>Member</th><th>School ID</th>
        <th>Due Date</th><th>Status</th><th>Fine</th><th class="table-actions-col"></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$openTx): ?>
        <tr><td colspan="8" class="table-empty">
          <?= $q !== '' ? 'No open loans match that search.' : 'Nothing is currently checked out.' ?>
        </td></tr>
      <?php else: foreach ($openTx as $t): $fine = tx_fine($t, $settings['fine_rate']); ?>
        <tr>
          <td class="mono"><?= e(tx_code($t['id'])) ?></td>
          <td><?= e($t['book_title'] ?? '&mdash;') ?></td>
          <td><?= e($t['member_name']) ?></td>
          <td class="mono muted"><?= e($t['school_id'] ?? '&mdash;') ?></td>
          <td class="mono muted"><?= e($t['due_date']) ?></td>
          <td><?= status_badge($t['status']) ?></td>
          <td class="mono"><?= $fine ? '&#8369;' . number_format($fine, 2) : '&mdash;' ?></td>
          <td class="table-actions-col">
            <form method="post" action="return.php" class="inline-form"
                  data-confirm="Mark <?= e(tx_code($t['id'])) ?> (<?= e($t['book_title'] ?? 'this book') ?>) as returned?">
              <?= csrf_field() ?>
              <input type="hidden" name="form" value="return">
              <input type="hidden" name="tx_id" value="<?= (int) $t['id'] ?>">
              <input type="hidden" name="back" value="<?= e($q) ?>">
              <button type="submit" class="btn-sm">Mark Returned</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
