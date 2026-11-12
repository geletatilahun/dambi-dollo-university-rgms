<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('coordinator','college','director','vp');

$user = current_user();
$role = $user['role'];

// ============ WHICH STATUSES THIS ROLE ACTS ON ============
if ($role === 'coordinator') {
    $myStatuses = ['submitted', 'under_review'];
    $statusLabels = ['submitted' => 'New Submission', 'under_review' => 'Under Review'];
} else {
    $statusMap = [
      'college'  => ['college_review',  'College Endorsement'],
      'director' => ['director_review', 'Director Review'],
      'vp'       => ['vp_review',       'VP Final Approval'],
    ];
    $myStatuses = [$statusMap[$role][0]];
    $statusLabels = [$statusMap[$role][0] => $statusMap[$role][1]];
}

$in = implode(',', array_fill(0, count($myStatuses), '?'));

// ============ COUNTS ============
$stmt = $pdo->prepare("SELECT COUNT(*) FROM proposals WHERE status IN ($in)");
$stmt->execute($myStatuses);
$totalItems = (int)$stmt->fetchColumn();

// ============ PAGINATION ============
$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalItems / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ============ SORT ============
$sort = $_GET['sort'] ?? 'oldest';
$orderBy = $sort === 'newest' ? 'p.submitted_at DESC' : 'p.submitted_at ASC';

// ============ SEARCH ============
$search = trim($_GET['q'] ?? '');
$where  = "WHERE p.status IN ($in)";
$params = $myStatuses;

if ($search !== '') {
    $where .= " AND (p.title LIKE ? OR p.proposal_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// ============ FETCH ============
$stmt = $pdo->prepare("
    SELECT p.id, p.proposal_code, p.title, p.total_budget, p.submitted_at, p.status,
           u.full_name AS researcher_name, u.email AS researcher_email
    FROM proposals p
    JOIN users u ON u.id = p.researcher_id
    $where
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$items = $stmt->fetchAll();

// ============ TOTAL BUDGET ============
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_budget),0) FROM proposals p $where");
$stmt->execute($params);
$totalBudget = (float)$stmt->fetchColumn();

// Helper: days waiting
function days_waiting($submittedAt) {
    if (!$submittedAt) return '—';
    $diff = time() - strtotime($submittedAt);
    $days = floor($diff / 86400);
    if ($days < 1)  return 'Today';
    if ($days == 1) return '1 day';
    return $days . ' days';
}

include __DIR__ . '/../../includes/header.php';
?>

<!-- ============ HEADER ============ -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">
      <?= e(ucfirst($role)) ?> Approval Queue
      <?php if ($totalItems): ?>
        <span class="badge bg-warning text-dark ms-2"><?= $totalItems ?> pending</span>
      <?php endif; ?>
    </h3>
    <small class="text-muted">
      <?php if ($totalItems): ?>
        <?= $totalItems ?> proposal<?= $totalItems == 1 ? '' : 's' ?> awaiting your action
        · Total budget: <?= number_format($totalBudget, 0) ?> ETB
      <?php else: ?>
        No proposals waiting for your action
      <?php endif; ?>
    </small>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/modules/proposals/list.php" class="btn btn-outline-secondary btn-sm">
      View All Proposals
    </a>
  </div>
</div>

<?php if (!$items): ?>

  <!-- ============ EMPTY STATE ============ -->
  <div class="card">
    <div class="card-body text-center py-5">
      <div style="font-size:4rem;">🎉</div>
      <h4 class="mt-3">All caught up!</h4>
      <p class="text-muted">
        There are no proposals waiting for your review right now.
        You'll be notified as soon as a new one arrives.
      </p>
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary btn-sm mt-2">Back to Dashboard</a>
    </div>
  </div>

<?php else: ?>

  <!-- ============ FILTERS ============ -->
  <form class="card card-body mb-3" method="get">
    <div class="row g-2">
      <div class="col-md-6">
        <input type="text" name="q" class="form-control"
               placeholder="Search by title or code..."
               value="<?= e($search) ?>">
      </div>
      <div class="col-md-3">
        <select name="sort" class="form-select">
          <option value="oldest" <?= $sort==='oldest' ? 'selected':'' ?>>Oldest first (priority)</option>
          <option value="newest" <?= $sort==='newest' ? 'selected':'' ?>>Newest first</option>
        </select>
      </div>
      <div class="col-md-3 d-grid">
        <button class="btn btn-outline-primary">Filter</button>
      </div>
    </div>
  </form>

  <!-- ============ TABLE ============ -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:150px">Code</th>
            <th>Title</th>
            <th style="width:150px">Researcher</th>
            <th class="text-end" style="width:110px">Budget (ETB)</th>
            <th style="width:100px">Waiting</th>
            <th style="width:120px">Status</th>
            <th style="width:100px" class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $p):
            $days = days_waiting($p['submitted_at']);
            $overdue = $days !== 'Today' && $days !== '1 day' && $days !== '—' && (int)$days > 7;
          ?>
            <tr>
              <td><code class="small text-nowrap"><?= e($p['proposal_code']) ?></code></td>
              <td>
                <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= (int)$p['id'] ?>"
                   class="text-decoration-none fw-semibold"
                   title="<?= e($p['title']) ?>">
                  <?= e(shorten($p['title'], 80)) ?>
                </a>
              </td>
              <td class="small">
                <?= e($p['researcher_name']) ?><br>
                <small class="text-muted"><?= e($p['researcher_email']) ?></small>
              </td>
              <td class="text-end"><?= number_format((float)$p['total_budget'], 2) ?></td>
              <td>
                <span class="badge bg-<?= $overdue ? 'danger' : 'secondary' ?>">
                  <?= e($days) ?>
                </span>
              </td>
              <td>
                <span class="badge bg-<?= status_badge($p['status']) ?>">
                  <?= e(ucwords(str_replace('_',' ', $p['status']))) ?>
                </span>
              </td>
              <td class="text-end">
                <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= (int)$p['id'] ?>"
                   class="btn btn-sm btn-primary">Review →</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- ============ PAGINATION ============ -->
    <?php if ($totalPages > 1): ?>
      <div class="card-footer">
        <nav>
          <ul class="pagination pagination-sm mb-0 justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= qs(['page' => $page - 1]) ?>">«</a>
            </li>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
              <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= qs(['page' => $i]) ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= qs(['page' => $page + 1]) ?>">»</a>
            </li>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>