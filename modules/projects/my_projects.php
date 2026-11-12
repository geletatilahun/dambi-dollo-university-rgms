<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('researcher');

$user = current_user();

// ============ LOAD MY PROJECTS ============
$stmt = $pdo->prepare("
    SELECT p.id, p.project_code, p.funds_awarded, p.status,
           p.start_date, p.end_date,
           pr.id AS proposal_id, pr.title, pr.proposal_code,
           COALESCE((SELECT SUM(amount) FROM disbursements WHERE project_id = p.id), 0) AS disbursed,
           COALESCE((SELECT SUM(amount) FROM expenses WHERE project_id = p.id), 0) AS spent,
           (SELECT COUNT(*) FROM progress_reports WHERE project_id = p.id) AS reports_count,
           (SELECT COUNT(*) FROM progress_reports WHERE project_id = p.id AND review_status = 'pending') AS pending_reports,
           (SELECT COUNT(*) FROM progress_reports WHERE project_id = p.id AND review_status = 'returned') AS returned_reports
    FROM projects p
    JOIN proposals pr ON pr.id = p.proposal_id
    WHERE p.leader_id = ?
    ORDER BY p.created_at DESC
");
$stmt->execute([$user['id']]);
$projects = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">My Projects</h3>
    <small class="text-muted">
      <?= count($projects) ?> project<?= count($projects) == 1 ? '' : 's' ?>
    </small>
  </div>
</div>

<?php if (!$projects): ?>
  <div class="card">
    <div class="card-body text-center py-5">
      <div style="font-size:3rem;">📁</div>
      <h5 class="mt-2">No projects yet</h5>
      <p class="text-muted">
        When your proposals are approved by the VP, they become projects.
      </p>
      <a href="<?= BASE_URL ?>/modules/proposals/list.php" class="btn btn-primary btn-sm">
        View My Proposals
      </a>
    </div>
  </div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($projects as $p):
      $awarded   = (float)$p['funds_awarded'];
      $disbursed = (float)$p['disbursed'];
      $spent     = (float)$p['spent'];
      $disbPct   = $awarded > 0 ? round($disbursed / $awarded * 100) : 0;
      $spentPct  = $disbursed > 0 ? round($spent / $disbursed * 100) : 0;
    ?>
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <div class="small text-muted">
                  <code><?= e($p['project_code']) ?></code>
                  <span class="badge bg-<?= $p['status'] === 'active' ? 'success' : 'secondary' ?> ms-1">
                    <?= e(ucfirst($p['status'])) ?>
                  </span>
                </div>
                <h6 class="mb-1 mt-2"><?= e(shorten($p['title'], 90)) ?></h6>
                <small class="text-muted">
                  <?= e(fmt_date($p['start_date'])) ?> → <?= e(fmt_date($p['end_date'])) ?>
                </small>
              </div>
            </div>

            <div class="row g-2 mt-3 small">
              <div class="col-4">
                <div class="text-muted">Awarded</div>
                <strong><?= number_format($awarded, 0) ?> ETB</strong>
              </div>
              <div class="col-4">
                <div class="text-muted">Disbursed</div>
                <strong class="text-info"><?= number_format($disbursed, 0) ?> (<?= $disbPct ?>%)</strong>
              </div>
              <div class="col-4">
                <div class="text-muted">Spent</div>
                <strong class="text-warning"><?= number_format($spent, 0) ?> (<?= $spentPct ?>%)</strong>
              </div>
            </div>

            <hr class="my-3">

            <div class="d-flex justify-content-between align-items-center">
              <div class="small">
                <?php if ($p['pending_reports'] > 0): ?>
                  <span class="badge bg-info text-dark">
                    <?= $p['pending_reports'] ?> pending
                  </span>
                <?php endif; ?>
                <?php if ($p['returned_reports'] > 0): ?>
                  <span class="badge bg-danger">
                    <?= $p['returned_reports'] ?> returned
                  </span>
                <?php endif; ?>
                <?php if ($p['reports_count'] == 0 && !$p['pending_reports']): ?>
                  <span class="text-muted">No reports submitted yet</span>
                <?php else: ?>
                  <span class="text-muted">
                    <?= $p['reports_count'] ?> report<?= $p['reports_count'] == 1 ? '' : 's' ?>
                  </span>
                <?php endif; ?>
              </div>
              <a href="<?= BASE_URL ?>/modules/projects/view.php?id=<?= (int)$p['id'] ?>"
                 class="btn btn-sm btn-primary">Open Project →</a>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>