<?php
/**
 * Authentication + role guard
 */

require_once __DIR__ . '/functions.php';

/** Require login; optionally require specific role(s) */
function require_login(...$roles) {
    if (!current_user()) {
        set_flash('warning', 'Please log in first.');
        redirect('/login.php');
    }
    if (!empty($roles) && !has_role(...$roles)) {
        set_flash('danger', 'You do not have permission to access that page.');
        redirect('/index.php');
    }
}

/** Attempt login; returns true on success */
function attempt_login($pdo, $email, $password) {
    $stmt = $pdo->prepare("
        SELECT u.*, r.name AS role
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.email = ? LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

if (!$user) return false;

// Block inactive accounts
if (!$user['is_active']) {
    set_flash('danger', 'Your account has been deactivated. Contact the administrator.');
    return false;
}
    // Check lock
    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        set_flash('danger', 'Account locked. Try again later.');
        return false;
    }

    if (!password_verify($password, $user['password'])) {
        // increment failed attempts
        $attempts = $user['failed_attempts'] + 1;
        $locked   = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        $upd = $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?");
        $upd->execute([$attempts, $locked, $user['id']]);
        return false;
    }

    // Reset attempts + update last login
    $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?")
        ->execute([$user['id']]);
    session_regenerate_id(true);
    // Save minimal data in session
    $_SESSION['user'] = [
    'id'         => $user['id'],
    'full_name'  => $user['full_name'],
    'email'      => $user['email'],
    'role'       => $user['role'],
    'role_id'    => $user['role_id'],
    'last_login' => $user['last_login'],
];

    log_action($pdo, 'login', 'users', $user['id'], 'User logged in');
    return true;
}

/** Log out */
function logout($pdo) {
    $user = current_user();
    if ($user) {
        log_action($pdo, 'logout', 'users', $user['id'], 'User logged out');
    }
    session_unset();
    session_destroy();
}