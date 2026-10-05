<?php
/**
 * IEMA CRMOps — Mail view (rendered by mail.php only).
 * Bento layout: summary tiles · folders · message list · reading pane,
 * plus the floating composer.
 */
if (!defined('CRMOPS_MAIL_VIEW')) { http_response_code(404); exit; }

$listUids = array_column($messages, 'uid');
$pos      = $openMessage ? array_search($openMessage['uid'], $listUids, true) : false;
$prevUid  = ($pos !== false && $pos > 0) ? $listUids[$pos - 1] : 0;
$nextUid  = ($pos !== false && $pos < count($listUids) - 1) ? $listUids[$pos + 1] : 0;
$rangeFrom = $totalFiltered ? ($mailPage - 1) * $perPage + 1 : 0;
$rangeTo   = min($totalFiltered, $mailPage * $perPage);
$moveTargets = array_values(array_filter($folders, fn($f) => $f['name'] !== $currentFolder && !in_array($f['role'], ['drafts'], true)));

$pageState = [
    'csrf'         => $csrfToken,
    'folder'       => $currentFolder,
    'folderRole'   => $folderRole,
    'draftsFolder' => $draftsFolderName,
    'hasArchive'   => $hasArchive,
    'viewUid'      => $openMessage['uid'] ?? 0,
    'prevUid'      => $prevUid,
    'nextUid'      => $nextUid,
    'flagged'      => $openMessage['flagged'] ?? false,
    'open'         => $openMessage['compose'] ?? null,
    'listUrl'      => mail_url(['uid' => null]),
    'baseUrl'      => mail_url(),
    'me'           => $user['mail_email'],
];
?>
<style>
  /* ── Mail page (scoped) ────────────────────────────────────────────── */
  .mx-stats .tile{ padding:14px 16px; display:flex; align-items:center; gap:12px; }
  .mx-stats .tile-value{ font-size:22px; margin-top:0; }
  .mx-stats .tile-sub{ margin-top:0; font-size:12px; }
  .mx-stats a.tile.on{ border-color:var(--navy-700); box-shadow:0 0 0 3px rgba(31,51,80,0.12); }
  .mx-grid{ display:grid; grid-template-columns:220px minmax(320px, 400px) minmax(0,1fr); gap:var(--gap); margin-top:var(--gap);
    height:calc(100vh - 250px); min-height:560px; }
  .mx-grid > .tile{ min-height:0; display:flex; flex-direction:column; }

  /* Folders */
  .mx-folders{ padding:14px 10px; overflow:auto; }
  .mx-compose-btn{ width:100%; justify-content:center; padding:12px 16px; margin-bottom:12px; }
  .mx-folder{ display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:10px; font-size:13.5px; font-weight:500; color:var(--ink-700); }
  .mx-folder:hover{ background:var(--neutral-100); color:var(--ink-900); }
  .mx-folder.active{ background:var(--navy-800); color:#fff; font-weight:600; }
  .mx-folder .icon{ width:17px; height:17px; }
  .mx-folder .n{ margin-left:auto; font-size:11px; font-weight:700; color:var(--ink-500); }
  .mx-folder .n.unread{ background:var(--red-600); color:#fff; padding:1px 7px; border-radius:999px; }
  .mx-folder.active .n{ color:rgba(255,255,255,0.8); }
  .mx-folder-sep{ font-size:10.5px; font-weight:700; letter-spacing:.8px; text-transform:uppercase; color:var(--ink-400); margin:14px 10px 6px; }
  .mx-folders-foot{ margin-top:auto; padding:12px 10px 2px; border-top:1px solid var(--line-soft); font-size:11.5px; color:var(--ink-500); line-height:1.5; word-break:break-all; }

  /* List */
  .mx-list{ padding:0; overflow:hidden; }
  .mx-list-head{ padding:14px 14px 10px; border-bottom:1px solid var(--line-soft); }
  .mx-list-title{ display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px; }
  .mx-list-title h2{ font-size:15px; font-weight:700; display:flex; align-items:center; gap:8px; }
  .mx-search{ display:flex; gap:6px; }
  .mx-search .search-box{ flex:1; width:auto; max-width:none; padding:7px 10px; }
  .mx-search select{ border:1px solid var(--line); border-radius:10px; background:#fff; font:inherit; font-size:12.5px; padding:0 8px; color:var(--ink-700); max-width:118px; }
  .mx-chips{ display:flex; gap:6px; margin-top:10px; align-items:center; flex-wrap:wrap; }
  .mx-chips .filter-chip{ padding:5px 11px; font-size:12px; }
  .mx-bulk{ display:flex; align-items:center; gap:4px; padding:6px 10px; border-bottom:1px solid var(--line-soft); background:var(--neutral-50); min-height:42px; }
  .mx-bulk .sel-count{ font-size:12px; color:var(--ink-500); margin:0 6px; }
  .mx-bulk .mx-act{ opacity:.45; pointer-events:none; }
  .mx-bulk.has-sel .mx-act{ opacity:1; pointer-events:auto; }
  .mx-iconbtn{ width:32px; height:32px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; border:1px solid transparent; background:transparent; color:var(--ink-700); cursor:pointer; position:relative; }
  .mx-iconbtn:hover{ background:#fff; border-color:var(--line); color:var(--ink-900); }
  .mx-iconbtn .icon{ width:16px; height:16px; }
  .mx-iconbtn.on .icon{ color:var(--amber); fill:var(--amber); }
  .mx-rows{ overflow:auto; flex:1; }
  .mx-row{ display:flex; gap:10px; padding:11px 12px 11px 16px; border-bottom:1px solid var(--line-soft); cursor:pointer; position:relative; align-items:flex-start; }
  .mx-row:hover{ background:var(--neutral-50); }
  .mx-row.active{ background:#EEF2F8; }
  .mx-row.active::before{ content:''; position:absolute; left:0; top:0; bottom:0; width:3px; background:var(--navy-700); }
  .mx-row.unread .mx-from, .mx-row.unread .mx-subj{ font-weight:700; color:var(--ink-900); }
  .mx-row.unread::after{ content:''; position:absolute; left:5px; top:17px; width:6px; height:6px; border-radius:50%; background:var(--red-600); }
  .mx-row input[type=checkbox]{ margin-top:3px; width:15px; height:15px; flex-shrink:0; }
  .mx-av{ width:34px; height:34px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; flex-shrink:0; font-family:'Libre Franklin',sans-serif; }
  .mx-av.h0{ background:#EEF2F8; color:#1F3350; } .mx-av.h1{ background:#FDF1F3; color:#A30F26; } .mx-av.h2{ background:#ECFDF3; color:#15803D; }
  .mx-av.h3{ background:#FFF7E6; color:#B45309; } .mx-av.h4{ background:#F3EEFF; color:#6D28D9; } .mx-av.h5{ background:#E8F7FA; color:#0E7490; }
  .mx-row-main{ flex:1; min-width:0; }
  .mx-row-top{ display:flex; align-items:baseline; gap:8px; }
  .mx-from{ font-size:13px; color:var(--ink-800); font-weight:500; flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .mx-date{ font-size:11.5px; color:var(--ink-500); white-space:nowrap; }
  .mx-subj{ font-size:13px; color:var(--ink-700); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:2px; }
  .mx-tags{ display:flex; gap:6px; margin-top:4px; align-items:center; }
  .mx-tag{ font-size:10.5px; font-weight:700; padding:1px 7px; border-radius:999px; background:var(--neutral-100); color:var(--ink-500); }
  .mx-tag.draft{ background:var(--red-50); color:var(--red-700); }
  .mx-star{ border:none; background:none; padding:2px; cursor:pointer; color:var(--ink-400); flex-shrink:0; }
  .mx-star .icon{ width:16px; height:16px; }
  .mx-star.on .icon{ color:var(--amber); fill:var(--amber); }
  .mx-star:hover{ color:var(--amber); }
  .mx-pager{ display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 12px; border-top:1px solid var(--line-soft); font-size:12px; color:var(--ink-500); }
  .mx-pager .btns{ display:flex; gap:4px; }
  .mx-pager a, .mx-pager span.dis{ display:inline-flex; width:30px; height:30px; align-items:center; justify-content:center; border-radius:9px; border:1px solid var(--line); background:#fff; color:var(--ink-700); }
  .mx-pager span.dis{ opacity:.4; }
  .mx-pager .icon{ width:15px; height:15px; }

  /* Reader */
  .mx-reader{ padding:0; overflow:hidden; }
  .mx-reader-bar{ display:flex; align-items:center; gap:4px; padding:10px 14px; border-bottom:1px solid var(--line-soft); flex-wrap:wrap; }
  .mx-reader-bar .spacer{ flex:1; }
  .mx-reader-bar .sep{ width:1px; height:20px; background:var(--line); margin:0 4px; }
  .mx-reader-body{ overflow:auto; flex:1; padding:22px 26px 26px; }
  .mx-subject{ font-size:20px; font-weight:800; letter-spacing:-0.3px; line-height:1.3; overflow-wrap:anywhere; display:flex; gap:10px; align-items:flex-start; }
  .mx-meta{ display:flex; gap:12px; margin-top:16px; align-items:flex-start; }
  .mx-meta .who{ flex:1; min-width:0; }
  .mx-meta .name{ font-weight:700; font-size:14px; color:var(--ink-900); }
  .mx-meta .addr{ font-size:12.5px; color:var(--ink-500); }
  .mx-meta .rcpt{ font-size:12px; color:var(--ink-500); margin-top:3px; overflow-wrap:anywhere; }
  .mx-meta .when{ font-size:12px; color:var(--ink-500); white-space:nowrap; }
  .mx-body{ margin-top:20px; border-top:1px solid var(--line-soft); padding-top:18px; }
  .mail-iframe{ width:100%; border:none; display:block; min-height:160px; background:#fff; }
  .mx-plain{ white-space:pre-wrap; font-family:inherit; font-size:14px; color:var(--ink-800); line-height:1.7; overflow-wrap:anywhere; }
  .mx-atts{ margin-top:20px; padding-top:16px; border-top:1px solid var(--line-soft); }
  .mx-att-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:8px; margin-top:8px; }
  .mx-att{ display:flex; align-items:center; gap:10px; padding:10px 12px; border:1px solid var(--line); border-radius:12px; background:var(--neutral-50); min-width:0; }
  .mx-att:hover{ border-color:var(--neutral-300); background:#fff; }
  .mx-att .ext{ width:34px; height:34px; border-radius:9px; background:var(--navy-800); color:#fff; font-size:10px; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; text-transform:uppercase; }
  .mx-att .fn{ font-size:12.5px; font-weight:600; color:var(--ink-900); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .mx-att .sz{ font-size:11px; color:var(--ink-500); }
  .mx-reply-cta{ margin-top:22px; display:flex; gap:8px; flex-wrap:wrap; }
  .mx-empty{ margin:auto; text-align:center; color:var(--ink-500); padding:30px; max-width:340px; }
  .mx-empty .big{ width:64px; height:64px; border-radius:20px; background:var(--neutral-100); display:flex; align-items:center; justify-content:center; margin:0 auto 14px; color:var(--ink-400); }
  .mx-empty .big .icon{ width:28px; height:28px; }
  .mx-empty h3{ font-size:15px; color:var(--ink-900); margin-bottom:6px; }
  .mx-kbd{ display:grid; grid-template-columns:auto 1fr; gap:6px 12px; font-size:12px; text-align:left; margin-top:16px; }
  .mx-kbd kbd{ font:700 11px/1 'Inter',sans-serif; border:1px solid var(--line); background:#fff; border-radius:6px; padding:3px 6px; color:var(--ink-700); min-width:22px; text-align:center; }

  /* Menus */
  .mx-menu-wrap{ position:relative; }
  .mx-menu{ position:absolute; top:36px; left:0; min-width:200px; background:#fff; border:1px solid var(--line); border-radius:12px; box-shadow:var(--shadow-pop); padding:5px; z-index:50; max-height:320px; overflow:auto; }
  .mx-menu.right{ left:auto; right:0; }
  .mx-menu button{ display:flex; width:100%; align-items:center; gap:9px; padding:8px 10px; border:none; background:none; border-radius:8px; font:inherit; font-size:13px; color:var(--ink-700); cursor:pointer; text-align:left; }
  .mx-menu button:hover{ background:var(--neutral-100); color:var(--ink-900); }
  .mx-menu .icon{ width:15px; height:15px; }

  /* Composer */
  .mx-composer{ position:fixed; right:24px; bottom:0; width:620px; max-width:calc(100vw - 24px); height:min(640px, calc(100vh - 90px)); background:#fff;
    border:1px solid var(--line); border-radius:18px 18px 0 0; box-shadow:0 -10px 60px -12px rgba(15,27,45,0.35); z-index:120; display:none; flex-direction:column; }
  .mx-composer.open{ display:flex; }
  .mx-composer.min{ height:auto; }
  .mx-composer.min .mx-c-body, .mx-composer.min .mx-c-foot{ display:none; }
  .mx-composer.max{ right:50%; transform:translateX(50%); bottom:4vh; width:min(1100px, 94vw); height:92vh; border-radius:18px; }
  .mx-c-head{ display:flex; align-items:center; gap:8px; padding:11px 12px 11px 18px; background:var(--navy-900); color:#fff; border-radius:17px 17px 0 0; cursor:pointer; }
  .mx-composer.max .mx-c-head{ border-radius:17px 17px 0 0; }
  .mx-c-head h3{ font-size:14px; font-weight:700; flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .mx-c-head .status{ font-size:11.5px; color:rgba(255,255,255,0.6); white-space:nowrap; }
  .mx-c-head button{ width:30px; height:30px; border-radius:8px; border:none; background:transparent; color:rgba(255,255,255,0.75); cursor:pointer; display:flex; align-items:center; justify-content:center; }
  .mx-c-head button:hover{ background:rgba(255,255,255,0.1); color:#fff; }
  .mx-c-head .icon{ width:16px; height:16px; }
  .mx-c-body{ flex:1; display:flex; flex-direction:column; min-height:0; overflow:auto; }
  .mx-field{ display:flex; align-items:center; gap:8px; padding:0 18px; border-bottom:1px solid var(--line-soft); min-height:42px; }
  .mx-field label{ font-size:12.5px; color:var(--ink-500); width:52px; flex-shrink:0; }
  .mx-field input{ flex:1; border:none; outline:none; font:inherit; font-size:13.5px; padding:10px 0; color:var(--ink-900); background:transparent; min-width:0; }
  .mx-field input.bad{ color:var(--red-700); }
  .mx-field .toggles{ display:flex; gap:4px; }
  .mx-field .toggles button{ border:none; background:none; font:inherit; font-size:12.5px; color:var(--ink-500); cursor:pointer; padding:4px 6px; border-radius:6px; }
  .mx-field .toggles button:hover{ background:var(--neutral-100); color:var(--ink-900); }
  .mx-c-body .rte-toolbar{ display:flex; gap:2px; padding:6px 12px; border-bottom:1px solid var(--line-soft); flex-wrap:wrap; }
  .mx-c-body .rte-toolbar button{ border:none; background:transparent; border-radius:7px; padding:6px 9px; font-size:13px; color:var(--ink-700); cursor:pointer; line-height:1; display:inline-flex; align-items:center; }
  .mx-c-body .rte-toolbar button:hover{ background:var(--neutral-100); }
  .mx-c-body .rte-toolbar .icon{ width:15px; height:15px; }
  .mx-c-body .rte-toolbar .rte-sep{ width:1px; height:18px; background:var(--line); margin:4px 4px; }
  .rte-editor{ flex:1; min-height:200px; padding:16px 18px; font-size:14px; line-height:1.65; color:var(--ink-900); outline:none; overflow:auto; }
  .rte-editor p{ margin:0 0 10px; }
  .rte-editor ul, .rte-editor ol{ margin:0 0 10px; padding-left:22px; }
  .rte-editor blockquote{ margin:0 0 10px; padding:4px 0 4px 14px; border-left:3px solid var(--neutral-300); color:var(--ink-500); }
  .rte-editor:empty:before{ content:attr(data-placeholder); color:var(--ink-400); }
  .mx-c-atts{ display:flex; flex-wrap:wrap; gap:6px; padding:0 18px 10px; }
  .mx-chip{ display:inline-flex; align-items:center; gap:6px; padding:5px 6px 5px 10px; border-radius:999px; background:var(--neutral-100); border:1px solid var(--line); font-size:12px; color:var(--ink-700); max-width:260px; }
  .mx-chip span.nm{ overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .mx-chip small{ color:var(--ink-400); }
  .mx-chip button{ border:none; background:none; cursor:pointer; color:var(--ink-400); font-size:15px; line-height:1; padding:0 3px; }
  .mx-chip button:hover{ color:var(--red-600); }
  .mx-c-foot{ display:flex; align-items:center; gap:8px; padding:12px 14px; border-top:1px solid var(--line-soft); flex-wrap:wrap; }
  .mx-c-foot .spacer{ flex:1; }
  .mx-c-foot .checkbox-row{ font-size:12.5px; color:var(--ink-700); }
  .mx-c-msg{ font-size:12.5px; padding:8px 18px; display:none; }
  .mx-c-msg.error{ display:block; background:var(--red-50); color:#B42318; }
  .mx-c-msg.success{ display:block; background:var(--green-50); color:var(--green-700); }
  .mx-drop{ position:absolute; inset:48px 0 0 0; background:rgba(238,242,248,0.92); border:2px dashed var(--navy-600); border-radius:0 0 0 0; display:none; align-items:center; justify-content:center; font-weight:700; color:var(--navy-700); z-index:5; }
  .mx-composer.dragging .mx-drop{ display:flex; }

  /* Toast */
  .mx-toast{ position:fixed; left:50%; bottom:24px; transform:translateX(-50%); background:var(--navy-900); color:#fff; padding:11px 16px; border-radius:12px; font-size:13px; box-shadow:var(--shadow-pop); z-index:300; display:none; align-items:center; gap:12px; max-width:calc(100vw - 32px); }
  .mx-toast.show{ display:flex; }
  .mx-toast.err{ background:#7A1020; }

  .mx-back{ display:none; }
  .icon.flip{ transform:rotate(180deg); }

  @media (max-width:1280px){
    .mx-grid{ grid-template-columns:190px minmax(280px, 340px) minmax(0,1fr); }
  }
  @media (max-width:1100px){
    .mx-grid{ grid-template-columns:minmax(280px, 360px) minmax(0,1fr); grid-template-rows:auto minmax(0,1fr); height:auto; }
    .mx-grid > .mx-folders{ grid-column:1 / -1; flex-direction:row; align-items:center; gap:6px; overflow-x:auto; padding:10px; }
    .mx-folders .mx-compose-btn{ width:auto; margin:0 6px 0 0; padding:9px 14px; }
    .mx-folder{ white-space:nowrap; }
    .mx-folder-sep, .mx-folders-foot{ display:none; }
    .mx-list, .mx-reader{ height:calc(100vh - 330px); min-height:520px; }
  }
  @media (max-width:860px){
    .mx-stats{ display:none !important; }
    .mx-grid{ grid-template-columns:1fr; }
    .mx-list, .mx-reader{ height:auto; min-height:0; }
    .mx-rows, .mx-reader-body{ overflow:visible; }
    .mx-grid.reading .mx-list{ display:none; }
    .mx-grid:not(.reading) .mx-reader{ display:none; }
    .mx-back{ display:inline-flex; }
    .mx-reader-body{ padding:18px 16px; }
    .mx-composer, .mx-composer.max{ right:0; left:0; bottom:0; top:0; width:100%; max-width:none; height:auto; transform:none; border-radius:0; }
    .mx-c-head{ border-radius:0 !important; }
    .page-head .mx-hide-sm{ display:none; }
    .mx-folders .mx-compose-btn{ display:none; }
  }
  @media print{
    .mx-stats, .mx-folders, .mx-list, .mx-reader-bar, .mx-reply-cta, .page-head, .mx-composer, .mx-toast{ display:none !important; }
    .mx-grid{ display:block; height:auto; }
    .mx-reader, .mx-reader-body{ overflow:visible; border:none; box-shadow:none; }
  }
</style>

<div class="page-head">
  <div>
    <h1>Mail</h1>
    <p><?php echo e($user['mail_email']); ?> · your own mailbox — nothing here syncs to CRM records.</p>
  </div>
  <div class="page-actions">
    <a class="btn-secondary mx-hide-sm" href="<?php echo e(mail_url()); ?>" title="Check for new mail"><?php echo icon('refresh'); ?> Refresh</a>
    <a class="btn-secondary mx-hide-sm" href="mail-settings.php"><?php echo icon('settings'); ?> Settings</a>
    <button type="button" class="btn-primary" onclick="MX.compose()"><?php echo icon('plus'); ?> Compose</button>
  </div>
</div>

<?php if ($connectionError): ?>
  <div class="alert error"><?php echo icon('alert'); ?><span><?php echo e($connectionError); ?> — <a href="mail-settings.php" style="text-decoration:underline;">check Mail Settings</a>.</span></div>
<?php endif; ?>

<!-- ── Summary tiles ───────────────────────────────────────────────────── -->
<div class="bento mx-stats">
  <a class="tile tile--navy span-3 m-half<?php echo ($filter === 'unread' && $folderRole === 'inbox') ? ' on' : ''; ?>" href="mail.php?filter=unread">
    <div class="tile-icon"><?php echo mx_icon('inbox'); ?></div>
    <div><div class="tile-value"><?php echo number_format($stats['unread']); ?></div><div class="tile-sub">Unread in Inbox</div></div>
  </a>
  <a class="tile span-3 m-half<?php echo ($filter === 'flagged' && $folderRole === 'inbox') ? ' on' : ''; ?>" href="mail.php?filter=flagged">
    <div class="tile-icon amber"><?php echo mx_icon('star'); ?></div>
    <div><div class="tile-value"><?php echo number_format($stats['flagged']); ?></div><div class="tile-sub">Flagged</div></div>
  </a>
  <a class="tile span-3 m-half<?php echo $isDraftsFolder ? ' on' : ''; ?>" href="<?php echo $draftsFolderName ? 'mail.php?folder=' . urlencode($draftsFolderName) : 'mail.php'; ?>">
    <div class="tile-icon navy"><?php echo icon('edit'); ?></div>
    <div><div class="tile-value"><?php echo number_format($stats['drafts']); ?></div><div class="tile-sub">Drafts</div></div>
  </a>
  <a class="tile span-3 m-half<?php echo $sinceParam === date('Y-m-d') ? ' on' : ''; ?>" href="mail.php?since=<?php echo date('Y-m-d'); ?>">
    <div class="tile-icon green"><?php echo icon('calendar'); ?></div>
    <div><div class="tile-value"><?php echo number_format($stats['today']); ?></div><div class="tile-sub">Received today</div></div>
  </a>
</div>

<div class="mx-grid<?php echo $openMessage ? ' reading' : ''; ?>" id="mxGrid">

  <!-- ── Folders ─────────────────────────────────────────────────────── -->
  <nav class="tile mx-folders" aria-label="Mail folders">
    <button type="button" class="btn-primary mx-compose-btn" onclick="MX.compose()"><?php echo icon('edit'); ?> Compose</button>
    <?php $printedSep = false; foreach ($folders as $f):
      if ($f['role'] === 'custom' && !$printedSep): $printedSep = true; ?>
        <div class="mx-folder-sep">Folders</div>
      <?php endif;
      $fs = $folderStats[$f['name']] ?? ['messages' => 0, 'unseen' => 0];
      $count = in_array($f['role'], ['drafts'], true) ? $fs['messages'] : $fs['unseen'];
      $isUnreadBadge = !in_array($f['role'], ['drafts', 'sent', 'trash', 'junk'], true) && $fs['unseen'] > 0; ?>
      <a class="mx-folder<?php echo $currentFolder === $f['name'] ? ' active' : ''; ?>" href="<?php echo e('mail.php' . ($f['name'] === 'INBOX' ? '' : '?folder=' . urlencode($f['name']))); ?>">
        <?php echo mail_folder_icon($f['role']); ?>
        <span><?php echo e($f['label']); ?></span>
        <?php if ($count > 0): ?><span class="n<?php echo $isUnreadBadge ? ' unread' : ''; ?>"><?php echo number_format($count); ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <div class="mx-folders-foot">
      Signed in as<br><b style="color:var(--ink-700);"><?php echo e($user['mail_email']); ?></b>
    </div>
  </nav>

  <!-- ── Message list ────────────────────────────────────────────────── -->
  <section class="tile mx-list" aria-label="Messages">
    <div class="mx-list-head">
      <div class="mx-list-title">
        <h2><?php echo e($folderLabel); ?> <span class="badge navy"><?php echo number_format($totalFiltered); ?></span></h2>
        <?php if ($searchQuery !== '' || $filter !== 'all' || $sinceParam !== 'all'): ?>
          <a class="link" style="font-size:12px;font-weight:600;color:var(--red-600);" href="<?php echo e(mail_url(['search' => '', 'filter' => 'all', 'since' => 'all', 'p' => 1])); ?>">Clear filters</a>
        <?php endif; ?>
      </div>
      <form method="GET" action="mail.php" class="mx-search" role="search">
        <?php if ($currentFolder !== 'INBOX'): ?><input type="hidden" name="folder" value="<?php echo e($currentFolder); ?>"><?php endif; ?>
        <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?php echo e($filter); ?>"><?php endif; ?>
        <label class="search-box">
          <?php echo icon('search'); ?>
          <input type="search" name="search" id="mxSearch" placeholder="Search sender, subject, text…" value="<?php echo e($searchQuery); ?>" autocomplete="off">
        </label>
        <select name="since" onchange="this.form.submit()" aria-label="Date range">
          <?php foreach ($sinceOptions as $k => $lbl): ?>
            <option value="<?php echo e($k); ?>"<?php echo $sinceParam === $k ? ' selected' : ''; ?>><?php echo e($lbl); ?></option>
          <?php endforeach; ?>
          <?php if (!isset($sinceOptions[$sinceParam])): ?>
            <option value="<?php echo e($sinceParam); ?>" selected>Since <?php echo e(date('j M Y', strtotime($sinceParam))); ?></option>
          <?php endif; ?>
        </select>
      </form>
      <div class="mx-chips">
        <?php foreach (['all' => 'All', 'unread' => 'Unread', 'flagged' => 'Flagged'] as $k => $lbl): ?>
          <a class="filter-chip<?php echo $filter === $k ? ' active' : ''; ?>" href="<?php echo e(mail_url(['filter' => $k, 'p' => 1, 'uid' => null])); ?>"><?php echo e($lbl); ?></a>
        <?php endforeach; ?>
        <?php if ($searchQuery !== ''): ?>
          <span class="row-sub" style="margin-left:4px;">for “<?php echo e($searchQuery); ?>”</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="mx-bulk" id="mxBulk">
      <input type="checkbox" id="mxSelectAll" aria-label="Select all on this page" title="Select all">
      <span class="sel-count" id="mxSelCount"></span>
      <button type="button" class="mx-iconbtn mx-act" title="Mark as read" onclick="MX.bulk('mark_read')"><?php echo mx_icon('mail-open'); ?></button>
      <button type="button" class="mx-iconbtn mx-act" title="Mark as unread" onclick="MX.bulk('mark_unread')"><?php echo icon('mail'); ?></button>
      <button type="button" class="mx-iconbtn mx-act" title="Flag" onclick="MX.bulk('flag')"><?php echo mx_icon('star'); ?></button>
      <?php if ($hasArchive && $folderRole !== 'archive'): ?>
        <button type="button" class="mx-iconbtn mx-act" title="Archive (e)" onclick="MX.bulk('archive')"><?php echo mx_icon('archive'); ?></button>
      <?php endif; ?>
      <div class="mx-menu-wrap">
        <button type="button" class="mx-iconbtn mx-act" title="Move to…" onclick="MX.toggleMenu('mxBulkMove', event)"><?php echo mx_icon('folder'); ?></button>
        <div class="mx-menu" id="mxBulkMove" style="display:none;">
          <?php foreach ($moveTargets as $t): ?>
            <button type="button" onclick="MX.bulk('move', <?php echo e(json_encode($t['name'])); ?>)"><?php echo mail_folder_icon($t['role']); ?> <?php echo e($t['label']); ?></button>
          <?php endforeach; ?>
        </div>
      </div>
      <button type="button" class="mx-iconbtn mx-act" title="<?php echo $isTrashFolder ? 'Delete forever' : 'Delete'; ?> (#)" onclick="MX.bulk('delete')"><?php echo icon('trash'); ?></button>
      <span style="flex:1;"></span>
      <a class="mx-iconbtn" href="<?php echo e(mail_url()); ?>" title="Refresh"><?php echo icon('refresh'); ?></a>
    </div>

    <div class="mx-rows" id="mxRows">
      <?php if (empty($messages)): ?>
        <div class="mx-empty" style="margin-top:40px;">
          <div class="big"><?php echo $searchQuery !== '' ? icon('search') : mx_icon('inbox'); ?></div>
          <h3><?php echo $connectionError ? 'Couldn’t load messages' : ($searchQuery !== '' ? 'No matches' : 'Nothing here'); ?></h3>
          <p style="font-size:13px;">
            <?php if ($searchQuery !== '' && $sinceParam !== 'all'): ?>
              Nothing matched in this date range. <a href="<?php echo e(mail_url(['since' => 'all', 'p' => 1])); ?>" style="color:var(--red-600);font-weight:600;">Search any time</a>.
            <?php elseif ($searchQuery !== ''): ?>
              Try a shorter word, a sender’s name or an email address.
            <?php elseif ($filter !== 'all'): ?>
              No <?php echo e($filter); ?> messages in <?php echo e($folderLabel); ?>.
            <?php else: ?>
              <?php echo e($folderLabel); ?> is empty.
            <?php endif; ?>
          </p>
        </div>
      <?php else: foreach ($messages as $m):
        $who = $isSentFolder || $isDraftsFolder ? ($m['to'] !== '' ? 'To: ' . mail_sender_name($m['to']) : '(no recipient)') : mail_sender_name($m['from']);
        $avatarName = $isSentFolder || $isDraftsFolder ? mail_sender_name($m['to']) : mail_sender_name($m['from']);
        $href = mail_url(['uid' => $m['uid']]);
        $active = $openMessage && $openMessage['uid'] === $m['uid']; ?>
        <div class="mx-row<?php echo $m['seen'] ? '' : ' unread'; ?><?php echo $active ? ' active' : ''; ?>" data-uid="<?php echo (int) $m['uid']; ?>" data-href="<?php echo e($href); ?>">
          <input type="checkbox" class="mx-sel" value="<?php echo (int) $m['uid']; ?>" aria-label="Select message">
          <div class="mx-av h<?php echo mail_avatar_hue($avatarName); ?>"><?php echo e(mail_initials($avatarName)); ?></div>
          <a class="mx-row-main" href="<?php echo e($href); ?>" data-open>
            <div class="mx-row-top">
              <span class="mx-from"><?php echo e($who); ?></span>
              <span class="mx-date" title="<?php echo e($m['date']); ?>"><?php echo e(mail_short_date($m['ts'])); ?></span>
            </div>
            <div class="mx-subj"><?php echo e($m['subject']); ?></div>
            <?php if ($m['answered'] || $m['draft'] || $isDraftsFolder): ?>
              <div class="mx-tags">
                <?php if ($isDraftsFolder || $m['draft']): ?><span class="mx-tag draft">Draft</span><?php endif; ?>
                <?php if ($m['answered']): ?><span class="mx-tag">Replied</span><?php endif; ?>
              </div>
            <?php endif; ?>
          </a>
          <button type="button" class="mx-star<?php echo $m['flagged'] ? ' on' : ''; ?>" title="<?php echo $m['flagged'] ? 'Remove flag' : 'Flag'; ?>" data-star="<?php echo (int) $m['uid']; ?>"><?php echo mx_icon('star'); ?></button>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="mx-pager">
      <span><?php echo $totalFiltered ? number_format($rangeFrom) . '–' . number_format($rangeTo) . ' of ' . number_format($totalFiltered) : '0 messages'; ?></span>
      <div class="btns">
        <?php if ($mailPage > 1): ?><a href="<?php echo e(mail_url(['p' => $mailPage - 1, 'uid' => null])); ?>" title="Newer"><?php echo icon('chevron-right', 'flip'); ?></a><?php else: ?><span class="dis"><?php echo icon('chevron-right', 'flip'); ?></span><?php endif; ?>
        <?php if ($mailPage < $totalPages): ?><a href="<?php echo e(mail_url(['p' => $mailPage + 1, 'uid' => null])); ?>" title="Older"><?php echo icon('chevron-right'); ?></a><?php else: ?><span class="dis"><?php echo icon('chevron-right'); ?></span><?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ── Reading pane ─────────────────────────────────────────────────── -->
  <section class="tile mx-reader" aria-label="Message">
    <?php if (!$openMessage): ?>
      <div class="mx-empty">
        <div class="big"><?php echo icon('mail'); ?></div>
        <h3>No message selected</h3>
        <p style="font-size:13px;">Pick a message from the list, or start a new one.</p>
        <button type="button" class="btn-secondary" style="margin-top:14px;" onclick="MX.compose()"><?php echo icon('edit'); ?> Compose</button>
        <div class="mx-kbd">
          <kbd>c</kbd><span>Compose</span>
          <kbd>/</kbd><span>Search mail</span>
          <kbd>j</kbd><span>Next / <kbd style="margin-left:2px;">k</kbd> previous</span>
          <kbd>r</kbd><span>Reply · <kbd>a</kbd> reply all · <kbd>f</kbd> forward</span>
          <kbd>s</kbd><span>Flag · <kbd>u</kbd> unread · <kbd>e</kbd> archive · <kbd>#</kbd> delete</span>
        </div>
      </div>
    <?php else: $om = $openMessage; ?>
      <div class="mx-reader-bar">
        <a class="mx-iconbtn mx-back" href="<?php echo e(mail_url(['uid' => null])); ?>" title="Back to list"><?php echo mx_icon('arrow-left'); ?></a>
        <?php if ($isDraftsFolder): ?>
          <button type="button" class="btn-primary btn-sm" onclick="MX.openDraft(<?php echo (int) $om['uid']; ?>)"><?php echo icon('edit'); ?> Edit draft</button>
        <?php else: ?>
          <button type="button" class="mx-iconbtn" title="Reply (r)" onclick="MX.reply('reply')"><?php echo mx_icon('reply'); ?></button>
          <button type="button" class="mx-iconbtn" title="Reply all (a)" onclick="MX.reply('reply_all')"><?php echo mx_icon('reply-all'); ?></button>
          <button type="button" class="mx-iconbtn" title="Forward (f)" onclick="MX.reply('forward')"><?php echo mx_icon('forward'); ?></button>
        <?php endif; ?>
        <span class="sep"></span>
        <?php if ($hasArchive && $folderRole !== 'archive'): ?>
          <button type="button" class="mx-iconbtn" title="Archive (e)" onclick="MX.one('archive')"><?php echo mx_icon('archive'); ?></button>
        <?php endif; ?>
        <button type="button" class="mx-iconbtn" title="<?php echo $isTrashFolder ? 'Delete forever' : 'Delete'; ?> (#)" onclick="MX.one('delete')"><?php echo icon('trash'); ?></button>
        <button type="button" class="mx-iconbtn" title="Mark unread (u)" onclick="MX.one('mark_unread')"><?php echo icon('mail'); ?></button>
        <button type="button" class="mx-iconbtn<?php echo $om['flagged'] ? ' on' : ''; ?>" id="mxFlagBtn" title="Flag (s)" onclick="MX.toggleFlag()"><?php echo mx_icon('star'); ?></button>
        <div class="mx-menu-wrap">
          <button type="button" class="mx-iconbtn" title="Move to…" onclick="MX.toggleMenu('mxOneMove', event)"><?php echo mx_icon('folder'); ?></button>
          <div class="mx-menu" id="mxOneMove" style="display:none;">
            <?php foreach ($moveTargets as $t): ?>
              <button type="button" onclick="MX.one('move', <?php echo e(json_encode($t['name'])); ?>)"><?php echo mail_folder_icon($t['role']); ?> <?php echo e($t['label']); ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <button type="button" class="mx-iconbtn" title="Print" onclick="window.print()"><?php echo mx_icon('printer'); ?></button>
        <span class="spacer"></span>
        <span class="row-sub" style="margin-right:4px;"><?php echo $pos !== false ? ($rangeFrom + $pos) . ' of ' . number_format($totalFiltered) : ''; ?></span>
        <?php if ($prevUid): ?><a class="mx-iconbtn" href="<?php echo e(mail_url(['uid' => $prevUid])); ?>" title="Newer (k)"><?php echo mx_icon('up'); ?></a><?php endif; ?>
        <?php if ($nextUid): ?><a class="mx-iconbtn" href="<?php echo e(mail_url(['uid' => $nextUid])); ?>" title="Older (j)"><?php echo mx_icon('down'); ?></a><?php endif; ?>
      </div>

      <div class="mx-reader-body">
        <h2 class="mx-subject"><?php echo e($om['subject']); ?></h2>
        <div class="mx-meta">
          <div class="mx-av h<?php echo mail_avatar_hue($om['fromName']); ?>" style="width:42px;height:42px;border-radius:13px;font-size:14px;"><?php echo e(mail_initials($om['fromName'])); ?></div>
          <div class="who">
            <div class="name"><?php echo e($om['fromName']); ?> <span class="addr">&lt;<?php echo e($om['fromEmail']); ?>&gt;</span></div>
            <?php if ($om['toDisplay'] !== ''): ?><div class="rcpt">To: <?php echo e($om['toDisplay']); ?></div><?php endif; ?>
            <?php if ($om['ccDisplay'] !== ''): ?><div class="rcpt">Cc: <?php echo e($om['ccDisplay']); ?></div><?php endif; ?>
          </div>
          <div class="when" title="<?php echo e($om['date']); ?>"><?php echo $om['date'] ? e(date('j M Y, g:i a', strtotime($om['date']))) : ''; ?></div>
        </div>

        <div class="mx-body">
          <?php if ($om['html'] !== ''):
            // Sandboxed (no scripts, no forms); links open in a new tab.
            $iframeDoc = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
              . '<meta http-equiv="Content-Security-Policy" content="script-src \'none\'; object-src \'none\'; form-action \'none\'">'
              . '<base target="_blank">'
              . '<style>body{margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#1a1a1a;word-break:break-word;}img{max-width:100%;height:auto;}a{color:#1F3350;}table{max-width:100% !important;}</style>'
              . '</head><body>' . $om['html'] . '</body></html>'; ?>
            <iframe class="mail-iframe" id="mailBodyIframe"
              sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
              srcdoc="<?php echo htmlspecialchars($iframeDoc, ENT_QUOTES, 'UTF-8'); ?>"
              title="Email body" onload="MX.resizeFrame(this)"></iframe>
          <?php else: ?>
            <div class="mx-plain"><?php echo e(trim($om['plain'])); ?></div>
          <?php endif; ?>
        </div>

        <?php if (!empty($om['attachments'])): ?>
          <div class="mx-atts">
            <div class="tile-label"><?php echo count($om['attachments']); ?> attachment<?php echo count($om['attachments']) > 1 ? 's' : ''; ?></div>
            <div class="mx-att-grid">
              <?php foreach ($om['attachments'] as $att):
                $ext = strtolower(pathinfo($att['filename'], PATHINFO_EXTENSION)) ?: 'file'; ?>
                <a class="mx-att" href="mail-attachment.php?folder=<?php echo urlencode($currentFolder); ?>&amp;msgno=<?php echo (int) $om['msgno']; ?>&amp;part=<?php echo urlencode($att['part']); ?>&amp;filename=<?php echo urlencode($att['filename']); ?>">
                  <span class="ext"><?php echo e(substr($ext, 0, 4)); ?></span>
                  <span style="min-width:0;"><span class="fn" style="display:block;"><?php echo e($att['filename']); ?></span><span class="sz"><?php echo e(mail_bytes((int) $att['bytes']) ?: 'Download'); ?></span></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!$isDraftsFolder): ?>
          <div class="mx-reply-cta">
            <button type="button" class="btn-secondary" onclick="MX.reply('reply')"><?php echo mx_icon('reply'); ?> Reply</button>
            <button type="button" class="btn-secondary" onclick="MX.reply('reply_all')"><?php echo mx_icon('reply-all'); ?> Reply all</button>
            <button type="button" class="btn-secondary" onclick="MX.reply('forward')"><?php echo mx_icon('forward'); ?> Forward</button>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<!-- ── Composer ────────────────────────────────────────────────────────── -->
<div class="mx-composer" id="mxComposer" role="dialog" aria-label="Compose message">
  <div class="mx-c-head" onclick="MX.headClick(event)">
    <h3 id="mxCTitle">New message</h3>
    <span class="status" id="mxCStatus"></span>
    <button type="button" title="Minimise" data-act="min"><?php echo mx_icon('minimize'); ?></button>
    <button type="button" title="Full screen" data-act="max"><?php echo mx_icon('maximize'); ?></button>
    <button type="button" title="Save &amp; close (Esc)" data-act="close"><?php echo icon('x'); ?></button>
  </div>
  <div class="mx-c-body">
    <div class="mx-drop">Drop files to attach</div>
    <div class="mx-field">
      <label for="mxTo">To</label>
      <input type="text" id="mxTo" autocomplete="off" spellcheck="false" placeholder="name@company.com, another@company.com">
      <div class="toggles">
        <button type="button" id="mxShowCc" onclick="MX.showField('cc')">Cc</button>
        <button type="button" id="mxShowBcc" onclick="MX.showField('bcc')">Bcc</button>
      </div>
    </div>
    <div class="mx-field" id="mxCcRow" style="display:none;"><label for="mxCc">Cc</label><input type="text" id="mxCc" autocomplete="off" spellcheck="false"></div>
    <div class="mx-field" id="mxBccRow" style="display:none;"><label for="mxBcc">Bcc</label><input type="text" id="mxBcc" autocomplete="off" spellcheck="false"></div>
    <div class="mx-field"><label for="mxSubject">Subject</label><input type="text" id="mxSubject" autocomplete="off"></div>
    <div class="rte-toolbar" id="rteToolbar">
      <button type="button" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>
      <button type="button" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>
      <button type="button" data-cmd="underline" title="Underline (Ctrl+U)"><u>U</u></button>
      <span class="rte-sep"></span>
      <button type="button" data-cmd="insertUnorderedList" title="Bulleted list">• List</button>
      <button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
      <button type="button" data-cmd="formatBlock" data-val="blockquote" title="Quote"><?php echo mx_icon('quote'); ?></button>
      <span class="rte-sep"></span>
      <button type="button" data-cmd="createLink" title="Insert link"><?php echo mx_icon('link'); ?></button>
      <button type="button" data-cmd="removeFormat" title="Clear formatting">Tx</button>
    </div>
    <div id="mxBody" class="rte-editor" contenteditable="true" data-placeholder="Write your message…"></div>
    <div class="mx-c-atts" id="mxAtts"></div>
  </div>
  <div class="mx-c-msg" id="mxCMsg"></div>
  <div class="mx-c-foot">
    <button type="button" class="btn-primary" id="mxSend" onclick="MX.send()"><?php echo icon('send'); ?> Send</button>
    <button type="button" class="btn-secondary" id="mxSaveDraft" onclick="MX.saveDraft(true)"><?php echo mx_icon('save'); ?> Save draft</button>
    <input type="file" id="mxFile" multiple style="display:none;">
    <button type="button" class="mx-iconbtn" title="Attach files" onclick="document.getElementById('mxFile').click()"><?php echo mx_icon('clip'); ?></button>
    <label class="checkbox-row"><input type="checkbox" id="mxSig" checked> Signature</label>
    <span class="spacer"></span>
    <button type="button" class="mx-iconbtn" title="Discard" onclick="MX.discard()"><?php echo icon('trash'); ?></button>
  </div>
</div>

<div class="mx-toast" id="mxToast" role="status" aria-live="polite"><span id="mxToastText"></span></div>

<script type="application/json" id="mxState"><?php echo json_encode($pageState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?></script>
<script>
(function () {
  'use strict';
  const S = JSON.parse(document.getElementById('mxState').textContent);
  document.body.dataset.ownSlash = '1';   // '/' focuses mail search, not the global jump box
  const $ = (id) => document.getElementById(id);
  const esc = (t) => String(t ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  // ── Server calls ────────────────────────────────────────────────────
  async function post(action, data = {}, files = null) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(data)) {
      if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x));
      else if (v !== undefined && v !== null) fd.append(k, v);
    }
    if (files) for (const f of files) fd.append('attachments[]', f, f.name);
    const res = await fetch('mail.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': S.csrf, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    let json;
    try { json = await res.json(); } catch (e) { throw new Error('The server returned an unexpected response (' + res.status + ').'); }
    return json;
  }

  let toastTimer;
  function toast(msg, isErr) {
    const t = $('mxToast');
    $('mxToastText').textContent = msg;
    t.classList.toggle('err', !!isErr);
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), isErr ? 6000 : 3200);
  }

  // Remember a toast across the reload that follows an action.
  function reloadWith(msg, url) {
    try { sessionStorage.setItem('mxToast', msg); } catch (e) {}
    window.location.href = url || window.location.href;
  }
  try { const m = sessionStorage.getItem('mxToast'); if (m) { sessionStorage.removeItem('mxToast'); setTimeout(() => toast(m), 50); } } catch (e) {}

  // ── Selection + bulk actions ────────────────────────────────────────
  const rows = Array.from(document.querySelectorAll('.mx-row'));
  function selected() { return Array.from(document.querySelectorAll('.mx-sel:checked')).map(c => c.value); }
  function updateBulk() {
    const n = selected().length;
    $('mxBulk').classList.toggle('has-sel', n > 0);
    $('mxSelCount').textContent = n ? n + ' selected' : '';
    const all = $('mxSelectAll');
    all.checked = n > 0 && n === rows.length;
    all.indeterminate = n > 0 && n < rows.length;
  }
  $('mxSelectAll').addEventListener('change', (e) => {
    document.querySelectorAll('.mx-sel').forEach(c => c.checked = e.target.checked);
    updateBulk();
  });
  document.querySelectorAll('.mx-sel').forEach(c => c.addEventListener('change', updateBulk));

  // Clicking a row opens it (drafts open straight into the composer).
  rows.forEach(row => {
    row.addEventListener('click', (e) => {
      if (e.target.closest('input,button')) return;
      if (S.folderRole === 'drafts') { e.preventDefault(); MX.openDraft(parseInt(row.dataset.uid, 10)); return; }
      if (e.target.closest('a')) return;
      window.location.href = row.dataset.href;
    });
  });

  // Star toggle in the list, no reload.
  document.querySelectorAll('[data-star]').forEach(btn => btn.addEventListener('click', async (e) => {
    e.stopPropagation();
    const on = btn.classList.contains('on');
    btn.classList.toggle('on', !on);
    try {
      const r = await post(on ? 'unflag' : 'flag', { folder: S.folder, uids: btn.dataset.star });
      if (!r.ok) { btn.classList.toggle('on', on); toast(r.message, true); }
      if (String(S.viewUid) === btn.dataset.star) { S.flagged = !on; $('mxFlagBtn')?.classList.toggle('on', !on); }
    } catch (err) { btn.classList.toggle('on', on); toast(err.message, true); }
  }));

  function nextUrlAfterRemoval() {
    const next = S.nextUid || S.prevUid;
    if (!next) return S.listUrl;
    const u = new URL(S.listUrl, window.location.href);
    u.searchParams.set('uid', next);
    return u.pathname + u.search;
  }

  // ── Public API (used by onclick handlers) ───────────────────────────
  const MX = window.MX = {
    async bulk(action, target) {
      const uids = selected();
      if (!uids.length) return;
      closeMenus();
      try {
        const r = await post(action, { folder: S.folder, uids: uids.join(','), target: target || '' });
        if (!r.ok) return toast(r.message, true);
        const removedOpen = ['move', 'archive', 'delete'].includes(action) && uids.includes(String(S.viewUid));
        reloadWith(r.message, removedOpen ? S.listUrl : undefined);
      } catch (err) { toast(err.message, true); }
    },
    async one(action, target) {
      if (!S.viewUid) return;
      closeMenus();
      try {
        const r = await post(action, { folder: S.folder, uids: String(S.viewUid), target: target || '' });
        if (!r.ok) return toast(r.message, true);
        if (action === 'mark_unread') return reloadWith(r.message, S.listUrl);
        reloadWith(r.message, nextUrlAfterRemoval());
      } catch (err) { toast(err.message, true); }
    },
    async toggleFlag() {
      if (!S.viewUid) return;
      const on = S.flagged;
      try {
        const r = await post(on ? 'unflag' : 'flag', { folder: S.folder, uids: String(S.viewUid) });
        if (!r.ok) return toast(r.message, true);
        S.flagged = !on;
        $('mxFlagBtn')?.classList.toggle('on', !on);
        document.querySelector('[data-star="' + S.viewUid + '"]')?.classList.toggle('on', !on);
        toast(r.message);
      } catch (err) { toast(err.message, true); }
    },
    toggleMenu(id, ev) {
      ev.stopPropagation();
      const m = $(id);
      const open = m.style.display === 'none';
      closeMenus();
      m.style.display = open ? 'block' : 'none';
    },
    resizeFrame(frame) {
      const fit = () => { try { const d = frame.contentDocument; if (d && d.body) frame.style.height = (d.documentElement.scrollHeight + 8) + 'px'; } catch (e) { frame.style.height = '600px'; } };
      fit();
      try { frame.contentDocument.querySelectorAll('img').forEach(img => img.addEventListener('load', fit)); } catch (e) {}
      setTimeout(fit, 500); setTimeout(fit, 1500);
    },
  };
  function closeMenus() { document.querySelectorAll('.mx-menu').forEach(m => m.style.display = 'none'); }
  document.addEventListener('click', (e) => { if (!e.target.closest('.mx-menu-wrap')) closeMenus(); });

  // ════════════════════════════════════════════════════════════════════
  // COMPOSER
  // ════════════════════════════════════════════════════════════════════
  const C = {
    mode: 'new', draftUid: 0, origFolder: '', origUid: 0, inReplyTo: '', references: '',
    carry: [], files: [], dirty: false, busy: false, lastSaved: '', autosave: null,
  };
  const el = { box: $('mxComposer'), to: $('mxTo'), cc: $('mxCc'), bcc: $('mxBcc'), subject: $('mxSubject'), body: $('mxBody'), sig: $('mxSig') };

  function setStatus(t) { $('mxCStatus').textContent = t || ''; }
  function setMsg(type, text) { const m = $('mxCMsg'); m.className = 'mx-c-msg' + (type ? ' ' + type : ''); m.textContent = text || ''; }
  function hasContent() {
    return el.to.value.trim() || el.cc.value.trim() || el.bcc.value.trim() || el.subject.value.trim()
      || el.body.textContent.trim() || C.files.length || C.carry.length;
  }
  function markDirty() { C.dirty = true; setStatus(C.lastSaved ? 'Unsaved changes' : ''); }
  [el.to, el.cc, el.bcc, el.subject].forEach(i => i.addEventListener('input', markDirty));
  el.body.addEventListener('input', markDirty);
  el.sig.addEventListener('change', markDirty);

  // Flag recipient typos as you go.
  const emailRe = /^[^\s@<>,;]+@[^\s@<>,;]+\.[^\s@<>,;]+$/;
  function badAddresses(v) {
    return v.split(/[,;\n]+/).map(s => s.trim()).filter(Boolean)
      .map(s => (s.match(/<([^<>]+)>\s*$/) || [null, s])[1].trim())
      .filter(a => !emailRe.test(a));
  }
  [el.to, el.cc, el.bcc].forEach(i => i.addEventListener('blur', () => i.classList.toggle('bad', badAddresses(i.value).length > 0)));

  MX.showField = function (which) {
    $(which === 'cc' ? 'mxCcRow' : 'mxBccRow').style.display = 'flex';
    $(which === 'cc' ? 'mxShowCc' : 'mxShowBcc').style.display = 'none';
    (which === 'cc' ? el.cc : el.bcc).focus();
  };

  function renderAtts() {
    const wrap = $('mxAtts');
    const kb = (b) => b ? (b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB') : '';
    wrap.innerHTML = '';
    C.carry.forEach((a, i) => {
      const chip = document.createElement('span');
      chip.className = 'mx-chip';
      chip.innerHTML = '<span class="nm"></span><small></small><button type="button" title="Remove">×</button>';
      chip.querySelector('.nm').textContent = a.name;
      chip.querySelector('small').textContent = kb(a.bytes);
      chip.querySelector('button').onclick = () => { C.carry.splice(i, 1); renderAtts(); markDirty(); };
      wrap.appendChild(chip);
    });
    C.files.forEach((f, i) => {
      const chip = document.createElement('span');
      chip.className = 'mx-chip';
      chip.innerHTML = '<span class="nm"></span><small></small><button type="button" title="Remove">×</button>';
      chip.querySelector('.nm').textContent = f.name;
      chip.querySelector('small').textContent = kb(f.size);
      chip.querySelector('button').onclick = () => { C.files.splice(i, 1); renderAtts(); markDirty(); };
      wrap.appendChild(chip);
    });
  }
  function addFiles(list) {
    for (const f of list) {
      if (!C.files.some(x => x.name === f.name && x.size === f.size)) C.files.push(f);
    }
    renderAtts(); markDirty();
  }
  $('mxFile').addEventListener('change', (e) => { addFiles(e.target.files); e.target.value = ''; });
  el.box.addEventListener('dragover', (e) => { if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) { e.preventDefault(); el.box.classList.add('dragging'); } });
  el.box.addEventListener('dragleave', (e) => { if (!el.box.contains(e.relatedTarget)) el.box.classList.remove('dragging'); });
  el.box.addEventListener('drop', (e) => { if (e.dataTransfer?.files?.length) { e.preventDefault(); addFiles(e.dataTransfer.files); } el.box.classList.remove('dragging'); });

  function openComposer(opts) {
    if (el.box.classList.contains('open') && C.dirty && hasContent()) {
      // Park the message that's already open as a draft before replacing it.
      MX.saveDraft(false);
    }
    Object.assign(C, { mode: 'new', draftUid: 0, origFolder: '', origUid: 0, inReplyTo: '', references: '', carry: [], files: [], dirty: false, lastSaved: '' }, opts.state || {});
    $('mxCTitle').textContent = opts.title || 'New message';
    el.to.value = opts.to || ''; el.cc.value = opts.cc || ''; el.bcc.value = opts.bcc || '';
    el.subject.value = opts.subject || '';
    el.body.innerHTML = opts.body || '';
    el.sig.checked = opts.signature !== false;
    [el.to, el.cc, el.bcc].forEach(i => i.classList.remove('bad'));
    $('mxCcRow').style.display = el.cc.value ? 'flex' : 'none'; $('mxShowCc').style.display = el.cc.value ? 'none' : '';
    $('mxBccRow').style.display = el.bcc.value ? 'flex' : 'none'; $('mxShowBcc').style.display = el.bcc.value ? 'none' : '';
    setMsg(); setStatus(opts.status || '');
    renderAtts();
    el.box.classList.add('open'); el.box.classList.remove('min');
    clearInterval(C.autosave);
    C.autosave = setInterval(() => { if (C.dirty && !C.busy && hasContent()) MX.saveDraft(false); }, 20000);
    setTimeout(() => {
      const target = !el.to.value ? el.to : el.body;
      target.focus();
      if (target === el.body) { const r = document.createRange(); r.setStart(el.body, 0); r.collapse(true); const s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
    }, 60);
  }

  MX.compose = () => openComposer({ title: 'New message' });

  MX.reply = function (mode) {
    const o = S.open;
    if (!o) return;
    const state = { mode, origFolder: o.folder, origUid: o.uid };
    if (mode === 'forward') {
      openComposer({ title: 'Forward', subject: o.fwdSubject, body: o.fwdHtml,
        state: Object.assign(state, { carry: (o.attachments || []).slice() }) });
    } else {
      openComposer({
        title: mode === 'reply_all' ? 'Reply all' : 'Reply',
        to: mode === 'reply_all' ? o.replyAllTo : o.replyTo,
        cc: mode === 'reply_all' ? o.replyAllCc : '',
        subject: o.reSubject, body: o.quoteHtml,
        state: Object.assign(state, { inReplyTo: o.messageId, references: o.references }),
      });
    }
  };

  MX.openDraft = async function (uid) {
    try {
      const r = await post('get_draft', { folder: S.folder, uid });
      if (!r.ok) return toast(r.message, true);
      const d = r.draft;
      openComposer({
        title: 'Draft', to: d.to, cc: d.cc, bcc: d.bcc, subject: d.subject, body: d.body_html, signature: d.signature,
        status: 'Draft',
        state: { mode: d.mode, draftUid: d.is_draft ? d.uid : 0, origFolder: d.orig_folder, origUid: d.orig_uid,
                 inReplyTo: d.in_reply_to, references: d.references, carry: d.carry || [], lastSaved: 'draft' },
      });
    } catch (err) { toast(err.message, true); }
  };

  function payload() {
    return {
      to: el.to.value, cc: el.cc.value, bcc: el.bcc.value, subject: el.subject.value,
      body_html: el.body.innerHTML, include_signature: el.sig.checked ? '1' : '',
      in_reply_to: C.inReplyTo, references: C.references, draft_uid: C.draftUid || '',
      mode: C.mode, orig_folder: C.origFolder, orig_uid: C.origUid || '', carry: JSON.stringify(C.carry),
    };
  }

  MX.saveDraft = async function (manual) {
    if (C.busy) return;
    if (!hasContent()) { if (manual) setMsg('error', 'Nothing to save yet.'); return; }
    C.busy = true;
    setStatus('Saving…');
    const btn = $('mxSaveDraft'); btn.disabled = true;
    try {
      const r = await post('save_draft', payload(), C.files);
      if (!r.ok) { setStatus('Not saved'); setMsg('error', r.message); return; }
      // Uploaded files now live in the stored draft — refer to them there.
      C.draftUid = r.draft_uid; C.carry = r.carry || []; C.files = []; C.dirty = false; C.lastSaved = r.saved_at;
      renderAtts();
      setStatus('Draft saved ' + r.saved_at);
      if (manual) { setMsg(); toast('Draft saved to ' + (S.draftsFolder ? 'Drafts' : 'your mailbox') + '.'); }
    } catch (err) {
      setStatus('Not saved'); if (manual) setMsg('error', err.message);
    } finally { C.busy = false; btn.disabled = false; }
  };

  MX.send = async function () {
    if (C.busy) return;
    const bad = [el.to, el.cc, el.bcc].flatMap(i => badAddresses(i.value));
    if (!el.to.value.trim() && !el.cc.value.trim() && !el.bcc.value.trim()) { setMsg('error', 'Add at least one recipient.'); el.to.focus(); return; }
    if (bad.length) { setMsg('error', 'Check these addresses: ' + bad.slice(0, 4).join(', ')); return; }
    if (!el.subject.value.trim() && !confirmNoSubject()) return;
    C.busy = true;
    const btn = $('mxSend'); btn.disabled = true; btn.lastChild.textContent = ' Sending…';
    setMsg();
    try {
      const r = await post('send', payload(), C.files);
      if (!r.ok) { setMsg('error', r.message); return; }
      C.dirty = false;
      clearInterval(C.autosave);
      el.box.classList.remove('open', 'max');
      reloadWith(r.message);
    } catch (err) {
      setMsg('error', err.message);
    } finally { C.busy = false; btn.disabled = false; btn.lastChild.textContent = ' Send'; }
  };

  let subjectWarned = false;
  function confirmNoSubject() {
    if (subjectWarned) return true;
    subjectWarned = true;
    setMsg('error', 'This message has no subject. Press Send again to send it anyway.');
    el.subject.focus();
    return false;
  }

  MX.discard = async function () {
    const uid = C.draftUid;
    C.dirty = false;
    clearInterval(C.autosave);
    el.box.classList.remove('open', 'max');
    if (uid && S.draftsFolder) {
      try {
        const r = await post('delete', { folder: S.draftsFolder, uids: String(uid) });
        if (S.folderRole === 'drafts') return reloadWith('Draft discarded.');
        toast(r.ok ? 'Draft discarded.' : r.message, !r.ok);
      } catch (err) { toast(err.message, true); }
    }
  };

  async function closeComposer() {
    clearInterval(C.autosave);
    if (C.dirty && hasContent()) {
      await MX.saveDraft(false);
      toast(C.dirty ? 'Could not save the draft — your text is still in the composer.' : 'Saved to Drafts.', C.dirty);
      if (C.dirty) return;
      el.box.classList.remove('open', 'max');
      if (S.folderRole === 'drafts') reloadWith('Saved to Drafts.');
      return;
    }
    el.box.classList.remove('open', 'max');
    if (S.folderRole === 'drafts' && C.lastSaved && C.lastSaved !== 'draft') reloadWith('Saved to Drafts.');
  }

  MX.headClick = function (e) {
    const b = e.target.closest('button');
    const act = b ? b.dataset.act : 'min';
    if (act === 'close') return closeComposer();
    if (act === 'max') { el.box.classList.remove('min'); el.box.classList.toggle('max'); return; }
    if (act === 'min' || !b) { el.box.classList.remove('max'); el.box.classList.toggle('min'); }
  };

  // Rich-text toolbar
  $('rteToolbar').addEventListener('click', (e) => {
    const b = e.target.closest('button[data-cmd]');
    if (!b) return;
    el.body.focus();
    const cmd = b.dataset.cmd;
    if (cmd === 'createLink') {
      const url = window.prompt('Link address (https://…)', 'https://');
      if (url && /^(https?:\/\/|mailto:)/i.test(url.trim())) document.execCommand('createLink', false, url.trim());
    } else if (cmd === 'formatBlock') {
      document.execCommand('formatBlock', false, b.dataset.val);
    } else {
      document.execCommand(cmd, false, null);
    }
    markDirty();
  });

  // Paste: keep simple formatting only (Word/Outlook markup stripped).
  const ALLOWED = ['P','BR','B','STRONG','I','EM','U','UL','OL','LI','A','BLOCKQUOTE','DIV'];
  function cleanPasted(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('style,script,meta,link,head,title,xml').forEach(n => n.remove());
    (function walk(node) {
      Array.from(node.childNodes).forEach(ch => {
        if (ch.nodeType === Node.COMMENT_NODE) { ch.remove(); return; }
        if (ch.nodeType !== Node.ELEMENT_NODE) return;
        walk(ch);
        if (!ALLOWED.includes(ch.tagName)) { while (ch.firstChild) node.insertBefore(ch.firstChild, ch); ch.remove(); return; }
        const href = ch.tagName === 'A' ? ch.getAttribute('href') : null;
        Array.from(ch.attributes).forEach(a => ch.removeAttribute(a.name));
        if (href && /^(https?:|mailto:|tel:)/i.test(href)) ch.setAttribute('href', href);
      });
    })(doc.body);
    return doc.body.innerHTML;
  }
  el.body.addEventListener('paste', (e) => {
    const cd = e.clipboardData;
    if (!cd) return;
    if (cd.files && cd.files.length) { e.preventDefault(); addFiles(cd.files); return; }
    e.preventDefault();
    const html = cd.getData('text/html');
    if (html) document.execCommand('insertHTML', false, cleanPasted(html));
    else document.execCommand('insertText', false, cd.getData('text/plain'));
    markDirty();
  });

  // Composer keys
  el.box.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); MX.send(); }
    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); MX.saveDraft(true); }
    if (e.key === 'Escape') { e.preventDefault(); closeComposer(); }
  });
  window.addEventListener('beforeunload', (e) => {
    if (el.box.classList.contains('open') && C.dirty && hasContent()) { e.preventDefault(); e.returnValue = ''; }
  });

  // ── Page keyboard shortcuts ─────────────────────────────────────────
  document.addEventListener('keydown', (e) => {
    const a = document.activeElement;
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    if (a && (/INPUT|TEXTAREA|SELECT/.test(a.tagName) || a.isContentEditable)) return;
    if (el.box.classList.contains('open') && !el.box.classList.contains('min')) return;
    const go = (uid) => { if (!uid) return; const u = new URL(S.baseUrl, location.href); u.searchParams.set('uid', uid); location.href = u.pathname + u.search; };
    switch (e.key) {
      case 'c': e.preventDefault(); MX.compose(); break;
      case '/': e.preventDefault(); $('mxSearch').focus(); $('mxSearch').select(); break;
      case 'r': if (S.open) { e.preventDefault(); MX.reply('reply'); } break;
      case 'a': if (S.open) { e.preventDefault(); MX.reply('reply_all'); } break;
      case 'f': if (S.open) { e.preventDefault(); MX.reply('forward'); } break;
      case 'j': e.preventDefault(); go(S.viewUid ? S.nextUid : (rows[0] && rows[0].dataset.uid)); break;
      case 'k': e.preventDefault(); go(S.prevUid); break;
      case 's': if (S.viewUid) { e.preventDefault(); MX.toggleFlag(); } break;
      case 'u': if (S.viewUid) { e.preventDefault(); MX.one('mark_unread'); } break;
      case 'e': if (S.viewUid && S.hasArchive) { e.preventDefault(); MX.one('archive'); } else if (selected().length && S.hasArchive) { MX.bulk('archive'); } break;
      case '#': case 'Delete': if (S.viewUid) { e.preventDefault(); MX.one('delete'); } else if (selected().length) { MX.bulk('delete'); } break;
    }
  });

  // Keep the open row in view.
  document.querySelector('.mx-row.active')?.scrollIntoView({ block: 'nearest' });

  // Deep links from elsewhere in the app: mail.php?compose=1&to=…&subject=…
  const qp = new URLSearchParams(location.search);
  if (qp.get('compose') === '1') {
    openComposer({ title: 'New message', to: qp.get('to') || '', subject: qp.get('subject') || '' });
  }
})();
</script>
