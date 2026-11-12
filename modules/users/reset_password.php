<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('admin');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger', 'Invalid user ID.'); redirect('/modules/users/list.php'); }

$stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { set_flash('danger', 'User not found.'); redirect('/modules/users/list.php'); }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters.";
    if ($password !== $confirm) $errors[] = "Passwords do not match.";

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?");
        $stmt->execute([$hash, $id]);

        log_action($pdo, 'reset_password', 'users', $id, 'Reset password for: ' . $user['email']);
        set_flash('success', 'Password reset successfully.');
        redirect('/modules/users/list.php');
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<h3 class="mb-4">Reset Password for: <?= e($user['full_name']) ?></h3>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card" style="max-width:600px">
  <div class="card-body">
    <p class="text-muted small mb-3">User: <strong><?= e($user['email']) ?></strong></p>
    <form method="post" novalidate>
      <div class="mb-3">
        <label class="form-label">New Password</label>
        <input type="password" name="password" class="form-control" required minlength="6">
      </div>
      <div class="mb-3">
        <label class="form-label">Confirm Password</label>
        <input type="password" name="password_confirm" class="form-control" required minlength="6">
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-warning">Reset Password</button>
        <a href="<?= BASE_URL ?>/modules/users/list.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>