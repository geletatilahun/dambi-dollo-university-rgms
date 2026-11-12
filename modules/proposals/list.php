<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$user = current_user();
$role = $user['role'];

// ============ FILTERS ============
$statusFilter     = $_GET['status'] ?? '';
$researcherFilter = (int)($_GET['researcher_id'] ?? 0);
$search           = trim($_GET['q'] ?? '');
$sort             = $_GET['sort'] ?? 'created_desc';
$page             = max(1, (int)($_GET['page'] ?? 1));
$perPage          = 10;

// ============ SORT MAP ============
$sortMap = [
  'created_desc'   => 'p.created_at DESC',
  'created_asc'    => 'p.created_at ASC',
  'title_asc'      => 'p.title ASC',
  'title_desc'     => 'p.title DESC',
  'budget_desc'    => 'p.total_budget DESC',
  'budget_asc'     => 'p.total_budget ASC',
  'submitted_desc' => 'p.submitted_at DESC',
  'submitted_asc'  => 'p.submitted_at ASC',
];
$orderBy = $sortMap[$sort] ?? 'p.created_at DESC';

// ============ WHERE ============
$where  = "WHERE 1=1";
$params = [];

if ($role === 'researcher') {
    $where .= " AND p.researcher_id = ?";
    $params[] = $user['id'];
} elseif ($role === 'reviewer') {
    $where .= " AND p.id IN (SELECT proposal_id FROM review_assignments WHERE reviewer_id = ?)";
    $params[] = $user['id'];
}

if ($statusFilter !== '') {
    $where .= " AND p.status = ?";
    $params[] = $statusFilter;
}
if ($researcherFilter > 0 && $role !== 'researcher') {
    $where .= " AND p.researcher_id = ?";
    $params[] = $researcherFilter;
}
if ($search !== '') {
    $where .= " AND (p.title LIKE ? OR p.proposal_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// ============ COUNT ============
$stmt = $pdo->prepare("SELECT COUNT(*) FROM proposals p $where");
$stmt->execute($params);
$totalRows  = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ============ FETCH ============
$sql = "SELECT p.id, p.proposal_code, p.title, p.status, p.total_budget,
               p.submitted_at, p.created_at, u.full_name AS researcher_name
        FROM proposals p
        JOIN users u ON u.id = p.researcher_id
        $where
        ORDER BY $orderBy
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$proposals = $stmt->fetchAll();

// ============ RESEARCHERS (staff filter) ============
$researchers = [];
if ($role !== 'researcher') {
    $researchers = $pdo->query("
        SELECT DISTINCT u.id, u.full_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE r.name = 'researcher'
        ORDER BY u.full_name
    ")->fetchAll();
}

// ============ EXPORT CSV ============
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="proposals_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Code','Title','Researcher','Budget (ETB)','Status','Stage','Submitted']);

    $expStmt = $pdo->prepare("
        SELECT p.proposal_code, p.title, u.full_name, p.total_budget, p.status, p.submitted_at
        FROM proposals p JOIN users u ON u.id = p.researcher_id
        $where ORDER BY $orderBy
    ");
    $expStmt->execute($params);
    foreach ($expStmt as $row) {
        fputcsv($out, [
          $row['proposal_code'], $row['title'], $row['full_name'],
          number_format((float)$row['total_budget'], 2),
          $row['status'],
          stage_label($row['status'])[0],
          $row['submitted_at'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// ============ STAGE LABEL (with — for terminal states) ============
function stage_label($status) {
    $map = [
        'submitted'       => ['Coordinator',        'primary'],
        'under_review'    => ['Reviewer(s)',        'info'],
        'college_review'  => ['College',            'info'],
        'director_review' => ['Director',           'warning'],
        'vp_review'       => ['VP',                 'warning'],
        'returned'        => ['Researcher (revise)','danger'],
        'draft'           => ['—',                  'secondary'],
        'approved'        => ['—',                  'secondary'],
        'rejected'        => ['—',                  'secondary'],
        'project_created' => ['—',                  'secondary'],
    ];
    return $map[$status] ?? ['—', 'secondary'];
}

// ============ ACTION REQUIRED ROW HIGHLIGHT ============
function is_my_action($status, $role) {
    $map = [
      'coordinator' => ['submitted','under_review'],
      'college'     => ['college_review'],
      'director'    => ['director_review'],
      'vp'          => ['vp_review'],
      'reviewer'    => ['under_review'],
      'researcher'  => ['returned'],
    ];
    return isset($map[$role]) && in_array($status, $map[$role], true);
}

include __DIR__ . '/../../includes/header.php';

$title = ($role === 'researcher') ? 'My Proposals' : 'All Proposals';
$statuses = ['draft','submitted','under_review','college_review','director_review',
             'vp_review','approved','rejected','returned','project_created'];
?>

<!-- ============ HEADER ============ -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="mb-0"><?= e($title) ?></h3>
    <small class="text-muted">
      Showing <?= count($proposals) ?> of <?= $totalRows ?> proposal<?= $totalRows == 1 ? '' : 's' ?>
      <?php if ($statusFilter || $search || $researcherFilter): ?>
        ·
        <?php if ($statusFilter): ?>
          <span class="badge bg-secondary">Status: <?= e(ucwords(str_replace('_',' ', $statusFilter))) ?></span>
        <?php endif; ?>
        <?php if ($search): ?>
          <span class="badge bg-secondary">Search: "<?= e($search) ?>"</span>
        <?php endif; ?>
        <?php if ($researcherFilter): ?>
          <?php
            $name = '';
            foreach ($researchers as $r) if ((int)$r['id'] === $researcherFilter) $name = $r['full_name'];
          ?>
          <span class="badge bg-secondary">Researcher: <?= e($name) ?></span>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/modules/proposals/list.php" class="small ms-1">clear</a>
      <?php endif; ?>
    </small>
  </div>
  <div class="d-flex gap-2">
    <?php if ($role === 'researcher'): ?>
      <a href="<?= BASE_URL ?>/modules/proposals/create.php" class="btn btn-primary">
        + New Proposal
      </a>
    <?php endif; ?>
    <a href="<?= qs(['export' => 'csv']) ?>" class="btn btn-outline-secondary">
      ⬇ Export CSV
    </a>
  </div>
</div>

<!-- ============ FILTERS ============ -->
<form class="card card-body mb-3" method="get">
  <div class="row g-2">
    <div class="<?= $role !== 'researcher' ? 'col-md-3' : 'col-md-4' ?>">
      <input type="text" name="q" class="form-control"
             placeholder="Search by title or code..."
             value="<?= e($search) ?>">
    </div>

    <?php if ($role !== 'researcher'): ?>
      <div class="col-md-3">
        <select name="researcher_id" class="form-select">
          <option value="0">— All Researchers —</option>
          <?php foreach ($researchers as $r): ?>
            <option value="<?= (int)$r['id'] ?>"
              <?= $researcherFilter === (int)$r['id'] ? 'selected' : '' ?>>
              <?= e($r['full_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <div class="<?= $role !== 'researcher' ? 'col-md-2' : 'col-md-3' ?>">
      <select name="status" class="form-select">
        <option value="">— All Statuses —</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>>
            <?= e(ucwords(str_replace('_',' ', $s))) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="<?= $role !== 'researcher' ? 'col-md-2' : 'col-md-3' ?>">
      <select name="sort" class="form-select">
        <option value="created_desc"   <?= $sort==='created_desc'   ? 'selected':'' ?>>Newest first</option>
        <option value="created_asc"    <?= $sort==='created_asc'    ? 'selected':'' ?>>Oldest first</option>
        <option value="title_asc"      <?= $sort==='title_asc'      ? 'selected':'' ?>>Title A→Z</option>
        <option value="title_desc"     <?= $sort==='title_desc'     ? 'selected':'' ?>>Title Z→A</option>
        <option value="budget_desc"    <?= $sort==='budget_desc'    ? 'selected':'' ?>>Budget high→low</option>
        <option value="budget_asc"     <?= $sort==='budget_asc'     ? 'selected':'' ?>>Budget low→high</option>
        <option value="submitted_desc" <?= $sort==='submitted_desc' ? 'selected':'' ?>>Recently submitted</option>
      </select>
    </div>

    <div class="<?= $role !== 'researcher' ? 'col-md-2' : 'col-md-2' ?> d-grid">
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
          <?php if ($role !== 'researcher'): ?>
            <th style="width:150px">Researcher</th>
          <?php endif; ?>
          <th class="text-end" style="width:110px">Budget (ETB)</th>
          <th style="width:120px">Status</th>
          <th style="width:130px">Stage</th>
          <th style="width:110px">Submitted</th>
          <th style="width:100px" class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$proposals): ?>
          <tr>
            <td colspan="<?= $role === 'researcher' ? 7 : 8 ?>" class="text-center text-muted py-5">
              <div style="font-size:2.5rem;">📄</div>
              <div class="mt-2">No proposals found matching the filters.</div>
              <?php if ($role === 'researcher'): ?>
                <a href="<?= BASE_URL ?>/modules/proposals/create.php"
                   class="btn btn-primary btn-sm mt-3">Create your first proposal</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php else: foreach ($proposals as $p): ?>
          <?php
            [$stageText, $stageColor] = stage_label($p['status']);
            $myAction = is_my_action($p['status'], $role);
          ?>
          <tr class="<?= $myAction ? 'table-warning' : '' ?>">
            <td><code class="small text-nowrap"><?= e($p['proposal_code']) ?></code></td>
            <td>
              <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= (int)$p['id'] ?>"
                 class="text-decoration-none fw-semibold"
                 title="<?= e($p['title']) ?>">
                <?= e(shorten($p['title'], 90)) ?>
              </a>
              <?php if ($myAction): ?>
                <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem;">Awaiting you</span>
              <?php endif; ?>
            </td>
            <?php if ($role !== 'researcher'): ?>
              <td class="small"><?= e($p['researcher_name']) ?></td>
            <?php endif; ?>
            <td class="text-end"><?= number_format((float)$p['total_budget'], 2) ?></td>
            <td>
              <span class="badge bg-<?= status_badge($p['status']) ?>">
                <?= e(ucwords(str_replace('_',' ', $p['status']))) ?>
              </span>
            </td>
            <td>
              <?php if ($stageText !== '—'): ?>
                <span class="badge bg-<?= $stageColor ?>">
                  <?= e($stageText) ?>
                </span>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="small text-muted">
              <?= $p['submitted_at'] ? e(date('d M Y', strtotime($p['submitted_at']))) : '—' ?>
            </td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= (int)$p['id'] ?>"
                   class="btn btn-outline-primary">View</a>
                <?php if ($role === 'researcher' && in_array($p['status'], ['draft','returned'], true)): ?>
                  <a href="<?= BASE_URL ?>/modules/proposals/edit.php?id=<?= (int)$p['id'] ?>"
                     class="btn btn-outline-secondary">Edit</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
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
          <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            if ($start > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            for ($i = $start; $i <= $end; $i++):
          ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= qs(['page' => $i]) ?>"><?= $i ?></a>
            </li>
          <?php endfor;
            if ($end < $totalPages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= qs(['page' => $page + 1]) ?>">»</a>
          </li>
        </ul>
      </nav>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>