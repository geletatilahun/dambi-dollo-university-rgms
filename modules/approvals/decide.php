<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('coordinator','college','director','vp');

$user = current_user();
$role = $user['role'];
$id   = (int)($_GET['id'] ?? 0);

// Load proposal
$stmt = $pdo->prepare("
    SELECT p.*, u.full_name AS researcher_name, u.id AS researcher_id
    FROM proposals p
    JOIN users u ON u.id = p.researcher_id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$proposal = $stmt->fetch();
if (!$proposal) { set_flash('danger', 'Proposal not found.'); redirect('/modules/approvals/queue.php'); }

// Which status does this role act on?
if ($role === 'coordinator') {
    // Coordinator can act on either submitted or under_review
    $allowedStatuses = ['submitted', 'under_review'];
    if (!in_array($proposal['status'], $allowedStatuses, true)) {
        set_flash('warning', "This proposal is not at the coordinator stage.");
        redirect('/modules/proposals/view.php?id=' . $id);
    }
} else {
    $statusMap = [
      'college'  => 'college_review',
      'director' => 'director_review',
      'vp'       => 'vp_review',
    ];
    $expectedStatus = $statusMap[$role] ?? null;
    if ($proposal['status'] !== $expectedStatus) {
        set_flash('warning', "This proposal is not at the {$role} stage.");
        redirect('/modules/proposals/view.php?id=' . $id);
    }
}

// Handle decision
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $decision = $_POST['decision'] ?? '';
    $comment  = trim($_POST['comment'] ?? '');

    $allowed = ['approve','reject','return'];
    if (!in_array($decision, $allowed, true)) {
        $errors[] = "Invalid decision.";
    }
    if (in_array($decision, ['reject','return'], true) && $comment === '') {
        $errors[] = "Comment is required for rejecting or returning.";
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            // Determine new status
            $newStatus = null;
            $newApprovalStage = null;

if ($decision === 'approve') {
    // Move to next stage
    $flow = [
      'coordinator' => ['next' => 'college_review',  'stage' => 'coordinator'],
      'college'     => ['next' => 'director_review', 'stage' => 'college'],
      'director'    => ['next' => 'vp_review',       'stage' => 'director'],
      'vp'          => ['next' => 'approved',        'stage' => 'vp'],
    ];
    $newStatus = $flow[$role]['next'];
    $newApprovalStage = $flow[$role]['stage'];

    // When VP approves → auto-create project record
    if ($role === 'vp' && $newStatus === 'approved') {
        // Check project doesn't already exist
        $chk = $pdo->prepare("SELECT id FROM projects WHERE proposal_id = ?");
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            // Generate project code
            $year = date('Y');
            $cnt  = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE YEAR(created_at) = $year")->fetchColumn() + 1;
            $projectCode = sprintf('DDU-PRJ-%s-%04d', $year, $cnt);

            // Compute dates from timeline_months
            $startDate = date('Y-m-d');
            $endDate   = date('Y-m-d', strtotime("+{$proposal['timeline_months']} months"));

            $pdo->prepare("
                INSERT INTO projects
                    (project_code, proposal_id, leader_id, start_date, end_date, funds_awarded, status)
                VALUES (?, ?, ?, ?, ?, ?, 'active')
            ")->execute([
                $projectCode,
                $id,
                $proposal['researcher_id'],
                $startDate,
                $endDate,
                $proposal['total_budget'],
            ]);

            // Optional: bump proposal status to project_created
            // (leave as 'approved' if you prefer)

            // Notify researcher
            notify($pdo, $proposal['researcher_id'],
                'Project Created 🎉',
                "Your project {$projectCode} has been created. Start tracking progress.",
                BASE_URL . "/modules/projects/view.php?id=" . $pdo->lastInsertId());

            log_action($pdo, 'create_project', 'projects',
                $pdo->lastInsertId(), $projectCode);
        }
    }
} elseif ($decision === 'reject') {
                $newStatus = 'rejected';
                $newApprovalStage = $role;
            } else { // return
                $newStatus = 'returned';
                $newApprovalStage = $role;
            }

            // Update proposal
            $approvedAt = ($newStatus === 'approved') ? date('Y-m-d H:i:s') : null;

            $pdo->prepare("
                UPDATE proposals
                SET status = ?, approved_at = COALESCE(?, approved_at)
                WHERE id = ?
            ")->execute([$newStatus, $approvedAt, $id]);

            // Record decision in approvals
            $pdo->prepare("
                INSERT INTO approvals (proposal_id, stage, approver_id, decision, comment)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$id, $newApprovalStage, $user['id'], $decision, $comment]);

            log_action($pdo, 'approval_decision', 'proposals', $id,
                "$role decided: $decision");

            // --- Notifications ---
            if ($decision === 'approve') {
                if ($newStatus === 'approved') {
                    // Notify researcher
                    notify($pdo, $proposal['researcher_id'],
                        'Proposal Approved 🎉',
                        "Your proposal {$proposal['proposal_code']} has been approved by the VP.",
                        BASE_URL . "/modules/proposals/view.php?id={$id}");
                } else {
                    // Notify next stage actors
                    $nextRole = [
                      'college_review'  => 'college',
                      'director_review' => 'director',
                      'vp_review'       => 'vp',
                    ][$newStatus];

                    $actors = $pdo->prepare("
                        SELECT u.id FROM users u
                        JOIN roles r ON r.id = u.role_id
                        WHERE r.name = ? AND u.is_active = 1
                    ");
                    $actors->execute([$nextRole]);
                    foreach ($actors->fetchAll() as $a) {
                        notify($pdo, $a['id'],
                            'Proposal Awaiting Your Review',
                            "Proposal {$proposal['proposal_code']} has advanced to your stage.",
                            BASE_URL . "/modules/proposals/view.php?id={$id}");
                    }
                }
            } else {
                // Notify researcher to revise
                notify($pdo, $proposal['researcher_id'],
                    'Proposal Returned for Revision',
                    "Your proposal {$proposal['proposal_code']} was returned at the $role stage. Reason: $comment",
                    BASE_URL . "/modules/proposals/edit.php?id={$id}");
            }

            $pdo->commit();

            $msgMap = [
              'approve' => $newStatus === 'approved'
                            ? 'Proposal approved successfully.'
                            : 'Proposal forwarded to next stage.',
              'reject'  => 'Proposal rejected.',
              'return'  => 'Proposal returned to researcher for revision.',
            ];
            set_flash('success', $msgMap[$decision]);
            redirect('/modules/proposals/view.php?id=' . $id);

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<h3 class="mb-4"><?= e(ucfirst($role)) ?> Decision</h3>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <h5><?= e($proposal['title']) ?></h5>
    <p class="text-muted small mb-1">
      <code><?= e($proposal['proposal_code']) ?></code> ·
      By <?= e($proposal['researcher_name']) ?> ·
      Budget: <?= number_format((float)$proposal['total_budget'], 2) ?> ETB
    </p>
    <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= $id ?>" target="_blank">
      Open full proposal in new tab →
    </a>
  </div>
</div>

<form method="post">
  <div class="card mb-3">
    <div class="card-body">
      <label class="form-label"><strong>Comment / Reason</strong></label>
      <textarea name="comment" rows="4" class="form-control"
                placeholder="Required if rejecting or returning."></textarea>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button type="submit" name="decision" value="approve" class="btn btn-success"
            onclick="return confirm('Approve and forward this proposal?');">
      ✔ Approve<?= $role === 'vp' ? ' (Final)' : ' & Forward' ?>
    </button>
    <button type="submit" name="decision" value="return" class="btn btn-warning"
            onclick="return confirm('Return to researcher for revision?');">
      ↩ Return for Revision
    </button>
    <button type="submit" name="decision" value="reject" class="btn btn-danger"
            onclick="return confirm('Reject this proposal permanently?');">
      ✖ Reject
    </button>
    <a href="<?= BASE_URL ?>/modules/approvals/queue.php" class="btn btn-secondary">Cancel</a>
  </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>