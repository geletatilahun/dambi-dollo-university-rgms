<?php
require_once __DIR__ . '/functions.php';

$user  = current_user();
$flash = get_flash();

$unreadCount = 0;
if ($user) {
    global $pdo;
    if (isset($pdo)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user['id']]);
        $unreadCount = (int)$stmt->fetchColumn();
    }
}

$currentPath = $_SERVER['REQUEST_URI'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e(SITE_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
/* ===== INLINE NAVBAR STYLES (guaranteed to load) ===== */
.ddu-navbar {
    background: linear-gradient(135deg, #0a1e3d 0%, #12315a 100%) !important;
    box-shadow: 0 2px 10px rgba(10, 30, 61, 0.15) !important;
    padding: 0.5rem 0 !important;
    min-height: 62px;
}
.ddu-navbar .brand-logo {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, #f5b500 0%, #e0a000 100%);
    border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    font-weight: 800; color: #0a1e3d; font-size: 0.75rem;
    letter-spacing: 0.5px; flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(245, 181, 0, 0.35);
}
.ddu-navbar .brand-text strong {
    color: #fff; font-size: 1rem; font-weight: 800; letter-spacing: 0.3px;
    display: block; line-height: 1.1;
}
.ddu-navbar .brand-text small {
    color: rgba(255,255,255,0.55); font-size: 0.65rem;
    text-transform: uppercase; letter-spacing: 1px;
    display: block; line-height: 1.2;
}
.ddu-navbar .nav-link {
    color: rgba(255,255,255,0.85) !important;
    font-weight: 500; font-size: 0.92rem;
    padding: 0.5rem 0.85rem !important;
    border-radius: 6px;
    transition: all 0.15s ease;
    position: relative;
}
.ddu-navbar .nav-link:hover {
    color: #fff !important;
    background: rgba(255,255,255,0.08);
}
.ddu-navbar .nav-link.active {
    color: #fff !important;
    background: rgba(245,181,0,0.15);
    font-weight: 600;
}
.ddu-navbar .nav-link.active::after {
    content: '';
    position: absolute;
    left: 0.85rem; right: 0.85rem; bottom: 0.15rem;
    height: 2px; background: #f5b500; border-radius: 2px;
}
.ddu-navbar .badge-notif {
    position: absolute; top: 4px; right: 2px;
    background: #ef4444; color: #fff;
    font-size: 0.65rem; font-weight: 700;
    padding: 2px 6px; border-radius: 10px;
    min-width: 18px; text-align: center;
    line-height: 1.2;
    box-shadow: 0 0 0 2px #0a1e3d;
}
.ddu-navbar .user-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: linear-gradient(135deg, #f5b500 0%, #e0a000 100%);
    color: #0a1e3d; font-weight: 800; font-size: 0.9rem;
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.ddu-navbar .dropdown-menu {
    border: 1px solid #e2e8f0; border-radius: 10px;
    padding: 0.4rem; margin-top: 0.5rem;
    box-shadow: 0 10px 30px rgba(10,30,61,0.1);
}
.ddu-navbar .dropdown-item {
    border-radius: 6px; padding: 0.5rem 0.75rem; font-size: 0.9rem;
}
.ddu-navbar .dropdown-item:hover { background: #f1f5f9; }
.ddu-navbar .navbar-toggler {
    border: 1px solid rgba(255,255,255,0.2) !important;
    padding: 0.35rem 0.55rem;
}
.ddu-navbar .navbar-toggler-icon {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3E%3Cpath stroke='rgba(255,255,255,0.85)' stroke-linecap='round' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3E%3C/svg%3E") !important;
}
body.ddu-app {
    background: #f5f7fa !important;
    font-family: 'Inter', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
    color: #0f172a;
}
@media (max-width: 991px) {
    .ddu-navbar .navbar-collapse {
        background: #0a1e3d;
        margin: 0.5rem -0.75rem 0;
        padding: 0.75rem;
        border-radius: 8px;
    }
    .ddu-navbar .nav-link.active::after { display: none; }
}
</style>
</head>
<body class="ddu-app">

<?php if ($user): ?>
<nav class="navbar navbar-expand-lg ddu-navbar sticky-top">
  <div class="container-fluid px-3">

    <!-- Brand -->
    <a class="navbar-brand d-flex align-items-center gap-2 me-4" href="<?= BASE_URL ?>/index.php">
      <span class="brand-logo">DDU</span>
      <span class="brand-text">
        <strong>DDU–RGMS</strong>
        <small>Research Grant Management</small>
      </span>
    </a>

    <!-- Mobile toggle -->
    <button class="navbar-toggler" type="button"
            data-bs-toggle="collapse" data-bs-target="#navMain"
            aria-controls="navMain" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <!-- Links -->
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">

        <li class="nav-item">
          <a class="nav-link <?= strpos($currentPath, '/index.php') !== false || rtrim($currentPath, '/') === BASE_URL ? 'active' : '' ?>"
             href="<?= BASE_URL ?>/index.php">
            🏠 Dashboard
          </a>
        </li>

        <?php if (has_role('researcher')): ?>
          <li class="nav-item">
            <a class="nav-link <?= strpos($currentPath, '/proposals/list') !== false ? 'active' : '' ?>"
               href="<?= BASE_URL ?>/modules/proposals/list.php">
              My Proposals
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?= strpos($currentPath, '/proposals/create') !== false ? 'active' : '' ?>"
               href="<?= BASE_URL ?>/modules/proposals/create.php">
              New Proposal
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?= strpos($currentPath, '/projects/') !== false ? 'active' : '' ?>"
               href="<?= BASE_URL ?>/modules/projects/my_projects.php">
              My Projects
            </a>
          </li>
        <?php endif; ?>

        <?php if (has_role('coordinator','college','director','vp','admin')): ?>
          <li class="nav-item">
            <a class="nav-link <?= strpos($currentPath, '/proposals/list') !== false ? 'active' : '' ?>"
               href="<?= BASE_URL ?>/modules/proposals/list.php">
              Proposals
            </a>
          </li>
        <?php endif; ?>

        <?php if (has_role('coordinator','college','director','vp')): ?>
          <li class="nav-item">
            <a class="nav-link <?= strpos($currentPath, '/approvals/queue') !== false ? 'active' : '' ?>"
               href="<?= BASE_URL ?>/modules/approvals/queue.php">
              Approval Queue
            </a>
          </li>
        <?php endif; ?>

        <?php if (has_role('reviewer')): ?>
          <li class="nav-item">
            <a class="nav-link <?= strpos($currentPath, '/reviews/') !== false ? 'active' : '' ?>"
               href="<?= BASE_URL ?>/modules/reviews/my_assignments.php">
              My Reviews
            </a>
          </li>
        <?php endif; ?>

        <?php if (has_role('finance')): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle <?= strpos($currentPath, '/finance/') !== false ? 'active' : '' ?>"
               href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              💰 Finance
            </a>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/finance/projects.php">📁 Projects</a></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/finance/reports.php">📊 Portfolio Report</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/finance/disbursements.php">💸 Disbursements</a></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/finance/expenses.php">🧾 Expenses</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/finance/disbursement_create.php">➕ Record Disbursement</a></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/finance/expense_create.php">➕ Record Expense</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <?php if (has_role('admin')): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" role="button"
               data-bs-toggle="dropdown" aria-expanded="false">
              Admin Tools
            </a>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/users/list.php">👥 Manage Users</a></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/users/create.php">➕ Add User</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/admin/audit_log.php">📋 Audit Log</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <li class="nav-item">
          <a class="nav-link position-relative <?= strpos($currentPath, '/notifications/') !== false ? 'active' : '' ?>"
             href="<?= BASE_URL ?>/modules/notifications/list.php">
            🔔 Notifications
            <?php if ($unreadCount): ?>
              <span class="badge-notif"><?= $unreadCount ?></span>
            <?php endif; ?>
          </a>
        </li>
      </ul>

      <!-- User menu -->
      <ul class="navbar-nav">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#"
             role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="user-avatar">
              <?= e(strtoupper(substr($user['full_name'], 0, 1))) ?>
            </span>
            <span class="d-none d-lg-inline"><?= e($user['full_name']) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end" style="min-width:240px;">
            <li class="px-3 py-2">
              <div class="fw-semibold"><?= e($user['full_name']) ?></div>
              <small class="text-muted d-block"><?= e($user['email'] ?? '') ?></small>
              <span class="badge bg-primary mt-1"><?= e(ucfirst($user['role'])) ?></span>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/profile/view.php">👤 My Profile</a></li>
            <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/profile/edit.php">✏️ Edit Profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php">🚪 Logout</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
<?php endif; ?>

<main class="container my-4">
<?php if ($flash): ?>
  <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show d-flex align-items-center">
    <span class="me-2" style="font-size:1.2rem;">
      <?= $flash['type'] === 'success' ? '✅' : ($flash['type'] === 'danger' ? '⚠️' : 'ℹ️') ?>
    </span>
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>