<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_login();

refresh_overdue($pdo);
$settings = get_settings($pdo);
$me       = current_user($pdo);

/* ---------- headline numbers (counted in SQL, not in PHP loops) ---------- */
$totalTitles     = (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();
$totalCopies     = (int) $pdo->query('SELECT COALESCE(SUM(copies),0) FROM books')->fetchColumn();
$availableCopies = (int) $pdo->query('SELECT COALESCE(SUM(available),0) FROM books')->fetchColumn();
$onLoan          = max(0, $totalCopies - $availableCopies);

$totalMembers  = (int) $pdo->query('SELECT COUNT(*) FROM members WHERE status = "active"')->fetchColumn();
$studentCount  = (int) $pdo->query('SELECT COUNT(*) FROM members WHERE type = "Student" AND status = "active"')->fetchColumn();
$facultyCount  = (int) $pdo->query('SELECT COUNT(*) FROM members WHERE type = "Faculty" AND status = "active"')->fetchColumn();

$activeLoans  = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE status != 'returned'")->fetchColumn();
$overdueLoans = (int) $pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'overdue'")->fetchColumn();

$borrowedToday = (int) $pdo->query('SELECT COUNT(*) FROM transactions WHERE borrow_date = CURDATE()')->fetchColumn();
$returnedToday = (int) $pdo->query('SELECT COUNT(*) FROM transactions WHERE return_date = CURDATE()')->fetchColumn();

/* ---------- outstanding fines ---------- */
$overdueRows = $pdo->query("SELECT * FROM transactions WHERE status = 'overdue'")->fetchAll();
$totalFines  = 0.0;
foreach ($overdueRows as $t) { $totalFines += tx_fine($t, $settings['fine_rate']); }

/* ---------- attention list: overdue first, then due in the next 7 days ---------- */
$attention = $pdo->query("SELECT t.*, b.title AS book_title,
                                 DATEDIFF(t.due_date, CURDATE()) AS days_left
                          FROM transactions t
                          LEFT JOIN books b ON b.id = t.book_id
                          WHERE t.status != 'returned'
                            AND DATEDIFF(t.due_date, CURDATE()) <= 7
                          ORDER BY t.due_date ASC
                          LIMIT 6")->fetchAll();

/* ---------- most borrowed titles ---------- */
$popular = $pdo->query("SELECT b.title, COUNT(t.id) AS loans
                        FROM transactions t
                        JOIN books b ON b.id = t.book_id
                        GROUP BY t.book_id, b.title
                        ORDER BY loans DESC, b.title ASC
                        LIMIT 5")->fetchAll();
$maxLoans = 1;
foreach ($popular as $p) { $maxLoans = max($maxLoans, (int) $p['loans']); }

/* ---------- recent activity ---------- */
$recent = $pdo->query("SELECT t.*, b.title AS book_title
                       FROM transactions t
                       LEFT JOIN books b ON b.id = t.book_id
                       ORDER BY t.id DESC
                       LIMIT 6")->fetchAll();

$hour     = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = trim(explode(' ', trim($me['name'] ?? 'there'))[0]);

$activePage = 'dashboard';
$pageTitle  = 'Dashboard';
$eyebrow    = $settings['library_name'];
$flashMsg   = flash_get();
require __DIR__ . '/includes/header.php';
?>

<div class="dash-head">
  <div>
    <p class="dash-greeting"><?= e($greeting) ?>, <?= e($firstName) ?>.</p>
    <p class="dash-date"><?= date('l, j F Y') ?> &middot; <?= $borrowedToday ?> borrowed and <?= $returnedToday ?> returned today</p>
  </div>
  <div class="dash-quick">
    <a class="btn btn-primary" href="borrow.php">Borrow a Book</a>
    <a class="btn btn-light" href="return.php">Return a Book</a>
    <a class="btn btn-light" href="members.php?open=add">New Member</a>
  </div>
</div>

<div class="metric-row">
  <div class="metric">
    <p class="metric-label">Available Copies</p>
    <p class="metric-value"><?= $availableCopies ?></p>
    <p class="metric-sub"><?= $onLoan ?> on loan &middot; <?= $totalTitles ?> titles</p>
    <div class="meter"><span style="width:<?= $totalCopies ? round(($availableCopies / $totalCopies) * 100) : 0 ?>%"></span></div>
  </div>

  <div class="metric">
    <p class="metric-label">Active Loans</p>
    <p class="metric-value"><?= $activeLoans ?></p>
    <p class="metric-sub">Currently in circulation</p>
    <div class="meter"><span style="width:<?= $totalCopies ? round(($activeLoans / max(1, $totalCopies)) * 100) : 0 ?>%"></span></div>
  </div>

  <div class="metric <?= $overdueLoans ? 'alert' : '' ?>">
    <p class="metric-label">Overdue</p>
    <p class="metric-value"><?= $overdueLoans ?></p>
    <p class="metric-sub">
      <?php if ($overdueLoans): ?>
        <a class="link" href="history.php?status=overdue">Review overdue loans</a>
      <?php else: ?>
        Nothing past its due date
      <?php endif; ?>
    </p>
  </div>

  <div class="metric">
    <p class="metric-label">Outstanding Fines</p>
    <p class="metric-value">&#8369;<?= number_format($totalFines, 2) ?></p>
    <p class="metric-sub">At &#8369;<?= number_format((float) $settings['fine_rate'], 2) ?> per day late</p>
  </div>
</div>

<div class="dash-grid">
  <div>
    <div class="card table-card" style="margin-bottom:16px;">
      <div class="card-head">
        <div>
          <h4>Needs Attention</h4>
          <p class="card-sub">Overdue loans and anything due within the next 7 days</p>
        </div>
        <a href="return.php" class="link">Process returns</a>
      </div>
      <?php if (!$attention): ?>
        <p class="dash-empty">Nothing is overdue or due soon. The shelves are in good shape.</p>
      <?php else: ?>
        <ul class="due-list">
          <?php foreach ($attention as $t):
                $late = (int) $t['days_left'] < 0; ?>
            <li>
              <div class="due-day <?= $late ? 'late' : '' ?>">
                <b><?= date('j', strtotime($t['due_date'])) ?></b>
                <small><?= date('M', strtotime($t['due_date'])) ?></small>
              </div>
              <div class="due-body">
                <strong><?= e($t['book_title'] ?? 'Unknown title') ?></strong>
                <small><?= e($t['member_name']) ?> &middot;
                  <?php if ($late): ?>
                    <?= abs((int) $t['days_left']) ?> day<?= abs((int) $t['days_left']) === 1 ? '' : 's' ?> overdue
                  <?php elseif ((int) $t['days_left'] === 0): ?>
                    due today
                  <?php else: ?>
                    due in <?= (int) $t['days_left'] ?> day<?= (int) $t['days_left'] === 1 ? '' : 's' ?>
                  <?php endif; ?>
                </small>
              </div>
              <?= status_badge($t['status']) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="card table-card">
      <div class="card-head">
        <div><h4>Recent Activity</h4><p class="card-sub">The last few checkouts and returns</p></div>
        <a href="history.php" class="link">View all</a>
      </div>
      <table>
        <thead><tr><th>Loan</th><th>Book</th><th>Member</th><th>Due</th><th>Status</th></tr></thead>
        <tbody>
          <?php if (!$recent): ?>
            <tr><td colspan="5" class="table-empty">No transactions recorded yet.</td></tr>
          <?php else: foreach ($recent as $t): ?>
            <tr>
              <td class="mono"><?= e(tx_code($t['id'])) ?></td>
              <td><?= e($t['book_title'] ?? '&mdash;') ?></td>
              <td><?= e($t['member_name']) ?></td>
              <td class="mono muted"><?= e($t['due_date']) ?></td>
              <td><?= status_badge($t['status']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <div class="card" style="margin-bottom:16px;">
      <h4 style="margin-top:0;">Membership</h4>
      <p class="card-sub" style="margin-bottom:16px;">Active borrowers on record</p>
      <p class="metric-value" style="font-size:32px;"><?= $totalMembers ?></p>
      <div class="stat-divider"></div>
      <p class="stat-foot">Students: <b><?= $studentCount ?></b></p>
      <p class="stat-foot">Faculty: <b><?= $facultyCount ?></b></p>
      <p class="stat-foot">Loan limit per member: <b><?= (int) $settings['max_loans'] ?></b></p>
      <a class="link" href="members.php" style="display:inline-block;margin-top:14px;">Manage members</a>
    </div>

    <div class="card">
      <h4 style="margin-top:0;">Most Borrowed</h4>
      <p class="card-sub" style="margin-bottom:16px;">All-time loan counts</p>
      <?php if (!$popular): ?>
        <p class="dash-empty" style="padding:8px 0;">No loans recorded yet.</p>
      <?php else: foreach ($popular as $p): ?>
        <div class="genre-row">
          <span class="bar-label" title="<?= e($p['title']) ?>"><?= e($p['title']) ?></span>
          <div class="bar-track"><div class="bar-fill" style="width:<?= round(((int) $p['loans'] / $maxLoans) * 100) ?>%"></div></div>
          <span class="bar-count mono"><?= (int) $p['loans'] ?></span>
        </div>
      <?php endforeach; endif; ?>
      <a class="link" href="reports.php" style="display:inline-block;margin-top:10px;">Full reports</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
