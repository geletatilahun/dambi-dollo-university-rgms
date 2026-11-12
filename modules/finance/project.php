<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('finance');

$user = current_user();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger', 'Invalid project.'); redirect('/modules/finance/projects.php'); }

// ============ LOAD PROJECT ============
$stmt = $pdo->prepare("
    SELECT p.*, pr.title, pr.proposal_code, pr.id AS proposal_id,
           u.full_name AS leader_name, u.email AS leader_email
    FROM projects p
    JOIN proposals pr ON pr.id = p.proposal_id
    JOIN users u ON u.id = p.leader_id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$proj = $stmt->fetch();
if (!$proj) { set_flash('danger', 'Project not found.'); redirect('/modules/finance/projects.php'); }

// ============ BUDGET ITEMS (planned) ============
$stmt = $pdo->prepare("
    SELECT category, SUM(total_cost) AS planned
    FROM budget_items
    WHERE proposal_id = ?
    GROUP BY category
    ORDER BY planned DESC
");
$stmt->execute([$proj['proposal_id']]);
$plannedByCategory = $stmt->fetchAll();

// ============ ACTUAL SPENDING BY CATEGORY ============
$stmt = $pdo->prepare("
    SELECT category, SUM(amount) AS actual, COUNT(*) AS cnt
    FROM expenses
    WHERE project_id = ?
    GROUP BY category
");
$stmt->execute([$id]);
$actualByCategory = [];
foreach ($stmt->fetchAll() as $r) {
    $actualByCategory[$r['category']] = $r;
}

// ============ MERGE INTO CATEGORY ROWS ============
$categoryRows = [];
foreach ($plannedByCategory as $p) {
    $cat = $p['category'];
    $categoryRows[$cat] = [
        'category' => $cat,
        'planned'  => (float)$p['planned'],
        'actual'   => (float)($actualByCategory[$cat]['actual'] ?? 0),
        'cnt'      => (int)($actualByCategory[$cat]['cnt'] ?? 0),
    ];
}
// Add categories with actuals but no plan
foreach ($actualByCategory as $cat => $a) {
    if (!isset($categoryRows[$cat])) {
        $categoryRows[$cat] = [
            'category' => $cat,
            'planned'  => 0,
            'actual'   => (float)$a['actual'],
            'cnt'      => (int)$a['cnt'],
        ];
    }
}

// ============ TRANSACTIONS ============
$stmt = $pdo->prepare("
    SELECT 'disbursement' AS type, d.id, d.amount, d.disbursed_on AS tx_date,
           d.tranche AS category, d.reference AS ref, d.notes,
           u.full_name AS recorder
    FROM disbursements d
    JOIN users u ON u.id = d.recorded_by
    WHERE d.project_id = ?
    UNION ALL
    SELECT 'expense' AS type, e.id, e.amount, e.expense_date AS tx_date,
           e.category, e.vendor AS ref, e.notes,
           u.full_name AS recorder
    FROM expenses e
    JOIN users u ON u.id = e.recorded_by
    WHERE e.project_id = ?
    ORDER BY tx_date DESC, id DESC
    LIMIT 50
");
$stmt->execute([$id, $id]);
$transactions = $stmt->fetchAll();

// ============ TOTALS ============
$disbursed = 0; $spent = 0;
foreach ($transactions as $t) {
    if ($t['type'] === 'disbursement') $disbursed += (float)$t['amount'];
    else $spent += (float)$t['amount'];
}
$awarded = (float)$proj['funds_awarded'];
$balance = $awarded - $disbursed;
$availableToSpend = $disbursed - $spent;

// ============ EXPORT CSV ============
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="project_'
           . preg_replace('/[^A-Za-z0-9_-]/', '', $proj['project_code']) . '_'
           . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Project', $proj['project_code'], $proj['title']]);
    fputcsv($out, ['Awarded', number_format($awarded, 2)]);
    fputcsv($out, ['Disbursed', number_format($disbursed, 2)]);
    fputcsv($out, ['Spent', number_format($spent, 2)]);
    fputcsv($out, []);
    fputcsv($out, ['Category', 'Planned (ETB)', 'Actual (ETB)', 'Variance', '% Used']);
    foreach ($categoryRows as $r) {
        $var = $r['planned'] - $r['actual'];
        $pct = $r['planned'] > 0 ? round($r['actual'] / $r['planned'] * 100) : 0;
        fputcsv($out, [$r['category'], number_format($r['planned'], 2),
                       number_format($r['actual'], 2),
                       number_format($var, 2), $pct . '%']);
    }
    fclose($out);
    exit;
}

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
  <div class="d-flex gap-2">
    <a href="<?= qs(['export' => 'csv']) ?>" class="btn btn-outline-secondary btn-sm">⬇ Export CSV</a>
    <a href="<?= BASE_URL ?>/modules/finance/projects.php" class="btn btn-secondary btn-sm">← Back</a>
  </div>
</div>

<!-- ============ TOP STATS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card card-stat p-3">
      <small class="text-muted">Total Awarded</small>
      <h3 class="mb-0 text-primary"><?= number_format($awarded, 0) ?></h3>
      <small class="text-muted">ETB</small>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3">
      <small class="text-muted">Disbursed</small>
      <h3 class="mb-0 text-info"><?= number_format($disbursed, 0) ?></h3>
      <small class="text-muted">
        <?= $awarded ? round($disbursed / $awarded * 100) : 0 ?>% of awarded
      </small>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3">
      <small class="text-muted">Spent</small>
      <h3 class="mb-0 text-warning"><?= number_format($spent, 0) ?></h3>
      <small class="text-muted">
        <?= $disbursed ? round($spent / $disbursed * 100) : 0 ?>% of disbursed
      </small>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3">
      <small class="text-muted">Available to Spend</small>
      <h3 class="mb-0 text-success"><?= number_format($availableToSpend, 0) ?></h3>
      <small class="text-muted">ETB remaining</small>
    </div>
  </div>
</div>

<!-- ============ SUMMARY BAR ============ -->
<div class="card mb-4">
  <div class="card-body">
    <div class="d-flex justify-content-between small mb-1">
      <span>Awarded: <strong><?= number_format($awarded, 0) ?> ETB</strong></span>
      <span>Disbursed: <strong><?= number_format($disbursed, 0) ?> ETB</strong></span>
      <span>Spent: <strong><?= number_format($spent, 0) ?> ETB</strong></span>
    </div>
    <?php
      $awardedPct   = $awarded > 0 ? min(100, $disbursed / $awarded * 100) : 0;
      $disbursedPct = $disbursed > 0 ? min(100, $spent / $disbursed * 100) : 0;
    ?>
    <div class="progress" style="height:22px;">
      <div class="progress-bar bg-info" style="width:<?= $awardedPct ?>%"
           title="Disbursed: <?= number_format($disbursed, 0) ?> ETB">
        Disbursed
      </div>
      <div class="progress-bar bg-warning" style="width:<?= $disbursedPct ?>%"
           title="Spent: <?= number_format($spent, 0) ?> ETB">
        Spent
      </div>
    </div>
    <div class="d-flex justify-content-between small mt-1">
      <span class="text-muted">Balance to disburse: <strong><?= number_format($balance, 0) ?> ETB</strong></span>
      <span class="text-muted">Available to spend: <strong><?= number_format($availableToSpend, 0) ?> ETB</strong></span>
    </div>
  </div>
</div>

<!-- ============ BUDGET VS ACTUALS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-12">
    <div class="card">
      <div class="card-header"><strong>Budget vs. Actuals by Category</strong></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Category</th>
              <th class="text-end" style="width:120px">Planned (ETB)</th>
              <th class="text-end" style="width:120px">Actual (ETB)</th>
              <th class="text-end" style="width:120px">Variance</th>
              <th style="width:200px">Usage</th>
              <th class="text-center" style="width:70px">Items</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$categoryRows): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">No budget data.</td></tr>
            <?php else: foreach ($categoryRows as $r):
              $planned = $r['planned'];
              $actual  = $r['actual'];
              $variance = $planned - $actual;
              $pct = $planned > 0 ? round($actual / $planned * 100) : ($actual > 0 ? 100 : 0);
              $barColor = $pct > 100 ? 'danger' : ($pct >= 80 ? 'warning' : 'success');
            ?>
              <tr>
                <td><strong><?= e($r['category']) ?></strong></td>
                <td class="text-end small"><?= number_format($planned, 2) ?></td>
                <td class="text-end small text-warning">
                  <strong><?= number_format($actual, 2) ?></strong>
                </td>
                <td class="text-end small text-<?= $variance >= 0 ? 'success' : 'danger' ?>">
                  <?= $variance >= 0 ? '' : '-' ?><?= number_format(abs($variance), 2) ?>
                </td>
                <td>
                  <div class="progress" style="height:8px;">
                    <div class="progress-bar bg-<?= $barColor ?>"
                         style="width:<?= min($pct, 100) ?>%"></div>
                  </div>
                  <small class="text-muted"><?= $pct ?>% used</small>
                </td>
                <td class="text-center">
                  <span class="badge bg-secondary"><?= $r['cnt'] ?></span>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ============ TRANSACTIONS ============ -->
<div class="row g-3">
  <div class="col-md-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Transaction History (Last 50)</strong>
        <div class="d-flex gap-3 small">
          <a href="<?= BASE_URL ?>/modules/finance/disbursement_create.php?project_id=<?= $id ?>"
             class="text-decoration-none">+ Disbursement</a>
          <a href="<?= BASE_URL ?>/modules/finance/expense_create.php?project_id=<?= $id ?>"
             class="text-decoration-none">+ Expense</a>
        </div>
      </div>
      <?php if (!$transactions): ?>
        <div class="card-body text-center text-muted py-4">
          No transactions recorded yet.
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:100px">Date</th>
                <th style="width:110px">Type</th>
                <th style="width:130px">Category</th>
                <th>Reference / Notes</th>
                <th style="width:140px">By</th>
                <th class="text-end" style="width:120px">Amount (ETB)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($transactions as $t):
                $isDisb = $t['type'] === 'disbursement';
              ?>
                <tr>
                  <td class="small"><?= e(fmt_date($t['tx_date'], 'd M Y')) ?></td>
                  <td>
                    <span class="badge bg-<?= $isDisb ? 'info' : 'warning' ?>">
                      <?= $isDisb ? '💸 Disbursed' : '🧾 Expense' ?>
                    </span>
                  </td>
                  <td class="small"><?= e(ucfirst($t['category'])) ?></td>
                  <td class="small">
                    <?php if ($t['ref']): ?><strong><?= e($t['ref']) ?></strong> · <?php endif; ?>
                    <?= $t['notes'] ? e(shorten($t['notes'], 50)) : '' ?>
                  </td>
                  <td class="small text-muted"><?= e($t['recorder']) ?></td>
                  <td class="text-end">
                    <strong class="text-<?= $isDisb ? 'info' : 'warning' ?>">
                      <?= $isDisb ? '+' : '−' ?> <?= number_format((float)$t['amount'], 2) ?>
                    </strong>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>