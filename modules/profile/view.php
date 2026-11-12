<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$user = current_user();

$stmt = $pdo->prepare("
    SELECT u.*, r.name AS role_name, d.name AS dept_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.id = ?
");
$stmt->execute([$user['id']]);
$me = $stmt->fetch();

$stmt = $pdo->prepare("SELECT * FROM researcher_profiles WHERE user_id = ?");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">My Profile</h3>
  <a href="<?= BASE_URL ?>/modules/profile/edit.php" class="btn btn-primary">Edit Profile</a>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header"><strong>Account Information</strong></div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4">Full Name</dt><dd class="col-sm-8"><?= e($me['full_name']) ?></dd>
          <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e($me['email']) ?></dd>
          <dt class="col-sm-4">Phone</dt><dd class="col-sm-8"><?= e($me['phone'] ?? '—') ?></dd>
          <dt class="col-sm-4">Role</dt><dd class="col-sm-8"><?= e(ucfirst($me['role_name'])) ?></dd>
          <dt class="col-sm-4">Department</dt><dd class="col-sm-8"><?= e($me['dept_name'] ?? '—') ?></dd>
          <dt class="col-sm-4">Last Login</dt>
          <dd class="col-sm-8">
            <?= $me['last_login'] ? e(date('d M Y H:i', strtotime($me['last_login']))) : '—' ?>
          </dd>
        </dl>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card">
      <div class="card-header"><strong>Academic Details</strong></div>
      <div class="card-body">
        <?php if ($profile): ?>
          <dl class="row mb-0">
            <dt class="col-sm-4">Academic Rank</dt><dd class="col-sm-8"><?= e($profile['academic_rank'] ?? '—') ?></dd>
            <dt class="col-sm-4">Specialization</dt><dd class="col-sm-8"><?= e($profile['specialization'] ?? '—') ?></dd>
            <dt class="col-sm-4">ORCID</dt><dd class="col-sm-8"><?= e($profile['orcid'] ?? '—') ?></dd>
            <dt class="col-sm-4">Bio</dt>
            <dd class="col-sm-8"><?= nl2br(e($profile['bio'] ?? '—')) ?></dd>
          </dl>
        <?php else: ?>
          <p class="text-muted mb-0">You haven't added academic details yet.
            <a href="<?= BASE_URL ?>/modules/profile/edit.php">Add them now →</a></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>