<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login('coordinator');

$user = current_user();

// ============ STATS ============
$pendingCount     = (int)$pdo->query("SELECT COUNT(*) FROM proposals WHERE status IN ('submitted','under_review')")->fetchColumn();
$underReviewCount = (int)$pdo->query("SELECT COUNT(*) FROM proposals WHERE status = 'under_review'")->fetchColumn();
$totalProposals   = (int)$pdo->query("SELECT COUNT(*) FROM proposals")->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM approvals WHERE approver_id = ? AND stage = 'coordinator'");
$stmt->execute([$user['id']]);
$myDecisionsCount = (int)$stmt->fetchColumn();

// ============ PIPELINE ============
$pipeline = [];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM proposals GROUP BY status") as $r) {
    $pipeline[$r['status']] = (int)$r['c'];
}

$totalPipeline = array_sum($pipeline);
$inProgress    = ($pipeline['submitted'] ?? 0) + ($pipeline['under_review'] ?? 0)
               + ($pipeline['college_review'] ?? 0) + ($pipeline['director_review'] ?? 0)
               + ($pipeline['vp_review'] ?? 0);
$closed        = ($pipeline['approved'] ?? 0) + ($pipeline['rejected'] ?? 0)
               + ($pipeline['project_created'] ?? 0);

// ============ PENDING MY DECISION ============
$stmt = $pdo->query("
    SELECT p.id, p.proposal_code, p.title, p.status, p.total_budget, p.submitted_at,
           u.full_name AS researcher_name
    FROM proposals p
    JOIN users u ON u.id = p.researcher_id
    WHERE p.status IN ('submitted','under_review')
    ORDER BY p.submitted_at ASC
    LIMIT 5
");
$pending = $stmt->fetchAll();

// ============ MY RECENT DECISIONS ============
$stmt = $pdo->prepare("
    SELECT a.decision, a.comment, a.decided_at,
           p.id AS proposal_id, p.proposal_code, p.title
    FROM approvals a
    JOIN proposals p ON p.id = a.proposal_id
    WHERE a.approver_id = ? AND a.stage = 'coordinator'
    ORDER BY a.decided_at DESC
    LIMIT 5
");
$stmt->execute([$user['id']]);
$myDecisions = $stmt->fetchAll();

// ============ PENDING REVIEWERS ============
$stmt = $pdo->query("
    SELECT ra.id, ra.due_date, ra.status,
           u.full_name AS reviewer_name,
           p.id AS proposal_id, p.proposal_code, p.title
    FROM review_assignments ra
    JOIN users u ON u.id = ra.reviewer_id
    JOIN proposals p ON p.id = ra.proposal_id
    WHERE ra.status = 'pending'
    ORDER BY ra.due_date ASC
    LIMIT 5
");
$pendingReviews = $stmt->fetchAll();

// ============ NOTIFICATIONS ============
$stmt = $pdo->prepare("
    SELECT id, title, message, link, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute([$user['id']]);
$notifs = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<!-- ============ ACTION REQUIRED BANNER ============ -->
<?php if ($pendingCount > 0): ?>
  <div class="alert alert-warning d-flex align-items-center mb-4">
    <span class="me-3" style="font-size:1.6rem;">⏳</span>
    <div class="flex-grow-1">
      <strong>Action required:</strong>
      You have <strong><?= $pendingCount ?></strong> proposal<?= $pendingCount == 1 ? '' : 's' ?> awaiting your decision.
    </div>
    <a href="<?= BASE_URL ?>/modules/approvals/queue.php" class="btn btn-warning btn-sm">
      Go to Approval Queue →
    </a>
  </div>
<?php endif; ?>

<!-- ============ HEADER ============ -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">Welcome, <?= e($user['full_name']) ?></h3>
    <small class="text-muted">
      Coordinator Dashboard — Research Grant Management
      <?php if (!empty($user['last_login'])): ?>
        · Last login: <?= e(fmt_date($user['last_login'], 'd M Y H:i')) ?>
      <?php endif; ?>
    </small>
  </div>
</div>

<!-- ============ STAT CARDS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <a href="<?= BASE_URL ?>/modules/approvals/queue.php" class="text-decoration-none">
      <div class="card card-stat p-3 h-100">
        <small class="text-muted">Pending My Decision</small>
        <h3 class="mb-0 <?= $pendingCount ? 'text-warning' : '' ?>"><?= $pendingCount ?></h3>
      </div>
    </a>
  </div>
  <div class="col-md-3">
    <a href="<?= BASE_URL ?>/modules/proposals/list.php?status=under_review" class="text-decoration-none">
      <div class="card card-stat p-3 h-100">
        <small class="text-muted">Under Review</small>
        <h3 class="mb-0 text-info"><?= $underReviewCount ?></h3>
      </div>
    </a>
  </div>
  <div class="col-md-3">
    <a href="<?= BASE_URL ?>/modules/proposals/list.php" class="text-decoration-none">
      <div class="card card-stat p-3 h-100">
        <small class="text-muted">My Decisions</small>
        <h3 class="mb-0"><?= $myDecisionsCount ?></h3>
      </div>
    </a>
  </div>
  <div class="col-md-3">
    <a href="<?= BASE_URL ?>/modules/proposals/list.php" class="text-decoration-none">
      <div class="card card-stat p-3 h-100">
        <small class="text-muted">Total Proposals</small>
        <h3 class="mb-0"><?= $totalProposals ?></h3>
      </div>
    </a>
  </div>
</div>

<div class="row g-3 mb-4">

  <!-- ============ PENDING MY DECISION ============ -->
  <div class="col-md-8">
    <div class="card" style="min-height: 280px;">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Proposals Awaiting My Decision</strong>
        <a href="<?= BASE_URL ?>/modules/approvals/queue.php" class="small">View all →</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:140px">Code</th>
              <th>Title</th>
              <th style="width:130px">Researcher</th>
              <th class="text-end" style="width:100px">Budget</th>
              <th style="width:130px">Status</th>
              <th style="width:90px"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$pending): ?>
              <tr>
                <td colspan="6" class="text-center text-muted py-5">
                  <div style="font-size:2rem;">🎉</div>
                  <div class="mt-2">All caught up! No proposals awaiting your decision.</div>
                </td>
              </tr>
            <?php else: foreach ($pending as $p): ?>
              <tr>
                <td><code class="small text-nowrap"><?= e($p['proposal_code']) ?></code></td>
                <td>
                  <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= (int)$p['id'] ?>"
                     class="text-decoration-none fw-semibold"
                     title="<?= e($p['title']) ?>">
                    <?= e(shorten($p['title'], 70)) ?>
                  </a>
                </td>
                <td class="small"><?= e($p['researcher_name']) ?></td>
                <td class="text-end small"><?= number_format((float)$p['total_budget'], 0) ?></td>
                <td>
                  <span class="badge bg-<?= status_badge($p['status']) ?>">
                    <?= e(ucwords(str_replace('_',' ', $p['status']))) ?>
                  </span>
                </td>
                <td class="text-end">
                  <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= (int)$p['id'] ?>"
                     class="btn btn-sm btn-outline-primary">Review</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ============ NOTIFICATIONS ============ -->
  <div class="col-md-4">
    <div class="card h-100">
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
            <?php [$icon] = notif_icon($n['title']); ?>
            <li class="list-group-item small <?= $n['is_read'] ? '' : 'bg-light' ?>">
              <div class="d-flex gap-2">
                <span style="font-size:1.2rem;"><?= $icon ?></span>
                <div class="flex-grow-1">
                  <div class="d-flex justify-content-between align-items-start">
                    <strong><?= e($n['title']) ?></strong>
                    <?php if (!$n['is_read']): ?>
                      <span class="badge bg-primary" style="font-size:.6rem;">New</span>
                    <?php endif; ?>
                  </div>
                  <div class="text-muted"><?= e(shorten($n['message'], 70)) ?></div>
                  <small class="text-muted">
                    <?= e(date('d M H:i', strtotime($n['created_at']))) ?>
                  </small>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============ PIPELINE OVERVIEW ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong>Proposal Pipeline</strong>
        <div class="d-flex gap-3 small text-muted">
          <span>Total: <strong class="text-dark"><?= $totalPipeline ?></strong></span>
          <span>In Progress: <strong class="text-warning"><?= $inProgress ?></strong></span>
          <span>Closed: <strong class="text-success"><?= $closed ?></strong></span>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-2 text-center">
          <?php
            $stages = [
              'submitted'       => ['Submitted',    'primary'],
              'under_review'    => ['Under Review', 'info'],
              'college_review'  => ['College',      'info'],
              'director_review' => ['Director',     'warning'],
              'vp_review'       => ['VP',           'warning'],
              'approved'        => ['Approved',     'success'],
              'returned'        => ['Returned',     'danger'],
              'rejected'        => ['Rejected',     'danger'],
            ];
            foreach ($stages as $key => $info):
              $count = $pipeline[$key] ?? 0;
          ?>
            <div class="col-md-3 col-6">
              <div class="border rounded p-2 bg-light">
                <small class="text-muted d-block"><?= $info[0] ?></small>
                <h5 class="mb-0 text-<?= $count ? $info[1] : 'muted' ?>">
                  <?= $count ?>
                </h5>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">

  <!-- ============ MY RECENT DECISIONS ============ -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><strong>My Recent Decisions</strong></div>
      <?php if (!$myDecisions): ?>
        <div class="card-body text-center text-muted small py-4">
          No decisions recorded yet.
        </div>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($myDecisions as $d): ?>
            <?php
              $icon  = $d['decision'] === 'approve' ? '✅' : ($d['decision'] === 'reject' ? '❌' : '↩');
              $color = $d['decision'] === 'approve' ? 'success' : ($d['decision'] === 'reject' ? 'danger' : 'warning');
            ?>
            <li class="list-group-item small">
              <div class="d-flex gap-2">
                <span style="font-size:1.2rem;"><?= $icon ?></span>
                <div class="flex-grow-1">
                  <div>
                    <span class="badge bg-<?= $color ?>"><?= e(ucfirst($d['decision'])) ?></span>
                    <code class="ms-1"><?= e($d['proposal_code']) ?></code>
                  </div>
                  <div class="text-muted"><?= e(shorten($d['title'], 60)) ?></div>
                  <small class="text-muted">
                    <?= e(date('d M H:i', strtotime($d['decided_at']))) ?>
                  </small>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <!-- ============ PENDING REVIEWERS ============ -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><strong>Reviewers In Progress</strong></div>
      <?php if (!$pendingReviews): ?>
        <div class="card-body text-center text-muted small py-4">
          No pending review assignments.
        </div>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($pendingReviews as $r): ?>
            <li class="list-group-item small">
              <div class="d-flex justify-content-between">
                <div>
                  <strong><?= e($r['reviewer_name']) ?></strong>
                  <div class="text-muted">
                    <code><?= e($r['proposal_code']) ?></code>
                    — <?= e(shorten($r['title'], 50)) ?>
                  </div>
                  <?php if ($r['due_date']): ?>
                    <small class="text-muted">
                      📅 Due: <?= e(date('d M Y', strtotime($r['due_date']))) ?>
                    </small>
                  <?php endif; ?>
                </div>
                <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= (int)$r['proposal_id'] ?>"
                   class="btn btn-sm btn-outline-primary align-self-start">View</a>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>