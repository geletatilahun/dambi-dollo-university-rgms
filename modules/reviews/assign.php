<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('coordinator','director');

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM proposals WHERE id = ?");
$stmt->execute([$id]);
$proposal = $stmt->fetch();
if (!$proposal) { set_flash('danger', 'Proposal not found.'); redirect('/modules/proposals/list.php'); }

// Only at submitted or under_review stage can we assign
if (!in_array($proposal['status'], ['submitted','under_review'], true)) {
    set_flash('warning', 'Reviewers can only be assigned while the proposal is under review.');
    redirect('/modules/proposals/view.php?id=' . $id);
}

// Load reviewers
$reviewers = $pdo->query("
    SELECT u.id, u.full_name, u.email
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE r.name = 'reviewer' AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll();

// Current assignments
$stmt = $pdo->prepare("
    SELECT ra.*, u.full_name AS reviewer_name
    FROM review_assignments ra
    JOIN users u ON u.id = ra.reviewer_id
    WHERE ra.proposal_id = ?
    ORDER BY ra.id
");
$stmt->execute([$id]);
$assignments = $stmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reviewerIds = $_POST['reviewer_ids'] ?? [];
    $dueDate     = $_POST['due_date'] ?? null;

    if (empty($reviewerIds)) $errors[] = "Select at least one reviewer.";

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            // Remove existing pending assignments not in new list
            $pdo->prepare("
                DELETE FROM review_assignments
                WHERE proposal_id = ? AND status = 'pending'
                  AND reviewer_id NOT IN (" . implode(',', array_fill(0, count($reviewerIds), '?')) . ")
            ")->execute(array_merge([$id], $reviewerIds));

            // Insert new ones (skip duplicates)
            $ins = $pdo->prepare("
                INSERT INTO review_assignments (proposal_id, reviewer_id, assigned_by, due_date)
                SELECT ?, ?, ?, ?
                WHERE NOT EXISTS (
                    SELECT 1 FROM review_assignments
                    WHERE proposal_id = ? AND reviewer_id = ?
                )
            ");
            foreach ($reviewerIds as $rid) {
                $ins->execute([$id, (int)$rid, current_user()['id'], $dueDate ?: null, $id, (int)$rid]);

                // Notify reviewer
                notify($pdo, (int)$rid, 'New Review Assignment',
                    "You have been assigned to review proposal {$proposal['proposal_code']}.",
                    BASE_URL . "/modules/reviews/submit.php?id={$id}");
            }

            // Move proposal to under_review if it was submitted
            if ($proposal['status'] === 'submitted') {
                $pdo->prepare("UPDATE proposals SET status='under_review' WHERE id=?")->execute([$id]);
            }

            log_action($pdo, 'assign_reviewers', 'proposals', $id,
                count($reviewerIds) . " reviewer(s) assigned");

            $pdo->commit();
            set_flash('success', 'Reviewers assigned successfully.');
            redirect('/modules/proposals/view.php?id=' . $id);
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<h3 class="mb-4">Assign Reviewers</h3>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0">
    <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body">
  <strong><?= e($proposal['title']) ?></strong><br>
  <code><?= e($proposal['proposal_code']) ?></code> · Status: <?= e($proposal['status']) ?>
</div></div>

<form method="post">
  <div class="card mb-3">
    <div class="card-header"><strong>Reviewers</strong></div>
    <div class="card-body">
      <?php if (!$reviewers): ?>
        <p class="text-muted mb-0">No reviewers found. Create reviewer accounts first.</p>
      <?php else: ?>
        <?php foreach ($reviewers as $r): ?>
          <?php
            $isAssigned = false;
            foreach ($assignments as $a) if ((int)$a['reviewer_id'] === (int)$r['id']) $isAssigned = true;
          ?>
          <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="reviewer_ids[]"
                   value="<?= (int)$r['id'] ?>" id="rev<?= (int)$r['id'] ?>"
                   <?= $isAssigned ? 'checked' : '' ?>>
            <label class="form-check-label" for="rev<?= (int)$r['id'] ?>">
              <?= e($r['full_name']) ?> <small class="text-muted">(<?= e($r['email']) ?>)</small>
            </label>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="card mb-3"><div class="card-body">
    <label class="form-label"><strong>Due Date (optional)</strong></label>
    <input type="date" name="due_date" class="form-control" style="max-width:300px">
  </div></div>

  <div class="d-flex gap-2">
    <button class="btn btn-primary">Save Assignments</button>
    <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= $id ?>" class="btn btn-secondary">Cancel</a>
  </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>