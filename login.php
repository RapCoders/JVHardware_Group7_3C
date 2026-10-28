<?php
require __DIR__ . '/includes/bootstrap.php';
if (current_user()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_SESSION['lock_until'] ?? 0) > time()) {
        $error = 'Too many wrong attempts. Please wait ' . ($_SESSION['lock_until'] - time()) . ' seconds.';
    } else {
        $st = db_q('SELECT * FROM users WHERE username=? AND is_active=1', [trim($_POST['username'] ?? '')]);
        $u = $st->fetch();
        if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['fails'] = 0;
            redirect('index.php');
        }
        $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
        if ($_SESSION['fails'] >= 5) { $_SESSION['lock_until'] = time() + 30; $_SESSION['fails'] = 0; }
        $error = 'Wrong username or password.';
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in · JV Hardware</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@700;800;900&family=Roboto:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= (int) filemtime(__DIR__ . '/assets/style.css') ?>"></head>
<body>
<?php include __DIR__ . '/includes/icons.php'; ?>
<div class="login-datetime" aria-label="Current date and time"><span id="login-date"></span><i></i><strong id="login-time"></strong></div>
<div class="login">
  <section class="login-art" aria-label="About JV Hardware">
    <a class="login-brand" href="login.php" aria-label="JV Hardware home">
      <img src="assets/jv-hardware-logo.png" alt="JV Hardware — Build, Repair, Improve">
    </a>
    <div class="login-promo">
      <h2>Know your<br>stock.<br><em class="typewriter" aria-label="Sell fast."><span class="typewriter-text" aria-hidden="true"></span><span class="sr-only">Sell fast.</span></em></h2>
      <p>Our store’s inventory and sales system. Every sale updates the shelf count, so there’s no more notebook counting.</p>
    </div>
  </section>
  <div class="login-form">
    <div class="login-card">
      <h1>Welcome Back!</h1>
      <p class="sub2">Access your account to continue.</p>
      <?php if ($error): ?><div class="err-box"><?= e($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label class="f login-field">
          <span class="sr-only">Username or Email</span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
          <input type="text" name="username" placeholder="Username or Email" required autocomplete="off" autocapitalize="none" spellcheck="false" readonly>
        </label>
        <label class="f login-field password-field">
          <span class="sr-only">Password</span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3M12 14v3"/></svg>
          <input type="password" id="login-password" name="password" placeholder="Password" required autocomplete="off" readonly>
          <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" aria-controls="login-password">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="eye-slash" d="m3 3 18 18"/></svg>
          </button>
        </label>
        <label class="remember-me"><input type="checkbox" name="remember_me"><span>Remember me</span></label>
        <button class="btn primary lg login-submit" type="submit"><span>Log In</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg></button>
      </form>
    </div>
  </div>
</div>
<script>
document.querySelector('.password-toggle').addEventListener('click', function () {
  var field = document.getElementById('login-password');
  var visible = field.type === 'password';
  field.type = visible ? 'text' : 'password';
  this.setAttribute('aria-pressed', String(visible));
  this.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
  field.focus();
});
var loginForm = document.querySelector('.login-card form');
loginForm.querySelectorAll('input').forEach(function (field) {
  field.addEventListener('focus', function () { this.removeAttribute('readonly'); }, { once: true });
});
loginForm.addEventListener('pointerdown', function () {
  loginForm.querySelectorAll('input').forEach(function (field) { field.removeAttribute('readonly'); });
}, { once: true });
loginForm.addEventListener('keydown', function (event) {
  if (event.key === 'Enter') {
    loginForm.querySelectorAll('input').forEach(function (field) { field.removeAttribute('readonly'); });
  }
});
function updateLoginClock() {
  var now = new Date();
  document.getElementById('login-date').textContent = new Intl.DateTimeFormat('en-US', {weekday:'long', month:'long', day:'numeric', year:'numeric'}).format(now);
  document.getElementById('login-time').textContent = new Intl.DateTimeFormat('en-US', {hour:'2-digit', minute:'2-digit'}).format(now);
}
updateLoginClock();
window.setInterval(updateLoginClock, 30000);
// Loop the highlighted phrase while reserving its full width to keep the hero still.
(function () {
  var target = document.querySelector('.typewriter-text');
  if (!target) return;
  var phrase = 'Sell fast.';
  var position = 0;
  var deleting = false;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    target.textContent = phrase;
    return;
  }
  function tick() {
    if (!deleting && position === phrase.length) {
      deleting = true;
      window.setTimeout(tick, 1500);
      return;
    }
    if (deleting && position === 0) {
      deleting = false;
      window.setTimeout(tick, 350);
      return;
    }
    position += deleting ? -1 : 1;
    target.textContent = phrase.slice(0, position);
    window.setTimeout(tick, deleting ? 55 : 95);
  }
  tick();
})();
</script>
</body></html>
