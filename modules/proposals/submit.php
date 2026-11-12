<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('researcher');

$user = current_user();
$id   = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM proposals WHERE id = ? AND researcher_id = ?");
$stmt->execute([$id, $user['id']]);
$proposal = $stmt->fetch();

if (!$proposal) {
    set_flash('danger', 'Proposal not found.');
    redirect('/modules/proposals/list.php');
}

if (!in_array($proposal['status'], ['draft','returned'], true)) {
    set_flash('warning', 'This proposal has already been submitted.');
    redirect('/modules/proposals/view.php?id=' . $id);
}

// Must have at least one budget item
$chk = $pdo->prepare("SELECT COUNT(*) FROM budget_items WHERE proposal_id = ?");
$chk->execute([$id]);
if ((int)$chk->fetchColumn() === 0) {
    set_flash('danger', 'Cannot submit: no budget items.');
    redirect('/modules/proposals/edit.php?id=' . $id);
}

$pdo->prepare("UPDATE proposals SET status='submitted', submitted_at=NOW() WHERE id=?")
    ->execute([$id]);

// Notify coordinators
$cos = $pdo->query("
    SELECT u.id FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE r.name='coordinator' AND u.is_active=1
")->fetchAll();

foreach ($cos as $c) {
    notify($pdo, $c['id'], '📤 New Proposal Awaiting Review',
        "Proposal {$proposal['proposal_code']} awaits your review.",
        BASE_URL . "/modules/proposals/view.php?id={$id}");
}

log_action($pdo, 'submit_proposal', 'proposals', $id, $proposal['proposal_code']);
set_flash('success', 'Proposal submitted successfully.');
redirect('/modules/proposals/view.php?id=' . $id);