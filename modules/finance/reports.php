<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('finance');

$user = current_user();

// ============ PORTFOLIO SUMMARY ============
$stmt = $pdo->query("
    SELECT
      COUNT(*) AS total_projects,
      SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_projects,
      COALESCE(SUM(funds_awarded), 0) AS total_awarded
    FROM projects
");
$summary = $stmt->fetch();

$totalDisbursed = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM disbursements")->fetchColumn();
$totalSpent     = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses")->fetchColumn();

// ============ PER-PROJECT ============
$stmt = $pdo->query("
    SELECT p.id, p.project_code, p.funds_awarded, p.status,
           pr.title,
           u.full_name AS leader_name,
           COALESCE((SELECT SUM(amount) FROM disbursements WHERE project_id = p.id), 0) AS disbursed,
           COALESCE((SELECT SUM(amount) FROM expenses WHERE project_id = p.id), 0) AS spent
    FROM projects p
    JOIN proposals pr ON pr.id = p.proposal_id
    JOIN users u ON u.id = p.leader_id
    ORDER BY p.created_at DESC
");
$projects = $stmt->fetchAll();

// ============ EXPENSES BY CATEGORY ============
$stmt = $pdo->query("
    SELECT category, SUM(amount) AS total, COUNT(*) AS cnt
    FROM expenses
    GROUP BY category
    ORDER BY total DESC
");
$byCategory = $stmt->fetchAll();
$grandCategoryTotal = 0;
foreach ($byCategory as $c) $grandCategoryTotal += (float)$c['total'];

// ============ EXPORT CSV ============
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="portfolio_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');

    fputcsv($out, ['PORTFOLIO SUMMARY']);
    fputcsv($out, ['Total Projects', $summary['total_projects']]);
    fputcsv($out, ['Active Projects', $summary['active_projects']]);
    fputcsv($out, ['Total Awarded (ETB)', number_format((float)$summary['total_awarded'], 2)]);
    fputcsv($out, ['Total Disbursed (ETB)', number_format($totalDisbursed, 2)]);
    fputcsv($out, ['Total Spent (ETB)', number_format($totalSpent, 2)]);
    fputcsv($out, []);

    fputcsv($out, ['PER-PROJECT']);
    fputcsv($out, ['Project Code','Title','Leader','Awarded','Disbursed','Spent','Spent %']);
    foreach ($projects as $p) {
        $awarded = (float)$p['funds_awarded'];
        $disb    = (float)$p['disbursed'];
        $spent   = (float)$p['spent'];
        $pct     = $disb > 0 ? round($spent / $disb * 100) : 0;
        fputcsv($out, [
            $p['project_code'], $p['title'], $p['leader_name'],
            number_format($awarded, 2),
            number_format($disb, 2),
            number_format($spent, 2),
            $pct . '%',
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['EXPENSES BY CATEGORY']);
    fputcsv($out, ['Category','Total (ETB)','Count']);
    foreach ($byCategory as $c) {
        fputcsv($out, [
            pretty_category($c['category']),
            number_format((float)$c['total'], 2),
            $c['cnt']
        ]);
    }
    fclose($out);
    exit;
}

include __DIR__ . '/../../includes/header.php';
?>

<!-- ============ HEADER ============ -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">Portfolio Financial Report</h3>
    <small class="text-muted">All projects at a glance</small>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= qs(['export' => 'csv']) ?>" class="btn btn-outline-secondary">⬇ Export CSV</a>
    <a href="<?= BASE_URL ?>/modules/finance/projects.php" class="btn btn-secondary">← Projects</a>
  </div>
</div>

<!-- ============ TOP STATS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-2">
    <div class="card card-stat p-3">
      <small class="text-muted">Projects</small>
      <h3 class="mb-0"><?= (int)$summary['total_projects'] ?></h3>
      <small class="text-muted"><?= (int)$summary['active_projects'] ?> active</small>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card card-stat p-3">
      <small class="text-muted">Total Awarded</small>
      <h3 class="mb-0 text-primary"><?= number_format((float)$summary['total_awarded'], 0) ?></h3>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card card-stat p-3">
      <small class="text-muted">Disbursed</small>
      <h3 class="mb-0 text-info"><?= number_format($totalDisbursed, 0) ?></h3>
      <small class="text-muted">
        <?= $summary['total_awarded'] ? round($totalDisbursed / $summary['total_awarded'] * 100) : 0 ?>%
      </small>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card card-stat p-3">
      <small class="text-muted">Spent</small>
      <h3 class="mb-0 text-warning"><?= number_format($totalSpent, 0) ?></h3>
      <small class="text-muted">
        <?= $totalDisbursed ? round($totalSpent / $totalDisbursed * 100) : 0 ?>%
      </small>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card card-stat p-3">
      <small class="text-muted">Balance</small>
      <h3 class="mb-0 text-success">
        <?= number_format((float)$summary['total_awarded'] - $totalDisbursed, 0) ?>
      </h3>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card card-stat p-3">
      <small class="text-muted">Available</small>
      <h3 class="mb-0 text-success"><?= number_format($totalDisbursed - $totalSpent, 0) ?></h3>
    </div>
  </div>
</div>

<!-- ============ CHARTS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><strong>Expenses by Category</strong></div>
      <div class="card-body">
        <?php if (!$byCategory): ?>
          <p class="text-center text-muted small py-4 mb-0">No expense data yet.</p>
        <?php else: ?>
          <div style="position:relative;height:280px;">
            <canvas id="chartCategories"></canvas>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><strong>Project Financial Status</strong></div>
      <div class="card-body">
        <?php if (!$projects): ?>
          <p class="text-center text-muted small py-4 mb-0">No projects yet.</p>
        <?php else: ?>
          <div style="position:relative;height:280px;">
            <canvas id="chartProjects"></canvas>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ============ PROJECTS BREAKDOWN + CATEGORY BREAKDOWN ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-8">
    <div class="card">
      <div class="card-header"><strong>Per-Project Breakdown</strong></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Project</th>
              <th class="text-end">Awarded</th>
              <th class="text-end">Disbursed</th>
              <th class="text-end">Spent</th>
              <th style="width:150px">% Used</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$projects): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No projects.</td></tr>
            <?php else: foreach ($projects as $p):
              $awarded   = (float)$p['funds_awarded'];
              $disbursed = (float)$p['disbursed'];
              $spent     = (float)$p['spent'];
              $pct       = $disbursed > 0 ? round($spent / $disbursed * 100) : 0;
              $barColor  = $pct >= 95 ? 'danger' : ($pct >= 75 ? 'warning' : 'success');
            ?>
              <tr>
                <td>
                  <a href="<?= BASE_URL ?>/modules/finance/project.php?id=<?= (int)$p['id'] ?>"
                     class="text-decoration-none fw-semibold"
                     title="<?= e($p['title']) ?>">
                    <?= e($p['project_code']) ?>
                  </a>
                  <br><small class="text-muted"><?= e(shorten($p['title'], 50)) ?></small>
                </td>
                <td class="text-end small"><?= number_format($awarded, 0) ?></td>
                <td class="text-end small"><?= number_format($disbursed, 0) ?></td>
                <td class="text-end small text-warning"><?= number_format($spent, 0) ?></td>
                <td>
                  <div class="progress" style="height:8px;">
                    <div class="progress-bar bg-<?= $barColor ?>" style="width:<?= min($pct, 100) ?>%"></div>
                  </div>
                  <small class="text-muted"><?= $pct ?>%</small>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ============ CATEGORY BREAKDOWN ============ -->
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-header"><strong>Expenses by Category</strong></div>
      <?php if (!$byCategory): ?>
        <div class="card-body text-center text-muted small py-4">
          No expenses recorded yet.
        </div>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($byCategory as $c):
            $pct = $grandCategoryTotal > 0 ? round((float)$c['total'] / $grandCategoryTotal * 100) : 0;
          ?>
            <li class="list-group-item">
              <div class="d-flex justify-content-between mb-1">
                <strong class="small"><?= e(pretty_category($c['category'])) ?></strong>
                <span class="small text-muted">
                  <?= number_format((float)$c['total'], 0) ?> ETB
                </span>
              </div>
              <div class="progress" style="height:6px;">
                <div class="progress-bar bg-secondary" style="width:<?= $pct ?>%"></div>
              </div>
              <small class="text-muted">
                <?= $pct ?>% · <?= (int)$c['cnt'] ?> item<?= $c['cnt'] == 1 ? '' : 's' ?>
              </small>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============ CHART.JS ============ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
<?php if ($byCategory): ?>
// ---- Expenses by Category (Doughnut) ----
(function() {
  var labels = <?= json_encode(array_map(function($c) { return pretty_category($c['category']); }, $byCategory)) ?>;
  var values = <?= json_encode(array_map(function($c) { return (float)$c['total']; }, $byCategory)) ?>;

  new Chart(document.getElementById('chartCategories'), {
    type: 'doughnut',
    data: {
      labels: labels,
      datasets: [{
        data: values,
        backgroundColor: [
          '#1a4a8a','#f5b500','#10b981','#ef4444','#8b5cf6',
          '#06b6d4','#f97316','#84cc16','#ec4899','#64748b'
        ],
        borderWidth: 2,
        borderColor: '#fff'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
        tooltip: {
          callbacks: {
            label: function(ctx) {
              var total = ctx.dataset.data.reduce(function(a,b){ return a+b; }, 0);
              var pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
              return ctx.label + ': ' + ctx.parsed.toLocaleString() + ' ETB (' + pct + '%)';
            }
          }
        }
      }
    }
  });
})();
<?php endif; ?>

<?php if ($projects): ?>
// ---- Project Financial Status (Bar) ----
(function() {
  var labels    = <?= json_encode(array_map(function($p) { return $p['project_code']; }, $projects)) ?>;
  var awarded   = <?= json_encode(array_map(function($p) { return (float)$p['funds_awarded']; }, $projects)) ?>;
  var disbursed = <?= json_encode(array_map(function($p) { return (float)$p['disbursed']; }, $projects)) ?>;
  var spent     = <?= json_encode(array_map(function($p) { return (float)$p['spent']; }, $projects)) ?>;

  new Chart(document.getElementById('chartProjects'), {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        { label: 'Awarded',   data: awarded,   backgroundColor: '#1a4a8a' },
        { label: 'Disbursed', data: disbursed, backgroundColor: '#06b6d4' },
        { label: 'Spent',     data: spent,     backgroundColor: '#f5b500' }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          ticks: { callback: function(v) { return (v/1000) + 'k'; } }
        }
      },
      plugins: {
        legend: { position: 'bottom' },
        tooltip: {
          callbacks: {
            label: function(ctx) {
              return ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString() + ' ETB';
            }
          }
        }
      }
    }
  });
})();
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>