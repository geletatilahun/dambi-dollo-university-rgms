<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login('finance');

$user = current_user();

// ============ OVERALL STATS ============
$totalProjects = (int)$pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
$activeProjects = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE status = 'active'")->fetchColumn();

$totalAwarded  = (float)$pdo->query("
    SELECT COALESCE(SUM(funds_awarded),0) FROM projects
")->fetchColumn();

$totalDisbursed = (float)$pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM disbursements
")->fetchColumn();

$totalSpent = (float)$pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM expenses
")->fetchColumn();

$remainingToDisburse = max(0, $totalAwarded - $totalDisbursed);
$unspentBalance = max(0, $totalDisbursed - $totalSpent);

// ============ RECENT ACTIVITY ============
$stmt = $pdo->query("
    SELECT d.id, d.amount, d.disbursed_on, d.tranche,
           p.project_code, pr.title AS proposal_title,
           u.full_name AS recorder
    FROM disbursements d
    JOIN projects p ON p.id = d.project_id
    JOIN proposals pr ON pr.id = p.proposal_id
    JOIN users u ON u.id = d.recorded_by
    ORDER BY d.created_at DESC
    LIMIT 5
");
$recentDisbursements = $stmt->fetchAll();
$stmt = $pdo->query("
    SELECT e.id, e.amount, e.expense_date, e.category,
           p.project_code, pr.title AS proposal_title,
           u.full_name AS recorder
    FROM expenses e
    JOIN projects p ON p.id = e.project_id
    JOIN proposals pr ON pr.id = p.proposal_id
    JOIN users u ON u.id = e.recorded_by
    ORDER BY e.created_at DESC
    LIMIT 5
");
$recentExpenses = $stmt->fetchAll();

// ============ PROJECTS NEEDING ATTENTION ============
// Projects where spent > 90% of disbursed
$stmt = $pdo->query("
    SELECT pr.id, pr.project_code, pr.funds_awarded,
           COALESCE(SUM(DISTINCT d.amount),0) AS disbursed,
           COALESCE(SUM(DISTINCT e.amount),0) AS spent
    FROM projects pr
    LEFT JOIN disbursements d ON d.project_id = pr.id
    LEFT JOIN expenses e ON e.project_id = pr.id
    WHERE pr.status = 'active'
    GROUP BY pr.id
    HAVING spent > (disbursed * 0.9) AND disbursed > 0
    ORDER BY spent DESC
    LIMIT 5
");
$projectsAtRisk = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- ============ HEADER ============ -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">Welcome, <?= e($user['full_name']) ?></h3>
    <small class="text-muted">
      Finance Dashboard — Research Grant Management
    </small>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/modules/finance/disbursements.php" class="btn btn-primary">
      + Record Disbursement
    </a>
    <a href="<?= BASE_URL ?>/modules/finance/expenses.php" class="btn btn-outline-primary">
      + Record Expense
    </a>
  </div>
</div>

<!-- ============ TOP STAT CARDS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card card-stat p-3 h-100">
      <small class="text-muted">Total Awarded</small>
      <h3 class="mb-0 text-primary"><?= number_format($totalAwarded, 0) ?></h3>
      <small class="text-muted">ETB across <?= $totalProjects ?> projects</small>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3 h-100">
      <small class="text-muted">Total Disbursed</small>
      <h3 class="mb-0 text-info"><?= number_format($totalDisbursed, 0) ?></h3>
      <small class="text-muted">
        <?= $totalAwarded ? round($totalDisbursed / $totalAwarded * 100) : 0 ?>% of awarded
      </small>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3 h-100">
      <small class="text-muted">Total Spent</small>
      <h3 class="mb-0 text-warning"><?= number_format($totalSpent, 0) ?></h3>
      <small class="text-muted">
        <?= $totalDisbursed ? round($totalSpent / $totalDisbursed * 100) : 0 ?>% of disbursed
      </small>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3 h-100">
      <small class="text-muted">Unspent Balance</small>
      <h3 class="mb-0 text-success"><?= number_format($unspentBalance, 0) ?></h3>
      <small class="text-muted">ETB remaining in projects</small>
    </div>
  </div>
</div>

<!-- ============ ALERTS ============ -->
<?php if ($remainingToDisburse > 0): ?>
  <div class="alert alert-warning d-flex align-items-center mb-3">
    <span class="me-3" style="font-size:1.4rem;">💸</span>
    <div class="flex-grow-1">
      <strong><?= number_format($remainingToDisburse, 0) ?> ETB</strong>
      still to be disbursed to active projects.
    </div>
    <a href="<?= BASE_URL ?>/modules/finance/disbursements.php" class="btn btn-warning btn-sm">
      Review Disbursements →
    </a>
  </div>
<?php endif; ?>

<?php if ($projectsAtRisk): ?>
  <div class="alert alert-danger d-flex align-items-center mb-4">
    <span class="me-3" style="font-size:1.4rem;">⚠️</span>
    <div class="flex-grow-1">
      <strong><?= count($projectsAtRisk) ?></strong>
      project<?= count($projectsAtRisk) == 1 ? '' : 's' ?> with >90% of disbursed funds spent.
    </div>
    <a href="#projects-at-risk" class="btn btn-danger btn-sm">View Details ↓</a>
  </div>
<?php endif; ?>

<!-- ============ RECENT ACTIVITY ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Recent Disbursements</strong>
        <a href="<?= BASE_URL ?>/modules/finance/disbursements.php" class="small">View all →</a>
      </div>
      <?php if (!$recentDisbursements): ?>
        <div class="card-body text-center text-muted small py-4">
          No disbursements recorded yet.
        </div>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($recentDisbursements as $d): ?>
            <li class="list-group-item small">
              <div class="d-flex justify-content-between">
                <div>
                  <code><?= e($d['project_code']) ?></code>
                  <span class="badge bg-info text-dark ms-1"><?= e(ucfirst($d['tranche'])) ?></span>
                  <div class="text-muted"><?= e(shorten($d['proposal_title'], 60)) ?></div>
                  <small class="text-muted">by <?= e($d['recorder']) ?></small>
                </div>
                <div class="text-end">
                  <strong class="text-info"><?= number_format((float)$d['amount'], 0) ?> ETB</strong>
                  <br><small class="text-muted"><?= e(fmt_date($d['disbursed_on'])) ?></small>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Recent Expenses</strong>
        <a href="<?= BASE_URL ?>/modules/finance/expenses.php" class="small">View all →</a>
      </div>
      <?php if (!$recentExpenses): ?>
        <div class="card-body text-center text-muted small py-4">
          No expenses recorded yet.
        </div>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($recentExpenses as $e): ?>
            <li class="list-group-item small">
              <div class="d-flex justify-content-between">
                <div>
                  <code><?= e($e['project_code']) ?></code>
                  <span class="badge bg-secondary ms-1"><?= e($e['category']) ?></span>
                  <div class="text-muted"><?= e(shorten($e['proposal_title'], 60)) ?></div>
                  <small class="text-muted">by <?= e($e['recorder']) ?></small>
                </div>
                <div class="text-end">
                  <strong class="text-warning"><?= number_format((float)$e['amount'], 0) ?> ETB</strong>
                  <br><small class="text-muted"><?= e(fmt_date($e['expense_date'])) ?></small>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============ PROJECTS AT RISK ============ -->
<?php if ($projectsAtRisk): ?>
  <div class="row g-3 mb-4" id="projects-at-risk">
    <div class="col-md-12">
      <div class="card border-danger">
        <div class="card-header bg-danger text-white">
          <strong>⚠️ Projects with High Spending (>90% of disbursed used)</strong>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Project Code</th>
                <th class="text-end">Awarded</th>
                <th class="text-end">Disbursed</th>
                <th class="text-end">Spent</th>
                <th class="text-end">Balance</th>
                <th class="text-center">% Used</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($projectsAtRisk as $p):
                $pct = $p['disbursed'] > 0 ? round($p['spent'] / $p['disbursed'] * 100) : 0;
              ?>
                <tr>
                  <td><code class="small"><?= e($p['project_code']) ?></code></td>
                  <td class="text-end small"><?= number_format((float)$p['funds_awarded'], 0) ?></td>
                  <td class="text-end small"><?= number_format((float)$p['disbursed'], 0) ?></td>
                  <td class="text-end small text-warning"><?= number_format((float)$p['spent'], 0) ?></td>
                  <td class="text-end small text-success">
                    <?= number_format((float)$p['disbursed'] - (float)$p['spent'], 0) ?>
                  </td>
                  <td class="text-center">
                    <span class="badge bg-<?= $pct >= 95 ? 'danger' : 'warning' ?>">
                      <?= $pct ?>%
                    </span>
                  </td>
                  <td class="text-end">
                    <a href="<?= BASE_URL ?>/modules/finance/project.php?id=<?= (int)$p['id'] ?>"
                       class="btn btn-sm btn-outline-danger">Details</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>