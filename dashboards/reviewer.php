<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login('reviewer');

$user = current_user();

// ============ STATS ============
// Pending assignments
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM review_assignments
    WHERE reviewer_id = ? AND status = 'pending'
");
$stmt->execute([$user['id']]);
$pendingCount = (int)$stmt->fetchColumn();

// Completed assignments (lifetime)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM review_assignments
    WHERE reviewer_id = ? AND status = 'completed'
");
$stmt->execute([$user['id']]);
$completedCount = (int)$stmt->fetchColumn();

// Overdue assignments (past due date, still pending)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM review_assignments
    WHERE reviewer_id = ? AND status = 'pending'
      AND due_date IS NOT NULL AND due_date < CURDATE()
");
$stmt->execute([$user['id']]);
$overdueCount = (int)$stmt->fetchColumn();

// Average score given
$stmt = $pdo->prepare("
    SELECT AVG(r.score) FROM reviews r
    JOIN review_assignments ra ON ra.id = r.assignment_id
    WHERE ra.reviewer_id = ?
");
$stmt->execute([$user['id']]);
$avgScore = round((float)$stmt->fetchColumn(), 1);

// ============ PENDING ASSIGNMENTS ============
$stmt = $pdo->prepare("
    SELECT ra.id AS assignment_id, ra.due_date, ra.assigned_at,
           p.id AS proposal_id, p.proposal_code, p.title, p.abstract,
           p.total_budget, p.timeline_months,
           u.full_name AS researcher_name
    FROM review_assignments ra
    JOIN proposals p ON p.id = ra.proposal_id
    JOIN users u ON u.id = p.researcher_id
    WHERE ra.reviewer_id = ? AND ra.status = 'pending'
    ORDER BY ra.due_date IS NULL, ra.due_date ASC, ra.assigned_at ASC
    LIMIT 10
");
$stmt->execute([$user['id']]);
$pending = $stmt->fetchAll();

// ============ COMPLETED ASSIGNMENTS ============
$stmt = $pdo->prepare("
    SELECT ra.id AS assignment_id, ra.assigned_at,
           p.id AS proposal_id, p.proposal_code, p.title,
           r.score, r.comments, r.submitted_at, r.merge_recommended
    FROM review_assignments ra
    JOIN proposals p ON p.id = ra.proposal_id
    LEFT JOIN reviews r ON r.assignment_id = ra.id
    WHERE ra.reviewer_id = ? AND ra.status = 'completed'
    ORDER BY r.submitted_at DESC
    LIMIT 5
");
$stmt->execute([$user['id']]);
$completed = $stmt->fetchAll();

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

// Helper: days until due
function days_until_due($dueDate) {
    if (!$dueDate) return null;
    $diff = strtotime($dueDate) - strtotime(date('Y-m-d'));
    return floor($diff / 86400);
}

function due_label($dueDate) {
    $days = days_until_due($dueDate);
    if ($days === null) return ['No deadline', 'secondary'];
    if ($days < 0)  return [abs($days) . ' day' . (abs($days) == 1 ? '' : 's') . ' overdue', 'danger'];
    if ($days == 0) return ['Due today', 'warning'];
    if ($days == 1) return ['Due tomorrow', 'warning'];
    if ($days <= 3) return ['Due in ' . $days . ' days', 'warning'];
    return ['Due in ' . $days . ' days', 'secondary'];
}

include __DIR__ . '/../includes/header.php';
?>

<!-- ============ ACTION REQUIRED BANNER ============ -->
<?php if ($pendingCount > 0): ?>
  <div class="alert alert-info d-flex align-items-center mb-4">
    <span class="me-3" style="font-size:1.6rem;">📝</span>
    <div class="flex-grow-1">
      <strong>You have <?= $pendingCount ?> review<?= $pendingCount == 1 ? '' : 's' ?> pending.</strong>
      <?php if ($overdueCount > 0): ?>
        <span class="text-danger ms-2">
          ⚠ <?= $overdueCount ?> overdue!
        </span>
      <?php endif; ?>
    </div>
    <a href="<?= BASE_URL ?>/modules/reviews/my_assignments.php" class="btn btn-primary btn-sm">
      Go to My Reviews →
    </a>
  </div>
<?php endif; ?>

<!-- ============ HEADER ============ -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">Welcome, <?= e($user['full_name']) ?></h3>
    <small class="text-muted">
      Reviewer Dashboard — Research Grant Management
      <?php if (!empty($user['last_login'])): ?>
        · Last login: <?= e(fmt_date($user['last_login'], 'd M Y H:i')) ?>
      <?php endif; ?>
    </small>
  </div>
  <a href="<?= BASE_URL ?>/modules/reviews/my_assignments.php" class="btn btn-outline-primary">
    View All My Assignments →
  </a>
</div>

<!-- ============ STAT CARDS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <a href="<?= BASE_URL ?>/modules/reviews/my_assignments.php" class="text-decoration-none">
      <div class="card card-stat p-3 h-100">
        <small class="text-muted">Pending Reviews</small>
        <h3 class="mb-0 <?= $pendingCount ? 'text-primary' : '' ?>"><?= $pendingCount ?></h3>
      </div>
    </a>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3 h-100">
      <small class="text-muted">Overdue</small>
      <h3 class="mb-0 <?= $overdueCount ? 'text-danger' : '' ?>"><?= $overdueCount ?></h3>
    </div>
  </div>
  <div class="col-md-3">
    <a href="<?= BASE_URL ?>/modules/reviews/my_assignments.php" class="text-decoration-none">
      <div class="card card-stat p-3 h-100">
        <small class="text-muted">Completed Reviews</small>
        <h3 class="mb-0 text-success"><?= $completedCount ?></h3>
      </div>
    </a>
  </div>
  <div class="col-md-3">
    <div class="card card-stat p-3 h-100">
      <small class="text-muted">Avg Score Given</small>
      <h3 class="mb-0 <?= $avgScore ? 'text-info' : '' ?>">
        <?= $avgScore ?: '—' ?>
        <?php if ($avgScore): ?><small class="text-muted" style="font-size:.6em;">/100</small><?php endif; ?>
      </h3>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">

  <!-- ============ PENDING ASSIGNMENTS ============ -->
  <div class="col-md-8">
    <div class="card" style="min-height: 300px;">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Proposals Assigned to Me</strong>
        <a href="<?= BASE_URL ?>/modules/reviews/my_assignments.php" class="small">View all →</a>
      </div>

      <?php if (!$pending): ?>
        <div class="card-body text-center text-muted py-5">
          <div style="font-size:2rem;">🎉</div>
          <div class="mt-2">No pending reviews. You're all caught up!</div>
        </div>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($pending as $p):
            [$dueText, $dueColor] = due_label($p['due_date']);
          ?>
            <li class="list-group-item py-3">
              <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div class="flex-grow-1">
                  <div class="d-flex gap-2 mb-1 flex-wrap">
                    <code class="small"><?= e($p['proposal_code']) ?></code>
                    <span class="badge bg-<?= $dueColor ?>"><?= e($dueText) ?></span>
                  </div>
                  <div class="fw-semibold mb-1">
                    <?= e(shorten($p['title'], 90)) ?>
                  </div>
                  <div class="text-muted small">
                    By <?= e($p['researcher_name']) ?>
                    · <?= number_format((float)$p['total_budget'], 0) ?> ETB
                    · <?= (int)$p['timeline_months'] ?> months
                  </div>
                </div>
                <div>
                  <a href="<?= BASE_URL ?>/modules/reviews/submit.php?id=<?= (int)$p['proposal_id'] ?>"
                     class="btn btn-sm btn-primary">
                    📝 Review Now
                  </a>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
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

<!-- ============ RECENTLY COMPLETED REVIEWS ============ -->
<div class="row g-3">
  <div class="col-md-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>My Recent Reviews</strong>
        <a href="<?= BASE_URL ?>/modules/reviews/my_assignments.php" class="small">View all →</a>
      </div>
      <?php if (!$completed): ?>
        <div class="card-body text-center text-muted small py-4">
          No completed reviews yet.
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:140px">Code</th>
                <th>Title</th>
                <th style="width:100px" class="text-center">Score</th>
                <th style="width:130px">Reviewed</th>
                <th style="width:100px" class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($completed as $c): ?>
                <tr>
                  <td><code class="small text-nowrap"><?= e($c['proposal_code']) ?></code></td>
                  <td>
                    <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= (int)$c['proposal_id'] ?>"
                       class="text-decoration-none"
                       title="<?= e($c['title']) ?>">
                      <?= e(shorten($c['title'], 80)) ?>
                    </a>
                    <?php if (!empty($c['merge_recommended'])): ?>
                      <span class="badge bg-info text-dark ms-1">Merge suggested</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <span class="badge bg-<?= $c['score'] >= 70 ? 'success' : ($c['score'] >= 50 ? 'warning' : 'danger') ?>">
                      <?= e($c['score']) ?>
                    </span>
                  </td>
                  <td class="small text-muted">
                    <?= e(fmt_date($c['submitted_at'], 'd M Y')) ?>
                  </td>
                  <td class="text-end">
                    <a href="<?= BASE_URL ?>/modules/reviews/submit.php?id=<?= (int)$c['proposal_id'] ?>"
                       class="btn btn-sm btn-outline-secondary">View</a>
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

<?php include __DIR__ . '/../includes/footer.php'; ?>