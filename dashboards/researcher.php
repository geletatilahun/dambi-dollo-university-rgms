<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login('researcher');

$user = current_user();

// ============ STATS ============
$stmt = $pdo->prepare("
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts,
      SUM(CASE WHEN status IN ('submitted','under_review','college_review','director_review','vp_review') THEN 1 ELSE 0 END) AS in_progress,
      SUM(CASE WHEN status IN ('approved','project_created') THEN 1 ELSE 0 END) AS approved,
      SUM(CASE WHEN status IN ('approved','project_created') THEN total_budget ELSE 0 END) AS total_awarded,
      SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) AS returned
    FROM proposals
    WHERE researcher_id = ?
");
$stmt->execute([$user['id']]);
$stats = $stmt->fetch();

// ============ RECENT PROPOSALS ============
$stmt = $pdo->prepare("
    SELECT id, proposal_code, title, status, total_budget, created_at, submitted_at
    FROM proposals
    WHERE researcher_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute([$user['id']]);
$proposals = $stmt->fetchAll();

// ============ MY PROJECTS ============
$stmt = $pdo->prepare("
    SELECT p.id, p.project_code, p.funds_awarded, p.status,
           pr.title,
           (SELECT COUNT(*) FROM progress_reports WHERE project_id = p.id) AS reports_count,
           (SELECT COUNT(*) FROM progress_reports WHERE project_id = p.id AND review_status = 'returned') AS returned_reports
    FROM projects p
    JOIN proposals pr ON pr.id = p.proposal_id
    WHERE p.leader_id = ?
    ORDER BY p.created_at DESC
    LIMIT 3
");
$stmt->execute([$user['id']]);
$projects = $stmt->fetchAll();

$projectCount = (int)$pdo->prepare("SELECT COUNT(*) FROM projects WHERE leader_id = ?")
    ->execute([$user['id']]) ? (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE leader_id = {$user['id']}")->fetchColumn() : 0;

// ============ RECENT NOTIFICATIONS ============
$stmt = $pdo->prepare("
    SELECT id, title, message, link, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 3
");
$stmt->execute([$user['id']]);
$notifs = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- ============ ACTION REQUIRED ALERT ============ -->
<?php if ((int)$stats['returned'] > 0): ?>
  <div class="alert alert-warning d-flex align-items-center mb-4">
    <span class="me-2" style="font-size:1.4rem;">⚠️</span>
    <div>
      <strong>Action required:</strong>
      You have <?= (int)$stats['returned'] ?> proposal(s) returned for revision.
      <a href="<?= BASE_URL ?>/modules/proposals/list.php?status=returned" class="alert-link ms-1">
        Review them now →
      </a>
    </div>
  </div>
<?php endif; ?>

<!-- ============ PAGE HEADER ============ -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">Welcome, <?= e($user['full_name']) ?></h3>
    <small class="text-muted">Here's an overview of your research portfolio</small>
  </div>
  <a href="<?= BASE_URL ?>/modules/proposals/create.php" class="btn btn-primary">
    + New Proposal
  </a>
</div>

<!-- ============ STAT CARDS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card card-stat p-3 h-100">
      <small class="text-muted">Total Proposals</small>
      <h3 class="mb-0"><?= (int)$stats['total'] ?></h3>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3 h-100">
      <small class="text-muted">In Progress</small>
      <h3 class="mb-0"><?= (int)$stats['in_progress'] ?></h3>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3 h-100">
      <small class="text-muted">Approved</small>
      <h3 class="mb-0 text-success"><?= (int)$stats['approved'] ?></h3>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3 h-100">
      <small class="text-muted">Total Awarded</small>
      <h3 class="mb-0"><?= number_format((float)$stats['total_awarded'], 0) ?>
        <small class="text-muted" style="font-size:.7em;">ETB</small>
      </h3>
    </div>
  </div>
</div>

<div class="row g-3">

  <!-- ============ PROPOSALS TABLE ============ -->
  <div class="col-md-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>My Recent Proposals</strong>
        <a href="<?= BASE_URL ?>/modules/proposals/list.php" class="small">View all →</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:140px">Code</th>
              <th>Title</th>
              <th style="width:130px">Status</th>
              <th style="width:100px">Created</th>
              <th style="width:70px"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$proposals): ?>
              <tr>
                <td colspan="5" class="text-center text-muted py-5">
                  <div style="font-size:2rem;">📄</div>
                  <div class="mt-2">You haven't submitted any proposals yet.</div>
                  <a href="<?= BASE_URL ?>/modules/proposals/create.php"
                     class="btn btn-primary btn-sm mt-3">Create your first proposal</a>
                </td>
              </tr>
            <?php else: foreach ($proposals as $p): ?>
              <tr>
                <td><code class="small"><?= e($p['proposal_code']) ?></code></td>
                <td>
                  <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= (int)$p['id'] ?>"
                     class="text-decoration-none fw-semibold d-inline-block text-truncate"
                     style="max-width:400px;">
                    <?= e($p['title']) ?>
                  </a>
                </td>
                <td>
                  <span class="badge bg-<?= status_badge($p['status']) ?>">
                    <?= e(ucwords(str_replace('_',' ', $p['status']))) ?>
                  </span>
                </td>
                <td class="small text-muted">
                  <?= e(date('d M Y', strtotime($p['created_at']))) ?>
                </td>
                <td class="text-end">
                  <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= (int)$p['id'] ?>"
                     class="btn btn-sm btn-outline-primary">View</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ============ SIDEBAR ============ -->
  <div class="col-md-4">

    <!-- Notifications -->
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Recent Notifications</strong>
        <a href="<?= BASE_URL ?>/modules/notifications/list.php" class="small">View all →</a>
      </div>
      <?php if (!$notifs): ?>
        <div class="card-body text-center text-muted small py-4">
          No notifications yet.
        </div>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($notifs as $n): ?>
            <li class="list-group-item small <?= $n['is_read'] ? '' : 'bg-light' ?>">
              <div class="d-flex justify-content-between">
                <strong><?= e($n['title']) ?></strong>
                <?php if (!$n['is_read']): ?>
                  <span class="badge bg-primary" style="font-size:.65rem;">New</span>
                <?php endif; ?>
              </div>
              <div class="text-muted"><?= e(shorten($n['message'], 90)) ?></div>
              <small class="text-muted"><?= e(date('d M H:i', strtotime($n['created_at']))) ?></small>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <!-- My Projects -->
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>My Projects (<?= (int)$projectCount ?>)</strong>
        <a href="<?= BASE_URL ?>/modules/projects/my_projects.php" class="small">View all →</a>
      </div>
      <?php if (!$projects): ?>
        <div class="card-body text-center text-muted small py-4">
          No active projects yet.<br>
          <span class="text-muted" style="font-size:.8rem;">
            Approved proposals become projects.
          </span>
        </div>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($projects as $p): ?>
            <li class="list-group-item small">
              <a href="<?= BASE_URL ?>/modules/projects/view.php?id=<?= (int)$p['id'] ?>"
                 class="text-decoration-none">
                <strong><?= e($p['project_code']) ?></strong>
              </a>
              <br>
              <span class="text-muted"><?= e(shorten($p['title'], 60)) ?></span>
              <div class="d-flex justify-content-between mt-1">
                <span class="badge bg-<?= $p['status'] === 'active' ? 'success' : 'secondary' ?>">
                  <?= e(ucfirst($p['status'])) ?>
                </span>
                <span class="text-muted small"><?= number_format((float)$p['funds_awarded'], 0) ?> ETB</span>
              </div>
              <?php if ((int)$p['returned_reports'] > 0): ?>
                <div class="mt-1">
                  <span class="badge bg-danger">
                    <?= (int)$p['returned_reports'] ?> report(s) returned
                  </span>
                </div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <!-- Quick Links -->
    <div class="card">
      <div class="card-header"><strong>Quick Links</strong></div>
      <div class="card-body d-grid gap-2">
        <a href="<?= BASE_URL ?>/modules/proposals/create.php" class="btn btn-outline-primary btn-sm">
          + New Proposal
        </a>
        <a href="<?= BASE_URL ?>/modules/proposals/list.php" class="btn btn-outline-primary btn-sm">
          My Proposals
        </a>
        <a href="<?= BASE_URL ?>/modules/projects/my_projects.php" class="btn btn-outline-primary btn-sm">
          My Projects
        </a>
        <a href="<?= BASE_URL ?>/modules/profile/view.php" class="btn btn-outline-secondary btn-sm">
          My Profile
        </a>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>