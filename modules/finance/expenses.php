<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('finance');

$user = current_user();

// ============ FILTERS ============
$projectFilter  = (int)($_GET['project_id'] ?? 0);
$categoryFilter = $_GET['category'] ?? '';
$search         = trim($_GET['q'] ?? '');
$dateFrom       = $_GET['from'] ?? '';
$dateTo         = $_GET['to']   ?? '';
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 15;

// ============ WHERE ============
$where  = "WHERE 1=1";
$params = [];

if ($projectFilter > 0) {
    $where .= " AND e.project_id = ?";
    $params[] = $projectFilter;
}
if ($categoryFilter !== '') {
    $where .= " AND e.category = ?";
    $params[] = $categoryFilter;
}
if ($search !== '') {
    $where .= " AND (e.description LIKE ? OR e.vendor LIKE ? OR p.project_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($dateFrom !== '') {
    $where .= " AND e.expense_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where .= " AND e.expense_date <= ?";
    $params[] = $dateTo;
}

// ============ COUNT ============
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM expenses e
    JOIN projects p ON p.id = e.project_id
    $where
");
$stmt->execute($params);
$totalRows  = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ============ FETCH ============
$stmt = $pdo->prepare("
    SELECT e.*, p.project_code, pr.title AS proposal_title,
           u.full_name AS recorder_name
    FROM expenses e
    JOIN projects p ON p.id = e.project_id
    JOIN proposals pr ON pr.id = p.proposal_id
    JOIN users u ON u.id = e.recorded_by
    $where
    ORDER BY e.expense_date DESC, e.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$items = $stmt->fetchAll();

// ============ TOTALS ============
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(e.amount),0)
    FROM expenses e
    JOIN projects p ON p.id = e.project_id
    $where
");
$stmt->execute($params);
$filteredTotal = (float)$stmt->fetchColumn();

// ============ DROPDOWNS ============
$projects = $pdo->query("
    SELECT p.id, p.project_code, pr.title
    FROM projects p
    JOIN proposals pr ON pr.id = p.proposal_id
    ORDER BY p.project_code DESC
")->fetchAll();

$categories = $pdo->query("
    SELECT DISTINCT category FROM expenses ORDER BY category
")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../../includes/header.php';
?>

<!-- ============ HEADER ============ -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">Expenses</h3>
    <small class="text-muted">
      Showing <?= count($items) ?> of <?= $totalRows ?> expense<?= $totalRows == 1 ? '' : 's' ?>
      · Total: <strong><?= number_format($filteredTotal, 2) ?> ETB</strong>
    </small>
  </div>
  <a href="<?= BASE_URL ?>/modules/finance/expense_create.php" class="btn btn-primary">
    + Record Expense
  </a>
</div>

<!-- ============ FILTERS ============ -->
<form class="card card-body mb-3" method="get">
  <div class="row g-2">
    <div class="col-md-3">
      <input type="text" name="q" class="form-control"
             placeholder="Search description, vendor, code..."
             value="<?= e($search) ?>">
    </div>
    <div class="col-md-3">
      <select name="project_id" class="form-select">
        <option value="0">— All Projects —</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $projectFilter === (int)$p['id'] ? 'selected' : '' ?>>
            <?= e($p['project_code']) ?> — <?= e(shorten($p['title'], 40)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="category" class="form-select">
        <option value="">— All Categories —</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= e($c) ?>" <?= $categoryFilter === $c ? 'selected' : '' ?>>
            <?= e($c) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <input type="date" name="from" class="form-control" placeholder="From"
             value="<?= e($dateFrom) ?>" title="From date">
    </div>
    <div class="col-md-2">
      <input type="date" name="to" class="form-control" placeholder="To"
             value="<?= e($dateTo) ?>" title="To date">
    </div>
    <div class="col-md-12 d-flex gap-2">
      <button class="btn btn-outline-primary">Filter</button>
      <?php if ($search || $projectFilter || $categoryFilter || $dateFrom || $dateTo): ?>
        <a href="<?= BASE_URL ?>/modules/finance/expenses.php" class="btn btn-link btn-sm">Clear filters</a>
      <?php endif; ?>
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
          <th style="width:130px">Category</th>
          <th>Description</th>
          <th style="width:140px">Vendor</th>
          <th class="text-end" style="width:120px">Amount (ETB)</th>
          <th style="width:80px" class="text-center">Receipt</th>
          <th style="width:110px" class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$items): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-5">
              <div style="font-size:2.5rem;">🧾</div>
              <div class="mt-2">No expenses recorded yet.</div>
              <a href="<?= BASE_URL ?>/modules/finance/expense_create.php"
                 class="btn btn-primary btn-sm mt-3">Record your first expense</a>
            </td>
          </tr>
        <?php else: foreach ($items as $e): ?>
          <tr>
            <td class="small"><?= e(fmt_date($e['expense_date'], 'd M Y')) ?></td>
            <td>
              <code class="small"><?= e($e['project_code']) ?></code>
              <br><small class="text-muted" title="<?= e($e['proposal_title']) ?>">
                <?= e(shorten($e['proposal_title'], 30)) ?>
              </small>
            </td>
            <td>
              <span class="badge bg-secondary"><?= e($e['category']) ?></span>
            </td>
            <td class="small">
              <?= e($e['description']) ?>
              <br><small class="text-muted">by <?= e($e['recorder_name']) ?></small>
            </td>
            <td class="small"><?= $e['vendor'] ? e($e['vendor']) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-end">
              <strong class="text-warning"><?= number_format((float)$e['amount'], 2) ?></strong>
            </td>
            <td class="text-center">
              <?php if ($e['receipt_path']): ?>
                <a href="<?= BASE_URL . '/' . e($e['receipt_path']) ?>" target="_blank"
                   class="btn btn-sm btn-outline-info" title="View receipt">
                  📎
                </a>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <a href="<?= BASE_URL ?>/modules/finance/expense_edit.php?id=<?= (int)$e['id'] ?>"
                   class="btn btn-outline-primary">Edit</a>
                <a href="<?= BASE_URL ?>/modules/finance/expense_delete.php?id=<?= (int)$e['id'] ?>"
                   class="btn btn-outline-danger"
                   onclick="return confirm('Delete this expense?');">Del</a>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

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