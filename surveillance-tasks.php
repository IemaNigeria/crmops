<?php
/**
 * IEMA CRMOps — Surveillance Tasks
 * -----------------------------------------------------------------------
 * Lets an Account Manager (or Management) identify a client that's due
 * for a surveillance audit and hand the follow-up to ANY active staff
 * member — not just BD team roles — to chase and close out. Everyone
 * gets a "My Tasks" view here regardless of role, since the assignee
 * might be IT Admin, Management, a BD Officer, etc.
 *
 * Entry point: accounts.php's "Assign Task" link/button on the
 * surveillance-filtered client list (?cert_filter=surveillance) links
 * here as surveillance-tasks.php?assign_client=ID.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_login();

$role      = current_role();
$uid       = (int) ($_SESSION['user_id'] ?? 0);
$canAssign = role_is(['account_manager', 'management']);
$error     = '';
$success   = '';

const TASK_STATUSES = ['Assigned', 'In Progress', 'Completed', 'Cancelled'];

// ---- Assign a new surveillance task ---------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_task' && $canAssign) {
    $clientId     = (int) ($_POST['client_id'] ?? 0);
    $assignedTo   = (int) ($_POST['assigned_to'] ?? 0);
    $instructions = trim($_POST['instructions'] ?? '');
    $dueDate      = trim($_POST['due_date'] ?? '');

    $clientStmt = $pdo->prepare('SELECT id, company_name FROM clients WHERE id = ?');
    $clientStmt->execute([$clientId]);
    $client = $clientStmt->fetch();

    $assigneeStmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ? AND status = 'active'");
    $assigneeStmt->execute([$assignedTo]);
    $assignee = $assigneeStmt->fetch();

    if (!$client) {
        $error = 'Please select a valid client.';
    } elseif (!$assignee) {
        $error = 'Please select a valid, active staff member to assign this to.';
    } else {
        // Snapshot exactly what's due right now, straight from CertAdmin —
        // the assignee sees this even if their role can't reach My Accounts
        // or the CertAdmin lookup endpoints directly.
        $certSummary = certadmin_surveillance_summary($client['company_name']);

        $stmt = $pdo->prepare('INSERT INTO surveillance_tasks (client_id, company_name, cert_summary, assigned_to, assigned_by, instructions, due_date, status) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([
            $client['id'], $client['company_name'], $certSummary,
            $assignee['id'], $uid, $instructions ?: null, $dueDate ?: null, 'Assigned',
        ]);
        $newId = (int) $pdo->lastInsertId();

        $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
        $audit->execute([$uid, 'create', 'surveillance_task', $newId, "Assigned surveillance follow-up for {$client['company_name']} to {$assignee['name']}"]);

        header('Location: surveillance-tasks.php?assigned=1');
        exit;
    }
}

// ---- Update a task's status / outcome --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_task') {
    $taskId       = (int) ($_POST['task_id'] ?? 0);
    $status       = $_POST['status'] ?? '';
    $outcomeNotes = trim($_POST['outcome_notes'] ?? '');

    $taskStmt = $pdo->prepare('SELECT * FROM surveillance_tasks WHERE id = ?');
    $taskStmt->execute([$taskId]);
    $task = $taskStmt->fetch();

    if (!$task) {
        $error = 'Task not found.';
    } elseif ((int) $task['assigned_to'] !== $uid && !$canAssign) {
        $error = 'You can only update tasks assigned to you.';
    } elseif (!in_array($status, TASK_STATUSES, true)) {
        $error = 'Invalid status.';
    } else {
        $wasAlreadyCompleted = $task['status'] === 'Completed';
        $completedAt = $status === 'Completed' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare('UPDATE surveillance_tasks SET status = ?, outcome_notes = ?, completed_at = ? WHERE id = ?')
            ->execute([$status, $outcomeNotes ?: null, $completedAt, $taskId]);

        $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
        $audit->execute([$uid, 'update', 'surveillance_task', $taskId, "Status changed to $status for {$task['company_name']}" . ($outcomeNotes !== '' ? ': ' . mb_substr($outcomeNotes, 0, 150) : '')]);

        $success = 'Task updated.';

        // Notify whoever assigned this task the moment it's closed and
        // settled — that's the whole point of this table: the assigner
        // doesn't have to keep checking back, they get told. Only fires
        // on the transition INTO Completed (not on every subsequent edit
        // to outcome notes), and never notifies someone of their own
        // action if they assigned it to themselves.
        if ($status === 'Completed' && !$wasAlreadyCompleted && !empty($task['assigned_by']) && (int) $task['assigned_by'] !== $uid) {
            try {
                $closerName = $_SESSION['user_name'] ?? 'A team member';
                $message = "$closerName closed the surveillance task for {$task['company_name']} — settled.";
                $notify = $pdo->prepare('INSERT INTO surveillance_task_notifications (task_id, recipient_id, message) VALUES (?,?,?)');
                $notify->execute([$taskId, (int) $task['assigned_by'], $message]);
                $success = 'Task marked Completed — the account manager who assigned it has been notified.';
            } catch (Throwable $e) {
                // surveillance_task_notifications hasn't been migrated yet —
                // don't let that block the task update itself.
            }
        }
    }
}

// ---- Prefill (arrived from accounts.php's "Assign Task" link) -------------
$assignClientId = (int) ($_GET['assign_client'] ?? 0);
$assignClient   = null;
$assignPreview  = '';
if ($assignClientId > 0 && $canAssign) {
    $stmt = $pdo->prepare('SELECT id, company_name FROM clients WHERE id = ?');
    $stmt->execute([$assignClientId]);
    $assignClient = $stmt->fetch();
    if ($assignClient) {
        $assignPreview = certadmin_surveillance_summary($assignClient['company_name']);
    }
}

// ---- Data for the assign form (Account Manager / Management only) --------
if ($canAssign) {
    $allClients = $pdo->query('SELECT id, company_name FROM clients ORDER BY company_name')->fetchAll();
    $assignableUsersStmt = $pdo->query("SELECT id, name, role FROM users WHERE status = 'active' ORDER BY role, name");
    $assignableUsers = $assignableUsersStmt->fetchAll();
    $assignableByRole = [];
    foreach ($assignableUsers as $u) {
        $assignableByRole[$u['role']][] = $u;
    }
}

// ---- My tasks (everyone) ---------------------------------------------------
// Joins in the client's actual contact details so the assignee can see who
// to call/email and reach them directly — not just the company name and a
// cert summary, which used to be all this view showed.
$myTasksStmt = $pdo->prepare("
    SELECT t.*, u.name AS assigned_by_name,
           cl.primary_contact_name, cl.primary_contact_email, cl.primary_contact_phone,
           cl.address, cl.website
    FROM surveillance_tasks t
    LEFT JOIN users u ON u.id = t.assigned_by
    LEFT JOIN clients cl ON cl.id = t.client_id
    WHERE t.assigned_to = ?
    ORDER BY FIELD(t.status, 'Assigned', 'In Progress', 'Completed', 'Cancelled'), t.due_date IS NULL, t.due_date ASC, t.created_at DESC
");
$myTasksStmt->execute([$uid]);
$myTasks = $myTasksStmt->fetchAll();

// ---- All tasks (Account Manager / Management only) -------------------------
$allTasks = [];
if ($canAssign) {
    $teamStatusFilter = $_GET['team_status'] ?? '';
    $teamWhere  = '';
    $teamParams = [];
    if (in_array($teamStatusFilter, TASK_STATUSES, true)) {
        $teamWhere = 'WHERE t.status = ?';
        $teamParams[] = $teamStatusFilter;
    }
    $allTasksStmt = $pdo->prepare("
        SELECT t.*, ua.name AS assigned_to_name, ua.role AS assigned_to_role, ub.name AS assigned_by_name,
               cl.primary_contact_name, cl.primary_contact_email, cl.primary_contact_phone,
               cl.address, cl.website
        FROM surveillance_tasks t
        LEFT JOIN users ua ON ua.id = t.assigned_to
        LEFT JOIN users ub ON ub.id = t.assigned_by
        LEFT JOIN clients cl ON cl.id = t.client_id
        $teamWhere
        ORDER BY FIELD(t.status, 'Assigned', 'In Progress', 'Completed', 'Cancelled'), t.due_date IS NULL, t.due_date ASC, t.created_at DESC
    ");
    $allTasksStmt->execute($teamParams);
    $allTasks = $allTasksStmt->fetchAll();
}

$statusBadge = [
    'Assigned'    => 'stage-lead',
    'In Progress' => 'stage-negotiation',
    'Completed'   => 'stage-client',
    'Cancelled'   => 'stage-lost',
];

/**
 * The client contact card shown on every task — this is the whole reason
 * an assignee (who might have no access to Clients/accounts.php at all,
 * e.g. an Auditor) can actually do something with a task instead of just
 * seeing a company name.
 */
function render_task_contact_block(array $t): string
{
    $hasContact = !empty($t['primary_contact_name']) || !empty($t['primary_contact_email'])
        || !empty($t['primary_contact_phone']) || !empty($t['address']);

    if (!$hasContact) {
        return '<div class="contact-missing">No contact details on file for this client yet.</div>';
    }

    $html = '<div class="contact-block">';
    if (!empty($t['primary_contact_name'])) {
        $html .= '<div><span class="contact-label">Contact</span><br>' . e($t['primary_contact_name']) . '</div>';
    }
    if (!empty($t['primary_contact_email'])) {
        $html .= '<div><span class="contact-label">Email</span><br><a href="mailto:' . e($t['primary_contact_email']) . '">' . e($t['primary_contact_email']) . '</a></div>';
    }
    if (!empty($t['primary_contact_phone'])) {
        $html .= '<div><span class="contact-label">Phone</span><br>' . e($t['primary_contact_phone']) . '</div>';
    }
    if (!empty($t['address'])) {
        $html .= '<div><span class="contact-label">Address</span><br>' . e($t['address']) . '</div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Invoices tied to this task's client, plus a "Generate Invoice" link —
 * this is what lets someone (an Auditor especially, who has no other
 * access to Invoices) bill for the surveillance audit they're doing and
 * later come back and record payment against it, without leaving this
 * page for the full Invoices list.
 */
function render_task_invoice_block(PDO $pdo, array $t): string
{
    if (empty($t['client_id'])) {
        return '';
    }

    $stmt = $pdo->prepare('SELECT id, invoice_number, total_ngn, status FROM invoices WHERE client_id = ? ORDER BY created_at DESC');
    $stmt->execute([(int) $t['client_id']]);
    $invs = $stmt->fetchAll();

    $statusBadge = [
        'draft' => 'stage-lead', 'sent' => 'stage-proposal', 'part-paid' => 'stage-negotiation',
        'paid' => 'stage-client', 'overdue' => 'stage-lost', 'cancelled' => 'stage-lost',
    ];

    $html = '<div class="invoice-block"><div class="invoice-label">Invoices for this client</div>';
    if (empty($invs)) {
        $html .= '<div style="font-size:12px;color:var(--ink-500);">None yet.</div>';
    } else {
        foreach ($invs as $inv) {
            $badge = $statusBadge[$inv['status']] ?? '';
            $html .= '<div class="invoice-row">'
                . '<span><b>' . e($inv['invoice_number']) . '</b> &mdash; &#x20A6;' . number_format((float) $inv['total_ngn'], 2) . '</span>'
                . '<span><span class="badge ' . e($badge) . '" style="margin-right:8px;">' . e(ucfirst($inv['status'])) . '</span>'
                . '<a href="invoices.php?view=' . (int) $inv['id'] . '" class="btn-ghost" style="padding:4px 9px;">Open</a></span>'
                . '</div>';
        }
    }
    $html .= '<a href="invoices.php?new=1&client_id=' . (int) $t['client_id'] . '" class="btn-secondary" style="margin-top:10px;display:inline-flex;align-items:center;gap:5px;">'
        . icon('plus') . ' Generate Invoice</a>';
    $html .= '</div>';
    return $html;
}

require_once __DIR__ . '/includes/header.php';
?>

<?php
// ---- Summary numbers (presentation only) ---------------------------------
// Assigners see team-wide counts (one read-only aggregate, independent of the
// status filter below); everyone else sees counts for their own tasks.
$todayYmd   = date('Y-m-d');
$sumCounts  = ['Assigned' => 0, 'In Progress' => 0, 'Completed' => 0, 'Cancelled' => 0];
$sumOverdue = 0;
if ($canAssign) {
    foreach ($pdo->query("SELECT status, COUNT(*) AS n, SUM(status IN ('Assigned','In Progress') AND due_date IS NOT NULL AND due_date < CURDATE()) AS od FROM surveillance_tasks GROUP BY status")->fetchAll() as $r) {
        $sumCounts[$r['status']] = (int) $r['n'];
        $sumOverdue += (int) $r['od'];
    }
} else {
    foreach ($myTasks as $mt) {
        $sumCounts[$mt['status']] = ($sumCounts[$mt['status']] ?? 0) + 1;
        if (in_array($mt['status'], ['Assigned', 'In Progress'], true) && !empty($mt['due_date']) && $mt['due_date'] < $todayYmd) {
            $sumOverdue++;
        }
    }
}
$sumOpen  = $sumCounts['Assigned'] + $sumCounts['In Progress'];
$sumScope = $canAssign ? 'across the team' : 'assigned to you';

/** Small due-date chip for a task card. */
function task_due_chip(array $t, string $todayYmd): string
{
    if (empty($t['due_date'])) {
        return '';
    }
    $label  = 'Due ' . date('j M Y', strtotime($t['due_date']));
    $isOpen = in_array($t['status'], ['Assigned', 'In Progress'], true);
    if ($isOpen && $t['due_date'] < $todayYmd) {
        return '<span class="due-chip overdue">' . icon('alert') . ' Overdue · ' . e(date('j M', strtotime($t['due_date']))) . '</span>';
    }
    return '<span class="due-chip">' . icon('calendar') . ' ' . e($label) . '</span>';
}
?>
<style>
.task-list { display:flex; flex-direction:column; gap:12px; }
.task-list.grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(min(100%, 440px), 1fr)); align-items:start; }
.task-card { background:var(--neutral-50); border:1px solid var(--line); border-radius:16px; padding:16px; min-width:0; container-type:inline-size; }
.task-card.is-closed { background:#fff; }
.task-card .task-top { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
.task-card .task-company { font-family:'Libre Franklin',sans-serif; font-weight:700; font-size:14.5px; color:var(--ink-900); }
.task-card .task-meta { font-size:11.5px; color:var(--ink-500); margin-top:3px; line-height:1.5; }
.task-card .task-meta b { color:var(--ink-700); font-weight:600; }
.task-card .task-chips { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
.due-chip { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:3px 8px; border-radius:999px; background:#EEF2F8; color:var(--navy-700); white-space:nowrap; }
.due-chip .icon { width:12px; height:12px; }
.due-chip.overdue { background:var(--red-50); color:var(--red-700); }
.task-card .task-body { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:10px; margin-top:12px; }
.task-card .task-body > * { margin-top:0 !important; }
.task-card .contact-block, .task-card .invoice-block { font-size:12.5px; color:var(--ink-700); background:#fff; border:1px solid var(--line); border-radius:12px; padding:11px 12px; min-width:0; }
.task-card .contact-block { display:grid; grid-template-columns:1fr 1fr; grid-auto-flow:row dense; gap:8px 14px; align-content:start; }
.task-card .contact-block > div { min-width:0; overflow-wrap:anywhere; }
.task-card .contact-block > div:has(a[href^="mailto:"]), .task-card .contact-block > div:last-child { grid-column:1 / -1; }
.task-card .contact-block a { color:var(--navy-700); font-weight:600; }
.task-card .contact-block .contact-label, .task-card .invoice-block .invoice-label { font-size:10.5px; font-weight:700; color:var(--ink-500); text-transform:uppercase; letter-spacing:.4px; }
.task-card .invoice-block .invoice-label { margin-bottom:4px; }
.task-card .contact-missing { font-size:12px; color:var(--ink-500); font-style:italic; background:#fff; border:1px dashed var(--neutral-300); border-radius:12px; padding:11px 12px; }
.task-card .invoice-row { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:6px 0; border-bottom:1px solid var(--line-soft); flex-wrap:wrap; }
.task-card .invoice-row:last-of-type { border-bottom:none; }
.task-card .invoice-block .btn-secondary { padding:6px 11px; font-size:12px; border-radius:9px; }
.task-card .invoice-block .btn-secondary .icon { width:14px; height:14px; }
.task-card .cert-summary { white-space:pre-line; font-size:12.5px; color:var(--ink-700); background:#fff; border:1px solid var(--line); border-radius:12px; padding:10px 12px; margin-top:10px; }
.task-card .instructions { font-size:12.5px; color:var(--ink-700); margin-top:10px; line-height:1.5; }
.task-card .outcome { font-size:12.5px; color:var(--ink-700); background:#fff; border-left:3px solid var(--navy-600); border-radius:0 10px 10px 0; padding:8px 10px; margin-top:10px; }
.task-card .task-foot { display:grid; grid-template-columns:auto minmax(0,1fr) auto auto; grid-template-areas:"note note note note" "remind . status update"; gap:8px; align-items:center; margin-top:12px; padding-top:12px; border-top:1px solid var(--line); }
.task-card .task-actions { grid-area:remind; display:flex; gap:8px; }
.task-card .task-actions .btn-secondary { padding:7px 11px; font-size:12.5px; border-radius:9px; }
.task-card .task-actions .icon, .task-update-form .icon { width:14px; height:14px; }
/* The update form's controls join the footer grid so the note gets a full row */
.task-update-form { display:contents; }
.task-update-form textarea { grid-area:note; width:100%; min-width:0; padding:8px 10px; border-radius:9px; border:1px solid var(--neutral-300); font-size:12.5px; font-family:inherit; min-height:42px; height:42px; resize:vertical; background:#fff; }
.task-update-form select { grid-area:status; padding:7px 9px; border-radius:9px; border:1px solid var(--neutral-300); font-size:12.5px; font-family:inherit; background:#fff; color:var(--ink-900); min-width:0; }
.task-update-form select:focus, .task-update-form textarea:focus { outline:none; border-color:var(--red-600); box-shadow:var(--ring); }
.task-update-form .btn-navy { grid-area:update; padding:7px 12px; font-size:12.5px; border-radius:9px; }
@container (max-width:440px) {
  .task-card .task-foot { grid-template-columns:minmax(0,1fr) auto; grid-template-areas:"note note" "status update" "remind remind"; }
  .task-card .task-actions .btn-secondary { width:100%; }
  .task-card .task-actions { display:block; }
  .task-card .contact-block { grid-template-columns:1fr; }
}
.col-stack { display:flex; flex-direction:column; gap:var(--gap); min-width:0; }
.assign-form .form-grid { grid-template-columns:1fr; gap:12px; }
.assign-form .cert-summary { white-space:pre-line; font-size:12.5px; color:var(--ink-700); background:var(--neutral-50); border:1px solid var(--line); border-radius:12px; padding:10px 12px; }
.assign-form .form-actions { margin-top:14px; }
.assign-form .form-actions .btn-primary { width:100%; }
.field-hint { font-size:11px; color:var(--ink-500); margin-top:5px; }
.kpi-tile-top { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }

/* Reminder modal */
.rem-modal { display:none; position:fixed; inset:0; background:rgba(11,21,36,0.5); backdrop-filter:blur(4px); z-index:101; align-items:center; justify-content:center; padding:20px; }
.rem-modal-box { background:#fff; border:1px solid var(--line); border-radius:var(--r-tile); padding:24px; max-width:640px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:var(--shadow-pop); }
.rem-modal-box .ai-chip { display:inline-flex; align-items:center; gap:5px; font-size:10.5px; font-weight:700; color:var(--red-700); background:var(--red-50); padding:3px 9px; border-radius:999px; margin-bottom:14px; }
.rem-modal-box .cert-strip { background:var(--neutral-50); border:1px solid var(--line); border-radius:12px; padding:12px 14px; margin-bottom:14px; font-size:12.5px; }
.rem-modal-box .cert-strip .strip-label { font-size:11px; color:var(--ink-500); text-transform:uppercase; letter-spacing:.5px; font-weight:700; }
.rem-modal-box .strip-badge { display:inline-flex; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:700; background:var(--amber-50); color:#B45309; }
.rem-modal-box .strip-badge.overdue { background:var(--red-50); color:var(--red-700); }
.rem-modal-box .form-field { margin-bottom:12px; }
.rem-modal-box .form-field textarea { min-height:220px; line-height:1.6; }
@media (max-width:760px){
  .bento > .kpi-half { grid-column:span 6 !important; }
  .kpi-half.tile { padding:14px; }
  .kpi-half .tile-value { font-size:24px; }
  .kpi-half .tile-icon { width:30px; height:30px; border-radius:9px; }
  .kpi-half .tile-sub { font-size:11.5px; }
  .task-card { padding:14px; }
  .task-card .contact-block { grid-template-columns:1fr; }
}
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Certification lifecycle</div>
    <h1>Surveillance Tasks</h1>
    <p><?php echo $canAssign ? 'Assign clients due for surveillance to any staff member, and track progress across the team.' : 'Surveillance follow-ups assigned to you.'; ?></p>
  </div>
  <?php if (role_is(['account_manager', 'management', 'it_admin'])): ?>
  <div class="page-actions">
    <a href="surveillance.php" class="btn-secondary"><?php echo icon('calendar'); ?> Surveillance Calendar</a>
  </div>
  <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert error"><?php echo icon('alert'); ?><span><?php echo e($error); ?></span></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?php echo icon('check'); ?><span><?php echo e($success); ?></span></div><?php endif; ?>
<?php if (isset($_GET['assigned'])): ?><div class="alert success"><?php echo icon('check'); ?><span>Surveillance task assigned.</span></div><?php endif; ?>

<div class="bento">
  <section class="tile tile--navy span-3 kpi-half">
    <div class="kpi-tile-top">
      <div class="tile-label">Open tasks</div>
      <div class="tile-icon"><?php echo icon('clipboard'); ?></div>
    </div>
    <div class="tile-value"><?php echo $sumOpen; ?></div>
    <div class="tile-sub"><?php echo $sumOverdue; ?> overdue · <?php echo e($sumScope); ?></div>
  </section>
  <section class="tile span-3 kpi-half">
    <div class="kpi-tile-top">
      <div class="tile-label">Assigned</div>
      <div class="tile-icon navy"><?php echo icon('users'); ?></div>
    </div>
    <div class="tile-value"><?php echo $sumCounts['Assigned']; ?></div>
    <div class="tile-sub">Not yet started</div>
  </section>
  <section class="tile span-3 kpi-half">
    <div class="kpi-tile-top">
      <div class="tile-label">In progress</div>
      <div class="tile-icon amber"><?php echo icon('clock'); ?></div>
    </div>
    <div class="tile-value"><?php echo $sumCounts['In Progress']; ?></div>
    <div class="tile-sub">Being chased now</div>
  </section>
  <section class="tile span-3 kpi-half">
    <div class="kpi-tile-top">
      <div class="tile-label">Completed</div>
      <div class="tile-icon green"><?php echo icon('check'); ?></div>
    </div>
    <div class="tile-value"><?php echo $sumCounts['Completed']; ?></div>
    <div class="tile-sub"><?php echo $sumCounts['Cancelled']; ?> cancelled</div>
  </section>

<?php if ($canAssign): ?>
  <div class="span-4 col-stack">
    <section class="tile assign-form">
      <div class="tile-head">
        <h2 class="tile-title"><?php echo icon('plus'); ?> Assign a follow-up</h2>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="assign_task">
        <div class="form-grid">
          <div class="form-field full">
            <label>Client *</label>
            <select name="client_id" required>
              <option value="">Select a client…</option>
              <?php foreach ($allClients as $cl): ?>
                <option value="<?php echo (int) $cl['id']; ?>" <?php echo ($assignClient && (int) $assignClient['id'] === (int) $cl['id']) ? 'selected' : ''; ?>>
                  <?php echo e($cl['company_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($assignClient): ?>
          <div class="form-field full">
            <label>Currently due for surveillance (live from CertAdmin)</label>
            <div class="cert-summary"><?php echo e($assignPreview); ?></div>
          </div>
          <?php endif; ?>
          <div class="form-field full">
            <label>Assign To *</label>
            <select name="assigned_to" required>
              <option value="">Select a staff member…</option>
              <?php foreach ($assignableByRole as $roleKey => $users): ?>
                <optgroup label="<?php echo e(ROLES[$roleKey] ?? $roleKey); ?>">
                  <?php foreach ($users as $u): ?>
                    <option value="<?php echo (int) $u['id']; ?>"><?php echo e($u['name']); ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
            <div class="field-hint">Any active staff member can be assigned — not only BD Officers/Managers.</div>
          </div>
          <div class="form-field">
            <label>Due Date</label>
            <input type="date" name="due_date">
          </div>
          <div class="form-field full">
            <label>Instructions</label>
            <textarea name="instructions" placeholder="e.g. Follow up with the client to schedule the surveillance audit and close it out." style="min-height:90px;"></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Assign Task</button>
        </div>
      </form>
    </section>
<?php endif; ?>

    <section class="tile <?php echo $canAssign ? '' : 'span-12'; ?>">
      <div class="tile-head">
        <h2 class="tile-title">My Tasks <span class="badge navy"><?php echo count($myTasks); ?></span></h2>
        <span class="tile-sub" style="margin:0;"><?php echo count($myTasks); ?> task<?php echo count($myTasks) === 1 ? '' : 's'; ?> assigned to you</span>
      </div>
      <?php if (empty($myTasks)): ?>
        <div class="empty-state">
          <div class="icon-button"><?php echo icon('clipboard'); ?></div>
          <p>No surveillance tasks assigned to you.</p>
        </div>
      <?php else: ?>
      <div class="task-list <?php echo $canAssign ? '' : 'grid'; ?>">
        <?php foreach ($myTasks as $t): $closed = in_array($t['status'], ['Completed', 'Cancelled'], true); ?>
          <article class="task-card<?php echo $closed ? ' is-closed' : ''; ?>">
            <div class="task-top">
              <div>
                <div class="task-company"><?php echo e($t['company_name']); ?></div>
                <div class="task-meta">
                  Assigned by <b><?php echo e($t['assigned_by_name'] ?? 'someone'); ?></b> on <?php echo date('j M Y', strtotime($t['created_at'])); ?>
                </div>
              </div>
              <span class="badge <?php echo e($statusBadge[$t['status']] ?? ''); ?>"><?php echo e($t['status']); ?></span>
            </div>
            <?php $chip = task_due_chip($t, $todayYmd); if ($chip): ?><div class="task-chips"><?php echo $chip; ?></div><?php endif; ?>
            <div class="task-body">
              <?php echo render_task_contact_block($t); ?>
              <?php echo render_task_invoice_block($pdo, $t); ?>
            </div>
            <?php if (!empty($t['cert_summary'])): ?><div class="cert-summary"><?php echo e($t['cert_summary']); ?></div><?php endif; ?>
            <?php if (!empty($t['instructions'])): ?><div class="instructions"><b>Instructions:</b> <?php echo e($t['instructions']); ?></div><?php endif; ?>
            <?php if (!empty($t['outcome_notes'])): ?><div class="outcome"><b>Latest note:</b> <?php echo e($t['outcome_notes']); ?></div><?php endif; ?>

            <?php if (!$closed): ?>
            <div class="task-foot">
              <div class="task-actions">
                <button type="button" class="btn-secondary task-remind-btn" data-task-id="<?php echo (int) $t['id']; ?>" data-company="<?php echo e($t['company_name']); ?>" data-email="<?php echo e($t['primary_contact_email'] ?? ''); ?>">
                  <?php echo icon('send'); ?> Send Reminder
                </button>
              </div>
              <form method="POST" class="task-update-form">
                <input type="hidden" name="action" value="update_task">
                <input type="hidden" name="task_id" value="<?php echo (int) $t['id']; ?>">
                <select name="status">
                  <?php foreach (TASK_STATUSES as $s): ?>
                    <option value="<?php echo e($s); ?>" <?php echo $s === $t['status'] ? 'selected' : ''; ?>><?php echo e($s); ?></option>
                  <?php endforeach; ?>
                </select>
                <textarea name="outcome_notes" placeholder="Add a note (e.g. audit scheduled, client unreachable, closed)…"></textarea>
                <button type="submit" class="btn-navy"><?php echo icon('check'); ?> Update</button>
              </form>
            </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
<?php if ($canAssign): ?>
  </div>

  <section class="tile span-8">
    <div class="tile-head">
      <h2 class="tile-title">All Surveillance Tasks <span class="badge navy"><?php echo count($allTasks); ?></span></h2>
      <div class="filter-chips">
        <a href="surveillance-tasks.php" class="filter-chip<?php echo $teamStatusFilter === '' ? ' active' : ''; ?>">All</a>
        <?php foreach (TASK_STATUSES as $s): ?>
          <a href="surveillance-tasks.php?team_status=<?php echo urlencode($s); ?>" class="filter-chip<?php echo $teamStatusFilter === $s ? ' active' : ''; ?>"><?php echo e($s); ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (empty($allTasks)): ?>
      <div class="empty-state">
        <div class="icon-button"><?php echo icon('clipboard'); ?></div>
        <p>No surveillance tasks<?php echo $teamStatusFilter ? ' with this status' : ' yet'; ?>.</p>
      </div>
    <?php else: ?>
    <div class="task-list">
      <?php foreach ($allTasks as $t): $closed = in_array($t['status'], ['Completed', 'Cancelled'], true); ?>
        <article class="task-card<?php echo $closed ? ' is-closed' : ''; ?>">
          <div class="task-top">
            <div>
              <div class="task-company"><?php echo e($t['company_name']); ?></div>
              <div class="task-meta">
                Assigned to <b><?php echo e($t['assigned_to_name'] ?? 'someone'); ?></b> (<?php echo e(ROLES[$t['assigned_to_role']] ?? $t['assigned_to_role'] ?? ''); ?>)
                by <?php echo e($t['assigned_by_name'] ?? 'someone'); ?> on <?php echo date('j M Y', strtotime($t['created_at'])); ?>
              </div>
            </div>
            <span class="badge <?php echo e($statusBadge[$t['status']] ?? ''); ?>"><?php echo e($t['status']); ?></span>
          </div>
          <?php $chip = task_due_chip($t, $todayYmd); if ($chip): ?><div class="task-chips"><?php echo $chip; ?></div><?php endif; ?>
          <div class="task-body">
            <?php echo render_task_contact_block($t); ?>
            <?php echo render_task_invoice_block($pdo, $t); ?>
          </div>
          <?php if (!empty($t['instructions'])): ?><div class="instructions"><b>Instructions:</b> <?php echo e($t['instructions']); ?></div><?php endif; ?>
          <?php if (!empty($t['outcome_notes'])): ?><div class="outcome"><b>Latest note:</b> <?php echo e($t['outcome_notes']); ?></div><?php endif; ?>

          <?php if (!$closed): ?>
          <div class="task-foot">
            <div class="task-actions">
              <button type="button" class="btn-secondary task-remind-btn" data-task-id="<?php echo (int) $t['id']; ?>" data-company="<?php echo e($t['company_name']); ?>" data-email="<?php echo e($t['primary_contact_email'] ?? ''); ?>">
                <?php echo icon('send'); ?> Send Reminder
              </button>
            </div>
            <form method="POST" class="task-update-form">
              <input type="hidden" name="action" value="update_task">
              <input type="hidden" name="task_id" value="<?php echo (int) $t['id']; ?>">
              <select name="status">
                <?php foreach (TASK_STATUSES as $s): ?>
                  <option value="<?php echo e($s); ?>" <?php echo $s === $t['status'] ? 'selected' : ''; ?>><?php echo e($s); ?></option>
                <?php endforeach; ?>
              </select>
              <textarea name="outcome_notes" placeholder="Add a note…"></textarea>
              <button type="submit" class="btn-navy"><?php echo icon('check'); ?> Update</button>
            </form>
          </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
</div>

<!-- Reminder Modal (ported from accounts.php, keyed by task_id instead of
     client_id/company/filter — see cert-reminder-draft.php / cert-reminder-send.php
     for the task_id-based auth path this relies on). -->
<div id="reminderModal" class="rem-modal">
  <div class="rem-modal-box">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
      <h3 style="font-size:17px;">Send Surveillance Reminder</h3>
      <button type="button" onclick="closeReminderModal()" class="btn-ghost" style="padding:4px 10px;font-size:16px;" aria-label="Close">✕</button>
    </div>
    <p id="reminderCompany" style="font-size:12.5px;color:var(--ink-500);margin-bottom:16px;"></p>

    <div id="reminderLoading" style="text-align:center;padding:30px;color:var(--ink-500);">
      <div style="font-size:13px;margin-bottom:8px;">⏳ Fetching certificate data and drafting reminder with AI…</div>
      <div style="font-size:11.5px;color:var(--ink-400);">This takes a few seconds</div>
    </div>

    <div id="reminderForm" style="display:none;">
      <div class="ai-chip">⚡ AI-drafted — review and edit before sending</div>

      <div id="reminderCertStrip" class="cert-strip"></div>

      <div class="form-field">
        <label for="reminderTo">To</label>
        <input type="email" id="reminderTo" placeholder="client@example.com">
      </div>
      <div class="form-field">
        <label for="reminderSubject">Subject</label>
        <input type="text" id="reminderSubject">
      </div>
      <div class="form-field" style="margin-bottom:14px;">
        <label for="reminderBody">Message</label>
        <textarea id="reminderBody"></textarea>
      </div>

      <div class="form-field" style="margin-bottom:14px;">
        <label for="reminderTone">Tone / instructions for the redraft (optional)</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <input type="text" id="reminderTone" style="flex:1 1 220px;width:auto;" placeholder="e.g. this is the 3rd reminder — make it firmer and more urgent">
          <button type="button" class="btn-secondary" id="reminderRegenerateBtn" onclick="regenerateReminder()">⚡ Regenerate</button>
        </div>
        <div class="field-hint">Leave blank and click Regenerate for a plain redraft, or describe the tone you want — e.g. "warm", "firm — second follow-up", "short and direct".</div>
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

<script>
// ---- Reminder modal (task_id-based) ---------------------------------------
var currentReminder = { taskId: 0, company: '' };

function escHtml(v) {
  return String(v == null ? '' : v).replace(/[&<>"']/g, function (ch) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
  });
}

function closeReminderModal() {
  document.getElementById('reminderModal').style.display = 'none';
}

function openTaskReminderModal(taskId, company, email) {
  currentReminder = { taskId: taskId, company: company };
  document.getElementById('reminderCompany').textContent = company;
  document.getElementById('reminderLoading').style.display = 'block';
  document.getElementById('reminderForm').style.display = 'none';
  document.getElementById('reminderStatus').style.display = 'none';
  document.getElementById('reminderTo').value = email || '';
  document.getElementById('reminderTone').value = '';
  document.getElementById('reminderModal').style.display = 'flex';
  fetchDraft(email);
}

function fetchDraft(email, toneInstruction) {
  document.getElementById('reminderLoading').style.display = 'block';
  document.getElementById('reminderForm').style.display = 'none';
  document.querySelector('#reminderLoading > div').textContent = toneInstruction
    ? '⏳ Redrafting with your instructions…'
    : '⏳ Fetching certificate data and drafting reminder with AI…';

  var url = 'cert-reminder-draft.php?task_id=' + encodeURIComponent(currentReminder.taskId);
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

      // Certificate fields come from CertAdmin — escape everything that
      // goes into innerHTML so a crafted certificate can't inject markup.
      var strip = document.getElementById('reminderCertStrip');
      var stripHtml = '<span class="strip-label">Certificates requiring attention:</span>';
      if (data.certs && data.certs.length > 0) {
        data.certs.forEach(function(c) {
          var badge;
          if (c.survey) {
            badge = c.survey.state === 'overdue'
              ? '<span class="strip-badge overdue">Survey ' + escHtml(c.survey.year) + ' Overdue</span>'
              : '<span class="strip-badge">Survey ' + escHtml(c.survey.year) + ' Due</span>';
          } else {
            var daysLeft = c.expire_date ? Math.floor((new Date(c.expire_date) - new Date()) / 86400000) : null;
            badge = daysLeft !== null && daysLeft < 0
              ? '<span class="strip-badge overdue">Expired</span>'
              : '<span class="strip-badge">Expires ' + escHtml(c.expire_date) + '</span>';
          }
          stripHtml += '<div style="margin-top:6px;">' + badge + ' <b>' + escHtml(c.certificate_number) + '</b> — ' + escHtml(c.certification_type) + '</div>';
        });
      }
      strip.innerHTML = stripHtml;

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
  fetchDraft(document.getElementById('reminderTo').value, toneInstruction);
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
      task_id:  currentReminder.taskId,
      company:  currentReminder.company,
      to_email: toEmail,
      subject:  subject,
      body:     body,
    })
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    sendBtn.disabled = false;
    sendBtn.innerHTML = '<?php echo icon("send"); ?> Send Reminder';
    if (data.ok) {
      showReminderStatus('success', '✅ ' + data.message);
      setTimeout(function(){ closeReminderModal(); }, 2500);
    } else {
      showReminderStatus('error', '❌ ' + (data.error || 'Send failed.'));
    }
  })
  .catch(function() {
    sendBtn.disabled = false;
    sendBtn.innerHTML = '<?php echo icon("send"); ?> Send Reminder';
    showReminderStatus('error', 'Network error — please try again.');
  });
}

function showReminderStatus(type, msg) {
  var el = document.getElementById('reminderStatus');
  el.style.display = 'block';
  el.style.background = type === 'success' ? '#ECFDF5' : type === 'error' ? '#FEF2F2' : '#EFF6FF';
  el.style.color      = type === 'success' ? '#065F46'  : type === 'error' ? '#991B1B'  : '#1E40AF';
  el.textContent = msg;
}

document.querySelectorAll('.task-remind-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    openTaskReminderModal(
      parseInt(btn.dataset.taskId, 10),
      btn.dataset.company,
      btn.dataset.email
    );
  });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>