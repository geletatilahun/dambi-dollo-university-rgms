<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('researcher');

$user = current_user();
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) { set_flash('danger', 'Invalid project.'); redirect('/modules/projects/my_projects.php'); }

$stmt = $pdo->prepare("SELECT p.*, pr.title FROM projects p JOIN proposals pr ON pr.id = p.proposal_id WHERE p.id = ? AND p.leader_id = ?");
$stmt->execute([$projectId, $user['id']]);
$proj = $stmt->fetch();
if (!$proj) { set_flash('danger', 'Project not found.'); redirect('/modules/projects/my_projects.php'); }

$errors = [];
$form = ['new_end_date' => '', 'reason' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['new_end_date'] = $_POST['new_end_date'] ?? '';
    $form['reason'] = trim($_POST['reason'] ?? '');

    if (strtotime($form['new_end_date']) <= strtotime($proj['end_date'])) {
        $errors[] = "New end date must be after current end date ({$proj['end_date']}).";
    }
    if (strlen($form['reason']) < 30) $errors[] = "Reason must be at least 30 characters.";

    if (!$errors) {
        $stmt = $pdo->prepare("INSERT INTO project_extensions (project_id, requested_by, old_end_date, new_end_date, reason) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$projectId, $user['id'], $proj['end_date'], $form['new_end_date'], $form['reason']]);
        $extId = (int)$pdo->lastInsertId();

        $cos = $pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'coordinator' AND u.is_active = 1")->fetchAll();
        foreach ($cos as $c) {
            notify($pdo, $c['id'], '⏰ Project Extension Requested', "Extension requested for {$proj['project_code']}.", BASE_URL . "/modules/projects/view.php?id={$projectId}");
        }
        log_action($pdo, 'extension_requested', 'project_extensions', $extId, "Project {$proj['project_code']}");
        set_flash('success', 'Extension request submitted.');
        redirect('/modules/projects/view.php?id=' . $projectId);
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<div class="row"><div class="col-lg-7 mx-auto">
  <h3>Request Project Extension</h3>
  <?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
  <div class="card mb-3"><div class="card-body">Current end date: <strong><?= e(fmt_date($proj['end_date'])) ?></strong></div></div>

  <form method="post">
    <div class="card">
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label">New End Date *</label>
          <input type="date" name="new_end_date" class="form-control" required min="<?= e(date('Y-m-d', strtotime($proj['end_date'] . ' +1 day'))) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Reason *</label>
          <textarea name="reason" rows="4" class="form-control" required></textarea>
        </div>
      </div>
      <div class="card-footer"><button class="btn btn-primary">📅 Submit Request</button></div>
    </div>
  </form>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>