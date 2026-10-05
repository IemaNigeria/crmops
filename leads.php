<?php
/**
 * IEMA CRMOps — Leads (FR-1.1 to FR-1.8)
 */
require_once __DIR__ . '/config.php';
require_login();

$role = current_role();
$uid  = (int) ($_SESSION['user_id'] ?? 0);
const LEAD_STAGES = ['New', 'Contacted', 'Needs Assessment', 'Proposal Sent', 'Negotiation', 'Won', 'Lost'];
const LOST_REASONS = ['Price', 'Competitor', 'No Budget', 'Timing', 'Scope Mismatch', 'Other'];

$error = '';
$success = '';

// ---- Create lead (FR-1.1, FR-1.3 auto reference number) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_lead') {
    $company = trim($_POST['company_name'] ?? '');
    $contact = trim($_POST['contact_person'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $sector  = trim($_POST['sector'] ?? '');
    $source  = trim($_POST['lead_source'] ?? '');
    $value   = (float) ($_POST['estimated_value_ngn'] ?? 0);
    $region  = trim($_POST['country_region'] ?? '');

    if ($company === '') {
        $error = 'Company name is required.';
    } else {
        $refStmt = $pdo->query('SELECT reference_number FROM leads ORDER BY id DESC LIMIT 1');
        $last = $refStmt->fetchColumn();
        $nextNum = 1;
        if ($last && preg_match('/LEAD-(\d{4})-(\d+)/', $last, $m)) {
            $nextNum = (int) $m[2] + 1;
        }
        $reference = 'LEAD-' . date('Y') . '-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare('INSERT INTO leads (reference_number, company_name, contact_person, contact_email, sector, lead_source, estimated_value_ngn, country_region, stage, assigned_to) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$reference, $company, $contact, $contactEmail ?: null, $sector, $source, $value ?: null, $region, 'New', $uid ?: null]);
        $newId = (int) $pdo->lastInsertId();

        $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
        $audit->execute([$uid, 'create', 'lead', $newId, "Created lead $reference for $company"]);

        $success = "Lead $reference created for $company.";
    }
}

// ---- Convert lead to client (manual, deliberate — see chat context) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'convert_to_client') {
    $leadId = (int) ($_POST['lead_id'] ?? 0);
    $company = trim($_POST['company_name'] ?? '');
    $rc = trim($_POST['rc_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $sector = trim($_POST['sector'] ?? '');
    $contactName = trim($_POST['contact_name'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $contactPhone = trim($_POST['contact_phone'] ?? '');

    $leadStmt = $pdo->prepare('SELECT * FROM leads WHERE id = ?');
    $leadStmt->execute([$leadId]);
    $sourceLead = $leadStmt->fetch();

    if (!$sourceLead || $company === '') {
        $error = 'Lead not found or company name missing.';
    } elseif (!empty($sourceLead['client_id'])) {
        $error = 'This lead is already linked to a client.';
    } elseif ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid contact email.';
    } else {
        // Same duplicate check used on clients.php (FR-2.6)
        $dupStmt = $pdo->prepare('SELECT id, company_name FROM clients WHERE rc_number = ? OR company_name LIKE ? LIMIT 1');
        $dupStmt->execute([$rc ?: '___none___', '%' . $company . '%']);
        $dup = $dupStmt->fetch();

        if ($dup && empty($_POST['confirm_duplicate'])) {
            $error = 'Possible duplicate: "' . e($dup['company_name']) . '" already exists as a client. Review it on the Clients page, or confirm below to link this lead to the existing client instead.';
            $_SESSION['_pending_convert'] = $_POST;
            $_SESSION['_pending_convert_existing_id'] = $dup['id'];
        } else {
            if ($dup) {
                // Confirmed: link to the existing client rather than creating a duplicate
                $newClientId = (int) $dup['id'];
            } else {
                $insertStmt = $pdo->prepare('INSERT INTO clients (company_name, rc_number, address, sector, primary_contact_name, primary_contact_email, primary_contact_phone, status) VALUES (?,?,?,?,?,?,?,?)');
                $insertStmt->execute([$company, $rc ?: null, $address ?: null, $sector ?: null, $contactName ?: null, $contactEmail ?: null, $contactPhone ?: null, 'active']);
                $newClientId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare('UPDATE leads SET client_id = ? WHERE id = ?')->execute([$newClientId, $leadId]);

            $syncIdStmt = $pdo->prepare('SELECT auditops_sync_id FROM clients WHERE id = ?');
            $syncIdStmt->execute([$newClientId]);
            sync_client_to_auditops($pdo, [
                'id' => $newClientId, 'company_name' => $company, 'sector' => $sector,
                'primary_contact_name' => $contactName, 'primary_contact_email' => $contactEmail,
                'primary_contact_phone' => $contactPhone, 'status' => 'active',
                'auditops_sync_id' => $syncIdStmt->fetchColumn(),
            ]);

            $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
            $audit->execute([$uid, 'create', 'client', $newClientId, "Converted lead #$leadId ($company) to client" . ($dup ? ' (linked to existing)' : '')]);

            $success = $dup
                ? "Lead linked to the existing client \"{$dup['company_name']}\"."
                : "\"$company\" is now a client.";
            unset($_SESSION['_pending_convert'], $_SESSION['_pending_convert_existing_id']);
        }
    }
}

// ---- Update stage / lost reason ----------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_stage') {
    $leadId = (int) ($_POST['lead_id'] ?? 0);
    $stage  = $_POST['stage'] ?? '';
    $lostReason = $_POST['lost_reason'] ?? null;

    if (in_array($stage, LEAD_STAGES, true) && $leadId > 0) {
        if ($stage === 'Lost' && !in_array($lostReason, LOST_REASONS, true)) {
            $error = 'A lost reason is required when marking a lead as Lost.';
        } else {
            $isOwnScoped = in_array($role, ['bd_officer', 'bd_manager', 'account_manager'], true);
            $stmt = $pdo->prepare('UPDATE leads SET stage = ?, lost_reason = ? WHERE id = ?' . ($isOwnScoped ? ' AND assigned_to = ?' : ''));
            $params = [$stage, $stage === 'Lost' ? $lostReason : null, $leadId];
            if ($isOwnScoped) $params[] = $uid;
            $stmt->execute($params);

            $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
            $audit->execute([$uid, 'update', 'lead', $leadId, "Stage changed to $stage"]);
            $success = 'Lead stage updated.';
        }
    }
}

// ---- Fetch leads (scoped) ------------------------------------------------
$stageFilter = $_GET['stage'] ?? '';
$where = [];
$params = [];
if (in_array($role, ['bd_officer', 'bd_manager', 'account_manager'], true)) { $where[] = 'assigned_to = ?'; $params[] = $uid; }
if ($stageFilter && in_array($stageFilter, LEAD_STAGES, true)) { $where[] = 'stage = ?'; $params[] = $stageFilter; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT l.*, u.name AS officer_name,
           (SELECT MAX(interaction_date) FROM interactions i WHERE i.lead_id = l.id) AS last_interaction_at
    FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
    $whereSql ORDER BY l.updated_at DESC
");
$stmt->execute($params);
$leads = $stmt->fetchAll();

$maxDealValue = 0;
foreach ($leads as $l) { $maxDealValue = max($maxDealValue, (float) ($l['estimated_value_ngn'] ?? 0)); }

function fmt_ngn2($value): string
{
    if ($value === null) return '—';
    return '₦' . number_format((float) $value);
}

function score_badge_class(int $score): string
{
    if ($score >= 70) return 'stage-client';
    if ($score >= 40) return 'stage-proposal';
    return 'stage-lost';
}

$stageBadgeClass = [
    'New' => 'stage-lead', 'Contacted' => 'stage-lead', 'Needs Assessment' => 'stage-lead',
    'Proposal Sent' => 'stage-proposal', 'Negotiation' => 'stage-negotiation',
    'Won' => 'stage-client', 'Lost' => 'stage-lost',
];

require_once __DIR__ . '/includes/header.php';
?>

<?php
// Summary numbers for the bento tiles — derived only from $leads (already loaded above).
$leadScores = [];
$sumOpenValue = 0.0; $openCount = 0; $wonCount = 0; $wonValue = 0.0; $lostCount = 0; $hotCount = 0; $scoreTotal = 0;
foreach ($leads as $l) {
    $s = compute_lead_score($l, $l['last_interaction_at'], $maxDealValue);
    $leadScores[(int) $l['id']] = $s;
    $scoreTotal += $s;
    if ($s >= 70) $hotCount++;
    if ($l['stage'] === 'Won') { $wonCount++; $wonValue += (float) ($l['estimated_value_ngn'] ?? 0); }
    elseif ($l['stage'] === 'Lost') { $lostCount++; }
    else { $openCount++; $sumOpenValue += (float) ($l['estimated_value_ngn'] ?? 0); }
}
$closedCount = $wonCount + $lostCount;
$winRate = $closedCount > 0 ? (int) round($wonCount / $closedCount * 100) : 0;
$avgScore = $leads ? (int) round($scoreTotal / count($leads)) : 0;
?>
<style>
/* leads.php — page-scoped bento helpers */
#newLeadForm{ scroll-margin-top:84px; }
.leads-table td{ white-space:nowrap; }
.leads-table td.company-cell{ white-space:normal; min-width:190px; }
.leads-table td.owner-cell{ white-space:normal; min-width:96px; }
.leads-table .row-actions{ gap:0; }
.leads-table .row-actions .btn-ghost{ padding:6px !important; }
.leads-table .btn-sm .icon{ width:15px; height:15px; }
.leads-table td, .leads-table th{ padding-left:10px; padding-right:10px; }
.leads-table .row-sub{ display:block; margin-top:2px; }
.inline-select{ padding:6px 8px; border-radius:9px; border:1px solid var(--neutral-300); background:#fff; font:inherit; font-size:12px; color:var(--ink-900); }
.inline-select:focus{ outline:none; border-color:var(--red-600); box-shadow:var(--ring); }
.quote-pill{ padding:2px 8px !important; font-size:10.5px !important; margin-left:4px; border:1px solid var(--line); border-radius:999px !important; }
.hero-figure{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.tile .meter{ margin-top:14px; }
.tile--navy .meter{ background:rgba(255,255,255,0.12); }
.tile--navy .meter > span{ background:#fff; }
.new-lead-grid{ grid-template-columns:repeat(4, minmax(0,1fr)); }
@media (max-width:1024px){ .new-lead-grid{ grid-template-columns:repeat(2, minmax(0,1fr)); } }
@media (max-width:700px){ .new-lead-grid{ grid-template-columns:1fr; } }
.leads-table-head .filter-chips{ justify-content:flex-end; }
@media (max-width:760px){ .leads-table-head .filter-chips{ justify-content:flex-start; flex-wrap:nowrap; overflow-x:auto; max-width:100%; padding-bottom:4px; } .leads-table-head .filter-chip{ white-space:nowrap; } }
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Business Development</div>
    <h1>My Leads</h1>
    <p>Lead capture and pipeline stage tracking (FR-1.1–FR-1.8).</p>
  </div>
  <div class="page-actions">
    <button class="btn-primary" onclick="document.getElementById('newLeadForm').style.display='block'; this.style.display='none';">
      <?php echo icon('plus'); ?> New Lead
    </button>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert error">
    <?php echo icon('alert'); ?>
    <span>
      <?php echo $error; /* already escaped where it contains DB data */ ?>
      <?php if (!empty($_SESSION['_pending_convert'])): ?>
        <form method="POST" style="margin-top:8px;">
          <input type="hidden" name="action" value="convert_to_client">
          <?php foreach ($_SESSION['_pending_convert'] as $k => $v): if ($k === 'confirm_duplicate') continue; ?>
            <input type="hidden" name="<?php echo e($k); ?>" value="<?php echo e($v); ?>">
          <?php endforeach; ?>
          <input type="hidden" name="confirm_duplicate" value="1">
          <button type="submit" class="btn-secondary btn-sm" style="margin-top:6px;">Link to existing client instead</button>
        </form>
      <?php endif; ?>
    </span>
  </div>
<?php endif; ?>
<?php if ($success): ?><div class="alert success"><?php echo icon('check'); ?><span><?php echo e($success); ?></span></div><?php unset($_SESSION['_pending_convert']); endif; ?>

<div class="bento">
  <section class="tile span-12" id="newLeadForm" style="display:none;">
    <div class="tile-head">
      <h2 class="tile-title"><span class="tile-icon" style="width:30px;height:30px;border-radius:9px;"><?php echo icon('plus'); ?></span> New Lead</h2>
      <span class="tile-sub" style="margin:0;">A reference number is generated automatically.</span>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="create_lead">
      <div class="form-grid new-lead-grid">
        <div class="form-field"><label>Company Name *</label><input type="text" name="company_name" required></div>
        <div class="form-field"><label>Contact Person</label><input type="text" name="contact_person"></div>
        <div class="form-field"><label>Contact Email</label><input type="email" name="contact_email" placeholder="for follow-up emails"></div>
        <div class="form-field"><label>Sector</label><input type="text" name="sector" placeholder="e.g. Manufacturing"></div>
        <div class="form-field"><label>Lead Source</label>
          <select name="lead_source">
            <option>Referral</option><option>Website</option><option>Trade Show</option><option>Cold Outreach</option><option>Other</option>
          </select>
        </div>
        <div class="form-field"><label>Estimated Value (NGN)</label><input type="number" name="estimated_value_ngn" min="0" step="1000"></div>
        <div class="form-field"><label>Country / Region</label><input type="text" name="country_region" placeholder="e.g. Lagos, Nigeria"></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="document.getElementById('newLeadForm').style.display='none';">Cancel</button>
        <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Save Lead</button>
      </div>
    </form>
  </section>

  <section class="tile tile--navy span-4 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Open pipeline value</span>
      <span class="tile-icon"><?php echo icon('trend'); ?></span>
    </div>
    <div class="tile-value"><?php echo fmt_ngn2($sumOpenValue); ?></div>
    <div class="tile-sub"><?php echo $openCount; ?> open lead<?php echo $openCount === 1 ? '' : 's'; ?><?php echo $stageFilter ? ' · ' . e($stageFilter) . ' only' : ''; ?></div>
  </section>

  <section class="tile span-4 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Won</span>
      <span class="tile-icon green"><?php echo icon('check'); ?></span>
    </div>
    <div class="tile-value"><?php echo fmt_ngn2($wonValue); ?></div>
    <div class="tile-sub"><?php echo $wonCount; ?> won · <?php echo $lostCount; ?> lost · <?php echo $winRate; ?>% win rate</div>
    <div class="meter"><span style="width:<?php echo $winRate; ?>%; background:var(--green);"></span></div>
  </section>

  <section class="tile span-4 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Hot leads (score 70+)</span>
      <span class="tile-icon amber"><?php echo icon('target'); ?></span>
    </div>
    <div class="tile-value"><?php echo $hotCount; ?> <span style="font-size:14px; font-weight:600; color:var(--ink-500); letter-spacing:0;">of <?php echo count($leads); ?></span></div>
    <div class="tile-sub">Average lead score <?php echo $avgScore; ?> / 100</div>
    <div class="meter"><span style="width:<?php echo min(100, $avgScore); ?>%; background:var(--amber);"></span></div>
  </section>

  <section class="tile span-12">
    <div class="tile-head leads-table-head">
      <h2 class="tile-title">Leads <span class="badge navy"><?php echo count($leads); ?></span></h2>
      <div class="filter-chips">
        <a class="filter-chip<?php echo $stageFilter === '' ? ' active' : ''; ?>" href="leads.php">All</a>
        <?php foreach (LEAD_STAGES as $s): ?>
          <a class="filter-chip<?php echo $stageFilter === $s ? ' active' : ''; ?>" href="leads.php?stage=<?php echo urlencode($s); ?>"><?php echo e($s); ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php if (empty($leads)): ?>
    <div class="empty-state">
      <div class="icon-button"><?php echo icon('target'); ?></div>
      <p>No leads yet. Click "New Lead" to add your first one.</p>
    </div>
  <?php else: ?>
  <div class="table-wrap">
  <table class="leads-table">
    <thead><tr><th>Company</th><th>Stage</th><th>Score</th><th>Value · Source</th><th>Owner</th><th>Update Stage</th><th>Client</th><th>AI</th></tr></thead>
    <tbody>
    <?php foreach ($leads as $l): $score = $leadScores[(int) $l['id']]; ?>
      <tr>
        <td class="company-cell">
          <span class="row-name"><?php echo e($l['company_name']); ?></span>
          <?php if (!empty($l['quote_details'])): ?>
            <button type="button" class="btn-ghost quote-view-btn quote-pill" title="View full quote request"
              data-company="<?php echo e($l['company_name']); ?>"
              data-details="<?php echo e($l['quote_details']); ?>"
              data-phone="<?php echo e($l['contact_phone'] ?? ''); ?>"
              data-email="<?php echo e($l['contact_email'] ?? ''); ?>"
              data-contact="<?php echo e($l['contact_person'] ?? ''); ?>">
              📋 Quote
            </button>
          <?php endif; ?>
          <span class="row-sub"><?php echo e($l['reference_number']); ?><?php if (!empty($l['contact_person'])): ?> · <?php echo e($l['contact_person']); ?><?php endif; ?></span>
        </td>
        <td><span class="badge <?php echo e($stageBadgeClass[$l['stage']] ?? ''); ?>"><?php echo e($l['stage']); ?></span></td>
        <td><span class="badge <?php echo score_badge_class($score); ?>" title="Rule-based score: stage progress, recency of contact, deal size, momentum"><?php echo $score; ?></span></td>
        <td><span style="font-weight:600; color:var(--ink-900);"><?php echo fmt_ngn2($l['estimated_value_ngn']); ?></span><span class="row-sub"><?php echo e($l['lead_source'] ?? '—'); ?></span></td>
        <td class="owner-cell"><?php echo e($l['officer_name'] ?? '—'); ?></td>
        <td>
          <form method="POST" style="display:flex; gap:6px; align-items:center;" onsubmit="return handleStageForm(this)">
            <input type="hidden" name="action" value="update_stage">
            <input type="hidden" name="lead_id" value="<?php echo (int) $l['id']; ?>">
            <select name="stage" class="inline-select">
              <?php foreach (LEAD_STAGES as $s): ?>
                <option value="<?php echo e($s); ?>" <?php echo $s === $l['stage'] ? 'selected' : ''; ?>><?php echo e($s); ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-ghost" style="padding:6px 9px;" title="Save stage"><?php echo icon('check'); ?></button>
          </form>
        </td>
        <td>
          <?php if (!empty($l['client_id'])): ?>
            <span class="badge stage-client" title="Already linked to a client">Client</span>
          <?php else:
            $leadAddress = $l['country_region'] ?? '';
            if (!empty($l['quote_details'])) {
                $qd = json_decode($l['quote_details'], true);
                if (is_array($qd) && !empty($qd['Address'])) {
                    $leadAddress = $qd['Address'] . ($leadAddress ? ", $leadAddress" : '');
                }
            }
          ?>
            <button type="button" class="btn-secondary btn-sm convert-btn"
              data-lead-id="<?php echo (int) $l['id']; ?>"
              data-company="<?php echo e($l['company_name']); ?>"
              data-sector="<?php echo e($l['sector'] ?? ''); ?>"
              data-contact-name="<?php echo e($l['contact_person'] ?? ''); ?>"
              data-contact-email="<?php echo e($l['contact_email'] ?? ''); ?>"
              data-contact-phone="<?php echo e($l['contact_phone'] ?? ''); ?>"
              data-address="<?php echo e($leadAddress); ?>"
              data-stage="<?php echo e($l['stage']); ?>"
              data-lost-reason="<?php echo e($l['lost_reason'] ?? ''); ?>">
              <?php echo icon('building'); ?> Convert
            </button>
          <?php endif; ?>
        </td>
        <td>
          <div class="row-actions">
            <button type="button" class="btn-ghost" style="padding:6px 9px;" title="Draft a follow-up email with AI"
              onclick="runAi('followup', <?php echo (int) $l['id']; ?>, '<?php echo e(addslashes($l['company_name'])); ?>', 'Follow-up Draft')">
              <?php echo icon('send'); ?>
            </button>
            <button type="button" class="btn-ghost" style="padding:6px 9px;" title="AI: suggest next action"
              onclick="runAi('next_action', <?php echo (int) $l['id']; ?>, '<?php echo e(addslashes($l['company_name'])); ?>', 'Suggested Next Action')">
              <?php echo icon('target'); ?>
            </button>
            <button type="button" class="btn-ghost" style="padding:6px 9px;" title="AI: summarise this lead's history"
              onclick="runAi('lead_intelligence', <?php echo (int) $l['id']; ?>, '<?php echo e(addslashes($l['company_name'])); ?>', 'Lead Intelligence')">
              <?php echo icon('chart'); ?>
            </button>
            <button type="button" class="btn-ghost" style="padding:6px 9px;" title="View this lead's full trail (interactions, stage changes, reassignments)"
              onclick="viewLeadHistory(<?php echo (int) $l['id']; ?>, '<?php echo e(addslashes($l['company_name'])); ?>')">
              <?php echo icon('clock'); ?>
            </button>
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

<!-- Quote Details modal -->
<div id="quoteModal" style="display:none; position:fixed; inset:0; background:rgba(11,21,36,0.45); backdrop-filter:blur(4px); z-index:100; align-items:center; justify-content:center; padding:20px;">
  <div style="background:#fff; border-radius:var(--r-tile); border:1px solid var(--line); padding:24px; max-width:520px; width:100%; max-height:82vh; overflow-y:auto; box-shadow:var(--shadow-pop);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
      <h3 style="font-family:'Libre Franklin',sans-serif; font-size:16px;">Quote Request — <span id="quoteModalCompany"></span></h3>
      <button type="button" onclick="document.getElementById('quoteModal').style.display='none'" class="btn-ghost" style="padding:4px 8px;">✕</button>
    </div>
    <div id="quoteModalContact" style="font-size:12.5px; color:var(--ink-500); margin:8px 0 16px; padding-bottom:12px; border-bottom:1px solid var(--neutral-200);"></div>
    <div id="quoteModalBody" style="display:grid; grid-template-columns:1fr 1fr; gap:12px 18px; font-size:13px;"></div>
    <div class="form-actions"><button type="button" class="btn-primary" onclick="document.getElementById('quoteModal').style.display='none'">Close</button></div>
  </div>
</div>

<!-- Convert to Client modal -->
<div id="convertModal" style="display:none; position:fixed; inset:0; background:rgba(11,21,36,0.45); backdrop-filter:blur(4px); z-index:100; align-items:center; justify-content:center; padding:20px;">
  <div style="background:#fff; border-radius:var(--r-tile); border:1px solid var(--line); padding:24px; max-width:480px; width:100%; box-shadow:var(--shadow-pop);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
      <h3 style="font-family:'Libre Franklin',sans-serif; font-size:16px;">Convert to Client</h3>
      <button type="button" onclick="document.getElementById('convertModal').style.display='none'" class="btn-ghost" style="padding:4px 8px;">✕</button>
    </div>
    <div id="convertDealOutcome" style="margin-bottom:16px;"></div>
    <form method="POST">
      <input type="hidden" name="action" value="convert_to_client">
      <input type="hidden" name="lead_id" id="convertLeadId">
      <div class="form-grid">
        <div class="form-field full"><label>Company Name *</label><input type="text" name="company_name" id="convertCompanyName" required></div>
        <div class="form-field"><label>RC Number</label><input type="text" name="rc_number" placeholder="RC123456"></div>
        <div class="form-field"><label>Sector</label><input type="text" name="sector" id="convertSector"></div>
        <div class="form-field full"><label>Address</label><input type="text" name="address" id="convertAddress"></div>
        <div class="form-field"><label>Primary Contact Name</label><input type="text" name="contact_name" id="convertContactName"></div>
        <div class="form-field"><label>Primary Contact Email</label><input type="email" name="contact_email" id="convertContactEmail"></div>
        <div class="form-field"><label>Primary Contact Phone</label><input type="text" name="contact_phone" id="convertContactPhone"></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="document.getElementById('convertModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Create Client</button>
      </div>
    </form>
  </div>
</div>

<!-- AI modal — shared by all AI features on this page -->
<div id="aiModal" style="display:none; position:fixed; inset:0; background:rgba(11,21,36,0.45); backdrop-filter:blur(4px); z-index:100; align-items:center; justify-content:center; padding:20px;">
  <div style="background:#fff; border-radius:var(--r-tile); border:1px solid var(--line); padding:24px; max-width:480px; width:100%; box-shadow:var(--shadow-pop);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
      <h3 style="font-family:'Libre Franklin',sans-serif; font-size:16px;"><span id="aiModalTitle"></span> — <span id="aiModalCompany"></span></h3>
      <button onclick="document.getElementById('aiModal').style.display='none'" class="btn-ghost" style="padding:4px 8px;">✕</button>
    </div>
    <div style="display:inline-flex; align-items:center; gap:5px; font-size:10.5px; font-weight:700; color:var(--red-600); background:rgba(200,16,46,0.08); padding:3px 9px; border-radius:999px; margin-bottom:12px;">
      ⚡ AI-generated — review before use
    </div>
    <div id="aiModalBody" style="font-size:13.5px; color:var(--ink-700); line-height:1.6; white-space:pre-wrap; min-height:80px;">Working…</div>
    <div style="margin-top:16px; display:flex; gap:8px; justify-content:flex-end;">
      <button class="btn-secondary" onclick="copyAiDraft()">Copy Text</button>
      <button class="btn-primary" onclick="document.getElementById('aiModal').style.display='none'">Done</button>
    </div>
  </div>
</div>

<script>
function handleStageForm(form){
  const stage = form.querySelector('select[name="stage"]').value;
  if (stage === 'Lost') {
    const reason = prompt('Reason for Lost (Price, Competitor, No Budget, Timing, Scope Mismatch, Other):', 'Price');
    if (!reason) return false;
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = 'lost_reason'; input.value = reason;
    form.appendChild(input);
  }
  return true;
}

document.querySelectorAll('.quote-view-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('quoteModalCompany').textContent = btn.dataset.company;

    const contactBits = [];
    if (btn.dataset.contact) contactBits.push(btn.dataset.contact);
    if (btn.dataset.email) contactBits.push(btn.dataset.email);
    if (btn.dataset.phone) contactBits.push(btn.dataset.phone);
    document.getElementById('quoteModalContact').textContent = contactBits.join(' · ') || 'No contact details on file';

    const body = document.getElementById('quoteModalBody');
    body.innerHTML = '';
    let fields = {};
    try {
      fields = JSON.parse(btn.dataset.details);
    } catch (e) {
      // Older records stored this as plain text before this field was structured — show as-is.
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
      const wide = label === 'Business description' || label === 'Address';
      const item = document.createElement('div');
      if (wide) item.style.gridColumn = '1 / -1';
      item.innerHTML = '<div class="row-sub"></div><div style="font-weight:600; margin-top:2px; color:var(--ink-900);"></div>';
      item.querySelector('.row-sub').textContent = label;
      item.querySelector('div:last-child').textContent = value;
      body.appendChild(item);
    });

    document.getElementById('quoteModal').style.display = 'flex';
  });
});

document.querySelectorAll('.convert-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('convertLeadId').value = btn.dataset.leadId;
    document.getElementById('convertCompanyName').value = btn.dataset.company;
    document.getElementById('convertSector').value = btn.dataset.sector;
    document.getElementById('convertContactName').value = btn.dataset.contactName;
    document.getElementById('convertContactEmail').value = btn.dataset.contactEmail;
    document.getElementById('convertContactPhone').value = btn.dataset.contactPhone;
    document.getElementById('convertAddress').value = btn.dataset.address;

    const stage = btn.dataset.stage;
    const lostReason = btn.dataset.lostReason;
    const outcomeEl = document.getElementById('convertDealOutcome');
    // Built with textContent so stage / lost reason from the DB can't inject markup.
    const outcome = (badgeClass, badgeText, note) => {
      outcomeEl.innerHTML = '<span class="badge"></span> <span style="font-size:11.5px;color:var(--ink-500);"></span>';
      outcomeEl.firstChild.classList.add(badgeClass);
      outcomeEl.firstChild.textContent = badgeText;
      outcomeEl.lastChild.textContent = note;
    };
    if (stage === 'Won') {
      outcome('stage-client', 'Deal Won', '');
    } else if (stage === 'Lost') {
      outcome('stage-lost', 'Deal Lost' + (lostReason ? ' — ' + lostReason : ''), 'Converting a lost deal to a client is unusual — double check this is intended.');
    } else {
      outcome('stage-negotiation', 'Still open — currently at "' + stage + '"', 'Converting before the deal is Won is unusual — double check this is intended.');
    }

    document.getElementById('convertModal').style.display = 'flex';
  });
});

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
  const text = document.getElementById('aiModalBody').textContent;
  navigator.clipboard.writeText(text).then(() => alert('Copied to clipboard.'));
}

<?php if (isset($_GET['new'])): ?>
document.getElementById('newLeadForm').style.display = 'block';
document.querySelector('.page-head .btn-primary')?.style && (document.querySelector('.page-head .btn-primary').style.display = 'none');
document.getElementById('newLeadForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>