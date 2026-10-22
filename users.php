<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_login();
require_role($pdo, ['Administrator']);

$me   = current_user($pdo);
$form = post_str('form', '', 30);

/** Stop the last administrator from being demoted, suspended, or deleted. */
function admin_count(PDO $pdo) {
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Administrator' AND status = 'active'")->fetchColumn();
}

/* ---------------------- ADD USER ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form === 'add_user') {
    csrf_verify();
    $name  = post_str('name', '', 100);
    $email = strtolower(post_str('email', '', 191));
    $role  = in_array($_POST['role'] ?? '', ['Administrator', 'Librarian', 'Staff'], true) ? $_POST['role'] : 'Staff';

    if (!valid_name($name, 100) || !valid_email($email)) {
        flash_set('Enter a name (100 characters max) and a valid email address.');
    } else {
        try {
            $pdo->prepare("INSERT INTO users (name, email, role, status) VALUES (?,?,?,'invited')")
                ->execute([$name, $email, $role]);
            audit('user_added', 'email=' . substr($email, 0, 60) . ' role=' . $role);
            flash_set($name . ' invited as ' . $role . '. They need a password set before signing in.');
        } catch (PDOException $ex) {
            flash_set($ex->getCode() === '23000'
                ? 'That email is already registered to another user.'
                : 'Could not add that user. Please try again.');
        }
    }
    header('Location: users.php');
    exit;
}

/* ---------------------- EDIT USER (name, email, role) ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form === 'edit_user') {
    csrf_verify();
    $id    = post_int('id');
    $name  = post_str('name', '', 100);
    $email = strtolower(post_str('email', '', 191));
    $role  = in_array($_POST['role'] ?? '', ['Administrator', 'Librarian', 'Staff'], true) ? $_POST['role'] : 'Staff';

    $stmt = $pdo->prepare('SELECT role, status FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();

    if (!$target) {
        flash_set('That user no longer exists.');
    } elseif (!valid_name($name, 100) || !valid_email($email)) {
        flash_set('Enter a name (100 characters max) and a valid email address.');
    } elseif ($target['role'] === 'Administrator' && $role !== 'Administrator'
              && $target['status'] === 'active' && admin_count($pdo) <= 1) {
        flash_set('This is the only active administrator. Promote someone else first.');
    } else {
        try {
            $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?')
                ->execute([$name, $email, $role, $id]);
            if ($target['role'] !== $role) audit('user_role_changed', 'target=' . $id . ' ' . $target['role'] . '->' . $role);
            flash_set('Updated ' . $name . '.');
        } catch (PDOException $ex) {
            flash_set($ex->getCode() === '23000'
                ? 'That email is already registered to another user.'
                : 'Could not save those changes. Please try again.');
        }
    }
    header('Location: users.php');
    exit;
}

/* ---------------------- ACTIVATE / SUSPEND ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form === 'toggle_user') {
    csrf_verify();
    $id = post_int('id');

    $stmt = $pdo->prepare('SELECT name, role, status, password_hash FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();

    if (!$u) {
        flash_set('That user no longer exists.');
    } elseif ($id === (int) $me['id']) {
        flash_set('You cannot deactivate your own account.');
    } elseif ($u['status'] === 'active') {
        if ($u['role'] === 'Administrator' && admin_count($pdo) <= 1) {
            flash_set('This is the only active administrator, so they cannot be suspended.');
        } else {
            $pdo->prepare("UPDATE users SET status = 'invited' WHERE id = ?")->execute([$id]);
            audit('user_deactivated', 'target=' . $id);
            flash_set($u['name'] . ' deactivated. They can no longer sign in.');
        }
    } else {
        if (!$u['password_hash']) {
            flash_set($u['name'] . ' has no password yet. They need to sign up or have one set via setup.php before activating.');
        } else {
            $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);
            audit('user_activated', 'target=' . $id);
            flash_set($u['name'] . ' approved and can now sign in.');
        }
    }
    header('Location: users.php');
    exit;
}

/* ---------------------- SET / RESET PASSWORD ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form === 'set_password') {
    csrf_verify();
    $id      = post_int('id');
    $new     = post_raw('new_password');
    $confirm = post_raw('confirm_password');

    $stmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    $name = $target['name'] ?? '';

    if (!rl_hit($pdo, 'pwreset_admin', (string) $me['id'], 10, 900)) {
        flash_set('Too many password resets in a short time. Wait a few minutes.');
    } elseif (!$name) {
        flash_set('That user no longer exists.');
    } elseif ($problems = password_problems($new, $target['email'], $name)) {
        flash_set(password_policy_message($problems));
    } elseif ($new !== $confirm) {
        flash_set('The two password fields do not match.');
    } else {
        // Changing the hash also ends every other session that user has open.
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([pw_hash($new), $id]);
        audit('password_reset_by_admin', 'target=' . $id);
        flash_set('Password set for ' . $name . '. They can be approved now if they are still pending.');
    }
    header('Location: users.php');
    exit;
}

/* ---------------------- DELETE USER ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form === 'delete_user') {
    csrf_verify();
    $id = post_int('id');

    $stmt = $pdo->prepare('SELECT name, role, status FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();

    if (!$u) {
        flash_set('That user no longer exists.');
    } elseif ($id === (int) $me['id']) {
        flash_set('You cannot delete your own account.');
    } elseif ($u['role'] === 'Administrator' && $u['status'] === 'active' && admin_count($pdo) <= 1) {
        flash_set('This is the only active administrator and cannot be deleted.');
    } else {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        audit('user_deleted', 'target=' . $id);
        flash_set($u['name'] . ' removed.');
    }
    header('Location: users.php');
    exit;
}

/* ---------------------- LIST ---------------------- */
$q = get_str('q');
if ($q !== '') {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE name LIKE ? OR email LIKE ? OR role LIKE ? ORDER BY id ASC');
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    $users = $stmt->fetchAll();
} else {
    $users = $pdo->query('SELECT * FROM users ORDER BY id ASC')->fetchAll();
}

$pending  = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'invited'")->fetchColumn();
$openAdd  = isset($_GET['open']) && $_GET['open'] === 'add';
$settings = get_settings($pdo);

$activePage = 'users';
$pageTitle  = 'Users / Admin';
$eyebrow    = 'System';
$flashMsg   = flash_get();
require __DIR__ . '/includes/header.php';
?>

<form class="page-head-row" method="get" action="users.php" role="search">
  <div class="search-bar toolbar-search">
    <input type="search" name="q" value="<?= e($q) ?>" aria-label="Search users"
           placeholder="Search by name, email, or role&hellip;">
    <?php if ($q !== ''): ?>
      <a class="search-clear" href="users.php" title="Clear search" aria-label="Clear search">&times;</a>
    <?php endif; ?>
    <button type="submit" class="search-submit">Search</button>
  </div>
  <button type="button" class="btn btn-primary" data-open-modal="addUserModal">Add User</button>
</form>

<?php if ($pending): ?>
  <p class="search-meta">
    <b><?= $pending ?></b> account<?= $pending === 1 ? '' : 's' ?> waiting for approval.
    <?= (int) $settings['allow_signup'] ? 'Self sign-up is on.' : 'Self sign-up is off.' ?>
  </p>
<?php endif; ?>

<div class="card table-card">
  <table>
    <thead>
      <tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th class="table-actions-col">Actions</th></tr>
    </thead>
    <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="6" class="table-empty">No users match your search.</td></tr>
      <?php else: foreach ($users as $u): $isSelf = (int) $u['id'] === (int) $me['id']; ?>
        <tr>
          <td class="mono"><?= e(user_code($u['id'])) ?></td>
          <td><?= e($u['name']) ?><?= $isSelf ? ' <span class="badge">You</span>' : '' ?></td>
          <td class="muted"><?= e($u['email']) ?></td>
          <td><span class="badge"><?= e($u['role']) ?></span></td>
          <td>
            <?= $u['status'] === 'active'
                  ? '<span class="badge ok">Active</span>'
                  : '<span class="badge warn">Pending</span>' ?>
          </td>
          <td class="table-actions-col">
            <div class="row-actions">
              <button type="button" class="btn-sm"
                      data-edit-user
                      data-id="<?= (int) $u['id'] ?>"
                      data-name="<?= e($u['name']) ?>"
                      data-email="<?= e($u['email']) ?>"
                      data-role="<?= e($u['role']) ?>">Edit</button>

              <button type="button" class="btn-sm"
                      data-set-password
                      data-id="<?= (int) $u['id'] ?>"
                      data-name="<?= e($u['name']) ?>"><?= $u['password_hash'] ? 'Reset Password' : 'Set Password' ?></button>

              <?php if (!$isSelf): ?>
                <form class="inline-form" method="post" action="users.php">
                  <?= csrf_field() ?>
                  <input type="hidden" name="form" value="toggle_user">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button type="submit" class="btn-sm"><?= $u['status'] === 'active' ? 'Deactivate' : 'Approve' ?></button>
                </form>

                <form class="inline-form" method="post" action="users.php"
                      data-confirm="Delete the account for <?= e($u['name']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="form" value="delete_user">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button type="submit" class="btn-sm danger">Delete</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="modal <?= $openAdd ? '' : 'hidden' ?>" id="addUserModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Add User</h3>
      <button type="button" data-close-modal aria-label="Close">&times;</button>
    </div>
    <form method="post" action="users.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="add_user">
      <div class="form-group">
        <label for="uf-name">Full Name</label>
        <input type="text" id="uf-name" name="name" required maxlength="100">
      </div>
      <div class="form-group">
        <label for="uf-email">Email</label>
        <input type="email" id="uf-email" name="email" required maxlength="191">
      </div>
      <div class="form-group">
        <label for="uf-role">Role</label>
        <select id="uf-role" name="role">
          <option value="Staff">Staff</option>
          <option value="Librarian">Librarian</option>
          <option value="Administrator">Administrator</option>
        </select>
        <p class="field-hint">The account stays pending until a password is set and it is approved.</p>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Save User</button>
    </form>
  </div>
</div>

<div class="modal hidden" id="editUserModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Edit User</h3>
      <button type="button" data-close-modal aria-label="Close">&times;</button>
    </div>
    <form method="post" action="users.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="edit_user">
      <input type="hidden" name="id" id="eu-id">
      <div class="form-group">
        <label for="eu-name">Full Name</label>
        <input type="text" id="eu-name" name="name" required maxlength="100">
      </div>
      <div class="form-group">
        <label for="eu-email">Email</label>
        <input type="email" id="eu-email" name="email" required maxlength="191">
      </div>
      <div class="form-group">
        <label for="eu-role">Role</label>
        <select id="eu-role" name="role">
          <option value="Staff">Staff</option>
          <option value="Librarian">Librarian</option>
          <option value="Administrator">Administrator</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
    </form>
  </div>
</div>

<div class="modal hidden" id="setPasswordModal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>Set Password</h3>
      <button type="button" data-close-modal aria-label="Close">&times;</button>
    </div>
    <form method="post" action="users.php" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="set_password">
      <input type="hidden" name="id" id="sp-id">
      <p class="card-sub" style="margin:0 0 16px;">
        Setting a password for <b id="sp-name"></b>. Give it to them directly and ask
        them to change it in Settings after their first sign-in.
      </p>
      <div class="form-group">
        <label for="sp-new">New Password</label>
        <input type="password" id="sp-new" name="new_password" required minlength="10" maxlength="128" autocomplete="new-password">
      </div>
      <div class="form-group">
        <label for="sp-confirm">Confirm Password</label>
        <input type="password" id="sp-confirm" name="confirm_password" required minlength="10" maxlength="128" autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Save Password</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
