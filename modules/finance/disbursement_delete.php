<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('finance');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger', 'Invalid ID.'); redirect('/modules/finance/disbursements.php'); }

$stmt = $pdo->prepare("SELECT amount, project_id FROM disbursements WHERE id = ?");
$stmt->execute([$id]);
$disb = $stmt->fetch();
if (!$disb) { set_flash('danger', 'Disbursement not found.'); redirect('/modules/finance/disbursements.php'); }

$pdo->prepare("DELETE FROM disbursements WHERE id = ?")->execute([$id]);

log_action($pdo, 'delete_disbursement', 'disbursements', $id,
    "Deleted: " . number_format((float)$disb['amount'], 2) . " ETB");

set_flash('success', 'Disbursement deleted.');
redirect('/modules/finance/disbursements.php');