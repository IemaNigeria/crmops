<?php
/**
 * IEMA CRMOps — Commercial Reports (FR-6.4: export to Excel/PDF)
 */
require_once __DIR__ . '/config.php';
require_login();

// CSV export (a practical first step toward FR-6.4; PDF export needs a
// document library and is a reasonable Phase 2 addition).
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = $pdo->query("
        SELECT l.reference_number, l.company_name, l.stage, l.estimated_value_ngn, u.name AS officer, l.created_at
        FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
        ORDER BY l.created_at DESC
    ");
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="crmops-pipeline-report-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Reference', 'Company', 'Stage', 'Value (NGN)', 'BD Officer', 'Created']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['reference_number'], $r['company_name'], $r['stage'], $r['estimated_value_ngn'], $r['officer'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

$stmt = $pdo->query("
    SELECT l.company_name, l.stage, l.estimated_value_ngn, u.name AS owner, l.created_at
    FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
    ORDER BY l.estimated_value_ngn DESC LIMIT 20
");
$topLeads = $stmt->fetchAll();

$monthStmt = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n, COALESCE(SUM(estimated_value_ngn),0) AS v
    FROM leads GROUP BY ym ORDER BY ym DESC LIMIT 6
");
$monthly = array_reverse($monthStmt->fetchAll());

$stageBadgeClass = [
    'New' => 'stage-lead', 'Contacted' => 'stage-lead', 'Needs Assessment' => 'stage-lead',
    'Proposal Sent' => 'stage-proposal', 'Negotiation' => 'stage-negotiation',
    'Won' => 'stage-client', 'Lost' => 'stage-lost',
];

// Presentation-only summary figures, derived from the arrays loaded above.
$rpLeads6m   = array_sum(array_map(fn($m) => (int) $m['n'], $monthly));
$rpValue6m   = array_sum(array_map(fn($m) => (float) $m['v'], $monthly));
$rpTopValue  = array_sum(array_map(fn($l) => (float) ($l['estimated_value_ngn'] ?? 0), $topLeads));
$rpTopWon    = count(array_filter($topLeads, fn($l) => $l['stage'] === 'Won'));
$rpTopOpen   = count(array_filter($topLeads, fn($l) => !in_array($l['stage'], ['Won', 'Lost'], true)));
$rpAvgMonth  = count($monthly) ? round($rpLeads6m / count($monthly), 1) : 0;
$rpMaxV      = max(1, max(array_map(fn($m) => (float) $m['v'], $monthly) ?: [1]));
$rpFmtM      = fn($v) => '₦' . number_format($v / 1000000, 1) . 'M';

require_once __DIR__ . '/includes/header.php';
?>
<style>
  .rp-month{ display:grid; grid-template-columns:64px minmax(0,1fr) auto; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--line-soft); font-size:12.5px; }
  .rp-month:last-child{ border-bottom:none; }
  .rp-month .m{ font-weight:600; color:var(--ink-700); }
  .rp-month .v{ font-weight:700; color:var(--ink-900); font-variant-numeric:tabular-nums; }
  .rp-month .meter > span{ background:var(--navy-600); }
  .rp-hero-foot{ display:flex; gap:18px; flex-wrap:wrap; margin-top:18px; padding-top:14px; border-top:1px solid rgba(255,255,255,0.1); font-size:12px; color:rgba(255,255,255,0.7); }
  .rp-hero-foot b{ color:#fff; font-size:15px; font-family:'Libre Franklin',sans-serif; margin-right:4px; }
  .rp-num{ font-variant-numeric:tabular-nums; font-weight:600; color:var(--ink-900); white-space:nowrap; }
  .rp-rank{ display:inline-flex; width:22px; height:22px; border-radius:7px; align-items:center; justify-content:center; background:var(--neutral-100); color:var(--ink-500); font-size:11px; font-weight:700; }
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Management review</div>
    <h1>Commercial Reports</h1>
    <p>Pipeline and lead reports for management review (FR-6.4).</p>
  </div>
  <div class="page-actions">
    <a class="btn-primary" href="reports.php?export=csv">
      <?php echo icon('download'); ?> Export CSV
    </a>
  </div>
</div>

<div class="bento">
  <section class="tile tile--navy span-4 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Top 20 opportunities</span>
      <div class="tile-icon"><?php echo icon('trend'); ?></div>
    </div>
    <div class="tile-value" style="font-size:36px;"><?php echo $rpFmtM($rpTopValue); ?></div>
    <div class="tile-sub">Combined estimated value of the largest opportunities</div>
    <div style="flex:1;"></div>
    <div class="rp-hero-foot">
      <span><b><?php echo (int) $rpTopOpen; ?></b>still open</span>
      <span><b><?php echo (int) $rpTopWon; ?></b>won</span>
    </div>
  </section>

  <section class="tile span-4 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Leads created</span>
      <div class="tile-icon navy"><?php echo icon('target'); ?></div>
    </div>
    <div class="tile-value"><?php echo (int) $rpLeads6m; ?></div>
    <div class="tile-sub">Across the last <?php echo count($monthly); ?> month<?php echo count($monthly) === 1 ? '' : 's'; ?> on record · avg <?php echo e((string) $rpAvgMonth); ?>/month</div>
  </section>

  <section class="tile span-4 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Value created</span>
      <div class="tile-icon green"><?php echo icon('chart'); ?></div>
    </div>
    <div class="tile-value"><?php echo $rpFmtM($rpValue6m); ?></div>
    <div class="tile-sub">Estimated value of leads created in the same period</div>
  </section>

  <section class="tile span-8">
    <div class="tile-head">
      <h2 class="tile-title"><?php echo icon('chart'); ?> Leads Created — Last 6 Months</h2>
      <span class="badge navy"><?php echo (int) $rpLeads6m; ?> leads</span>
    </div>
    <div class="funnel">
      <?php $maxN = max(1, max(array_column($monthly, 'n') ?: [1])); ?>
      <?php foreach ($monthly as $m): $h = max(14, round(($m['n'] / $maxN) * 130)); ?>
        <div class="funnel-bar">
          <span class="bar-value"><?php echo (int) $m['n']; ?></span>
          <div class="bar" style="height:<?php echo (int) $h; ?>px;"></div>
          <span class="bar-label"><?php echo date('M Y', strtotime($m['ym'] . '-01')); ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="tile span-4 flex-col">
    <div class="tile-head">
      <h2 class="tile-title">Value by Month</h2>
    </div>
    <?php if (empty($monthly)): ?>
      <div class="empty-state"><p>No leads recorded yet.</p></div>
    <?php else: ?>
      <?php foreach (array_reverse($monthly) as $m): ?>
        <div class="rp-month">
          <span class="m"><?php echo date('M Y', strtotime($m['ym'] . '-01')); ?></span>
          <div class="meter"><span style="width:<?php echo (int) round(((float) $m['v'] / $rpMaxV) * 100); ?>%;"></span></div>
          <span class="v"><?php echo $rpFmtM((float) $m['v']); ?></span>
        </div>
      <?php endforeach; ?>
      <div class="tile-foot" style="justify-content:space-between; border-top:1px solid var(--line); margin-top:14px;">
        <span class="tile-label">Total</span>
        <span class="rp-num"><?php echo $rpFmtM($rpValue6m); ?></span>
      </div>
    <?php endif; ?>
  </section>

  <section class="tile span-12">
    <div class="tile-head">
      <h2 class="tile-title">Top 20 Opportunities by Value</h2>
      <a class="link" href="reports.php?export=csv">Download full pipeline (CSV)</a>
    </div>
    <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>Company</th><th>Stage</th><th>Value</th><th>Owner</th><th>Created</th></tr></thead>
      <tbody>
      <?php foreach ($topLeads as $i => $l): ?>
        <tr>
          <td style="width:44px;"><span class="rp-rank"><?php echo (int) $i + 1; ?></span></td>
          <td class="row-name"><?php echo e($l['company_name']); ?></td>
          <td><span class="badge <?php echo e($stageBadgeClass[$l['stage']] ?? ''); ?>"><?php echo e($l['stage']); ?></span></td>
          <td class="rp-num">₦<?php echo number_format((float) ($l['estimated_value_ngn'] ?? 0)); ?></td>
          <td><?php echo e($l['owner'] ?? '—'); ?></td>
          <td><?php echo date('j M Y', strtotime($l['created_at'])); ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($topLeads)): ?>
        <tr><td colspan="6"><div class="empty-state"><p>No leads recorded yet.</p></div></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
