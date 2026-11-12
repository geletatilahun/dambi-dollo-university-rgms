<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('researcher');

$user = current_user();
$id   = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger', 'Invalid proposal ID.'); redirect('/modules/proposals/list.php'); }

// Load proposal (must belong to this researcher)
$stmt = $pdo->prepare("SELECT * FROM proposals WHERE id = ? AND researcher_id = ?");
$stmt->execute([$id, $user['id']]);
$proposal = $stmt->fetch();
if (!$proposal) { set_flash('danger', 'Proposal not found.'); redirect('/modules/proposals/list.php'); }

// Only draft or returned can be edited
if (!in_array($proposal['status'], ['draft','returned'], true)) {
    set_flash('warning', 'This proposal cannot be edited anymore.');
    redirect('/modules/proposals/view.php?id=' . $id);
}

// Existing budget items
$stmt = $pdo->prepare("SELECT * FROM budget_items WHERE proposal_id = ? ORDER BY id");
$stmt->execute([$id]);
$budget = $stmt->fetchAll();

// Existing documents
$stmt = $pdo->prepare("SELECT * FROM proposal_documents WHERE proposal_id = ? ORDER BY id");
$stmt->execute([$id]);
$docs = $stmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'draft';

    $form = [
      'title'           => trim($_POST['title'] ?? ''),
      'abstract'        => trim($_POST['abstract'] ?? ''),
      'objectives'      => trim($_POST['objectives'] ?? ''),
      'methodology'     => trim($_POST['methodology'] ?? ''),
      'timeline_months' => (int)($_POST['timeline_months'] ?? 0),
    ];

    $budget_cats   = $_POST['budget_category']    ?? [];
    $budget_descs  = $_POST['budget_description'] ?? [];
    $budget_qtys   = $_POST['budget_quantity']    ?? [];
    $budget_costs  = $_POST['budget_unit_cost']   ?? [];

    if ($form['title'] === '') $errors[] = "Title is required.";
    if ($form['abstract'] === '') $errors[] = "Abstract is required.";
    if ($form['timeline_months'] <= 0) $errors[] = "Timeline (months) is required.";
    if (count($budget_cats) === 0) $errors[] = "At least one budget item is required.";

    $maxBudget = (float)$pdo->query("SELECT value FROM settings WHERE key_name='max_budget_per_proposal'")->fetchColumn();
    $total = 0;
    foreach ($budget_cats as $i => $c) {
        $total += (float)($budget_qtys[$i] ?? 0) * (float)($budget_costs[$i] ?? 0);
    }
    if ($maxBudget > 0 && $total > $maxBudget) {
        $errors[] = "Total budget exceeds maximum allowed (" . number_format($maxBudget, 2) . " ETB).";
    }

    if (!$errors) {
        $status = ($action === 'submit') ? 'submitted' : $proposal['status'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                UPDATE proposals
                SET title=?, abstract=?, objectives=?, methodology=?,
                    timeline_months=?, total_budget=?, status=?,
                    submitted_at = CASE WHEN ? = 'submitted' THEN NOW() ELSE submitted_at END
                WHERE id=?
            ")->execute([
                $form['title'], $form['abstract'], $form['objectives'],
                $form['methodology'], $form['timeline_months'], $total,
                $status, $status, $id,
            ]);

            // Replace budget items
            $pdo->prepare("DELETE FROM budget_items WHERE proposal_id = ?")->execute([$id]);
            $bi = $pdo->prepare("
                INSERT INTO budget_items (proposal_id, category, description, quantity, unit_cost, total_cost)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($budget_cats as $i => $cat) {
                $q = (float)($budget_qtys[$i] ?? 0);
                $u = (float)($budget_costs[$i] ?? 0);
                $bi->execute([$id, $cat, $budget_descs[$i] ?? '', $q, $u, $q * $u]);
            }

            // New file uploads
            if (!empty($_FILES['attachments']['name'][0])) {
                $uploadDir = __DIR__ . '/../../assets/uploads/proposals/' . $id . '/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

                $allowed  = ['pdf','docx','doc','xlsx','xls'];
                $maxBytes = 10 * 1024 * 1024;

                foreach ($_FILES['attachments']['name'] as $idx => $name) {
                    if ($_FILES['attachments']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                    if ($_FILES['attachments']['size'][$idx] > $maxBytes) continue;
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed, true)) continue;

                    $safe = bin2hex(random_bytes(8)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['attachments']['tmp_name'][$idx], $uploadDir . $safe)) {
                        $rel = 'assets/uploads/proposals/' . $id . '/' . $safe;
                        $pdo->prepare("
                            INSERT INTO proposal_documents (proposal_id, file_name, file_path, file_type, file_size)
                            VALUES (?, ?, ?, ?, ?)
                        ")->execute([$id, $name, $rel, $ext, (int)$_FILES['attachments']['size'][$idx]]);
                    }
                }
            }

            // Notify coordinators on submit
            if ($status === 'submitted') {
                $cos = $pdo->query("
                    SELECT u.id FROM users u
                    JOIN roles r ON r.id = u.role_id
                    WHERE r.name='coordinator' AND u.is_active=1
                ")->fetchAll();
                foreach ($cos as $c) {
                    notify($pdo, $c['id'], '📤 Revised Proposal Awaiting Review',
    "Revised proposal {$proposal['proposal_code']} — {$form['title']} awaits your review.",
                        BASE_URL . "/modules/proposals/view.php?id={$id}");
                }
            }

            log_action($pdo, 'update_proposal', 'proposals', $id, $proposal['proposal_code']);
            $pdo->commit();

            set_flash('success', $status === 'submitted'
                ? 'Proposal submitted successfully.'
                : 'Draft updated.');
            redirect('/modules/proposals/view.php?id=' . $id);

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Save failed: " . $e->getMessage();
        }
    }

    // Repopulate form on error
    $proposal = array_merge($proposal, $form);
}

include __DIR__ . '/../../includes/header.php';
?>

<h3 class="mb-4">Edit Proposal: <?= e($proposal['proposal_code']) ?></h3>

<?php if ($proposal['status'] === 'returned'): ?>
  <div class="alert alert-warning">
    <strong>This proposal was returned for revision.</strong>
    Check the Workflow tab on the <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= $id ?>">view page</a> for comments.
    After editing, click <em>Submit</em> to restart the workflow.
  </div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
  <div class="card mb-3">
    <div class="card-header">
      <ul class="nav nav-tabs card-header-tabs">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-basic" type="button">1. Basic Info</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-details" type="button">2. Details</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-budget" type="button">3. Budget</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-files" type="button">4. Attachments</button></li>
      </ul>
    </div>
    <div class="card-body">
      <div class="tab-content">

        <div class="tab-pane fade show active" id="tab-basic">
          <div class="mb-3">
            <label class="form-label">Proposal Title *</label>
            <input type="text" name="title" class="form-control" required
                   value="<?= e($proposal['title']) ?>" maxlength="255">
          </div>
          <div class="mb-3">
            <label class="form-label">Abstract *</label>
            <textarea name="abstract" rows="6" class="form-control" required><?= e($proposal['abstract']) ?></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Timeline (months) *</label>
            <input type="number" name="timeline_months" class="form-control" required
                   min="1" max="60" value="<?= e($proposal['timeline_months']) ?>">
          </div>
        </div>

        <div class="tab-pane fade" id="tab-details">
          <div class="mb-3">
            <label class="form-label">Objectives</label>
            <textarea name="objectives" rows="4" class="form-control"><?= e($proposal['objectives']) ?></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Methodology</label>
            <textarea name="methodology" rows="6" class="form-control"><?= e($proposal['methodology']) ?></textarea>
          </div>
        </div>

        <div class="tab-pane fade" id="tab-budget">
          <div class="table-responsive">
            <table class="table table-bordered align-middle">
              <thead class="table-light">
                <tr>
                  <th style="width:20%">Category</th>
                  <th>Description</th>
                  <th style="width:12%">Quantity</th>
                  <th style="width:15%">Unit Cost</th>
                  <th style="width:15%">Total</th>
                  <th style="width:6%"></th>
                </tr>
              </thead>
              <tbody id="budgetRows">
                <?php if ($budget): foreach ($budget as $b): ?>
                  <tr>
                    <td><input type="text" name="budget_category[]" class="form-control" required value="<?= e($b['category']) ?>"></td>
                    <td><input type="text" name="budget_description[]" class="form-control" required value="<?= e($b['description']) ?>"></td>
                    <td><input type="number" name="budget_quantity[]" class="form-control qty" step="0.01" min="0.01" required value="<?= e($b['quantity']) ?>"></td>
                    <td><input type="number" name="budget_unit_cost[]" class="form-control cost" step="0.01" min="0" required value="<?= e($b['unit_cost']) ?>"></td>
                    <td class="text-end total"><?= number_format((float)$b['total_cost'], 2) ?></td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger removeRow">×</button></td>
                  </tr>
                <?php endforeach; else: ?>
                  <tr>
                    <td><input type="text" name="budget_category[]" class="form-control" required></td>
                    <td><input type="text" name="budget_description[]" class="form-control" required></td>
                    <td><input type="number" name="budget_quantity[]" class="form-control qty" step="0.01" min="0.01" value="1" required></td>
                    <td><input type="number" name="budget_unit_cost[]" class="form-control cost" step="0.01" min="0" required></td>
                    <td class="text-end total">0.00</td>
                    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger removeRow">×</button></td>
                  </tr>
                <?php endif; ?>
              </tbody>
              <tfoot>
                <tr class="table-light">
                  <th colspan="4" class="text-end">Grand Total</th>
                  <th class="text-end" id="grandTotal">0.00</th>
                  <th></th>
                </tr>
              </tfoot>
            </table>
          </div>
          <button type="button" class="btn btn-sm btn-outline-primary" id="addRow">+ Add Budget Item</button>
        </div>

        <div class="tab-pane fade" id="tab-files">
          <?php if ($docs): ?>
            <p class="mb-2"><strong>Existing files:</strong></p>
            <ul class="list-group mb-3">
              <?php foreach ($docs as $d): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <span><?= e($d['file_name']) ?></span>
                  <a href="<?= BASE_URL . '/' . e($d['file_path']) ?>" target="_blank"
                     class="btn btn-sm btn-outline-primary">Download</a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <p class="text-muted small mb-2">Add more files (PDF, DOC/DOCX, XLS/XLSX, max 10 MB each):</p>
          <input type="file" name="attachments[]" class="form-control" multiple
                 accept=".pdf,.doc,.docx,.xls,.xlsx">
        </div>

      </div>
    </div>
    <div class="card-footer d-flex gap-2">
      <button type="submit" name="action" value="draft" class="btn btn-secondary">Save as Draft</button>
      <button type="submit" name="action" value="submit" class="btn btn-primary"
              onclick="return confirm('Submit this proposal?');">Submit Proposal</button>
      <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= $id ?>" class="btn btn-link">Cancel</a>
    </div>
  </div>
</form>

<script>
const rows = document.getElementById('budgetRows');
const grand = document.getElementById('grandTotal');
function recalc() {
  let sum = 0;
  rows.querySelectorAll('tr').forEach(tr => {
    const q = parseFloat(tr.querySelector('.qty').value || 0);
    const c = parseFloat(tr.querySelector('.cost').value || 0);
    const t = q * c;
    tr.querySelector('.total').textContent = t.toFixed(2);
    sum += t;
  });
  grand.textContent = sum.toFixed(2);
}
rows.addEventListener('input', recalc);
document.getElementById('addRow').addEventListener('click', () => {
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input type="text" name="budget_category[]" class="form-control" required></td>
    <td><input type="text" name="budget_description[]" class="form-control" required></td>
    <td><input type="number" name="budget_quantity[]" class="form-control qty" step="0.01" min="0.01" value="1" required></td>
    <td><input type="number" name="budget_unit_cost[]" class="form-control cost" step="0.01" min="0" required></td>
    <td class="text-end total">0.00</td>
    <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger removeRow">×</button></td>
  `;
  rows.appendChild(tr);
});
rows.addEventListener('click', e => {
  if (e.target.classList.contains('removeRow')) {
    if (rows.querySelectorAll('tr').length > 1) {
      e.target.closest('tr').remove();
      recalc();
    }
  }
});
recalc();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>