<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('admin');

// Filters
$roleFilter   = $_GET['role']   ?? '';
$statusFilter = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');

$sql = "SELECT u.id, u.full_name, u.email, u.phone, u.is_active, u.last_login, u.created_at,
               r.name AS role_name, d.name AS dept_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE 1=1";
$params = [];

if ($roleFilter !== '') {
    $sql .= " AND r.name = ?";
    $params[] = $roleFilter;
}
if ($statusFilter === 'active') {
    $sql .= " AND u.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $sql .= " AND u.is_active = 0";
}
if ($search !== '') {
    $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY u.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Roles for filter dropdown
$roles = $pdo->query("SELECT name FROM roles ORDER BY id")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Manage Users</h3>
  <a href="<?= BASE_URL ?>/modules/users/create.php" class="btn btn-primary">
    + Add New User
  </a>
</div>

<!-- Filters -->
<form class="card card-body mb-3" method="get">
  <div class="row g-2">
    <div class="col-md-4">
      <input type="text" name="q" class="form-control"
             placeholder="Search name or email..."
             value="<?= e($search) ?>">
    </div>
    <div class="col-md-3">
      <select name="role" class="form-select">
        <option value="">— All Roles —</option>
        <?php foreach ($roles as $r): ?>
          <option value="<?= e($r['name']) ?>" <?= $roleFilter === $r['name'] ? 'selected' : '' ?>>
            <?= e(ucfirst($r['name'])) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select name="status" class="form-select">
        <option value="">— All Status —</option>
        <option value="active"   <?= $statusFilter === 'active'   ? 'selected' : '' ?>>Active</option>
        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
      </select>
    </div>
    <div class="col-md-2 d-grid">
      <button class="btn btn-outline-primary">Filter</button>
    </div>
  </div>
</form>

<!-- Users table -->
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Department</th>
          <th>Status</th>
          <th>Last Login</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No users found.</td></tr>
      <?php else: $i = 1; foreach ($users as $u): ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><strong><?= e($u['full_name']) ?></strong></td>
          <td><?= e($u['email']) ?></td>
          <td><span class="badge bg-info text-dark"><?= e(ucfirst($u['role_name'])) ?></span></td>
          <td><?= e($u['dept_name'] ?? '—') ?></td>
          <td>
            <?php if ($u['is_active']): ?>
              <span class="badge bg-success">Active</span>
            <?php else: ?>
              <span class="badge bg-secondary">Inactive</span>
            <?php endif; ?>
          </td>
          <td>
            <?= $u['last_login'] ? e(date('d M Y H:i', strtotime($u['last_login']))) : '—' ?>
          </td>
          <td class="text-end">
            <div class="btn-group btn-group-sm">
              <a href="<?= BASE_URL ?>/modules/users/edit.php?id=<?= (int)$u['id'] ?>"
                 class="btn btn-outline-primary">Edit</a>
              <a href="<?= BASE_URL ?>/modules/users/reset_password.php?id=<?= (int)$u['id'] ?>"
                 class="btn btn-outline-warning">Reset PW</a>
              <a href="<?= BASE_URL ?>/modules/users/toggle_active.php?id=<?= (int)$u['id'] ?>"
                 class="btn btn-outline-<?= $u['is_active'] ? 'danger' : 'success' ?>"
                 onclick="return confirm('Are you sure?');">
                <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
              </a>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>