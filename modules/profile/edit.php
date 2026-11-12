<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$user = current_user();
$errors = [];

$stmt = $pdo->prepare("SELECT * FROM researcher_profiles WHERE user_id = ?");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone          = trim($_POST['phone'] ?? '');
    $academic_rank  = trim($_POST['academic_rank'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');
    $orcid          = trim($_POST['orcid'] ?? '');
    $bio            = trim($_POST['bio'] ?? '');

    if ($phone !== '' && !preg_match('/^\+?[0-9 \-]{7,20}$/', $phone)) {
        $errors[] = "Phone format looks invalid.";
    }
    if ($orcid !== '' && !preg_match('/^[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9X]{4}$/', $orcid)) {
        $errors[] = "ORCID format should be 0000-0000-0000-0000.";
    }

    if (!$errors) {
        // Update users.phone
        $pdo->prepare("UPDATE users SET phone = ? WHERE id = ?")
            ->execute([$phone ?: null, $user['id']]);

        // Upsert researcher_profiles
        if ($profile) {
            $pdo->prepare("
                UPDATE researcher_profiles
                SET academic_rank=?, specialization=?, orcid=?, bio=?
                WHERE user_id=?
            ")->execute([$academic_rank, $specialization, $orcid, $bio, $user['id']]);
        } else {
            $pdo->prepare("
                INSERT INTO researcher_profiles (user_id, academic_rank, specialization, orcid, bio)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$user['id'], $academic_rank, $specialization, $orcid, $bio]);
        }

        log_action($pdo, 'update_profile', 'users', $user['id'], 'Updated profile');
        set_flash('success', 'Profile updated successfully.');
        redirect('/modules/profile/view.php');
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<h3 class="mb-4">Edit Profile</h3>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control"
                 value="<?= e($profile['phone'] ?? $user['phone'] ?? '') ?>"
                 placeholder="+251...">
        </div>
        <div class="col-md-6">
          <label class="form-label">Academic Rank</label>
          <input type="text" name="academic_rank" class="form-control"
                 value="<?= e($profile['academic_rank'] ?? '') ?>"
                 placeholder="e.g., Assistant Professor">
        </div>
        <div class="col-md-6">
          <label class="form-label">Specialization</label>
          <input type="text" name="specialization" class="form-control"
                 value="<?= e($profile['specialization'] ?? '') ?>"
                 placeholder="e.g., Plant Biotechnology">
        </div>
        <div class="col-md-6">
          <label class="form-label">ORCID</label>
          <input type="text" name="orcid" class="form-control"
                 value="<?= e($profile['orcid'] ?? '') ?>"
                 placeholder="0000-0000-0000-0000">
        </div>
        <div class="col-12">
          <label class="form-label">Short Bio</label>
          <textarea name="bio" rows="4" class="form-control"><?= e($profile['bio'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary">Save</button>
        <a href="<?= BASE_URL ?>/modules/profile/view.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>