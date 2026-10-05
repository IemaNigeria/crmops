<?php
/**
 * IEMA CRMOps — Proposals (FR-3.1 to FR-3.8)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/proposal-render.php';
require_login();

$role = current_role();
$uid  = (int) ($_SESSION['user_id'] ?? 0);
const PROPOSAL_STATUSES = ['Draft', 'Internal Review', 'Sent to Client', 'Under Client Review', 'Revised', 'Signed', 'Rejected'];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_proposal') {
    $leadId = (int) ($_POST['lead_id'] ?? 0);
    $value  = (float) ($_POST['value_ngn'] ?? 0);
    $docLink = trim($_POST['document_link'] ?? '');
    $scopeOfWork = trim($_POST['scope_of_work'] ?? '');

    if ($leadId <= 0 || $value <= 0) {
        $error = 'Please select a lead and enter a proposal value.';
    } else {
        $leadStmt = $pdo->prepare('SELECT client_id, company_name FROM leads WHERE id = ?');
        $leadStmt->execute([$leadId]);
        $lead = $leadStmt->fetch();

        $countStmt = $pdo->query('SELECT COUNT(*) FROM proposals');
        $seq = str_pad((string) ($countStmt->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
        $ref = 'IEMA/PROP/' . date('Y') . '/GEN/' . $seq;                          // FR-3.2

        $stmt = $pdo->prepare('INSERT INTO proposals (reference_number, client_id, lead_id, status, value_ngn, document_link, scope_of_work) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$ref, $lead['client_id'] ?? null, $leadId, 'Draft', $value, $docLink ?: null, $scopeOfWork ?: null]);
        $newId = (int) $pdo->lastInsertId();

        $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
        $audit->execute([$uid, 'create', 'proposal', $newId, "Created proposal $ref"]);

        header('Location: proposals.php?view=' . $newId . '&created=1');
        exit;
    }
}

// ---- Edit scope of work (after creation, e.g. from the preview page) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_scope') {
    $pid = (int) ($_POST['proposal_id'] ?? 0);
    $scopeOfWork = trim($_POST['scope_of_work'] ?? '');
    if ($pid > 0) {
        $pdo->prepare('UPDATE proposals SET scope_of_work = ? WHERE id = ?')->execute([$scopeOfWork ?: null, $pid]);
        $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
        $audit->execute([$uid, 'update', 'proposal', $pid, 'Updated scope of work']);
        header('Location: proposals.php?view=' . $pid . '&updated=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $pid = (int) ($_POST['proposal_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($pid > 0 && in_array($status, PROPOSAL_STATUSES, true)) {
        $current = $pdo->prepare('SELECT status, value_ngn FROM proposals WHERE id = ?');
        $current->execute([$pid]);
        $row = $current->fetch();

        if ($row && $row['status'] !== $status) {
            // FR-3.8: version history on each revision
            $rev = $pdo->prepare('INSERT INTO proposal_revisions (proposal_id, previous_value_ngn, previous_status, revised_by, notes) VALUES (?,?,?,?,?)');
            $rev->execute([$pid, $row['value_ngn'], $row['status'], $uid, "Status changed from {$row['status']} to $status"]);

            $extra = '';
            $params = [$status];
            if ($status === 'Sent to Client') { $extra = ', sent_at = NOW()'; }
            if ($status === 'Signed') { $extra = ', signed_at = NOW()'; }           // FR-3.6
            $stmt = $pdo->prepare("UPDATE proposals SET status = ?$extra WHERE id = ?");
            $params[] = $pid;
            $stmt->execute($params);

            if ($status === 'Signed') {
                $clientStmt = $pdo->prepare('SELECT client_id FROM proposals WHERE id = ?');
                $clientStmt->execute([$pid]);
                $cid = $clientStmt->fetchColumn();
                if ($cid) {
                    $pdo->prepare("UPDATE clients SET status = 'active' WHERE id = ?")->execute([$cid]);

                    // Auto-assign a Client Relationship Manager — load-balanced across
                    // whichever account_manager users currently have the fewest active
                    // clients. Only if this client doesn't already have one.
                    $needsCrmStmt = $pdo->prepare('SELECT assigned_crm_id FROM clients WHERE id = ?');
                    $needsCrmStmt->execute([$cid]);
                    if (!$needsCrmStmt->fetchColumn()) {
                        $crmStmt = $pdo->query("
                            SELECT u.id, COUNT(c.id) AS client_count
                            FROM users u
                            LEFT JOIN clients c ON c.assigned_crm_id = u.id AND c.status = 'active'
                            WHERE u.role = 'account_manager' AND u.status = 'active'
                            GROUP BY u.id
                            ORDER BY client_count ASC LIMIT 1
                        ");
                        $chosenCrm = $crmStmt->fetchColumn();
                        if ($chosenCrm) {
                            $pdo->prepare('UPDATE clients SET assigned_crm_id = ? WHERE id = ?')->execute([$chosenCrm, $cid]);
                            $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
                            $audit->execute([$uid, 'update', 'client', $cid, 'Auto-assigned Client Relationship Manager on proposal signing']);
                        }
                        // If no account_manager users exist yet, the client is left
                        // unassigned — surfaced to Management via the dashboard notice.
                    }
                }
            }

            $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
            $audit->execute([$uid, 'update', 'proposal', $pid, "Status changed to $status"]);
            $success = 'Proposal status updated.';
        }
    }
}

// ---- Load view data (branded document preview) --------------------------
$viewingProposal = null;
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("
        SELECT p.*,
               COALESCE(c.company_name, l.company_name) AS company_name,
               COALESCE(c.primary_contact_name, l.contact_person) AS contact_name,
               COALESCE(c.primary_contact_email, l.contact_email) AS contact_email,
               l.sector,
               (SELECT GROUP_CONCAT(s.name SEPARATOR ', ') FROM lead_schemes ls JOIN schemes s ON s.id = ls.scheme_id WHERE ls.lead_id = p.lead_id) AS schemes_text
        FROM proposals p
        LEFT JOIN clients c ON c.id = p.client_id
        LEFT JOIN leads l ON l.id = p.lead_id
        WHERE p.id = ?
    ");
    $stmt->execute([(int) $_GET['view']]);
    $viewingProposal = $stmt->fetch();
}

// Pull along each lead's "Get a Quote" request details and scheme interest
// too, so the New Proposal form can show what the prospect actually asked
// for instead of just a bare name in a dropdown.
$leadOptionsStmt = $pdo->query("
    SELECT l.id, l.reference_number, l.company_name, l.sector, l.estimated_value_ngn, l.quote_details,
           (SELECT GROUP_CONCAT(s.name SEPARATOR ', ') FROM lead_schemes ls JOIN schemes s ON s.id = ls.scheme_id WHERE ls.lead_id = l.id) AS schemes
    FROM leads l
    WHERE l.stage NOT IN ('Lost')
    ORDER BY l.company_name
");
$leadOptions = $leadOptionsStmt->fetchAll();

$isOwnScoped = in_array($role, ['bd_officer', 'bd_manager', 'account_manager'], true);
$scopeSql = $isOwnScoped ? 'WHERE l.assigned_to = ?' : '';
$scopeParams = $isOwnScoped ? [$uid] : [];
$stmt = $pdo->prepare("
    SELECT p.*, COALESCE(c.company_name, l.company_name) AS company_name
    FROM proposals p
    LEFT JOIN clients c ON c.id = p.client_id
    LEFT JOIN leads l ON l.id = p.lead_id
    $scopeSql
    ORDER BY p.updated_at DESC
");
$stmt->execute($scopeParams);
$proposals = $stmt->fetchAll();

$statusBadge = [
    'Draft' => 'stage-lead', 'Internal Review' => 'stage-lead', 'Sent to Client' => 'stage-proposal',
    'Under Client Review' => 'stage-proposal', 'Revised' => 'stage-negotiation',
    'Signed' => 'stage-client', 'Rejected' => 'stage-lost',
];

// ---- Presentation-only summary figures (derived from $proposals above) ----
$ppGroups = [
    'draft'  => ['Draft', 'Internal Review', 'Revised'],
    'client' => ['Sent to Client', 'Under Client Review'],
    'signed' => ['Signed'],
    'lost'   => ['Rejected'],
];
$ppGroupOf = function (string $status) use ($ppGroups): string {
    foreach ($ppGroups as $g => $list) { if (in_array($status, $list, true)) { return $g; } }
    return 'draft';
};
$ppCount = array_fill_keys(array_keys($ppGroups), 0);
$ppValue = array_fill_keys(array_keys($ppGroups), 0.0);
foreach ($proposals as $pp) {
    $g = $ppGroupOf((string) $pp['status']);
    $ppCount[$g]++;
    $ppValue[$g] += (float) $pp['value_ngn'];
}
$ppOpenValue = $ppValue['draft'] + $ppValue['client'];
$ppClosed    = $ppCount['signed'] + $ppCount['lost'];
$ppWinRate   = $ppClosed > 0 ? round($ppCount['signed'] / $ppClosed * 100) : 0;
$ppFmtM      = fn($v) => '₦' . number_format($v / 1000000, 1) . 'M';
$ppDate      = fn($d) => $d ? date('j M Y', strtotime($d)) : '—';

require_once __DIR__ . '/includes/header.php';
?>

<style>
  .pp-num{ font-variant-numeric:tabular-nums; font-weight:600; color:var(--ink-900); white-space:nowrap; }
  .pp-status-cell{ display:flex; flex-direction:column; align-items:flex-start; gap:5px; }
  .pp-invoice-link{ display:inline-flex; align-items:center; gap:4px; font-size:11.5px; font-weight:700; color:var(--red-600); }
  .pp-invoice-link .icon{ width:13px; height:13px; }
  .pp-invoice-link:hover{ text-decoration:underline; }
  .pp-ext{ display:block; font-size:11px; color:var(--ink-500); margin-top:4px; }
  .pp-ext:hover{ color:var(--ink-900); text-decoration:underline; }
  .pp-update{ display:flex; gap:6px; align-items:center; }
  .pp-update select{ padding:6px 8px; border-radius:9px; border:1px solid var(--neutral-300); background:#fff; font-size:12px; font-family:inherit; color:var(--ink-900); max-width:170px; }
  .pp-update select:focus{ border-color:var(--red-600); box-shadow:var(--ring); outline:none; }
  .pp-actions .icon-button{ width:32px; height:32px; border-radius:9px; }
  .pp-actions .icon-button .icon{ width:15px; height:15px; }
  .pp-toolbar{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  .pp-toolbar .pp-ref{ font-family:'Libre Franklin',sans-serif; font-weight:700; color:var(--ink-900); font-size:14px; }
  .pp-toolbar .pp-right{ margin-left:auto; display:flex; gap:8px; flex-wrap:wrap; }
  .pp-doc{ background:var(--neutral-50); align-self:start; }
  .pp-side-col{ display:flex; flex-direction:column; gap:var(--gap); min-width:0; }
  .pp-back .icon{ transform:rotate(90deg); }
  .pp-doc-inner{ overflow-x:auto; }
  @media (max-width:1024px){
    .pp-side-col{ order:-1; }
    .bento > .proposal-actions{ order:-2; }
  }
  @media (max-width:760px){
    .pp-doc-inner > div{ padding:22px !important; min-width:620px; }
    .pp-toolbar .pp-right{ margin-left:0; width:100%; }
  }
  .pp-doc-inner{ background:#fff; border:1px solid var(--line); border-radius:14px; overflow:hidden; }
  .pp-kv{ display:grid; grid-template-columns:auto minmax(0,1fr); gap:9px 14px; font-size:13px; }
  .pp-kv dt{ color:var(--ink-500); font-weight:500; }
  .pp-kv dd{ color:var(--ink-900); font-weight:600; text-align:right; overflow-wrap:anywhere; }
  .pp-timeline{ list-style:none; }
  .pp-timeline li{ display:flex; gap:11px; padding:9px 0; border-bottom:1px solid var(--line-soft); font-size:13px; }
  .pp-timeline li:last-child{ border-bottom:none; }
  .pp-timeline .dot{ width:10px; height:10px; border-radius:50%; margin-top:4px; flex-shrink:0; background:var(--neutral-300); }
  .pp-timeline li.done .dot{ background:var(--navy-700); }
  .pp-timeline li.win .dot{ background:var(--green); }
  .pp-timeline .t{ font-weight:600; color:var(--ink-900); }
  .pp-timeline .d{ font-size:11.5px; color:var(--ink-500); margin-top:1px; }
  .pp-quote-card{ padding:14px; background:var(--neutral-50); border:1px solid var(--line-soft); border-radius:14px; }
  .pp-form-cols{ display:grid; grid-template-columns:minmax(0,1.1fr) minmax(0,1fr); gap:24px; }
  .pp-form-cols > div > .form-field{ margin-bottom:14px; }
  .pp-form-cols > div > .form-field:last-child{ margin-bottom:0; }
  #proposalValueHint:empty{ display:none; }
  @media (max-width:900px){ .pp-form-cols{ grid-template-columns:1fr; } }
  .pp-modal{ display:none; position:fixed; inset:0; background:rgba(11,21,36,0.5); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:20px; }
  .pp-modal-card{ background:#fff; border-radius:var(--r-tile); padding:24px; width:100%; box-shadow:var(--shadow-pop); border:1px solid var(--line); }
  .pp-modal-card h3{ font-size:16px; }
  .pp-ai-chip{ display:inline-flex; align-items:center; gap:5px; font-size:10.5px; font-weight:700; color:var(--red-700); background:var(--red-50); padding:3px 9px; border-radius:999px; }
  @media print {
    .sidebar, .topbar, .page-head .btn-primary, .page-head .btn-secondary,
    .proposal-actions, .form-actions, .pp-side { display: none !important; }
    main { padding: 0 !important; }
    .page-head { display:none !important; }
    .bento { display:block !important; }
    .pp-doc { border:none !important; padding:0 !important; background:#fff !important; }
    .pp-doc-inner { border:none !important; }
  }
</style>

<div class="page-head">
  <div>
    <div class="eyebrow"><?php echo $viewingProposal ? 'Proposal preview' : 'Sales'; ?></div>
    <h1>Proposals</h1>
    <p><?php echo $viewingProposal ? 'Branded preview — this is what the client sees.' : 'Status tracking and e-signature routing (FR-3.1–FR-3.8). CRMops can now generate the branded document itself, or you can link one hosted elsewhere.'; ?></p>
  </div>
  <?php if (!$viewingProposal): ?>
  <div class="page-actions">
    <button class="btn-primary" onclick="document.getElementById('newProposalForm').style.display='block'; this.style.display='none';">
      <?php echo icon('plus'); ?> New Proposal
    </button>
  </div>
  <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert error"><?php echo icon('alert'); ?><span><?php echo e($error); ?></span></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?php echo icon('check'); ?><span><?php echo e($success); ?></span></div><?php endif; ?>
<?php if (isset($_GET['created'])): ?><div class="alert success"><?php echo icon('check'); ?><span>Proposal created. Add a scope of work below before sending.</span></div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert success"><?php echo icon('check'); ?><span>Scope of work updated.</span></div><?php endif; ?>

<?php if ($viewingProposal):
  $vpStatus = (string) $viewingProposal['status'];
  $vpSteps = [
      ['Created',        $viewingProposal['created_at'] ?? null, true],
      ['Sent to client', $viewingProposal['sent_at'] ?? null,    !empty($viewingProposal['sent_at']) || in_array($vpStatus, ['Sent to Client', 'Under Client Review', 'Revised', 'Signed', 'Rejected'], true)],
      [$vpStatus === 'Rejected' ? 'Rejected' : 'Signed', $viewingProposal['signed_at'] ?? null, in_array($vpStatus, ['Signed', 'Rejected'], true)],
  ];
?>

<div class="bento">
  <section class="tile pad-sm span-12 proposal-actions">
    <div class="pp-toolbar">
      <a href="proposals.php" class="btn-secondary btn-sm pp-back"><?php echo icon('chevron'); ?> Back to list</a>
      <span class="pp-ref"><?php echo e($viewingProposal['reference_number']); ?></span>
      <span class="badge <?php echo e($statusBadge[$viewingProposal['status']] ?? ''); ?>"><?php echo e($viewingProposal['status']); ?></span>
      <div class="pp-right">
        <button type="button" class="btn-secondary" onclick="document.getElementById('scopeEditForm').style.display = document.getElementById('scopeEditForm').style.display === 'none' ? 'block' : 'none';">
          <?php echo icon('edit'); ?> Edit Scope of Work
        </button>
        <?php if (role_is(['bd_officer', 'bd_manager', 'account_manager', 'management'])): ?>
        <button type="button" class="btn-secondary" onclick="openProposalSendModal(<?php echo (int) $viewingProposal['id']; ?>, '<?php echo e(addslashes($viewingProposal['company_name'] ?? '')); ?>')">
          <?php echo icon('mail'); ?> Send to Client
        </button>
        <?php endif; ?>
        <button type="button" class="btn-primary" onclick="window.print()"><?php echo icon('download'); ?> Print / Save PDF</button>
      </div>
    </div>
  </section>

  <section class="tile span-12 proposal-actions" id="scopeEditForm" style="display:none;">
    <div class="tile-head">
      <h2 class="tile-title"><?php echo icon('edit'); ?> Edit Scope of Work</h2>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="update_scope">
      <input type="hidden" name="proposal_id" value="<?php echo (int) $viewingProposal['id']; ?>">
      <div class="form-field full">
        <label>Scope of Work</label>
        <textarea name="scope_of_work" style="min-height:160px;"><?php echo e($viewingProposal['scope_of_work'] ?? ''); ?></textarea>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="document.getElementById('scopeEditForm').style.display='none';">Cancel</button>
        <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Save</button>
      </div>
    </form>
  </section>

  <section class="tile pp-doc span-8 pad-sm">
    <div class="pp-doc-inner">
      <?php echo render_proposal_html($viewingProposal); ?>
    </div>
  </section>

  <div class="span-4 pp-side pp-side-col">
  <section class="tile tile--navy">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Proposed value</span>
      <div class="tile-icon"><?php echo icon('file'); ?></div>
    </div>
    <div class="tile-value">₦<?php echo number_format((float) $viewingProposal['value_ngn']); ?></div>
    <div class="tile-sub"><?php echo e($viewingProposal['company_name'] ?? '—'); ?></div>
    <?php if ($vpStatus === 'Signed'): ?>
      <div class="tile-foot">
        <a href="invoices.php?from_proposal=<?php echo (int) $viewingProposal['id']; ?>" class="btn-primary btn-sm"><?php echo icon('file'); ?> Create Invoice</a>
      </div>
    <?php endif; ?>
  </section>

  <section class="tile">
    <div class="tile-head"><h2 class="tile-title">Client &amp; scope</h2></div>
    <dl class="pp-kv">
      <dt>Company</dt><dd><?php echo e($viewingProposal['company_name'] ?? '—'); ?></dd>
      <dt>Contact</dt><dd><?php echo e($viewingProposal['contact_name'] ?? '—'); ?></dd>
      <dt>Email</dt><dd><?php echo e($viewingProposal['contact_email'] ?? '—'); ?></dd>
      <dt>Sector</dt><dd><?php echo e($viewingProposal['sector'] ?? '—'); ?></dd>
      <dt>Schemes</dt><dd><?php echo e($viewingProposal['schemes_text'] ?? '—'); ?></dd>
      <?php if (!empty($viewingProposal['document_link'])): ?>
      <dt>External doc</dt><dd><a href="<?php echo e($viewingProposal['document_link']); ?>" target="_blank" rel="noopener" style="color:var(--red-600);">Open link</a></dd>
      <?php endif; ?>
    </dl>
  </section>

  <section class="tile">
    <div class="tile-head"><h2 class="tile-title">Progress</h2></div>
    <ul class="pp-timeline">
      <?php foreach ($vpSteps as $i => [$label, $when, $done]): ?>
        <li class="<?php echo $done ? ($i === 2 && $vpStatus === 'Signed' ? 'win' : 'done') : ''; ?>">
          <span class="dot"></span>
          <div>
            <div class="t"><?php echo e($label); ?></div>
            <div class="d"><?php echo $when ? e($ppDate($when)) : ($done ? 'Done' : 'Pending'); ?></div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  </div>
</div>

<?php else: ?>

<div class="bento">
  <section class="tile tile--navy span-4 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Open proposal value</span>
      <div class="tile-icon"><?php echo icon('trend'); ?></div>
    </div>
    <div class="tile-value" style="font-size:34px;"><?php echo $ppFmtM($ppOpenValue); ?></div>
    <div class="tile-sub"><?php echo (int) ($ppCount['draft'] + $ppCount['client']); ?> proposals not yet signed or rejected</div>
    <div style="flex:1; min-height:14px;"></div>
    <div class="stat-row" style="grid-template-columns:repeat(2,minmax(0,1fr));">
      <div class="stat"><div class="v"><?php echo (int) $ppCount['draft']; ?></div><div class="l">In preparation</div></div>
      <div class="stat"><div class="v"><?php echo (int) $ppCount['client']; ?></div><div class="l">With client</div></div>
    </div>
  </section>

  <section class="tile span-4 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Awaiting client</span>
      <div class="tile-icon amber"><?php echo icon('clock'); ?></div>
    </div>
    <div class="tile-value"><?php echo (int) $ppCount['client']; ?></div>
    <div class="tile-sub"><?php echo $ppFmtM($ppValue['client']); ?> sent or under client review</div>
  </section>

  <section class="tile span-4 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Signed</span>
      <div class="tile-icon green"><?php echo icon('check'); ?></div>
    </div>
    <div class="tile-value"><?php echo (int) $ppCount['signed']; ?></div>
    <div class="tile-sub"><?php echo $ppFmtM($ppValue['signed']); ?> · <?php echo (int) $ppWinRate; ?>% of closed proposals</div>
    <div style="flex:1; min-height:12px;"></div>
    <div class="meter"><span style="width:<?php echo (int) $ppWinRate; ?>%; background:var(--green);"></span></div>
  </section>

  <section class="tile span-12" id="newProposalForm" style="display:none;">
    <div class="tile-head">
      <h2 class="tile-title"><?php echo icon('plus'); ?> New Proposal</h2>
    </div>
    <form method="POST" id="newProposalFormEl">
      <input type="hidden" name="action" value="create_proposal">
      <div class="pp-form-cols">

        <div>
          <div class="form-field full">
            <label>Lead *</label>
            <select name="lead_id" id="proposalLeadSelect" required>
              <option value="">Select a lead…</option>
              <?php foreach ($leadOptions as $lo): ?>
                <option value="<?php echo (int) $lo['id']; ?>"
                  data-sector="<?php echo e($lo['sector'] ?? ''); ?>"
                  data-schemes="<?php echo e($lo['schemes'] ?? ''); ?>"
                  data-value="<?php echo (int) ($lo['estimated_value_ngn'] ?? 0); ?>"
                  data-quote="<?php echo e($lo['quote_details'] ?? ''); ?>">
                  <?php echo e($lo['reference_number'] . ' — ' . $lo['company_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div id="proposalQuoteSummary">
            <p style="font-size:12px; color:var(--ink-500);">Select a lead to see what they actually asked for.</p>
          </div>
        </div>

        <div>
          <div class="form-field"><label>Proposed Value (NGN) *</label><input type="number" name="value_ngn" id="proposalValueInput" min="0" step="1000" required></div>
          <div id="proposalValueHint" style="font-size:11px; color:var(--ink-500); margin:-8px 0 14px;"></div>
          <div class="form-field"><label>Document Link <span style="font-weight:400; color:var(--ink-500);">(optional — only if you're linking one hosted elsewhere)</span></label><input type="text" name="document_link" placeholder="https://…"></div>

          <div class="form-field full">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
              <label style="margin:0;">Scope of Work</label>
              <button type="button" class="btn-secondary btn-sm" id="proposalAiAssistBtn" onclick="draftProposalAssist()">
                ✨ Draft with AI
              </button>
            </div>
            <textarea name="scope_of_work" id="proposalScopeInput" placeholder="What's included, tailored to what the lead asked for — or click &quot;Draft with AI&quot; to start from a suggestion." style="min-height:140px;"></textarea>
            <div style="font-size:11px; color:var(--ink-500); margin-top:4px;">This is what appears on the branded proposal document CRMops generates — review and edit before sending.</div>
          </div>
        </div>

      </div>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="document.getElementById('newProposalForm').style.display='none';">Cancel</button>
        <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Save Proposal</button>
      </div>
    </form>
  </section>

  <section class="tile span-12">
    <div class="tile-head">
      <h2 class="tile-title"><?php echo icon('file'); ?> All Proposals <span class="badge navy" id="ppCountBadge"><?php echo count($proposals); ?></span></h2>
      <?php if (!empty($proposals)): ?>
      <div class="filter-chips" id="ppFilters">
        <button type="button" class="filter-chip active" data-group="all" onclick="filterProposals('all', this)">All</button>
        <button type="button" class="filter-chip" data-group="draft" onclick="filterProposals('draft', this)">In preparation · <?php echo (int) $ppCount['draft']; ?></button>
        <button type="button" class="filter-chip" data-group="client" onclick="filterProposals('client', this)">With client · <?php echo (int) $ppCount['client']; ?></button>
        <button type="button" class="filter-chip" data-group="signed" onclick="filterProposals('signed', this)">Signed · <?php echo (int) $ppCount['signed']; ?></button>
        <?php if ($ppCount['lost'] > 0): ?>
        <button type="button" class="filter-chip" data-group="lost" onclick="filterProposals('lost', this)">Rejected · <?php echo (int) $ppCount['lost']; ?></button>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php if (empty($proposals)): ?>
      <div class="empty-state">
        <div class="icon-button"><?php echo icon('file'); ?></div>
        <p>No proposals yet.</p>
      </div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead><tr><th>Company</th><th>Status</th><th>Value</th><th>Document</th><th>Update Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($proposals as $p): ?>
        <tr data-group="<?php echo e($ppGroupOf((string) $p['status'])); ?>">
          <td>
            <div class="row-name"><?php echo e($p['company_name'] ?? '—'); ?></div>
            <div class="row-sub"><?php echo e($p['reference_number']); ?></div>
          </td>
          <td>
            <div class="pp-status-cell">
              <span class="badge <?php echo e($statusBadge[$p['status']] ?? ''); ?>"><?php echo e($p['status']); ?></span>
              <?php if ($p['status'] === 'Signed'): ?>
                <a href="invoices.php?from_proposal=<?php echo (int) $p['id']; ?>" class="pp-invoice-link"><?php echo icon('file'); ?> Create Invoice</a>
              <?php endif; ?>
            </div>
          </td>
          <td class="pp-num">₦<?php echo number_format((float) $p['value_ngn']); ?></td>
          <td>
            <a href="proposals.php?view=<?php echo (int) $p['id']; ?>" class="btn-secondary btn-sm">Preview</a>
            <?php if ($p['document_link']): ?><a href="<?php echo e($p['document_link']); ?>" target="_blank" class="pp-ext">External link</a><?php endif; ?>
          </td>
          <td>
            <form method="POST" class="pp-update">
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="proposal_id" value="<?php echo (int) $p['id']; ?>">
              <select name="status" aria-label="Proposal status">
                <?php foreach (PROPOSAL_STATUSES as $s): ?>
                  <option value="<?php echo e($s); ?>" <?php echo $s === $p['status'] ? 'selected' : ''; ?>><?php echo e($s); ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn-secondary btn-sm" title="Save status"><?php echo icon('check'); ?></button>
            </form>
          </td>
          <td>
            <?php if ($p['lead_id'] || $p['client_id']): ?>
            <div class="row-actions pp-actions">
              <?php if ($p['lead_id']): ?>
              <button type="button" class="icon-button" title="Draft a cover note with AI"
                onclick="runAi('proposal_note', <?php echo (int) $p['lead_id']; ?>, '<?php echo e(addslashes($p['company_name'] ?? '')); ?>', 'Cover Note')">
                <?php echo icon('send'); ?>
              </button>
              <button type="button" class="icon-button" title="AI: help draft proposal content (no pricing)"
                onclick="runAi('proposal_assist', <?php echo (int) $p['lead_id']; ?>, '<?php echo e(addslashes($p['company_name'] ?? '')); ?>', 'Proposal Content Assist')">
                <?php echo icon('file'); ?>
              </button>
              <?php endif; ?>
              <?php if (role_is(['bd_officer', 'bd_manager', 'account_manager', 'management'])): ?>
              <button type="button" class="icon-button" title="Email this proposal to the client"
                onclick="openProposalSendModal(<?php echo (int) $p['id']; ?>, '<?php echo e(addslashes($p['company_name'] ?? '')); ?>')">
                <?php echo icon('mail'); ?>
              </button>
              <?php endif; ?>
            </div>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="empty-state" id="ppFilterEmpty" style="display:none;"><p>No proposals in this group.</p></div>
    <?php endif; ?>
  </section>
</div>

<script>
function filterProposals(group, btn){
  document.querySelectorAll('#ppFilters .filter-chip').forEach(function(c){ c.classList.toggle('active', c === btn); });
  var shown = 0;
  document.querySelectorAll('tr[data-group]').forEach(function(tr){
    var on = group === 'all' || tr.dataset.group === group;
    tr.style.display = on ? '' : 'none';
    if (on) shown++;
  });
  var badge = document.getElementById('ppCountBadge'); if (badge) badge.textContent = shown;
  var empty = document.getElementById('ppFilterEmpty'); if (empty) empty.style.display = shown ? 'none' : 'block';
}
</script>

<?php endif; ?>

<div id="aiModal" class="pp-modal" style="display:none; z-index:100;">
  <div class="pp-modal-card" style="max-width:480px;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px;">
      <h3><span id="aiModalTitle"></span> — <span id="aiModalCompany"></span></h3>
      <button onclick="document.getElementById('aiModal').style.display='none'" class="btn-ghost btn-sm" aria-label="Close">✕</button>
    </div>
    <div class="pp-ai-chip" style="margin-bottom:12px;">
      ⚡ AI-generated — review before use, pricing intentionally excluded
    </div>
    <div id="aiModalBody" style="font-size:13.5px; color:var(--ink-700); line-height:1.6; white-space:pre-wrap; min-height:80px; background:var(--neutral-50); border:1px solid var(--line-soft); border-radius:12px; padding:12px 14px;">Working…</div>
    <div style="margin-top:16px; display:flex; gap:8px; justify-content:flex-end;">
      <button class="btn-secondary" onclick="copyAiDraft()">Copy Text</button>
      <button class="btn-primary" onclick="document.getElementById('aiModal').style.display='none'">Done</button>
    </div>
  </div>
</div>

<!-- Send to Client modal — emails the proposal from the officer's own
     configured mailbox and moves the proposal to "Sent to Client". -->
<div id="proposalSendModal" class="pp-modal" style="display:none; z-index:101;">
  <div class="pp-modal-card" style="max-width:640px; max-height:90vh; overflow-y:auto;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
      <h3 style="font-size:17px;">Send Proposal to Client</h3>
      <button type="button" onclick="closeProposalSendModal()" class="btn-ghost btn-sm" aria-label="Close">✕</button>
    </div>
    <p id="proposalSendCompany" style="font-size:12.5px; color:var(--ink-500); margin-bottom:16px;"></p>

    <div id="proposalSendLoading" style="text-align:center; padding:30px; color:var(--ink-500);">
      <div style="font-size:13px; margin-bottom:8px;">⏳ Drafting a cover note with AI…</div>
      <div style="font-size:11.5px; color:var(--ink-400);">This takes a few seconds</div>
    </div>

    <div id="proposalSendForm" style="display:none;">
      <div class="pp-ai-chip" style="margin-bottom:14px;">
        ⚡ AI-drafted — review and edit before sending
      </div>

      <div class="form-field" style="margin-bottom:12px;">
        <label>To</label>
        <input type="email" id="proposalSendTo" placeholder="client@example.com">
      </div>
      <div class="form-field" style="margin-bottom:12px;">
        <label>Document Link <span style="font-weight:400; color:var(--ink-500);">(optional — only if you're linking one hosted elsewhere)</span></label>
        <input type="text" id="proposalSendDocLink" placeholder="https://…">
        <div style="font-size:11px; color:var(--ink-500); margin-top:4px;">The branded IEMA proposal document is generated automatically and included in the email below — this link is only for an extra reference hosted elsewhere.</div>
      </div>
      <div class="form-field" style="margin-bottom:12px;">
        <label>Subject</label>
        <input type="text" id="proposalSendSubject">
      </div>
      <div class="form-field" style="margin-bottom:14px;">
        <label>Message</label>
        <textarea id="proposalSendBody" style="min-height:200px; line-height:1.6;"></textarea>
      </div>

      <div id="proposalSendStatus" style="display:none; padding:10px 14px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:12px;"></div>

      <div style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap;">
        <button type="button" class="btn-secondary" onclick="closeProposalSendModal()">Cancel</button>
        <button type="button" class="btn-secondary" id="proposalSendRegenBtn" onclick="regenerateProposalDraft()">⚡ Regenerate Draft</button>
        <button type="button" class="btn-primary" id="proposalSendBtn" onclick="sendProposalEmail()">
          <?php echo icon('send'); ?> Send Proposal
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// ---- Send to Client modal --------------------------------------------
var currentProposalSend = { proposalId: 0, company: '' };

function closeProposalSendModal(){
  document.getElementById('proposalSendModal').style.display = 'none';
}

function openProposalSendModal(proposalId, company){
  currentProposalSend = { proposalId: proposalId, company: company };
  document.getElementById('proposalSendCompany').textContent = company;
  document.getElementById('proposalSendLoading').style.display = 'block';
  document.getElementById('proposalSendForm').style.display = 'none';
  document.getElementById('proposalSendStatus').style.display = 'none';
  document.getElementById('proposalSendModal').style.display = 'flex';
  fetchProposalDraft(proposalId);
}

function fetchProposalDraft(proposalId){
  document.getElementById('proposalSendLoading').style.display = 'block';
  document.getElementById('proposalSendForm').style.display = 'none';

  fetch('proposal-send-draft.php?proposal_id=' + encodeURIComponent(proposalId))
    .then(r => r.json())
    .then(data => {
      document.getElementById('proposalSendLoading').style.display = 'none';
      document.getElementById('proposalSendForm').style.display = 'block';
      if (!data.ok) {
        showProposalSendStatus('error', 'Could not draft email: ' + (data.error || 'Unknown error'));
        return;
      }
      document.getElementById('proposalSendTo').value = data.contact_email || '';
      document.getElementById('proposalSendDocLink').value = data.document_link || '';
      document.getElementById('proposalSendSubject').value = data.subject || '';
      document.getElementById('proposalSendBody').value = data.body || '';
      if (data.ai) {
        showProposalSendStatus('info', '⚡ Draft generated by AI — review carefully before sending. The branded proposal document will be attached below this message automatically.');
      }
    })
    .catch(() => {
      document.getElementById('proposalSendLoading').style.display = 'none';
      document.getElementById('proposalSendForm').style.display = 'block';
      showProposalSendStatus('error', 'Could not reach the server. Please try again.');
    });
}

function regenerateProposalDraft(){
  fetchProposalDraft(currentProposalSend.proposalId);
}

function sendProposalEmail(){
  var toEmail = document.getElementById('proposalSendTo').value.trim();
  var docLink = document.getElementById('proposalSendDocLink').value.trim();
  var subject = document.getElementById('proposalSendSubject').value.trim();
  var body    = document.getElementById('proposalSendBody').value.trim();

  if (!toEmail) { showProposalSendStatus('error', 'Please enter the recipient email address.'); return; }
  if (!subject) { showProposalSendStatus('error', 'Please enter a subject.'); return; }
  if (!body)    { showProposalSendStatus('error', 'Message body cannot be empty.'); return; }

  var sendBtn = document.getElementById('proposalSendBtn');
  sendBtn.disabled = true;
  sendBtn.textContent = 'Sending…';

  fetch('proposal-send.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      proposal_id: currentProposalSend.proposalId,
      to_email: toEmail,
      subject: subject,
      body: body,
      document_link: docLink,
    })
  })
  .then(r => r.json())
  .then(data => {
    sendBtn.disabled = false;
    sendBtn.innerHTML = '<?php echo icon("send"); ?> Send Proposal';
    if (data.ok) {
      showProposalSendStatus('success', '✅ ' + data.message);
      setTimeout(() => { closeProposalSendModal(); location.reload(); }, 2000);
    } else {
      showProposalSendStatus('error', '❌ ' + (data.error || 'Send failed.'));
    }
  })
  .catch(() => {
    sendBtn.disabled = false;
    sendBtn.innerHTML = '<?php echo icon("send"); ?> Send Proposal';
    showProposalSendStatus('error', 'Network error — please try again.');
  });
}

function showProposalSendStatus(type, msg){
  var el = document.getElementById('proposalSendStatus');
  el.style.display = 'block';
  el.style.background = type === 'success' ? 'var(--green-50)' : type === 'error' ? 'var(--red-50)' : 'var(--blue-50)';
  el.style.color      = type === 'success' ? 'var(--green-700)' : type === 'error' ? '#B42318' : '#1E40AF';
  el.textContent = msg;
}
</script>

<script>
function escapeHtmlLocal(str){
  return (str || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// Show what the lead actually asked for on "Get a Quote" (if anything),
// right next to the field where you set the proposal's value — and
// suggest a starting value from their stated budget range.
let lastValueWasSuggested = false;
function populateProposalContext(){
  const sel = document.getElementById('proposalLeadSelect');
  const opt = sel.options[sel.selectedIndex];
  const summaryEl = document.getElementById('proposalQuoteSummary');
  const valueInput = document.getElementById('proposalValueInput');
  const valueHint = document.getElementById('proposalValueHint');

  if (!opt || !opt.value) {
    summaryEl.innerHTML = '<p style="font-size:12px; color:var(--ink-500);">Select a lead to see what they actually asked for.</p>';
    valueHint.textContent = '';
    return;
  }

  const sector = opt.dataset.sector || '';
  const schemes = opt.dataset.schemes || '';
  const estValue = parseInt(opt.dataset.value || '0', 10);

  if (estValue > 0 && (valueInput.value === '' || lastValueWasSuggested)) {
    valueInput.value = estValue;
    lastValueWasSuggested = true;
    valueHint.textContent = 'Suggested from their stated budget range — adjust as needed.';
  } else {
    valueHint.textContent = '';
  }

  let quote = {};
  try { quote = opt.dataset.quote ? JSON.parse(opt.dataset.quote) : {}; } catch (e) { quote = {}; }

  const rows = Object.keys(quote).filter(k => quote[k]).map(k =>
    '<div><div class="row-sub">' + escapeHtmlLocal(k) + '</div><div style="font-weight:600; margin-top:2px; font-size:12.5px;">' + escapeHtmlLocal(quote[k]) + '</div></div>'
  ).join('');

  if (rows) {
    summaryEl.innerHTML = '<div class="pp-quote-card">'
      + '<div style="font-size:11px; font-weight:800; color:var(--ink-500); letter-spacing:0.03em; margin-bottom:10px;">PROSPECT\'S REQUEST'
      + (sector ? ' &middot; ' + escapeHtmlLocal(sector) : '') + '</div>'
      + '<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px 16px;">' + rows + '</div>'
      + '</div>';
  } else {
    summaryEl.innerHTML = '<p style="font-size:12px; color:var(--ink-500);">No "Get a Quote" details on file for this lead'
      + (schemes ? ' — interested in <b>' + escapeHtmlLocal(schemes) + '</b>' : '') + '.</p>';
  }
}
// The New Proposal form only exists on the list view (not ?view=ID).
if (document.getElementById('proposalLeadSelect')) {
  document.getElementById('proposalLeadSelect').addEventListener('change', populateProposalContext);
  document.getElementById('proposalValueInput').addEventListener('input', () => { lastValueWasSuggested = false; });
}

function draftProposalAssist(){
  const sel = document.getElementById('proposalLeadSelect');
  if (!sel.value) { alert('Select a lead first.'); return; }

  const btn = document.getElementById('proposalAiAssistBtn');
  const scopeInput = document.getElementById('proposalScopeInput');
  const hadExisting = scopeInput.value.trim() !== '';
  if (hadExisting && !confirm('This will replace what\'s currently in the Scope of Work field. Continue?')) return;

  btn.disabled = true;
  btn.textContent = 'Drafting…';
  scopeInput.value = 'Working…';

  fetch('ai-draft.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'type=proposal_assist&lead_id=' + encodeURIComponent(sel.value)
  })
  .then(r => r.json())
  .then(data => {
    scopeInput.value = data.text || '';
  })
  .catch(() => {
    scopeInput.value = '';
    alert('Something went wrong reaching the AI service. Please try again.');
  })
  .finally(() => {
    btn.disabled = false;
    btn.textContent = '✨ Draft with AI';
  });
}

function runAi(type, leadId, companyName, title){
  document.getElementById('aiModalTitle').textContent = title;
  document.getElementById('aiModalCompany').textContent = companyName;
  document.getElementById('aiModalBody').textContent = 'Working…';
  document.getElementById('aiModal').style.display = 'flex';

  fetch('ai-draft.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'type=' + encodeURIComponent(type) + '&lead_id=' + encodeURIComponent(leadId)
  })
  .then(r => r.json())
  .then(data => { document.getElementById('aiModalBody').textContent = data.text; })
  .catch(() => { document.getElementById('aiModalBody').textContent = 'Something went wrong reaching the AI service. Please try again.'; });
}
function copyAiDraft(){
  navigator.clipboard.writeText(document.getElementById('aiModalBody').textContent).then(() => alert('Copied to clipboard.'));
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>