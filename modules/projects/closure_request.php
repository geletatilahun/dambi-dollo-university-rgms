<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('researcher');

$user = current_user();
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) { set_flash('danger', 'Invalid project.'); redirect('/modules/projects/my_projects.php'); }

$stmt = $pdo->prepare("
    SELECT p.*, pr.title FROM projects p
    JOIN proposals pr ON pr.id = p.proposal_id
    WHERE p.id = ? AND p.leader_id = ?
");
$stmt->execute([$projectId, $user['id']]);
$proj = $stmt->fetch();
if (!$proj) { set_flash('danger', 'Project not found.'); redirect('/modules/projects/my_projects.php'); }

if (!in_array($proj['status'], ['active','extended'], true)) {
    set_flash('warning', 'This project cannot be closed.');
    redirect('/modules/projects/view.php?id=' . $projectId);
}

$stmt = $pdo->prepare("SELECT id FROM project_closures WHERE project_id = ? AND status NOT IN ('closed','rejected')");
$stmt->execute([$projectId]);
if ($stmt->fetch()) {
    set_flash('warning', 'A closure request is already pending.');
    redirect('/modules/projects/view.php?id=' . $projectId);
}

$stmt = $pdo->prepare("
    SELECT
      COALESCE((SELECT SUM(amount) FROM disbursements WHERE project_id = ?), 0) AS disbursed,
      COALESCE((SELECT SUM(amount) FROM expenses WHERE project_id = ?), 0) AS spent
");
$stmt->execute([$projectId, $projectId]);
$fin = $stmt->fetch();
$unspent = (float)$fin['disbursed'] - (float)$fin['spent'];

$errors = [];
$form = ['final_summary' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['final_summary'] = trim($_POST['final_summary'] ?? '');
    if (strlen($form['final_summary']) < 100) $errors[] = "Summary must be at least 100 characters.";

    $reportPath = null;
    if (!$errors && !empty($_FILES['final_report']['name'])) {
        $allowed = ['pdf','doc','docx'];
        $maxBytes = 15 * 1024 * 1024;
        $ext = strtolower(pathinfo($_FILES['final_report']['name'], PATHINFO_EXTENSION));
        if ($_FILES['final_report']['size'] > $maxBytes) {
            $errors[] = "File too large (max 15 MB).";
        } elseif (!in_array($ext, $allowed, true)) {
            $errors[] = "File must be PDF or DOC/DOCX.";
        } else {
            $dir = __DIR__ . '/../../assets/uploads/closures/' . $projectId . '/';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $safe = bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($_FILES['final_report']['tmp_name'], $dir . $safe)) {
                $reportPath = 'assets/uploads/closures/' . $projectId . '/' . $safe;
            }
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO project_closures (project_id, requested_by, final_summary, final_report_path, total_spent, unspent_balance)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$projectId, $user['id'], $form['final_summary'], $reportPath, (float)$fin['spent'], $unspent]);
            $closureId = (int)$pdo->lastInsertId();

            $cos = $pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'coordinator' AND u.is_active = 1")->fetchAll();
            foreach ($cos as $c) {
                notify($pdo, $c['id'], '🏁 Project Closure Requested', "Closure request for {$proj['project_code']} awaits your review.", BASE_URL . "/modules/projects/closure_review.php?id={$closureId}");
            }
            log_action($pdo, 'closure_requested', 'project_closures', $closureId, "Project {$proj['project_code']}");
            $pdo->commit();
            set_flash('success', 'Closure request submitted.');
            redirect('/modules/projects/view.php?id=' . $projectId);
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<div class="row"><div class="col-lg-8 mx-auto">
  <h3>Request Project Closure</h3>
  <?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
  <div class="card mb-3"><div class="card-body"><strong><?= e($proj['project_code']) ?></strong> — <?= e(shorten($proj['title'], 80)) ?></div></div>
  
  <div class="card mb-3"><div class="card-header">Financials</div><div class="card-body small">
    Disbursed: <?= number_format((float)$fin['disbursed'], 0) ?> ETB · Spent: <?= number_format((float)$fin['spent'], 0) ?> ETB · Unspent: <?= number_format($unspent, 0) ?> ETB
  </div></div>

  <form method="post" enctype="multipart/form-data">
    <div class="card">
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label">Final Summary *</label>
          <textarea name="final_summary" rows="5" class="form-control" required></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Final Report (optional)</label>
          <input type="file" name="final_report" class="form-control" accept=".pdf,.doc,.docx">
        </div>
      </div>
      <div class="card-footer"><button class="btn btn-warning">🏁 Submit Closure Request</button></div>
    </div>
  </form>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>