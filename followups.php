<?php
/**
 * IEMA CRMOps — Follow-ups / Interactions (FR-4.1 to FR-4.3)
 */
require_once __DIR__ . '/config.php';
require_login();

$role = current_role();
$uid  = (int) ($_SESSION['user_id'] ?? 0);
const INTERACTION_TYPES = ['Call', 'Email', 'Meeting', 'Site Visit'];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_interaction') {
    $leadId = (int) ($_POST['lead_id'] ?? 0) ?: null;
    $type   = $_POST['type'] ?? '';
    $date   = $_POST['interaction_date'] ?? date('Y-m-d\TH:i');
    $notes  = trim($_POST['notes'] ?? '');

    if (!in_array($type, INTERACTION_TYPES, true) || !$leadId) {
        $error = 'Please select a lead and interaction type.';
    } else {
        $clientStmt = $pdo->prepare('SELECT client_id FROM leads WHERE id = ?');
        $clientStmt->execute([$leadId]);
        $clientId = $clientStmt->fetchColumn() ?: null;

        $stmt = $pdo->prepare('INSERT INTO interactions (client_id, lead_id, type, interaction_date, notes, logged_by) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$clientId, $leadId, $type, str_replace('T', ' ', $date) . ':00', $notes, $uid]);
        $newId = (int) $pdo->lastInsertId();

        $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
        $audit->execute([$uid, 'create', 'interaction', $newId, "Logged $type"]);

        $success = ucfirst($type) . ' logged successfully.';
    }
}

$isOwnScoped = in_array($role, ['bd_officer', 'bd_manager', 'account_manager'], true);

$leadOptionsStmt = $pdo->prepare($isOwnScoped
    ? 'SELECT id, reference_number, company_name FROM leads WHERE assigned_to = ? ORDER BY company_name'
    : 'SELECT id, reference_number, company_name FROM leads ORDER BY company_name');
$leadOptionsStmt->execute($isOwnScoped ? [$uid] : []);
$leadOptions = $leadOptionsStmt->fetchAll();

// The feed itself is scoped by lead ownership, not by who logged each
// entry — a bd_officer (and, with identical function, an account_manager)
// needs to see the FULL trail on a lead that's theirs now, including
// notes the previous owner left before handing it over, not just the
// interactions they personally typed in. Everyone above bd_officer
// already sees every lead via Team Pipeline / Lead Assignment, so the
// feed is unscoped for them too.
$feedScoped = in_array($role, ['bd_officer', 'account_manager'], true);
$scopeSql = $feedScoped ? 'WHERE l.assigned_to = ?' : '';
$scopeParams = $feedScoped ? [$uid] : [];
$stmt = $pdo->prepare("
    SELECT i.*, COALESCE(l.company_name, c.company_name) AS company_name, u.name AS logged_by_name
    FROM interactions i
    LEFT JOIN leads l ON l.id = i.lead_id
    LEFT JOIN clients c ON c.id = i.client_id
    LEFT JOIN users u ON u.id = i.logged_by
    $scopeSql
    ORDER BY i.interaction_date DESC
");
$stmt->execute($scopeParams);
$interactions = $stmt->fetchAll();

$typeIcon = ['Call' => 'phone', 'Email' => 'mail', 'Meeting' => 'users', 'Site Visit' => 'building'];

require_once __DIR__ . '/includes/header.php';
?>

<?php
// Summary numbers for the bento tiles — derived only from $interactions (already loaded above).
$typeCounts = array_fill_keys(INTERACTION_TYPES, 0);
$recentCount = 0; $companyCounts = []; $loggerCounts = [];
$cutoff = strtotime('-30 days');
foreach ($interactions as $i) {
    if (isset($typeCounts[$i['type']])) $typeCounts[$i['type']]++;
    if (strtotime($i['interaction_date']) >= $cutoff) $recentCount++;
    $cn = $i['company_name'] ?? 'Unknown';
    $companyCounts[$cn] = ($companyCounts[$cn] ?? 0) + 1;
    $ln = $i['logged_by_name'] ?? '—';
    $loggerCounts[$ln] = ($loggerCounts[$ln] ?? 0) + 1;
}
arsort($companyCounts); arsort($loggerCounts);
$topCompanies = array_slice($companyCounts, 0, 5, true);
$topLoggers = array_slice($loggerCounts, 0, 5, true);
$maxCompany = $topCompanies ? max($topCompanies) : 1;
$latestAt = $interactions ? $interactions[0]['interaction_date'] : null;
?>
<style>
/* followups.php — page-scoped bento helpers */
.fu-aside{ display:flex; flex-direction:column; gap:var(--gap); min-width:0; align-self:start; position:sticky; top:88px; }
@media (max-width:1024px){ .fu-aside{ position:static; } }
.fu-feed .tile-scroll{ max-height:880px; }
@media (max-width:760px){ .fu-feed .tile-scroll{ max-height:640px; } }
.fu-form .form-grid{ grid-template-columns:1fr; }
.fu-types{ grid-template-columns:repeat(4, minmax(0,1fr)); }
@media (max-width:560px){ .fu-types{ grid-template-columns:repeat(2, minmax(0,1fr)); } }
.fu-types .stat{ display:flex; align-items:center; gap:12px; }
.fu-types .stat .tile-icon{ width:34px; height:34px; border-radius:10px; }
.fu-feed .activity-item{ align-items:flex-start; }
.fu-feed .activity-item:first-child{ padding-top:0; }
.fu-type-icon{ width:34px; height:34px; border-radius:10px; background:#EEF2F8; color:var(--navy-700); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.fu-type-icon .icon{ width:16px; height:16px; }
.fu-note{ color:var(--ink-700); margin-top:2px; overflow-wrap:anywhere; }
.rank-list{ display:flex; flex-direction:column; gap:12px; }
.rank-row{ display:grid; grid-template-columns:minmax(0,1fr) auto; gap:4px 10px; font-size:13px; }
.rank-row .n{ font-weight:700; color:var(--ink-700); font-size:12px; }
.rank-row .nm{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:var(--ink-900); font-weight:600; }
.rank-row .meter{ grid-column:1 / -1; height:6px; }
.rank-row .meter > span{ background:var(--navy-600); }
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Business Development</div>
    <h1>Follow-ups &amp; Interactions</h1>
    <p>Chronological interaction history across leads and clients (FR-4.1–FR-4.3).</p>
  </div>
  <div class="page-actions">
    <button class="btn-primary" onclick="document.getElementById('newInteractionForm').style.display='block'; this.style.display='none';">
      <?php echo icon('plus'); ?> Log Interaction
    </button>
  </div>
</div>

<?php if ($error): ?><div class="alert error"><?php echo icon('alert'); ?><span><?php echo e($error); ?></span></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?php echo icon('check'); ?><span><?php echo e($success); ?></span></div><?php endif; ?>

<div class="bento">
  <section class="tile tile--navy span-4 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Last 30 days</span>
      <span class="tile-icon"><?php echo icon('clock'); ?></span>
    </div>
    <div class="tile-value"><?php echo $recentCount; ?> <span style="font-size:14px; font-weight:600; color:rgba(255,255,255,0.65); letter-spacing:0;">interaction<?php echo $recentCount === 1 ? '' : 's'; ?></span></div>
    <div class="tile-sub"><?php echo count($interactions); ?> in total<?php echo $latestAt ? ' · latest ' . date('j M Y', strtotime($latestAt)) : ''; ?></div>
  </section>

  <section class="tile span-8">
    <div class="tile-head">
      <h2 class="tile-title">By type</h2>
      <span class="tile-sub" style="margin:0;"><?php echo $feedScoped ? 'Your leads' : 'All leads'; ?></span>
    </div>
    <div class="stat-row fu-types">
      <?php foreach (INTERACTION_TYPES as $t): ?>
        <div class="stat">
          <span class="tile-icon navy"><?php echo icon($typeIcon[$t] ?? 'clock'); ?></span>
          <div><div class="v"><?php echo (int) $typeCounts[$t]; ?></div><div class="l"><?php echo e($t); ?></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="tile span-8 fu-feed">
    <div class="tile-head">
      <h2 class="tile-title">Interaction history <span class="badge navy"><?php echo count($interactions); ?></span></h2>
    </div>
  <?php if (empty($interactions)): ?>
    <div class="empty-state">
      <div class="icon-button"><?php echo icon('clock'); ?></div>
      <p>No interactions logged yet.</p>
    </div>
  <?php else: ?>
    <div class="tile-scroll">
  <?php foreach ($interactions as $i): ?>
    <div class="activity-item">
      <div class="fu-type-icon"><?php echo icon($typeIcon[$i['type']] ?? 'clock'); ?></div>
      <div style="flex:1; min-width:0;">
        <div class="activity-text">
          <b><?php echo e($i['type']); ?></b> with <b><?php echo e($i['company_name'] ?? 'Unknown'); ?></b>
          <?php if (!empty($i['notes'])): ?><div class="fu-note"><?php echo e($i['notes']); ?></div><?php endif; ?>
        </div>
        <div class="activity-time"><?php echo date('j M Y, g:ia', strtotime($i['interaction_date'])); ?> · logged by <?php echo e($i['logged_by_name'] ?? '—'); ?></div>
      </div>
      <?php if (!empty($i['notes'])): ?>
        <button type="button" class="btn-ghost" style="padding:6px 9px; flex-shrink:0;" title="Summarise this note with AI"
          data-notes="<?php echo e($i['notes']); ?>" data-company="<?php echo e($i['company_name'] ?? 'Unknown'); ?>"
          onclick="summarizeInteraction(this.dataset.notes, this.dataset.company)">
          <?php echo icon('file'); ?>
        </button>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
    </div>
  <?php endif; ?>
  </section>

  <div class="span-4 fu-aside">
    <section class="tile fu-form" id="newInteractionForm" style="display:none;">
      <div class="tile-head">
        <h2 class="tile-title"><span class="tile-icon" style="width:30px;height:30px;border-radius:9px;"><?php echo icon('plus'); ?></span> Log Interaction</h2>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="log_interaction">
        <div class="form-grid">
          <div class="form-field">
            <label>Lead *</label>
            <select name="lead_id" required>
              <option value="">Select a lead…</option>
              <?php foreach ($leadOptions as $lo): ?>
                <option value="<?php echo (int) $lo['id']; ?>"><?php echo e($lo['reference_number'] . ' — ' . $lo['company_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-field">
            <label>Type *</label>
            <select name="type" required>
              <?php foreach (INTERACTION_TYPES as $t): ?><option value="<?php echo e($t); ?>"><?php echo e($t); ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-field"><label>Date &amp; Time</label><input type="datetime-local" name="interaction_date" value="<?php echo date('Y-m-d\TH:i'); ?>"></div>
          <div class="form-field full">
            <label>Notes</label>
            <textarea name="notes" id="interactionNotes" placeholder="What was discussed…"></textarea>
            <button type="button" class="btn-ghost btn-sm" style="margin-top:6px;" onclick="summarizeTypedNotes()">
              <?php echo icon('send'); ?> Summarise with AI
            </button>
          </div>
        </div>
        <div class="form-actions">
          <button type="button" class="btn-secondary" onclick="document.getElementById('newInteractionForm').style.display='none';">Cancel</button>
          <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Save</button>
        </div>
      </form>
    </section>

    <?php if ($interactions): ?>
    <section class="tile">
      <div class="tile-head"><h2 class="tile-title">Most contacted</h2></div>
      <?php if (!$topCompanies): ?>
        <p class="tile-sub">Nothing logged yet.</p>
      <?php else: ?>
      <div class="rank-list">
        <?php foreach ($topCompanies as $name => $n): ?>
          <div class="rank-row">
            <span class="nm"><?php echo e((string) $name); ?></span><span class="n"><?php echo (int) $n; ?></span>
            <div class="meter"><span style="width:<?php echo (int) round($n / $maxCompany * 100); ?>%;"></span></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <section class="tile tile--soft">
      <div class="tile-head"><h2 class="tile-title">Logged by</h2></div>
      <?php if (!$topLoggers): ?>
        <p class="tile-sub">Nothing logged yet.</p>
      <?php else: foreach ($topLoggers as $name => $n): ?>
        <div style="display:flex; justify-content:space-between; gap:10px; padding:8px 0; border-bottom:1px solid var(--line-soft); font-size:13px;">
          <span style="color:var(--ink-800);"><?php echo e((string) $name); ?></span>
          <span class="badge navy"><?php echo (int) $n; ?></span>
        </div>
      <?php endforeach; endif; ?>
    </section>
    <?php endif; ?>
  </div>
</div>

<!-- AI modal -->
<div id="aiModal" style="display:none; position:fixed; inset:0; background:rgba(11,21,36,0.45); backdrop-filter:blur(4px); z-index:100; align-items:center; justify-content:center; padding:20px;">
  <div style="background:#fff; border-radius:var(--r-tile); border:1px solid var(--line); padding:24px; max-width:520px; width:100%; box-shadow:var(--shadow-pop);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
      <h3 style="font-family:'Libre Franklin',sans-serif; font-size:16px;">AI Interaction Summary — <span id="aiModalCompany"></span></h3>
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
function runSummary(notesText, companyName){
  document.getElementById('aiModalCompany').textContent = companyName;
  document.getElementById('aiModalBody').textContent = 'Working…';
  document.getElementById('aiModal').style.display = 'flex';

  fetch('ai-draft.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'type=interaction_summary&notes=' + encodeURIComponent(notesText)
  })
  .then(r => r.json())
  .then(data => { document.getElementById('aiModalBody').textContent = data.text; })
  .catch(() => { document.getElementById('aiModalBody').textContent = 'Something went wrong reaching the AI service. Please try again.'; });
}

function summarizeInteraction(notes, company){ runSummary(notes, company); }

function summarizeTypedNotes(){
  const notes = document.getElementById('interactionNotes').value.trim();
  if (!notes) { alert('Type some notes first.'); return; }
  runSummary(notes, 'Draft Notes (not yet saved)');
}

function copyAiDraft(){
  navigator.clipboard.writeText(document.getElementById('aiModalBody').textContent).then(() => alert('Copied to clipboard.'));
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>