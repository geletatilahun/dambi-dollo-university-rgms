<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('finance');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger', 'Invalid ID.'); redirect('/modules/finance/expenses.php'); }

$stmt = $pdo->prepare("SELECT amount, receipt_path FROM expenses WHERE id = ?");
$stmt->execute([$id]);
$exp = $stmt->fetch();
if (!$exp) { set_flash('danger', 'Expense not found.'); redirect('/modules/finance/expenses.php'); }

// Delete receipt file if exists
if ($exp['receipt_path']) {
    $full = __DIR__ . '/../../' . $exp['receipt_path'];
    if (file_exists($full)) @unlink($full);
}

$pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);

log_action($pdo, 'delete_expense', 'expenses', $id,
    "Deleted: " . number_format((float)$exp['amount'], 2) . " ETB");

set_flash('success', 'Expense deleted.');
redirect('/modules/finance/expenses.php');