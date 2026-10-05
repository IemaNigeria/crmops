<?php
/**
 * IEMA CRMOps — Shared Header
 * -----------------------------------------------------------------------
 * Include this at the top of any protected page:
 *
 *   require_once __DIR__ . '/../config.php';
 *   require_login();
 *   require_once __DIR__ . '/includes/header.php';
 *
 * Renders <!DOCTYPE> ... sidebar ... topbar ... and opens <main>.
 * The including page is responsible for closing </main> before
 * requiring footer.php.
 * -----------------------------------------------------------------------
 */

// IEMA Portal: re-confirm every few minutes that this person is still
// active in the Portal (suspensions there take effect here too).
if (is_file(__DIR__ . '/../iema-sso/adapter.php')) {
    require_once __DIR__ . '/../iema-sso/adapter.php';
    iema_sso_page_check();
}
$ssoPortalLink = function_exists('iema_sso_enabled') && iema_sso_enabled() ? iema_sso_portal_url() : null;

$role      = current_role();
$roleLabel = current_role_label();
$navItems  = nav_for_role($role);
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');
// Match nav items on the script name only, so links that carry a query
// string (e.g. leads.php?stage=New) still highlight as active.
$navHrefPage = fn(string $href): string => basename(parse_url($href, PHP_URL_PATH) ?: $href);
$currentNavLabel = 'Dashboard';
foreach ($navItems as $navItem) {
    if ($navHrefPage($navItem['href']) === $currentPage) { $currentNavLabel = $navItem['label']; break; }
}
if ($currentPage === 'mail-settings.php') $currentNavLabel = 'Mail Settings';
$userName  = $_SESSION['user_name'] ?? 'Guest User';
$userInitials = '';
foreach (explode(' ', trim($userName)) as $part) {
    $userInitials .= strtoupper(substr($part, 0, 1));
}
$userInitials = substr($userInitials, 0, 2) ?: 'U';

// Surveillance-task notifications (e.g. "closed and settled" alerts to
// whoever assigned it). Wrapped in try/catch so a site that hasn't yet
// run the surveillance_task_notifications migration doesn't fatal on
// every single page — this header is included everywhere.
$headerNotifications = [];
try {
    $notifStmt = $pdo->prepare("
        SELECT id, task_id, message, is_read, created_at
        FROM surveillance_task_notifications
        WHERE recipient_id = ?
        ORDER BY is_read ASC, created_at DESC
        LIMIT 8
    ");
    $notifStmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $headerNotifications = $notifStmt->fetchAll();
} catch (Throwable $e) {
    $headerNotifications = [];
}
$headerUnreadCount = count(array_filter($headerNotifications, fn($n) => !$n['is_read']));

function notif_time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'Yesterday';
    return floor($diff / 86400) . 'd ago';
}

/** Minimal inline icon set — Feather-style strokes, no icon-font dependency. */
function icon(string $name, string $class = ''): string
{
    $paths = [
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect>',
        'target'   => '<circle cx="12" cy="12" r="8"></circle><circle cx="12" cy="12" r="4"></circle><circle cx="12" cy="12" r="0.6" fill="currentColor"></circle>',
        'clock'    => '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3.5 2"></path>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path><circle cx="10" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path>',
        'trend'    => '<path d="M23 6l-9.5 9.5-5-5L1 18"></path><path d="M17 6h6v6"></path>',
        'chart'    => '<path d="M3 3v18h18"></path><rect x="7" y="12" width="3" height="6"></rect><rect x="13" y="8" width="3" height="10"></rect><rect x="19" y="5" width="3" height="13"></rect>',
        'settings' => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>',
        'refresh'  => '<path d="M23 4v6h-6"></path><path d="M1 20v-6h6"></path><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10"></path><path d="M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>',
        'search'   => '<circle cx="11" cy="11" r="7"></circle><path d="M21 21l-4.3-4.3"></path>',
        'bell'     => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path>',
        'chevron'  => '<path d="M6 9l6 6 6-6"></path>',
        'chevron-right' => '<path d="M9 18l6-6-6-6"></path>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><path d="M16 17l5-5-5-5"></path><path d="M21 12H9"></path>',
        'plus'     => '<path d="M12 5v14"></path><path d="M5 12h14"></path>',
        'edit'     => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"></path>',
        'trash'    => '<path d="M3 6h18"></path><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>',
        'check'    => '<path d="M20 6L9 17l-5-5"></path>',
        'x'        => '<path d="M18 6L6 18"></path><path d="M6 6l12 12"></path>',
        'mail'     => '<path d="M4 4h16v16H4z"></path><path d="M22 6l-10 7L2 6"></path>',
        'phone'    => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path>',
        'building' => '<rect x="4" y="2" width="16" height="20" rx="1"></rect><path d="M9 22v-4h6v4"></path><path d="M9 6h.01M9 10h.01M9 14h.01M15 6h.01M15 10h.01M15 14h.01"></path>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18"></path>',
        'columns'  => '<rect x="3" y="3" width="7" height="18" rx="1"></rect><rect x="14" y="3" width="7" height="10" rx="1"></rect>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><path d="M7 10l5 5 5-5"></path><path d="M12 15V3"></path>',
        'filter'   => '<path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"></path>',
        'lock'     => '<rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>',
        'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>',
        'alert'    => '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>',
        'dots'     => '<circle cx="12" cy="5" r="1.3"></circle><circle cx="12" cy="12" r="1.3"></circle><circle cx="12" cy="19" r="1.3"></circle>',
        'send'     => '<path d="M22 2L11 13"></path><path d="M22 2l-7 20-4-9-9-4 20-7z"></path>',
        'briefcase'=> '<rect x="2" y="7" width="20" height="14" rx="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>',
        'clipboard'=> '<rect x="8" y="2" width="8" height="4" rx="1"></rect><path d="M9 4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-4"></path><path d="M9 12l2 2 4-4"></path>',
    ];
    $d = $paths[$name] ?? $paths['grid'];
    return '<svg class="icon ' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e(($pageTitle ?? $currentNavLabel) . ' — ' . APP_NAME); ?></title>
<meta name="theme-color" content="#0F1B2D">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/crm-bento.css?v=<?php echo (int) @filemtime(__DIR__ . '/../assets/crm-bento.css'); ?>">
</head>
<body>
<div class="shell">

  <aside class="sidebar" id="appSidebar" aria-label="Main navigation">
    <div class="sidebar-brand">
      <a class="sidebar-logo" href="dashboard.php" title="<?php echo e(APP_ORG); ?>">
        <img src="assets/logo.png" alt="<?php echo e(APP_ORG); ?>" onerror="this.style.display='none'">
        <span style="font-family:'Libre Franklin',sans-serif;font-weight:800;font-size:13px;color:var(--navy-900);">CRMops</span>
      </a>
      <button type="button" class="sidebar-close" onclick="toggleSidebar(false)" aria-label="Close menu"><?php echo icon('x'); ?></button>
    </div>

    <div class="sidebar-role"><span class="role-dot"></span><?php echo e($roleLabel); ?></div>

    <div class="sidebar-section">Workspace</div>
    <nav class="sidebar-nav">
      <?php foreach ($navItems as $item): ?>
        <a class="nav-item<?php echo $navHrefPage($item['href']) === $currentPage ? ' active' : ''; ?>" href="<?php echo e($item['href']); ?>">
          <?php echo icon($item['icon']); ?>
          <span><?php echo e($item['label']); ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <?php if ($role !== 'auditor'): ?>
        <a class="nav-item<?php echo $currentPage === 'mail-settings.php' ? ' active' : ''; ?>" href="mail-settings.php">
          <?php echo icon('settings'); ?><span>Mail Settings</span>
        </a>
      <?php endif; ?>
      <a class="nav-item" href="logout.php">
        <?php echo icon('logout'); ?><span>Sign Out</span>
      </a>
    </div>
  </aside>
  <div class="sidebar-scrim" onclick="toggleSidebar(false)"></div>

  <div class="content">
    <header class="topbar">
      <div class="topbar-left">
        <button type="button" class="icon-button menu-toggle" onclick="toggleSidebar(true)" aria-label="Open menu">
          <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div class="search-wrap">
          <div class="search-box" role="search">
            <?php echo icon('search'); ?>
            <input type="text" id="quickJumpInput" placeholder="Jump to a page or search…" autocomplete="off" aria-label="Jump to a page or search" aria-controls="quickJumpList">
            <kbd>/</kbd>
          </div>
          <div class="jump-list" id="quickJumpList" style="display:none;" role="listbox"></div>
        </div>
      </div>

      <div class="topbar-actions">
        <div class="notif-wrap">
          <div class="icon-button" id="notifBellBtn" title="Notifications" onclick="toggleNotifPanel()" role="button" tabindex="0" aria-label="Notifications">
            <?php echo icon('bell'); ?>
            <?php if ($headerUnreadCount > 0): ?><span class="dot"></span><?php endif; ?>
          </div>
          <div class="notif-panel" id="notifPanel" style="display:none;">
            <div class="notif-panel-head">
              <span>Notifications</span>
              <?php if ($headerUnreadCount > 0): ?>
                <a onclick="markAllNotificationsRead()">Mark all read</a>
              <?php endif; ?>
            </div>
            <?php if (empty($headerNotifications)): ?>
              <div class="notif-empty">No notifications yet</div>
            <?php else: ?>
              <?php foreach ($headerNotifications as $n): ?>
                <a class="notif-item" href="surveillance-tasks.php" style="<?php echo $n['is_read'] ? '' : 'background:var(--red-50);'; ?>">
                  <div class="notif-text"><?php echo e($n['message']); ?></div>
                  <div class="notif-time"><?php echo notif_time_ago($n['created_at']); ?></div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="user-wrap">
          <div class="user-chip" role="button" tabindex="0" aria-haspopup="menu" aria-controls="userMenu">
            <div class="avatar"><?php echo e($userInitials); ?></div>
            <div class="user-meta">
              <div class="name"><?php echo e($userName); ?></div>
              <div class="role"><?php echo e($roleLabel); ?></div>
            </div>
            <?php echo icon('chevron'); ?>
          </div>
          <div class="user-menu" id="userMenu" style="display:none;" role="menu">
            <?php if ($role !== 'auditor'): ?>
              <a href="mail.php" role="menuitem"><?php echo icon('mail'); ?> Mail</a>
              <a href="mail-settings.php" role="menuitem"><?php echo icon('settings'); ?> Mail settings &amp; signature</a>
              <div class="sep"></div>
            <?php endif; ?>
            <?php if ($ssoPortalLink): ?>
              <?php foreach (iema_sso_other_apps() as $ssoKey => $ssoName): ?>
                <a href="<?php echo e(iema_sso_switch_url($ssoKey)); ?>" role="menuitem"><?php echo icon('chevron-right'); ?> Switch to <?php echo e($ssoName); ?></a>
              <?php endforeach; ?>
              <a href="<?php echo e($ssoPortalLink); ?>" role="menuitem"><?php echo icon('grid'); ?> IEMA Portal home</a>
              <div class="sep"></div>
            <?php endif; ?>
            <a href="logout.php" role="menuitem"><?php echo icon('logout'); ?> Sign out<?php echo $ssoPortalLink ? ' of all apps' : ''; ?></a>
          </div>
        </div>
      </div>
    </header>

    <script>
      // Pages this role can open — used by the topbar quick-jump (footer.php).
      window.CRM_NAV = <?php echo json_encode(array_values(array_map(fn($i) => ['label' => $i['label'], 'href' => $i['href']], $navItems)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>

<main>