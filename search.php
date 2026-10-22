<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_login();

const SR_CAP        = 30;   // most rows fetched per group
const SR_PREVIEW    = 5;    // rows per group on the "Everything" tab

$q     = get_str('q');
$scope = get_str('scope', 'all', 12);
if (!in_array($scope, ['all', 'books', 'members', 'loans'], true)) $scope = 'all';

$bookHits = $memberHits = $txHits = [];

if ($q !== '') {
    $like = '%' . $q . '%';

    // always search every group so the tabs can show how many each has
    $stmt = $pdo->prepare("SELECT * FROM books
                           WHERE title LIKE ? OR author LIKE ? OR genre LIKE ?
                              OR CONCAT('BK-', LPAD(id, 3, '0')) LIKE ?
                              OR isbn LIKE ? OR isbn = ?
                           ORDER BY title ASC LIMIT 31");   // SR_CAP + 1 (fixed literal, no concatenation)
    $qClean   = isbn_clean($q);
    $isbnLike = $qClean !== '' ? '%' . $qClean . '%' : $like;   // partial ISBN, hyphens ignored
    $stmt->execute([$like, $like, $like, $like, $isbnLike, isbn_normalize($q) ?: '']);
    $bookHits = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM members
                           WHERE name LIKE ? OR school_id LIKE ? OR email LIKE ?
                           ORDER BY name ASC LIMIT 31');
    $stmt->execute([$like, $like, $like]);
    $memberHits = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT t.*, b.title AS book_title FROM transactions t
                           LEFT JOIN books b ON b.id = t.book_id
                           WHERE t.member_name LIKE ? OR b.title LIKE ?
                              OR CONCAT('LN-', 8000 + t.id) LIKE ?
                           ORDER BY t.id DESC LIMIT 31");
    $stmt->execute([$like, $like, $like]);
    $txHits = $stmt->fetchAll();
}

$groups = [
    'books'   => ['label' => 'Books',   'rows' => $bookHits],
    'members' => ['label' => 'Members', 'rows' => $memberHits],
    'loans'   => ['label' => 'Loans',   'rows' => $txHits],
];
foreach ($groups as $k => $g) {
    $n = count($g['rows']);
    $groups[$k]['count'] = min($n, SR_CAP);
    $groups[$k]['capped'] = $n > SR_CAP;
    $groups[$k]['rows'] = array_slice($g['rows'], 0, SR_CAP);
}
$total = array_sum(array_column($groups, 'count'));
$anyCapped = in_array(true, array_column($groups, 'capped'), true);

function sr_count($g) { return $g['count'] . ($g['capped'] ? '+' : ''); }
function sr_url($q, $scope) {
    return 'search.php?' . http_build_query(['q' => $q] + ($scope === 'all' ? [] : ['scope' => $scope]));
}

$activePage = 'search';
$pageTitle  = 'Search';
$eyebrow    = 'System';
$flashMsg   = flash_get();
require __DIR__ . '/includes/header.php';
?>

<form class="sr-form" method="get" action="search.php" role="search">
  <div class="search-bar sr-bar">
    <input type="search" id="searchPageInput" name="q" value="<?= e($q) ?>" data-suggest="all"
           <?= $q === '' ? 'autofocus' : '' ?> aria-label="Search everything"
           placeholder="Search books, ISBNs, members, School IDs, loan IDs&hellip;">
    <?php if ($q !== ''): ?>
      <a class="search-clear" href="search.php" title="Clear search" aria-label="Clear search">&times;</a>
    <?php endif; ?>
    <button type="submit" class="search-submit">Search</button>
  </div>
  <?php if ($scope !== 'all'): ?><input type="hidden" name="scope" value="<?= e($scope) ?>"><?php endif; ?>
</form>

<?php if ($q === ''): ?>

  <div class="card sr-empty">
    <h4>Search the whole system</h4>
    <p>One box finds books, members and loans. Try any of these:</p>
    <div class="sr-chips">
      <span class="sr-chip">Book title or author</span>
      <span class="sr-chip">ISBN <span class="mono">9780132350884</span></span>
      <span class="sr-chip">Book code <span class="mono">BK-001</span></span>
      <span class="sr-chip">Member name</span>
      <span class="sr-chip">School ID <span class="mono">2025-00141</span></span>
      <span class="sr-chip">Loan ID <span class="mono">LN-8001</span></span>
    </div>
  </div>

<?php else: ?>

  <div class="sr-tabs" role="group" aria-label="Filter results">
    <a class="sr-tab <?= $scope === 'all' ? 'active' : '' ?>" href="<?= e(sr_url($q, 'all')) ?>">
      Everything <span><?= $total ?><?= $anyCapped ? '+' : '' ?></span>
    </a>
    <?php foreach ($groups as $key => $g): ?>
      <a class="sr-tab <?= $scope === $key ? 'active' : '' ?> <?= $g['count'] === 0 ? 'zero' : '' ?>"
         href="<?= e(sr_url($q, $key)) ?>">
        <?= e($g['label']) ?> <span><?= e(sr_count($g)) ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <p class="sr-summary">
    <b><?= $total ?><?= $anyCapped ? '+' : '' ?></b> result<?= $total === 1 ? '' : 's' ?> for &ldquo;<?= e($q) ?>&rdquo;
  </p>

  <?php
    $shown = 0;   // groups actually drawn
    foreach ($groups as $key => $g):
        if ($scope !== 'all' && $scope !== $key) continue;
        if ($g['count'] === 0) continue;
        $shown++;
        $rows    = $scope === 'all' ? array_slice($g['rows'], 0, SR_PREVIEW) : $g['rows'];
        $hidden  = $g['count'] - count($rows);
  ?>
    <section class="card sr-card">
      <div class="sr-head">
        <h4><?= e($g['label']) ?></h4>
        <small><?= e(sr_count($g)) ?> found</small>
      </div>

      <?php foreach ($rows as $r): ?>

        <?php if ($key === 'books'):
              $href  = 'books.php?q=' . urlencode(!empty($r['isbn']) ? $r['isbn'] : $r['title']);
              $free  = (int) $r['available']; ?>
          <a class="sr-row" href="<?= e($href) ?>">
            <span class="sr-tile">BK</span>
            <span class="sr-main">
              <strong class="sr-title"><?= hl($r['title'], $q) ?></strong>
              <span class="sr-meta">
                <?= hl($r['author'], $q) ?>
                <?php if (!empty($r['isbn'])): ?><i>&middot;</i><span class="mono">ISBN <?= hl($r['isbn'], $qClean !== '' ? $qClean : $q) ?></span><?php endif; ?>
                <i>&middot;</i><span class="mono"><?= e(book_code($r['id'])) ?></span>
              </span>
            </span>
            <span class="sr-side">
              <span class="badge <?= $free > 0 ? 'ok' : 'danger' ?>"><?= $free > 0 ? 'Available' : 'All out' ?></span>
              <small><?= $free ?> of <?= (int) $r['copies'] ?> copies</small>
            </span>
            <span class="sr-go" aria-hidden="true">&rsaquo;</span>
          </a>

        <?php elseif ($key === 'members'): ?>
          <a class="sr-row" href="members.php?q=<?= urlencode($r['school_id']) ?>">
            <span class="sr-tile round"><?= e(person_initials($r['name'])) ?></span>
            <span class="sr-main">
              <strong class="sr-title"><?= hl($r['name'], $q) ?></strong>
              <span class="sr-meta">
                <span class="mono"><?= hl($r['school_id'], $q) ?></span>
                <i>&middot;</i><?= hl($r['email'], $q) ?>
              </span>
            </span>
            <span class="sr-side">
              <span class="badge"><?= e($r['type']) ?></span>
              <?php if (($r['status'] ?? 'active') !== 'active'): ?><small><?= e(ucfirst($r['status'])) ?></small><?php endif; ?>
            </span>
            <span class="sr-go" aria-hidden="true">&rsaquo;</span>
          </a>

        <?php else: ?>
          <a class="sr-row" href="history.php?q=<?= urlencode(tx_code($r['id'])) ?>">
            <span class="sr-tile">LN</span>
            <span class="sr-main">
              <strong class="sr-title"><?= hl(tx_code($r['id']), $q) ?> &mdash; <?= hl($r['book_title'] ?? 'Unknown title', $q) ?></strong>
              <span class="sr-meta"><?= hl($r['member_name'], $q) ?><i>&middot;</i>due <?= e($r['due_date']) ?></span>
            </span>
            <span class="sr-side"><?= status_badge($r['status']) ?></span>
            <span class="sr-go" aria-hidden="true">&rsaquo;</span>
          </a>
        <?php endif; ?>

      <?php endforeach; ?>

      <?php if ($hidden > 0): ?>
        <a class="sr-more" href="<?= e(sr_url($q, $key)) ?>">
          Show all <?= e(sr_count($g)) ?> <?= e(strtolower($g['label'])) ?> &rarr;
        </a>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <?php if ($total === 0): ?>
    <div class="card sr-empty">
      <h4>Nothing matched &ldquo;<?= e($q) ?>&rdquo;</h4>
      <p>Check the spelling, or try a shorter piece of the name, title or ISBN.</p>
    </div>
  <?php elseif ($shown === 0): ?>
    <div class="card sr-empty">
      <h4>No <?= e(strtolower($groups[$scope]['label'])) ?> matched &ldquo;<?= e($q) ?>&rdquo;</h4>
      <p>But there are <?= $total ?><?= $anyCapped ? '+' : '' ?> result<?= $total === 1 ? '' : 's' ?> in other places.
         <a href="<?= e(sr_url($q, 'all')) ?>" style="color:var(--accent);font-weight:600;">Show everything</a></p>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
