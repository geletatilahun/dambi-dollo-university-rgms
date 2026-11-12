<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('finance');

$user = current_user();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT d.*, p.project_code, pr.title AS proposal_title
    FROM disbursements d
    JOIN projects p ON p.id = d.project_id
    JOIN proposals pr ON pr.id = p.proposal_id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$disb = $stmt->fetch();
if (!$disb) { set_flash('danger', 'Disbursement not found.'); redirect('/modules/finance/disbursements.php'); }

$errors = [];
$form = $disb;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['amount']       = trim($_POST['amount'] ?? '');
    $form['tranche']      = $_POST['tranche'] ?? 'other';
    $form['disbursed_on'] = $_POST['disbursed_on'] ?? date('Y-m-d');
    $form['method']       = trim($_POST['method'] ?? '');
    $form['reference']    = trim($_POST['reference'] ?? '');
    $form['notes']        = trim($_POST['notes'] ?? '');

    if ($form['amount'] === '' || (float)$form['amount'] <= 0) $errors[] = "Amount must be greater than 0.";

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE disbursements
                SET amount=?, tranche=?, disbursed_on=?, method=?, reference=?, notes=?
                WHERE id=?
            ")->execute([
                (float)$form['amount'],
                $form['tranche'],
                $form['disbursed_on'],
                $form['method'] ?: null,
                $form['reference'] ?: null,
                $form['notes'] ?: null,
                $id,
            ]);

            log_action($pdo, 'update_disbursement', 'disbursements', $id,
                "Updated: " . number_format((float)$form['amount'], 2) . " ETB");

            $pdo->commit();
            set_flash('success', 'Disbursement updated.');
            redirect('/modules/finance/disbursements.php');
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
      <h3 class="mb-0">Edit Disbursement #<?= $id ?></h3>
      <a href="<?= BASE_URL ?>/modules/finance/disbursements.php" class="btn btn-outline-secondary btn-sm">
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
        <strong><?= e($disb['project_code']) ?></strong> — <?= e($disb['proposal_title']) ?>
      </div>
    </div>

    <form method="post">
      <div class="card">
        <div class="card-body">
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Amount (ETB) *</label>
              <input type="number" name="amount" class="form-control" required
                     min="0.01" step="0.01" value="<?= e($form['amount']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Tranche *</label>
              <select name="tranche" class="form-select" required>
                <?php foreach (['first','second','third','final','other'] as $t): ?>
                  <option value="<?= $t ?>" <?= $form['tranche']===$t ? 'selected':'' ?>>
                    <?= e(ucfirst($t)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Date *</label>
              <input type="date" name="disbursed_on" class="form-control" required
                     value="<?= e($form['disbursed_on']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Payment Method</label>
              <input type="text" name="method" class="form-control" value="<?= e($form['method']) ?>">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Reference</label>
            <input type="text" name="reference" class="form-control" value="<?= e($form['reference']) ?>">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" rows="3" class="form-control"><?= e($form['notes']) ?></textarea>
          </div>
        </div>
        <div class="card-footer d-flex gap-2">
          <button class="btn btn-primary">Save Changes</button>
          <a href="<?= BASE_URL ?>/modules/finance/disbursements.php" class="btn btn-secondary">Cancel</a>
        </div>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>