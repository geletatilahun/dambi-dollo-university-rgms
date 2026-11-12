<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$user = current_user();
$id   = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger', 'Invalid proposal ID.'); redirect('/modules/proposals/list.php'); }

// ============ LOAD PROPOSAL ============
$stmt = $pdo->prepare("
    SELECT p.*, u.full_name AS researcher_name, u.email AS researcher_email,
           r.name AS researcher_role
    FROM proposals p
    JOIN users u ON u.id = p.researcher_id
    JOIN roles r ON r.id = u.role_id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$proposal = $stmt->fetch();
if (!$proposal) { set_flash('danger', 'Proposal not found.'); redirect('/modules/proposals/list.php'); }

// ============ ACCESS CONTROL ============
$isOwner = ((int)$proposal['researcher_id'] === (int)$user['id']);
$isStaff = has_role('coordinator','college','director','vp','reviewer','admin','finance');
if (!$isOwner && !$isStaff) {
    set_flash('danger', 'You do not have permission to view this proposal.');
    redirect('/modules/proposals/list.php');
}

// ============ BUDGET ITEMS ============
$stmt = $pdo->prepare("SELECT * FROM budget_items WHERE proposal_id = ? ORDER BY id");
$stmt->execute([$id]);
$budget = $stmt->fetchAll();

// ============ DOCUMENTS ============
$stmt = $pdo->prepare("SELECT * FROM proposal_documents WHERE proposal_id = ? ORDER BY id");
$stmt->execute([$id]);
$docs = $stmt->fetchAll();

// ============ APPROVAL HISTORY ============
$stmt = $pdo->prepare("
    SELECT a.*, u.full_name AS approver_name
    FROM approvals a
    JOIN users u ON u.id = a.approver_id
    WHERE a.proposal_id = ?
    ORDER BY a.decided_at ASC
");
$stmt->execute([$id]);
$approvals = $stmt->fetchAll();

// ============ REVIEW ASSIGNMENTS ============
$stmt = $pdo->prepare("
    SELECT ra.*, u.full_name AS reviewer_name,
           r.score, r.comments, r.merge_recommended, r.submitted_at AS review_submitted_at
    FROM review_assignments ra
    JOIN users u ON u.id = ra.reviewer_id
    LEFT JOIN reviews r ON r.assignment_id = ra.id
    WHERE ra.proposal_id = ?
    ORDER BY ra.id
");
$stmt->execute([$id]);
$assignments = $stmt->fetchAll();

// ============ PROJECT FINANCIALS (for approved) ============
$project = null;
if (in_array($proposal['status'], ['approved','project_created'], true)) {
    $stmt = $pdo->prepare("
        SELECT p.id, p.project_code, p.funds_awarded,
               p.start_date, p.end_date, p.status AS project_status,
               COALESCE((SELECT SUM(amount) FROM disbursements WHERE project_id = p.id), 0) AS disbursed,
               COALESCE((SELECT SUM(amount) FROM expenses WHERE project_id = p.id), 0) AS spent
        FROM projects p
        WHERE p.proposal_id = ?
    ");
    $stmt->execute([$id]);
    $project = $stmt->fetch();

    // Recent transactions for this project
    $transactions = [];
    if ($project) {
        $stmt = $pdo->prepare("
            SELECT 'disbursement' AS type, d.id, d.amount, d.disbursed_on AS tx_date,
                   d.tranche AS category, d.reference AS ref, d.notes,
                   u.full_name AS recorder
            FROM disbursements d
            JOIN users u ON u.id = d.recorded_by
            WHERE d.project_id = ?
            UNION ALL
            SELECT 'expense' AS type, e.id, e.amount, e.expense_date AS tx_date,
                   e.category, COALESCE(e.vendor, '—') AS ref, e.notes,
                   u.full_name AS recorder
            FROM expenses e
            JOIN users u ON u.id = e.recorded_by
            WHERE e.project_id = ?
            ORDER BY tx_date DESC, id DESC
            LIMIT 10
        ");
        $stmt->execute([$project['id'], $project['id']]);
        $transactions = $stmt->fetchAll();
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<!-- ============ HEADER ============ -->
<div class="row mb-3">
  <div class="col-md-8">
    <h3 class="mb-1"><?= e($proposal['title']) ?></h3>
    <p class="text-muted small mb-0">
      <code><?= e($proposal['proposal_code']) ?></code> ·
      By <?= e($proposal['researcher_name']) ?> ·
      <span class="badge bg-<?= status_badge($proposal['status']) ?>">
        <?= e(ucwords(str_replace('_',' ', $proposal['status']))) ?>
      </span>
    </p>
  </div>
  <div class="col-md-4 text-md-end mt-2 mt-md-0">
    <?php if ($isOwner && in_array($proposal['status'], ['draft','returned'], true)): ?>
      <a href="<?= BASE_URL ?>/modules/proposals/edit.php?id=<?= (int)$proposal['id'] ?>"
         class="btn btn-outline-primary btn-sm">Edit</a>
    <?php endif; ?>

    <?php if ($isOwner && $proposal['status'] === 'draft'): ?>
      <a href="<?= BASE_URL ?>/modules/proposals/submit.php?id=<?= (int)$proposal['id'] ?>"
         class="btn btn-primary btn-sm"
         onclick="return confirm('Submit this proposal to the workflow?');">Submit</a>
    <?php endif; ?>

    <?php if (has_role('coordinator') && in_array($proposal['status'], ['submitted','under_review'], true)): ?>
      <a href="<?= BASE_URL ?>/modules/reviews/assign.php?id=<?= (int)$proposal['id'] ?>"
         class="btn btn-outline-primary btn-sm">Assign Reviewers</a>
      <a href="<?= BASE_URL ?>/modules/approvals/decide.php?id=<?= (int)$proposal['id'] ?>"
         class="btn btn-success btn-sm">Coordinator Decision</a>
    <?php endif; ?>

    <?php if (has_role('college') && $proposal['status'] === 'college_review'): ?>
      <a href="<?= BASE_URL ?>/modules/approvals/decide.php?id=<?= (int)$proposal['id'] ?>"
         class="btn btn-primary btn-sm">College Decision</a>
    <?php endif; ?>

    <?php if (has_role('director') && $proposal['status'] === 'director_review'): ?>
      <a href="<?= BASE_URL ?>/modules/approvals/decide.php?id=<?= (int)$proposal['id'] ?>"
         class="btn btn-primary btn-sm">Director Decision</a>
    <?php endif; ?>

    <?php if (has_role('vp') && $proposal['status'] === 'vp_review'): ?>
      <a href="<?= BASE_URL ?>/modules/approvals/decide.php?id=<?= (int)$proposal['id'] ?>"
         class="btn btn-success btn-sm">VP Final Decision</a>
    <?php endif; ?>

    <?php if (has_role('finance') && $project): ?>
      <a href="<?= BASE_URL ?>/modules/finance/project.php?id=<?= (int)$project['id'] ?>"
         class="btn btn-info btn-sm">💰 Finance View</a>
    <?php endif; ?>

    <a href="<?= BASE_URL ?>/modules/proposals/list.php" class="btn btn-secondary btn-sm">Back</a>
  </div>
</div>

<!-- ============ INFO CARDS ============ -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card p-3"><small class="text-muted">Timeline</small><h5 class="mb-0"><?= (int)$proposal['timeline_months'] ?> months</h5></div>
  </div>
  <div class="col-md-3">
    <div class="card p-3"><small class="text-muted">Total Budget</small><h5 class="mb-0"><?= number_format((float)$proposal['total_budget'], 2) ?> ETB</h5></div>
  </div>
  <div class="col-md-3">
    <div class="card p-3"><small class="text-muted">Submitted</small><h5 class="mb-0"><?= $proposal['submitted_at'] ? e(date('d M Y', strtotime($proposal['submitted_at']))) : '—' ?></h5></div>
  </div>
  <div class="col-md-3">
    <div class="card p-3"><small class="text-muted">Approved</small><h5 class="mb-0"><?= $proposal['approved_at'] ? e(date('d M Y', strtotime($proposal['approved_at']))) : '—' ?></h5></div>
  </div>
</div>

<!-- ============ TABS ============ -->
<ul class="nav nav-tabs" id="proposalTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-content" type="button" role="tab">
      Content
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-budget" type="button" role="tab">
      Budget (<?= count($budget) ?>)
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-files" type="button" role="tab">
      Files (<?= count($docs) ?>)
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-workflow" type="button" role="tab">
      Workflow (<?= count($approvals) ?>)
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-reviews" type="button" role="tab">
      Reviews (<?= count($assignments) ?>)
    </button>
  </li>

  <?php if ($project): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-finance" type="button" role="tab">
      💰 Financials
    </button>
  </li>
  <?php endif; ?>
</ul>

<div class="tab-content card card-body border-top-0">

  <!-- Content -->
  <div class="tab-pane fade show active" id="tab-content" role="tabpanel">
    <h6>Abstract</h6>
    <p><?= nl2br(e($proposal['abstract'])) ?></p>
    <hr>
    <h6>Objectives</h6>
    <p><?= $proposal['objectives'] ? nl2br(e($proposal['objectives'])) : '<em class="text-muted">Not provided</em>' ?></p>
    <hr>
    <h6>Methodology</h6>
    <p><?= $proposal['methodology'] ? nl2br(e($proposal['methodology'])) : '<em class="text-muted">Not provided</em>' ?></p>
  </div>

  <!-- Budget -->
  <div class="tab-pane fade" id="tab-budget" role="tabpanel">
    <div class="table-responsive">
      <table class="table table-sm table-bordered">
        <thead class="table-light">
          <tr>
            <th>Category</th><th>Description</th>
            <th class="text-end">Qty</th>
            <th class="text-end">Unit (ETB)</th>
            <th class="text-end">Total (ETB)</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$budget): ?>
            <tr><td colspan="5" class="text-center text-muted">No budget items.</td></tr>
          <?php else: foreach ($budget as $b): ?>
            <tr>
              <td><?= e(pretty_category($b['category'])) ?></td>
              <td><?= e($b['description']) ?></td>
              <td class="text-end"><?= number_format((float)$b['quantity'], 2) ?></td>
              <td class="text-end"><?= number_format((float)$b['unit_cost'], 2) ?></td>
              <td class="text-end"><?= number_format((float)$b['total_cost'], 2) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
          <tr class="table-light">
            <th colspan="4" class="text-end">Grand Total</th>
            <th class="text-end"><?= number_format((float)$proposal['total_budget'], 2) ?></th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <!-- Files -->
  <div class="tab-pane fade" id="tab-files" role="tabpanel">
    <?php if (!$docs): ?>
      <p class="text-muted mb-0">No files uploaded.</p>
    <?php else: ?>
      <ul class="list-group">
        <?php foreach ($docs as $d): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>
              <strong><?= e($d['file_name']) ?></strong>
              <small class="text-muted">(<?= number_format($d['file_size']/1024, 1) ?> KB)</small>
            </span>
            <a href="<?= BASE_URL . '/' . e($d['file_path']) ?>" target="_blank"
               class="btn btn-sm btn-outline-primary">Download</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <!-- Workflow -->
  <div class="tab-pane fade" id="tab-workflow" role="tabpanel">
    <?php if (!$approvals): ?>
      <p class="text-muted mb-0">No workflow decisions yet.</p>
    <?php else: ?>
      <ul class="list-group">
        <?php foreach ($approvals as $a): ?>
          <li class="list-group-item">
            <div class="d-flex justify-content-between">
              <div>
                <strong><?= e(ucfirst($a['stage'])) ?></strong> —
                <?= e(ucfirst($a['decision'])) ?>
                <small class="text-muted">by <?= e($a['approver_name']) ?></small>
              </div>
              <small class="text-muted">
                <?= e(date('d M Y H:i', strtotime($a['decided_at']))) ?>
              </small>
            </div>
            <?php if ($a['comment']): ?>
              <p class="mb-0 mt-1 text-muted small"><em>"<?= e($a['comment']) ?>"</em></p>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <!-- Reviews -->
  <div class="tab-pane fade" id="tab-reviews" role="tabpanel">
    <?php if (!$assignments): ?>
      <p class="text-muted mb-0">No reviewers assigned yet.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm">
          <thead class="table-light">
            <tr><th>Reviewer</th><th>Status</th><th class="text-end">Score</th><th>Comments</th></tr>
          </thead>
          <tbody>
            <?php foreach ($assignments as $a): ?>
              <tr>
                <td><?= e($a['reviewer_name']) ?></td>
                <td><span class="badge bg-secondary"><?= e($a['status']) ?></span></td>
                <td class="text-end"><?= $a['score'] !== null ? e($a['score']) : '—' ?></td>
                <td><?= $a['comments'] ? e($a['comments']) : '<em class="text-muted">—</em>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ============ FINANCIALS TAB ============ -->
  <?php if ($project):
    $awarded   = (float)$project['funds_awarded'];
    $disbursed = (float)$project['disbursed'];
    $spent     = (float)$project['spent'];
    $disbPct   = $awarded > 0 ? round($disbursed / $awarded * 100) : 0;
    $spentPct  = $disbursed > 0 ? round($spent / $disbursed * 100) : 0;
    $available = $disbursed - $spent;
  ?>
  <div class="tab-pane fade" id="tab-finance" role="tabpanel">

    <div class="d-flex justify-content-between align-items-center mb-3">
      <h6 class="mb-0">
        Project <code><?= e($project['project_code']) ?></code>
        <span class="badge bg-<?= $project['project_status'] === 'active' ? 'success' : 'secondary' ?> ms-1">
          <?= e(ucfirst($project['project_status'])) ?>
        </span>
      </h6>
      <a href="<?= BASE_URL ?>/modules/finance/project.php?id=<?= (int)$project['id'] ?>"
         class="btn btn-sm btn-outline-primary">Full Finance Report →</a>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-3">
      <div class="col-md-3">
        <div class="border rounded p-3">
          <small class="text-muted">Awarded</small>
          <h5 class="mb-0 text-primary"><?= number_format($awarded, 0) ?> ETB</h5>
        </div>
      </div>
      <div class="col-md-3">
        <div class="border rounded p-3">
          <small class="text-muted">Disbursed</small>
          <h5 class="mb-0 text-info"><?= number_format($disbursed, 0) ?> ETB</h5>
          <small class="text-muted"><?= $disbPct ?>% of awarded</small>
        </div>
      </div>
      <div class="col-md-3">
        <div class="border rounded p-3">
          <small class="text-muted">Spent</small>
          <h5 class="mb-0 text-warning"><?= number_format($spent, 0) ?> ETB</h5>
          <small class="text-muted"><?= $spentPct ?>% of disbursed</small>
        </div>
      </div>
      <div class="col-md-3">
        <div class="border rounded p-3">
          <small class="text-muted">Available</small>
          <h5 class="mb-0 text-success"><?= number_format($available, 0) ?> ETB</h5>
        </div>
      </div>
    </div>

    <!-- Progress bar -->
    <div class="progress mb-3" style="height:20px;">
      <div class="progress-bar bg-info" style="width:<?= $disbPct ?>%"
           title="Disbursed: <?= number_format($disbursed, 0) ?> ETB">
        Disbursed (<?= $disbPct ?>%)
      </div>
      <div class="progress-bar bg-warning" style="width:<?= $spentPct ?>%"
           title="Spent: <?= number_format($spent, 0) ?> ETB">
        Spent (<?= $spentPct ?>%)
      </div>
    </div>

    <!-- Recent transactions -->
    <?php if ($transactions): ?>
      <h6 class="mt-4 mb-2">Recent Transactions</h6>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:100px">Date</th>
              <th style="width:110px">Type</th>
              <th style="width:130px">Category</th>
              <th>Reference / Notes</th>
              <th class="text-end" style="width:120px">Amount (ETB)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transactions as $t):
              $isDisb = $t['type'] === 'disbursement';
            ?>
              <tr>
                <td class="small"><?= e(fmt_date($t['tx_date'], 'd M Y')) ?></td>
                <td>
                  <span class="badge bg-<?= $isDisb ? 'info' : 'warning' ?>">
                    <?= $isDisb ? '💸 Disbursed' : '🧾 Expense' ?>
                  </span>
                </td>
                <td class="small"><?= e(pretty_category($t['category'])) ?></td>
                <td class="small">
                  <?php if ($t['ref']): ?><strong><?= e($t['ref']) ?></strong><?php endif; ?>
                  <?php if ($t['notes']): ?> · <?= e(shorten($t['notes'], 50)) ?><?php endif; ?>
                </td>
                <td class="text-end small">
                  <strong class="text-<?= $isDisb ? 'info' : 'warning' ?>">
                    <?= $isDisb ? '+' : '−' ?> <?= number_format((float)$t['amount'], 2) ?>
                  </strong>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-muted small mb-0">No transactions recorded yet.</p>
    <?php endif; ?>

  </div>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>