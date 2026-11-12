<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('finance');

$user = current_user();

// ============ FETCH PROJECTS WITH SUMMARY ============
$stmt = $pdo->query("
    SELECT p.id, p.project_code, p.funds_awarded, p.status,
           p.start_date, p.end_date,
           pr.title, pr.proposal_code,
           u.full_name AS leader_name,
           COALESCE((SELECT SUM(amount) FROM disbursements WHERE project_id = p.id), 0) AS disbursed,
           COALESCE((SELECT SUM(amount) FROM expenses WHERE project_id = p.id), 0) AS spent
    FROM projects p
    JOIN proposals pr ON pr.id = p.proposal_id
    JOIN users u ON u.id = p.leader_id
    ORDER BY p.status = 'active' DESC, p.created_at DESC
");
$projects = $stmt->fetchAll();

// ============ PORTFOLIO TOTALS ============
$totalAwarded   = 0;
$totalDisbursed = 0;
$totalSpent     = 0;
foreach ($projects as $p) {
    $totalAwarded   += (float)$p['funds_awarded'];
    $totalDisbursed += (float)$p['disbursed'];
    $totalSpent     += (float)$p['spent'];
}

include __DIR__ . '/../../includes/header.php';
?>

<!-- ============ HEADER ============ -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">Projects — Financial Overview</h3>
    <small class="text-muted">
      <?= count($projects) ?> project<?= count($projects) == 1 ? '' : 's' ?>
      · Total awarded: <strong><?= number_format($totalAwarded, 0) ?> ETB</strong>
    </small>
  </div>
  <a href="<?= BASE_URL ?>/modules/finance/reports.php" class="btn btn-outline-primary">
    📊 Portfolio Report →
  </a>
</div>

<!-- ============ SUMMARY CARDS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card card-stat p-3">
      <small class="text-muted">Total Awarded</small>
      <h3 class="mb-0 text-primary"><?= number_format($totalAwarded, 0) ?></h3>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card card-stat p-3">
      <small class="text-muted">Total Disbursed</small>
      <h3 class="mb-0 text-info"><?= number_format($totalDisbursed, 0) ?></h3>
      <small class="text-muted">
        <?= $totalAwarded ? round($totalDisbursed / $totalAwarded * 100) : 0 ?>% of awarded
      </small>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card card-stat p-3">
      <small class="text-muted">Total Spent</small>
      <h3 class="mb-0 text-warning"><?= number_format($totalSpent, 0) ?></h3>
      <small class="text-muted">
        <?= $totalDisbursed ? round($totalSpent / $totalDisbursed * 100) : 0 ?>% of disbursed
      </small>
    </div>
  </div>
</div>

<!-- ============ PROJECTS TABLE ============ -->
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:150px">Project</th>
          <th>Title</th>
          <th style="width:150px">Leader</th>
          <th class="text-end" style="width:110px">Awarded</th>
          <th class="text-end" style="width:110px">Disbursed</th>
          <th class="text-end" style="width:110px">Spent</th>
          <th style="width:130px">Progress</th>
          <th style="width:90px">Status</th>
          <th style="width:80px" class="text-end"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$projects): ?>
          <tr>
            <td colspan="9" class="text-center text-muted py-5">
              <div style="font-size:2.5rem;">📁</div>
              <div class="mt-2">No projects yet.</div>
              <small class="text-muted">Projects are created when proposals are approved by the VP.</small>
            </td>
          </tr>
        <?php else: foreach ($projects as $p):
          $awarded   = (float)$p['funds_awarded'];
          $disbursed = (float)$p['disbursed'];
          $spent     = (float)$p['spent'];
          $spentPct  = $disbursed > 0 ? round($spent / $disbursed * 100) : 0;
          $disbPct   = $awarded > 0 ? round($disbursed / $awarded * 100) : 0;
          $barColor  = $spentPct >= 95 ? 'danger' : ($spentPct >= 75 ? 'warning' : 'success');
        ?>
          <tr>
            <td><code class="small text-nowrap"><?= e($p['project_code']) ?></code></td>
            <td>
              <a href="<?= BASE_URL ?>/modules/finance/project.php?id=<?= (int)$p['id'] ?>"
                 class="text-decoration-none fw-semibold"
                 title="<?= e($p['title']) ?>">
                <?= e(shorten($p['title'], 70)) ?>
              </a>
              <br><small class="text-muted">Proposal: <?= e($p['proposal_code']) ?></small>
            </td>
            <td class="small"><?= e($p['leader_name']) ?></td>
            <td class="text-end small"><?= number_format($awarded, 0) ?></td>
            <td class="text-end small"><?= number_format($disbursed, 0) ?></td>
            <td class="text-end small">
              <strong class="text-<?= $barColor ?>"><?= number_format($spent, 0) ?></strong>
            </td>
            <td>
              <div class="progress" style="height:8px;" title="<?= $spentPct ?>% spent of disbursed">
                <div class="progress-bar bg-<?= $barColor ?>"
                     style="width:<?= min($spentPct, 100) ?>%"></div>
              </div>
              <small class="text-muted"><?= $spentPct ?>% spent</small>
            </td>
            <td>
              <span class="badge bg-<?= $p['status'] === 'active' ? 'success' : 'secondary' ?>">
                <?= e(ucfirst($p['status'])) ?>
              </span>
            </td>
            <td class="text-end">
              <a href="<?= BASE_URL ?>/modules/finance/project.php?id=<?= (int)$p['id'] ?>"
                 class="btn btn-sm btn-outline-primary">View</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>