<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_login();

refresh_overdue($pdo);
$settings = get_settings($pdo);

$q      = get_str('q');
$status = get_str('status', 'all', 12);

$sql = "SELECT t.*, b.title AS book_title
        FROM transactions t
        LEFT JOIN books b ON b.id = t.book_id
        WHERE 1=1";
$params = [];

if ($status !== 'all' && in_array($status, ['active', 'overdue', 'returned'], true)) {
    $sql .= ' AND t.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $sql .= " AND (t.member_name LIKE ? OR b.title LIKE ? OR CONCAT('LN-', 8000 + t.id) LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$sql .= ' ORDER BY t.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$totalTx = (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();

$activePage = 'history';
$pageTitle  = 'Transaction History';
$eyebrow    = 'Transactions';
$flashMsg   = flash_get();
require __DIR__ . '/includes/header.php';
?>

<form class="page-head-row" method="get" action="history.php" role="search">
  <div class="search-bar toolbar-search">
    <input type="search" name="q" value="<?= e($q) ?>" aria-label="Search transaction history"
           placeholder="Search by loan ID, book, or member&hellip;">
    <?php if ($q !== ''): ?>
      <a class="search-clear" href="history.php<?= $status !== 'all' ? '?status=' . urlencode($status) : '' ?>" title="Clear search" aria-label="Clear search">&times;</a>
    <?php endif; ?>
    <button type="submit" class="search-submit">Search</button>
  </div>
  <select name="status" class="status-select" data-autosubmit aria-label="Filter by status">
    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All statuses</option>
    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
    <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue</option>
    <option value="returned" <?= $status === 'returned' ? 'selected' : '' ?>>Returned</option>
  </select>
</form>

<p class="search-meta">
  Showing <b><?= count($rows) ?></b> of <b><?= $totalTx ?></b> transactions<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.
</p>

<div class="card table-card">
  <table>
    <thead><tr><th>Loan ID</th><th>Book</th><th>Member</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th><th>Fine</th></tr></thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="table-empty">No transactions match your search.</td></tr>
      <?php else: foreach ($rows as $t): $fine = tx_fine($t, $settings['fine_rate']); ?>
        <tr>
          <td class="mono"><?= e(tx_code($t['id'])) ?></td>
          <td><?= e($t['book_title'] ?? '—') ?></td>
          <td><?= e($t['member_name']) ?></td>
          <td class="mono muted"><?= e($t['borrow_date']) ?></td>
          <td class="mono muted"><?= e($t['due_date']) ?></td>
          <td class="mono muted"><?= e($t['return_date'] ?? '—') ?></td>
          <td><?= status_badge($t['status']) ?></td>
          <td class="mono"><?= $fine ? '&#8369;' . number_format($fine, 2) : '—' ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
