<?php
/**
 * IEMA CRMOps — Lead Assignment (FR-1.4)
 *
 * Allows BD Managers, BD Officers, Account Managers, and Management users
 * to be assigned or reassigned to open leads. Management users can now
 * assign leads to themselves (fix applied to officers query and dropdown).
 */
require_once __DIR__ . '/config.php';
require_login();

$uid     = (int) ($_SESSION['user_id'] ?? 0);
$success = '';
$error   = '';

// ── Reassign action ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reassign') {
    $leadId       = (int) ($_POST['lead_id'] ?? 0);
    $newOfficer   = (int) ($_POST['assigned_to'] ?? 0);
    $handoverNote = trim($_POST['handover_note'] ?? '');

    if ($leadId > 0 && $newOfficer > 0) {
        // Capture previous assignee for the audit trail.
        $prevStmt = $pdo->prepare(
            'SELECT l.assigned_to, u.name AS prev_name
             FROM leads l
             LEFT JOIN users u ON u.id = l.assigned_to
             WHERE l.id = ?'
        );
        $prevStmt->execute([$leadId]);
        $prev = $prevStmt->fetch();

        $pdo->prepare('UPDATE leads SET assigned_to = ? WHERE id = ?')
            ->execute([$newOfficer, $leadId]);

        $newNameStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
        $newNameStmt->execute([$newOfficer]);
        $newName = $newNameStmt->fetchColumn() ?: 'someone';

        $samePerson = $prev && (int) $prev['assigned_to'] === $newOfficer;
        $details    = ($prev && $prev['prev_name'] && !$samePerson)
            ? "Reassigned from {$prev['prev_name']} to $newName"
            : "Assigned to $newName";
        if ($handoverNote !== '') {
            $details .= ': ' . $handoverNote;
        }
        $details = mb_substr($details, 0, 255);

        $pdo->prepare(
            'INSERT INTO audit_log (user_id, action, entity_type, entity_id, details)
             VALUES (?,?,?,?,?)'
        )->execute([$uid, 'update', 'lead', $leadId, $details]);

        $success = "Lead assigned to $newName. The handover is logged in its trail.";
    } else {
        $error = 'Please select both a lead and an officer before saving.';
    }
}

// ── Data ──────────────────────────────────────────────────────────────────

// Include 'management' so management users can assign leads to themselves.
$officers = $pdo->query(
    "SELECT id, name, role
     FROM users
     WHERE role IN ('bd_officer', 'bd_manager', 'account_manager', 'management')
       AND status = 'active'
     ORDER BY (role = 'bd_manager') DESC, name ASC"
)->fetchAll();

$leads = $pdo->query("
    SELECT l.id, l.reference_number, l.company_name, l.stage,
           l.estimated_value_ngn, l.assigned_to, l.quote_details,
           l.contact_person, l.contact_email, l.contact_phone,
           l.lead_source, l.updated_at,
           u.name AS officer_name
    FROM leads l
    LEFT JOIN users u ON u.id = l.assigned_to
    WHERE l.stage NOT IN ('Won', 'Lost')
    ORDER BY l.assigned_to IS NULL DESC, l.updated_at DESC
")->fetchAll();

// Workload summary: active lead count per officer (for the tooltip/badge).
$workloadStmt = $pdo->query(
    "SELECT assigned_to, COUNT(*) AS n
     FROM leads
     WHERE stage NOT IN ('Won','Lost') AND assigned_to IS NOT NULL
     GROUP BY assigned_to"
);
$workload = [];
foreach ($workloadStmt->fetchAll() as $row) {
    $workload[(int) $row['assigned_to']] = (int) $row['n'];
}

// Lead history modal data — fetched via AJAX in viewLeadHistory(); defined
// here only for clarity that it's not on this page's initial payload.

$stageBadgeClass = [
    'New'              => 'stage-lead',
    'Contacted'        => 'stage-lead',
    'Needs Assessment' => 'stage-lead',
    'Proposal Sent'    => 'stage-proposal',
    'Negotiation'      => 'stage-negotiation',
];

$roleLabel = [
    'bd_officer'      => 'BD Officer',
    'bd_manager'      => 'Manager',
    'account_manager' => 'CRM',
    'management'      => 'Management',
];

// Group counts for the summary bar.
$totalLeads      = count($leads);
$unassignedCount = count(array_filter($leads, fn($l) => $l['assigned_to'] === null));
$assignedCount   = $totalLeads - $unassignedCount;

require_once __DIR__ . '/includes/header.php';
?>

<?php
// Workload tile: officers ranked by current open-lead count (from $officers + $workload, already loaded).
$workloadRows = [];
foreach ($officers as $o) {
    $workloadRows[] = ['name' => $o['name'], 'role' => $o['role'], 'id' => (int) $o['id'], 'n' => $workload[(int) $o['id']] ?? 0];
}
usort($workloadRows, fn($a, $b) => $b['n'] <=> $a['n'] ?: strcmp($a['name'], $b['name']));
$maxWorkload = $workloadRows ? max(1, max(array_column($workloadRows, 'n'))) : 1;
?>
<style>
/* assign.php — page-scoped bento helpers */
.officer-select-wrap { position: relative; }
.officer-select-wrap select {
    padding: 7px 10px; border-radius: 9px; border: 1px solid var(--neutral-300);
    font: inherit; font-size: 12.5px; background: #fff; color: var(--ink-900); width: 100%; cursor: pointer;
}
.officer-select-wrap select:focus,
.reassign-form input[type="text"]:focus { outline: none; border-color: var(--red-600); box-shadow: var(--ring); }
.reassign-form { display: flex; flex-direction: column; gap: 6px; min-width: 240px; max-width: 300px; }
.reassign-form-row { display: flex; gap: 6px; align-items: center; }
.reassign-form input[type="text"] {
    padding: 6px 9px; border-radius: 9px; border: 1px solid var(--line); background: var(--neutral-50);
    font: inherit; font-size: 11.5px; width: 100%; color: var(--ink-700);
}
.workload-badge {
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--neutral-100); color: var(--ink-500); font-size: 10px; font-weight: 700;
    border-radius: 99px; padding: 1px 7px; margin-left: 5px; vertical-align: middle; white-space: nowrap;
}
.workload-badge.heavy { background: var(--red-50); color: var(--red-700); }
tr.unassigned-row td:first-child { box-shadow: inset 3px 0 0 var(--red-600); }
.unassigned-flag { color: var(--red-600); font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; }
.unassigned-flag::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--red-600); }
.assign-table td { vertical-align: middle; }
.assign-table td.company-cell { min-width: 200px; }
.assign-table .row-sub { display: block; margin-top: 2px; }
.quote-pill { padding: 2px 8px !important; font-size: 10.5px !important; margin-left: 4px; border: 1px solid var(--line); border-radius: 999px !important; }
.assign-hero-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: auto; padding-top: 18px; }
.assign-hero-stats .stat { padding: 10px 12px; }
.workload-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px 32px; margin-top: 6px; }
@media (max-width: 1100px) { .workload-list { grid-template-columns: 1fr; } }
.workload-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 10px; align-items: center; }
.workload-row .wl-name { font-size: 13px; font-weight: 600; color: var(--ink-900); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.workload-row .wl-name small { font-weight: 500; color: var(--ink-500); font-size: 11.5px; margin-left: 4px; }
.workload-row .wl-n { font-size: 12px; font-weight: 700; color: var(--ink-700); }
.workload-row .meter { grid-column: 1 / -1; height: 8px; margin-top: 2px; }
.workload-row .meter > span { background: var(--navy-600); }
.workload-row.heavy .meter > span { background: var(--red-600); }
.assign-head .filter-chips { justify-content: flex-end; }
@media (max-width: 760px) {
    .assign-head .filter-chips { justify-content: flex-start; flex-wrap: nowrap; overflow-x: auto; max-width: 100%; padding-bottom: 4px; }
    .assign-head .filter-chip { white-space: nowrap; }
}
</style>

<div class="page-head">
    <div>
        <div class="eyebrow">Business Development</div>
        <h1>Lead Assignment</h1>
        <p>Assign or reassign open leads to BD Officers, Managers, or Management (FR-1.4).</p>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert success"><?php echo icon('check'); ?><span><?php echo e($success); ?></span></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert error"><?php echo icon('alert'); ?><span><?php echo e($error); ?></span></div>
<?php endif; ?>

<div class="bento">
    <!-- Hero: what needs an owner -->
    <section class="tile tile--navy span-4 flex-col">
        <div class="tile-head" style="margin-bottom:0;">
            <span class="tile-label">Unassigned leads</span>
            <span class="tile-icon"><?php echo icon('target'); ?></span>
        </div>
        <div class="tile-value"><?php echo $unassignedCount; ?></div>
        <div class="tile-sub"><?php echo $unassignedCount > 0 ? 'Open leads waiting for an owner' : 'Every open lead has an owner'; ?></div>
        <div class="stat-row assign-hero-stats">
            <div class="stat"><div class="v"><?php echo $totalLeads; ?></div><div class="l">Open leads</div></div>
            <div class="stat"><div class="v"><?php echo $assignedCount; ?></div><div class="l">Assigned</div></div>
            <div class="stat"><div class="v"><?php echo count($officers); ?></div><div class="l">Officers</div></div>
        </div>
    </section>

    <!-- Team workload -->
    <section class="tile span-8">
        <div class="tile-head">
            <h2 class="tile-title">Team workload</h2>
            <span class="tile-sub" style="margin:0;">Open leads per person · 10+ is flagged heavy</span>
        </div>
        <?php if (empty($workloadRows)): ?>
            <p class="tile-sub">No active officers.</p>
        <?php else: ?>
        <div class="workload-list">
            <?php foreach ($workloadRows as $w): ?>
                <div class="workload-row <?php echo $w['n'] >= 10 ? 'heavy' : ''; ?>">
                    <div class="wl-name"><?php echo e($w['name']); ?><small><?php echo e($w['id'] === $uid ? 'You' : ($roleLabel[$w['role']] ?? '')); ?></small></div>
                    <div class="wl-n"><?php echo $w['n']; ?></div>
                    <div class="meter"><span style="width:<?php echo (int) round($w['n'] / $maxWorkload * 100); ?>%;"></span></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <section class="tile span-12">
        <div class="tile-head assign-head">
            <h2 class="tile-title">Open leads <span class="badge navy"><?php echo $totalLeads; ?></span></h2>
            <!-- Filter tabs -->
            <div class="filter-chips">
                <button class="filter-chip filter-tab active" data-filter="all">All leads</button>
                <button class="filter-chip filter-tab" data-filter="unassigned">Unassigned only</button>
                <?php foreach (['New','Contacted','Needs Assessment','Proposal Sent','Negotiation'] as $stage): ?>
                    <button class="filter-chip filter-tab" data-filter="<?php echo e($stage); ?>"><?php echo e($stage); ?></button>
                <?php endforeach; ?>
            </div>
        </div>

    <?php if (empty($leads)): ?>
        <div class="empty-state">
            <div class="icon-button"><?php echo icon('target'); ?></div>
            <p>No open leads to assign right now.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table id="leadsTable" class="assign-table">
                <thead>
                    <tr>
                        <th>Company</th>
                        <th>Stage</th>
                        <th>Value</th>
                        <th>Currently Assigned</th>
                        <th>Reassign To</th>
                        <th>Trail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leads as $l):
                        $isUnassigned = $l['assigned_to'] === null;
                    ?>
                        <tr class="lead-row <?php echo $isUnassigned ? 'unassigned-row' : ''; ?>"
                            data-stage="<?php echo e($l['stage']); ?>"
                            data-assigned="<?php echo $isUnassigned ? 'unassigned' : 'assigned'; ?>">

                            <!-- Company -->
                            <td class="company-cell">
                                <span class="row-name"><?php echo e($l['company_name']); ?></span>
                                <?php if (!empty($l['quote_details'])): ?>
                                    <button
                                        type="button"
                                        class="btn-ghost quote-view-btn quote-pill"
                                        title="View full quote request"
                                        data-company="<?php echo e($l['company_name']); ?>"
                                        data-details="<?php echo e($l['quote_details']); ?>"
                                        data-phone="<?php echo e($l['contact_phone'] ?? ''); ?>"
                                        data-email="<?php echo e($l['contact_email'] ?? ''); ?>"
                                        data-contact="<?php echo e($l['contact_person'] ?? ''); ?>">
                                        📋 Quote
                                    </button>
                                <?php endif; ?>
                                <span class="row-sub">
                                    <?php echo e($l['reference_number']); ?>
                                    <?php if ($l['lead_source']): ?> · <?php echo e($l['lead_source']); ?><?php endif; ?>
                                </span>
                            </td>

                            <!-- Stage -->
                            <td>
                                <span class="badge <?php echo e($stageBadgeClass[$l['stage']] ?? ''); ?>">
                                    <?php echo e($l['stage']); ?>
                                </span>
                            </td>

                            <!-- Value -->
                            <td style="white-space:nowrap; font-weight:600; color:var(--ink-900);">₦<?php echo number_format((float) ($l['estimated_value_ngn'] ?? 0)); ?></td>

                            <!-- Currently assigned -->
                            <td>
                                <?php if ($isUnassigned): ?>
                                    <span class="unassigned-flag">Unassigned</span>
                                <?php else: ?>
                                    <?php echo e($l['officer_name']); ?>
                                    <?php $wl = $workload[(int) $l['assigned_to']] ?? 0; ?>
                                    <span class="workload-badge <?php echo $wl >= 10 ? 'heavy' : ''; ?>"
                                          title="<?php echo $wl; ?> open lead<?php echo $wl !== 1 ? 's' : ''; ?>">
                                        <?php echo $wl; ?> lead<?php echo $wl !== 1 ? 's' : ''; ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Reassign form -->
                            <td>
                                <form method="POST" class="reassign-form">
                                    <input type="hidden" name="action" value="reassign">
                                    <input type="hidden" name="lead_id" value="<?php echo (int) $l['id']; ?>">
                                    <div class="reassign-form-row">
                                        <div class="officer-select-wrap" style="flex:1;">
                                            <select name="assigned_to" aria-label="Assign to">
                                                <?php if ($isUnassigned): ?>
                                                    <option value="" disabled selected>— Select officer —</option>
                                                <?php endif; ?>
                                                <?php foreach ($officers as $o):
                                                    $label = e($o['name']);
                                                    $suffix = '';
                                                    if ((int) $o['id'] === $uid)                    $suffix = ' (You)';
                                                    elseif ($o['role'] === 'bd_manager')             $suffix = ' (Manager)';
                                                    elseif ($o['role'] === 'management')             $suffix = ' (Management)';
                                                    elseif ($o['role'] === 'account_manager')        $suffix = ' (CRM)';
                                                    $owl = $workload[(int) $o['id']] ?? 0;
                                                ?>
                                                    <option
                                                        value="<?php echo (int) $o['id']; ?>"
                                                        <?php echo (int) $o['id'] === (int) $l['assigned_to'] ? 'selected' : ''; ?>
                                                        data-workload="<?php echo $owl; ?>">
                                                        <?php echo $label . $suffix; ?> — <?php echo $owl; ?> lead<?php echo $owl !== 1 ? 's' : ''; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn-navy btn-sm" style="flex-shrink:0;" title="Save assignment">
                                            <?php echo icon('check'); ?>
                                        </button>
                                    </div>
                                    <input
                                        type="text"
                                        name="handover_note"
                                        placeholder="Handover note — where you left off (optional)"
                                        autocomplete="off">
                                </form>
                            </td>

                            <!-- Trail -->
                            <td>
                                <button
                                    type="button"
                                    class="btn-ghost"
                                    style="padding:6px 9px;"
                                    title="View this lead's full trail"
                                    onclick="viewLeadHistory(<?php echo (int) $l['id']; ?>, '<?php echo e(addslashes($l['company_name'])); ?>')">
                                    <?php echo icon('clock'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    </section>
</div>

<!-- Quote request modal -->
<div id="quoteModal" style="display:none; position:fixed; inset:0; background:rgba(11,21,36,0.45); backdrop-filter:blur(4px); z-index:100; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:var(--r-tile); border:1px solid var(--line); padding:24px; max-width:520px; width:100%; max-height:82vh; overflow-y:auto; box-shadow:var(--shadow-pop);">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
            <h3 style="font-family:'Libre Franklin',sans-serif; font-size:16px;">
                Quote Request — <span id="quoteModalCompany"></span>
            </h3>
            <button type="button" onclick="document.getElementById('quoteModal').style.display='none'" class="btn-ghost" style="padding:4px 8px;">✕</button>
        </div>
        <div id="quoteModalContact" style="font-size:12.5px; color:var(--ink-500); margin:8px 0 16px; padding-bottom:12px; border-bottom:1px solid var(--neutral-200);"></div>
        <div id="quoteModalBody" style="display:grid; grid-template-columns:1fr 1fr; gap:12px 18px; font-size:13px;"></div>
        <div class="form-actions">
            <button type="button" class="btn-primary" onclick="document.getElementById('quoteModal').style.display='none'">Close</button>
        </div>
    </div>
</div>

<script>
// ── Filter tabs ──────────────────────────────────────────────────────────
document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');

        const filter = tab.dataset.filter;
        document.querySelectorAll('.lead-row').forEach(row => {
            if (filter === 'all') {
                row.style.display = '';
            } else if (filter === 'unassigned') {
                row.style.display = row.dataset.assigned === 'unassigned' ? '' : 'none';
            } else {
                row.style.display = row.dataset.stage === filter ? '' : 'none';
            }
        });
    });
});

// ── Quote request modal ──────────────────────────────────────────────────
document.querySelectorAll('.quote-view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('quoteModalCompany').textContent = btn.dataset.company;

        const contactBits = [];
        if (btn.dataset.contact) contactBits.push(btn.dataset.contact);
        if (btn.dataset.email)   contactBits.push(btn.dataset.email);
        if (btn.dataset.phone)   contactBits.push(btn.dataset.phone);
        document.getElementById('quoteModalContact').textContent =
            contactBits.join(' · ') || 'No contact details on file';

        const body = document.getElementById('quoteModalBody');
        body.innerHTML = '';
        let fields = {};
        try {
            fields = JSON.parse(btn.dataset.details);
        } catch (e) {
            body.style.gridTemplateColumns = '1fr';
            body.style.whiteSpace = 'pre-wrap';
            body.textContent = btn.dataset.details;
            document.getElementById('quoteModal').style.display = 'flex';
            return;
        }

        body.style.gridTemplateColumns = '1fr 1fr';
        body.style.whiteSpace = 'normal';
        Object.keys(fields).forEach(label => {
            const value = fields[label];
            if (!value) return;
            const wide  = label === 'Business description' || label === 'Address';
            const item  = document.createElement('div');
            if (wide) item.style.gridColumn = '1 / -1';
            item.innerHTML = '<div class="row-sub"></div><div style="font-weight:600; margin-top:2px; color:var(--ink-900);"></div>';
            item.querySelector('.row-sub').textContent         = label;
            item.querySelector('div:last-child').textContent   = value;
            body.appendChild(item);
        });

        document.getElementById('quoteModal').style.display = 'flex';
    });
});

// ── Lead history (unchanged — calls existing endpoint) ───────────────────
function viewLeadHistory(leadId, companyName) {
    // Defer to leads.php history drawer / modal as implemented elsewhere.
    window.location.href = 'leads.php?id=' + leadId + '#history';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>