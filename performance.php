<?php
/**
 * IEMA CRMOps — Performance (FR-6.3: view performance by individual BD Officer)
 */
require_once __DIR__ . '/config.php';
require_login();

$stmt = $pdo->query("
    SELECT u.id, u.name,
           COUNT(DISTINCT l.id) AS total_leads,
           SUM(CASE WHEN l.stage = 'Won' THEN 1 ELSE 0 END) AS won,
           SUM(CASE WHEN l.stage = 'Lost' THEN 1 ELSE 0 END) AS lost,
           SUM(CASE WHEN l.stage NOT IN ('Won','Lost') THEN l.estimated_value_ngn ELSE 0 END) AS open_value,
           SUM(CASE WHEN l.stage = 'Won' THEN l.estimated_value_ngn ELSE 0 END) AS won_value,
           COUNT(DISTINCT i.id) AS interactions_logged
    FROM users u
    LEFT JOIN leads l ON l.assigned_to = u.id
    LEFT JOIN interactions i ON i.logged_by = u.id
    WHERE u.role = 'bd_officer'
    GROUP BY u.id, u.name
    ORDER BY won_value DESC
");
$rows = $stmt->fetchAll();
$maxWonValue = $rows ? max(1, max(array_column($rows, 'won_value'))) : 1;

require_once __DIR__ . '/includes/header.php';
?>

<?php
// Summary numbers for the bento tiles — derived only from $rows (already loaded above).
$perfWonValue = array_sum(array_map(fn($r) => (float) $r['won_value'], $rows));
$perfOpenValue = array_sum(array_map(fn($r) => (float) $r['open_value'], $rows));
$perfWon = array_sum(array_map(fn($r) => (int) $r['won'], $rows));
$perfLost = array_sum(array_map(fn($r) => (int) $r['lost'], $rows));
$perfInteractions = array_sum(array_map(fn($r) => (int) $r['interactions_logged'], $rows));
$perfWinRate = ($perfWon + $perfLost) > 0 ? (int) round($perfWon / ($perfWon + $perfLost) * 100) : 0;
$topPerformer = $rows[0] ?? null; // query is ordered by won_value DESC
?>
<style>
/* performance.php — page-scoped bento helpers */
.perf-chart .funnel{ height:210px; margin-top:18px; }
.perf-chart .funnel-bar .bar,
.perf-chart .funnel-bar:last-child .bar{ background:linear-gradient(180deg, var(--navy-600), var(--navy-900)); }
.perf-chart .funnel-bar .bar-label{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
.leader-row{ display:flex; align-items:center; gap:12px; padding:11px 0; border-bottom:1px solid var(--line-soft); }
.leader-row:last-child{ border-bottom:none; padding-bottom:0; }
.leader-rank{ width:26px; height:26px; border-radius:8px; background:var(--neutral-100); color:var(--ink-700); font:800 12px/26px 'Libre Franklin',sans-serif; text-align:center; flex-shrink:0; }
.leader-row:first-child .leader-rank{ background:var(--red-50); color:var(--red-700); }
.leader-row .nm{ font-weight:600; color:var(--ink-900); font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.leader-row .sub{ font-size:11.5px; color:var(--ink-500); }
.leader-row .val{ margin-left:auto; font-weight:800; font-family:'Libre Franklin',sans-serif; font-size:13px; color:var(--ink-900); white-space:nowrap; }
.perf-table td{ white-space:nowrap; }
.perf-table .meter{ width:70px; height:6px; display:inline-block; vertical-align:middle; margin-left:8px; }
.perf-table .meter > span{ background:var(--green); }
@media (max-width:760px){ .perf-chart .funnel-bar .bar-label{ font-size:9.5px; } .bento > .tile.m-half{ grid-column:span 6 !important; } .bento > .tile.m-half .tile-icon{ display:none; } }
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Business Development</div>
    <h1>Performance</h1>
    <p>BD Officer performance breakdown — closed value, win rate, and activity (FR-6.3).</p>
  </div>
</div>

<div class="bento">
  <section class="tile tile--navy span-4 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Won value · all officers</span>
      <span class="tile-icon"><?php echo icon('trend'); ?></span>
    </div>
    <div class="tile-value">₦<?php echo number_format($perfWonValue / 1000000, 1); ?>M</div>
    <div class="tile-sub"><?php echo $topPerformer ? 'Top closer: ' . e($topPerformer['name']) : 'No officers yet'; ?></div>
  </section>
  <section class="tile span-3 flex-col m-half">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Win rate</span>
      <span class="tile-icon green"><?php echo icon('check'); ?></span>
    </div>
    <div class="tile-value"><?php echo $perfWinRate; ?>%</div>
    <div class="tile-sub"><?php echo $perfWon; ?> won · <?php echo $perfLost; ?> lost</div>
    <div class="meter" style="margin-top:12px;"><span style="width:<?php echo $perfWinRate; ?>%; background:var(--green);"></span></div>
  </section>
  <section class="tile span-3 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Open pipeline</span>
      <span class="tile-icon navy"><?php echo icon('target'); ?></span>
    </div>
    <div class="tile-value">₦<?php echo number_format($perfOpenValue / 1000000, 1); ?>M</div>
    <div class="tile-sub">Still to close</div>
  </section>
  <section class="tile span-2 flex-col m-half">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Activity</span>
    </div>
    <div class="tile-value"><?php echo $perfInteractions; ?></div>
    <div class="tile-sub">Interactions logged</div>
  </section>

  <section class="tile span-8 perf-chart">
    <div class="tile-head"><h2 class="tile-title">Won Value by Officer</h2><span class="tile-sub" style="margin:0;">₦ millions</span></div>
    <div class="funnel">
      <?php foreach ($rows as $r): $h = max(14, round(($r['won_value'] / $maxWonValue) * 170)); ?>
        <div class="funnel-bar">
          <span class="bar-value">₦<?php echo number_format($r['won_value'] / 1000000, 1); ?>M</span>
          <div class="bar" style="height:<?php echo (int) $h; ?>px;"></div>
          <span class="bar-label" title="<?php echo e($r['name']); ?>"><?php echo e($r['name']); ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="tile span-4">
    <div class="tile-head"><h2 class="tile-title">Leaderboard</h2></div>
    <?php foreach ($rows as $idx => $r): $closed = $r['won'] + $r['lost']; $wr = $closed > 0 ? round(($r['won'] / $closed) * 100) : 0; ?>
      <div class="leader-row">
        <span class="leader-rank"><?php echo $idx + 1; ?></span>
        <div style="min-width:0;">
          <div class="nm"><?php echo e($r['name']); ?></div>
          <div class="sub"><?php echo (int) $wr; ?>% win rate · <?php echo (int) $r['total_leads']; ?> leads</div>
        </div>
        <span class="val">₦<?php echo number_format($r['won_value'] / 1000000, 1); ?>M</span>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="tile span-12">
    <div class="tile-head"><h2 class="tile-title">Detailed Breakdown</h2></div>
    <div class="table-wrap">
    <table class="perf-table">
      <thead><tr><th>Officer</th><th>Total Leads</th><th>Won</th><th>Lost</th><th>Win Rate</th><th>Open Pipeline Value</th><th>Interactions Logged</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $closed = $r['won'] + $r['lost']; $wr = $closed > 0 ? round(($r['won'] / $closed) * 100) : 0; ?>
        <tr>
          <td class="row-name"><?php echo e($r['name']); ?></td>
          <td><?php echo (int) $r['total_leads']; ?></td>
          <td><?php echo (int) $r['won']; ?></td>
          <td><?php echo (int) $r['lost']; ?></td>
          <td><?php echo $wr; ?>%<span class="meter"><span style="width:<?php echo (int) $wr; ?>%;"></span></span></td>
          <td>₦<?php echo number_format((float) $r['open_value']); ?></td>
          <td><?php echo (int) $r['interactions_logged']; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
