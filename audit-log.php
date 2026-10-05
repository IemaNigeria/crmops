<?php
/**
 * IEMA CRMOps — Audit Log (NFR-2)
 */
require_once __DIR__ . '/config.php';
require_login();

if (!role_is(['it_admin', 'management'])) {
    header('Location: dashboard.php');
    exit;
}

$actionFilter = $_GET['action'] ?? '';
$where = '';
$params = [];
if ($actionFilter && in_array($actionFilter, ['create', 'update', 'delete', 'login'], true)) {
    $where = 'WHERE a.action = ?';
    $params[] = $actionFilter;
}

$stmt = $pdo->prepare("
    SELECT a.*, u.name AS actor
    FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
    $where
    ORDER BY a.created_at DESC LIMIT 100
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actionColor = ['create' => 'stage-client', 'update' => 'stage-proposal', 'delete' => 'stage-lost', 'login' => 'stage-lead'];

require_once __DIR__ . '/includes/header.php';
?>

<?php
// Summary numbers — counted from the (up to 100) events already loaded.
$aByAction = ['create' => 0, 'update' => 0, 'delete' => 0, 'login' => 0];
$aActors   = [];
foreach ($logs as $l) {
    if (isset($aByAction[$l['action']])) { $aByAction[$l['action']]++; }
    $aActors[$l['actor'] ?? 'System'] = true;
}
$aLatest = $logs[0]['created_at'] ?? null;
$aIcons  = ['create' => 'plus', 'update' => 'edit', 'delete' => 'trash', 'login' => 'lock'];
?>
<style>
.audit-kpi { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
.audit-stat { display:flex; align-items:center; gap:10px; }
.audit-stat .tile-icon { width:34px; height:34px; border-radius:10px; }
.audit-stat .tile-icon .icon { width:16px; height:16px; }
.audit-table td.when { white-space:nowrap; color:var(--ink-500); font-size:12.5px; }
.audit-table td.details { color:var(--ink-700); line-height:1.5; min-width:260px; }
.audit-table td.entity { white-space:nowrap; }
.audit-actor { display:flex; align-items:center; gap:8px; white-space:nowrap; }
.audit-actor .avatar { width:26px; height:26px; font-size:10px; border-radius:8px; }
.audit-actor .avatar.sys { background:var(--neutral-200); color:var(--ink-500); }
@media (max-width:760px){
  .table-wrap table.audit-table { min-width:0; }
  .audit-table thead { display:none; }
  .audit-table, .audit-table tbody { display:block; }
  .audit-table tr { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:6px 10px; padding:12px 0; border-bottom:1px solid var(--line-soft); }
  .audit-table tr:last-child { border-bottom:none; }
  .audit-table td { display:block; padding:0; border:none; min-width:0 !important; }
  .audit-table td:nth-child(1) { grid-column:1; grid-row:1; }
  .audit-table td:nth-child(3) { grid-column:2; grid-row:1; }
  .audit-table td:nth-child(2) { grid-column:1; grid-row:2; }
  .audit-table td:nth-child(4) { grid-column:2; grid-row:2; text-align:right; }
  .audit-table td:nth-child(5) { grid-column:1 / -1; grid-row:3; font-size:12.5px; }
  .audit-table tbody tr:hover { background:transparent; }
}
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Compliance</div>
    <h1>Audit Log</h1>
    <p>Record creation and edit history, in line with NDPA 2023 accountability requirements (NFR-2).</p>
  </div>
</div>

<div class="bento">
  <section class="tile tile--navy span-4">
    <div class="audit-kpi">
      <div class="tile-label"><?php echo $actionFilter ? e(ucfirst($actionFilter)) . ' events' : 'Events shown'; ?></div>
      <div class="tile-icon"><?php echo icon('shield'); ?></div>
    </div>
    <div class="tile-value"><?php echo count($logs); ?></div>
    <div class="tile-sub">
      Latest 100 max · <?php echo count($aActors); ?> actor<?php echo count($aActors) === 1 ? '' : 's'; ?><?php if ($aLatest): ?> · last <?php echo date('j M, g:ia', strtotime($aLatest)); ?><?php endif; ?>
    </div>
  </section>

  <section class="tile span-8">
    <div class="tile-head" style="margin-bottom:12px;">
      <h2 class="tile-title">Breakdown</h2>
      <span class="tile-sub" style="margin:0;">Of the events shown</span>
    </div>
    <div class="stat-row">
      <?php foreach ($aByAction as $act => $n): ?>
        <div class="stat audit-stat">
          <div class="tile-icon <?php echo $act === 'delete' ? '' : ($act === 'create' ? 'green' : ($act === 'update' ? 'amber' : 'navy')); ?>"><?php echo icon($aIcons[$act]); ?></div>
          <div>
            <div class="v"><?php echo $n; ?></div>
            <div class="l"><?php echo e(ucfirst($act)); ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="tile span-12">
    <div class="tile-head">
      <h2 class="tile-title">Event history <span class="badge navy"><?php echo count($logs); ?></span></h2>
      <div class="filter-chips">
        <a class="filter-chip<?php echo $actionFilter === '' ? ' active' : ''; ?>" href="audit-log.php">All</a>
        <?php foreach (['create', 'update', 'delete', 'login'] as $a): ?>
          <a class="filter-chip<?php echo $actionFilter === $a ? ' active' : ''; ?>" href="audit-log.php?action=<?php echo urlencode($a); ?>"><?php echo e(ucfirst($a)); ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (empty($logs)): ?>
      <div class="empty-state"><div class="icon-button"><?php echo icon('shield'); ?></div><p>No audit events recorded yet.</p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="audit-table">
      <thead><tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $l):
          $actor = $l['actor'] ?? 'System';
          $ini = '';
          foreach (array_slice(preg_split('/\s+/', trim((string) $actor)), 0, 2) as $part) { $ini .= mb_strtoupper(mb_substr($part, 0, 1)); }
      ?>
        <tr>
          <td class="when"><?php echo date('j M Y, g:ia', strtotime($l['created_at'])); ?></td>
          <td><div class="audit-actor"><span class="avatar<?php echo $l['actor'] ? '' : ' sys'; ?>"><?php echo e($ini); ?></span><span class="row-name"><?php echo e($actor); ?></span></div></td>
          <td><span class="badge <?php echo e($actionColor[$l['action']] ?? ''); ?>"><?php echo e(ucfirst($l['action'])); ?></span></td>
          <td class="entity"><?php echo e(ucfirst($l['entity_type'])); ?> <span class="row-sub">#<?php echo (int) $l['entity_id']; ?></span></td>
          <td class="details"><?php echo e($l['details'] ?? '—'); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
