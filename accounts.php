<?php
/**
 * IEMA CRMOps — My Accounts (Client Relationship Manager)
 * -----------------------------------------------------------------------
 * Paginated (50/page), cert data loaded on-demand via AJAX.
 * Stat cards clickable to filter by cert status.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_login();

if (!role_is(['account_manager', 'management', 'it_admin'])) {
    header('Location: dashboard.php');
    exit;
}

$role = current_role();
$uid  = (int) ($_SESSION['user_id'] ?? 0);
$canAssignTask = role_is(['account_manager', 'management']);
$error   = '';
$success = '';

// ---- Update health status -----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_health') {
    $clientId     = (int) ($_POST['client_id'] ?? 0);
    $healthStatus = $_POST['health_status'] ?? '';
    if (in_array($healthStatus, ['Good', 'At Risk', 'Critical'], true)) {
        $scopeSql = $role === 'account_manager' ? ' AND assigned_crm_id = ?' : '';
        $params   = [$healthStatus, $clientId];
        if ($role === 'account_manager') $params[] = $uid;
        $pdo->prepare("UPDATE clients SET health_status = ? WHERE id = ?$scopeSql")->execute($params);
        $success = 'Health status updated.';
        $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)')
            ->execute([$uid, 'update', 'client', $clientId, "Health status set to $healthStatus"]);
    }
}

// ---- Log touchpoint -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_touchpoint') {
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $type     = $_POST['type'] ?? 'Call';
    $notes    = trim($_POST['notes'] ?? '');
    if (in_array($type, ['Call', 'Email', 'Meeting', 'Site Visit'], true) && $clientId > 0) {
        $pdo->prepare('INSERT INTO interactions (client_id, type, interaction_date, notes, logged_by) VALUES (?,?,NOW(),?,?)')
            ->execute([$clientId, $type, $notes ?: null, $uid]);
        $success = 'Touchpoint logged.';
    }
}

// ---- Cert filter (from stat card clicks) ----------------------------------
$certFilter = $_GET['cert_filter'] ?? '';
$certFilterNames = []; // company names matching the cert filter
$certFilterLabel = '';

if (in_array($certFilter, ['active', 'expiring_30', 'expiring_60', 'expired', 'surveillance'], true)) {
    $filterLabels = [
        'active'       => 'Active Certificates',
        'expiring_30'  => 'Expiring in 30 Days',
        'expiring_60'  => 'Expiring in 60 Days',
        'expired'      => 'Expired Certificates',
        'surveillance' => 'Due for Surveillance',
    ];
    $certFilterLabel = $filterLabels[$certFilter];

    // Fetch matching company names from CertAdmin API
    $filterUrl = CERTADMIN_API_URL . '?action=filter_companies&filter=' . urlencode($certFilter);
    $ch = curl_init($filterUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . CERTADMIN_API_KEY],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $filterResp = curl_exec($ch);
    curl_close($ch);
    $filterData = $filterResp ? json_decode($filterResp, true) : null;
    if (!empty($filterData['ok']) && isset($filterData['companies'])) {
        $certFilterNames = $filterData['companies'];
    }
}

// ---- Pagination & search --------------------------------------------------
$perPage = 50;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$search  = trim($_GET['search'] ?? '');
$offset  = ($page - 1) * $perPage;

$scopeWhere  = $role === 'account_manager' ? 'c.assigned_crm_id = ?' : 'c.assigned_crm_id IS NOT NULL';
$scopeParams = $role === 'account_manager' ? [$uid] : [];

$searchWhere  = '';
$searchParams = [];
if ($search !== '') {
    $searchWhere    = " AND c.company_name LIKE ?";
    $searchParams[] = '%' . $search . '%';
}

// Cert filter — restrict to companies matching the CertAdmin filter
$certWhere  = '';
$certParams = [];
if ($certFilter !== '' && !empty($certFilterNames)) {
    $placeholders = implode(',', array_fill(0, count($certFilterNames), '?'));
    $certWhere    = " AND c.company_name IN ($placeholders)";
    $certParams   = $certFilterNames;
} elseif ($certFilter !== '' && empty($certFilterNames)) {
    // Filter active but no matches — return empty
    $certWhere = " AND 1=0";
}

$allWhere  = $scopeWhere . $searchWhere . $certWhere;
$allParams = array_merge($scopeParams, $searchParams, $certParams);

// Total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients c WHERE $allWhere");
$countStmt->execute($allParams);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($totalCount / $perPage);

// Page data
$limitInt  = (int) $perPage;
$offsetInt = (int) $offset;
$stmt = $pdo->prepare("
    SELECT c.id, c.company_name, c.health_status, c.assigned_crm_id,
           c.primary_contact_email,
           u.name AS crm_name,
           (SELECT MAX(i.interaction_date) FROM interactions i WHERE i.client_id = c.id) AS last_touch,
           (SELECT MIN(r.renewal_date) FROM renewal_opportunities r WHERE r.client_id = c.id AND r.follow_up_status != 'Completed') AS next_renewal
    FROM clients c
    LEFT JOIN users u ON u.id = c.assigned_crm_id
    WHERE $allWhere
    ORDER BY FIELD(c.health_status, 'Critical', 'At Risk', 'Good'), c.company_name
    LIMIT $limitInt OFFSET $offsetInt
");
$stmt->execute($allParams);
$accounts = $stmt->fetchAll();

$healthBadge = ['Good' => 'stage-client', 'At Risk' => 'stage-negotiation', 'Critical' => 'stage-lost'];

require_once __DIR__ . '/includes/header.php';

// Presentation helpers: stat tiles (values are filled in by cert-stats.php below)
$accStatTiles = [
    ['',             'statTotal',        'Total Certificates',   'All certificates in CertAdmin', 'shield',    ''],
    ['active',       'statActive',       'Active',               'Currently valid',               'check',     'green'],
    ['surveillance', 'statSurveillance', 'Due for Surveillance', 'Annual check due or overdue',   'clipboard', 'amber'],
    ['expiring_30',  'statExp30',        'Expiring in 30 Days',  'Renewal needed now',            'clock',     'amber'],
    ['expiring_60',  'statExp60',        'Expiring in 60 Days',  'Plan the renewal',              'calendar',  'navy'],
    ['expired',      'statExpired',      'Expired',              'Lapsed certificates',           'alert',     ''],
];
$accPageUrl = function (int $p) use ($search, $certFilter): string {
    return '?page=' . $p . '&search=' . urlencode($search) . '&cert_filter=' . urlencode($certFilter);
};
?>

<style>
.cert-status-cell { min-width:110px; }
.expiry-pill { display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700; }
.expiry-expired  { background:var(--red-50);color:#B42318; }
.expiry-critical { background:#FEF3C7;color:#92400E; }
.expiry-soon     { background:var(--amber-50);color:#B45309; }
.expiry-ok       { background:var(--green-50);color:var(--green-700); }
.cert-detail-card { background:var(--neutral-50);border:1px solid var(--line);border-radius:14px;padding:14px;margin-bottom:10px; }
.cert-detail-card:last-child { margin-bottom:0; }
.cert-number { font-weight:700;font-size:13px;color:var(--ink-900); }
.cert-type   { font-size:13px;color:var(--ink-800);margin-top:3px; }
.cert-spec   { font-size:11.5px;color:var(--ink-500);margin-top:2px; }
.cert-loc    { font-size:11px;color:var(--ink-400);margin-top:3px; }
.cert-dates  { display:flex;gap:16px;margin-top:8px;flex-wrap:wrap; }
.cert-date-block .label { color:var(--ink-400);font-size:10px;text-transform:uppercase;letter-spacing:.5px; }
.cert-date-block .value { color:var(--ink-800);font-weight:600;font-size:12px; }
.cert-status-badge { display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700; }
.cert-active  { background:var(--green-50);color:var(--green-700); }
.cert-expired { background:var(--red-50);color:#B42318; }
.cert-inactive{ background:var(--neutral-100);color:var(--ink-500); }
.survey-tag { display:inline-block;margin-left:6px;font-size:10px;font-weight:800;letter-spacing:.3px;color:#B45309;background:var(--amber-50);padding:2px 8px;border-radius:8px;text-transform:uppercase;white-space:nowrap; }
.survey-tag.overdue { color:#B42318;background:var(--red-50); }
/* Stat tiles (clickable filters) */
a.acc-stat{ display:flex; flex-direction:column; min-height:132px; }
a.acc-stat .tile-value{ font-size:26px; }
a.acc-stat .tile-label{ font-size:10.5px; line-height:1.35; }
a.acc-stat .tile-icon{ width:32px; height:32px; border-radius:10px; }
a.acc-stat .tile-icon .icon{ width:16px; height:16px; }
a.acc-stat.is-active:not(.tile--navy){ border-color:var(--navy-700); box-shadow:0 0 0 3px rgba(31,51,80,0.14); }
a.acc-stat.tile--navy.is-active{ box-shadow:0 0 0 3px rgba(31,51,80,0.22); }
a.acc-stat .acc-stat-sub{ margin-top:auto; padding-top:8px; font-size:11.5px; color:var(--ink-500); }
a.acc-stat.tile--navy .acc-stat-sub{ color:rgba(255,255,255,0.65); }
/* List tile */
.acc-search{ display:flex; gap:8px; flex:1; max-width:460px; min-width:240px; }
.acc-search .search-box{ flex:1; padding:7px 12px; }
.acc-active-filter{ display:inline-flex; align-items:center; gap:8px; background:var(--navy-800); color:#fff; padding:5px 6px 5px 12px; border-radius:999px; font-size:12px; font-weight:600; }
.acc-active-filter a{ display:inline-flex; align-items:center; justify-content:center; width:20px; height:20px; border-radius:50%; background:rgba(255,255,255,0.14); color:#fff; font-size:11px; }
.acc-active-filter a:hover{ background:rgba(255,255,255,0.28); }
.acc-health{ display:flex; gap:5px; align-items:center; }
.acc-health select{ padding:6px 8px; border-radius:9px; border:1px solid var(--neutral-300); background:#fff; font-size:12px; font-family:inherit; color:var(--ink-900); }
.acc-health select:focus{ border-color:var(--red-600); box-shadow:var(--ring); outline:none; }
.acc-cert-actions{ display:flex; flex-wrap:wrap; gap:6px; align-items:center; min-width:250px; max-width:380px; }
.acc-client{ min-width:170px; max-width:260px; overflow-wrap:anywhere; }
.acc-cert-actions .icon{ width:14px; height:14px; }
.pagination { display:flex;gap:6px;align-items:center;justify-content:center;flex-wrap:wrap; width:100%; }
.pagination a,.pagination span.current { padding:7px 13px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none; }
.pagination a { background:var(--tile); border:1px solid var(--line); color:var(--ink-700); }
.pagination a:hover { border-color:var(--neutral-300); background:var(--neutral-50); }
.pagination .current { background:var(--navy-800);color:#fff; }
/* Modals */
.acc-modal{ display:none; position:fixed; inset:0; background:rgba(11,21,36,0.5); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:20px; }
.acc-modal-card{ background:#fff; border-radius:var(--r-tile); padding:24px; width:100%; box-shadow:var(--shadow-pop); border:1px solid var(--line); }
.acc-modal-card h3{ font-size:17px; }
.acc-ai-chip{ display:inline-flex; align-items:center; gap:5px; font-size:10.5px; font-weight:700; color:var(--red-700); background:var(--red-50); padding:3px 9px; border-radius:999px; }
@media (max-width:760px){
  .acc-search{ max-width:none; min-width:0; flex:1 1 100%; margin-left:0 !important; }
  .bento > a.acc-stat{ grid-column:span 6 !important; min-height:118px; }
  a.acc-stat .tile-value{ font-size:22px; }
}
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Client relationships</div>
    <h1><?php echo $role === 'account_manager' ? 'My Accounts' : 'Client Portfolios'; ?></h1>
    <p><?php echo number_format($totalCount); ?> clients<?php echo $certFilterLabel ? ' — filtered by: ' . e($certFilterLabel) : ' — certification data pulled live from CertAdmin'; ?>.</p>
  </div>
</div>

<?php if ($error): ?><div class="alert error"><?php echo icon('alert'); ?><span><?php echo e($error); ?></span></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?php echo icon('check'); ?><span><?php echo e($success); ?></span></div><?php endif; ?>

<div class="bento">
  <!-- Stat tiles — clickable to filter client list -->
  <?php foreach ($accStatTiles as $i => [$key, $id, $label, $sub, $ic, $tone]): ?>
    <a class="tile pad-sm span-2 acc-stat <?php echo $i === 0 ? 'tile--navy' : ''; ?> <?php echo $certFilter === $key ? 'is-active' : ''; ?>"
       href="accounts.php<?php echo $key !== '' ? '?cert_filter=' . e($key) : ''; ?>">
      <div class="tile-head" style="margin-bottom:0; flex-wrap:nowrap; align-items:flex-start;">
        <span class="tile-label"><?php echo e($label); ?></span>
        <div class="tile-icon <?php echo e($tone); ?>"><?php echo icon($ic); ?></div>
      </div>
      <div class="tile-value" id="<?php echo e($id); ?>">—</div>
      <div class="acc-stat-sub"><?php echo $certFilter === $key ? 'Showing now' : e($sub); ?></div>
    </a>
  <?php endforeach; ?>

  <section class="tile span-12 <?php echo empty($accounts) ? '' : 'pad-0'; ?>">
    <div class="tile-head" style="<?php echo empty($accounts) ? '' : 'padding:18px 20px 0;'; ?>">
      <h2 class="tile-title"><?php echo icon('users'); ?> Clients <span class="badge navy"><?php echo number_format($totalCount); ?></span></h2>
      <?php if ($certFilterLabel): ?>
        <span class="acc-active-filter">
          <?php echo e($certFilterLabel); ?>
          <a href="accounts.php" title="Clear filter" aria-label="Clear filter">✕</a>
        </span>
      <?php endif; ?>
      <!-- Search -->
      <form method="GET" class="acc-search" style="margin-left:auto;">
        <?php if ($certFilter): ?><input type="hidden" name="cert_filter" value="<?php echo e($certFilter); ?>"><?php endif; ?>
        <label class="search-box"><?php echo icon('search'); ?><input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Search clients…"></label>
        <button type="submit" class="btn-navy btn-sm">Search</button>
        <?php if ($search): ?><a href="accounts.php<?php echo $certFilter ? '?cert_filter=' . e($certFilter) : ''; ?>" class="btn-secondary btn-sm">Clear</a><?php endif; ?>
      </form>
    </div>

    <?php if (empty($accounts)): ?>
      <div class="empty-state" style="padding:40px;">
        <div class="icon-button"><?php echo icon('users'); ?></div>
        <p><?php echo $certFilterLabel ? 'No clients with ' . e(strtolower($certFilterLabel)) . '.' : ($search ? 'No clients match your search.' : 'No accounts yet.'); ?></p>
      </div>
    <?php else: ?>
    <div class="table-wrap" style="padding:0 8px;">
    <table>
      <thead>
        <tr>
          <th>Client</th>
          <th>Health</th>
          <th>Last Touch</th>
          <th>Certifications</th>
          <?php if ($role !== 'account_manager'): ?><th>Owner</th><?php endif; ?>
          <th>Update Health</th>
          <th>Log Touchpoint</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($accounts as $c): ?>
        <tr>
          <td class="acc-client">
            <div class="row-name"><?php echo e($c['company_name']); ?></div>
            <?php if (!empty($c['next_renewal'])): ?><div class="row-sub">Next renewal <?php echo e(date('j M Y', strtotime($c['next_renewal']))); ?></div><?php endif; ?>
          </td>
          <td><span class="badge <?php echo e($healthBadge[$c['health_status']] ?? ''); ?>"><?php echo e($c['health_status']); ?></span></td>
          <td style="font-size:12px; white-space:nowrap;"><?php echo $c['last_touch'] ? date('j M Y', strtotime($c['last_touch'])) : '<span class="row-sub">Never</span>'; ?></td>
          <td class="cert-status-cell">
            <div class="acc-cert-actions">
              <button type="button" class="btn-secondary btn-sm cert-view-btn"
                data-company="<?php echo e($c['company_name']); ?>"
                data-client-id="<?php echo (int) $c['id']; ?>"
                data-email="<?php echo e($c['primary_contact_email'] ?? ''); ?>">
                <?php echo icon('file'); ?> View Certs
              </button>
              <?php if (in_array($certFilter, ['expiring_30','expiring_60','expired','surveillance'], true)): ?>
              <button type="button" class="btn-primary btn-sm cert-remind-btn"
                data-client-id="<?php echo (int) $c['id']; ?>"
                data-company="<?php echo e($c['company_name']); ?>"
                data-email="<?php echo e($c['primary_contact_email'] ?? ''); ?>"
                data-filter="<?php echo e($certFilter); ?>">
                <?php echo icon('send'); ?> Send Reminder
              </button>
              <?php endif; ?>
              <?php if ($certFilter === 'surveillance' && $canAssignTask): ?>
              <a href="surveillance-tasks.php?assign_client=<?php echo (int) $c['id']; ?>" class="btn-navy btn-sm">
                <?php echo icon('clipboard'); ?> Assign Task
              </a>
              <?php endif; ?>
            </div>
          </td>
          <?php if ($role !== 'account_manager'): ?><td style="font-size:12px; white-space:nowrap;"><?php echo e($c['crm_name'] ?? '—'); ?></td><?php endif; ?>
          <td>
            <form method="POST" class="acc-health">
              <input type="hidden" name="action" value="update_health">
              <input type="hidden" name="client_id" value="<?php echo (int) $c['id']; ?>">
              <select name="health_status" aria-label="Health status">
                <?php foreach (['Good', 'At Risk', 'Critical'] as $h): ?>
                  <option value="<?php echo e($h); ?>" <?php echo $h === $c['health_status'] ? 'selected' : ''; ?>><?php echo e($h); ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn-secondary btn-sm" title="Save health"><?php echo icon('check'); ?></button>
            </form>
          </td>
          <td>
            <button type="button" class="btn-secondary btn-sm" title="Log a touchpoint"
              onclick="openTouchpoint(<?php echo (int) $c['id']; ?>, '<?php echo e(addslashes($c['company_name'])); ?>')">
              <?php echo icon('clock'); ?> Log
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="tile-foot" style="padding:14px 20px 18px; border-top:1px solid var(--line-soft);">
      <div class="pagination">
        <?php if ($page > 1): ?><a href="<?php echo e($accPageUrl($page - 1)); ?>">← Prev</a><?php endif; ?>
        <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
          <?php if ($p === $page): ?>
            <span class="current"><?php echo $p; ?></span>
          <?php else: ?>
            <a href="<?php echo e($accPageUrl($p)); ?>"><?php echo $p; ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?><a href="<?php echo e($accPageUrl($page + 1)); ?>">Next →</a><?php endif; ?>
        <span style="color:var(--ink-500);font-size:12px;">Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo number_format($totalCount); ?> clients)</span>
      </div>
    </div>
    <?php else: ?>
    <div style="height:8px;"></div>
    <?php endif; ?>
    <?php endif; ?>
  </section>
</div>

<!-- Reminder Modal -->
<div id="reminderModal" class="acc-modal" style="display:none; z-index:101;">
  <div class="acc-modal-card" style="max-width:640px;max-height:90vh;overflow-y:auto;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
      <h3 id="reminderModalTitle">Send Renewal Reminder</h3>
      <button type="button" onclick="closeReminderModal()" class="btn-ghost btn-sm" aria-label="Close">✕</button>
    </div>
    <p id="reminderCompany" style="font-size:12.5px;color:var(--ink-500);margin-bottom:16px;"></p>

    <!-- Loading state -->
    <div id="reminderLoading" style="text-align:center;padding:30px;color:var(--ink-500);">
      <div style="font-size:13px;margin-bottom:8px;">⏳ Fetching certificate data and drafting reminder with AI…</div>
      <div style="font-size:11.5px;color:var(--ink-400);">This takes a few seconds</div>
    </div>

    <!-- Draft form (hidden until loaded) -->
    <div id="reminderForm" style="display:none;">
      <div class="acc-ai-chip" style="margin-bottom:14px;">
        ⚡ AI-drafted — review and edit before sending
      </div>

      <!-- Cert summary strip -->
      <div id="reminderCertStrip" style="background:var(--neutral-50);border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin-bottom:14px;font-size:12.5px;"></div>

      <div class="form-field" style="margin-bottom:12px;">
        <label>To</label>
        <input type="email" id="reminderTo" placeholder="client@example.com">
      </div>
      <div class="form-field" style="margin-bottom:12px;">
        <label>Subject</label>
        <input type="text" id="reminderSubject">
      </div>
      <div class="form-field" style="margin-bottom:14px;">
        <label>Message</label>
        <textarea id="reminderBody" style="min-height:220px;line-height:1.6;"></textarea>
      </div>

      <div class="form-field" style="margin-bottom:14px;">
        <label>Tone / instructions for the redraft (optional)</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <input type="text" id="reminderTone" style="flex:1;min-width:200px;" placeholder="e.g. this is the 3rd reminder — make it firmer and more urgent">
          <button type="button" class="btn-secondary" id="reminderRegenerateBtn" onclick="regenerateReminder()">⚡ Regenerate</button>
        </div>
        <div style="font-size:11px;color:var(--ink-500);margin-top:4px;">Leave blank and click Regenerate for a plain redraft, or describe the tone you want — e.g. "warm", "firm — second follow-up", "short and direct".</div>
      </div>

      <div id="reminderStatus" style="display:none;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:12px;"></div>

      <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
        <button type="button" class="btn-secondary" onclick="closeReminderModal()">Cancel</button>
        <button type="button" class="btn-primary" id="reminderSendBtn" onclick="sendReminder()">
          <?php echo icon('send'); ?> Send Reminder
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Cert Modal -->
<div id="certModal" class="acc-modal" style="display:none; z-index:100;">
  <div class="acc-modal-card" style="max-width:600px;max-height:85vh;overflow-y:auto;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
      <h3>Certificates</h3>
      <button type="button" onclick="document.getElementById('certModal').style.display='none'" class="btn-ghost btn-sm" aria-label="Close">✕</button>
    </div>
    <p id="certModalCompany" style="font-size:12.5px;color:var(--ink-500);margin-bottom:16px;"></p>
    <div id="certModalBody"></div>
    <div class="form-actions">
      <button type="button" class="btn-primary" id="certModalSurveyBtn" style="display:none;" onclick="sendSurveillanceReminderFromModal()">
        <?php echo icon('send'); ?> Send Surveillance Reminder
      </button>
      <?php if ($canAssignTask): ?>
      <a id="certModalAssignBtn" href="#" style="display:none;" class="btn-secondary">
        <?php echo icon('clipboard'); ?> Assign Task
      </a>
      <?php endif; ?>
      <button type="button" class="btn-secondary" onclick="document.getElementById('certModal').style.display='none'">Close</button>
    </div>
  </div>
</div>

<!-- Touchpoint Modal -->
<div id="touchpointModal" class="acc-modal" style="display:none; z-index:100;">
  <div class="acc-modal-card" style="max-width:440px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px;">
      <h3 style="font-size:16px;">Log Touchpoint — <span id="tpCompany"></span></h3>
      <button type="button" onclick="document.getElementById('touchpointModal').style.display='none'" class="btn-ghost btn-sm" aria-label="Close">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="log_touchpoint">
      <input type="hidden" name="client_id" id="tpClientId">
      <div class="form-field" style="margin-bottom:12px;"><label>Type</label>
        <select name="type"><option>Call</option><option>Email</option><option>Meeting</option><option>Site Visit</option></select>
      </div>
      <div class="form-field"><label>Notes</label><textarea name="notes" style="min-height:80px;"></textarea></div>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="document.getElementById('touchpointModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Log</button>
      </div>
    </form>
  </div>
</div>

<script>
// ---- Reminder modal -------------------------------------------------------
// Escape CertAdmin / API values before they go into innerHTML.
function escHtml(v) {
  return String(v == null ? '' : v).replace(/[&<>"']/g, function(ch){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]; });
}

var currentReminder = { clientId: 0, company: '', filter: '' };

function closeReminderModal() {
  document.getElementById('reminderModal').style.display = 'none';
}

function openReminderModal(clientId, company, email, filter) {
  currentReminder = { clientId: clientId, company: company, filter: filter };
  document.getElementById('reminderModalTitle').textContent = filter === 'surveillance' ? 'Send Surveillance Reminder' : 'Send Renewal Reminder';
  document.getElementById('reminderCompany').textContent = company;
  document.getElementById('reminderLoading').style.display = 'block';
  document.getElementById('reminderForm').style.display = 'none';
  document.getElementById('reminderStatus').style.display = 'none';
  document.getElementById('reminderTo').value = email || '';
  document.getElementById('reminderTone').value = '';
  document.getElementById('reminderModal').style.display = 'flex';
  fetchDraft(company, email, filter);
}

function fetchDraft(company, email, filter, toneInstruction) {
  document.getElementById('reminderLoading').style.display = 'block';
  document.getElementById('reminderForm').style.display = 'none';
  document.querySelector('#reminderLoading > div').textContent = toneInstruction
    ? '⏳ Redrafting with your instructions…'
    : '⏳ Fetching certificate data and drafting reminder with AI…';

  var url = 'cert-reminder-draft.php?company=' + encodeURIComponent(company) + '&filter=' + encodeURIComponent(filter);
  if (toneInstruction) {
    url += '&tone_instruction=' + encodeURIComponent(toneInstruction);
  }

  fetch(url)
    .then(function(r){ return r.json(); })
    .then(function(data) {
      document.getElementById('reminderLoading').style.display = 'none';
      if (!data.ok) {
        document.getElementById('reminderForm').style.display = 'block';
        showReminderStatus('error', 'Could not draft reminder: ' + (data.error || 'Unknown error'));
        return;
      }

      // Populate cert strip
      var strip = document.getElementById('reminderCertStrip');
      strip.innerHTML = '<b style="font-size:11px;color:var(--ink-500);text-transform:uppercase;letter-spacing:.5px;">Certificates requiring attention:</b>';
      if (data.certs && data.certs.length > 0) {
        data.certs.forEach(function(c) {
          var badge;
          if (c.survey) {
            badge = c.survey.state === 'overdue'
              ? '<span style="background:#FEE2E2;color:#991B1B;padding:2px 7px;border-radius:10px;font-size:10.5px;font-weight:700;">Survey ' + escHtml(c.survey.year) + ' Overdue</span>'
              : '<span style="background:#FFF7ED;color:#C05621;padding:2px 7px;border-radius:10px;font-size:10.5px;font-weight:700;">Survey ' + escHtml(c.survey.year) + ' Due</span>';
          } else {
            var daysLeft = c.expire_date ? Math.floor((new Date(c.expire_date) - new Date()) / 86400000) : null;
            badge = daysLeft !== null && daysLeft < 0
              ? '<span style="background:#FEE2E2;color:#991B1B;padding:2px 7px;border-radius:10px;font-size:10.5px;font-weight:700;">Expired</span>'
              : '<span style="background:#FFF7ED;color:#C05621;padding:2px 7px;border-radius:10px;font-size:10.5px;font-weight:700;">Expires ' + escHtml(c.expire_date) + '</span>';
          }
          strip.innerHTML += '<div style="margin-top:6px;">' + badge + ' <b>' + escHtml(c.certificate_number||'') + '</b> — ' + escHtml(c.certification_type||'') + '</div>';
        });
      }

      document.getElementById('reminderSubject').value = data.subject || '';
      document.getElementById('reminderBody').value    = data.body    || '';
      if (!document.getElementById('reminderTo').value && email) {
        document.getElementById('reminderTo').value = email;
      }

      if (data.ai) {
        showReminderStatus('info', '⚡ Draft generated by AI — review carefully before sending.');
      }

      document.getElementById('reminderForm').style.display = 'block';
    })
    .catch(function() {
      document.getElementById('reminderLoading').style.display = 'none';
      document.getElementById('reminderForm').style.display = 'block';
      showReminderStatus('error', 'Could not reach the server. Please try again.');
    });
}

function regenerateReminder() {
  var toneInstruction = document.getElementById('reminderTone').value.trim();
  fetchDraft(currentReminder.company, document.getElementById('reminderTo').value, currentReminder.filter, toneInstruction);
}

function sendReminder() {
  var toEmail  = document.getElementById('reminderTo').value.trim();
  var subject  = document.getElementById('reminderSubject').value.trim();
  var body     = document.getElementById('reminderBody').value.trim();

  if (!toEmail) { showReminderStatus('error', 'Please enter the recipient email address.'); return; }
  if (!subject) { showReminderStatus('error', 'Please enter a subject.'); return; }
  if (!body)    { showReminderStatus('error', 'Message body cannot be empty.'); return; }

  var sendBtn = document.getElementById('reminderSendBtn');
  sendBtn.disabled = true;
  sendBtn.textContent = 'Sending…';

  fetch('cert-reminder-send.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      client_id: currentReminder.clientId,
      company:   currentReminder.company,
      to_email:  toEmail,
      subject:   subject,
      body:      body,
    })
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    sendBtn.disabled = false;
    sendBtn.textContent = 'Send Reminder';
    if (data.ok) {
      showReminderStatus('success', '✅ ' + data.message);
      setTimeout(function(){ closeReminderModal(); }, 2500);
    } else {
      showReminderStatus('error', '❌ ' + (data.error || 'Send failed.'));
    }
  })
  .catch(function() {
    sendBtn.disabled = false;
    sendBtn.textContent = 'Send Reminder';
    showReminderStatus('error', 'Network error — please try again.');
  });
}

function showReminderStatus(type, msg) {
  var el = document.getElementById('reminderStatus');
  el.style.display = 'block';
  el.style.background = type === 'success' ? 'var(--green-50)' : type === 'error' ? 'var(--red-50)' : 'var(--blue-50)';
  el.style.color      = type === 'success' ? 'var(--green-700)' : type === 'error' ? '#B42318' : '#1E40AF';
  el.textContent = msg;
}

// Attach remind button listeners
document.querySelectorAll('.cert-remind-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    openReminderModal(
      parseInt(btn.dataset.clientId),
      btn.dataset.company,
      btn.dataset.email,
      btn.dataset.filter
    );
  });
});

// ---- Cert view modal ------------------------------------------------------
// Load stat cards
fetch('cert-stats.php')
  .then(function(r){ return r.json(); })
  .then(function(data){
    if (data.ok && data.stats) {
      var s = data.stats;
      document.getElementById('statTotal').textContent   = Number(s.total||0).toLocaleString();
      document.getElementById('statActive').textContent  = Number(s.active||0).toLocaleString();
      document.getElementById('statExp30').textContent   = Number(s.expiring_30||0).toLocaleString();
      document.getElementById('statExp60').textContent   = Number(s.expiring_60||0).toLocaleString();
      document.getElementById('statExpired').textContent = Number(s.expired||0).toLocaleString();
      document.getElementById('statSurveillance').textContent = Number(s.surveillance||0).toLocaleString();
    }
  })
  .catch(function(){});

// Mirrors certadmin_surveillance_status() in config.php (which itself
// mirrors CertAdmin's own getSurveillanceStatus()) — computed client-side
// here since renderCerts() works entirely from the JSON already fetched.
var SURVEY_GRACE_DAYS = 14;
function getSurveillanceInfo(issueDate, status) {
  if (!issueDate || (status || '').toUpperCase() !== 'ACTIVE') return null;
  var issue = new Date(issueDate);
  if (isNaN(issue.getTime())) return null;
  var today = new Date(); today.setHours(0,0,0,0);
  var expire = new Date(issue); expire.setFullYear(expire.getFullYear() + 3);
  if (today >= expire) return null;

  var anniversaries = [1, 2].map(function(y) {
    var d = new Date(issue); d.setFullYear(d.getFullYear() + y);
    return { year: y, ts: d };
  });
  var result = null;
  for (var i = 0; i < anniversaries.length; i++) {
    var a = anniversaries[i];
    var daysSince = Math.floor((today - a.ts) / 86400000);
    if (daysSince < 0) break;
    var annivLabel = a.ts.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
    if (daysSince <= SURVEY_GRACE_DAYS) {
      result = { state: 'due', year: a.year, anniversary: annivLabel, days: daysSince };
    } else {
      result = { state: 'overdue', year: a.year, anniversary: annivLabel, days: daysSince - SURVEY_GRACE_DAYS };
    }
  }
  return result;
}

function getExpiryInfo(expireDate) {
  if (!expireDate) return { label: 'No expiry date', cls: 'expiry-ok' };
  var exp  = new Date(expireDate);
  var now  = new Date();
  var days = Math.floor((exp - now) / 86400000);
  if (days < 0)   return { label: 'Expired ' + Math.abs(days) + ' days ago', cls: 'expiry-expired' };
  if (days <= 30) return { label: '⚠ Expires in ' + days + ' days', cls: 'expiry-critical' };
  if (days <= 60) return { label: '⚠ Expires in ' + days + ' days', cls: 'expiry-soon' };
  return { label: 'Valid — ' + days + ' days remaining', cls: 'expiry-ok' };
}

function renderCerts(certs, company) {
  var body = document.getElementById('certModalBody');
  document.getElementById('certModalCompany').textContent = company;
  body.innerHTML = '';

  var surveyBtn = document.getElementById('certModalSurveyBtn');
  var assignBtn = document.getElementById('certModalAssignBtn');

  if (!certs || certs.length === 0) {
    body.innerHTML = '<div style="text-align:center;padding:20px;color:var(--ink-500);">No certificates found in CertAdmin for this company.</div>';
    surveyBtn.style.display = 'none';
    if (assignBtn) assignBtn.style.display = 'none';
    return;
  }

  var anySurveyDue = certs.some(function(c) { return !!getSurveillanceInfo(c.issue_date, c.status); });
  surveyBtn.style.display = anySurveyDue ? 'inline-flex' : 'none';
  if (assignBtn) {
    if (anySurveyDue && currentCertModalClient.id) {
      assignBtn.style.display = 'inline-flex';
      assignBtn.href = 'surveillance-tasks.php?assign_client=' + encodeURIComponent(currentCertModalClient.id);
    } else {
      assignBtn.style.display = 'none';
    }
  }

  var active   = certs.filter(function(c){ return (c.status||'').toUpperCase()==='ACTIVE'; }).length;
  var expiring = certs.filter(function(c){
    if (!c.expire_date) return false;
    var d = Math.floor((new Date(c.expire_date)-new Date())/86400000);
    return d>=0 && d<=60;
  }).length;
  var expired  = certs.filter(function(c){
    return c.expire_date && new Date(c.expire_date)<new Date();
  }).length;

  var summary = '<div class="stat-row" style="margin-bottom:16px;">' +
    '<div class="stat" style="background:var(--green-50);border-color:var(--green-200);"><div class="v" style="color:var(--green-700);">' + active + '</div><div class="l">Active</div></div>' +
    (expiring>0 ? '<div class="stat" style="background:var(--amber-50);"><div class="v" style="color:#B45309;">' + expiring + '</div><div class="l">Expiring Soon</div></div>' : '') +
    (expired>0  ? '<div class="stat" style="background:var(--red-50);"><div class="v" style="color:#B42318;">' + expired + '</div><div class="l">Expired</div></div>' : '') +
    '<div class="stat"><div class="v">' + certs.length + '</div><div class="l">Total</div></div>' +
    '</div>';
  body.innerHTML = summary;

  certs.sort(function(a,b){
    var da = a.expire_date ? Math.floor((new Date(a.expire_date)-new Date())/86400000) : 9999;
    var db = b.expire_date ? Math.floor((new Date(b.expire_date)-new Date())/86400000) : 9999;
    return da-db;
  });

  certs.forEach(function(c) {
    var expInfo   = getExpiryInfo(c.expire_date);
    var surveyInfo = getSurveillanceInfo(c.issue_date, c.status);
    var statusCls = (c.status||'').toUpperCase()==='ACTIVE' ? 'cert-active' : 'cert-expired';
    var surveyHtml = '';
    if (surveyInfo) {
      var dayWord = surveyInfo.days === 1 ? 'day' : 'days';
      var surveyNote = 'Year ' + surveyInfo.year + ' check (anniversary ' + surveyInfo.anniversary + ') — '
        + (surveyInfo.state === 'overdue' ? surveyInfo.days + ' ' + dayWord + ' past grace' : surveyInfo.days + ' ' + dayWord + ' in');
      surveyHtml = '<span class="survey-tag' + (surveyInfo.state === 'overdue' ? ' overdue' : '') + '" title="' + escHtml(surveyNote) + '">'
        + 'Survey ' + surveyInfo.year + ' ' + (surveyInfo.state === 'overdue' ? 'Overdue' : 'Due') + '</span>';
    }
    var card = document.createElement('div');
    card.className = 'cert-detail-card';
    card.innerHTML =
      '<div style="display:flex;justify-content:space-between;align-items:start;gap:10px;">' +
        '<div style="flex:1;min-width:0;">' +
          '<div class="cert-number">' + escHtml(c.certificate_number||'—') + '</div>' +
          '<div class="cert-type">' + escHtml(c.certification_type||'') + '</div>' +
          (c.specialization ? '<div class="cert-spec">' + escHtml(c.specialization) + '</div>' : '') +
          (c.location ? '<div class="cert-loc">📍 ' + escHtml(c.location) + '</div>' : '') +
          '<div class="cert-dates">' +
            '<div class="cert-date-block"><div class="label">Issue Date</div><div class="value">' + escHtml(c.issue_date||'—') + '</div></div>' +
            '<div class="cert-date-block"><div class="label">Expiry Date</div><div class="value">' + escHtml(c.expire_date||'—') + '</div></div>' +
          '</div>' +
          '<div style="margin-top:8px;"><span class="expiry-pill ' + expInfo.cls + '">' + expInfo.label + '</span>' + surveyHtml + '</div>' +
        '</div>' +
        '<span class="cert-status-badge ' + statusCls + '">' + escHtml(c.status||'—') + '</span>' +
      '</div>';
    body.appendChild(card);
  });
}

var currentCertModalClient = { id: 0, company: '', email: '' };

document.querySelectorAll('.cert-view-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var company = btn.dataset.company;
    currentCertModalClient = {
      id: parseInt(btn.dataset.clientId) || 0,
      company: company,
      email: btn.dataset.email || ''
    };
    document.getElementById('certModalCompany').textContent = company;
    document.getElementById('certModalBody').innerHTML = '<div style="text-align:center;padding:30px;color:var(--ink-500);">⏳ Loading certificates…</div>';
    document.getElementById('certModalSurveyBtn').style.display = 'none';
    var assignBtnReset = document.getElementById('certModalAssignBtn');
    if (assignBtnReset) assignBtnReset.style.display = 'none';
    document.getElementById('certModal').style.display = 'flex';

    fetch('cert-lookup.php?company=' + encodeURIComponent(company))
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data.ok) {
          renderCerts(data.certificates, company);
        } else {
          document.getElementById('certModalBody').innerHTML = '<div style="color:#B42318;padding:20px;">Error: ' + escHtml(data.error||'Unknown') + '</div>';
        }
      })
      .catch(function(){
        document.getElementById('certModalBody').innerHTML = '<div style="color:#B42318;padding:20px;">Could not reach CertAdmin.</div>';
      });
  });
});

function sendSurveillanceReminderFromModal() {
  document.getElementById('certModal').style.display = 'none';
  openReminderModal(currentCertModalClient.id, currentCertModalClient.company, currentCertModalClient.email, 'surveillance');
}

function openTouchpoint(clientId, companyName) {
  document.getElementById('tpClientId').value = clientId;
  document.getElementById('tpCompany').textContent = companyName;
  document.getElementById('touchpointModal').style.display = 'flex';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>