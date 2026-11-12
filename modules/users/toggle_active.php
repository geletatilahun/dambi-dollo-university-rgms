<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('admin');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('danger', 'Invalid user ID.'); redirect('/modules/users/list.php'); }

// Prevent admin from deactivating themselves
if ($id === (int)current_user()['id']) {
    set_flash('danger', 'You cannot deactivate your own account.');
    redirect('/modules/users/list.php');
}

$stmt = $pdo->prepare("SELECT id, full_name, email, is_active FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { set_flash('danger', 'User not found.'); redirect('/modules/users/list.php'); }

$newStatus = $user['is_active'] ? 0 : 1;
$pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$newStatus, $id]);

log_action($pdo, $newStatus ? 'activate_user' : 'deactivate_user',
           'users', $id, $user['email']);

set_flash('success', 'User ' . ($newStatus ? 'activated' : 'deactivated') . ' successfully.');
redirect('/modules/users/list.php');