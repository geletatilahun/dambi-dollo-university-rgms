<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('finance');

$user = current_user();

// ============ FILTERS ============
$projectFilter = (int)($_GET['project_id'] ?? 0);
$trancheFilter = $_GET['tranche'] ?? '';
$search        = trim($_GET['q'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 15;

// ============ WHERE ============
$where  = "WHERE 1=1";
$params = [];

if ($projectFilter > 0) {
    $where .= " AND d.project_id = ?";
    $params[] = $projectFilter;
}
if ($trancheFilter !== '') {
    $where .= " AND d.tranche = ?";
    $params[] = $trancheFilter;
}
if ($search !== '') {
    $where .= " AND (pr.title LIKE ? OR p.project_code LIKE ? OR d.reference LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// ============ COUNT ============
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM disbursements d
    JOIN projects p ON p.id = d.project_id
    JOIN proposals pr ON pr.id = p.proposal_id
    $where
");
$stmt->execute($params);
$totalRows  = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ============ FETCH ============
$stmt = $pdo->prepare("
    SELECT d.*, p.project_code, pr.title AS proposal_title,
           u.full_name AS recorder_name
    FROM disbursements d
    JOIN projects p ON p.id = d.project_id
    JOIN proposals pr ON pr.id = p.proposal_id
    JOIN users u ON u.id = d.recorded_by
    $where
    ORDER BY d.disbursed_on DESC, d.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$items = $stmt->fetchAll();

// ============ TOTAL ============
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(d.amount),0)
    FROM disbursements d
    JOIN projects p ON p.id = d.project_id
    JOIN proposals pr ON pr.id = p.proposal_id
    $where
");
$stmt->execute($params);
$filteredTotal = (float)$stmt->fetchColumn();

// ============ PROJECTS (for filter) ============
$projects = $pdo->query("
    SELECT p.id, p.project_code, pr.title
    FROM projects p
    JOIN proposals pr ON pr.id = p.proposal_id
    ORDER BY p.project_code DESC
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<!-- ============ HEADER ============ -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">Disbursements</h3>
    <small class="text-muted">
      Showing <?= count($items) ?> of <?= $totalRows ?> disbursement<?= $totalRows == 1 ? '' : 's' ?>
      · Total: <strong><?= number_format($filteredTotal, 2) ?> ETB</strong>
    </small>
  </div>
  <a href="<?= BASE_URL ?>/modules/finance/disbursement_create.php" class="btn btn-primary">
    + Record Disbursement
  </a>
</div>

<!-- ============ FILTERS ============ -->
<form class="card card-body mb-3" method="get">
  <div class="row g-2">
    <div class="col-md-4">
      <input type="text" name="q" class="form-control"
             placeholder="Search by project code, title, or reference..."
             value="<?= e($search) ?>">
    </div>
    <div class="col-md-4">
      <select name="project_id" class="form-select">
        <option value="0">— All Projects —</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $projectFilter === (int)$p['id'] ? 'selected' : '' ?>>
            <?= e($p['project_code']) ?> — <?= e(shorten($p['title'], 50)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="tranche" class="form-select">
        <option value="">— All Tranches —</option>
        <?php foreach (['first','second','third','final','other'] as $t): ?>
          <option value="<?= $t ?>" <?= $trancheFilter === $t ? 'selected' : '' ?>>
            <?= e(ucfirst($t)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 d-grid">
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
          <th style="width:110px">Date</th>
          <th style="width:150px">Project</th>
          <th>Notes / Reference</th>
          <th style="width:100px">Tranche</th>
          <th style="width:120px">Recorded By</th>
          <th class="text-end" style="width:130px">Amount (ETB)</th>
          <th style="width:110px" class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$items): ?>
          <tr>
            <td colspan="7" class="text-center text-muted py-5">
              <div style="font-size:2.5rem;">💸</div>
              <div class="mt-2">No disbursements found.</div>
              <a href="<?= BASE_URL ?>/modules/finance/disbursement_create.php"
                 class="btn btn-primary btn-sm mt-3">Record your first disbursement</a>
            </td>
          </tr>
        <?php else: foreach ($items as $d): ?>
          <tr>
            <td class="small"><?= e(fmt_date($d['disbursed_on'], 'd M Y')) ?></td>
            <td>
              <code class="small"><?= e($d['project_code']) ?></code>
              <br><small class="text-muted" title="<?= e($d['proposal_title']) ?>">
                <?= e(shorten($d['proposal_title'], 40)) ?>
              </small>
            </td>
            <td class="small">
              <?php if ($d['reference']): ?>
                <strong><?= e($d['reference']) ?></strong><br>
              <?php endif; ?>
              <?= $d['notes'] ? e(shorten($d['notes'], 60)) : '<span class="text-muted">—</span>' ?>
            </td>
            <td>
              <span class="badge bg-info text-dark"><?= e(ucfirst($d['tranche'])) ?></span>
            </td>
            <td class="small"><?= e($d['recorder_name']) ?></td>
            <td class="text-end">
              <strong class="text-info"><?= number_format((float)$d['amount'], 2) ?></strong>
            </td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>/modules/finance/disbursement_edit.php?id=<?= (int)$d['id'] ?>"
                   class="btn btn-outline-primary">Edit</a>
                <a href="<?= BASE_URL ?>/modules/finance/disbursement_delete.php?id=<?= (int)$d['id'] ?>"
                   class="btn btn-outline-danger"
                   onclick="return confirm('Delete this disbursement? This cannot be undone.');">Delete</a>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>