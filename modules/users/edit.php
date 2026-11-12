<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('admin');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger', 'Invalid user ID.'); redirect('/modules/users/list.php'); }

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { set_flash('danger', 'User not found.'); redirect('/modules/users/list.php'); }

$roles = $pdo->query("SELECT id, name FROM roles ORDER BY id")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name     = trim($_POST['full_name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $role_id       = (int)($_POST['role_id'] ?? 0);
    $department_id = (int)($_POST['department_id'] ?? 0) ?: null;

    if ($full_name === '') $errors[] = "Full name is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
    if ($role_id <= 0) $errors[] = "Role is required.";

    $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
    $chk->execute([$email, $id]);
    if ($chk->fetch()) $errors[] = "Email already used by another user.";

    if (!$errors) {
        $stmt = $pdo->prepare("
            UPDATE users SET full_name=?, email=?, phone=?, role_id=?, department_id=?
            WHERE id=?
        ");
        $stmt->execute([$full_name, $email, $phone ?: null, $role_id, $department_id, $id]);

        log_action($pdo, 'update_user', 'users', $id, 'Updated user: ' . $email);

        set_flash('success', 'User updated successfully.');
        redirect('/modules/users/list.php');
    }

    // Repopulate form with POST data on error
    $user = array_merge($user, [
        'full_name'     => $full_name,
        'email'         => $email,
        'phone'         => $phone,
        'role_id'       => $role_id,
        'department_id' => $department_id,
    ]);
}

include __DIR__ . '/../../includes/header.php';
?>

<h3 class="mb-4">Edit User: <?= val($user, 'full_name') ?></h3>

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
                 value="<?= val($user, 'full_name') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Email *</label>
          <input type="email" name="email" class="form-control" required
                 value="<?= val($user, 'email') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control"
                 value="<?= val($user, 'phone') ?>"
                 placeholder="+251...">
        </div>
        <div class="col-md-6">
          <label class="form-label">Role *</label>
          <select name="role_id" class="form-select" required>
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int)$r['id'] ?>"
                <?= (int)($user['role_id'] ?? 0) === (int)$r['id'] ? 'selected' : '' ?>>
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
                <?= (int)($user['department_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
                <?= e($d['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary">Save Changes</button>
        <a href="<?= BASE_URL ?>/modules/users/list.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>