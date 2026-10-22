<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

// Already logged in? Don't show the login form again.
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$settings = get_settings($pdo);
$signupOn = (int) ($settings['allow_signup'] ?? 1) === 1;
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email    = strtolower(post_str('email', '', 191));
    $password = post_raw('password');
    $ip       = client_ip();

    // Three independent limits: per IP, per IP+account, and per account overall.
    if (rl_blocked($pdo, 'login_ip', $ip, 30, 900)
        || rl_blocked($pdo, 'login_acct', $ip . '|' . $email, 5, 900)
        || rl_blocked($pdo, 'login_email', $email, 20, 900)) {
        audit('login_throttled', 'email=' . substr($email, 0, 60));
        header('Retry-After: 900');
        http_response_code(429);
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, email, role, status, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always run one hash verification, even for unknown emails, so response
        // time doesn't reveal which addresses have accounts.
        $dummy = '$2y$12$jvnFsiytEwLEeDvk6eJERe7MfENBaM6K.iK8vrlB8ljLxIQc4Nezu';
        $hash  = ($user && $user['password_hash']) ? $user['password_hash'] : $dummy;
        $good  = password_verify($password, $hash) && $user && $user['password_hash'];

        // Same generic error whether the email doesn't exist, the password
        // hasn't been set yet, or the password is wrong - never reveal which.
        if (!$good) {
            rl_add($pdo, 'login_ip', $ip);
            rl_add($pdo, 'login_acct', $ip . '|' . $email);
            rl_add($pdo, 'login_email', $email);
            audit('login_failed', 'email=' . substr($email, 0, 60));
            $error = 'Incorrect email or password.';
        } elseif ($user['status'] !== 'active') {
            $error = 'This account is not active yet. Ask an administrator to activate it.';
        } else {
            rl_clear($pdo, 'login_acct', $ip . '|' . $email);

            // Quietly upgrade old hashes (bcrypt cost 10 etc.) to Argon2id.
            if (pw_needs_rehash($user['password_hash'])) {
                $user['password_hash'] = pw_hash($password);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$user['password_hash'], $user['id']]);
            }

            $redirect = $_SESSION['redirect_after_login'] ?? 'dashboard.php';
            auth_login_user($user);
            audit('login_ok');
            unset($_SESSION['redirect_after_login']);

            // Only ever redirect to a local page, never to an attacker-supplied URL.
            if (!is_string($redirect) || $redirect === '' || !preg_match('#^/?[A-Za-z0-9_./-]+\.php(\?[A-Za-z0-9_=&%.+-]*)?$#', $redirect)
                || str_starts_with($redirect, '//') || str_contains($redirect, '..')) {
                $redirect = 'dashboard.php';
            }
            header('Location: ' . $redirect);
            exit;
        }
    }
}
$error = $error ?: (string) ($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f6f0e7">
<title>Sign in — UniLibrary OS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  --bg: #f6f0e7; --surface: #ffffff; --ink: #2b2118; --ink-soft: #6e5c4c;
  --ink-faint: #9c8a78; --line: #e4d9c8; --accent: #8a6f55; --accent-hover: #6b4f38;
  --accent-ink: #ffffff; --danger: #c23b2b; --radius-sm: 8px; --radius-lg: 22px;
  --font-display: "Hanken Grotesk", sans-serif; --font-body: "Inter", sans-serif;
  --font-mono: "JetBrains Mono", monospace;
}
* { box-sizing: border-box; }
a, a:hover, a:visited { text-decoration: none; }
html, body { height: 100%; }
body { margin: 0; font-family: var(--font-body); color: var(--ink); background: var(--bg); -webkit-font-smoothing: antialiased; }
button, input { font: inherit; color: inherit; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 6px; }
.form-group input { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: var(--radius-sm); background: var(--surface); }
.form-group input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
.btn-block { width: 100%; display: block; text-align: center; padding: 11px 16px; border-radius: var(--radius-sm); border: none; cursor: pointer; font-weight: 600; }
.btn-primary { background: var(--accent); color: var(--accent-ink); }
.btn-primary:hover { background: var(--accent-hover); }

.login-screen {
  position: fixed; inset: 0; display: flex; align-items: center; justify-content: center;
  background: radial-gradient(circle at 15% 20%, rgba(107, 79, 56, 0.07), transparent 45%),
              radial-gradient(circle at 85% 80%, rgba(138, 111, 85, 0.07), transparent 45%), var(--bg);
  padding: 24px;
}
@property --ang { syntax: '<angle>'; inherits: false; initial-value: 0deg; }

/* ---------- the card ---------- */
.card-tilt { position: relative; z-index: 1; width: 100%; max-width: 410px; transform: perspective(900px) rotateX(var(--rx, 0deg)) rotateY(var(--ry, 0deg)); transition: transform .25s ease-out; will-change: transform; }
.login-card {
  position: relative; width: 100%; border-radius: var(--radius-lg); padding: 38px 34px 28px;
  background: linear-gradient(160deg, rgba(255,255,255,.97), rgba(255,250,242,.9));
  -webkit-backdrop-filter: blur(14px) saturate(1.3); backdrop-filter: blur(14px) saturate(1.3);
  border: 1px solid rgba(255,255,255,.7);
  box-shadow: 0 2px 4px rgba(43,33,24,.05), 0 18px 40px rgba(107,79,56,.16), 0 50px 90px rgba(107,79,56,.18), inset 0 1px 0 #fff;
  opacity: 0; animation: card-in .9s cubic-bezier(.2,.8,.2,1) .05s forwards;
}
@keyframes card-in { from { opacity: 0; transform: translateY(34px) scale(.95); filter: blur(6px); } to { opacity: 1; transform: none; filter: none; } }
/* glowing border that sweeps around the card */
.login-card::before {
  content: ""; position: absolute; inset: 0; border-radius: inherit; padding: 1.5px; pointer-events: none;
  background: conic-gradient(from var(--ang), transparent 0deg, #e3c79f 60deg, #8a6f55 120deg, transparent 190deg, transparent 360deg);
  -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor;
  mask: linear-gradient(#000 0 0) content-box exclude, linear-gradient(#000 0 0);
  animation: spin 7s linear infinite;
}
@keyframes spin { to { --ang: 360deg; } }
/* soft light that follows the cursor */
.login-card .spot { position: absolute; inset: 0; border-radius: inherit; pointer-events: none; opacity: 0; transition: opacity .3s;
  background: radial-gradient(320px circle at var(--mx, 50%) var(--my, 0%), rgba(227,199,159,.30), transparent 65%); }
.login-card:hover .spot { opacity: 1; }
.login-card > *:not(.spot) { position: relative; z-index: 1; }

/* staggered entrance for each piece of the card */
.rise { opacity: 0; transform: translateY(14px); animation: rise .7s cubic-bezier(.2,.8,.2,1) forwards; animation-delay: calc(var(--i, 0) * 80ms + 250ms); }
@keyframes rise { to { opacity: 1; transform: none; } }

/* brand */
.login-brand { display: flex; align-items: center; gap: 13px; margin-bottom: 26px; }
.login-brand .brand-icon { position: relative; width: 44px; height: 44px; border-radius: 13px; color: #fff; display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #b99571, #6b4f38); box-shadow: 0 8px 20px rgba(107,79,56,.4), inset 0 1px 0 rgba(255,255,255,.35); animation: float 4s ease-in-out infinite; overflow: hidden; }
.login-brand .brand-icon::after { content: ""; position: absolute; top: -50%; left: -80%; width: 50%; height: 200%; background: linear-gradient(90deg, transparent, rgba(255,255,255,.55), transparent); transform: rotate(20deg); animation: glint 4.5s ease-in-out 1.2s infinite; }
@keyframes float { 50% { transform: translateY(-4px); } }
@keyframes glint { 0% { left: -80%; } 35%, 100% { left: 160%; } }
.login-brand h1 { margin: 0; font-family: var(--font-display); font-weight: 800; font-size: 20px; letter-spacing: -.01em; }
.login-brand span { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.14em; color: var(--ink-faint); }

.login-title { margin: 0 0 6px; font-family: var(--font-display); font-weight: 800; font-size: 28px; letter-spacing: -.02em;
  background: linear-gradient(90deg, #2b2118 30%, #8a6f55 60%, #2b2118 90%); background-size: 220% 100%; -webkit-background-clip: text; background-clip: text; color: transparent; animation: shine 6s linear infinite; }
@keyframes shine { to { background-position: -220% 0; } }
.login-sub { margin: 0 0 24px; font-size: 13.5px; color: var(--ink-soft); }

/* inputs */
.field { position: relative; }
.field .ico { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); width: 17px; height: 17px; color: var(--ink-faint); transition: color .2s, transform .2s; pointer-events: none; z-index: 2; }
.login-card .form-group input { padding: 12px 14px 12px 40px; border: 1.5px solid var(--line); background: rgba(255,255,255,.85); border-radius: 11px; transition: border-color .2s, box-shadow .25s, background .2s, transform .2s; }
.login-card .form-group input:hover { border-color: #cdb99f; }
.login-card .form-group input:focus { outline: none; border-color: var(--accent); background: #fff; box-shadow: 0 0 0 4px rgba(138,111,85,.16), 0 8px 18px rgba(107,79,56,.10); transform: translateY(-1px); }
.field:focus-within .ico { color: var(--accent); transform: translateY(-50%) scale(1.12); }
.password-wrap input { padding-right: 62px !important; }
.toggle-pw { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: none; background: transparent; cursor: pointer; font-size: 11.5px; font-weight: 700; color: var(--accent); padding: 6px 8px; border-radius: 6px; z-index: 2; }
.toggle-pw:hover { background: rgba(138,111,85,.1); }

/* button */
.btn-go { position: relative; overflow: hidden; display: flex !important; align-items: center; justify-content: center; gap: 8px; margin-top: 4px; padding: 13px 16px; border-radius: 12px; color: #fff; font-weight: 700; letter-spacing: .01em; cursor: pointer;
  background: linear-gradient(135deg, #a0825f, #6b4f38); box-shadow: 0 10px 24px rgba(107,79,56,.38), inset 0 1px 0 rgba(255,255,255,.25); transition: transform .2s, box-shadow .2s; }
.btn-go::before { content: ""; position: absolute; top: 0; left: -120%; width: 60%; height: 100%; background: linear-gradient(100deg, transparent, rgba(255,255,255,.38), transparent); transform: skewX(-20deg); transition: left .7s; }
.btn-go:hover { transform: translateY(-2px); box-shadow: 0 16px 32px rgba(107,79,56,.46), inset 0 1px 0 rgba(255,255,255,.25); }
.btn-go:hover::before { left: 140%; }
.btn-go:active { transform: translateY(0) scale(.98); }
.btn-go .arr { width: 18px; height: 18px; transition: transform .25s; }
.btn-go:hover .arr { transform: translateX(5px); }
.btn-go .spin { display: none; width: 18px; height: 18px; border: 2.5px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: rot .7s linear infinite; }
.btn-go.loading { pointer-events: none; filter: saturate(.85); }
.btn-go.loading .arr { display: none; }
.btn-go.loading .spin { display: block; }
@keyframes rot { to { transform: rotate(360deg); } }

.login-error { margin: -4px 0 16px; font-size: 12.5px; color: var(--danger); background: #fbe4e0; border: 1px solid #f3c4bc; border-radius: 10px; padding: 9px 12px; animation: shake .5s cubic-bezier(.36,.07,.19,.97); }
@keyframes shake { 20%, 60% { transform: translateX(-6px); } 40%, 80% { transform: translateX(6px); } }
.login-hint { margin: 16px 0 0; text-align: center; font-size: 12px; color: var(--ink-faint); }
.login-hint a { color: var(--accent); font-weight: 700; position: relative; }
.login-hint a::after { content: ""; position: absolute; left: 0; bottom: -2px; width: 100%; height: 1.5px; background: currentColor; transform: scaleX(0); transform-origin: left; transition: transform .25s; }
.login-hint a:hover { color: var(--accent-hover); }
.login-hint a:hover::after { transform: scaleX(1); }
.login-divider { margin: 20px 0 0; height: 1px; border: 0; background: linear-gradient(90deg, transparent, var(--line), transparent); }

/* ---------- fits every screen: scrolls on short phones, snug on narrow ones ---------- */
.login-screen { position: fixed; inset: 0; height: 100vh; height: 100dvh; overflow-y: auto; -webkit-overflow-scrolling: touch;
  padding: max(24px, env(safe-area-inset-top)) max(24px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(24px, env(safe-area-inset-left)); }
.login-screen .card-tilt { margin: auto; }
.bg-anim { position: fixed; }
.form-group input, .password-wrap input { font-size: 16px; }
@media (max-width: 480px) { .login-card { padding: 30px 22px 24px; } .login-title { font-size: 25px; } .orb { filter: blur(50px); } }
@media (max-height: 560px) { .login-brand { margin-bottom: 16px; } .login-sub { margin-bottom: 16px; } }
@media (hover: none) { .card-tilt { transform: none !important; } }

@media (prefers-reduced-motion: reduce) {
  .login-card, .rise { animation: none; opacity: 1; transform: none; }
  .login-card::before, .brand-icon, .brand-icon::after, .login-title { animation: none; }
  .card-tilt { transform: none !important; }
}

/* ---------- background animation: books drifting up + soft glows ---------- */
.bg-anim { position: absolute; inset: 0; overflow: hidden; pointer-events: none; z-index: 0; }

.bg-anim .orb { position: absolute; border-radius: 50%; filter: blur(70px); will-change: transform; animation: orb-drift 24s ease-in-out infinite alternate; }
.bg-anim .orb.a { width: 420px; height: 420px; left: -110px; top: -90px; background: #c9a97f; opacity: 0.38; }
.bg-anim .orb.b { width: 360px; height: 360px; right: -90px; bottom: -70px; background: #a3573f; opacity: 0.16; animation-duration: 30s; animation-delay: -9s; }
@keyframes orb-drift { to { transform: translate3d(70px, 50px, 0) scale(1.18); } }

.bg-anim .bk {
  position: absolute; bottom: -150px; left: var(--x);
  width: var(--w); height: calc(var(--w) * 1.38);
  background: var(--c);
  border-radius: 3px 7px 7px 3px;
  box-shadow: inset 5px 0 0 rgba(0, 0, 0, 0.16), inset -2px 0 0 rgba(255, 255, 255, 0.2);
  filter: blur(var(--b));
  opacity: 0;
  will-change: transform, opacity;
  animation: bk-rise var(--d) linear var(--delay) infinite;
}
.bg-anim .bk::before, .bg-anim .bk::after { content: ""; position: absolute; background: rgba(255, 255, 255, 0.36); border-radius: 2px; }
.bg-anim .bk::before { left: 24%; right: 14%; top: 18%; height: 13%; }
.bg-anim .bk::after  { left: 24%; right: 34%; top: 38%; height: 6%; background: rgba(255, 255, 255, 0.24); }
@keyframes bk-rise {
  0%   { transform: translate3d(0, 0, 0) rotate(var(--r0)); opacity: 0; }
  12%  { opacity: var(--o); }
  88%  { opacity: var(--o); }
  100% { transform: translate3d(var(--dx), -135vh, 0) rotate(var(--r1)); opacity: 0; }
}

/* people who ask their system for less motion get a calm, still page */
@media (prefers-reduced-motion: reduce) { .bg-anim { display: none; } }
</style>
</head>
<body>

<div class="login-screen">
  <div class="bg-anim" aria-hidden="true">
    <span class="orb a"></span><span class="orb b"></span>
    <?php
      // fixed seed = the same pleasant layout every visit
      $bookColors = ['#8a6f55', '#b79a7a', '#6b4f38', '#c9a97f', '#a3573f', '#7d8a6b', '#d8c3a5'];
      mt_srand(11);
      $bookCount = 16;
      for ($i = 0; $i < $bookCount; $i++) {
          $w   = mt_rand(34, 76);
          $dur = mt_rand(22, 40);
          printf('<span class="bk" style="--x:%d%%;--w:%dpx;--c:%s;--d:%ds;--delay:-%ds;--r0:%ddeg;--r1:%ddeg;--dx:%dpx;--o:%s;--b:%spx"></span>',
              max(0, min(95, (int) ($i * 100 / $bookCount) + mt_rand(-2, 3))),
              $w,
              $bookColors[$i % count($bookColors)],
              $dur,
              mt_rand(0, $dur),
              mt_rand(-25, 25),
              mt_rand(-70, 70),
              mt_rand(-60, 60),
              number_format(mt_rand(28, 52) / 100, 2, '.', ''),
              $w < 48 ? '1.4' : '0');
      }
    ?>
  </div>
  <div class="card-tilt" id="tilt">
  <div class="login-card" id="card">
    <span class="spot"></span>
    <div class="login-brand rise" style="--i:0">
      <?php $__logo = logo_url(); ?>
      <?php if ($__logo !== ''): ?><img src="<?= e($__logo) ?>" alt="UniLibrary logo" style="width:44px;height:44px;border-radius:13px;object-fit:contain;background:#fff">
      <?php else: ?><div class="brand-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 5.5C4.5 4 8 4 12 6c4-2 7.5-2 10-.5V19c-2.5-1.5-6-1.5-10 .5-4-2-7.5-2-10-.5z"/><path d="M12 6v13.5"/></svg>
      </div><?php endif; ?>
      <div><h1>UniLibrary</h1><span>LIBRARY OS</span></div>
    </div>
    <h2 class="login-title rise" style="--i:1">Welcome back</h2>
    <p class="login-sub rise" style="--i:2">Use your library staff account to continue.</p>

    <?php if ($error): ?>
      <div class="login-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" autocomplete="off" id="loginForm">
      <?= csrf_field() ?>
      <div class="form-group rise" style="--i:3">
        <label for="email">Email</label>
        <div class="field">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="m3.5 7 8.5 6 8.5-6"/></svg>
          <input type="email" id="email" name="email" required autofocus autocomplete="username" maxlength="191" value="<?= e(post_str('email', '', 191)) ?>">
        </div>
      </div>
      <div class="form-group rise" style="--i:4">
        <label for="password">Password</label>
        <div class="field password-wrap">
          <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10.5" width="16" height="10" rx="3"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/></svg>
          <input type="password" id="password" name="password" required autocomplete="current-password" maxlength="256">
          <button type="button" class="toggle-pw" id="togglePw" aria-label="Show password">Show</button>
        </div>
      </div>
      <button type="submit" class="btn-go rise" id="goBtn" style="--i:5">
        <span class="lbl">Sign In</span>
        <svg class="arr" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        <span class="spin"></span>
      </button>
    </form>

    <?php if ($signupOn): ?>
      <div class="login-divider rise" style="--i:6"></div>
      <p class="login-hint rise" style="--i:6">Don't have an account yet? <a href="signup.php">Create one</a></p>
    <?php endif; ?>

    <p class="login-hint rise" style="--i:7">Forgot your password? Ask a library administrator to reset it.</p>
  </div>
  </div>
</div>

<script nonce="<?= csp_nonce() ?>">
  // show / hide password
  document.getElementById('togglePw').addEventListener('click', function () {
    var input = document.getElementById('password');
    var showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    this.textContent = showing ? 'Show' : 'Hide';
    this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
  });

  // button spinner while signing in
  document.getElementById('loginForm').addEventListener('submit', function () {
    var b = document.getElementById('goBtn');
    b.classList.add('loading');
    b.querySelector('.lbl').textContent = 'Signing in…';
  });

  // gentle 3D tilt + cursor spotlight (skipped for touch and reduced-motion users)
  var calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches || window.matchMedia('(hover: none)').matches;
  if (!calm) {
    var tilt = document.getElementById('tilt'), card = document.getElementById('card');
    document.addEventListener('mousemove', function (e) {
      var r = tilt.getBoundingClientRect();
      var px = (e.clientX - (r.left + r.width / 2)) / window.innerWidth;
      var py = (e.clientY - (r.top + r.height / 2)) / window.innerHeight;
      tilt.style.setProperty('--ry', (px * 9).toFixed(2) + 'deg');
      tilt.style.setProperty('--rx', (-py * 9).toFixed(2) + 'deg');
      card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
      card.style.setProperty('--my', (e.clientY - r.top) + 'px');
    });
    document.addEventListener('mouseleave', function () {
      tilt.style.setProperty('--rx', '0deg'); tilt.style.setProperty('--ry', '0deg');
    });
  }
</script>
</body>
</html>
