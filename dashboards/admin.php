<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login('admin');

$user = current_user();

// ============ PRIMARY STATS ============
$totalUsers    = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalProps    = (int) $pdo->query("SELECT COUNT(*) FROM proposals")->fetchColumn();
$totalProjects = (int) $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
$totalReviews  = (int) $pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn();

// ============ USERS BY ROLE ============
$roleCounts = $pdo->query("
    SELECT r.name, COUNT(u.id) c
    FROM roles r
    LEFT JOIN users u ON u.role_id = r.id
    GROUP BY r.id
    ORDER BY r.id
")->fetchAll();

// ============ PROPOSALS BY STATUS ============
$statusCounts = [];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM proposals GROUP BY status") as $row) {
    $statusCounts[$row['status']] = (int)$row['c'];
}

// ============ AWAITING ACTION ============
$awaiting = [
  'coordinator' => (int)$pdo->query("SELECT COUNT(*) FROM proposals WHERE status='submitted'")->fetchColumn(),
  'reviewers'   => (int)$pdo->query("SELECT COUNT(*) FROM review_assignments WHERE status='pending'")->fetchColumn(),
  'college'     => (int)$pdo->query("SELECT COUNT(*) FROM proposals WHERE status='college_review'")->fetchColumn(),
  'director'    => (int)$pdo->query("SELECT COUNT(*) FROM proposals WHERE status='director_review'")->fetchColumn(),
  'vp'          => (int)$pdo->query("SELECT COUNT(*) FROM proposals WHERE status='vp_review'")->fetchColumn(),
];

// ============ RECENT ACTIVITY ============
$recentActivity = $pdo->query("
    SELECT a.action, a.details, a.created_at, u.full_name
    FROM audit_log a
    LEFT JOIN users u ON u.id = a.user_id
    ORDER BY a.id DESC
    LIMIT 10
")->fetchAll();

// ============ TOP RESEARCHERS ============
$topResearchers = $pdo->query("
    SELECT u.full_name, COUNT(p.id) c
    FROM users u
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN proposals p ON p.researcher_id = u.id
    WHERE r.name = 'researcher'
    GROUP BY u.id
    HAVING c > 0
    ORDER BY c DESC
    LIMIT 5
")->fetchAll();

// ============ BUDGET SUMMARY ============
$budgetSummary = $pdo->query("
    SELECT
      COALESCE(SUM(CASE WHEN status NOT IN ('draft','rejected') THEN total_budget END), 0) AS requested,
      COALESCE(SUM(CASE WHEN status IN ('approved','project_created') THEN total_budget END), 0) AS approved
    FROM proposals
")->fetch();

// ============ RECENT USERS ============
$recentUsers = $pdo->query("
    SELECT u.id, u.full_name, u.email, u.created_at, r.name AS role_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    ORDER BY u.created_at DESC
    LIMIT 5
")->fetchAll();

// ============ SYSTEM HEALTH ============
$dbSizeMB = (float) $pdo->query("
    SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS mb
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<h3 class="mb-4">Admin Dashboard</h3>

<!-- ============ PRIMARY STATS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card card-stat p-3">
      <small class="text-muted">Total Users</small>
      <h3><?= $totalUsers ?></h3>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3">
      <small class="text-muted">Total Proposals</small>
      <h3><?= $totalProps ?></h3>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3">
      <small class="text-muted">Active Projects</small>
      <h3><?= $totalProjects ?></h3>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3">
      <small class="text-muted">Reviews Submitted</small>
      <h3><?= $totalReviews ?></h3>
    </div>
  </div>
</div>

<!-- ============ AWAITING ACTION + QUICK ACTIONS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header"><strong>⏳ Awaiting Action</strong></div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <a href="<?= BASE_URL ?>/modules/proposals/list.php?status=submitted"
               class="text-decoration-none text-dark">
              Proposals awaiting <strong>Coordinator</strong>
            </a>
            <span class="badge bg-<?= $awaiting['coordinator'] ? 'primary' : 'secondary' ?>">
              <?= $awaiting['coordinator'] ?>
            </span>
          </li>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <a href="<?= BASE_URL ?>/modules/reviews/my_assignments.php"
               class="text-decoration-none text-dark">
              Reviews pending with <strong>Reviewers</strong>
            </a>
            <span class="badge bg-<?= $awaiting['reviewers'] ? 'info' : 'secondary' ?>">
              <?= $awaiting['reviewers'] ?>
            </span>
          </li>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <a href="<?= BASE_URL ?>/modules/proposals/list.php?status=college_review"
               class="text-decoration-none text-dark">
              Proposals awaiting <strong>College</strong>
            </a>
            <span class="badge bg-<?= $awaiting['college'] ? 'primary' : 'secondary' ?>">
              <?= $awaiting['college'] ?>
            </span>
          </li>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <a href="<?= BASE_URL ?>/modules/proposals/list.php?status=director_review"
               class="text-decoration-none text-dark">
              Proposals awaiting <strong>Director</strong>
            </a>
            <span class="badge bg-<?= $awaiting['director'] ? 'warning' : 'secondary' ?>">
              <?= $awaiting['director'] ?>
            </span>
          </li>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <a href="<?= BASE_URL ?>/modules/proposals/list.php?status=vp_review"
               class="text-decoration-none text-dark">
              Proposals awaiting <strong>VP</strong>
            </a>
            <span class="badge bg-<?= $awaiting['vp'] ? 'warning' : 'secondary' ?>">
              <?= $awaiting['vp'] ?>
            </span>
          </li>
        </ul>
      </div>
    </div>
  </div>

  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header"><strong>⚡ Quick Actions</strong></div>
      <div class="card-body d-grid gap-2">
        <a href="<?= BASE_URL ?>/modules/users/list.php" class="btn btn-outline-primary">Manage Users</a>
        <a href="<?= BASE_URL ?>/modules/users/create.php" class="btn btn-outline-primary">Add New User</a>
        <a href="<?= BASE_URL ?>/modules/proposals/list.php" class="btn btn-outline-primary">View All Proposals</a>
        <a href="<?= BASE_URL ?>/modules/admin/audit_log.php" class="btn btn-outline-secondary">Full Audit Log</a>
      </div>
    </div>
  </div>
</div>

<!-- ============ USERS BY ROLE + PROPOSALS BY STATUS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><strong>👥 Users by Role</strong></div>
      <div class="card-body p-0">
        <table class="table mb-0">
          <thead class="table-light">
            <tr><th>Role</th><th class="text-end">Count</th></tr>
          </thead>
          <tbody>
            <?php foreach ($roleCounts as $r): ?>
              <tr>
                <td><?= e(ucfirst($r['name'])) ?></td>
                <td class="text-end"><?= (int)$r['c'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><strong>📄 Proposals by Status</strong></div>
      <div class="card-body p-0">
        <?php if (!$statusCounts): ?>
          <p class="text-muted text-center py-4 mb-0">No proposals yet.</p>
        <?php else: ?>
          <table class="table mb-0">
            <thead class="table-light">
              <tr><th>Status</th><th class="text-end">Count</th></tr>
            </thead>
            <tbody>
              <?php foreach ($statusCounts as $s => $c): ?>
                <tr>
                  <td><?= e(ucwords(str_replace('_',' ', $s))) ?></td>
                  <td class="text-end"><?= (int)$c ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ============ BUDGET + TOP RESEARCHERS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><strong>💰 Budget Summary (ETB)</strong></div>
      <div class="card-body">
        <?php
          $req = (float)$budgetSummary['requested'];
          $app = (float)$budgetSummary['approved'];
          $rate = $req > 0 ? round($app / $req * 100, 1) : 0;
        ?>
        <div class="row text-center">
          <div class="col-4">
            <small class="text-muted d-block">Requested</small>
            <h5><?= number_format($req, 0) ?></h5>
          </div>
          <div class="col-4">
            <small class="text-muted d-block">Approved</small>
            <h5><?= number_format($app, 0) ?></h5>
          </div>
          <div class="col-4">
            <small class="text-muted d-block">Approval Rate</small>
            <h5><?= $rate ?>%</h5>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><strong>🏆 Top Researchers</strong></div>
      <div class="card-body p-0">
        <?php if (!$topResearchers): ?>
          <p class="text-muted text-center py-4 mb-0">No researchers with proposals yet.</p>
        <?php else: ?>
          <table class="table mb-0">
            <thead class="table-light">
              <tr><th>Researcher</th><th class="text-end">Proposals</th></tr>
            </thead>
            <tbody>
              <?php foreach ($topResearchers as $t): ?>
                <tr>
                  <td><?= e($t['full_name']) ?></td>
                  <td class="text-end"><span class="badge bg-primary"><?= (int)$t['c'] ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ============ RECENT ACTIVITY + RECENT USERS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header"><strong>🕐 Recent Activity</strong></div>
      <div class="card-body p-0">
        <?php if (!$recentActivity): ?>
          <p class="text-muted text-center py-4 mb-0">No activity yet.</p>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($recentActivity as $a): ?>
              <li class="list-group-item small">
                <div class="d-flex justify-content-between">
                  <span>
                    <strong><?= e($a['full_name'] ?? 'System') ?></strong>
                    — <?= e(str_replace('_',' ', $a['action'])) ?>
                    <?php if ($a['details']): ?>
                      <em class="text-muted">(<?= e($a['details']) ?>)</em>
                    <?php endif; ?>
                  </span>
                  <small class="text-muted">
                    <?= e(date('d M H:i', strtotime($a['created_at']))) ?>
                  </small>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header"><strong>🆕 Recent Users</strong></div>
      <div class="card-body p-0">
        <table class="table mb-0">
          <thead class="table-light">
            <tr><th>Name</th><th>Role</th></tr>
          </thead>
          <tbody>
            <?php foreach ($recentUsers as $u): ?>
              <tr>
                <td class="small">
                  <?= e($u['full_name']) ?><br>
                  <small class="text-muted"><?= e($u['email']) ?></small>
                </td>
                <td class="small">
                  <span class="badge bg-info text-dark"><?= e(ucfirst($u['role_name'])) ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ============ SYSTEM HEALTH ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-12">
    <div class="card">
      <div class="card-body d-flex flex-wrap justify-content-between gap-2 small">
        <span><strong>PHP:</strong> <?= e(PHP_VERSION) ?></span>
        <span><strong>Server:</strong> <?= e($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') ?></span>
        <span><strong>Memory Usage:</strong> <?= number_format(memory_get_usage() / 1024 / 1024, 2) ?> MB</span>
        <span><strong>Database Size:</strong> <?= number_format($dbSizeMB, 2) ?> MB</span>
        <span><strong>Server Time:</strong> <?= e(date('d M Y H:i:s')) ?></span>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>