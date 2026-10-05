<?php
/**
 * IEMA CRMOps — Escalations
 * -----------------------------------------------------------------------
 * Client Relationship Managers raise issues here; Management and IT Admin
 * see and resolve them (per design — not visible to BD Manager/Officer,
 * since these are post-sale account issues, not pipeline matters).
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_login();

if (!role_is(['account_manager', 'management', 'it_admin'])) {
    header('Location: dashboard.php');
    exit;
}

$role = current_role();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'raise_escalation') {
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $issue = trim($_POST['issue'] ?? '');
    if ($clientId <= 0 || $issue === '') {
        $error = 'Please select a client and describe the issue.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO client_escalations (client_id, issue, raised_by) VALUES (?,?,?)');
        $stmt->execute([$clientId, $issue, $uid]);
        $escId = (int) $pdo->lastInsertId();
        $success = 'Escalation raised.';
        $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
        $audit->execute([$uid, 'create', 'escalation', $escId, "Escalation raised: $issue"]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resolve_escalation') {
    $escId = (int) ($_POST['escalation_id'] ?? 0);
    $notes = trim($_POST['resolution_notes'] ?? '');
    $stmt = $pdo->prepare("UPDATE client_escalations SET status='Resolved', resolved_by=?, resolved_at=NOW(), resolution_notes=? WHERE id=?");
    $stmt->execute([$uid, $notes ?: null, $escId]);
    $success = 'Escalation marked resolved.';
}

// account_manager sees only their own portfolio's escalations; Management/IT Admin see all.
$scopeSql = $role === 'account_manager' ? 'WHERE c.assigned_crm_id = ?' : '';
$scopeParams = $role === 'account_manager' ? [$uid] : [];

$stmt = $pdo->prepare("
    SELECT e.*, c.company_name, u1.name AS raised_by_name, u2.name AS resolved_by_name
    FROM client_escalations e
    JOIN clients c ON c.id = e.client_id
    LEFT JOIN users u1 ON u1.id = e.raised_by
    LEFT JOIN users u2 ON u2.id = e.resolved_by
    $scopeSql
    ORDER BY e.status = 'Open' DESC, e.raised_at DESC
");
$stmt->execute($scopeParams);
$escalations = $stmt->fetchAll();

$clientOptionsStmt = $pdo->prepare($role === 'account_manager'
    ? 'SELECT id, company_name FROM clients WHERE assigned_crm_id = ? ORDER BY company_name'
    : 'SELECT id, company_name FROM clients WHERE assigned_crm_id IS NOT NULL ORDER BY company_name');
$clientOptionsStmt->execute($role === 'account_manager' ? [$uid] : []);
$clientOptions = $clientOptionsStmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<?php
// Summary numbers — counted from the list this page already loaded.
$escOpen      = count(array_filter($escalations, fn($x) => $x['status'] === 'Open'));
$escResolved  = count($escalations) - $escOpen;
$escClients   = count(array_unique(array_map(fn($x) => (int) $x['client_id'], array_filter($escalations, fn($x) => $x['status'] === 'Open'))));
$escOldestOpen = null;
foreach ($escalations as $x) {
    if ($x['status'] === 'Open' && ($escOldestOpen === null || strtotime($x['raised_at']) < strtotime($escOldestOpen))) {
        $escOldestOpen = $x['raised_at'];
    }
}
$escOldestDays = $escOldestOpen ? (int) floor((time() - strtotime($escOldestOpen)) / 86400) : null;
?>
<style>
.esc-item { display:flex; gap:14px; padding:16px 0; border-bottom:1px solid var(--line-soft); align-items:flex-start; }
.esc-item:first-child { padding-top:4px; }
.esc-item:last-child { border-bottom:none; padding-bottom:0; }
.esc-mark { width:36px; height:36px; border-radius:11px; display:flex; align-items:center; justify-content:center; flex-shrink:0; background:var(--red-50); color:var(--red-600); }
.esc-mark.done { background:var(--green-50); color:var(--green); }
.esc-mark .icon { width:17px; height:17px; }
.esc-top { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.esc-company { font-weight:700; color:var(--ink-900); font-size:13.5px; }
.esc-issue { font-size:13px; color:var(--ink-700); line-height:1.55; margin-top:4px; }
.esc-meta { font-size:11.5px; color:var(--ink-500); margin-top:5px; }
.esc-resolution { font-size:12px; color:var(--ink-700); background:var(--green-50); border:1px solid var(--green-200); border-radius:10px; padding:8px 10px; margin-top:8px; }
.esc-resolve { margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; }
.esc-resolve input[type=text] { flex:1 1 220px; min-width:0; padding:8px 11px; border-radius:9px; border:1px solid var(--neutral-300); font-size:12.5px; font-family:inherit; background:#fff; }
.esc-resolve input[type=text]:focus { outline:none; border-color:var(--red-600); box-shadow:var(--ring); }
.esc-kpi { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
.esc-side { display:flex; flex-direction:column; gap:var(--gap); min-width:0; }
.esc-side .card-form { margin-bottom:0; }
.esc-side .form-grid { grid-template-columns:1fr; }
.esc-steps { list-style:none; display:flex; flex-direction:column; gap:12px; }
.esc-steps li { display:flex; gap:10px; font-size:12.5px; color:var(--ink-700); line-height:1.5; }
.esc-steps .n { width:22px; height:22px; border-radius:7px; background:#EEF2F8; color:var(--navy-700); font-size:11px; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
@media (max-width:760px){
  .bento > .esc-half { grid-column:span 6 !important; }
  .bento > .esc-half.tile { padding:14px; }
  .esc-half .tile-value { font-size:24px; }
}
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Account care</div>
    <h1>Escalations</h1>
    <p>Client issues raised by account owners, routed to Management and IT Admin.</p>
  </div>
  <?php if ($role === 'account_manager'): ?>
  <div class="page-actions">
  <button class="btn-primary" onclick="document.getElementById('newEscForm').style.display='block'; this.style.display='none';">
    <?php echo icon('plus'); ?> Raise Escalation
  </button>
  </div>
  <?php endif; ?>
</div>

<?php if ($error): ?><div class="alert error"><?php echo icon('alert'); ?><span><?php echo e($error); ?></span></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?php echo icon('check'); ?><span><?php echo e($success); ?></span></div><?php endif; ?>

<div class="bento">
  <section class="tile tile--navy span-4">
    <div class="esc-kpi">
      <div class="tile-label">Open escalations</div>
      <div class="tile-icon"><?php echo icon('alert'); ?></div>
    </div>
    <div class="tile-value"><?php echo $escOpen; ?></div>
    <div class="tile-sub">
      <?php if ($escOpen > 0): ?>
        Across <?php echo $escClients; ?> client<?php echo $escClients === 1 ? '' : 's'; ?> · oldest raised <?php echo $escOldestDays === 0 ? 'today' : $escOldestDays . ' day' . ($escOldestDays === 1 ? '' : 's') . ' ago'; ?>
      <?php else: ?>
        Nothing waiting — all clear
      <?php endif; ?>
    </div>
  </section>
  <section class="tile span-4 esc-half">
    <div class="esc-kpi">
      <div class="tile-label">Resolved</div>
      <div class="tile-icon green"><?php echo icon('check'); ?></div>
    </div>
    <div class="tile-value"><?php echo $escResolved; ?></div>
    <div class="tile-sub">Closed with notes</div>
  </section>
  <section class="tile span-4 esc-half">
    <div class="esc-kpi">
      <div class="tile-label">Total raised</div>
      <div class="tile-icon navy"><?php echo icon('clipboard'); ?></div>
    </div>
    <div class="tile-value"><?php echo count($escalations); ?></div>
    <div class="tile-sub"><?php echo $role === 'account_manager' ? 'In your portfolio' : 'All account managers'; ?></div>
  </section>

  <section class="tile span-8">
    <div class="tile-head">
      <h2 class="tile-title">Escalation log <span class="badge navy"><?php echo count($escalations); ?></span></h2>
      <span class="tile-sub" style="margin:0;">Open first, newest first</span>
    </div>
    <?php if (empty($escalations)): ?>
      <div class="empty-state"><div class="icon-button"><?php echo icon('alert'); ?></div><p>No escalations.</p></div>
    <?php else: foreach ($escalations as $e): $isOpen = $e['status'] === 'Open'; ?>
      <div class="esc-item">
        <div class="esc-mark<?php echo $isOpen ? '' : ' done'; ?>"><?php echo icon($isOpen ? 'alert' : 'check'); ?></div>
        <div style="flex:1; min-width:0;">
          <div class="esc-top">
            <span class="esc-company"><?php echo e($e['company_name']); ?></span>
            <span class="badge <?php echo $isOpen ? 'red' : 'stage-client'; ?>"><?php echo e($e['status']); ?></span>
          </div>
          <div class="esc-issue"><?php echo e($e['issue']); ?></div>
          <div class="esc-meta">Raised by <?php echo e($e['raised_by_name'] ?? '—'); ?> · <?php echo date('j M Y, g:ia', strtotime($e['raised_at'])); ?></div>
          <?php if ($e['status'] === 'Resolved'): ?>
            <div class="esc-resolution"><b>Resolved by <?php echo e($e['resolved_by_name'] ?? '—'); ?></b><?php if ($e['resolution_notes']): ?> — <?php echo e($e['resolution_notes']); ?><?php endif; ?></div>
          <?php elseif (in_array($role, ['management', 'it_admin'], true)): ?>
            <form method="POST" class="esc-resolve">
              <input type="hidden" name="action" value="resolve_escalation">
              <input type="hidden" name="escalation_id" value="<?php echo (int) $e['id']; ?>">
              <input type="text" name="resolution_notes" placeholder="Resolution notes (optional)">
              <button type="submit" class="btn-navy btn-sm"><?php echo icon('check'); ?> Mark Resolved</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </section>

  <div class="span-4 esc-side">
    <?php if ($role === 'account_manager'): ?>
    <div class="card-form" id="newEscForm" style="display:none;">
      <div class="tile-head"><h2 class="tile-title"><?php echo icon('plus'); ?> Raise an escalation</h2></div>
      <form method="POST">
        <input type="hidden" name="action" value="raise_escalation">
        <div class="form-grid">
          <div class="form-field full">
            <label>Client *</label>
            <select name="client_id" required>
              <option value="">Select…</option>
              <?php foreach ($clientOptions as $c): ?><option value="<?php echo (int) $c['id']; ?>"><?php echo e($c['company_name']); ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-field full"><label>Issue *</label><textarea name="issue" required></textarea></div>
        </div>
        <div class="form-actions">
          <button type="button" class="btn-secondary" onclick="document.getElementById('newEscForm').style.display='none';">Cancel</button>
          <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Raise</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <section class="tile tile--soft">
      <div class="tile-head"><h2 class="tile-title">How escalations work</h2></div>
      <ol class="esc-steps">
        <li><span class="n">1</span><span>A Client Relationship Manager raises an issue against one of their clients.</span></li>
        <li><span class="n">2</span><span>Management and IT Admin see every open escalation here.</span></li>
        <li><span class="n">3</span><span>Whoever fixes it marks it resolved, with notes for the account owner.</span></li>
      </ol>
      <p class="tile-sub" style="margin-top:14px;">Post-sale account issues only — not visible to the BD pipeline team.</p>
    </section>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
