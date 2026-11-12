<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$user = current_user();

// Mark all as read
if (isset($_GET['mark_all']) && $_GET['mark_all'] === '1') {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")
        ->execute([$user['id']]);
    set_flash('success', 'All notifications marked as read.');
    redirect('/modules/notifications/list.php');
}

// Mark single as read
if (isset($_GET['read'])) {
    $nid = (int)$_GET['read'];
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
        ->execute([$nid, $user['id']]);
    redirect('/modules/notifications/list.php');
}

// Delete single
if (isset($_GET['delete'])) {
    $nid = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?")
        ->execute([$nid, $user['id']]);
    set_flash('success', 'Notification deleted.');
    redirect('/modules/notifications/list.php');
}

// Delete all read
if (isset($_GET['clear_read']) && $_GET['clear_read'] === '1') {
    $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1")
        ->execute([$user['id']]);
    set_flash('success', 'All read notifications deleted.');
    redirect('/modules/notifications/list.php');
}

// Filters
$filter = $_GET['filter'] ?? 'all';
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where  = "WHERE user_id = ?";
$params = [$user['id']];

if ($filter === 'unread') {
    $where .= " AND is_read = 0";
} elseif ($filter === 'read') {
    $where .= " AND is_read = 1";
}

// Counts
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user['id']]);
$unreadCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 1");
$stmt->execute([$user['id']]);
$readCount = (int)$stmt->fetchColumn();

$totalCount = $unreadCount + $readCount;

// Total for current filter
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications $where");
$stmt->execute($params);
$totalRows  = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Fetch
$stmt = $pdo->prepare("
    SELECT * FROM notifications
    $where
    ORDER BY is_read ASC, created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$notes = $stmt->fetchAll();

// Human-friendly time
function human_time($datetime) {
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
    if ($diff < 172800) return 'Yesterday ' . date('H:i', $ts);
    if ($diff < 604800) return date('D H:i', $ts);
    return date('d M Y', $ts);
}

// Action label from title
function notif_action_label($title) {
    $t = strtolower($title);
    if (str_contains($t, 'resubmit'))  return 'Review proposal →';
    if (str_contains($t, 'approved'))  return 'View proposal →';
    if (str_contains($t, 'reject'))    return 'View proposal →';
    if (str_contains($t, 'return'))    return 'Revise proposal →';
    if (str_contains($t, 'submit'))    return 'Review proposal →';
    if (str_contains($t, 'assign'))    return 'Open review →';
    if (str_contains($t, 'welcome'))   return 'View profile →';
    return 'Open →';
}

include __DIR__ . '/../../includes/header.php';
?>

<!-- ============ HEADER ============ -->
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <h3 class="mb-0">
      Notifications
      <?php if ($unreadCount): ?>
        <span class="badge bg-danger"><?= $unreadCount ?> unread</span>
      <?php endif; ?>
    </h3>
    <small class="text-muted">
      Showing <?= count($notes) ?> of <?= $totalRows ?>
    </small>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($unreadCount): ?>
      <a href="?mark_all=1" class="btn btn-outline-secondary btn-sm"
         onclick="return confirm('Mark all as read?');">
        ✓ Mark all as read
      </a>
    <?php endif; ?>
    <?php if ($readCount): ?>
      <a href="?clear_read=1" class="btn btn-outline-danger btn-sm"
         onclick="return confirm('Delete all read notifications? This cannot be undone.');">
        🗑 Delete read (<?= $readCount ?>)
      </a>
    <?php endif; ?>
  </div>
</div>

<!-- ============ FILTER TABS ============ -->
<ul class="nav nav-pills mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all">
      All <span class="badge bg-secondary ms-1"><?= $totalCount ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $filter === 'unread' ? 'active' : '' ?>" href="?filter=unread">
      Unread
      <?php if ($unreadCount): ?>
        <span class="badge bg-danger ms-1"><?= $unreadCount ?></span>
      <?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $filter === 'read' ? 'active' : '' ?>" href="?filter=read">
      Read
      <?php if ($readCount): ?>
        <span class="badge bg-secondary ms-1"><?= $readCount ?></span>
      <?php endif; ?>
    </a>
  </li>
</ul>

<!-- ============ LIST ============ -->
<div class="card">
  <?php if (!$notes): ?>
    <div class="card-body text-center py-5">
      <div style="font-size:3rem;">🔔</div>
      <h5 class="mt-2">
        <?php if ($filter === 'unread'): ?>
          No unread notifications
        <?php elseif ($filter === 'read'): ?>
          No read notifications
        <?php else: ?>
          No notifications yet
        <?php endif; ?>
      </h5>
      <p class="text-muted">
        You'll see updates here when proposals move through the workflow.
      </p>
      <?php if ($filter !== 'all'): ?>
        <a href="?filter=all" class="btn btn-outline-secondary btn-sm me-2">Show all</a>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary btn-sm">Back to Dashboard</a>
    </div>
  <?php else: ?>
    <ul class="list-group list-group-flush">
      <?php foreach ($notes as $n): ?>
        <?php [$icon, $color] = notif_icon($n['title']); ?>
        <li class="list-group-item py-3" 
            style="border-left:4px solid var(--bs-<?= $color ?>);
                   <?= $n['is_read'] ? '' : 'background:#f0f7ff;' ?>">
          <div class="d-flex gap-3">
            <div style="font-size:1.5rem; line-height:1;"><?= $icon ?></div>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between flex-wrap align-items-start">
                <div>
                  <strong <?= $n['is_read'] ? 'class="text-muted"' : '' ?>>
                    <?= e($n['title']) ?>
                  </strong>
                  <?php if (!$n['is_read']): ?>
                    <span class="badge bg-primary ms-2" style="font-size:.65rem;">New</span>
                  <?php endif; ?>
                </div>
                <small class="text-muted" title="<?= e($n['created_at']) ?>">
                  <?= e(human_time($n['created_at'])) ?>
                </small>
              </div>
              <p class="mb-2 small mt-1 <?= $n['is_read'] ? 'text-muted' : 'text-dark' ?>">
                <?= e($n['message']) ?>
              </p>

              <div class="d-flex gap-3 small flex-wrap">
                <?php if ($n['link']): ?>
                  <a href="<?= e($n['link']) ?>" class="fw-semibold text-decoration-none">
                    <?= e(notif_action_label($n['title'])) ?>
                  </a>
                <?php endif; ?>
                <?php if (!$n['is_read']): ?>
                  <a href="?read=<?= (int)$n['id'] ?>&filter=<?= e($filter) ?>"
                     class="text-secondary text-decoration-none">
                    ✓ Mark as read
                  </a>
                <?php endif; ?>
                <a href="?delete=<?= (int)$n['id'] ?>&filter=<?= e($filter) ?>"
                   class="text-danger text-decoration-none"
                   onclick="return confirm('Delete this notification?');">
                  ✕ Delete
                </a>
              </div>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <div class="card-footer">
        <nav>
          <ul class="pagination pagination-sm mb-0 justify-content-center">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="?filter=<?= e($filter) ?>&page=<?= $page - 1 ?>">«</a>
            </li>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
              <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?filter=<?= e($filter) ?>&page=<?= $i ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="?filter=<?= e($filter) ?>&page=<?= $page + 1 ?>">»</a>
            </li>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>