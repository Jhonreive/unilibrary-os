<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_login();
$canManage = can('members.manage');

$formAction = post_str('form', '', 30);

/* ---------------------- ADD MEMBER ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formAction === 'add_member') {
    csrf_verify();
    require_perm('members.manage');
    $schoolId = post_str('school_id', '', 30);
    $name     = post_str('name', '', 100);
    $type     = in_array($_POST['type'] ?? '', ['Student', 'Faculty'], true) ? $_POST['type'] : 'Student';
    $email    = strtolower(post_str('email', '', 191));

    if (!$schoolId || !$name || !$email || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,29}$/', $schoolId) || !valid_email($email)) {
        flash_set('Enter a School ID (letters, digits, dashes), a name, and a valid email address.');
        old_set(['school_id' => $schoolId, 'name' => $name, 'type' => $type, 'email' => $email]);
        header('Location: members.php?open=add');
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO members (school_id, name, type, email, joined, status)
                               VALUES (?,?,?,?,CURDATE(),'active')");
        $stmt->execute([$schoolId, $name, $type, $email]);
        flash_set($name . ' added as a member.');
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') {
            flash_set('School ID ' . $schoolId . ' is already registered to another member.');
            old_set(['school_id' => $schoolId, 'name' => $name, 'type' => $type, 'email' => $email]);
            header('Location: members.php?open=add');
            exit;
        }
        error_log('add_member failed: ' . $ex->getMessage());
        flash_set('Could not add that member. Please try again.');
    }
    header('Location: members.php');
    exit;
}

/* ---------------------- EDIT MEMBER ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formAction === 'edit_member') {
    csrf_verify();
    require_perm('members.manage');
    $id       = post_int('id', 0);
    $schoolId = post_str('school_id', '', 30);
    $name     = post_str('name', '', 100);
    $type     = in_array($_POST['type'] ?? '', ['Student', 'Faculty'], true) ? $_POST['type'] : 'Student';
    $email    = strtolower(post_str('email', '', 191));
    $status   = in_array($_POST['status'] ?? '', ['active', 'suspended'], true) ? $_POST['status'] : 'active';

    if (!$id || !$name || !valid_email($email) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,29}$/', $schoolId)) {
        flash_set('Enter a School ID (letters, digits, dashes), a name, and a valid email address.');
    } else {
        try {
            // keep the denormalised name on past loans in step with the rename
            $old = $pdo->prepare('SELECT name FROM members WHERE id = ?');
            $old->execute([$id]);
            $oldName = $old->fetchColumn();

            $pdo->prepare('UPDATE members SET school_id = ?, name = ?, type = ?, email = ?, status = ? WHERE id = ?')
                ->execute([$schoolId, $name, $type, $email, $status, $id]);

            if ($oldName && $oldName !== $name) {
                $pdo->prepare('UPDATE transactions SET member_name = ? WHERE member_id = ? OR member_name = ?')
                    ->execute([$name, $id, $oldName]);
            }
            flash_set('Updated ' . $name . '.');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                flash_set('School ID ' . $schoolId . ' belongs to a different member.');
            } else {
                error_log('edit_member failed: ' . $ex->getMessage());
                flash_set('Could not save those changes. Please try again.');
            }
        }
    }
    header('Location: members.php');
    exit;
}

/* ---------------------- DELETE MEMBER ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formAction === 'delete_member') {
    csrf_verify();
    require_perm('members.manage');
    $id = post_int('id', 0);

    $stmt = $pdo->prepare('SELECT name FROM members WHERE id = ?');
    $stmt->execute([$id]);
    $name = $stmt->fetchColumn();

    if (!$name) {
        flash_set('That member no longer exists.');
    } else {
        $open = open_loans_for_member($pdo, $id, $name);
        if ($open > 0) {
            flash_set($name . ' still has ' . $open . ' book' . ($open === 1 ? '' : 's') . ' on loan. Process the return first.');
        } else {
            $pdo->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
            flash_set($name . ' removed from the directory.');
        }
    }
    header('Location: members.php');
    exit;
}

/* ---------------------- SEARCH / FILTER ---------------------- */
$q    = get_str('q');
$type = get_str('type', 'all', 12);

$sql    = 'SELECT * FROM members WHERE 1=1';
$params = [];

if (in_array($type, ['Student', 'Faculty'], true)) {
    $sql .= ' AND type = ?';
    $params[] = $type;
}
if ($q !== '') {
    $sql .= ' AND (school_id LIKE ? OR name LIKE ? OR email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$sql .= ' ORDER BY name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

$totalMembers = (int) $pdo->query('SELECT COUNT(*) FROM members')->fetchColumn();

$openAdd = isset($_GET['open']) && $_GET['open'] === 'add';
$old     = old_get();

$activePage = 'members';
$pageTitle  = 'Members Directory';
$eyebrow    = 'People';
$flashMsg   = flash_get();
require __DIR__ . '/includes/header.php';
?>

<form class="page-head-row" method="get" action="members.php" role="search">
  <div class="search-bar toolbar-search">
    <input type="search" name="q" data-suggest="members" value="<?= e($q) ?>" aria-label="Search members"
           placeholder="Search by School ID, name, or email&hellip;">
    <?php if ($q !== ''): ?>
      <a class="search-clear" href="members.php<?= $type !== 'all' ? '?type=' . urlencode($type) : '' ?>" title="Clear search" aria-label="Clear search">&times;</a>
    <?php endif; ?>
    <button type="submit" class="search-submit">Search</button>
  </div>
  <select name="type" class="status-select" data-autosubmit aria-label="Filter by member type">
    <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All types</option>
    <option value="Student" <?= $type === 'Student' ? 'selected' : '' ?>>Students</option>
    <option value="Faculty" <?= $type === 'Faculty' ? 'selected' : '' ?>>Faculty</option>
  </select>
  <?php if ($canManage): ?><button type="button" class="btn btn-primary" data-open-modal="addMemberModal">Add Member</button><?php endif; ?>
</form>

<p class="search-meta">
  Showing <b><?= count($members) ?></b> of <b><?= $totalMembers ?></b> members<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.
</p>

<div class="card table-card">
  <table>
    <thead>
      <tr>
        <th>School ID</th><th>Name</th><th>Type</th><th>Email</th><th>Joined</th><th>Status</th>
        <th class="table-actions-col">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$members): ?>
        <tr><td colspan="7" class="table-empty">No members match your search.</td></tr>
      <?php else: foreach ($members as $m): ?>
        <tr>
          <td class="mono"><?= e($m['school_id']) ?></td>
          <td><?= e($m['name']) ?></td>
          <td><span class="badge"><?= e($m['type']) ?></span></td>
          <td class="muted"><?= e($m['email']) ?></td>
          <td class="mono muted"><?= e($m['joined']) ?></td>
          <td>
            <?= $m['status'] === 'active'
                  ? '<span class="badge ok">Active</span>'
                  : '<span class="badge warn">Suspended</span>' ?>
          </td>
          <td class="table-actions-col">
            <?php if ($canManage): ?>
            <div class="row-actions">
              <button type="button" class="btn-sm"
                      data-edit-member
                      data-id="<?= (int) $m['id'] ?>"
                      data-school-id="<?= e($m['school_id']) ?>"
                      data-name="<?= e($m['name']) ?>"
                      data-type="<?= e($m['type']) ?>"
                      data-email="<?= e($m['email']) ?>"
                      data-status="<?= e($m['status']) ?>">Edit</button>
              <form class="inline-form" method="post" action="members.php"
                    data-confirm="Remove <?= e($m['name']) ?> from the directory? This cannot be undone.">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="delete_member">
                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <button type="submit" class="btn-sm danger">Remove</button>
              </form>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if ($canManage): ?>
<!-- ---------------------- ADD MODAL ---------------------- -->
<div class="modal <?= $openAdd ? '' : 'hidden' ?>" id="addMemberModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Add Member</h3>
      <button type="button" data-close-modal aria-label="Close">&times;</button>
    </div>
    <form method="post" action="members.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="add_member">
      <div class="form-group">
        <label for="mf-school">School ID</label>
        <input type="text" id="mf-school" name="school_id" required value="<?= e($old['school_id'] ?? '') ?>"
               placeholder="e.g. 2026-00451 or FAC-2026-03">
        <p class="field-hint">Required for both students and faculty. Must be unique.</p>
      </div>
      <div class="form-group">
        <label for="mf-name">Full Name</label>
        <input type="text" id="mf-name" name="name" required value="<?= e($old['name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="mf-type">Type</label>
        <select id="mf-type" name="type">
          <option value="Student" <?= ($old['type'] ?? '') === 'Faculty' ? '' : 'selected' ?>>Student</option>
          <option value="Faculty" <?= ($old['type'] ?? '') === 'Faculty' ? 'selected' : '' ?>>Faculty</option>
        </select>
      </div>
      <div class="form-group">
        <label for="mf-email">Email</label>
        <input type="email" id="mf-email" name="email" required value="<?= e($old['email'] ?? '') ?>">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Save Member</button>
    </form>
  </div>
</div>

<!-- ---------------------- EDIT MODAL ---------------------- -->
<div class="modal hidden" id="editMemberModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Edit Member</h3>
      <button type="button" data-close-modal aria-label="Close">&times;</button>
    </div>
    <form method="post" action="members.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="edit_member">
      <input type="hidden" name="id" id="em-id">
      <div class="form-group">
        <label for="em-school">School ID</label>
        <input type="text" id="em-school" name="school_id" required>
      </div>
      <div class="form-group">
        <label for="em-name">Full Name</label>
        <input type="text" id="em-name" name="name" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="em-type">Type</label>
          <select id="em-type" name="type">
            <option value="Student">Student</option>
            <option value="Faculty">Faculty</option>
          </select>
        </div>
        <div class="form-group">
          <label for="em-status">Status</label>
          <select id="em-status" name="status">
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label for="em-email">Email</label>
        <input type="email" id="em-email" name="email" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
