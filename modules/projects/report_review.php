<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('coordinator','director');

$user = current_user();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger', 'Invalid report.'); redirect('/index.php'); }

$stmt = $pdo->prepare("
    SELECT r.*, p.project_code, p.leader_id,
           pr.title AS proposal_title
    FROM progress_reports r
    JOIN projects p ON p.id = r.project_id
    JOIN proposals pr ON pr.id = p.proposal_id
    WHERE r.id = ?
");
$stmt->execute([$id]);
$report = $stmt->fetch();
if (!$report) { set_flash('danger', 'Report not found.'); redirect('/index.php'); }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');

    if (!in_array($action, ['accept','return'], true)) $errors[] = "Invalid action.";
    if ($action === 'return' && $comment === '') $errors[] = "Comment is required when returning.";

    if (!$errors) {
        $newStatus = $action === 'accept' ? 'accepted' : 'returned';

        $pdo->prepare("
            UPDATE progress_reports
            SET review_status = ?, reviewed_by = ?, review_comment = ?
            WHERE id = ?
        ")->execute([$newStatus, $user['id'], $comment, $id]);

        log_action($pdo, 'review_progress_report', 'progress_reports', $id,
            "Decision: $newStatus");

        // Notify researcher
        $title = $action === 'accept'
            ? '✅ Progress Report Accepted'
            : '↩ Progress Report Returned';
        $msg = $action === 'accept'
            ? "Your report for {$report['project_code']} has been accepted."
            : "Your report for {$report['project_code']} was returned for revision: {$comment}";

        notify($pdo, $report['leader_id'], $title, $msg,
            BASE_URL . "/modules/projects/report_view.php?id={$id}");

        set_flash('success', "Report $newStatus.");
        redirect('/modules/projects/report_view.php?id=' . $id);
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="row">
  <div class="col-lg-8 mx-auto">

    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3 class="mb-0">Review Progress Report</h3>
      <a href="<?= BASE_URL ?>/modules/projects/report_view.php?id=<?= $id ?>"
         class="btn btn-outline-secondary btn-sm">← Back</a>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-body">
        <div class="small text-muted mb-2">
          <code><?= e($report['project_code']) ?></code>
          · <?= e(shorten($report['proposal_title'], 70)) ?>
        </div>
        <strong>Report Date:</strong> <?= e(fmt_date($report['report_date'])) ?>
        <hr>
        <h6>Summary</h6>
        <p style="white-space: pre-wrap;"><?= e($report['summary']) ?></p>
        <?php if ($report['file_path']): ?>
          <a href="<?= BASE_URL . '/' . e($report['file_path']) ?>" target="_blank"
             class="btn btn-sm btn-outline-primary">📎 Download Attachment</a>
        <?php endif; ?>
      </div>
    </div>

    <form method="post">
      <div class="card mb-3">
        <div class="card-body">
          <label class="form-label fw-semibold">Review Comment</label>
          <textarea name="comment" rows="4" class="form-control"
                    placeholder="Provide feedback. Required if returning the report."></textarea>
        </div>
      </div>

      <div class="d-flex gap-2">
        <button name="action" value="accept" class="btn btn-success"
                onclick="return confirm('Accept this report?');">✅ Accept</button>
        <button name="action" value="return" class="btn btn-warning"
                onclick="return confirm('Return this report for revision?');">↩ Return for Revision</button>
        <a href="<?= BASE_URL ?>/modules/projects/report_view.php?id=<?= $id ?>"
           class="btn btn-secondary">Cancel</a>
      </div>
    </form>

  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>