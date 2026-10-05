<?php
/**
 * IEMA CRMOps — System Settings (IT Admin)
 */
require_once __DIR__ . '/config.php';
require_login();

if (!role_is(['it_admin'])) {
    header('Location: dashboard.php');
    exit;
}

$dbStatus = 'Connected';
try { $pdo->query('SELECT 1'); } catch (Exception $e) { $dbStatus = 'Error'; }

$tableCounts = [];
foreach (['users', 'leads', 'clients', 'proposals', 'interactions', 'renewal_opportunities', 'audit_log'] as $t) {
    $tableCounts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
}

require_once __DIR__ . '/includes/header.php';
?>

<?php
$tcTotal = array_sum($tableCounts);
$tcMax   = max(1, max($tableCounts ?: [0]));
$outstanding = [
    ['AuditOps sync frequency', 'Not yet configured (proposed: daily)'],
    ['E-signature provider', 'Not yet selected (FR-3.7)'],
    ['Mobile/field access', 'Not yet confirmed as required'],
    ['Backup/recovery targets', 'To be defined with IT (NFR-5)'],
    ['Data Protection Officer', 'Not yet appointed (NDPA/GAID compliance dependency)'],
];
?>
<style>
.set-kpi { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
.set-value { font-family:'Libre Franklin',sans-serif; font-size:22px; font-weight:800; letter-spacing:-0.3px; margin-top:10px; line-height:1.2; }
.set-dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:8px; vertical-align:2px; }
.tc-row { display:grid; grid-template-columns:minmax(0,170px) minmax(0,1fr) 70px; gap:12px; align-items:center; padding:9px 0; border-bottom:1px solid var(--line-soft); }
.tc-row:last-child { border-bottom:none; }
.tc-name { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12.5px; color:var(--ink-800); font-weight:600; overflow:hidden; text-overflow:ellipsis; }
.tc-n { text-align:right; font-weight:700; color:var(--ink-900); font-size:13px; }
.tc-row .meter > span { background:var(--navy-600); }
.todo-list { list-style:none; display:flex; flex-direction:column; gap:10px; }
.todo-list li { display:flex; gap:11px; align-items:flex-start; padding:11px 12px; border:1px solid var(--line-soft); background:var(--neutral-50); border-radius:12px; }
.todo-list .tile-icon { width:30px; height:30px; border-radius:9px; }
.todo-list .tile-icon .icon { width:15px; height:15px; }
.todo-list b { display:block; font-size:13px; color:var(--ink-900); font-weight:600; }
.todo-list span { font-size:12px; color:var(--ink-500); }
@media (max-width:760px){
  .bento > .set-half { grid-column:span 6 !important; }
  .bento > .set-half.tile { padding:14px; }
  .set-half .set-value { font-size:18px; }
  .tc-row { grid-template-columns:minmax(0,1fr) 60px; }
  .tc-row .meter { grid-column:1 / -1; grid-row:2; }
}
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Administration</div>
    <h1>System Settings</h1>
    <p>Environment, database, and integration status.</p>
  </div>
</div>

<div class="bento">
  <section class="tile tile--navy span-3 set-half">
    <div class="set-kpi">
      <div class="tile-label">Database</div>
      <div class="tile-icon"><?php echo icon('shield'); ?></div>
    </div>
    <div class="set-value"><span class="set-dot" style="background:<?php echo $dbStatus === 'Connected' ? '#4ADE80' : 'var(--red-500)'; ?>;"></span><?php echo e($dbStatus); ?></div>
    <div class="tile-sub"><?php echo e(DB_NAME); ?></div>
  </section>
  <section class="tile span-3 set-half">
    <div class="set-kpi">
      <div class="tile-label">Environment</div>
      <div class="tile-icon navy"><?php echo icon('settings'); ?></div>
    </div>
    <div class="set-value"><?php echo e(APP_ENV); ?></div>
    <div class="tile-sub">APP_ENV</div>
  </section>
  <section class="tile span-3 set-half">
    <div class="set-kpi">
      <div class="tile-label">Session timeout</div>
      <div class="tile-icon navy"><?php echo icon('lock'); ?></div>
    </div>
    <div class="set-value"><?php echo (int) (SESSION_TIMEOUT_SECONDS / 60); ?> min</div>
    <div class="tile-sub">Idle sessions sign out</div>
  </section>
  <section class="tile span-3 set-half">
    <div class="set-kpi">
      <div class="tile-label">AuditOps sync</div>
      <div class="tile-icon amber"><?php echo icon('refresh'); ?></div>
    </div>
    <div class="set-value" style="color:var(--amber);">Not connected</div>
    <div class="tile-sub">Phase 2</div>
  </section>

  <section class="tile span-7">
    <div class="tile-head">
      <h2 class="tile-title">Table Record Counts</h2>
      <span class="badge navy"><?php echo number_format($tcTotal); ?> rows</span>
    </div>
    <?php foreach ($tableCounts as $t => $n): ?>
      <div class="tc-row">
        <div class="tc-name"><?php echo e($t); ?></div>
        <div class="meter"><span style="width:<?php echo max(2, round($n / $tcMax * 100)); ?>%;"></span></div>
        <div class="tc-n"><?php echo number_format($n); ?></div>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="tile span-5">
    <div class="tile-head">
      <h2 class="tile-title">Outstanding Configuration</h2>
      <span class="tile-sub" style="margin:0;">PRD Section 10</span>
    </div>
    <ul class="todo-list">
      <?php foreach ($outstanding as $o): ?>
        <li><div class="tile-icon amber"><?php echo icon('clock'); ?></div><div><b><?php echo e($o[0]); ?></b><span><?php echo e($o[1]); ?></span></div></li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
