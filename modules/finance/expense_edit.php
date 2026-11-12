<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('finance');

$user = current_user();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT e.*, p.project_code, pr.title AS proposal_title
    FROM expenses e
    JOIN projects p ON p.id = e.project_id
    JOIN proposals pr ON pr.id = p.proposal_id
    WHERE e.id = ?
");
$stmt->execute([$id]);
$exp = $stmt->fetch();
if (!$exp) { set_flash('danger', 'Expense not found.'); redirect('/modules/finance/expenses.php'); }

$categories = ['Equipment','Travel','Supplies','Personnel','Services',
               'Communication','Utilities','Training','Other'];

$errors = [];
$form = $exp;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['category']     = trim($_POST['category'] ?? '');
    $form['description']  = trim($_POST['description'] ?? '');
    $form['amount']       = trim($_POST['amount'] ?? '');
    $form['expense_date'] = $_POST['expense_date'] ?? date('Y-m-d');
    $form['vendor']       = trim($_POST['vendor'] ?? '');
    $form['notes']        = trim($_POST['notes'] ?? '');

    if ($form['category'] === '') $errors[] = "Category is required.";
    if ($form['description'] === '') $errors[] = "Description is required.";
    if ($form['amount'] === '' || (float)$form['amount'] <= 0) $errors[] = "Amount must be > 0.";

    // Replace receipt if uploaded
    $newReceiptPath = $exp['receipt_path'];
    if (!empty($_FILES['receipt']['name'])) {
        $allowed = ['pdf','jpg','jpeg','png','doc','docx'];
        $maxBytes = 5 * 1024 * 1024;
        $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));

        if ($_FILES['receipt']['size'] > $maxBytes) {
            $errors[] = "Receipt too large (max 5 MB).";
        } elseif (!in_array($ext, $allowed, true)) {
            $errors[] = "Receipt must be PDF, JPG, PNG, or DOC.";
        } else {
            $dir = __DIR__ . '/../../assets/uploads/receipts/' . $exp['project_id'] . '/';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $safe = bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($_FILES['receipt']['tmp_name'], $dir . $safe)) {
                $newReceiptPath = 'assets/uploads/receipts/' . $exp['project_id'] . '/' . $safe;
            } else {
                $errors[] = "Could not save receipt.";
            }
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE expenses
                SET category=?, description=?, amount=?, expense_date=?,
                    vendor=?, receipt_path=?, notes=?
                WHERE id=?
            ")->execute([
                $form['category'],
                $form['description'],
                (float)$form['amount'],
                $form['expense_date'],
                $form['vendor'] ?: null,
                $newReceiptPath,
                $form['notes'] ?: null,
                $id,
            ]);

            log_action($pdo, 'update_expense', 'expenses', $id,
                "Updated to " . number_format((float)$form['amount'], 2) . " ETB");

            $pdo->commit();
            set_flash('success', 'Expense updated.');
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
      <h3 class="mb-0">Edit Expense #<?= $id ?></h3>
      <a href="<?= BASE_URL ?>/modules/finance/expenses.php" class="btn btn-outline-secondary btn-sm">
        ← Back
      </a>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-body">
        <strong><?= e($exp['project_code']) ?></strong> — <?= e($exp['proposal_title']) ?>
      </div>
    </div>

    <form method="post" enctype="multipart/form-data">
      <div class="card">
        <div class="card-body">

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Category *</label>
              <select name="category" class="form-select" required>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= e($c) ?>" <?= $form['category'] === $c ? 'selected':'' ?>>
                    <?= e($c) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Amount (ETB) *</label>
              <input type="number" name="amount" class="form-control" required
                     min="0.01" step="0.01" value="<?= e($form['amount']) ?>">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Description *</label>
            <input type="text" name="description" class="form-control" required
                   maxlength="255" value="<?= e($form['description']) ?>">
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Date *</label>
              <input type="date" name="expense_date" class="form-control" required
                     value="<?= e($form['expense_date']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Vendor</label>
              <input type="text" name="vendor" class="form-control"
                     value="<?= e($form['vendor']) ?>">
            </div>
          </div>

          <?php if ($exp['receipt_path']): ?>
            <div class="mb-3">
              <label class="form-label fw-semibold">Current Receipt</label><br>
              <a href="<?= BASE_URL . '/' . e($exp['receipt_path']) ?>" target="_blank"
                 class="btn btn-sm btn-outline-info">📎 View current receipt</a>
            </div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label fw-semibold">
              <?= $exp['receipt_path'] ? 'Replace Receipt' : 'Upload Receipt' ?>
            </label>
            <input type="file" name="receipt" class="form-control"
                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
            <small class="text-muted">Leave empty to keep current. Max 5 MB.</small>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" rows="3" class="form-control"><?= e($form['notes']) ?></textarea>
          </div>
        </div>
        <div class="card-footer d-flex gap-2">
          <button class="btn btn-primary">Save Changes</button>
          <a href="<?= BASE_URL ?>/modules/finance/expenses.php" class="btn btn-secondary">Cancel</a>
        </div>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>