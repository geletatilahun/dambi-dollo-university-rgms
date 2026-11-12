<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('finance');

$user = current_user();
$errors = [];
$preProject = (int)($_GET['project_id'] ?? 0);

// Load projects with their disbursed vs spent totals
$projects = $pdo->query("
    SELECT p.id, p.project_code, p.funds_awarded,
           pr.title,
           COALESCE((SELECT SUM(amount) FROM disbursements WHERE project_id = p.id), 0) AS disbursed,
           COALESCE((SELECT SUM(amount) FROM expenses WHERE project_id = p.id), 0) AS spent
    FROM projects p
    JOIN proposals pr ON pr.id = p.proposal_id
    WHERE p.status IN ('active','extended')
    ORDER BY p.project_code DESC
")->fetchAll();

// Categories (same as proposal budget categories + extras)
$categories = ['Equipment','Travel','Supplies','Personnel','Services',
               'Communication','Utilities','Training','Other'];

$form = [
    'project_id'  => $preProject,
    'category'    => '',
    'description' => '',
    'amount'      => '',
    'expense_date'=> date('Y-m-d'),
    'vendor'      => '',
    'notes'       => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'project_id'  => (int)($_POST['project_id'] ?? 0),
        'category'    => trim($_POST['category'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'amount'      => trim($_POST['amount'] ?? ''),
        'expense_date'=> $_POST['expense_date'] ?? date('Y-m-d'),
        'vendor'      => trim($_POST['vendor'] ?? ''),
        'notes'       => trim($_POST['notes'] ?? ''),
    ];

    if ($form['project_id'] <= 0) $errors[] = "Please select a project.";
    if ($form['category'] === '') $errors[] = "Category is required.";
    if ($form['description'] === '') $errors[] = "Description is required.";
    if ($form['amount'] === '' || (float)$form['amount'] <= 0) $errors[] = "Amount must be greater than 0.";
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['expense_date'])) $errors[] = "Invalid date.";

    // Check against disbursed funds
    if (!$errors) {
        $stmt = $pdo->prepare("
            SELECT p.funds_awarded,
                   COALESCE((SELECT SUM(amount) FROM disbursements WHERE project_id = p.id), 0) AS disbursed,
                   COALESCE((SELECT SUM(amount) FROM expenses WHERE project_id = p.id), 0) AS spent
            FROM projects p WHERE p.id = ?
        ");
        $stmt->execute([$form['project_id']]);
        $proj = $stmt->fetch();

        if (!$proj) {
            $errors[] = "Project not found.";
        } else {
            $remaining = (float)$proj['disbursed'] - (float)$proj['spent'];
            if ((float)$form['amount'] > $remaining) {
                $errors[] = "Expense exceeds the remaining disbursed balance. "
                          . "Remaining: " . number_format($remaining, 2) . " ETB.";
            }
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $receiptPath = null;

            // Handle receipt upload
            if (!empty($_FILES['receipt']['name'])) {
                $allowed = ['pdf','jpg','jpeg','png','doc','docx'];
                $maxBytes = 5 * 1024 * 1024; // 5 MB
                $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));

                if ($_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = "Receipt upload failed.";
                } elseif ($_FILES['receipt']['size'] > $maxBytes) {
                    $errors[] = "Receipt too large (max 5 MB).";
                } elseif (!in_array($ext, $allowed, true)) {
                    $errors[] = "Receipt must be PDF, JPG, PNG, or DOC.";
                } else {
                    $dir = __DIR__ . '/../../assets/uploads/receipts/' . $form['project_id'] . '/';
                    if (!is_dir($dir)) mkdir($dir, 0775, true);
                    $safe = bin2hex(random_bytes(8)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['receipt']['tmp_name'], $dir . $safe)) {
                        $receiptPath = 'assets/uploads/receipts/' . $form['project_id'] . '/' . $safe;
                    } else {
                        $errors[] = "Could not save receipt.";
                    }
                }
            }

            if ($errors) throw new Exception(implode(" ", $errors));

            $stmt = $pdo->prepare("
                INSERT INTO expenses
                    (project_id, category, description, amount, expense_date,
                     vendor, receipt_path, notes, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $form['project_id'],
                $form['category'],
                $form['description'],
                (float)$form['amount'],
                $form['expense_date'],
                $form['vendor'] ?: null,
                $receiptPath,
                $form['notes'] ?: null,
                $user['id'],
            ]);
            $expId = (int)$pdo->lastInsertId();

            log_action($pdo, 'create_expense', 'expenses', $expId,
                number_format((float)$form['amount'], 2) . " ETB · {$form['category']}");

            // Notify researcher
            $stmt = $pdo->prepare("
                SELECT p.leader_id, p.project_code
                FROM projects p WHERE p.id = ?
            ");
            $stmt->execute([$form['project_id']]);
            $projInfo = $stmt->fetch();

            if ($projInfo) {
                notify($pdo, $projInfo['leader_id'],
                    'Expense Recorded 💳',
                    number_format((float)$form['amount'], 2)
                      . " ETB ({$form['category']}) recorded against {$projInfo['project_code']}.",
                    BASE_URL . "/modules/proposals/view.php?id=" . $form['project_id']);
            }

            $pdo->commit();
            set_flash('success', 'Expense recorded successfully.');
            redirect('/modules/finance/expenses.php');

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="row">
  <div class="col-lg-8 mx-auto">

    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3 class="mb-0">Record Expense</h3>
      <a href="<?= BASE_URL ?>/modules/finance/expenses.php" class="btn btn-outline-secondary btn-sm">
        ← Back to Expenses
      </a>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <strong>Please fix:</strong>
        <ul class="mb-0 mt-1">
          <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate>
      <div class="card">
        <div class="card-body">

          <div class="mb-3">
            <label class="form-label fw-semibold">Project *</label>
            <select name="project_id" id="projectSelect" class="form-select" required>
              <option value="">— Select Project —</option>
              <?php foreach ($projects as $p):
                $remaining = (float)$p['disbursed'] - (float)$p['spent'];
              ?>
                <option value="<?= (int)$p['id'] ?>"
                        data-disbursed="<?= $p['disbursed'] ?>"
                        data-spent="<?= $p['spent'] ?>"
                        data-remaining="<?= $remaining ?>"
                        <?= $form['project_id'] == $p['id'] ? 'selected' : '' ?>>
                  <?= e($p['project_code']) ?>
                  — <?= e(shorten($p['title'], 50)) ?>
                  (Remaining: <?= number_format($remaining, 0) ?> ETB)
                </option>
              <?php endforeach; ?>
            </select>
            <small id="projectInfo" class="text-muted d-block mt-1"></small>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Category *</label>
              <select name="category" class="form-select" required>
                <option value="">— Select Category —</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= e($c) ?>" <?= $form['category'] === $c ? 'selected' : '' ?>>
                    <?= e($c) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Amount (ETB) *</label>
              <input type="number" name="amount" id="amountInput"
                     class="form-control" required min="0.01" step="0.01"
                     value="<?= e($form['amount']) ?>">
              <small id="amountWarning" class="text-danger d-block mt-1"></small>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Description *</label>
            <input type="text" name="description" class="form-control" required
                   maxlength="255"
                   placeholder="e.g., 2 Dell laptops for field data collection"
                   value="<?= e($form['description']) ?>">
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Expense Date *</label>
              <input type="date" name="expense_date" class="form-control" required
                     value="<?= e($form['expense_date']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Vendor / Supplier</label>
              <input type="text" name="vendor" class="form-control"
                     placeholder="e.g., Addis Computer Import"
                     value="<?= e($form['vendor']) ?>">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Receipt / Supporting Document</label>
            <input type="file" name="receipt" class="form-control"
                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
            <small class="text-muted">
              Accepted: PDF, JPG, PNG, DOC/DOCX · Max 5 MB
            </small>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" rows="3" class="form-control"
                      placeholder="Optional internal notes..."><?= e($form['notes']) ?></textarea>
          </div>

        </div>
        <div class="card-footer d-flex gap-2">
          <button class="btn btn-primary">💾 Record Expense</button>
          <a href="<?= BASE_URL ?>/modules/finance/expenses.php" class="btn btn-secondary">Cancel</a>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
const projSelect = document.getElementById('projectSelect');
const amountInput = document.getElementById('amountInput');
const info = document.getElementById('projectInfo');
const warn = document.getElementById('amountWarning');

function updateInfo() {
  const opt = projSelect.options[projSelect.selectedIndex];
  const disbursed = parseFloat(opt.getAttribute('data-disbursed') || 0);
  const spent     = parseFloat(opt.getAttribute('data-spent') || 0);
  const remaining = parseFloat(opt.getAttribute('data-remaining') || 0);

  if (opt.value) {
    info.innerHTML =
      `Disbursed: <strong>${disbursed.toLocaleString()} ETB</strong> · ` +
      `Spent: <strong>${spent.toLocaleString()} ETB</strong> · ` +
      `Remaining: <strong class="text-success">${remaining.toLocaleString()} ETB</strong>`;
  } else {
    info.innerHTML = '';
  }

  const amt = parseFloat(amountInput.value || 0);
  if (amt > remaining) {
    warn.textContent = `⚠ Amount exceeds remaining balance (${remaining.toLocaleString()} ETB).`;
  } else {
    warn.textContent = '';
  }
}

projSelect.addEventListener('change', updateInfo);
amountInput.addEventListener('input', updateInfo);
updateInfo();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>