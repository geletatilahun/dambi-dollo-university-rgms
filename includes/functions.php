<?php
/**
 * Common helper functions
 * DDU-RGMS — PHP 7.3 compatible
 *
 * Every function is guarded with function_exists() so duplicates
 * in other files will NOT cause "Cannot redeclare" fatal errors.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ============================================================
 * POLYFILLS FOR PHP < 8.0
 * ============================================================ */

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        if ($needle === '') return true;
        $len = strlen($needle);
        return $len <= strlen($haystack) && substr_compare($haystack, $needle, -$len) === 0;
    }
}

/* ============================================================
 * OUTPUT & NAVIGATION
 * ============================================================ */

if (!function_exists('e')) {
    function e($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('val')) {
    function val($arr, $key, $default = '') {
        return htmlspecialchars($arr[$key] ?? $default, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    function redirect($path) {
        header("Location: " . BASE_URL . $path);
        exit;
    }
}

/* ============================================================
 * FLASH MESSAGES
 * ============================================================ */

if (!function_exists('set_flash')) {
    function set_flash($type, $message) {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('get_flash')) {
    function get_flash() {
        if (!empty($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $flash;
        }
        return null;
    }
}

/* ============================================================
 * AUTHENTICATION HELPERS
 * ============================================================ */

if (!function_exists('current_user')) {
    function current_user() {
        if (!isset($_SESSION['user'])) return null;

        // 30-minute session timeout (1800 seconds)
        $timeout = 30 * 60; 
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
            // Session expired
            session_unset();
            session_destroy();
            return null;
        }
        
        // Update last activity timestamp
        $_SESSION['last_activity'] = time();
        
        return $_SESSION['user'];
    }
}

if (!function_exists('has_role')) {
    function has_role(...$roles) {
        $user = current_user();
        return $user && in_array($user['role'], $roles, true);
    }
}

/* ============================================================
 * BUSINESS LOGIC HELPERS
 * ============================================================ */

if (!function_exists('generate_proposal_code')) {
    function generate_proposal_code($pdo) {
        $year = date('Y');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM proposals WHERE YEAR(created_at) = ?");
        $stmt->execute([$year]);
        $count = (int)$stmt->fetchColumn() + 1;
        return sprintf('DDU-RGMS-%s-%04d', $year, $count);
    }
}

if (!function_exists('log_action')) {
    function log_action($pdo, $action, $table = null, $record_id = null, $details = null) {
        $user = current_user();
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, action, table_name, record_id, details, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user['id'] ?? null,
            $action,
            $table,
            $record_id,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    }
}

if (!function_exists('notify')) {
    function notify($pdo, $user_id, $title, $message, $link = null) {
        // 1. Save in-app notification
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, link)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $title, $message, $link]);

        // 2. Send email (best-effort — errors don't block the workflow)
        try {
            // Load recipient
            $stmt = $pdo->prepare("SELECT full_name, email, is_active FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $recipient = $stmt->fetch();

            if ($recipient && $recipient['is_active'] && !empty($recipient['email'])) {
                // Load mailer only when needed
                require_once __DIR__ . '/mailer.php';

                // Build email body with optional link
                $body = '<p>' . htmlspecialchars($message) . '</p>';
                if ($link) {
                    $body .= '<p style="margin-top:20px;">'
                           . '<a href="' . htmlspecialchars($link) . '" '
                           . 'style="display:inline-block;background:#1a4a8a;'
                           . 'color:#fff;text-decoration:none;padding:10px 20px;'
                           . 'border-radius:6px;font-weight:600;">Open in DDU-RGMS →</a>'
                           . '</p>';
                }
                send_email($pdo, $recipient['email'], $recipient['full_name'],
                           '[DDU-RGMS] ' . $title, $body);
            }
        } catch (Exception $e) {
            @error_log('notify() email failed: ' . $e->getMessage());
        }
    }
}

/* ============================================================
 * STATUS & UI HELPERS
 * ============================================================ */

if (!function_exists('status_badge')) {
    function status_badge($status) {
        $map = [
            'draft'            => 'secondary',
            'submitted'        => 'primary',
            'under_review'     => 'info',
            'college_review'   => 'info',
            'director_review'  => 'warning',
            'vp_review'        => 'warning',
            'approved'         => 'success',
            'rejected'         => 'danger',
            'returned'         => 'danger',
            'project_created'  => 'success',
        ];
        return $map[$status] ?? 'secondary';
    }
}

if (!function_exists('action_color')) {
    function action_color($action) {
        $a = strtolower($action);
        if (str_contains($a, 'login'))   return 'primary';
        if (str_contains($a, 'logout'))  return 'secondary';
        if (str_contains($a, 'delete'))  return 'danger';
        if (str_contains($a, 'reject'))  return 'danger';
        if (str_contains($a, 'return'))  return 'warning';
        if (str_contains($a, 'approve')) return 'success';
        if (str_contains($a, 'create'))  return 'success';
        if (str_contains($a, 'submit'))  return 'primary';
        if (str_contains($a, 'update'))  return 'info';
        if (str_contains($a, 'assign'))  return 'info';
        return 'secondary';
    }
}

if (!function_exists('notif_icon')) {
    function notif_icon($title) {
        $t = strtolower($title);
        if (str_contains($t, 'approved')) return ['✅', 'success'];
        if (str_contains($t, 'reject'))   return ['❌', 'danger'];
        if (str_contains($t, 'return'))   return ['↩',  'warning'];
        if (str_contains($t, 'submit'))   return ['📤', 'primary'];
        if (str_contains($t, 'review'))   return ['📝', 'info'];
        if (str_contains($t, 'assign'))   return ['👤', 'info'];
        if (str_contains($t, 'welcome'))  return ['👋', 'success'];
        if (str_contains($t, 'disburse')) return ['💸', 'info'];
        if (str_contains($t, 'expense'))  return ['🧾', 'warning'];
        if (str_contains($t, 'closur'))   return ['🏁', 'warning'];
        if (str_contains($t, 'extens'))   return ['📅', 'warning'];
        return ['🔔', 'secondary'];
    }
}

/* ============================================================
 * FORMATTING HELPERS
 * ============================================================ */

if (!function_exists('fmt_date')) {
    function fmt_date($date, $format = 'd M Y') {
        if (!$date) return '—';
        $ts = strtotime($date);
        return $ts ? date($format, $ts) : '—';
    }
}

if (!function_exists('fmt_money')) {
    function fmt_money($amount) {
        return number_format((float)$amount, 2);
    }
}

if (!function_exists('shorten')) {
    function shorten($text, $max = 80) {
        $text = trim($text);
        if (strlen($text) <= $max) return $text;
        $cut = substr($text, 0, $max - 1);
        $lastSpace = strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > ($max * 0.6)) {
            $cut = substr($cut, 0, $lastSpace);
        }
        return $cut . '…';
    }
}

/* ============================================================
 * FINANCE HELPERS
 * ============================================================ */

if (!function_exists('pretty_category')) {
    function pretty_category($cat) {
        $cat = trim((string)$cat);
        $map = [
            '1' => 'Equipment',
            '2' => 'Travel',
            '3' => 'Supplies',
            '4' => 'Personnel',
            '5' => 'Services',
            '6' => 'Other',
            // Legacy spelling variants
            'equip'     => 'Equipment',
            'equipment' => 'Equipment',
            'travel'    => 'Travel',
            'supplies'  => 'Supplies',
            'supply'    => 'Supplies',
            'personnel' => 'Personnel',
            'services'  => 'Services',
            'service'   => 'Services',
        ];
        return $map[strtolower($cat)] ?? ($cat !== '' ? $cat : 'Other');
    }
}

/* ============================================================
 * QUERY STRING HELPERS
 * ============================================================ */

if (!function_exists('qs')) {
    function qs($overrides = []) {
        $base = $_GET;
        foreach ($overrides as $k => $v) {
            if ($v === null) unset($base[$k]);
            else $base[$k] = $v;
        }
        return '?' . http_build_query($base);
    }
}

if (!function_exists('qsa')) {
    function qsa($overrides = []) {
        return qs($overrides);
    }
}