<?php
/**
 * IEMA CRMOps — Dashboard
 * -----------------------------------------------------------------------
 * ONE dashboard shared by every role. Unlike the earlier version, every
 * number and row below comes from real queries against the schema in
 * schema.sql (leads, clients, proposals, interactions, users, audit_log)
 * — nothing here is hardcoded mock data.
 *
 * Pipeline stages match PRD FR-1.2 exactly:
 *   New, Contacted, Needs Assessment, Proposal Sent, Negotiation, Won, Lost
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';
require_login();

// (The old ?preview_role= switch was removed: while APP_ENV was
// 'development' it let any logged-in user rewrite their own session role.)

$role = current_role();
$uid  = (int) ($_SESSION['user_id'] ?? 0);

const STAGE_ORDER = ['New', 'Contacted', 'Needs Assessment', 'Proposal Sent', 'Negotiation', 'Won', 'Lost'];
const OPEN_STAGES = ['New', 'Contacted', 'Needs Assessment', 'Proposal Sent', 'Negotiation'];

$stageBadgeClass = [
    'New' => 'stage-lead', 'Contacted' => 'stage-lead', 'Needs Assessment' => 'stage-lead',
    'Proposal Sent' => 'stage-proposal', 'Negotiation' => 'stage-negotiation',
    'Won' => 'stage-client', 'Lost' => 'stage-lost',
    'active' => 'stage-client', 'suspended' => 'stage-lost',
];

function fmt_ngn($value): string
{
    if ($value === null) return '₦0';
    $value = (float) $value;
    if ($value >= 1_000_000) return '₦' . rtrim(rtrim(number_format($value / 1_000_000, 1), '0'), '.') . 'M';
    if ($value >= 1_000) return '₦' . rtrim(rtrim(number_format($value / 1_000, 1), '0'), '.') . 'K';
    return '₦' . number_format($value);
}

function time_ago(?string $datetime): string
{
    if (!$datetime) return '—';
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 172800) return 'Yesterday';
    return floor($diff / 86400) . ' days ago';
}

/** Stage counts for the funnel, scoped by an optional WHERE clause. */
function stage_counts(PDO $pdo, string $whereSql = '', array $params = []): array
{
    $sql = "SELECT stage, COUNT(*) AS n FROM leads $whereSql GROUP BY stage";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $counts = array_fill_keys(STAGE_ORDER, 0);
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['stage']] = (int) $row['n'];
    }
    $out = [];
    foreach (STAGE_ORDER as $stage) {
        $out[] = ['label' => $stage, 'value' => $counts[$stage]];
    }
    return $out;
}

$openStagesPlaceholders = implode(',', array_fill(0, count(OPEN_STAGES), '?'));

// ---------------------------------------------------------------------
// Role-scoped data
// ---------------------------------------------------------------------
if ($role === 'bd_officer') {

    $kpis = [];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE assigned_to = ? AND stage IN ($openStagesPlaceholders)");
    $stmt->execute(array_merge([$uid], OPEN_STAGES));
    $kpis[] = ['label' => 'My Active Leads', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'Open pipeline', 'dir' => 'up', 'icon' => 'target'];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM proposals p JOIN leads l ON l.id = p.lead_id WHERE l.assigned_to = ? AND p.status NOT IN ("Signed","Rejected")');
    $stmt->execute([$uid]);
    $kpis[] = ['label' => 'Proposals In Progress', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'Awaiting outcome', 'dir' => 'up', 'icon' => 'file'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(estimated_value_ngn),0) FROM leads WHERE assigned_to = ? AND stage IN ($openStagesPlaceholders)");
    $stmt->execute(array_merge([$uid], OPEN_STAGES));
    $kpis[] = ['label' => 'My Pipeline Value', 'value' => fmt_ngn($stmt->fetchColumn()), 'delta' => 'Open leads', 'dir' => 'up', 'icon' => 'trend'];

    $stmt = $pdo->prepare('SELECT stage, COUNT(*) AS n FROM leads WHERE assigned_to = ? AND stage IN ("Won","Lost") GROUP BY stage');
    $stmt->execute([$uid]);
    $wl = array_fill_keys(['Won', 'Lost'], 0);
    foreach ($stmt->fetchAll() as $r) { $wl[$r['stage']] = (int) $r['n']; }
    $totalClosed = $wl['Won'] + $wl['Lost'];
    $winRate = $totalClosed > 0 ? round(($wl['Won'] / $totalClosed) * 100) : 0;
    $kpis[] = ['label' => 'My Win Rate', 'value' => $winRate . '%', 'delta' => $wl['Won'] . ' won / ' . $wl['Lost'] . ' lost', 'dir' => $winRate >= 30 ? 'up' : 'down', 'icon' => 'chart'];

    $funnel = stage_counts($pdo, 'WHERE assigned_to = ?', [$uid]);

    $stmt = $pdo->prepare("
        SELECT l.reference_number, l.company_name, l.stage, l.estimated_value_ngn,
               (SELECT MAX(interaction_date) FROM interactions i WHERE i.lead_id = l.id) AS last_contact
        FROM leads l WHERE l.assigned_to = ?
        ORDER BY l.updated_at DESC LIMIT 6
    ");
    $stmt->execute([$uid]);
    $leadRows = $stmt->fetchAll();
    $tableConfig = [
        'title' => 'My Leads', 'link' => 'leads.php',
        'cols'  => ['Company', 'Stage', 'Value', 'Last Contact'],
        'rows'  => array_map(fn($r) => [$r['company_name'], $r['stage'], fmt_ngn($r['estimated_value_ngn']), time_ago($r['last_contact'])], $leadRows),
    ];

    $stmt = $pdo->prepare("
        SELECT i.type, i.interaction_date, i.notes, COALESCE(l.company_name, c.company_name) AS company
        FROM interactions i
        LEFT JOIN leads l ON l.id = i.lead_id
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE i.logged_by = ?
        ORDER BY i.interaction_date DESC LIMIT 5
    ");
    $stmt->execute([$uid]);
    $activityRows = $stmt->fetchAll();
    $activity = array_map(fn($r) => [
        'text' => e($r['type']) . ' logged for <b>' . e($r['company'] ?? 'Unknown') . '</b>',
        'time' => time_ago($r['interaction_date']),
    ], $activityRows);

    $quickAction = ['label' => 'New Lead', 'icon' => 'plus', 'href' => 'leads.php?new=1'];

} elseif ($role === 'bd_manager') {

    $kpis = [];
    $stmt = $pdo->query("SELECT COALESCE(SUM(estimated_value_ngn),0) FROM leads WHERE stage IN ('" . implode("','", OPEN_STAGES) . "')");
    $kpis[] = ['label' => 'Team Pipeline Value', 'value' => fmt_ngn($stmt->fetchColumn()), 'delta' => 'All open leads', 'dir' => 'up', 'icon' => 'trend'];

    $stmt = $pdo->query("SELECT COUNT(*) FROM leads WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
    $kpis[] = ['label' => 'Leads Created (MTD)', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'This month', 'dir' => 'up', 'icon' => 'target'];

    $stmt = $pdo->query("SELECT stage, COUNT(*) AS n FROM leads WHERE stage IN ('Won','Lost') GROUP BY stage");
    $wl = array_fill_keys(['Won', 'Lost'], 0);
    foreach ($stmt->fetchAll() as $r) { $wl[$r['stage']] = (int) $r['n']; }
    $totalClosed = $wl['Won'] + $wl['Lost'];
    $winRate = $totalClosed > 0 ? round(($wl['Won'] / $totalClosed) * 100) : 0;
    $kpis[] = ['label' => 'Team Win Rate', 'value' => $winRate . '%', 'delta' => $wl['Won'] . ' won / ' . $wl['Lost'] . ' lost', 'dir' => $winRate >= 30 ? 'up' : 'down', 'icon' => 'chart'];

    $stmt = $pdo->query('SELECT COUNT(*) FROM proposals WHERE status NOT IN ("Signed","Rejected")');
    $kpis[] = ['label' => 'Open Proposals', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'Awaiting outcome', 'dir' => 'up', 'icon' => 'file'];

    $funnel = stage_counts($pdo);

    $stmt = $pdo->query("
        SELECT u.name,
               COUNT(CASE WHEN l.stage IN ('" . implode("','", OPEN_STAGES) . "') THEN 1 END) AS active_leads,
               COALESCE(SUM(CASE WHEN l.stage IN ('" . implode("','", OPEN_STAGES) . "') THEN l.estimated_value_ngn ELSE 0 END),0) AS pipeline_value,
               SUM(CASE WHEN l.stage = 'Won' THEN 1 ELSE 0 END) AS won,
               SUM(CASE WHEN l.stage = 'Lost' THEN 1 ELSE 0 END) AS lost
        FROM users u
        LEFT JOIN leads l ON l.assigned_to = u.id
        WHERE u.role = 'bd_officer'
        GROUP BY u.id, u.name
        ORDER BY pipeline_value DESC
    ");
    $teamRows = $stmt->fetchAll();
    $tableConfig = [
        'title' => 'Team Pipeline', 'link' => 'pipeline.php',
        'cols'  => ['BD Officer', 'Active Leads', 'Pipeline Value', 'Win Rate'],
        'rows'  => array_map(function ($r) {
            $closed = $r['won'] + $r['lost'];
            $wr = $closed > 0 ? round(($r['won'] / $closed) * 100) : 0;
            return [$r['name'], (string) $r['active_leads'], fmt_ngn($r['pipeline_value']), $wr . '%'];
        }, $teamRows),
    ];

    $stmt = $pdo->query("
        SELECT i.type, i.interaction_date, u.name AS officer, COALESCE(l.company_name, c.company_name) AS company
        FROM interactions i
        LEFT JOIN leads l ON l.id = i.lead_id
        LEFT JOIN clients c ON c.id = i.client_id
        LEFT JOIN users u ON u.id = i.logged_by
        ORDER BY i.interaction_date DESC LIMIT 5
    ");
    $activityRows = $stmt->fetchAll();
    $activity = array_map(fn($r) => [
        'text' => '<b>' . e($r['officer'] ?? 'A team member') . '</b> logged a ' . strtolower(e($r['type'])) . ' with <b>' . e($r['company'] ?? 'a client') . '</b>',
        'time' => time_ago($r['interaction_date']),
    ], $activityRows);

    $quickAction = ['label' => 'Assign Leads', 'icon' => 'target', 'href' => 'assign.php'];

} elseif ($role === 'management') {

    $kpis = [];
    $stmt = $pdo->query("SELECT COALESCE(SUM(estimated_value_ngn),0) FROM leads WHERE stage IN ('" . implode("','", OPEN_STAGES) . "')");
    $kpis[] = ['label' => 'Total Pipeline Value', 'value' => fmt_ngn($stmt->fetchColumn()), 'delta' => 'All open leads', 'dir' => 'up', 'icon' => 'trend'];

    $stmt = $pdo->query("SELECT COUNT(*) FROM clients WHERE status = 'active'");
    $kpis[] = ['label' => 'Active Clients', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'Signed & onboarded', 'dir' => 'up', 'icon' => 'users'];

    $stmt = $pdo->query("SELECT COALESCE(SUM(value_ngn),0) FROM proposals WHERE status = 'Signed' AND signed_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
    $kpis[] = ['label' => 'Signed Value (MTD)', 'value' => fmt_ngn($stmt->fetchColumn()), 'delta' => 'This month', 'dir' => 'up', 'icon' => 'chart'];

    $stmt = $pdo->query("SELECT COUNT(*) FROM renewal_opportunities WHERE renewal_date <= DATE_ADD(NOW(), INTERVAL 90 DAY) AND follow_up_status != 'Completed'");
    $kpis[] = ['label' => 'Renewals Due (90d)', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'Needs follow-up', 'dir' => 'down', 'icon' => 'refresh'];

    $funnel = stage_counts($pdo);

    // New leads (stage = 'New') — most recent 6
    $stmt = $pdo->query("
        SELECT l.reference_number, l.company_name, l.estimated_value_ngn,
               l.created_at, u.name AS owner
        FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
        WHERE l.stage = 'New'
        ORDER BY l.created_at DESC LIMIT 6
    ");
    $mgmtNewLeadRows = $stmt->fetchAll();
    $tableConfig = [
        'title' => 'New Leads',
        'link'  => 'leads.php?stage=New',
        'cols'  => ['Company', 'Value', 'Owner', 'Received'],
        'rows'  => array_map(
            fn($r) => [
                $r['company_name'],
                fmt_ngn($r['estimated_value_ngn']),
                $r['owner'] ?? '—',
                time_ago($r['created_at']),
            ],
            $mgmtNewLeadRows
        ),
    ];

    // Quotes / proposals — open (not yet Signed or Rejected)
    $stmt = $pdo->query("
        SELECT p.reference_number, p.value_ngn, p.status,
               COALESCE(l.company_name, c.company_name) AS company
        FROM proposals p
        LEFT JOIN leads l ON l.id = p.lead_id
        LEFT JOIN clients c ON c.id = p.client_id
        WHERE p.status NOT IN ('Signed', 'Rejected')
        ORDER BY p.created_at DESC LIMIT 6
    ");
    $mgmtQuoteRows = $stmt->fetchAll();
    $tableConfig2 = [
        'title' => 'Quotes & Proposals',
        'link'  => 'proposals.php',
        'cols'  => ['Company', 'Ref #', 'Value', 'Status'],
        'rows'  => array_map(
            fn($r) => [
                $r['company'] ?? '—',
                $r['reference_number'] ?? '—',
                fmt_ngn($r['value_ngn']),
                $r['status'],
            ],
            $mgmtQuoteRows
        ),
    ];

    $stmt = $pdo->query("
        SELECT i.type, i.interaction_date, COALESCE(l.company_name, c.company_name) AS company
        FROM interactions i
        LEFT JOIN leads l ON l.id = i.lead_id
        LEFT JOIN clients c ON c.id = i.client_id
        ORDER BY i.interaction_date DESC LIMIT 5
    ");
    $activityRows = $stmt->fetchAll();
    $activity = array_map(fn($r) => [
        'text' => e($r['type']) . ' logged with <b>' . e($r['company'] ?? 'a client') . '</b>',
        'time' => time_ago($r['interaction_date']),
    ], $activityRows);

    $quickAction = ['label' => 'Create Invoice', 'icon' => 'briefcase', 'href' => 'invoices.php?new=1'];

} elseif ($role === 'account_manager') {

    $kpis = [];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE assigned_crm_id = ? AND status = 'active'");
    $stmt->execute([$uid]);
    $kpis[] = ['label' => 'My Active Accounts', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'Owned accounts', 'dir' => 'up', 'icon' => 'users'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE assigned_crm_id = ? AND health_status = 'Critical'");
    $stmt->execute([$uid]);
    $criticalCount = (int) $stmt->fetchColumn();
    $kpis[] = ['label' => 'Critical Health', 'value' => (string) $criticalCount, 'delta' => 'Needs attention', 'dir' => $criticalCount > 0 ? 'down' : 'up', 'icon' => 'alert'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE assigned_crm_id = ? AND health_status = 'At Risk'");
    $stmt->execute([$uid]);
    $kpis[] = ['label' => 'At Risk', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'Monitor closely', 'dir' => 'down', 'icon' => 'clock'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM client_escalations e JOIN clients c ON c.id = e.client_id WHERE c.assigned_crm_id = ? AND e.status = 'Open'");
    $stmt->execute([$uid]);
    $kpis[] = ['label' => 'Open Escalations', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'Awaiting resolution', 'dir' => 'down', 'icon' => 'alert'];

    $funnel = null;

    $stmt = $pdo->prepare("
        SELECT c.company_name, c.health_status,
               (SELECT MAX(i.interaction_date) FROM interactions i WHERE i.client_id = c.id) AS last_touch
        FROM clients c WHERE c.assigned_crm_id = ?
        ORDER BY FIELD(c.health_status, 'Critical', 'At Risk', 'Good') LIMIT 8
    ");
    $stmt->execute([$uid]);
    $portfolioRows = $stmt->fetchAll();
    $tableConfig = [
        'title' => 'My Client Portfolio', 'link' => 'accounts.php',
        'cols'  => ['Client', 'Health', 'Last Touch'],
        'rows'  => array_map(fn($r) => [$r['company_name'], $r['health_status'], $r['last_touch'] ? time_ago($r['last_touch']) : 'Never'], $portfolioRows),
    ];

    $stmt = $pdo->prepare("
        SELECT i.type, i.interaction_date, c.company_name
        FROM interactions i JOIN clients c ON c.id = i.client_id
        WHERE c.assigned_crm_id = ? ORDER BY i.interaction_date DESC LIMIT 5
    ");
    $stmt->execute([$uid]);
    $activityRows = $stmt->fetchAll();
    $activity = array_map(fn($r) => [
        'text' => e($r['type']) . ' logged with <b>' . e($r['company_name']) . '</b>',
        'time' => time_ago($r['interaction_date']),
    ], $activityRows);

    $quickAction = ['label' => 'My Accounts', 'icon' => 'users', 'href' => 'accounts.php'];

} elseif ($role === 'it_admin') {

    $kpis = [];
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'");
    $kpis[] = ['label' => 'Active Users', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'Across all roles', 'dir' => 'up', 'icon' => 'users'];

    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'suspended'");
    $kpis[] = ['label' => 'Suspended Accounts', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'Needs review', 'dir' => 'down', 'icon' => 'settings'];

    $stmt = $pdo->query("SELECT COUNT(*) FROM leads");
    $kpis[] = ['label' => 'Total System Leads', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'All records', 'dir' => 'up', 'icon' => 'target'];

    $stmt = $pdo->query("SELECT COUNT(*) FROM audit_log WHERE created_at >= CURDATE()");
    $kpis[] = ['label' => 'Audit Events Today', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'Logged actions', 'dir' => 'up', 'icon' => 'file'];

    $funnel = null;

    $stmt = $pdo->query('SELECT id, name, role, status, last_login_at FROM users ORDER BY created_at DESC');
    $userRows = $stmt->fetchAll();
    $roleLabels = ROLES;
    $tableConfig = [
        'title' => 'User Accounts', 'link' => 'users.php',
        'cols'  => ['Name', 'Role', 'Status', 'Last Login'],
        'rows'  => array_map(fn($r) => [$r['name'], $roleLabels[$r['role']] ?? $r['role'], ucfirst($r['status']), $r['last_login_at'] ? time_ago($r['last_login_at']) : 'Never'], $userRows),
    ];

    $stmt = $pdo->query("
        SELECT a.action, a.entity_type, a.details, a.created_at, u.name AS actor
        FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
        ORDER BY a.created_at DESC LIMIT 5
    ");
    $activityRows = $stmt->fetchAll();
    $activity = array_map(fn($r) => [
        'text' => '<b>' . e($r['actor'] ?? 'System') . '</b> — ' . e($r['details'] ?? ($r['action'] . ' ' . $r['entity_type'])),
        'time' => time_ago($r['created_at']),
    ], $activityRows);

    $quickAction = ['label' => 'Add User', 'icon' => 'plus', 'href' => 'users.php?new=1'];

} else { // auditor — deliberately minimal: their own surveillance tasks only

    $kpis = [];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM surveillance_tasks WHERE assigned_to = ? AND status = 'Assigned'");
    $stmt->execute([$uid]);
    $kpis[] = ['label' => 'Assigned To Me', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'Not started', 'dir' => 'up', 'icon' => 'clipboard'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM surveillance_tasks WHERE assigned_to = ? AND status = 'In Progress'");
    $stmt->execute([$uid]);
    $kpis[] = ['label' => 'In Progress', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'Currently working', 'dir' => 'up', 'icon' => 'clock'];

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM surveillance_tasks
        WHERE assigned_to = ? AND status NOT IN ('Completed','Cancelled') AND due_date IS NOT NULL AND due_date < CURDATE()
    ");
    $stmt->execute([$uid]);
    $overdueCount = (int) $stmt->fetchColumn();
    $kpis[] = ['label' => 'Overdue', 'value' => (string) $overdueCount, 'delta' => 'Past due date', 'dir' => $overdueCount > 0 ? 'down' : 'up', 'icon' => 'alert'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM surveillance_tasks WHERE assigned_to = ? AND status = 'Completed'");
    $stmt->execute([$uid]);
    $kpis[] = ['label' => 'Completed', 'value' => (string) $stmt->fetchColumn(), 'delta' => 'All time', 'dir' => 'up', 'icon' => 'chart'];

    $funnel = null;

    $stmt = $pdo->prepare("
        SELECT company_name, status, due_date
        FROM surveillance_tasks
        WHERE assigned_to = ?
        ORDER BY FIELD(status,'Assigned','In Progress','Completed','Cancelled'), due_date IS NULL, due_date ASC
        LIMIT 8
    ");
    $stmt->execute([$uid]);
    $taskRows = $stmt->fetchAll();
    $tableConfig = [
        'title' => 'My Surveillance Tasks', 'link' => 'surveillance-tasks.php',
        'cols'  => ['Company', 'Status', 'Due Date'],
        'rows'  => array_map(fn($r) => [$r['company_name'], $r['status'], $r['due_date'] ? date('M d, Y', strtotime($r['due_date'])) : '—'], $taskRows),
    ];

    $stmt = $pdo->prepare("
        SELECT action, details, created_at
        FROM audit_log
        WHERE user_id = ? AND entity_type = 'surveillance_task'
        ORDER BY created_at DESC LIMIT 5
    ");
    $stmt->execute([$uid]);
    $activityRows = $stmt->fetchAll();
    $activity = array_map(fn($r) => [
        'text' => e($r['details'] ?? $r['action']),
        'time' => time_ago($r['created_at']),
    ], $activityRows);

    $quickAction = ['label' => 'My Tasks', 'icon' => 'clipboard', 'href' => 'surveillance-tasks.php'];
}

// ---------------------------------------------------------------------
// Smart Suggestions — rule-based "next best action" list, not shown to
// IT Admin (not relevant to that role) or Auditor (would leak org-wide
// leads/proposals/renewals data well beyond that role's minimal scope).
// Plain SQL date math, no AI call involved.
// ---------------------------------------------------------------------
$suggestions = [];
if (!in_array($role, ['it_admin', 'auditor'], true)) {

    // ---- CertAdmin renewal alerts for Client Relationship Manager --------
    // Fetches live expiry stats from CertAdmin and surfaces them as
    // actionable suggestions — no manual data entry needed.
    if ($role === 'account_manager') {
        $certStatsUrl = CERTADMIN_API_URL . '?action=stats&api_key=' . urlencode(CERTADMIN_API_KEY);
        $ch = curl_init($certStatsUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . CERTADMIN_API_KEY],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $certResp = curl_exec($ch);
        curl_close($ch);
        $certStats = $certResp ? json_decode($certResp, true) : null;

        if (!empty($certStats['ok']) && !empty($certStats['stats'])) {
            $cs = $certStats['stats'];

            if ((int) $cs['expiring_30'] > 0) {
                $suggestions[] = [
                    'icon' => 'alert', 'urgency' => 'down',
                    'text' => '<b>' . (int) $cs['expiring_30'] . '</b> client certificate' . ((int) $cs['expiring_30'] > 1 ? 's are' : ' is') . ' expiring within <b>30 days</b> — send renewal reminders now',
                    'href' => 'accounts.php?cert_filter=expiring_30',
                ];
            }

            if ((int) $cs['expiring_60'] > (int) $cs['expiring_30']) {
                $additional = (int) $cs['expiring_60'] - (int) $cs['expiring_30'];
                $suggestions[] = [
                    'icon' => 'clock', 'urgency' => 'down',
                    'text' => '<b>' . $additional . '</b> more certificate' . ($additional > 1 ? 's' : '') . ' expiring in <b>31–60 days</b> — start outreach early',
                    'href' => 'accounts.php?cert_filter=expiring_60',
                ];
            }

            if ((int) $cs['expired'] > 0) {
                $suggestions[] = [
                    'icon' => 'alert', 'urgency' => 'down',
                    'text' => '<b>' . (int) $cs['expired'] . '</b> certificate' . ((int) $cs['expired'] > 1 ? 's have' : ' has') . ' already <b>expired</b> — urgent follow-up required',
                    'href' => 'accounts.php?cert_filter=expired',
                ];
            }
        }
    }

    // New leads from the public "Get a Quote" form, still unassigned —
    // surfaced for Manager/Management since they're the ones who assign them.
    if (in_array($role, ['bd_manager', 'management'], true)) {
        $newQuoteStmt = $pdo->query("
            SELECT id, company_name, reference_number, created_at FROM leads
            WHERE lead_source = 'Website - Get a Quote' AND assigned_to IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY created_at DESC
        ");
        $newQuotes = $newQuoteStmt->fetchAll();
        if (!empty($newQuotes)) {
            $names = implode(', ', array_map(fn($q) => e($q['company_name']), array_slice($newQuotes, 0, 3)));
            $more = count($newQuotes) > 3 ? ' and ' . (count($newQuotes) - 3) . ' more' : '';
            $suggestions[] = [
                'icon' => 'send', 'urgency' => 'down',
                'text' => '<b>' . count($newQuotes) . '</b> new quote request' . (count($newQuotes) > 1 ? 's' : '') . " submitted online: $names$more — needs assigning",
                'href' => 'assign.php',
            ];
        }
    }

    // Active clients with no Client Relationship Manager — happens when a
    // proposal is signed before any account_manager user exists yet, or
    // one hasn't been created. Surfaced to Management to assign manually.
    if ($role === 'management') {
        $unassignedCrmStmt = $pdo->query("
            SELECT id, company_name FROM clients
            WHERE status = 'active' AND assigned_crm_id IS NULL
            ORDER BY created_at DESC
        ");
        $unassignedCrmClients = $unassignedCrmStmt->fetchAll();
        if (!empty($unassignedCrmClients)) {
            $names = implode(', ', array_map(fn($c) => e($c['company_name']), array_slice($unassignedCrmClients, 0, 3)));
            $more = count($unassignedCrmClients) > 3 ? ' and ' . (count($unassignedCrmClients) - 3) . ' more' : '';
            $suggestions[] = [
                'icon' => 'users', 'urgency' => 'down',
                'text' => '<b>' . count($unassignedCrmClients) . '</b> active client' . (count($unassignedCrmClients) > 1 ? 's have' : ' has') . " no Client Relationship Manager: $names$more",
                'href' => 'accounts.php',
            ];
        }
    }

    $leadScope = $role === 'bd_officer' ? 'AND assigned_to = ?' : '';
    $leadParams = $role === 'bd_officer' ? [$uid] : [];

    $staleStmt = $pdo->prepare("
        SELECT l.id, l.company_name, l.reference_number,
               COALESCE((SELECT MAX(interaction_date) FROM interactions i WHERE i.lead_id = l.id), l.created_at) AS last_touch
        FROM leads l
        WHERE l.stage IN ('" . implode("','", OPEN_STAGES) . "') $leadScope
        HAVING last_touch <= DATE_SUB(NOW(), INTERVAL 14 DAY)
        ORDER BY last_touch ASC LIMIT 4
    ");
    $staleStmt->execute($leadParams);
    foreach ($staleStmt->fetchAll() as $s) {
        $days = (int) floor((time() - strtotime($s['last_touch'])) / 86400);
        $suggestions[] = [
            'icon' => 'clock', 'urgency' => 'down',
            'text' => '<b>' . e($s['company_name']) . "</b> hasn't had a follow-up in $days days",
            'href' => 'leads.php', 'lead_id' => $s['id'], 'company' => $s['company_name'],
        ];
    }

    $proposalScope = $role === 'bd_officer' ? 'AND l.assigned_to = ?' : '';
    $proposalParams = $role === 'bd_officer' ? [$uid] : [];
    $stalledStmt = $pdo->prepare("
        SELECT p.id, p.lead_id, COALESCE(c.company_name, l.company_name) AS company_name, p.sent_at
        FROM proposals p
        LEFT JOIN clients c ON c.id = p.client_id
        LEFT JOIN leads l ON l.id = p.lead_id
        WHERE p.status IN ('Sent to Client','Under Client Review') AND p.sent_at <= DATE_SUB(NOW(), INTERVAL 7 DAY) $proposalScope
        ORDER BY p.sent_at ASC LIMIT 3
    ");
    $stalledStmt->execute($proposalParams);
    foreach ($stalledStmt->fetchAll() as $s) {
        $days = (int) floor((time() - strtotime($s['sent_at'])) / 86400);
        $suggestions[] = [
            'icon' => 'file', 'urgency' => 'down',
            'text' => 'Proposal to <b>' . e($s['company_name']) . "</b> has had no response in $days days — consider a nudge",
            'href' => 'proposals.php', 'lead_id' => $s['lead_id'], 'company' => $s['company_name'],
        ];
    }

    if ($role !== 'bd_officer') {
        $renewalStmt = $pdo->query("
            SELECT c.company_name, r.renewal_date FROM renewal_opportunities r
            JOIN clients c ON c.id = r.client_id
            WHERE r.renewal_date <= DATE_ADD(NOW(), INTERVAL 30 DAY) AND r.follow_up_status = 'Pending'
            ORDER BY r.renewal_date ASC LIMIT 2
        ");
        foreach ($renewalStmt->fetchAll() as $s) {
            $days = (int) floor((strtotime($s['renewal_date']) - time()) / 86400);
            $suggestions[] = [
                'icon' => 'refresh', 'urgency' => 'down',
                'text' => '<b>' . e($s['company_name']) . "</b> renewal due in $days days — still unflagged",
                'href' => 'renewals.php',
            ];
        }
    }

    if (in_array($role, ['bd_officer', 'bd_manager'], true)) {
        $draftScopeSql = $role === 'bd_officer' ? "AND l.assigned_to = ?" : '';
        $draftScopeParams = $role === 'bd_officer' ? [$uid] : [];
        $draftStmt = $pdo->prepare("
            SELECT COUNT(*) FROM scheduled_email_drafts d JOIN leads l ON l.id = d.lead_id
            WHERE d.status = 'pending_review' $draftScopeSql
        ");
        $draftStmt->execute($draftScopeParams);
        $pendingDraftCount = (int) $draftStmt->fetchColumn();
        if ($pendingDraftCount > 0) {
            array_unshift($suggestions, [
                'icon' => 'mail', 'urgency' => 'down',
                'text' => "<b>$pendingDraftCount</b> AI-drafted follow-up" . ($pendingDraftCount > 1 ? 's are' : ' is') . " waiting for your review",
                'href' => 'ai-drafts.php',
            ]);
        }
    }
}

require_once __DIR__ . '/includes/header.php';

// ── View helpers ─────────────────────────────────────────────────────────
$hourLocal = (int) date('G');
$greeting  = $hourLocal < 12 ? 'Good morning' : ($hourLocal < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', trim($userName))[0] ?: 'there';
$heroKpi   = $kpis[0] ?? null;
$sideKpis  = array_slice($kpis, 1);

// A few role-relevant shortcuts for the "Quick actions" tile (only pages
// this role can already open — taken from the role's own nav).
$quickLinks = array_values(array_filter($navItems, fn($n) => !in_array(basename(parse_url($n['href'], PHP_URL_PATH)), ['dashboard.php'], true)));
$quickLinks = array_slice($quickLinks, 0, 4);

$hasSuggestions = !empty($suggestions);
$showHealth     = $role === 'it_admin';

// Helper to render any tableConfig as a bento tile
function render_table_tile(array $cfg, array $stageBadgeClass, string $spanClass): void { ?>
  <section class="tile flex-col <?php echo e($spanClass); ?>">
    <div class="tile-head">
      <h2 class="tile-title"><?php echo e($cfg['title']); ?>
        <?php if (!empty($cfg['rows'])): ?><span class="badge navy"><?php echo count($cfg['rows']); ?></span><?php endif; ?>
      </h2>
      <a class="link" href="<?php echo e($cfg['link']); ?>">View all →</a>
    </div>
    <?php if (empty($cfg['rows'])): ?>
      <div class="empty-state" style="padding:24px 8px;">No records yet.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><?php foreach ($cfg['cols'] as $col): ?><th><?php echo e($col); ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
          <?php foreach ($cfg['rows'] as $row): ?>
            <tr>
              <?php foreach ($row as $i => $cell): ?>
                <td>
                  <?php if ($i === 0): ?>
                    <span class="row-name"><?php echo e($cell); ?></span>
                  <?php elseif (isset($stageBadgeClass[$cell])): ?>
                    <span class="badge <?php echo e($stageBadgeClass[$cell]); ?>"><?php echo e($cell); ?></span>
                  <?php else: ?>
                    <?php echo e($cell); ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>
<?php }

function render_activity_tile(array $activity, string $spanClass, bool $twoCol = false): void { ?>
  <section class="tile <?php echo e($spanClass); ?>">
    <div class="tile-head">
      <h2 class="tile-title">Recent activity</h2>
    </div>
    <?php if (empty($activity)): ?>
      <div class="empty-state" style="padding:24px 8px;">No activity logged yet.</div>
    <?php else: ?>
      <div class="<?php echo $twoCol ? 'dash-activity-2col' : 'tile-scroll'; ?>">
        <?php foreach ($activity as $item): ?>
          <div class="activity-item">
            <span class="activity-dot"></span>
            <div>
              <div class="activity-text"><?php echo $item['text']; /* built with e() above, <b> tags intentional */ ?></div>
              <div class="activity-time"><?php echo e($item['time']); ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php }
?>

<style>
  .dash-hero{ display:flex; flex-direction:column; gap:18px; min-height:260px; overflow:hidden; }
  .dash-hero::after{
    content:''; position:absolute; right:-60px; bottom:-60px; width:240px; height:240px; border-radius:50%;
    border:36px solid rgba(255,255,255,0.04); pointer-events:none;
  }
  .dash-hero h1{ font-size:26px; font-weight:800; color:#fff; letter-spacing:-0.4px; }
  .dash-hero .hero-date{ font-size:13px; color:rgba(255,255,255,0.65); margin-top:4px; }
  .dash-hero .hero-kpi{ margin-top:auto; display:flex; align-items:flex-end; justify-content:space-between; gap:16px; flex-wrap:wrap; }
  .dash-hero .hero-kpi .tile-value{ font-size:40px; color:#fff; }
  .dash-hero .hero-kpi .tile-label{ color:rgba(255,255,255,0.6); }
  .dash-hero .hero-pill{ display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:700; padding:4px 10px; border-radius:999px; background:rgba(255,255,255,0.1); color:#fff; margin-top:8px; }
  .dash-hero .hero-accent{ width:36px; height:3px; border-radius:3px; background:var(--red-500); }
  .dash-kpi{ display:flex; flex-direction:column; justify-content:space-between; gap:18px; }
  .dash-kpi .tile-value{ margin-top:0; font-size:28px; }
  .dash-kpi .kpi-top{ display:flex; align-items:center; justify-content:space-between; gap:8px; }
  .dash-kpi .kpi-delta{ font-size:11px; font-weight:700; padding:3px 9px; border-radius:999px; white-space:nowrap; }
  .dash-quick a{ display:flex; align-items:center; gap:10px; padding:9px 10px; border-radius:11px; font-size:13px; font-weight:600; color:var(--ink-800); border:1px solid transparent; }
  .dash-quick a:hover{ background:var(--neutral-50); border-color:var(--line); }
  .dash-quick a .icon{ width:16px; height:16px; color:var(--ink-500); }
  .dash-quick a .go{ margin-left:auto; color:var(--ink-400); }
  .sg-item{ display:flex; align-items:center; gap:12px; padding:10px 8px; border-radius:12px; }
  .sg-item:hover{ background:var(--neutral-50); }
  .sg-item + .sg-item{ border-top:1px solid var(--line-soft); }
  .sg-item a.sg-link{ display:flex; align-items:center; gap:12px; flex:1; min-width:0; color:inherit; }
  .sg-icon{ width:32px; height:32px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; background:var(--neutral-100); color:var(--ink-700); }
  .sg-icon.alert{ background:var(--red-50); color:var(--red-600); }
  .sg-icon.clock{ background:var(--amber-50); color:var(--amber); }
  .sg-icon .icon{ width:16px; height:16px; }
  .sg-text{ font-size:13px; color:var(--ink-700); line-height:1.45; }
  .sg-text b{ color:var(--ink-900); font-weight:600; }
  .sg-ai{ flex-shrink:0; display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:600; color:var(--navy-700); padding:6px 9px; border-radius:9px; border:1px solid var(--line); background:#fff; cursor:pointer; }
  .sg-ai:hover{ border-color:var(--neutral-300); }
  .sg-ai .icon{ width:13px; height:13px; }
  .dash-funnel .funnel{ height:auto; min-height:200px; margin-top:14px; }
  .dash-activity-2col{ columns:2 320px; column-gap:28px; }
  .dash-activity-2col .activity-item{ break-inside:avoid; }
  .health-grid{ display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
  @media (max-width:760px){
    .dash-hero .hero-kpi .tile-value{ font-size:32px; }
    .dash-funnel .funnel{ gap:5px; }
    .dash-funnel .funnel-bar .bar-label{ font-size:9.5px; }
  }
</style>

<div class="bento">

  <!-- Hero: greeting + the role's headline number -->
  <section class="tile tile--navy dash-hero span-6 row-2">
    <div>
      <div class="hero-accent"></div>
      <h1 style="margin-top:14px;"><?php echo e($greeting . ', ' . $firstName); ?></h1>
      <div class="hero-date"><?php echo e(date('l, j F Y')); ?> · <?php echo e($roleLabel); ?></div>
    </div>
    <?php if ($heroKpi): ?>
      <div class="hero-kpi">
        <div>
          <div class="tile-label"><?php echo e($heroKpi['label']); ?></div>
          <div class="tile-value"><?php echo e($heroKpi['value']); ?></div>
          <span class="hero-pill"><?php echo icon($heroKpi['icon']); ?> <?php echo e($heroKpi['delta']); ?></span>
        </div>
        <a class="btn-primary" href="<?php echo e($quickAction['href']); ?>">
          <?php echo icon($quickAction['icon']); ?> <?php echo e($quickAction['label']); ?>
        </a>
      </div>
    <?php endif; ?>
  </section>

  <!-- Remaining KPIs -->
  <?php foreach ($sideKpis as $kpi): ?>
    <section class="tile dash-kpi span-3 m-half">
      <div class="kpi-top">
        <div class="tile-icon<?php echo $kpi['dir'] === 'down' ? '' : ' navy'; ?>"><?php echo icon($kpi['icon']); ?></div>
        <span class="kpi-delta <?php echo e($kpi['dir']); ?>"><?php echo e($kpi['delta']); ?></span>
      </div>
      <div>
        <div class="tile-value"><?php echo e($kpi['value']); ?></div>
        <div class="tile-sub"><?php echo e($kpi['label']); ?></div>
      </div>
    </section>
  <?php endforeach; ?>

  <!-- Quick actions -->
  <section class="tile dash-quick span-3 m-half pad-sm">
    <div class="tile-label" style="padding:4px 10px 6px;">Quick actions</div>
    <?php foreach ($quickLinks as $ql): ?>
      <a href="<?php echo e($ql['href']); ?>"><?php echo icon($ql['icon']); ?> <?php echo e($ql['label']); ?> <span class="go">→</span></a>
    <?php endforeach; ?>
  </section>

  <!-- Smart suggestions -->
  <?php if ($hasSuggestions): ?>
  <section class="tile span-7">
    <div class="tile-head">
      <h2 class="tile-title">Smart suggestions <span class="badge red"><?php echo count($suggestions); ?></span></h2>
      <span class="row-sub">Rule-based · AI can explain, never auto-acts</span>
    </div>
    <div class="tile-scroll" style="max-height:340px;">
      <?php foreach ($suggestions as $sg): ?>
        <div class="sg-item">
          <a class="sg-link" href="<?php echo e($sg['href']); ?>">
            <span class="sg-icon <?php echo e($sg['icon']); ?>"><?php echo icon($sg['icon']); ?></span>
            <span class="sg-text"><?php echo $sg['text']; /* built with e() above, <b> tags intentional */ ?></span>
          </a>
          <?php if (!empty($sg['lead_id'])): ?>
            <button type="button" class="sg-ai" title="AI: explain and suggest the next action"
              data-lead-id="<?php echo (int) $sg['lead_id']; ?>" data-company="<?php echo e($sg['company']); ?>"
              onclick="explainSuggestion(this.dataset.leadId, this.dataset.company)">
              <?php echo icon('send'); ?> Explain
            </button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Pipeline funnel / system health / activity -->
  <?php $sideSpan = $hasSuggestions ? 'span-5' : 'span-12'; ?>
  <?php if ($funnel !== null): $funnelMax = max(1, max(array_column($funnel, 'value'))); $funnelTotal = array_sum(array_column($funnel, 'value')); ?>
    <section class="tile dash-funnel <?php echo $sideSpan; ?>">
      <div class="tile-head">
        <h2 class="tile-title">Pipeline by stage <span class="badge navy"><?php echo (int) $funnelTotal; ?> leads</span></h2>
        <a class="link" href="<?php echo in_array('pipeline.php', array_map(fn($n) => basename(parse_url($n['href'], PHP_URL_PATH)), $navItems), true) ? 'pipeline.php' : 'intelligence.php'; ?>">Open pipeline →</a>
      </div>
      <div class="funnel">
        <?php foreach ($funnel as $stage): $h = max(10, round(($stage['value'] / $funnelMax) * 150)); ?>
          <div class="funnel-bar">
            <span class="bar-value"><?php echo e((string) $stage['value']); ?></span>
            <div class="bar" style="height:<?php echo (int) $h; ?>px;<?php echo $stage['label'] === 'Won' ? 'background:linear-gradient(180deg,#22C55E,#15803D);' : ($stage['label'] === 'Lost' ? 'background:var(--neutral-300);' : ''); ?>"></div>
            <span class="bar-label"><?php echo e($stage['label']); ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php elseif ($showHealth): ?>
    <section class="tile <?php echo $sideSpan; ?>">
      <div class="tile-head">
        <h2 class="tile-title">System health</h2>
        <a class="link" href="settings.php">System settings →</a>
      </div>
      <div class="health-grid">
        <div class="stat"><div class="v" style="color:var(--green);font-size:16px;">Connected</div><div class="l">Database</div></div>
        <div class="stat"><div class="v" style="font-size:16px;"><?php echo e(APP_ENV); ?></div><div class="l">App environment</div></div>
        <div class="stat"><div class="v" style="font-size:16px;"><?php echo e(PHP_VERSION); ?></div><div class="l">PHP version</div></div>
        <div class="stat"><div class="v" style="font-size:16px;"><?php echo date('H:i'); ?></div><div class="l">Server time</div></div>
      </div>
    </section>
  <?php else: ?>
    <?php render_activity_tile($activity, $sideSpan, !$hasSuggestions); ?>
  <?php endif; ?>

  <!-- Tables + activity -->
  <?php if ($role === 'management'): ?>
    <?php render_table_tile($tableConfig, $stageBadgeClass, 'span-6'); ?>
    <?php render_table_tile($tableConfig2, $stageBadgeClass, 'span-6'); ?>
    <?php render_activity_tile($activity, 'span-12', true); ?>
  <?php elseif ($funnel !== null || $showHealth): ?>
    <?php render_table_tile($tableConfig, $stageBadgeClass, 'span-8'); ?>
    <?php render_activity_tile($activity, 'span-4'); ?>
  <?php else: ?>
    <?php render_table_tile($tableConfig, $stageBadgeClass, 'span-12'); ?>
  <?php endif; ?>

</div>

<?php if ($hasSuggestions): ?>
<div id="aiModal" style="display:none; position:fixed; inset:0; background:rgba(11,21,36,0.45); backdrop-filter:blur(4px); z-index:100; align-items:center; justify-content:center; padding:20px;">
  <div style="background:#fff; border-radius:20px; padding:24px; max-width:500px; width:100%; box-shadow:var(--shadow-pop);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; gap:10px;">
      <h3 style="font-size:16px;">Next best action — <span id="aiModalCompany"></span></h3>
      <button onclick="document.getElementById('aiModal').style.display='none'" class="btn-ghost" style="padding:4px 8px;" aria-label="Close">✕</button>
    </div>
    <span class="badge red" style="margin-bottom:12px;">AI-generated — review before use</span>
    <div id="aiModalBody" style="font-size:13.5px; color:var(--ink-700); line-height:1.6; white-space:pre-wrap; min-height:80px; margin-top:10px;">Working…</div>
    <div class="form-actions">
      <button class="btn-primary" onclick="document.getElementById('aiModal').style.display='none'">Done</button>
    </div>
  </div>
</div>

<script>
function explainSuggestion(leadId, companyName){
  document.getElementById('aiModalCompany').textContent = companyName;
  document.getElementById('aiModalBody').textContent = 'Working…';
  document.getElementById('aiModal').style.display = 'flex';
  fetch('ai-draft.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'type=next_action&lead_id=' + encodeURIComponent(leadId)
  })
  .then(r => r.json())
  .then(data => { document.getElementById('aiModalBody').textContent = data.text; })
  .catch(() => { document.getElementById('aiModalBody').textContent = 'Something went wrong reaching the AI service. Please try again.'; });
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
