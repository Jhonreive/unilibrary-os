<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_login();
require_perm('reports.view');

$books        = $pdo->query('SELECT * FROM books ORDER BY id ASC')->fetchAll();
$members      = $pdo->query('SELECT * FROM members ORDER BY id ASC')->fetchAll();
$transactions = $pdo->query('SELECT * FROM transactions ORDER BY id ASC')->fetchAll();

$byGenre = [];
foreach ($books as $b) {
    $byGenre[$b['genre']] = ($byGenre[$b['genre']] ?? 0) + ((int) $b['copies'] - (int) $b['available']);
}
arsort($byGenre);
$maxGenre = max(1, ...array_values($byGenre ?: [0]));

$overdueCount  = count(array_filter($transactions, fn($t) => $t['status'] === 'overdue'));
$returnedCount = count(array_filter($transactions, fn($t) => $t['status'] === 'returned'));
$totalTx       = count($transactions) ?: 1;
$onTimeRate    = round(($returnedCount / $totalTx) * 100);

$memberTypeBreak = [];
foreach ($members as $m) {
    $memberTypeBreak[$m['type']] = ($memberTypeBreak[$m['type']] ?? 0) + 1;
}

$activePage = 'reports';
$pageTitle  = 'Reports & Analytics';
$eyebrow    = 'System';
$flashMsg   = flash_get();
require __DIR__ . '/includes/header.php';
?>

<div class="page-head-row report-actions">
  <p class="search-meta" style="margin:0;">Live numbers from your current records.</p>
  <a class="btn btn-primary btn-export" href="export_reports.php" id="exportBtn" download>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v12M7 11l5 5 5-5M4 20h16"/></svg>
    <span>Export to Excel</span>
  </a>
</div>
<script nonce="<?= csp_nonce() ?>">
  document.getElementById('exportBtn').addEventListener('click', function () {
    var l = this.querySelector('span'), old = l.textContent;
    l.textContent = 'Preparing your file\u2026';
    setTimeout(function () { l.textContent = old; }, 2200);
  });
</script>

<div class="grid cols-split">
  <div class="card">
    <h4 style="margin-top:0;">Checkouts by Genre</h4>
    <p class="card-sub" style="margin-bottom:16px;">Copies currently out on loan, grouped by genre</p>
    <?php if (!$byGenre): ?>
      <p class="table-empty">No active loans yet.</p>
    <?php else: foreach ($byGenre as $genre => $count): ?>
      <div class="bar-row">
        <span class="bar-label"><?= e($genre) ?></span>
        <div class="bar-track"><div class="bar-fill" style="width:<?= ($count / $maxGenre) * 100 ?>%"></div></div>
        <span class="bar-count mono"><?= $count ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <div class="card">
    <h4 style="margin-top:0;">Circulation Health</h4>
    <div class="stat-divider"></div>
    <p class="stat-foot">Total transactions: <b><?= count($transactions) ?></b></p>
    <p class="stat-foot">Overdue: <b><?= $overdueCount ?></b></p>
    <p class="stat-foot">On-time return rate: <b><?= $onTimeRate ?>%</b></p>
    <div class="stat-divider"></div>
    <p class="stat-foot">Students: <b><?= $memberTypeBreak['Student'] ?? 0 ?></b></p>
    <p class="stat-foot">Faculty: <b><?= $memberTypeBreak['Faculty'] ?? 0 ?></b></p>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
