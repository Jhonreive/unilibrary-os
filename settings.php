<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require_login();

$me      = current_user($pdo);
$isAdmin = ($me['role'] ?? '') === 'Administrator';
$form    = post_str('form', '', 30);

/* ---------------------- MY PROFILE (everyone) ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form === 'save_profile') {
    csrf_verify();
    $name  = post_str('name', '', 100);
    $email = strtolower(post_str('email', '', 191));

    if (!valid_name($name, 100) || !valid_email($email)) {
        flash_set('Enter your name and a valid email address.');
    } else {
        try {
            $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')
                ->execute([$name, $email, $me['id']]);
            flash_set('Profile updated.');
        } catch (PDOException $ex) {
            flash_set($ex->getCode() === '23000'
                ? 'That email is already used by another account.'
                : 'Could not save your profile. Please try again.');
        }
    }
    header('Location: settings.php');
    exit;
}

/* ---------------------- CHANGE PASSWORD (everyone) ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form === 'change_password') {
    csrf_verify();
    $current = post_raw('current_password');
    $new     = post_raw('new_password');
    $confirm = post_raw('confirm_password');

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$me['id']]);
    $hash = $stmt->fetchColumn();

    if (rl_blocked($pdo, 'pwchange', (string) $me['id'], 5, 900)) {
        flash_set('Too many attempts. Please wait a few minutes and try again.');
    } elseif (!$hash || !password_verify($current, $hash)) {
        rl_add($pdo, 'pwchange', (string) $me['id']);
        audit('password_change_failed');
        flash_set('Your current password is not correct.');
    } elseif ($problems = password_problems($new, $me['email'], $me['name'])) {
        flash_set(password_policy_message($problems));
    } elseif ($new !== $confirm) {
        flash_set('The two new password fields do not match.');
    } elseif (hash_equals($current, $new)) {
        flash_set('Your new password must be different from the current one.');
    } else {
        $newHash = pw_hash($new);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $me['id']]);
        // Keep THIS session alive, new id; every other session of this user is now invalid.
        session_regenerate_id(true);
        $_SESSION['pwv'] = pw_version($newHash);
        rl_clear($pdo, 'pwchange', (string) $me['id']);
        audit('password_changed');
        flash_set('Password changed. Any other devices were signed out.');
    }
    header('Location: settings.php');
    exit;
}

/* ---------------------- LIBRARY PREFERENCES (admin only) ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form === 'save_settings') {
    csrf_verify();
    require_perm('settings.manage');

    $libraryName = post_str('library_name', '', 100) ?: 'University Library';
    $fineRaw     = $_POST['fine_rate'] ?? '0.5';
    $fineRate    = (is_string($fineRaw) && is_numeric($fineRaw)) ? max(0, min(10000, round((float) $fineRaw, 2))) : 0.5;
    $loanPeriod  = max(1, min(365, post_int('loan_period', 14)));
    $maxLoans    = max(1, min(50, post_int('max_loans', 3)));
    $allowSignup = isset($_POST['allow_signup']) ? 1 : 0;

    $pdo->prepare('UPDATE settings SET library_name = ?, fine_rate = ?, loan_period = ?, max_loans = ?, allow_signup = ? WHERE id = 1')
        ->execute([$libraryName, $fineRate, $loanPeriod, $maxLoans, $allowSignup]);
    audit('settings_changed');
    flash_set('Library settings saved.');
    header('Location: settings.php');
    exit;
}

$settings = get_settings($pdo);

$activePage = 'settings';
$pageTitle  = 'Settings';
$eyebrow    = 'System';
$flashMsg   = flash_get();
require __DIR__ . '/includes/header.php';
?>

<div class="settings-grid">

  <!-- ---------------------- MY ACCOUNT ---------------------- -->
  <div class="card">
    <h4 style="margin-top:0;">My Account</h4>
    <p class="card-sub" style="margin-bottom:18px;">
      Signed in as <b><?= e($me['role']) ?></b> (<?= e(user_code($me['id'])) ?>).
    </p>
    <form method="post" action="settings.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="save_profile">
      <div class="form-group">
        <label for="pf-name">Display Name</label>
        <input type="text" id="pf-name" name="name" required maxlength="100" value="<?= e($me['name']) ?>">
      </div>
      <div class="form-group">
        <label for="pf-email">Email</label>
        <input type="email" id="pf-email" name="email" required maxlength="191" value="<?= e($me['email']) ?>">
        <p class="field-hint">This is the address you sign in with.</p>
      </div>
      <button type="submit" class="btn btn-primary">Save Profile</button>
    </form>
  </div>

  <!-- ---------------------- PASSWORD ---------------------- -->
  <div class="card">
    <h4 style="margin-top:0;">Change Password</h4>
    <p class="card-sub" style="margin-bottom:18px;">At least 10 characters, mixing at least 3 of: lowercase, UPPERCASE, numbers, symbols.</p>
    <form method="post" action="settings.php" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="change_password">
      <div class="form-group">
        <label for="pw-current">Current Password</label>
        <input type="password" id="pw-current" name="current_password" required maxlength="256" autocomplete="current-password">
      </div>
      <div class="form-group">
        <label for="pw-new">New Password</label>
        <input type="password" id="pw-new" name="new_password" required minlength="10" maxlength="128" autocomplete="new-password">
      </div>
      <div class="form-group">
        <label for="pw-confirm">Confirm New Password</label>
        <input type="password" id="pw-confirm" name="confirm_password" required minlength="10" maxlength="128" autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-primary">Update Password</button>
    </form>
  </div>

  <!-- ---------------------- LIBRARY PREFERENCES ---------------------- -->
  <?php if ($isAdmin): ?>
  <div class="card" style="grid-column:1 / -1;">
    <h4 style="margin-top:0;">Library Preferences</h4>
    <p class="card-sub" style="margin-bottom:18px;">
      These apply to everyone. Loan period and limit are used when issuing new loans; the fine rate is applied to overdue books.
    </p>
    <form method="post" action="settings.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="save_settings">
      <div class="form-group">
        <label for="libName">Library Name</label>
        <input type="text" id="libName" name="library_name" value="<?= e($settings['library_name']) ?>">
        <p class="field-hint">Shown above the page title on the dashboard.</p>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="fineRate">Overdue Fine Rate (&#8369; per day)</label>
          <input type="number" id="fineRate" name="fine_rate" step="0.10" min="0"
                 value="<?= number_format((float) $settings['fine_rate'], 2, '.', '') ?>">
        </div>
        <div class="form-group">
          <label for="loanPeriod">Default Loan Period (days)</label>
          <input type="number" id="loanPeriod" name="loan_period" min="1" max="365"
                 value="<?= (int) $settings['loan_period'] ?>">
        </div>
      </div>
      <div class="form-group">
        <label for="maxLoans">Maximum Books per Member</label>
        <input type="number" id="maxLoans" name="max_loans" min="1" max="50"
               value="<?= (int) $settings['max_loans'] ?>">
        <p class="field-hint">Borrowing is blocked once a member reaches this many open loans.</p>
      </div>
      <div class="check-row">
        <input type="checkbox" id="allowSignup" name="allow_signup" value="1" <?= (int) $settings['allow_signup'] ? 'checked' : '' ?>>
        <div>
          <label for="allowSignup">Allow staff self sign-up</label>
          <p class="field-hint">New accounts are created as Staff and stay inactive until an administrator approves them on the Users page.</p>
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Save Library Settings</button>
    </form>
  </div>
  <?php else: ?>
  <div class="card" style="grid-column:1 / -1;">
    <h4 style="margin-top:0;">Library Preferences</h4>
    <p class="card-sub">
      Fine rate, loan period, and borrowing limits are managed by an administrator.
      Current values: &#8369;<?= number_format((float) $settings['fine_rate'], 2) ?>/day,
      <?= (int) $settings['loan_period'] ?>-day loans, up to <?= (int) $settings['max_loans'] ?> books per member.
    </p>
  </div>
  <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
