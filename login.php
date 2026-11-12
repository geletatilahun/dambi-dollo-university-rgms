<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

if (current_user()) redirect('/index.php');

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = "Please enter your university email and password.";
    } elseif (attempt_login($pdo, $email, $password)) {
        set_flash('success', 'Welcome back, ' . current_user()['full_name'] . '!');
        redirect('/index.php');
    } else {
        $error = "Invalid email or password.";
    }
}

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sign in — <?= SITE_NAME ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<div class="login-split">

  <!-- ============ LEFT PANEL ============ -->
  <div class="login-left">
    <div class="login-brand">
      <div class="login-brand-logo">DDU</div>
      <div class="login-brand-text">
        <h5>DDU–RGMS</h5>
        <small>Research Grant Management System</small>
      </div>
    </div>

    <div class="login-hero">
      <div class="login-hero-tag">Research · Innovation · Impact</div>
      <h1 class="login-hero-title">Turn great ideas into meaningful impact.</h1>
      <p class="login-hero-sub">
        A central workspace for managing research grant applications,
        reviewing proposals, tracking awards, and supporting research
        excellence at Dambi Dollo University.
      </p>
      <ul class="login-features">
        <li>
          <span class="check">✓</span>
          Submit and track research grant applications
        </li>
        <li>
          <span class="check">✓</span>
          Coordinate reviews, approvals, and award progress
        </li>
        <li>
          <span class="check">✓</span>
          Keep research information organized in one place
        </li>
      </ul>
    </div>

    <div class="login-footer">
      <span>© <?= date('Y') ?> Dambi Dollo University</span>
      <span>Research &amp; Innovation</span>
    </div>
  </div>

  <!-- ============ RIGHT PANEL ============ -->
  <div class="login-right">
    <div class="login-form-wrap">

      <div class="login-form-eyebrow">Welcome Back</div>
      <h2 class="login-form-title">Sign in to your account</h2>
      <p class="login-form-sub">
        Enter your university credentials to access your research workspace.
      </p>

      <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> d-flex align-items-center">
          <span class="me-2">
            <?= $flash['type'] === 'success' ? '✅' : ($flash['type'] === 'danger' ? '⚠️' : 'ℹ️') ?>
          </span>
          <?= e($flash['message']) ?>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center">
          <span class="me-2">⚠️</span>
          <?= e($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" novalidate>

        <!-- Email -->
        <div class="mb-3 position-relative">
          <label for="email">University email or username</label>
          <input type="text" name="email" id="email"
                 class="form-control pe-5"
                 placeholder="e.g. researcher@ddu.edu.et"
                 value="<?= e($_POST['email'] ?? '') ?>"
                 required autofocus>
          <span class="login-input-icon">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
                 viewBox="0 0 24 24"><path d="M4 4h16v16H4z" stroke="none"/><path d="M22 6l-10 7L2 6"/><rect x="2" y="4" width="20" height="16" rx="2"/></svg>
          </span>
        </div>

        <!-- Password -->
        <div class="mb-2">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <label for="password" class="mb-0">Password</label>
            <a href="#" class="login-forgot"
               onclick="alert('Contact the Research Office: research@ddu.edu.et'); return false;">
              Forgot password?
            </a>
          </div>
          <div class="position-relative">
            <input type="password" name="password" id="password"
                   class="form-control pe-5"
                   placeholder="Enter your password"
                   required>
            <button type="button" class="login-show-btn" onclick="togglePwd()" tabindex="-1">
              <span id="pwdToggleText">Show</span>
            </button>
          </div>
        </div>

        <!-- Remember + Authorized -->
        <div class="login-remember">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="remember" id="remember">
            <label class="form-check-label" for="remember">Remember me</label>
          </div>
          <span class="authorized">Authorized users only</span>
        </div>

        <!-- Sign in -->
        <button class="btn-signin mb-0">
          Sign in →
        </button>
      </form>

      <div class="login-divider">Need access?</div>

      <div class="login-help-box">
        <div class="login-help-icon">?</div>
        <div>
          <h6>Need an account or assistance?</h6>
          <p>Contact the university Research and Community Service office or
             your system administrator for access support.</p>
        </div>
      </div>

      <p class="login-trust">
        🔒 Your session should be accessed on a trusted device.
      </p>
    </div>
  </div>

</div>

<script>
function togglePwd() {
    const p = document.getElementById('password');
    const t = document.getElementById('pwdToggleText');
    if (p.type === 'password') {
        p.type = 'text';
        t.textContent = 'Hide';
    } else {
        p.type = 'password';
        t.textContent = 'Show';
    }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>