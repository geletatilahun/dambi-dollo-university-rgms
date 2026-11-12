<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$user = current_user();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger', 'Invalid project.'); redirect('/modules/projects/my_projects.php'); }

// ============ LOAD PROJECT ============
$stmt = $pdo->prepare("
    SELECT p.*, pr.id AS proposal_id, pr.title, pr.proposal_code, pr.abstract,
           u.full_name AS leader_name, u.email AS leader_email
    FROM projects p
    JOIN proposals pr ON pr.id = p.proposal_id
    JOIN users u ON u.id = p.leader_id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$proj = $stmt->fetch();
if (!$proj) { set_flash('danger', 'Project not found.'); redirect('/index.php'); }

// ============ ACCESS CONTROL ============
$isLeader = ((int)$proj['leader_id'] === (int)$user['id']);
$isStaff  = has_role('coordinator','college','director','vp','admin','finance');
if (!$isLeader && !$isStaff) {
    set_flash('danger', 'You do not have permission to view this project.');
    redirect('/index.php');
}

// ============ FINANCIAL TOTALS ============
$stmt = $pdo->prepare("
    SELECT
      COALESCE((SELECT SUM(amount) FROM disbursements WHERE project_id = ?), 0) AS disbursed,
      COALESCE((SELECT SUM(amount) FROM expenses WHERE project_id = ?), 0) AS spent
");
$stmt->execute([$id, $id]);
$fin = $stmt->fetch();

// ============ PROGRESS REPORTS ============
$stmt = $pdo->prepare("
    SELECT r.*, u.full_name AS reviewer_name
    FROM progress_reports r
    LEFT JOIN users u ON u.id = r.reviewed_by
    WHERE r.project_id = ?
    ORDER BY r.report_date DESC, r.id DESC
");
$stmt->execute([$id]);
$reports = $stmt->fetchAll();

$canSubmitReport = $isLeader && in_array($proj['status'], ['active','extended'], true);

// ============ PENDING CLOSURE / EXTENSION ============
$stmt = $pdo->prepare("
    SELECT c.*, u.full_name AS requester_name
    FROM project_closures c
    JOIN users u ON u.id = c.requested_by
    WHERE c.project_id = ? AND c.status NOT IN ('closed','rejected')
    ORDER BY c.created_at DESC LIMIT 1
");
$stmt->execute([$id]);
$pendingClosureCard = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT * FROM project_extensions
    WHERE project_id = ? AND status = 'pending'
    ORDER BY created_at DESC LIMIT 1
");
$stmt->execute([$id]);
$pendingExtensionCard = $stmt->fetch();

include __DIR__ . '/../../includes/header.php';
?>

<!-- ============ HEADER ============ -->
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h3 class="mb-1"><?= e($proj['title']) ?></h3>
    <div class="text-muted small">
      <code><?= e($proj['project_code']) ?></code>
      · Proposal <?= e($proj['proposal_code']) ?>
      · Leader: <?= e($proj['leader_name']) ?>
      <span class="badge bg-<?= $proj['status'] === 'active' ? 'success' : 'secondary' ?> ms-1">
        <?= e(ucfirst($proj['status'])) ?>
      </span>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($isStaff): ?>
      <a href="<?= BASE_URL ?>/modules/finance/project.php?id=<?= $id ?>"
         class="btn btn-outline-primary btn-sm">💰 Finance View</a>
    <?php endif; ?>
    
    <?php if ($isLeader && in_array($proj['status'], ['active','extended'], true)): ?>
      <?php if (!$pendingClosureCard && !$pendingExtensionCard): ?>
        <a href="<?= BASE_URL ?>/modules/projects/extension_request.php?project_id=<?= $id ?>"
           class="btn btn-outline-warning btn-sm">📅 Request Extension</a>
        <a href="<?= BASE_URL ?>/modules/projects/closure_request.php?project_id=<?= $id ?>"
           class="btn btn-warning btn-sm">🏁 Request Closure</a>
      <?php elseif ($pendingClosureCard): ?>
        <span class="badge bg-warning text-dark align-self-center p-2">⏳ Closure Pending</span>
      <?php elseif ($pendingExtensionCard): ?>
        <span class="badge bg-warning text-dark align-self-center p-2">⏳ Extension Pending</span>
      <?php endif; ?>
    <?php endif; ?>

    <a href="<?= BASE_URL ?>/index.php" class="btn btn-secondary btn-sm">← Back</a>
  </div>
</div>

<!-- ============ STATS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card card-stat p-3">
      <small class="text-muted">Awarded</small>
      <h3 class="mb-0 text-primary"><?= number_format((float)$proj['funds_awarded'], 0) ?> ETB</h3>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3">
      <small class="text-muted">Disbursed</small>
      <h3 class="mb-0 text-info"><?= number_format((float)$fin['disbursed'], 0) ?> ETB</h3>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3">
      <small class="text-muted">Spent</small>
      <h3 class="mb-0 text-warning"><?= number_format((float)$fin['spent'], 0) ?> ETB</h3>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3">
      <small class="text-muted">Available</small>
      <h3 class="mb-0 text-success">
        <?= number_format((float)$fin['disbursed'] - (float)$fin['spent'], 0) ?> ETB
      </h3>
    </div>
  </div>
</div>

<!-- ============ PROJECT DETAILS ============ -->
<div class="card mb-4">
  <div class="card-body">
    <div class="row g-3 small">
      <div class="col-md-3">
        <div class="text-muted">Start Date</div>
        <strong><?= e(fmt_date($proj['start_date'])) ?></strong>
      </div>
      <div class="col-md-3">
        <div class="text-muted">End Date</div>
        <strong><?= e(fmt_date($proj['end_date'])) ?></strong>
      </div>
      <div class="col-md-3">
        <div class="text-muted">Duration</div>
        <strong>
          <?php
            $start = new DateTime($proj['start_date']);
            $end   = new DateTime($proj['end_date']);
            echo $start->diff($end)->m + ($start->diff($end)->y * 12);
          ?> months
        </strong>
      </div>
      <div class="col-md-3">
        <div class="text-muted">Leader Email</div>
        <strong><?= e($proj['leader_email']) ?></strong>
      </div>
    </div>
  </div>
</div>

<!-- ============ PENDING REQUESTS BANNER ============ -->
<?php if ($pendingClosureCard): ?>
  <div class="card mt-3 border-warning mb-4">
    <div class="card-header bg-warning text-dark">
      <strong>⏳ Closure Request Pending</strong>
    </div>
    <div class="card-body">
      <p class="small mb-2">
        Requested by <strong><?= e($pendingClosureCard['requester_name']) ?></strong>
        on <?= e(fmt_date($pendingClosureCard['created_at'], 'd M Y H:i')) ?>
      </p>
      <div class="small mb-3">
        <strong>Current Stage:</strong>
        <span class="badge bg-warning text-dark">
          <?= e(ucwords(str_replace('_',' ', $pendingClosureCard['status']))) ?>
        </span>
      </div>
      <a href="<?= BASE_URL ?>/modules/projects/closure_review.php?id=<?= (int)$pendingClosureCard['id'] ?>"
         class="btn btn-sm btn-warning">Open Review →</a>
    </div>
  </div>
<?php endif; ?>

<?php if ($pendingExtensionCard): ?>
  <div class="card mt-3 border-info mb-4">
    <div class="card-header bg-info text-dark">
      <strong>⏳ Extension Request Pending</strong>
    </div>
    <div class="card-body small">
      Requested on <?= e(fmt_date($pendingExtensionCard['created_at'], 'd M Y')) ?>.
      New end date requested: <strong><?= e(fmt_date($pendingExtensionCard['new_end_date'])) ?></strong>.
      <br><span class="text-muted">Awaiting coordinator approval.</span>
    </div>
  </div>
<?php endif; ?>

<!-- ============ PROGRESS REPORTS ============ -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Progress Reports (<?= count($reports) ?>)</strong>
    <?php if ($canSubmitReport): ?>
      <a href="<?= BASE_URL ?>/modules/projects/report_create.php?project_id=<?= $id ?>"
         class="btn btn-sm btn-primary">+ Submit Progress Report</a>
    <?php endif; ?>
  </div>

  <?php if (!$reports): ?>
    <div class="card-body text-center text-muted py-5">
      <div style="font-size:3rem;">📝</div>
      <h5 class="mt-2">No progress reports yet</h5>
      <?php if ($canSubmitReport): ?>
        <p class="mb-2">Submit regular progress reports to track your project.</p>
        <a href="<?= BASE_URL ?>/modules/projects/report_create.php?project_id=<?= $id ?>"
           class="btn btn-primary btn-sm">Submit First Report</a>
      <?php else: ?>
        <p class="mb-0">Reports will appear here once submitted.</p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:110px">Date</th>
            <th>Summary</th>
            <th style="width:130px">Status</th>
            <th style="width:150px">Reviewed By</th>
            <th style="width:100px" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reports as $r):
            $statusColor = [
              'pending'  => 'info',
              'reviewed' => 'primary',
              'accepted' => 'success',
              'returned' => 'danger',
            ][$r['review_status']] ?? 'secondary';
          ?>
            <tr>
              <td class="small"><?= e(fmt_date($r['report_date'], 'd M Y')) ?></td>
              <td class="small"><?= e(shorten($r['summary'], 100)) ?></td>
              <td>
                <span class="badge bg-<?= $statusColor ?>">
                  <?= e(ucfirst($r['review_status'])) ?>
                </span>
              </td>
              <td class="small text-muted">
                <?= $r['reviewer_name'] ? e($r['reviewer_name']) : '—' ?>
              </td>
              <td class="text-end">
                <a href="<?= BASE_URL ?>/modules/projects/report_view.php?id=<?= (int)$r['id'] ?>"
                   class="btn btn-sm btn-outline-primary">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>