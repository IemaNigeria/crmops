<?php
/**
 * IEMA CRMOps — Surveillance Calendar
 * -----------------------------------------------------------------------
 * For every 3-year certificate, surveillance audits are due on the exact
 * anniversary of the issue date:
 *   - Surveillance 1 = issue_date + 1 year
 *   - Surveillance 2 = issue_date + 2 years
 *   - Recertification = expire_date (issue_date + 3 years)
 *
 * Shows a 12-month strip — click any month to see all dues for that month.
 * Defaults to the current month.
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

// ---- Fetch all certificates from CertAdmin ----------------------------
$url = CERTADMIN_API_URL . '?action=all_certs&api_key=' . urlencode(CERTADMIN_API_KEY);
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . CERTADMIN_API_KEY],
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$allCerts = [];
$apiError = '';
if ($response === false || $httpCode !== 200) {
    $apiError = "Could not reach CertAdmin (HTTP $httpCode). Please try again.";
} else {
    $data = json_decode($response, true);
    if (!empty($data['ok']) && isset($data['certs'])) {
        $allCerts = $data['certs'];
    } else {
        $apiError = 'CertAdmin returned no data.';
    }
}

// ---- If account_manager, restrict to their assigned clients -------------
$assignedNames = [];
if ($role === 'account_manager') {
    $stmt = $pdo->prepare('SELECT LOWER(TRIM(company_name)) FROM clients WHERE assigned_crm_id = ?');
    $stmt->execute([$uid]);
    $assignedNames = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    // Filter certs to only assigned clients
    $allCerts = array_filter($allCerts, function ($c) use ($assignedNames) {
        return isset($assignedNames[strtolower(trim($c['company_name']))]);
    });
}

// ---- Calculate all due dates (surveillance + recertification) -----------
// dues[$year][$month][] = [ company, cert, type, date, daysFromNow ]
$dues = [];
$today = new DateTimeImmutable('today');

foreach ($allCerts as $cert) {
    if (empty($cert['issue_date']) || $cert['issue_date'] === '0000-00-00') continue;

    $issueDate  = new DateTimeImmutable($cert['issue_date']);
    $expireDate = !empty($cert['expire_date']) && $cert['expire_date'] !== '0000-00-00'
        ? new DateTimeImmutable($cert['expire_date'])
        : null;

    // Calculate surveillance due dates based on issue_date anniversary
    $auditTypes = [
        'Surveillance 1'   => $issueDate->modify('+1 year'),
        'Surveillance 2'   => $issueDate->modify('+2 years'),
        'Recertification'  => $expireDate ?? $issueDate->modify('+3 years'),
    ];

    foreach ($auditTypes as $type => $dueDate) {
        if (!$dueDate) continue;
        // Only include dates within ±18 months of today for performance
        $diffDays = (int) $today->diff($dueDate)->days * ($dueDate >= $today ? 1 : -1);
        if ($diffDays < -30 || $diffDays > 548) continue; // past 30 days or beyond 18 months

        $year  = (int) $dueDate->format('Y');
        $month = (int) $dueDate->format('n');

        $dues[$year][$month][] = [
            'company'         => $cert['company_name'],
            'cert_number'     => $cert['certificate_number'] ?? '',
            'cert_type'       => $cert['certification_type'] ?? '',
            'specialization'  => $cert['specialization']     ?? '',
            'audit_type'      => $type,
            'due_date'        => $dueDate->format('Y-m-d'),
            'due_day'         => (int) $dueDate->format('j'),
            'days_from_now'   => $diffDays,
            'status'          => $cert['status'] ?? '',
        ];
    }
}

// Sort each month's entries by day
foreach ($dues as $y => &$months) {
    foreach ($months as $m => &$entries) {
        usort($entries, fn($a, $b) => $a['due_day'] - $b['due_day']);
    }
}
unset($months, $entries);

// ---- Selected month (default = current month) ---------------------------
$selYear  = (int) ($_GET['year']  ?? date('Y'));
$selMonth = (int) ($_GET['month'] ?? date('n'));

// Build 12-month strip starting from 2 months ago
$stripMonths = [];
$stripStart  = $today->modify('-2 months')->modify('first day of this month');
for ($i = 0; $i < 15; $i++) {
    $dt = $stripStart->modify("+$i months");
    $y  = (int) $dt->format('Y');
    $m  = (int) $dt->format('n');
    $stripMonths[] = [
        'year'    => $y,
        'month'   => $m,
        'label'   => $dt->format('M'),
        'year_label' => $dt->format('Y'),
        'count'   => count($dues[$y][$m] ?? []),
        'is_current' => ($y === (int) date('Y') && $m === (int) date('n')),
        'is_selected' => ($y === $selYear && $m === $selMonth),
        'has_overdue' => !empty(array_filter($dues[$y][$m] ?? [], fn($e) => $e['days_from_now'] < 0)),
        'has_urgent'  => !empty(array_filter($dues[$y][$m] ?? [], fn($e) => $e['days_from_now'] >= 0 && $e['days_from_now'] <= 30)),
    ];
}

$selectedEntries = $dues[$selYear][$selMonth] ?? [];
$monthLabel      = date('F Y', mktime(0, 0, 0, $selMonth, 1, $selYear));
$totalDues       = count($selectedEntries);

// Urgency colour helper
function due_urgency(int $daysFromNow): array {
    if ($daysFromNow < 0)  return ['cls' => 'expiry-expired',  'label' => 'Overdue by ' . abs($daysFromNow) . ' days'];
    if ($daysFromNow === 0) return ['cls' => 'expiry-critical', 'label' => 'Due today'];
    if ($daysFromNow <= 30) return ['cls' => 'expiry-critical', 'label' => 'Due in ' . $daysFromNow . ' days'];
    if ($daysFromNow <= 60) return ['cls' => 'expiry-soon',     'label' => 'Due in ' . $daysFromNow . ' days'];
    return ['cls' => 'expiry-ok', 'label' => 'Due in ' . $daysFromNow . ' days'];
}

// Get client email for the remind button
function get_client_email(PDO $pdo, string $companyName): string {
    $stmt = $pdo->prepare('SELECT primary_contact_email FROM clients WHERE LOWER(TRIM(company_name)) = LOWER(TRIM(?)) LIMIT 1');
    $stmt->execute([$companyName]);
    return $stmt->fetchColumn() ?: '';
}

function get_client_id(PDO $pdo, string $companyName): int {
    $stmt = $pdo->prepare('SELECT id FROM clients WHERE LOWER(TRIM(company_name)) = LOWER(TRIM(?)) LIMIT 1');
    $stmt->execute([$companyName]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Month strip — navy selection, red only as the overdue accent */
.month-strip { display:grid; grid-template-columns:repeat(15, minmax(62px,1fr)); gap:6px; overflow-x:auto; padding-bottom:2px; }
.month-btn {
    min-width:62px; padding:10px 6px 9px; border-radius:12px; border:1px solid var(--line-soft);
    background:var(--neutral-50); cursor:pointer; text-align:center; font-family:inherit; position:relative;
    transition:background .15s ease, border-color .15s ease;
}
.month-btn:hover { border-color:var(--neutral-300); background:#fff; }
.month-btn .mo-name { font-size:12.5px; font-weight:700; color:var(--ink-800); }
.month-btn .mo-year { font-size:10px; color:var(--ink-400); margin-top:1px; }
.month-btn .mo-count { font-size:11px; font-weight:700; margin-top:6px; color:var(--ink-500); }
.month-btn.is-current { border-color:var(--navy-600); }
.month-btn.is-selected { background:var(--navy-800); border-color:var(--navy-800); }
.month-btn.is-selected .mo-name { color:#fff; }
.month-btn.is-selected .mo-year, .month-btn.is-selected .mo-count { color:rgba(255,255,255,0.7); }
.month-btn.has-overdue::after, .month-btn.has-urgent::after {
    content:''; position:absolute; top:7px; right:7px; width:6px; height:6px; border-radius:50%; background:var(--red-600);
}
.month-btn.has-urgent::after { background:var(--amber); }
.month-btn.has-overdue .mo-count { color:var(--red-700); }
.month-btn.has-urgent .mo-count { color:var(--amber); }
.month-btn.is-selected.has-overdue .mo-count, .month-btn.is-selected.has-urgent .mo-count { color:#fff; }
.strip-legend { display:flex; gap:14px; flex-wrap:wrap; font-size:11.5px; color:var(--ink-500); }
.strip-legend i { display:inline-block; width:7px; height:7px; border-radius:50%; margin-right:5px; vertical-align:1px; }

/* Audit type badges */
.audit-s1, .audit-s2, .audit-re { display:inline-flex; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.audit-s1 { background:#EEF2F8; color:var(--navy-700); }
.audit-s2 { background:#F1EEFB; color:#5B3FA8; }
.audit-re { background:var(--red-50); color:var(--red-700); }

/* Due-date pills */
.expiry-pill { display:inline-flex; align-items:center; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; white-space:nowrap; }
.expiry-expired  { background:var(--red-50); color:var(--red-700); }
.expiry-critical { background:var(--amber-50); color:#93690C; }
.expiry-soon     { background:#FFF4E5; color:#B45309; }
.expiry-ok       { background:var(--green-50); color:var(--green-700); }

.surv-table td { vertical-align:top; }
.surv-company { font-weight:700; font-size:13px; color:var(--ink-900); }
.surv-certno  { font-size:12.5px; font-weight:600; color:var(--ink-800); }
.surv-cert    { font-size:11.5px; color:var(--ink-500); margin-top:2px; }
.surv-type    { font-size:11px; color:var(--ink-400); margin-top:1px; max-width:280px; }
.surv-due     { font-weight:700; font-size:13px; color:var(--ink-900); white-space:nowrap; }
.surv-actions { display:flex; gap:6px; flex-wrap:nowrap; }
.surv-actions .icon { width:14px; height:14px; }
.surv-table-head { padding:18px 20px 14px; margin:0; border-bottom:1px solid var(--line); }
.surv-table-head .tile-sub { margin-top:2px; }
.surv-table th:first-child, .surv-table th:last-child { border-radius:0; }
.surv-kpi { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
a.tile.surv-kpi-link { color:inherit; }

/* Reminder modal */
.surv-modal { display:none; position:fixed; inset:0; background:rgba(11,21,36,0.5); backdrop-filter:blur(4px); z-index:100; align-items:center; justify-content:center; padding:20px; }
.surv-modal-box { background:#fff; border:1px solid var(--line); border-radius:var(--r-tile); padding:24px; max-width:640px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:var(--shadow-pop); }
.surv-modal-box .ai-chip { display:inline-flex; align-items:center; gap:5px; font-size:10.5px; font-weight:700; color:var(--red-700); background:var(--red-50); padding:3px 9px; border-radius:999px; margin-bottom:14px; }
.surv-modal-box .audit-strip { background:var(--neutral-50); border:1px solid var(--line); border-radius:12px; padding:12px 14px; margin-bottom:14px; font-size:12.5px; }
.surv-modal-box .audit-strip .strip-label { font-size:11px; color:var(--ink-500); text-transform:uppercase; letter-spacing:.5px; font-weight:700; }
.surv-modal-box .audit-strip .strip-due { margin-top:4px; color:var(--ink-500); font-size:11.5px; }
.surv-modal-box .form-field { margin-bottom:12px; }
.surv-modal-box .form-field textarea { min-height:220px; line-height:1.6; }
@media (max-width:760px){
  .surv-table-head { padding:16px; }
  .bento > .surv-half { grid-column:span 6 !important; }
  .surv-half.tile { padding:14px; }
  .surv-half .tile-value { font-size:24px; }
  .surv-half .tile-icon { width:30px; height:30px; border-radius:9px; }
  .surv-half .tile-sub { font-size:11.5px; }
  /* Table rows become stacked cards on phones */
  .table-wrap table.surv-table { min-width:0; }
  .surv-table thead { display:none; }
  .surv-table, .surv-table tbody { display:block; }
  .surv-table tr { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:8px 12px; padding:14px 16px; border-bottom:1px solid var(--line-soft); }
  .surv-table tr:last-child { border-bottom:none; }
  .surv-table td { display:block; padding:0; border:none; }
  .surv-table td:nth-child(1) { grid-column:1; grid-row:1; }
  .surv-table td:nth-child(5) { grid-column:2; grid-row:1; }
  .surv-table td:nth-child(2) { grid-column:1 / -1; }
  .surv-table td:nth-child(3) { grid-column:1; align-self:center; }
  .surv-table td:nth-child(4) { grid-column:2; text-align:right; }
  .surv-table td:nth-child(6) { grid-column:1 / -1; }
  .surv-type { max-width:none; }
}
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Certification lifecycle</div>
    <h1>Surveillance Calendar</h1>
    <p>Surveillance and recertification audit schedule — calculated from CertAdmin issue dates.</p>
  </div>
  <?php if (role_is(['account_manager', 'management'])): ?>
  <div class="page-actions">
    <a href="surveillance-tasks.php" class="btn-secondary"><?php echo icon('clipboard'); ?> Surveillance Tasks</a>
  </div>
  <?php endif; ?>
</div>

<?php if ($apiError): ?>
  <div class="alert error"><?php echo icon('alert'); ?><span><?php echo e($apiError); ?></span></div>
<?php else: ?>

<!-- Summary stat cards -->
<?php
$overdueCount  = 0;
$urgent30Count = 0;
$urgent60Count = 0;
foreach ($dues as $y => $months) {
    foreach ($months as $m => $entries) {
        foreach ($entries as $e) {
            if ($e['days_from_now'] < 0)  $overdueCount++;
            elseif ($e['days_from_now'] <= 30) $urgent30Count++;
            elseif ($e['days_from_now'] <= 60) $urgent60Count++;
        }
    }
}
$selRecert = count(array_filter($selectedEntries, fn($x) => $x['audit_type'] === 'Recertification'));
?>
<div class="bento">
  <a class="tile tile--navy span-3 surv-half surv-kpi-link" href="surveillance.php?year=<?php echo date('Y'); ?>&month=<?php echo date('n'); ?>">
    <div class="surv-kpi">
      <div class="tile-label">Overdue</div>
      <div class="tile-icon"><?php echo icon('alert'); ?></div>
    </div>
    <div class="tile-value"><?php echo $overdueCount; ?></div>
    <div class="tile-sub">Audits past their due date (last 30 days)</div>
  </a>
  <section class="tile span-3 surv-half">
    <div class="surv-kpi">
      <div class="tile-label">Due in <?php echo e(date('M Y', mktime(0, 0, 0, $selMonth, 1, $selYear))); ?></div>
      <div class="tile-icon navy"><?php echo icon('calendar'); ?></div>
    </div>
    <div class="tile-value"><?php echo count($selectedEntries); ?></div>
    <div class="tile-sub"><?php echo $selRecert; ?> recertification<?php echo $selRecert === 1 ? '' : 's'; ?> in the selected month</div>
  </section>
  <section class="tile span-3 surv-half">
    <div class="surv-kpi">
      <div class="tile-label">Next 30 days</div>
      <div class="tile-icon amber"><?php echo icon('clock'); ?></div>
    </div>
    <div class="tile-value"><?php echo $urgent30Count; ?></div>
    <div class="tile-sub">Due within 30 days</div>
  </section>
  <section class="tile span-3 surv-half">
    <div class="surv-kpi">
      <div class="tile-label">31–60 days</div>
      <div class="tile-icon green"><?php echo icon('check'); ?></div>
    </div>
    <div class="tile-value"><?php echo $urgent60Count; ?></div>
    <div class="tile-sub">Due within 60 days — time to plan</div>
  </section>

  <!-- Month strip -->
  <section class="tile span-12 pad-sm" style="padding:16px 18px;">
    <div class="tile-head" style="margin-bottom:12px;">
      <h2 class="tile-title"><?php echo icon('calendar'); ?> 15-month outlook</h2>
      <div class="strip-legend">
        <span><i style="background:var(--red-600);"></i>Has overdue</span>
        <span><i style="background:var(--amber);"></i>Due ≤ 30 days</span>
        <span><i style="border:1.5px solid var(--navy-600); width:9px; height:9px; border-radius:3px;"></i>This month</span>
      </div>
    </div>
    <div class="month-strip">
      <?php foreach ($stripMonths as $sm): ?>
        <?php
        $cls = 'month-btn';
        if ($sm['is_selected']) $cls .= ' is-selected';
        elseif ($sm['is_current']) $cls .= ' is-current';
        if ($sm['has_overdue']) $cls .= ' has-overdue';
        elseif ($sm['has_urgent']) $cls .= ' has-urgent';
        ?>
        <button type="button" class="<?php echo $cls; ?>"
          onclick="window.location='surveillance.php?year=<?php echo $sm['year']; ?>&month=<?php echo $sm['month']; ?>'">
          <div class="mo-name"><?php echo $sm['label']; ?></div>
          <div class="mo-year"><?php echo $sm['year_label']; ?></div>
          <div class="mo-count"><?php echo $sm['count'] > 0 ? $sm['count'] . ' due' : '—'; ?></div>
        </button>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Selected month table -->
  <section class="tile span-12 pad-0">
    <div class="tile-head surv-table-head">
      <div>
        <h2 class="tile-title"><?php echo e($monthLabel); ?> <span class="badge navy"><?php echo $totalDues; ?></span></h2>
        <div class="tile-sub">
          <?php echo $totalDues > 0 ? $totalDues . ' audit' . ($totalDues > 1 ? 's' : '') . ' due this month' : 'No audits due this month'; ?>
        </div>
      </div>
      <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
        <span class="audit-s1">Surveillance 1</span>
        <span class="audit-s2">Surveillance 2</span>
        <span class="audit-re">Recertification</span>
      </div>
    </div>

    <?php if (empty($selectedEntries)): ?>
      <div class="empty-state" style="padding:40px;">
        <div class="icon-button"><?php echo icon('check'); ?></div>
        <p>No surveillance or recertification audits due in <?php echo e($monthLabel); ?>.</p>
      </div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="surv-table">
      <thead>
        <tr>
          <th>Company</th>
          <th>Certificate</th>
          <th>Audit Type</th>
          <th>Due Date</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($selectedEntries as $entry):
          $urgency  = due_urgency($entry['days_from_now']);
          $auditCls = $entry['audit_type'] === 'Surveillance 1' ? 'audit-s1'
                    : ($entry['audit_type'] === 'Surveillance 2' ? 'audit-s2' : 'audit-re');
          $clientId = get_client_id($pdo, $entry['company']);
          $clientEmail = get_client_email($pdo, $entry['company']);
      ?>
        <tr>
          <td>
            <div class="surv-company"><?php echo e($entry['company']); ?></div>
          </td>
          <td>
            <div class="surv-certno"><?php echo e($entry['cert_number']); ?></div>
            <div class="surv-cert"><?php echo e($entry['cert_type']); ?></div>
            <?php if ($entry['specialization']): ?>
              <div class="surv-type"><?php echo e($entry['specialization']); ?></div>
            <?php endif; ?>
          </td>
          <td><span class="<?php echo $auditCls; ?>"><?php echo e($entry['audit_type']); ?></span></td>
          <td>
            <div class="surv-due"><?php echo date('j M Y', strtotime($entry['due_date'])); ?></div>
            <div class="expiry-pill <?php echo $urgency['cls']; ?>" style="margin-top:5px;"><?php echo e($urgency['label']); ?></div>
          </td>
          <td>
            <span class="badge <?php echo $entry['status'] === 'ACTIVE' ? 'stage-client' : 'red'; ?>">
              <?php echo e(ucfirst(strtolower($entry['status']))); ?>
            </span>
          </td>
          <td>
            <div class="surv-actions">
              <button type="button" class="btn-primary btn-sm surv-remind-btn"
                data-client-id="<?php echo $clientId; ?>"
                data-company="<?php echo e($entry['company']); ?>"
                data-email="<?php echo e($clientEmail); ?>"
                data-audit-type="<?php echo e($entry['audit_type']); ?>"
                data-cert-number="<?php echo e($entry['cert_number']); ?>"
                data-cert-type="<?php echo e($entry['cert_type']); ?>"
                data-due-date="<?php echo e($entry['due_date']); ?>">
                <?php echo icon('send'); ?> Send Reminder
              </button>
              <?php if ($clientId > 0 && role_is(['account_manager', 'management'])): ?>
              <a href="surveillance-tasks.php?assign_client=<?php echo $clientId; ?>" class="btn-secondary btn-sm">
                <?php echo icon('plus'); ?> Assign Task
              </a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </section>
</div>

<?php endif; ?>

<!-- Surveillance Reminder Modal -->
<div id="survReminderModal" class="surv-modal">
  <div class="surv-modal-box">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
      <h3 style="font-size:17px;">Send Surveillance Reminder</h3>
      <button type="button" onclick="closeSurvModal()" class="btn-ghost" style="padding:4px 10px; font-size:16px;" aria-label="Close">✕</button>
    </div>
    <p id="survModalCompany" style="font-size:12.5px; color:var(--ink-500); margin-bottom:16px;"></p>

    <div id="survLoading" style="text-align:center; padding:30px; color:var(--ink-500);">
      <div style="font-size:13px; margin-bottom:8px;">⏳ Drafting surveillance reminder with AI…</div>
    </div>

    <div id="survForm" style="display:none;">
      <div class="ai-chip">⚡ AI-drafted — review and edit before sending</div>

      <div id="survAuditStrip" class="audit-strip"></div>

      <div class="form-field">
        <label for="survTo">To</label>
        <input type="email" id="survTo" placeholder="client@example.com">
      </div>
      <div class="form-field">
        <label for="survSubject">Subject</label>
        <input type="text" id="survSubject">
      </div>
      <div class="form-field" style="margin-bottom:14px;">
        <label for="survBody">Message</label>
        <textarea id="survBody"></textarea>
      </div>

      <div id="survStatus" style="display:none; padding:10px 14px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:12px;"></div>

      <div style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap;">
        <button type="button" class="btn-secondary" onclick="closeSurvModal()">Cancel</button>
        <button type="button" class="btn-secondary" id="survRegenerateBtn" onclick="fetchSurvDraft()">⚡ Regenerate</button>
        <button type="button" class="btn-primary" id="survSendBtn" onclick="sendSurvReminder()">
          <?php echo icon('send'); ?> Send Reminder
        </button>
      </div>
    </div>
  </div>
</div>

<script>
var currentSurv = {};

function escHtml(v) {
  return String(v == null ? '' : v).replace(/[&<>"']/g, function (ch) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
  });
}

function closeSurvModal() {
  document.getElementById('survReminderModal').style.display = 'none';
}

function openSurvModal(btn) {
  currentSurv = {
    clientId:   btn.dataset.clientId,
    company:    btn.dataset.company,
    email:      btn.dataset.email,
    auditType:  btn.dataset.auditType,
    certNumber: btn.dataset.certNumber,
    certType:   btn.dataset.certType,
    dueDate:    btn.dataset.dueDate,
  };

  document.getElementById('survModalCompany').textContent = currentSurv.company + ' — ' + currentSurv.auditType;
  document.getElementById('survLoading').style.display = 'block';
  document.getElementById('survForm').style.display   = 'none';
  document.getElementById('survStatus').style.display = 'none';
  document.getElementById('survTo').value = currentSurv.email || '';
  document.getElementById('survReminderModal').style.display = 'flex';

  fetchSurvDraft();
}

function fetchSurvDraft() {
  document.getElementById('survLoading').style.display = 'block';
  document.getElementById('survForm').style.display   = 'none';

  fetch('cert-surveillance-draft.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(currentSurv),
  })
  .then(function(r){ return r.json(); })
  .then(function(data){
    document.getElementById('survLoading').style.display = 'none';
    document.getElementById('survForm').style.display   = 'block';

    if (!data.ok) {
      showSurvStatus('error', data.error || 'Could not generate draft.');
      return;
    }

    // Audit strip
    var strip = document.getElementById('survAuditStrip');
    // Certificate data comes from CertAdmin via data-* attributes (already
    // HTML-decoded by the browser) — escape before it goes into innerHTML.
    var auditCls = currentSurv.auditType === 'Surveillance 1' ? 'audit-s1'
                 : (currentSurv.auditType === 'Surveillance 2' ? 'audit-s2' : 'audit-re');
    strip.innerHTML =
      '<span class="strip-label">Audit Details</span>' +
      '<div style="margin-top:6px;">' +
        '<span class="' + auditCls + '">' + escHtml(currentSurv.auditType) + '</span> ' +
        '<b>' + escHtml(currentSurv.certNumber) + '</b> — ' + escHtml(currentSurv.certType) +
        '<div class="strip-due">Due: <b>' + escHtml(currentSurv.dueDate) + '</b></div>' +
      '</div>';

    document.getElementById('survSubject').value = data.subject || '';
    document.getElementById('survBody').value    = data.body    || '';

    if (data.ai) {
      showSurvStatus('info', '⚡ Draft generated by AI — review carefully before sending.');
    }
  })
  .catch(function(){
    document.getElementById('survLoading').style.display = 'none';
    document.getElementById('survForm').style.display   = 'block';
    showSurvStatus('error', 'Could not reach the server. Please try again.');
  });
}

function sendSurvReminder() {
  var to      = document.getElementById('survTo').value.trim();
  var subject = document.getElementById('survSubject').value.trim();
  var body    = document.getElementById('survBody').value.trim();

  if (!to)      { showSurvStatus('error', 'Please enter the recipient email.'); return; }
  if (!subject) { showSurvStatus('error', 'Please enter a subject.'); return; }
  if (!body)    { showSurvStatus('error', 'Message cannot be empty.'); return; }

  var btn = document.getElementById('survSendBtn');
  btn.disabled = true; btn.textContent = 'Sending…';

  fetch('cert-reminder-send.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      client_id: currentSurv.clientId,
      company:   currentSurv.company,
      to_email:  to,
      subject:   subject,
      body:      body,
    })
  })
  .then(function(r){ return r.json(); })
  .then(function(data){
    btn.disabled = false; btn.textContent = 'Send Reminder';
    if (data.ok) {
      showSurvStatus('success', '✅ ' + data.message);
      setTimeout(closeSurvModal, 2500);
    } else {
      showSurvStatus('error', '❌ ' + (data.error || 'Send failed.'));
    }
  })
  .catch(function(){
    btn.disabled = false; btn.textContent = 'Send Reminder';
    showSurvStatus('error', 'Network error — please try again.');
  });
}

function showSurvStatus(type, msg) {
  var el = document.getElementById('survStatus');
  el.style.display = 'block';
  el.style.background = type === 'success' ? '#ECFDF5' : type === 'error' ? '#FEF2F2' : '#EFF6FF';
  el.style.color      = type === 'success' ? '#065F46'  : type === 'error' ? '#991B1B'  : '#1E40AF';
  el.textContent = msg;
}

document.querySelectorAll('.surv-remind-btn').forEach(function(btn){
  btn.addEventListener('click', function(){ openSurvModal(this); });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>