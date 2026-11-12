<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('admin');

$roles = $pdo->query("SELECT id, name FROM roles ORDER BY id")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

$errors = [];
$old = [
  'full_name' => '', 'email' => '', 'phone' => '',
  'role_id' => '', 'department_id' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['full_name']     = trim($_POST['full_name'] ?? '');
    $old['email']         = trim($_POST['email'] ?? '');
    $old['phone']         = trim($_POST['phone'] ?? '');
    $old['role_id']       = (int)($_POST['role_id'] ?? 0);
    $old['department_id'] = (int)($_POST['department_id'] ?? 0) ?: null;
    $password             = $_POST['password'] ?? '';
    $confirm              = $_POST['password_confirm'] ?? '';

    // Validate
    if ($old['full_name'] === '') $errors[] = "Full name is required.";
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
    if ($old['role_id'] <= 0) $errors[] = "Role is required.";
    if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters.";
    if ($password !== $confirm) $errors[] = "Passwords do not match.";

    // Duplicate email?
    $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $chk->execute([$old['email']]);
    if ($chk->fetch()) $errors[] = "Email already registered.";

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (full_name, email, password, role_id, department_id, phone)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $old['full_name'], $old['email'], $hash,
            $old['role_id'], $old['department_id'], $old['phone']
        ]);
        $newId = $pdo->lastInsertId();

        log_action($pdo, 'create_user', 'users', $newId, 'Created user: ' . $old['email']);
        notify($pdo, $newId, 'Welcome to DDU-RGMS',
               'Your account has been created. Please log in and update your profile.');

        set_flash('success', 'User created successfully.');
        redirect('/modules/users/list.php');
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<h3 class="mb-4">Add New User</h3>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post" novalidate>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Full Name *</label>
          <input type="text" name="full_name" class="form-control" required
                 value="<?= e($old['full_name']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Email *</label>
          <input type="email" name="email" class="form-control" required
                 value="<?= e($old['email']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control"
                 placeholder="+251..." value="<?= e($old['phone']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Role *</label>
          <select name="role_id" class="form-select" required>
            <option value="">— Select Role —</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int)$r['id'] ?>"
                <?= $old['role_id'] == $r['id'] ? 'selected' : '' ?>>
                <?= e(ucfirst($r['name'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Department</label>
          <select name="department_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= (int)$d['id'] ?>"
                <?= $old['department_id'] == $d['id'] ? 'selected' : '' ?>>
                <?= e($d['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Password *</label>
          <input type="password" name="password" class="form-control" required minlength="6">
          <small class="text-muted">Minimum 6 characters.</small>
        </div>
        <div class="col-md-6">
          <label class="form-label">Confirm Password *</label>
          <input type="password" name="password_confirm" class="form-control" required minlength="6">
        </div>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary">Create User</button>
        <a href="<?= BASE_URL ?>/modules/users/list.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>