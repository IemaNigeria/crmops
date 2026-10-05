<?php
/**
 * IEMA CRMOps — Pipeline Intelligence (FR-6.1, FR-6.2: reporting by scheme)
 */
require_once __DIR__ . '/config.php';
require_login();

const STAGE_ORDER_INT = ['New', 'Contacted', 'Needs Assessment', 'Proposal Sent', 'Negotiation', 'Won', 'Lost'];

$stmt = $pdo->query('SELECT stage, COUNT(*) AS n, COALESCE(SUM(estimated_value_ngn),0) AS v FROM leads GROUP BY stage');
$raw = $stmt->fetchAll();
$counts = array_fill_keys(STAGE_ORDER_INT, ['n' => 0, 'v' => 0]);
foreach ($raw as $r) { $counts[$r['stage']] = ['n' => (int) $r['n'], 'v' => (float) $r['v']]; }

$schemeStmt = $pdo->query("
    SELECT s.name, COUNT(ls.lead_id) AS lead_count, COALESCE(SUM(l.estimated_value_ngn),0) AS pipeline_value
    FROM schemes s
    LEFT JOIN lead_schemes ls ON ls.scheme_id = s.id
    LEFT JOIN leads l ON l.id = ls.lead_id AND l.stage NOT IN ('Lost')
    GROUP BY s.id, s.name
    HAVING lead_count > 0
    ORDER BY pipeline_value DESC
");
$schemes = $schemeStmt->fetchAll();
$maxSchemeValue = max(1, max(array_column($schemes, 'pipeline_value') ?: [1]));

$totalOpen = array_sum(array_map(fn($s) => $counts[$s]['n'], ['New','Contacted','Needs Assessment','Proposal Sent','Negotiation']));
$totalOpenValue = array_sum(array_map(fn($s) => $counts[$s]['v'], ['New','Contacted','Needs Assessment','Proposal Sent','Negotiation']));
$totalWon = $counts['Won']['n']; $totalLost = $counts['Lost']['n'];
$overallWinRate = ($totalWon + $totalLost) > 0 ? round($totalWon / ($totalWon + $totalLost) * 100) : 0;

// Presentation-only figures derived from the arrays above.
$piOpenStages = ['New','Contacted','Needs Assessment','Proposal Sent','Negotiation'];
$piMaxStageV  = max(1, max(array_map(fn($s) => $counts[$s]['v'], STAGE_ORDER_INT)));
$piFmtM       = fn($v) => '₦' . number_format($v / 1000000, 1) . 'M';

require_once __DIR__ . '/includes/header.php';
?>
<style>
  .pi-stage{ display:grid; grid-template-columns:minmax(0,130px) minmax(0,1fr) auto; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--line-soft); font-size:12.5px; }
  .pi-stage:last-child{ border-bottom:none; }
  .pi-stage .n{ font-weight:600; color:var(--ink-700); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .pi-stage .n small{ color:var(--ink-400); font-weight:600; margin-left:4px; }
  .pi-stage .v{ font-weight:700; color:var(--ink-900); font-variant-numeric:tabular-nums; white-space:nowrap; }
  .pi-stage .meter > span{ background:var(--navy-600); }
  .pi-stage.won .meter > span{ background:var(--green); }
  .pi-stage.lost .meter > span{ background:var(--ink-400); }
  .pi-num{ font-variant-numeric:tabular-nums; font-weight:600; color:var(--ink-900); white-space:nowrap; }
  .pi-scheme-meter{ width:220px; }
  .pi-scheme-meter .meter > span{ background:var(--navy-600); }
  .pi-hero-stats{ grid-template-columns:repeat(3,minmax(0,1fr)); }
  .pi-funnel{ overflow-x:auto; }
  @media (max-width:760px){ .pi-scheme-meter{ width:120px; } .pi-funnel .funnel{ min-width:560px; } }
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Analytics</div>
    <h1>Pipeline Intelligence</h1>
    <p>Pipeline value, lead volume, and scheme-level breakdown (FR-6.1, FR-6.2).</p>
  </div>
</div>

<div class="bento">
  <section class="tile tile--navy span-6 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Total open pipeline value</span>
      <div class="tile-icon"><?php echo icon('trend'); ?></div>
    </div>
    <div class="tile-value" style="font-size:38px;">₦<?php echo number_format($totalOpenValue / 1000000, 1); ?>M</div>
    <div class="tile-sub">Estimated value of leads not yet won or lost</div>
    <div style="flex:1; min-height:16px;"></div>
    <div class="stat-row pi-hero-stats">
      <div class="stat"><div class="v"><?php echo (int) $totalOpen; ?></div><div class="l">Open leads</div></div>
      <div class="stat"><div class="v"><?php echo (int) $totalWon; ?></div><div class="l">Won</div></div>
      <div class="stat"><div class="v"><?php echo (int) $totalLost; ?></div><div class="l">Lost</div></div>
    </div>
  </section>

  <section class="tile span-3 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Overall win rate</span>
      <div class="tile-icon green"><?php echo icon('chart'); ?></div>
    </div>
    <div class="tile-value"><?php echo (int) $overallWinRate; ?>%</div>
    <div class="tile-sub"><?php echo (int) $totalWon; ?> won of <?php echo (int) ($totalWon + $totalLost); ?> closed</div>
    <div style="flex:1; min-height:14px;"></div>
    <div class="meter"><span style="width:<?php echo (int) $overallWinRate; ?>%; background:var(--green);"></span></div>
  </section>

  <section class="tile span-3 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Active schemes</span>
      <div class="tile-icon navy"><?php echo icon('briefcase'); ?></div>
    </div>
    <div class="tile-value"><?php echo count($schemes); ?></div>
    <div class="tile-sub">Certification schemes with leads in the pipeline</div>
  </section>

  <section class="tile span-7">
    <div class="tile-head">
      <h2 class="tile-title"><?php echo icon('chart'); ?> Pipeline by Stage</h2>
      <span class="badge navy"><?php echo (int) array_sum(array_map(fn($s) => $counts[$s]['n'], STAGE_ORDER_INT)); ?> leads</span>
    </div>
    <div class="pi-funnel"><div class="funnel">
      <?php $funnelMax = max(1, max(array_map(fn($s) => $counts[$s]['n'], STAGE_ORDER_INT))); ?>
      <?php foreach (STAGE_ORDER_INT as $s): $h = max(14, round(($counts[$s]['n'] / $funnelMax) * 130)); ?>
        <div class="funnel-bar">
          <span class="bar-value"><?php echo $counts[$s]['n']; ?></span>
          <div class="bar" style="height:<?php echo (int) $h; ?>px;"></div>
          <span class="bar-label"><?php echo e($s); ?></span>
        </div>
      <?php endforeach; ?>
    </div></div>
  </section>

  <section class="tile span-5">
    <div class="tile-head">
      <h2 class="tile-title">Value by Stage</h2>
    </div>
    <?php foreach (STAGE_ORDER_INT as $s): ?>
      <div class="pi-stage <?php echo $s === 'Won' ? 'won' : ($s === 'Lost' ? 'lost' : ''); ?>">
        <span class="n"><?php echo e($s); ?><small><?php echo (int) $counts[$s]['n']; ?></small></span>
        <div class="meter"><span style="width:<?php echo (int) round(($counts[$s]['v'] / $piMaxStageV) * 100); ?>%;"></span></div>
        <span class="v"><?php echo $piFmtM($counts[$s]['v']); ?></span>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="tile span-12">
    <div class="tile-head">
      <h2 class="tile-title">Pipeline by Certification Scheme</h2>
      <?php if (!empty($schemes)): ?><span class="badge navy"><?php echo count($schemes); ?> schemes</span><?php endif; ?>
    </div>
    <?php if (empty($schemes)): ?>
      <div class="empty-state"><p>No scheme data tagged on leads yet.</p></div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead><tr><th>Scheme</th><th>Open Leads</th><th>Pipeline Value</th><th>Share of largest</th></tr></thead>
      <tbody>
      <?php foreach ($schemes as $s): $pct = round(($s['pipeline_value'] / $maxSchemeValue) * 100); ?>
        <tr>
          <td class="row-name"><?php echo e($s['name']); ?></td>
          <td><?php echo (int) $s['lead_count']; ?></td>
          <td class="pi-num">₦<?php echo number_format($s['pipeline_value']); ?></td>
          <td class="pi-scheme-meter">
            <div class="meter"><span style="width:<?php echo (int) $pct; ?>%;"></span></div>
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
