<?php
/**
 * IEMA CRMOps — Renewals & Upsell (FR-5.1 to FR-5.3)
 */
require_once __DIR__ . '/config.php';
require_login();

$uid = (int) ($_SESSION['user_id'] ?? 0);
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_followup') {
    $id = (int) ($_POST['renewal_id'] ?? 0);
    $status = $_POST['follow_up_status'] ?? '';
    if ($id > 0 && in_array($status, ['Pending', 'Flagged', 'Contacted', 'Completed'], true)) {
        $pdo->prepare('UPDATE renewal_opportunities SET follow_up_status = ? WHERE id = ?')->execute([$status, $id]);
        $success = 'Follow-up status updated.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_opportunity') {
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $date = $_POST['renewal_date'] ?? '';
    $type = $_POST['opportunity_type'] ?? 'Upsell';
    $notes = trim($_POST['notes'] ?? '');
    if ($clientId > 0 && $date !== '') {
        $pdo->prepare('INSERT INTO renewal_opportunities (client_id, renewal_date, opportunity_type, notes) VALUES (?,?,?,?)')
            ->execute([$clientId, $date, $type, $notes]);
        $success = 'Opportunity logged.';
    }
}

$clients = $pdo->query('SELECT id, company_name FROM clients ORDER BY company_name')->fetchAll();

$stmt = $pdo->query("
    SELECT r.*, c.company_name
    FROM renewal_opportunities r JOIN clients c ON c.id = r.client_id
    ORDER BY r.renewal_date ASC
");
$renewals = $stmt->fetchAll();

$statusColor = ['Pending' => 'stage-lead', 'Flagged' => 'stage-negotiation', 'Contacted' => 'stage-proposal', 'Completed' => 'stage-client'];

// Presentation-only summary counts from the array loaded above.
$rnDueSoon = 0; $rnOverdue = 0; $rnByStatus = ['Pending' => 0, 'Flagged' => 0, 'Contacted' => 0, 'Completed' => 0]; $rnByType = [];
foreach ($renewals as $r) {
    $d = (strtotime($r['renewal_date']) - time()) / 86400;
    $open = ($r['follow_up_status'] ?? '') !== 'Completed';
    if ($d >= 0 && $d <= 60) { $rnDueSoon++; }
    if ($d < 0 && $open) { $rnOverdue++; }
    if (isset($rnByStatus[$r['follow_up_status']])) { $rnByStatus[$r['follow_up_status']]++; }
    $t = (string) ($r['opportunity_type'] ?? 'Other');
    $rnByType[$t] = ($rnByType[$t] ?? 0) + 1;
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
  .rn-update{ display:flex; gap:6px; align-items:center; }
  .rn-update select{ padding:6px 8px; border-radius:9px; border:1px solid var(--neutral-300); background:#fff; font-size:12px; font-family:inherit; color:var(--ink-900); }
  .rn-update select:focus{ border-color:var(--red-600); box-shadow:var(--ring); outline:none; }
  .rn-date{ white-space:nowrap; }
  .rn-date .badge{ margin-left:4px; }
  .rn-notes{ max-width:340px; color:var(--ink-700); line-height:1.45; }
  #newOppForm .form-actions{ margin-top:16px; }
  .rn-client{ min-width:150px; }
  @media (max-width:760px){ .bento > .rn-stat{ grid-column:span 6 !important; } .rn-stat .tile-value{ font-size:24px; } }
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Account growth</div>
    <h1>Renewals &amp; Upsell</h1>
    <p>Commercial renewal follow-ups and cross-sell opportunities (FR-5.1–FR-5.3).</p>
  </div>
  <div class="page-actions">
    <button class="btn-primary" onclick="document.getElementById('newOppForm').style.display='block'; this.style.display='none';">
      <?php echo icon('plus'); ?> Log Opportunity
    </button>
  </div>
</div>

<?php if ($success): ?><div class="alert success"><?php echo icon('check'); ?><span><?php echo e($success); ?></span></div><?php endif; ?>

<div class="bento">
  <section class="tile tile--navy span-3 flex-col rn-stat">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Due in 60 days</span>
      <div class="tile-icon"><?php echo icon('calendar'); ?></div>
    </div>
    <div class="tile-value"><?php echo (int) $rnDueSoon; ?></div>
    <div class="tile-sub"><?php echo $rnOverdue > 0 ? (int) $rnOverdue . ' past date and still open' : 'Nothing past date left open'; ?></div>
  </section>
  <section class="tile span-3 flex-col rn-stat">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Flagged</span>
      <div class="tile-icon"><?php echo icon('alert'); ?></div>
    </div>
    <div class="tile-value"><?php echo (int) $rnByStatus['Flagged']; ?></div>
    <div class="tile-sub">Need attention from the account team</div>
  </section>
  <section class="tile span-3 flex-col rn-stat">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">In progress</span>
      <div class="tile-icon amber"><?php echo icon('clock'); ?></div>
    </div>
    <div class="tile-value"><?php echo (int) ($rnByStatus['Pending'] + $rnByStatus['Contacted']); ?></div>
    <div class="tile-sub"><?php echo (int) $rnByStatus['Pending']; ?> pending · <?php echo (int) $rnByStatus['Contacted']; ?> contacted</div>
  </section>
  <section class="tile span-3 flex-col rn-stat">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Completed</span>
      <div class="tile-icon green"><?php echo icon('check'); ?></div>
    </div>
    <div class="tile-value"><?php echo (int) $rnByStatus['Completed']; ?></div>
    <div class="tile-sub">of <?php echo count($renewals); ?> logged opportunities</div>
  </section>

  <section class="tile span-12" id="newOppForm" style="display:none;">
    <div class="tile-head">
      <h2 class="tile-title"><?php echo icon('plus'); ?> Log Opportunity</h2>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="create_opportunity">
      <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
        <div class="form-field">
          <label>Client *</label>
          <select name="client_id" required>
            <option value="">Select client…</option>
            <?php foreach ($clients as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo e($c['company_name']); ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-field"><label>Date *</label><input type="date" name="renewal_date" required></div>
        <div class="form-field"><label>Type</label>
          <select name="opportunity_type"><option>Renewal</option><option>Upsell</option><option>Cross-sell</option></select>
        </div>
        <div class="form-field full"><label>Notes</label><textarea name="notes"></textarea></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="document.getElementById('newOppForm').style.display='none';">Cancel</button>
        <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Save</button>
      </div>
    </form>
  </section>

  <section class="tile span-12">
    <div class="tile-head">
      <h2 class="tile-title"><?php echo icon('refresh'); ?> Opportunities <span class="badge navy"><?php echo count($renewals); ?></span></h2>
      <?php if (!empty($rnByType)): ?>
        <div class="filter-chips" aria-label="Opportunities by type">
          <?php foreach ($rnByType as $t => $n): ?>
            <span class="badge" style="background:var(--neutral-100); color:var(--ink-700);"><?php echo e($t); ?> · <?php echo (int) $n; ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php if (empty($renewals)): ?>
      <div class="empty-state"><div class="icon-button"><?php echo icon('refresh'); ?></div><p>No renewal or upsell opportunities logged yet.</p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead><tr><th>Client</th><th>Type</th><th>Date</th><th>Status</th><th>Notes</th><th>Update</th></tr></thead>
      <tbody>
      <?php foreach ($renewals as $r):
        $daysOut = (strtotime($r['renewal_date']) - time()) / 86400;
      ?>
        <tr>
          <td class="row-name rn-client"><?php echo e($r['company_name']); ?></td>
          <td style="white-space:nowrap;"><?php echo e($r['opportunity_type']); ?></td>
          <td class="rn-date"><?php echo date('j M Y', strtotime($r['renewal_date'])); ?><?php if ($daysOut >= 0 && $daysOut <= 60): ?> <span class="badge red">Due soon</span><?php endif; ?></td>
          <td><span class="badge <?php echo e($statusColor[$r['follow_up_status']] ?? ''); ?>"><?php echo e($r['follow_up_status']); ?></span></td>
          <td class="rn-notes"><?php echo e($r['notes'] ?? '—'); ?></td>
          <td>
            <form method="POST" class="rn-update">
              <input type="hidden" name="action" value="update_followup">
              <input type="hidden" name="renewal_id" value="<?php echo (int) $r['id']; ?>">
              <select name="follow_up_status" aria-label="Follow-up status">
                <?php foreach (['Pending','Flagged','Contacted','Completed'] as $s): ?>
                  <option value="<?php echo e($s); ?>" <?php echo $s === $r['follow_up_status'] ? 'selected' : ''; ?>><?php echo e($s); ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn-secondary btn-sm" title="Save status"><?php echo icon('check'); ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
