<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('researcher');

$user = current_user();
$projectId = (int)($_GET['project_id'] ?? 0);
if ($projectId <= 0) { set_flash('danger', 'Invalid project.'); redirect('/modules/projects/my_projects.php'); }

// ============ VERIFY OWNERSHIP ============
$stmt = $pdo->prepare("
    SELECT p.*, pr.title
    FROM projects p
    JOIN proposals pr ON pr.id = p.proposal_id
    WHERE p.id = ? AND p.leader_id = ?
");
$stmt->execute([$projectId, $user['id']]);
$proj = $stmt->fetch();
if (!$proj) { set_flash('danger', 'Project not found or not yours.'); redirect('/modules/projects/my_projects.php'); }

if (!in_array($proj['status'], ['active','extended'], true)) {
    set_flash('warning', 'Cannot submit reports for a ' . $proj['status'] . ' project.');
    redirect('/modules/projects/view.php?id=' . $projectId);
}

$errors = [];
$form = [
    'report_date' => date('Y-m-d'),
    'summary'     => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['report_date'] = $_POST['report_date'] ?? date('Y-m-d');
    $form['summary']     = trim($_POST['summary'] ?? '');

    if ($form['summary'] === '') $errors[] = "Summary is required.";
    if (strlen($form['summary']) < 50) $errors[] = "Summary should be at least 50 characters.";
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['report_date'])) $errors[] = "Invalid date.";

    // Handle attachment
    $filePath = null;
    if (!$errors && !empty($_FILES['attachment']['name'])) {
        $allowed = ['pdf','doc','docx','xls','xlsx','jpg','png'];
        $maxBytes = 10 * 1024 * 1024;
        $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));

        if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "File upload failed.";
        } elseif ($_FILES['attachment']['size'] > $maxBytes) {
            $errors[] = "File too large (max 10 MB).";
        } elseif (!in_array($ext, $allowed, true)) {
            $errors[] = "File type not allowed.";
        } else {
            $dir = __DIR__ . '/../../assets/uploads/reports/' . $projectId . '/';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $safe = bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dir . $safe)) {
                $filePath = 'assets/uploads/reports/' . $projectId . '/' . $safe;
            } else {
                $errors[] = "Could not save file.";
            }
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare("
            INSERT INTO progress_reports
                (project_id, summary, file_path, report_date)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $projectId,
            $form['summary'],
            $filePath,
            $form['report_date'],
        ]);
        $reportId = (int)$pdo->lastInsertId();

        log_action($pdo, 'submit_progress_report', 'progress_reports', $reportId,
            "Project {$proj['project_code']}");

        // Notify coordinator(s)
        $cos = $pdo->query("
            SELECT u.id FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'coordinator' AND u.is_active = 1
        ")->fetchAll();

        foreach ($cos as $c) {
            notify($pdo, $c['id'],
                '📊 New Progress Report Submitted',
                "Progress report for {$proj['project_code']} awaits your review.",
                BASE_URL . "/modules/projects/report_view.php?id={$reportId}");
        }

        set_flash('success', 'Progress report submitted successfully.');
        redirect('/modules/projects/view.php?id=' . $projectId);
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="row">
  <div class="col-lg-8 mx-auto">

    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3 class="mb-0">Submit Progress Report</h3>
      <a href="<?= BASE_URL ?>/modules/projects/view.php?id=<?= $projectId ?>"
         class="btn btn-outline-secondary btn-sm">← Back to Project</a>
    </div>

    <div class="card mb-3">
      <div class="card-body small">
        <strong><?= e($proj['project_code']) ?></strong>
        — <?= e(shorten($proj['title'], 80)) ?>
      </div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <strong>Please fix:</strong>
        <ul class="mb-0 mt-1">
          <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
      <div class="card">
        <div class="card-body">

          <div class="mb-3">
            <label class="form-label fw-semibold">Report Date *</label>
            <input type="date" name="report_date" class="form-control" required
                   value="<?= e($form['report_date']) ?>" style="max-width:220px;">
            <small class="text-muted">The date of the reporting period ending.</small>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Progress Summary *</label>
            <textarea name="summary" id="summary" rows="8" class="form-control" required
                      placeholder="Describe what has been accomplished since the last report. Include milestones met, challenges, and next steps."><?= e($form['summary']) ?></textarea>
            <div class="d-flex justify-content-between">
              <small class="text-muted">Minimum 50 characters.</small>
              <small class="text-muted"><span id="charCount">0</span> characters</small>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Attachment (optional)</label>
            <input type="file" name="attachment" class="form-control"
                   accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.png">
            <small class="text-muted">
              PDF, DOC/DOCX, XLS/XLSX, JPG, PNG · Max 10 MB
            </small>
          </div>

        </div>
        <div class="card-footer d-flex gap-2">
          <button class="btn btn-primary">📤 Submit Report</button>
          <a href="<?= BASE_URL ?>/modules/projects/view.php?id=<?= $projectId ?>"
             class="btn btn-secondary">Cancel</a>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
const ta = document.getElementById('summary');
const cnt = document.getElementById('charCount');
function updateCount() { cnt.textContent = ta.value.length; }
ta.addEventListener('input', updateCount);
updateCount();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>