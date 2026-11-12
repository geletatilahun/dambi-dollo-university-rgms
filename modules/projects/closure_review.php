<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('coordinator','director','finance','vp');

$user = current_user();
$role = $user['role'];
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT c.*, p.project_code, p.leader_id, p.funds_awarded, pr.title AS proposal_title, u.full_name AS requester_name
    FROM project_closures c
    JOIN projects p ON p.id = c.project_id
    JOIN proposals pr ON pr.id = p.proposal_id
    JOIN users u ON u.id = c.requested_by
    WHERE c.id = ?
");
$stmt->execute([$id]);
$closure = $stmt->fetch();
if (!$closure) { set_flash('danger', 'Closure not found.'); redirect('/index.php'); }

$stageMap = ['coordinator'=>'pending_coordinator', 'director'=>'pending_director', 'finance'=>'pending_finance', 'vp'=>'pending_vp'];
$myStage = $stageMap[$role] ?? null;

if ($closure['status'] !== $myStage) {
    set_flash('warning', "This closure is not at your stage.");
    redirect('/modules/projects/view.php?id=' . $closure['project_id']);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');
    if ($action === 'reject' && $comment === '') $errors[] = "Comment required to reject.";

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $col = $role . '_comment';
            $idcol = $role . '_id';
            $datecol = $role . '_decided_at';

            if ($action === 'reject') {
                $pdo->prepare("UPDATE project_closures SET status = 'rejected', {$idcol} = ?, {$col} = ?, {$datecol} = NOW() WHERE id = ?")->execute([$user['id'], $comment, $id]);
                notify($pdo, $closure['leader_id'], '❌ Closure Request Rejected', "Reason: {$comment}", BASE_URL . "/modules/projects/view.php?id={$closure['project_id']}");
                $msg = 'Closure rejected.';
            } else {
                $flow = ['coordinator'=>'pending_director', 'director'=>'pending_finance', 'finance'=>'pending_vp', 'vp'=>'closed'];
                $newStatus = $flow[$role];
                $pdo->prepare("UPDATE project_closures SET status = ?, {$idcol} = ?, {$col} = ?, {$datecol} = NOW() WHERE id = ?")->execute([$newStatus, $user['id'], $comment, $id]);

                if ($role === 'vp') {
                    $pdo->prepare("UPDATE projects SET status = 'completed' WHERE id = ?")->execute([$closure['project_id']]);
                    notify($pdo, $closure['leader_id'], '🏁 Project Closed', "Your project {$closure['project_code']} has been officially closed.", BASE_URL . "/modules/projects/view.php?id={$closure['project_id']}");
                } else {
                    $nextRole = ['pending_director'=>'director', 'pending_finance'=>'finance', 'pending_vp'=>'vp'][$newStatus];
                    $actors = $pdo->prepare("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = ? AND u.is_active = 1");
                    $actors->execute([$nextRole]);
                    foreach ($actors->fetchAll() as $a) {
                        notify($pdo, $a['id'], '🏁 Project Closure Needs Review', "Closure for {$closure['project_code']} advanced.", BASE_URL . "/modules/projects/closure_review.php?id={$id}");
                    }
                }
                $msg = 'Closure advanced.';
            }
            log_action($pdo, 'closure_' . $action, 'project_closures', $id, "Role: $role");
            $pdo->commit();
            set_flash('success', $msg);
            redirect('/modules/projects/view.php?id=' . $closure['project_id']);
        } catch (Exception $e) {
            $pdo->rollBack(); $errors[] = $e->getMessage();
        }
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<div class="row"><div class="col-lg-9 mx-auto">
  <h3>Closure Review (<?= e(ucfirst($role)) ?>)</h3>
  <?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
  <div class="card mb-3"><div class="card-body">
    <strong><?= e($closure['project_code']) ?></strong> — <?= e($closure['proposal_title']) ?>
  </div></div>
  <form method="post">
    <div class="card mb-3">
      <div class="card-body">
        <label class="form-label">Comment</label>
        <textarea name="comment" rows="3" class="form-control"></textarea>
      </div>
      <div class="card-footer d-flex gap-2">
        <button name="action" value="approve" class="btn btn-success">✅ Approve</button>
        <button name="action" value="reject" class="btn btn-danger">❌ Reject</button>
      </div>
    </div>
  </form>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>