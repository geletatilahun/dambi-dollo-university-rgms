<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('reviewer');

$user = current_user();
$id   = (int)($_GET['id'] ?? 0);

// Verify this reviewer is assigned
$stmt = $pdo->prepare("
    SELECT ra.*, p.proposal_code, p.title, p.abstract, p.objectives, p.methodology,
           p.total_budget, u.full_name AS researcher_name
    FROM review_assignments ra
    JOIN proposals p ON p.id = ra.proposal_id
    JOIN users u ON u.id = p.researcher_id
    WHERE ra.proposal_id = ? AND ra.reviewer_id = ?
");
$stmt->execute([$id, $user['id']]);
$row = $stmt->fetch();
if (!$row) { set_flash('danger', 'You are not assigned to this proposal.'); redirect('/modules/reviews/my_assignments.php'); }

// Existing review?
$stmt = $pdo->prepare("SELECT * FROM reviews WHERE assignment_id = ?");
$stmt->execute([$row['id']]);
$review = $stmt->fetch();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $score  = (float)($_POST['score'] ?? -1);
    $comments = trim($_POST['comments'] ?? '');
    $mergeRec = isset($_POST['merge_recommended']) ? 1 : 0;
    $mergeTarget = (int)($_POST['merge_target_id'] ?? 0) ?: null;

    if ($score < 0 || $score > 100) $errors[] = "Score must be between 0 and 100.";
    if ($comments === '') $errors[] = "Comments are required.";

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            if ($review) {
                $pdo->prepare("
                    UPDATE reviews SET score=?, comments=?, merge_recommended=?, merge_target_id=?
                    WHERE id=?
                ")->execute([$score, $comments, $mergeRec, $mergeTarget, $review['id']]);
            } else {
                $pdo->prepare("
                    INSERT INTO reviews (assignment_id, score, comments, merge_recommended, merge_target_id)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$row['id'], $score, $comments, $mergeRec, $mergeTarget]);
            }

            $pdo->prepare("UPDATE review_assignments SET status='completed' WHERE id=?")
                ->execute([$row['id']]);

            log_action($pdo, 'submit_review', 'proposals', $id, "Score: $score");

            $pdo->commit();
            set_flash('success', 'Review submitted. Thank you!');
            redirect('/modules/reviews/my_assignments.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}

// Other proposals to merge with (optional)
$mergeOptions = $pdo->prepare("
    SELECT id, proposal_code, title FROM proposals
    WHERE id <> ? AND status IN ('submitted','under_review','college_review','director_review','approved')
    ORDER BY submitted_at DESC LIMIT 20
");
$mergeOptions->execute([$id]);
$mergeOptions = $mergeOptions->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<h3 class="mb-4">Review Proposal</h3>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0">
    <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body">
  <h5><?= e($row['title']) ?></h5>
  <p class="text-muted small mb-2">
    <code><?= e($row['proposal_code']) ?></code> · By <?= e($row['researcher_name']) ?>
    · Budget: <?= number_format((float)$row['total_budget'], 2) ?> ETB
  </p>
  <details>
    <summary class="text-primary">Read full proposal</summary>
    <div class="mt-3">
      <strong>Abstract</strong><p><?= nl2br(e($row['abstract'])) ?></p>
      <?php if ($row['objectives']): ?>
        <strong>Objectives</strong><p><?= nl2br(e($row['objectives'])) ?></p>
      <?php endif; ?>
      <?php if ($row['methodology']): ?>
        <strong>Methodology</strong><p><?= nl2br(e($row['methodology'])) ?></p>
      <?php endif; ?>
    </div>
  </details>
  <a href="<?= BASE_URL ?>/modules/proposals/view.php?id=<?= $id ?>" target="_blank"
     class="small">View full proposal with budget & files →</a>
</div></div>

<form method="post">
  <div class="card mb-3"><div class="card-body">
    <label class="form-label"><strong>Score (0–100)</strong></label>
    <input type="number" name="score" class="form-control" required
           min="0" max="100" step="0.5" style="max-width:200px"
           value="<?= $review ? e($review['score']) : '' ?>">
  </div></div>

  <div class="card mb-3"><div class="card-body">
    <label class="form-label"><strong>Comments</strong></label>
    <textarea name="comments" rows="6" class="form-control" required><?= $review ? e($review['comments']) : '' ?></textarea>
  </div></div>

  <div class="card mb-3"><div class="card-body">
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" name="merge_recommended"
             id="merge" value="1"
             <?= $review && $review['merge_recommended'] ? 'checked' : '' ?>
             onchange="document.getElementById('mergeBox').style.display = this.checked ? 'block' : 'none';">
      <label class="form-check-label" for="merge">
        Recommend merging with existing similar research
      </label>
    </div>

    <div id="mergeBox" style="display:<?= $review && $review['merge_recommended'] ? 'block' : 'none' ?>">
      <label class="form-label small">Select the proposal to merge with:</label>
      <select name="merge_target_id" class="form-select" style="max-width:600px">
        <option value="">— Choose —</option>
        <?php foreach ($mergeOptions as $m): ?>
          <option value="<?= (int)$m['id'] ?>"
            <?= $review && (int)$review['merge_target_id'] === (int)$m['id'] ? 'selected' : '' ?>>
            <?= e($m['proposal_code'] . ' — ' . $m['title']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div></div>

  <div class="d-flex gap-2">
    <button class="btn btn-primary">Submit Review</button>
    <a href="<?= BASE_URL ?>/modules/reviews/my_assignments.php" class="btn btn-secondary">Cancel</a>
  </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>