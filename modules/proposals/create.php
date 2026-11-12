<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('researcher');

$user = current_user();
$errors = [];

$form = [
  'title' => '', 'abstract' => '', 'objectives' => '',
  'methodology' => '', 'timeline_months' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'draft';

    $form['title']           = trim($_POST['title'] ?? '');
    $form['abstract']        = trim($_POST['abstract'] ?? '');
    $form['objectives']      = trim($_POST['objectives'] ?? '');
    $form['methodology']     = trim($_POST['methodology'] ?? '');
    $form['timeline_months'] = (int)($_POST['timeline_months'] ?? 0);

    $budget_cats  = $_POST['budget_category']    ?? [];
    $budget_descs = $_POST['budget_description'] ?? [];
    $budget_qtys  = $_POST['budget_quantity']    ?? [];
    $budget_costs = $_POST['budget_unit_cost']   ?? [];

    if ($form['title'] === '') $errors[] = "Title is required.";
    if ($form['abstract'] === '') $errors[] = "Abstract is required.";
    if ($form['timeline_months'] <= 0 || $form['timeline_months'] > 60) {
        $errors[] = "Timeline must be between 1 and 60 months.";
    }
    if (count($budget_cats) === 0) $errors[] = "At least one budget item is required.";

    $maxBudget = (float)$pdo->query("SELECT value FROM settings WHERE key_name='max_budget_per_proposal'")->fetchColumn();
    $total = 0;
    foreach ($budget_cats as $i => $c) {
        $total += (float)($budget_qtys[$i] ?? 0) * (float)($budget_costs[$i] ?? 0);
    }
    if ($maxBudget > 0 && $total > $maxBudget) {
        $errors[] = "Total budget exceeds the maximum allowed (" . number_format($maxBudget, 2) . " ETB).";
    }

    if (!$errors) {
        $status = ($action === 'submit') ? 'submitted' : 'draft';
        $code = generate_proposal_code($pdo);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO proposals
                    (proposal_code, researcher_id, title, abstract, objectives,
                     methodology, timeline_months, total_budget, status, submitted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $code, $user['id'], $form['title'], $form['abstract'],
                $form['objectives'], $form['methodology'],
                $form['timeline_months'], $total, $status,
                $status === 'submitted' ? date('Y-m-d H:i:s') : null,
            ]);
            $proposalId = (int)$pdo->lastInsertId();

            // Budget
            $bi = $pdo->prepare("
                INSERT INTO budget_items
                    (proposal_id, category, description, quantity, unit_cost, total_cost)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($budget_cats as $i => $cat) {
                $q = (float)($budget_qtys[$i] ?? 0);
                $u = (float)($budget_costs[$i] ?? 0);
                $bi->execute([$proposalId, $cat, $budget_descs[$i] ?? '', $q, $u, $q * $u]);
            }

            // Attachments
            if (!empty($_FILES['attachments']['name'][0])) {
                $uploadDir = __DIR__ . '/../../assets/uploads/proposals/' . $proposalId . '/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
                $allowed = ['pdf','docx','doc','xlsx','xls'];
                $maxBytes = 10 * 1024 * 1024;
                foreach ($_FILES['attachments']['name'] as $idx => $name) {
                    if ($_FILES['attachments']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                    if ($_FILES['attachments']['size'][$idx] > $maxBytes) continue;
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed, true)) continue;
                    $safe = bin2hex(random_bytes(8)) . '.' . $ext;
                    $dest = $uploadDir . $safe;
                    if (move_uploaded_file($_FILES['attachments']['tmp_name'][$idx], $dest)) {
                        $rel = 'assets/uploads/proposals/' . $proposalId . '/' . $safe;
                        $pdo->prepare("
                            INSERT INTO proposal_documents
                                (proposal_id, file_name, file_path, file_type, file_size)
                            VALUES (?, ?, ?, ?, ?)
                        ")->execute([
                            $proposalId, $name, $rel, $ext,
                            (int)$_FILES['attachments']['size'][$idx],
                        ]);
                    }
                }
            }

            // Notify coordinators if submitted
            if ($status === 'submitted') {
                $coordinators = $pdo->query("
                    SELECT u.id FROM users u
                    JOIN roles r ON r.id = u.role_id
                    WHERE r.name = 'coordinator' AND u.is_active = 1
                ")->fetchAll();
                foreach ($coordinators as $c) {
                    notify($pdo, $c['id'],
    '📤 New Proposal Awaiting Review',
    "Proposal {$code} — {$form['title']} has been submitted and awaits your review.",
                        BASE_URL . "/modules/proposals/view.php?id={$proposalId}");
                }
            }

            log_action($pdo, $status === 'submitted' ? 'submit_proposal' : 'create_draft',
                       'proposals', $proposalId, $code);

            $pdo->commit();

            set_flash('success', $status === 'submitted'
                ? "Proposal {$code} submitted successfully."
                : "Draft saved. You can continue editing later.");
            redirect('/modules/proposals/view.php?id=' . $proposalId);

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Save failed: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="row">
  <div class="col-lg-10 mx-auto">

    <!-- ============ HEADER ============ -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div>
        <h3 class="mb-0">Create New Proposal</h3>
        <small class="text-muted">Fill in all tabs, then save as draft or submit for review.</small>
      </div>
      <a href="<?= BASE_URL ?>/modules/proposals/list.php" class="btn btn-outline-secondary btn-sm">
        ← Back to My Proposals
      </a>
    </div>

    <!-- ============ ERRORS ============ -->
    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <strong>Please fix the following:</strong>
        <ul class="mb-0 mt-1">
          <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- ============ FORM ============ -->
    <form method="post" enctype="multipart/form-data" id="proposalForm">
      <div class="card">

        <!-- Tabs -->
        <div class="card-header p-0">
          <ul class="nav nav-tabs card-header-tabs m-0 px-3 pt-2" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-basic" type="button">
                <span class="badge bg-primary me-1">1</span> Basic Info
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-details" type="button">
                <span class="badge bg-secondary me-1">2</span> Details
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-budget" type="button">
                <span class="badge bg-secondary me-1">3</span> Budget
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-files" type="button">
                <span class="badge bg-secondary me-1">4</span> Attachments
              </button>
            </li>
          </ul>
        </div>

        <div class="card-body">
          <div class="tab-content">

            <!-- TAB 1 -->
            <div class="tab-pane fade show active" id="tab-basic">
              <div class="mb-4">
                <label class="form-label fw-semibold">Proposal Title *</label>
                <input type="text" name="title" id="title" class="form-control form-control-lg"
                       required maxlength="255"
                       value="<?= e($form['title']) ?>"
                       placeholder="Enter a clear, descriptive title for your research">
                <small class="text-muted">
                  <span id="titleCount">0</span> / 255 characters
                </small>
              </div>

              <div class="mb-4">
                <label class="form-label fw-semibold">Abstract *</label>
                <textarea name="abstract" id="abstract" rows="7" class="form-control"
                          required placeholder="Summarize your research goals, methodology, and expected outcomes..."><?= e($form['abstract']) ?></textarea>
                <div class="d-flex justify-content-between">
                  <small class="text-muted">Aim for 200–300 words.</small>
                  <small class="text-muted">
                    <span id="abstractWords">0</span> words
                  </small>
                </div>
              </div>

              <div class="mb-3">
                <label class="form-label fw-semibold">Timeline (months) *</label>
                <input type="number" name="timeline_months" class="form-control"
                       required min="1" max="60" style="max-width:200px;"
                       value="<?= e($form['timeline_months']) ?>"
                       placeholder="e.g., 12">
                <small class="text-muted">Between 1 and 60 months.</small>
              </div>
            </div>

            <!-- TAB 2 -->
            <div class="tab-pane fade" id="tab-details">
              <div class="mb-4">
                <label class="form-label fw-semibold">Objectives</label>
                <textarea name="objectives" rows="4" class="form-control"
                          placeholder="General and specific objectives of the research..."><?= e($form['objectives']) ?></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">Methodology</label>
                <textarea name="methodology" rows="7" class="form-control"
                          placeholder="Study design, data collection, analysis approach, ethical considerations..."><?= e($form['methodology']) ?></textarea>
              </div>
            </div>

            <!-- TAB 3 -->
            <div class="tab-pane fade" id="tab-budget">
              <p class="text-muted small">
                Add each budget line item. The total is calculated automatically in ETB.
              </p>

              <div class="table-responsive">
                <table class="table table-bordered align-middle">
                  <thead class="table-light">
                    <tr>
                      <th style="width:20%">Category</th>
                      <th>Description</th>
                      <th style="width:12%">Quantity</th>
                      <th style="width:15%">Unit Cost (ETB)</th>
                      <th style="width:15%">Total</th>
                      <th style="width:6%"></th>
                    </tr>
                  </thead>
                  <tbody id="budgetRows">
                    <tr>
                      <td>
                        <select name="budget_category[]" class="form-select" required>
                          <option value="">— Select —</option>
                          <option>Equipment</option>
                          <option>Travel</option>
                          <option>Supplies</option>
                          <option>Personnel</option>
                          <option>Services</option>
                          <option>Other</option>
                        </select>
                      </td>
                      <td><input type="text" name="budget_description[]" class="form-control" required></td>
                      <td><input type="number" name="budget_quantity[]" class="form-control qty" step="0.01" min="0.01" value="1" required></td>
                      <td><input type="number" name="budget_unit_cost[]" class="form-control cost" step="0.01" min="0" required></td>
                      <td class="text-end total">0.00</td>
                      <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger removeRow">×</button>
                      </td>
                    </tr>
                  </tbody>
                  <tfoot>
                    <tr class="table-light">
                      <th colspan="4" class="text-end">Grand Total (ETB)</th>
                      <th class="text-end" id="grandTotal">0.00</th>
                      <th></th>
                    </tr>
                  </tfoot>
                </table>
              </div>

              <button type="button" class="btn btn-sm btn-outline-primary" id="addRow">
                + Add Budget Item
              </button>
            </div>

            <!-- TAB 4 -->
            <div class="tab-pane fade" id="tab-files">
              <div class="alert alert-info small">
                <strong>Allowed:</strong> PDF, DOC/DOCX, XLS/XLSX.<br>
                <strong>Max size:</strong> 10 MB per file.<br>
                You can select multiple files at once.
              </div>
              <input type="file" name="attachments[]" class="form-control" multiple
                     accept=".pdf,.doc,.docx,.xls,.xlsx">
            </div>

          </div>
        </div>

        <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
          <button type="submit" name="action" value="draft" class="btn btn-outline-secondary">
            💾 Save as Draft
          </button>
          <button type="submit" name="action" value="submit" class="btn btn-primary"
                  onclick="return confirm('Submit this proposal to the workflow? You won\\'t be able to edit it after.');">
            📤 Submit Proposal
          </button>
          <a href="<?= BASE_URL ?>/modules/proposals/list.php" class="btn btn-link ms-auto"
             onclick="return confirm('Discard this draft?');">
            Cancel
          </a>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
// ============ CHAR / WORD COUNTERS ============
const titleInput = document.getElementById('title');
const titleCount = document.getElementById('titleCount');
titleInput.addEventListener('input', () => {
  titleCount.textContent = titleInput.value.length;
});

const abstractInput = document.getElementById('abstract');
const abstractWords = document.getElementById('abstractWords');
abstractInput.addEventListener('input', () => {
  const words = abstractInput.value.trim().split(/\s+/).filter(w => w.length > 0).length;
  abstractWords.textContent = words;
});

// ============ BUDGET TABLE ============
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
    <td>
      <select name="budget_category[]" class="form-select" required>
        <option value="">— Select —</option>
        <option>Equipment</option>
        <option>Travel</option>
        <option>Supplies</option>
        <option>Personnel</option>
        <option>Services</option>
        <option>Other</option>
      </select>
    </td>
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
    } else {
      alert('You must have at least one budget item.');
    }
  }
});

recalc();

// ============ WARN IF LEAVING WITH UNSAVED DATA ============
let dirty = false;
document.getElementById('proposalForm').addEventListener('input', () => { dirty = true; });
window.addEventListener('beforeunload', (e) => {
  if (dirty) {
    e.preventDefault();
    e.returnValue = '';
  }
});
document.getElementById('proposalForm').addEventListener('submit', () => { dirty = false; });
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>