<?php
/**
 * IEMA CRMOps — AuditOps Sync History
 */
require_once __DIR__ . '/config.php';
require_login();

if (!role_is(['it_admin', 'management'])) {
    header('Location: dashboard.php');
    exit;
}

$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$where = [];
$params = [];
if ($typeFilter && in_array($typeFilter, ['client_push', 'renewal_pull'], true)) {
    $where[] = 'sync_type = ?';
    $params[] = $typeFilter;
}
if ($statusFilter && in_array($statusFilter, ['success', 'failed'], true)) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT * FROM sync_log $whereSql ORDER BY created_at DESC LIMIT 200");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$totalStmt = $pdo->query("SELECT status, COUNT(*) AS n FROM sync_log GROUP BY status");
$totals = ['success' => 0, 'failed' => 0];
foreach ($totalStmt->fetchAll() as $r) { $totals[$r['status']] = (int) $r['n']; }

require_once __DIR__ . '/includes/header.php';
?>

<?php
// Summary numbers — from the totals and the log rows already loaded.
$sTotal  = $totals['success'] + $totals['failed'];
$sRate   = $sTotal > 0 ? round($totals['success'] / $sTotal * 100) : null;
$sLast   = $logs[0] ?? null;
$sLastFail = null;
foreach ($logs as $l) { if ($l['status'] === 'failed') { $sLastFail = $l; break; } }
?>
<style>
.sync-kpi { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
.sync-table td.when { white-space:nowrap; color:var(--ink-500); font-size:12.5px; }
.sync-table td.details { line-height:1.5; min-width:260px; }
.sync-dir { display:inline-flex; align-items:center; gap:6px; white-space:nowrap; font-weight:600; color:var(--ink-800); }
.sync-dir .icon { width:15px; height:15px; color:var(--navy-600); }
@media (max-width:760px){
  .bento > .sync-half { grid-column:span 6 !important; }
  .bento > .sync-half.tile { padding:14px; }
  .sync-half .tile-value { font-size:24px; }
}
@media (max-width:760px){
  .table-wrap table.sync-table { min-width:0; }
  .sync-table thead { display:none; }
  .sync-table, .sync-table tbody { display:block; }
  .sync-table tr { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:6px 10px; padding:12px 0; border-bottom:1px solid var(--line-soft); }
  .sync-table tr:last-child { border-bottom:none; }
  .sync-table td { display:block; padding:0; border:none; min-width:0 !important; }
  .sync-table td:nth-child(1) { grid-column:1; grid-row:1; }
  .sync-table td:nth-child(4) { grid-column:2; grid-row:1; }
  .sync-table td:nth-child(2) { grid-column:1; grid-row:2; }
  .sync-table td:nth-child(3) { grid-column:2; grid-row:2; text-align:right; }
  .sync-table td:nth-child(5) { grid-column:1 / -1; grid-row:3; font-size:12.5px; }
  .sync-table tbody tr:hover { background:transparent; }
}
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Integrations</div>
    <h1>AuditOps Sync History</h1>
    <p>Every sync attempt between CRMOps and AuditOps — client pushes now, renewal pulls once built.</p>
  </div>
</div>

<div class="bento">
  <section class="tile tile--navy span-4">
    <div class="sync-kpi">
      <div class="tile-label">Success rate</div>
      <div class="tile-icon"><?php echo icon('refresh'); ?></div>
    </div>
    <div class="tile-value"><?php echo $sRate === null ? '—' : $sRate . '%'; ?></div>
    <div class="tile-sub"><?php echo $sTotal; ?> sync attempt<?php echo $sTotal === 1 ? '' : 's'; ?> recorded<?php if ($sLast): ?> · last <?php echo date('j M, g:ia', strtotime($sLast['created_at'])); ?><?php endif; ?></div>
  </section>
  <section class="tile span-4 sync-half">
    <div class="sync-kpi">
      <div class="tile-label">Successful</div>
      <div class="tile-icon green"><?php echo icon('check'); ?></div>
    </div>
    <div class="tile-value"><?php echo $totals['success']; ?></div>
    <div class="tile-sub">Pushed or pulled cleanly</div>
  </section>
  <section class="tile span-4 sync-half">
    <div class="sync-kpi">
      <div class="tile-label">Failed</div>
      <div class="tile-icon"><?php echo icon('alert'); ?></div>
    </div>
    <div class="tile-value" style="<?php echo $totals['failed'] > 0 ? 'color:var(--red-600);' : ''; ?>"><?php echo $totals['failed']; ?></div>
    <div class="tile-sub"><?php echo $sLastFail ? 'Last failure ' . date('j M, g:ia', strtotime($sLastFail['created_at'])) : 'No failures in view'; ?></div>
  </section>

  <section class="tile span-12">
    <div class="tile-head">
      <h2 class="tile-title">Sync attempts <span class="badge navy"><?php echo count($logs); ?></span></h2>
      <div class="filter-chips">
        <a class="filter-chip<?php echo $statusFilter === '' ? ' active' : ''; ?>" href="sync-log.php">All</a>
        <a class="filter-chip<?php echo $statusFilter === 'success' ? ' active' : ''; ?>" href="sync-log.php?status=success">Success</a>
        <a class="filter-chip<?php echo $statusFilter === 'failed' ? ' active' : ''; ?>" href="sync-log.php?status=failed">Failed</a>
      </div>
    </div>
    <?php if (empty($logs)): ?>
      <div class="empty-state"><div class="icon-button"><?php echo icon('refresh'); ?></div><p>No sync events recorded yet.</p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="sync-table">
      <thead><tr><th>When</th><th>Type</th><th>Entity</th><th>Status</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td class="when"><?php echo date('j M Y, g:ia', strtotime($l['created_at'])); ?></td>
          <td><span class="sync-dir"><?php echo icon($l['sync_type'] === 'client_push' ? 'send' : 'download'); ?><?php echo $l['sync_type'] === 'client_push' ? 'Client → AuditOps' : 'Renewal ← AuditOps'; ?></span></td>
          <td style="white-space:nowrap;"><?php echo e(ucfirst($l['entity_type'])); ?><?php if ($l['entity_id']): ?> <span class="row-sub">#<?php echo (int) $l['entity_id']; ?></span><?php endif; ?></td>
          <td><span class="badge <?php echo $l['status'] === 'success' ? 'stage-client' : 'red'; ?>"><?php echo e(ucfirst($l['status'])); ?></span></td>
          <td class="details"><?php echo e($l['details'] ?? '—'); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </section>
</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
