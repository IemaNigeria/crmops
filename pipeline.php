<?php
/**
 * IEMA CRMOps — Team Pipeline (FR-1.7: kanban-style pipeline view)
 */
require_once __DIR__ . '/config.php';
require_login();

const KANBAN_STAGES = ['New', 'Contacted', 'Needs Assessment', 'Proposal Sent', 'Negotiation', 'Won', 'Lost'];

$stmt = $pdo->query("
    SELECT l.id, l.reference_number, l.company_name, l.stage, l.estimated_value_ngn, u.name AS officer_name
    FROM leads l LEFT JOIN users u ON u.id = l.assigned_to
    ORDER BY l.updated_at DESC
");
$allLeads = $stmt->fetchAll();

$byStage = array_fill_keys(KANBAN_STAGES, []);
foreach ($allLeads as $l) {
    $byStage[$l['stage']][] = $l;
}

require_once __DIR__ . '/includes/header.php';
?>

<?php
// Summary numbers for the bento tiles — derived only from $allLeads / $byStage (already loaded above).
$stageValue = [];
foreach (KANBAN_STAGES as $s) {
    $stageValue[$s] = array_sum(array_map(fn($l) => (float) ($l['estimated_value_ngn'] ?? 0), $byStage[$s]));
}
$openStages = array_diff(KANBAN_STAGES, ['Won', 'Lost']);
$openValue = 0.0; $openCount = 0;
foreach ($openStages as $s) { $openValue += $stageValue[$s]; $openCount += count($byStage[$s]); }
$wonCount = count($byStage['Won']); $lostCount = count($byStage['Lost']);
$pipeWinRate = ($wonCount + $lostCount) > 0 ? (int) round($wonCount / ($wonCount + $lostCount) * 100) : 0;
$unassignedCount = count(array_filter($allLeads, fn($l) => empty($l['officer_name'])));
$lateStage = count($byStage['Proposal Sent']) + count($byStage['Negotiation']);
$stageAccent = [
    'New' => '#4338CA', 'Contacted' => '#4338CA', 'Needs Assessment' => '#4338CA',
    'Proposal Sent' => '#B45309', 'Negotiation' => '#BE185D', 'Won' => 'var(--green)', 'Lost' => 'var(--ink-400)',
];
?>
<style>
/* pipeline.php — page-scoped bento helpers */
.kanban-board{ grid-auto-columns:minmax(236px,1fr); align-items:start; }
.kanban-col{ background:var(--neutral-50); }
.kanban-col-head{ margin-bottom:4px; }
.kanban-col-head .stage-name{ display:flex; align-items:center; gap:8px; font-size:12.5px; font-weight:800; }
.kanban-col-head .stage-name::before{ content:''; width:8px; height:8px; border-radius:50%; background:var(--col-accent, var(--ink-400)); }
.kanban-col-total{ font-size:11.5px; color:var(--ink-500); margin-bottom:12px; font-weight:600; }
.kanban-card .kc-top{ display:flex; align-items:flex-start; justify-content:space-between; gap:6px; }
.kanban-card .kc-top .btn-ghost{ padding:3px 5px; flex-shrink:0; margin:-3px -5px 0 0; }
.kanban-card .kc-top .btn-ghost .icon{ width:15px; height:15px; }
.kanban-empty{ font-size:11.5px; color:var(--ink-500); padding:14px 10px; text-align:center; border:1px dashed var(--neutral-300); border-radius:12px; }
.stage-strip{ display:flex; height:10px; border-radius:999px; overflow:hidden; background:var(--neutral-100); margin-top:16px; gap:2px; }
.stage-strip span{ display:block; height:100%; }
.stage-legend{ display:flex; flex-wrap:wrap; gap:6px 14px; margin-top:12px; font-size:11.5px; color:var(--ink-500); }
.stage-legend i{ display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:5px; vertical-align:middle; }
.stage-legend b{ color:var(--ink-900); margin-left:3px; }
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Business Development</div>
    <h1>Team Pipeline</h1>
    <p>Kanban view of every lead across the BD team, grouped by stage (FR-1.7).</p>
  </div>
</div>

<div class="bento">
  <section class="tile tile--navy span-4 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Open pipeline value</span>
      <span class="tile-icon"><?php echo icon('trend'); ?></span>
    </div>
    <div class="tile-value">₦<?php echo number_format($openValue); ?></div>
    <div class="tile-sub"><?php echo $openCount; ?> open leads · <?php echo $lateStage; ?> at proposal or negotiation</div>
  </section>

  <section class="tile span-8">
    <div class="tile-head" style="margin-bottom:0;">
      <h2 class="tile-title">Stage mix</h2>
      <span class="tile-sub" style="margin:0;"><?php echo count($allLeads); ?> leads · <?php echo $pipeWinRate; ?>% win rate · <?php echo $unassignedCount; ?> unassigned</span>
    </div>
    <div class="stage-strip">
      <?php foreach (KANBAN_STAGES as $s): if (!$byStage[$s]) continue; ?>
        <span style="flex:<?php echo count($byStage[$s]); ?>; background:<?php echo $stageAccent[$s]; ?>;<?php echo in_array($s, ['Contacted', 'Needs Assessment'], true) ? ' opacity:' . ($s === 'Contacted' ? '.7' : '.45') . ';' : ''; ?>" title="<?php echo e($s); ?>: <?php echo count($byStage[$s]); ?>"></span>
      <?php endforeach; ?>
    </div>
    <div class="stage-legend">
      <?php foreach (KANBAN_STAGES as $s): ?>
        <span><i style="background:<?php echo $stageAccent[$s]; ?>;<?php echo in_array($s, ['Contacted', 'Needs Assessment'], true) ? ' opacity:' . ($s === 'Contacted' ? '.7' : '.45') . ';' : ''; ?>"></i><?php echo e($s); ?><b><?php echo count($byStage[$s]); ?></b></span>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<div class="kanban-board">
  <?php foreach (KANBAN_STAGES as $stage): ?>
    <div class="kanban-col" style="--col-accent:<?php echo $stageAccent[$stage]; ?>;">
      <div class="kanban-col-head">
        <span class="stage-name"><?php echo e($stage); ?></span>
        <span class="count"><?php echo count($byStage[$stage]); ?></span>
      </div>
      <div class="kanban-col-total">₦<?php echo number_format($stageValue[$stage]); ?></div>
      <?php if (empty($byStage[$stage])): ?>
        <p class="kanban-empty">No leads.</p>
      <?php else: foreach ($byStage[$stage] as $l): ?>
        <div class="kanban-card">
          <div class="kc-top">
            <div class="kc-name"><?php echo e($l['company_name']); ?></div>
            <button type="button" class="btn-ghost" title="View this lead's full trail"
              onclick="viewLeadHistory(<?php echo (int) $l['id']; ?>, '<?php echo e(addslashes($l['company_name'])); ?>')">
              <?php echo icon('clock'); ?>
            </button>
          </div>
          <div class="kc-meta"><?php echo e($l['reference_number']); ?> · <?php echo e($l['officer_name'] ?? 'Unassigned'); ?></div>
          <div class="kc-value">₦<?php echo number_format((float) ($l['estimated_value_ngn'] ?? 0)); ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>