<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('finance');

$user = current_user();
$errors = [];

// Pre-fill project from query string
$preProject = (int)($_GET['project_id'] ?? 0);

// ============ LOAD PROJECTS ============
$projects = $pdo->query("
    SELECT p.id, p.project_code, p.funds_awarded,
           pr.title,
           COALESCE((SELECT SUM(amount) FROM disbursements WHERE project_id = p.id), 0) AS disbursed
    FROM projects p
    JOIN proposals pr ON pr.id = p.proposal_id
    WHERE p.status IN ('active','extended')
    ORDER BY p.project_code DESC
")->fetchAll();

// Form values
$form = [
    'project_id'  => $preProject,
    'amount'      => '',
    'tranche'     => 'first',
    'disbursed_on'=> date('Y-m-d'),
    'method'      => '',
    'reference'   => '',
    'notes'       => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'project_id'  => (int)($_POST['project_id'] ?? 0),
        'amount'      => trim($_POST['amount'] ?? ''),
        'tranche'     => $_POST['tranche'] ?? 'other',
        'disbursed_on'=> $_POST['disbursed_on'] ?? date('Y-m-d'),
        'method'      => trim($_POST['method'] ?? ''),
        'reference'   => trim($_POST['reference'] ?? ''),
        'notes'       => trim($_POST['notes'] ?? ''),
    ];

    // ============ VALIDATION ============
    if ($form['project_id'] <= 0) $errors[] = "Please select a project.";
    if ($form['amount'] === '' || (float)$form['amount'] <= 0) $errors[] = "Amount must be greater than 0.";
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['disbursed_on'])) $errors[] = "Invalid date.";
    if (!in_array($form['tranche'], ['first','second','third','final','other'], true)) $errors[] = "Invalid tranche.";

    // ============ CHECK BUDGET ============
    if (!$errors) {
        $stmt = $pdo->prepare("
            SELECT p.funds_awarded,
                   COALESCE((SELECT SUM(amount) FROM disbursements WHERE project_id = p.id), 0) AS disbursed
            FROM projects p WHERE p.id = ?
        ");
        $stmt->execute([$form['project_id']]);
        $proj = $stmt->fetch();

        if (!$proj) {
            $errors[] = "Project not found.";
        } else {
            $newTotal = (float)$proj['disbursed'] + (float)$form['amount'];
            if ($newTotal > (float)$proj['funds_awarded']) {
                $errors[] = "Disbursement exceeds the awarded budget. Awarded: "
                          . number_format((float)$proj['funds_awarded'], 2) . " ETB · "
                          . "Already disbursed: " . number_format((float)$proj['disbursed'], 2) . " ETB.";
            }
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO disbursements
                    (project_id, amount, tranche, disbursed_on, method, reference, notes, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $form['project_id'],
                (float)$form['amount'],
                $form['tranche'],
                $form['disbursed_on'],
                $form['method'] ?: null,
                $form['reference'] ?: null,
                $form['notes'] ?: null,
                $user['id'],
            ]);
            $disbId = (int)$pdo->lastInsertId();

            log_action($pdo, 'create_disbursement', 'disbursements', $disbId,
                number_format((float)$form['amount'], 2) . " ETB to project {$form['project_id']}");

            // Notify researcher + coordinator
            $stmt = $pdo->prepare("
                SELECT p.leader_id, pr.title, p.project_code
                FROM projects p
                JOIN proposals pr ON pr.id = p.proposal_id
                WHERE p.id = ?
            ");
            $stmt->execute([$form['project_id']]);
            $projInfo = $stmt->fetch();

            if ($projInfo) {
                notify($pdo, $projInfo['leader_id'],
                    'Funds Disbursed 💰',
                    number_format((float)$form['amount'], 2)
                      . " ETB disbursed to {$projInfo['project_code']}.",
                    BASE_URL . "/modules/proposals/view.php?id=" . $form['project_id']);
            }

            $pdo->commit();

            set_flash('success', 'Disbursement recorded successfully.');
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
      <h3 class="mb-0">Record Disbursement</h3>
      <a href="<?= BASE_URL ?>/modules/finance/disbursements.php" class="btn btn-outline-secondary btn-sm">
        ← Back to Disbursements
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

    <form method="post" novalidate>
      <div class="card">
        <div class="card-body">

          <div class="mb-3">
            <label class="form-label fw-semibold">Project *</label>
            <select name="project_id" id="projectSelect" class="form-select" required>
              <option value="">— Select Project —</option>
              <?php foreach ($projects as $p): ?>
                <?php
                  $remaining = (float)$p['funds_awarded'] - (float)$p['disbursed'];
                ?>
                <option value="<?= (int)$p['id'] ?>"
                        data-remaining="<?= $remaining ?>"
                        data-awarded="<?= $p['funds_awarded'] ?>"
                        data-disbursed="<?= $p['disbursed'] ?>"
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
              <label class="form-label fw-semibold">Amount (ETB) *</label>
              <input type="number" name="amount" id="amountInput"
                     class="form-control" required min="0.01" step="0.01"
                     value="<?= e($form['amount']) ?>">
              <small id="amountWarning" class="text-danger d-block mt-1"></small>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Tranche *</label>
              <select name="tranche" class="form-select" required>
                <option value="first"  <?= $form['tranche']==='first'  ? 'selected':'' ?>>First (initial release)</option>
                <option value="second" <?= $form['tranche']==='second' ? 'selected':'' ?>>Second</option>
                <option value="third"  <?= $form['tranche']==='third'  ? 'selected':'' ?>>Third</option>
                <option value="final"  <?= $form['tranche']==='final'  ? 'selected':'' ?>>Final</option>
                <option value="other"  <?= $form['tranche']==='other'  ? 'selected':'' ?>>Other</option>
              </select>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Disbursement Date *</label>
              <input type="date" name="disbursed_on" class="form-control" required
                     value="<?= e($form['disbursed_on']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Payment Method</label>
              <input type="text" name="method" class="form-control"
                     placeholder="e.g., Bank transfer, Cheque"
                     value="<?= e($form['method']) ?>">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Reference / Voucher Number</label>
            <input type="text" name="reference" class="form-control"
                   placeholder="e.g., PV-2026-0123"
                   value="<?= e($form['reference']) ?>">
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" rows="3" class="form-control"
                      placeholder="Optional notes about this disbursement..."><?= e($form['notes']) ?></textarea>
          </div>

        </div>
        <div class="card-footer d-flex gap-2">
          <button class="btn btn-primary">💾 Record Disbursement</button>
          <a href="<?= BASE_URL ?>/modules/finance/disbursements.php" class="btn btn-secondary">Cancel</a>
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
  const remaining = parseFloat(opt.getAttribute('data-remaining') || 0);
  const awarded   = parseFloat(opt.getAttribute('data-awarded') || 0);
  const disbursed = parseFloat(opt.getAttribute('data-disbursed') || 0);

  if (opt.value) {
    info.innerHTML = `Awarded: <strong>${awarded.toLocaleString()} ETB</strong> · ` +
                     `Already disbursed: <strong>${disbursed.toLocaleString()} ETB</strong> · ` +
                     `Remaining: <strong class="text-success">${remaining.toLocaleString()} ETB</strong>`;
  } else {
    info.innerHTML = '';
  }

  const amt = parseFloat(amountInput.value || 0);
  if (amt > remaining) {
    warn.textContent = `⚠ Amount exceeds remaining budget (${remaining.toLocaleString()} ETB).`;
  } else {
    warn.textContent = '';
  }
}

projSelect.addEventListener('change', updateInfo);
amountInput.addEventListener('input', updateInfo);
updateInfo();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>