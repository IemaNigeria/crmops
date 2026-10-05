<?php
/**
 * IEMA CRMOps — BD Team (Section 5: BD Manager visibility across team pipeline)
 */
require_once __DIR__ . '/config.php';
require_login();

$stmt = $pdo->query("
    SELECT u.id, u.name, u.email, u.status, u.last_login_at,
           COUNT(l.id) AS total_leads,
           SUM(CASE WHEN l.stage NOT IN ('Won','Lost') THEN 1 ELSE 0 END) AS open_leads,
           SUM(CASE WHEN l.stage = 'Won' THEN 1 ELSE 0 END) AS won,
           SUM(CASE WHEN l.stage = 'Lost' THEN 1 ELSE 0 END) AS lost
    FROM users u
    LEFT JOIN leads l ON l.assigned_to = u.id
    WHERE u.role = 'bd_officer'
    GROUP BY u.id, u.name, u.email, u.status, u.last_login_at
    ORDER BY u.name
");
$officers = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<?php
// Summary numbers for the bento tiles — derived only from $officers (already loaded above).
$teamOpen = $teamWon = $teamLost = $teamActive = 0;
foreach ($officers as $o) {
    $teamOpen += (int) $o['open_leads']; $teamWon += (int) $o['won']; $teamLost += (int) $o['lost'];
    if ($o['status'] === 'active') $teamActive++;
}
$teamWinRate = ($teamWon + $teamLost) > 0 ? (int) round($teamWon / ($teamWon + $teamLost) * 100) : 0;
$initials = function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) { $out .= mb_strtoupper(mb_substr($p, 0, 1)); }
    return $out ?: '?';
};
?>
<style>
/* team.php — page-scoped bento helpers */
.officer-tile .who{ display:flex; align-items:center; gap:12px; min-width:0; }
.officer-tile .who .avatar{ flex-shrink:0; }
.officer-tile .who .nm{ font-family:'Libre Franklin',sans-serif; font-weight:700; font-size:14.5px; color:var(--ink-900); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.officer-tile .who .em{ font-size:11.5px; color:var(--ink-500); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.officer-tile .stat-row{ margin-top:14px; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; }
.officer-tile .stat{ padding:9px 10px; }
.officer-tile .stat .v{ font-size:17px; }
.officer-tile .meter{ margin-top:12px; height:6px; }
.officer-tile .meter > span{ background:var(--green); }
.officer-tile .wr{ display:flex; justify-content:space-between; font-size:11.5px; color:var(--ink-500); margin-top:12px; font-weight:600; }
.badge.status-active{ background:var(--green-50); color:var(--green-700); }
.badge.status-off{ background:var(--neutral-100); color:var(--ink-500); }
.team-hero .stat-row{ grid-template-columns:repeat(3,minmax(0,1fr)); margin-top:auto; padding-top:18px; }
.team-table td{ white-space:nowrap; }
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Business Development</div>
    <h1>BD Team</h1>
    <p>Roster and pipeline snapshot for every BD Officer.</p>
  </div>
</div>

<div class="bento">
  <section class="tile tile--navy span-4 flex-col team-hero">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Team open leads</span>
      <span class="tile-icon"><?php echo icon('users'); ?></span>
    </div>
    <div class="tile-value"><?php echo $teamOpen; ?></div>
    <div class="tile-sub">Across <?php echo count($officers); ?> BD Officer<?php echo count($officers) === 1 ? '' : 's'; ?> · <?php echo $teamActive; ?> active</div>
    <div class="stat-row">
      <div class="stat"><div class="v"><?php echo $teamWon; ?></div><div class="l">Won</div></div>
      <div class="stat"><div class="v"><?php echo $teamLost; ?></div><div class="l">Lost</div></div>
      <div class="stat"><div class="v"><?php echo $teamWinRate; ?>%</div><div class="l">Win rate</div></div>
    </div>
  </section>

  <?php if (empty($officers)): ?>
    <section class="tile tile--dashed span-8">
      <div class="empty-state"><div class="icon-button"><?php echo icon('users'); ?></div><p>No BD Officers yet.</p></div>
    </section>
  <?php endif; ?>

  <?php foreach ($officers as $o):
    $closed = $o['won'] + $o['lost'];
    $wr = $closed > 0 ? round(($o['won'] / $closed) * 100) : 0;
  ?>
    <section class="tile span-4 officer-tile">
      <div class="tile-head" style="margin-bottom:0; flex-wrap:nowrap;">
        <div class="who">
          <span class="avatar"><?php echo e($initials((string) $o['name'])); ?></span>
          <div style="min-width:0;">
            <div class="nm"><?php echo e($o['name']); ?></div>
            <div class="em"><?php echo e($o['email']); ?></div>
          </div>
        </div>
        <span class="badge <?php echo $o['status'] === 'active' ? 'status-active' : 'status-off'; ?>"><?php echo e(ucfirst($o['status'])); ?></span>
      </div>
      <div class="stat-row">
        <div class="stat"><div class="v"><?php echo (int) $o['open_leads']; ?></div><div class="l">Open</div></div>
        <div class="stat"><div class="v"><?php echo (int) $o['won']; ?></div><div class="l">Won</div></div>
        <div class="stat"><div class="v"><?php echo (int) $o['lost']; ?></div><div class="l">Lost</div></div>
      </div>
      <div class="wr"><span>Win rate</span><span style="color:var(--ink-900);"><?php echo (int) $wr; ?>%</span></div>
      <div class="meter"><span style="width:<?php echo (int) $wr; ?>%;"></span></div>
    </section>
  <?php endforeach; ?>

  <?php
    // Fill the rest of the last bento row with a share-of-workload tile so the grid never has a hole.
    $usedCols = 4 + 4 * max(1, count($officers));
    $shareSpan = (12 - $usedCols % 12) % 12 ?: 12;
  ?>
  <?php if ($officers): ?>
  <section class="tile tile--soft span-<?php echo (int) $shareSpan; ?> flex-col">
    <div class="tile-head"><h2 class="tile-title">Share of open leads</h2></div>
    <div style="display:flex; flex-direction:column; gap:12px;">
      <?php foreach ($officers as $o): $share = $teamOpen > 0 ? (int) round($o['open_leads'] / $teamOpen * 100) : 0; ?>
        <div>
          <div style="display:flex; justify-content:space-between; gap:10px; font-size:12.5px;">
            <span style="font-weight:600; color:var(--ink-900);"><?php echo e($o['name']); ?></span>
            <span style="color:var(--ink-500); font-weight:600;"><?php echo $share; ?>%</span>
          </div>
          <div class="meter" style="height:6px; margin-top:6px; background:var(--neutral-200);"><span style="width:<?php echo $share; ?>%; background:var(--navy-600);"></span></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="tile span-12">
    <div class="tile-head"><h2 class="tile-title">Full Roster <span class="badge navy"><?php echo count($officers); ?></span></h2></div>
    <div class="table-wrap">
    <table class="team-table">
      <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Open Leads</th><th>Won</th><th>Lost</th><th>Last Login</th></tr></thead>
      <tbody>
      <?php foreach ($officers as $o): ?>
        <tr>
          <td class="row-name"><?php echo e($o['name']); ?></td>
          <td><?php echo e($o['email']); ?></td>
          <td><span class="badge <?php echo $o['status'] === 'active' ? 'status-active' : 'status-off'; ?>"><?php echo e(ucfirst($o['status'])); ?></span></td>
          <td><?php echo (int) $o['open_leads']; ?></td>
          <td><?php echo (int) $o['won']; ?></td>
          <td><?php echo (int) $o['lost']; ?></td>
          <td><?php echo $o['last_login_at'] ? date('j M Y, g:ia', strtotime($o['last_login_at'])) : 'Never'; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
