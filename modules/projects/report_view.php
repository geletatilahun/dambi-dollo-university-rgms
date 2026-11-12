<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$user = current_user();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger', 'Invalid report.'); redirect('/index.php'); }

$stmt = $pdo->prepare("
    SELECT r.*, u.full_name AS reviewer_name,
           p.id AS project_id, p.project_code, p.leader_id,
           pr.title AS proposal_title
    FROM progress_reports r
    JOIN projects p ON p.id = r.project_id
    JOIN proposals pr ON pr.id = p.proposal_id
    LEFT JOIN users u ON u.id = r.reviewed_by
    WHERE r.id = ?
");
$stmt->execute([$id]);
$report = $stmt->fetch();
if (!$report) { set_flash('danger', 'Report not found.'); redirect('/index.php'); }

$isLeader = ((int)$report['leader_id'] === (int)$user['id']);
$isStaff  = has_role('coordinator','college','director','vp','admin');
if (!$isLeader && !$isStaff) {
    set_flash('danger', 'You do not have permission to view this report.');
    redirect('/index.php');
}

$statusColor = [
  'pending'  => 'info',
  'reviewed' => 'primary',
  'accepted' => 'success',
  'returned' => 'danger',
][$report['review_status']] ?? 'secondary';

include __DIR__ . '/../../includes/header.php';
?>

<div class="row">
  <div class="col-lg-9 mx-auto">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div>
        <h3 class="mb-1">Progress Report #<?= $id ?></h3>
        <small class="text-muted">
          <code><?= e($report['project_code']) ?></code>
          · <?= e(shorten($report['proposal_title'], 60)) ?>
        </small>
      </div>
      <div class="d-flex gap-2">
        <?php if (has_role('coordinator','director') && $report['review_status'] === 'pending'): ?>
          <a href="<?= BASE_URL ?>/modules/projects/report_review.php?id=<?= $id ?>"
             class="btn btn-primary btn-sm">📝 Review Report</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/modules/projects/view.php?id=<?= (int)$report['project_id'] ?>"
           class="btn btn-secondary btn-sm">← Back to Project</a>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between mb-3">
          <div>
            <small class="text-muted">Report Date</small>
            <div><strong><?= e(fmt_date($report['report_date'], 'd M Y')) ?></strong></div>
          </div>
          <div class="text-end">
            <small class="text-muted">Status</small>
            <div>
              <span class="badge bg-<?= $statusColor ?>">
                <?= e(ucfirst($report['review_status'])) ?>
              </span>
            </div>
          </div>
        </div>

        <h6>Summary</h6>
        <p style="white-space: pre-wrap;"><?= e($report['summary']) ?></p>

        <?php if ($report['file_path']): ?>
          <hr>
          <h6>Attachment</h6>
          <a href="<?= BASE_URL . '/' . e($report['file_path']) ?>" target="_blank"
             class="btn btn-sm btn-outline-primary">📎 Download Attachment</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($report['review_status'] !== 'pending'): ?>
      <div class="card">
        <div class="card-header"><strong>Review Feedback</strong></div>
        <div class="card-body">
          <div class="d-flex justify-content-between small mb-2">
            <span><strong><?= e($report['reviewer_name'] ?? 'Reviewer') ?></strong></span>
            <span class="text-muted"><?= e(fmt_date($report['submitted_at'], 'd M Y H:i')) ?></span>
          </div>
          <p style="white-space: pre-wrap;" class="mb-0"><?= e($report['review_comment'] ?? '—') ?></p>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>