<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('admin');

// ============ FILTERS ============
$actionFilter = trim($_GET['action'] ?? '');
$userFilter   = (int)($_GET['user_id'] ?? 0);
$dateFrom     = $_GET['from'] ?? '';
$dateTo       = $_GET['to']   ?? '';
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;

$where = "WHERE 1=1";
$params = [];

if ($actionFilter !== '') {
    $where .= " AND a.action = ?";
    $params[] = $actionFilter;
}
if ($userFilter > 0) {
    $where .= " AND a.user_id = ?";
    $params[] = $userFilter;
}
if ($dateFrom !== '') {
    $where .= " AND DATE(a.created_at) >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where .= " AND DATE(a.created_at) <= ?";
    $params[] = $dateTo;
}
if ($search !== '') {
    $where .= " AND (a.details LIKE ? OR u.full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Count
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM audit_log a
    LEFT JOIN users u ON u.id = a.user_id
    $where
");
$stmt->execute($params);
$totalRows = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Fetch
$stmt = $pdo->prepare("
    SELECT a.*, u.full_name, u.email, r.name AS role_name
    FROM audit_log a
    LEFT JOIN users u ON u.id = a.user_id
    LEFT JOIN roles r ON r.id = u.role_id
    $where
    ORDER BY a.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Distinct actions (for filter dropdown)
$actions = $pdo->query("
    SELECT DISTINCT action FROM audit_log ORDER BY action
")->fetchAll(PDO::FETCH_COLUMN);

// Users for filter dropdown
$users = $pdo->query("
    SELECT id, full_name, email FROM users ORDER BY full_name
")->fetchAll();

// ============ EXPORT CSV ============
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Time','User','Role','Action','Table','Record','Details','IP']);

    $expStmt = $pdo->prepare("
        SELECT a.*, u.full_name, r.name AS role_name
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN roles r ON r.id = u.role_id
        $where
        ORDER BY a.id DESC
    ");
    $expStmt->execute($params);
    foreach ($expStmt as $row) {
        fputcsv($out, [
            $row['id'], $row['created_at'], $row['full_name'] ?? 'System',
            $row['role_name'] ?? '-', $row['action'], $row['table_name'],
            $row['record_id'], $row['details'], $row['ip_address'],
        ]);
    }
    fclose($out);
    exit;
}

// ============ ACTION COLOR ============
function action_color($action) {
    $a = strtolower($action);
    if (str_contains($a, 'login'))       return 'primary';
    if (str_contains($a, 'logout'))      return 'secondary';
    if (str_contains($a, 'create'))      return 'success';
    if (str_contains($a, 'update'))      return 'info';
    if (str_contains($a, 'delete'))      return 'danger';
    if (str_contains($a, 'approve'))     return 'success';
    if (str_contains($a, 'reject'))      return 'danger';
    if (str_contains($a, 'return'))      return 'warning';
    if (str_contains($a, 'submit'))      return 'primary';
    if (str_contains($a, 'assign'))      return 'info';
    return 'secondary';
}

// Helper for preserving query string
function qsa($overrides = []) {
    $base = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($base[$k]);
        else $base[$k] = $v;
    }
    return '?' . http_build_query($base);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">Audit Log</h3>
    <small class="text-muted">
      Showing <?= count($logs) ?> of <?= $totalRows ?> entr<?= $totalRows == 1 ? 'y' : 'ies' ?>
    </small>
  </div>
  <a href="<?= qsa(['export' => 'csv']) ?>" class="btn btn-outline-secondary">
    ⬇ Export CSV
  </a>
</div>

<!-- ============ FILTERS ============ -->
<form class="card card-body mb-3" method="get">
  <div class="row g-2">
    <div class="col-md-3">
      <input type="text" name="q" class="form-control"
             placeholder="Search details or user..."
             value="<?= e($search) ?>">
    </div>
    <div class="col-md-3">
      <select name="action" class="form-select">
        <option value="">— All Actions —</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= e($a) ?>" <?= $actionFilter === $a ? 'selected' : '' ?>>
            <?= e(str_replace('_',' ', $a)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select name="user_id" class="form-select">
        <option value="0">— All Users —</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= $userFilter === (int)$u['id'] ? 'selected' : '' ?>>
            <?= e($u['full_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3 d-grid">
      <button class="btn btn-outline-primary">Filter</button>
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-0">From</label>
      <input type="date" name="from" class="form-control" value="<?= e($dateFrom) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-0">To</label>
      <input type="date" name="to" class="form-control" value="<?= e($dateTo) ?>">
    </div>
    <div class="col-md-6 d-flex align-items-end gap-2">
      <a href="<?= BASE_URL ?>/modules/admin/audit_log.php" class="btn btn-link btn-sm">Clear filters</a>
    </div>
  </div>
</form>

<!-- ============ TABLE ============ -->
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:50px">#</th>
          <th style="width:150px">Time</th>
          <th style="width:180px">User</th>
          <th style="width:150px">Action</th>
          <th style="width:120px">Table</th>
          <th style="width:80px" class="text-center">Rec.</th>
          <th>Details</th>
          <th style="width:130px">IP</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$logs): ?>
          <tr><td colspan="8" class="text-center text-muted py-5">No audit entries match the filters.</td></tr>
        <?php else: foreach ($logs as $log): ?>
          <tr>
            <td class="text-muted small"><?= (int)$log['id'] ?></td>
            <td class="small">
              <?= e(date('d M Y', strtotime($log['created_at']))) ?><br>
              <span class="text-muted"><?= e(date('H:i:s', strtotime($log['created_at']))) ?></span>
            </td>
            <td class="small">
              <?php if ($log['full_name']): ?>
                <strong><?= e($log['full_name']) ?></strong>
                <?php if ($log['role_name']): ?>
                  <br><span class="badge bg-light text-dark"><?= e(ucfirst($log['role_name'])) ?></span>
                <?php endif; ?>
              <?php else: ?>
                <em class="text-muted">System</em>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge bg-<?= action_color($log['action']) ?>">
                <?= e(str_replace('_',' ', $log['action'])) ?>
              </span>
            </td>
            <td class="small"><code><?= e($log['table_name'] ?? '—') ?></code></td>
            <td class="text-center small"><?= $log['record_id'] ? (int)$log['record_id'] : '—' ?></td>
            <td class="small"><?= e($log['details'] ?? '—') ?></td>
            <td class="small text-muted"><?= e($log['ip_address'] ?? '—') ?></td>
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
            <a class="page-link" href="<?= qsa(['page' => $page - 1]) ?>">«</a>
          </li>
          <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            if ($start > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            for ($i = $start; $i <= $end; $i++):
          ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= qsa(['page' => $i]) ?>"><?= $i ?></a>
            </li>
          <?php endfor;
            if ($end < $totalPages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          ?>
          <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= qsa(['page' => $page + 1]) ?>">»</a>
          </li>
        </ul>
      </nav>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>