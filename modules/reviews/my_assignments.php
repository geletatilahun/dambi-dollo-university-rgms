<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('reviewer');

$user = current_user();

// ============ FILTERS ============
$filter = $_GET['filter'] ?? 'pending'; // pending | completed | all
$search = trim($_GET['q'] ?? '');
$sort   = $_GET['sort'] ?? 'due_asc';

// ============ SORT MAP ============
$sortMap = [
  'due_asc'    => 'ra.due_date IS NULL, ra.due_date ASC, ra.assigned_at ASC',
  'due_desc'   => 'ra.due_date DESC, ra.assigned_at DESC',
  'assigned'   => 'ra.assigned_at DESC',
];
$orderBy = $sortMap[$sort] ?? $sortMap['due_asc'];

// ============ WHERE ============
$where  = "WHERE ra.reviewer_id = ?";
$params = [$user['id']];

if ($filter === 'pending') {
    $where .= " AND ra.status = 'pending'";
} elseif ($filter === 'completed') {
    $where .= " AND ra.status = 'completed'";
}

if ($search !== '') {
    $where .= " AND (p.title LIKE ? OR p.proposal_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// ============ FETCH ============
$stmt = $pdo->prepare("
    SELECT ra.id AS assignment_id, ra.status AS assignment_status,
           ra.due_date, ra.assigned_at,
           p.id AS proposal_id, p.proposal_code, p.title, p.abstract,
           p.total_budget, p.timeline_months,
           u.full_name AS researcher_name,
           r.score, r.comments, r.merge_recommended, r.submitted_at
    FROM review_assignments ra
    JOIN proposals p ON p.id = ra.proposal_id
    JOIN users u ON u.id = p.researcher_id
    LEFT JOIN reviews r ON r.assignment_id = ra.id
    $where
    ORDER BY $orderBy
");
$stmt->execute($params);
$items = $stmt->fetchAll();

// ============ COUNTS ============
$stmt = $pdo->prepare("SELECT COUNT(*) FROM review_assignments WHERE reviewer_id = ? AND status = 'pending'");
$stmt->execute([$user['id']]);
$pendingCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM review_assignments WHERE reviewer_id = ? AND status = 'completed'");
$stmt->execute([$user['id']]);
$completedCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM review_assignments
    WHERE reviewer_id = ? AND status = 'pending'
      AND due_date IS NOT NULL AND due_date < CURDATE()
");
$stmt->execute([$user['id']]);
$overdueCount = (int)$stmt->fetchColumn();

// ============ HELPERS ============
function due_label($dueDate) {
    if (!$dueDate) return ['No deadline', 'secondary'];
    $days = floor((strtotime($dueDate) - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0)   return [abs($days) . ' day' . (abs($days)==1?'':'s') . ' overdue', 'danger'];
    if ($days == 0)  return ['Due today', 'warning'];
    if ($days == 1)  return ['Due tomorrow', 'warning'];
    if ($days <= 3)  return ['Due in ' . $days . ' days', 'warning'];
    return ['Due in ' . $days . ' days', 'secondary'];
}

include __DIR__ . '/../../includes/header.php';
?>

<!-- ============ HEADER ============ -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">My Review Assignments</h3>
    <small class="text-muted">
      <?php if ($overdueCount): ?>
        <span class="text-danger">⚠ <?= $overdueCount ?> overdue</span> ·
      <?php endif; ?>
      <?= $pendingCount ?> pending ·
      <?= $completedCount ?> completed
    </small>
  </div>
</div>

<!-- ============ FILTER TABS ============ -->
<ul class="nav nav-pills mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $filter === 'pending' ? 'active' : '' ?>" href="?filter=pending">
      Pending
      <?php if ($pendingCount): ?>
        <span class="badge bg-<?= $overdueCount ? 'danger' : 'primary' ?> ms-1"><?= $pendingCount ?></span>
      <?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $filter === 'completed' ? 'active' : '' ?>" href="?filter=completed">
      Completed
      <?php if ($completedCount): ?>
        <span class="badge bg-secondary ms-1"><?= $completedCount ?></span>
      <?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all">
      All
    </a>
  </li>
</ul>

<!-- ============ SEARCH ============ -->
<?php if ($filter !== 'completed'): ?>
<form class="card card-body mb-3" method="get">
  <input type="hidden" name="filter" value="<?= e($filter) ?>">
  <div class="row g-2">
    <div class="col-md-6">
      <input type="text" name="q" class="form-control"
             placeholder="Search by title or code..."
             value="<?= e($search) ?>">
    </div>
    <div class="col-md-4">
      <select name="sort" class="form-select">
        <option value="due_asc" <?= $sort==='due_asc'?'selected':'' ?>>Due date (earliest)</option>
        <option value="due_desc" <?= $sort==='due_desc'?'selected':'' ?>>Due date (latest)</option>
        <option value="assigned" <?= $sort==='assigned'?'selected':'' ?>>Recently assigned</option>
      </select>
    </div>
    <div class="col-md-2 d-grid">
      <button class="btn btn-outline-primary">Filter</button>
    </div>
  </div>
</form>
<?php endif; ?>

<!-- ============ LIST ============ -->
<?php if (!$items): ?>

  <div class="card">
    <div class="card-body text-center py-5">
      <div style="font-size:4rem;">📭</div>
      <h5 class="mt-3">
        <?php if ($filter === 'pending'): ?>
          No pending review assignments
        <?php elseif ($filter === 'completed'): ?>
          No completed reviews yet
        <?php else: ?>
          No review assignments
        <?php endif; ?>
      </h5>
      <p class="text-muted">
        <?php if ($filter === 'pending'): ?>
          You're all caught up! You'll be notified when the coordinator assigns you a proposal.
        <?php elseif ($filter === 'completed'): ?>
          Once you've scored a proposal, it will appear here.
        <?php else: ?>
          You haven't been assigned any reviews yet.
        <?php endif; ?>
      </p>
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary btn-sm">Back to Dashboard</a>
    </div>
  </div>

<?php else: ?>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:140px">Code</th>
            <th>Title</th>
            <th style="width:150px">Researcher</th>
            <th class="text-end" style="width:110px">Budget (ETB)</th>
            <?php if ($filter !== 'completed'): ?>
              <th style="width:140px">Due</th>
            <?php endif; ?>
            <?php if ($filter !== 'pending'): ?>
              <th class="text-center" style="width:80px">Score</th>
            <?php endif; ?>
            <th style="width:100px">Status</th>
            <th style="width:120px" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <?php
              [$dueText, $dueColor] = due_label($it['due_date']);
              $isCompleted = $it['assignment_status'] === 'completed';
            ?>
            <tr>
              <td><code class="small text-nowrap"><?= e($it['proposal_code']) ?></code></td>
              <td>
                <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= (int)$it['proposal_id'] ?>"
                   class="text-decoration-none fw-semibold"
                   title="<?= e($it['title']) ?>">
                  <?= e(shorten($it['title'], 80)) ?>
                </a>
                <?php if (!empty($it['merge_recommended'])): ?>
                  <span class="badge bg-info text-dark ms-1" style="font-size:.65rem;">Merge suggested</span>
                <?php endif; ?>
              </td>
              <td class="small"><?= e($it['researcher_name']) ?></td>
              <td class="text-end small"><?= number_format((float)$it['total_budget'], 0) ?></td>
              <?php if ($filter !== 'completed'): ?>
                <td>
                  <span class="badge bg-<?= $dueColor ?>">
                    <?= e($dueText) ?>
                  </span>
                </td>
              <?php endif; ?>
              <?php if ($filter !== 'pending'): ?>
                <td class="text-center">
                  <?php if ($it['score'] !== null): ?>
                    <span class="badge bg-<?= $it['score'] >= 70 ? 'success' : ($it['score'] >= 50 ? 'warning' : 'danger') ?>">
                      <?= e($it['score']) ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted small">—</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
              <td>
                <span class="badge bg-<?= $isCompleted ? 'success' : 'warning' ?>">
                  <?= e(ucfirst($it['assignment_status'])) ?>
                </span>
              </td>
              <td class="text-end">
                <a href="<?= BASE_URL ?>/modules/reviews/submit.php?id=<?= (int)$it['proposal_id'] ?>"
                   class="btn btn-sm <?= $isCompleted ? 'btn-outline-secondary' : 'btn-primary' ?>">
                  <?= $isCompleted ? 'View' : '📝 Review Now' ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>