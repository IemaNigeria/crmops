</main>
  </div>
</div>

<!-- Lead Trail modal — shared across every page. Merges interactions with
     reassignment / stage-change history so a lead can be handed over
     without losing context. Triggered via viewLeadHistory(leadId, name). -->
<div id="leadHistoryModal" style="display:none; position:fixed; inset:0; background:rgba(25,27,32,0.45); backdrop-filter:blur(4px); z-index:200; align-items:center; justify-content:center; padding:20px;">
  <div style="background:#fff; border-radius:18px; padding:24px; max-width:560px; width:100%; max-height:82vh; overflow-y:auto; box-shadow:0 30px 70px -20px rgba(0,0,0,0.4);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
      <h3 style="font-family:'Libre Franklin',sans-serif; font-size:16px;">Lead Trail — <span id="leadHistoryCompany"></span></h3>
      <button type="button" onclick="document.getElementById('leadHistoryModal').style.display='none'" class="btn-ghost" style="padding:4px 8px;">✕</button>
    </div>
    <div id="leadHistoryMeta" style="font-size:12.5px; color:var(--ink-500); margin:8px 0 14px; padding-bottom:12px; border-bottom:1px solid var(--neutral-200);"></div>
    <div id="leadHistoryBody"><p style="font-size:13px; color:var(--ink-500);">Loading…</p></div>
    <div class="form-actions"><button type="button" class="btn-primary" onclick="document.getElementById('leadHistoryModal').style.display='none'">Close</button></div>
  </div>
</div>

<script>
  // Notification bell — shows recent surveillance-task alerts (e.g. "X
  // closed the surveillance task for Y — settled") for the logged-in user.
  function toggleNotifPanel(){
    const panel = document.getElementById('notifPanel');
    if (!panel) return;
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
  }

  function markAllNotificationsRead(){
    fetch('notifications.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'action=mark_all_read'
    })
      .then(r => r.json())
      .then(data => {
        if (!data || !data.ok) return;
        document.querySelectorAll('#notifBellBtn .dot').forEach(d => d.remove());
        document.querySelectorAll('#notifPanel .notif-item').forEach(i => i.style.background = '');
        const markLink = document.querySelector('.notif-panel-head a');
        if (markLink) markLink.remove();
      })
      .catch(() => {});
  }

  document.addEventListener('click', (e) => {
    const wrap = document.querySelector('.notif-wrap');
    const panel = document.getElementById('notifPanel');
    if (wrap && panel && panel.style.display === 'block' && !wrap.contains(e.target)) {
      panel.style.display = 'none';
    }
  });

  // ── Shell: mobile sidebar, user menu, quick-jump search ──────────────
  function toggleSidebar(open){
    document.body.classList.toggle('nav-open', open);
  }

  (function(){
    const chip = document.querySelector('.user-chip');
    const menu = document.getElementById('userMenu');
    if (chip && menu) {
      const toggle = (e) => { e.stopPropagation(); menu.style.display = menu.style.display === 'none' ? 'block' : 'none'; };
      chip.addEventListener('click', toggle);
      chip.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(e); } });
      document.addEventListener('click', (e) => { if (!menu.contains(e.target)) menu.style.display = 'none'; });
    }

    const input = document.getElementById('quickJumpInput');
    const list  = document.getElementById('quickJumpList');
    if (!input || !list) return;
    const nav   = (window.CRM_NAV || []);
    const pages = nav.map(n => n.href.split('?')[0]);
    let items = [], active = 0;

    const escHtml = (t) => String(t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    function build(q){
      const t = q.trim().toLowerCase();
      items = nav.filter(n => !t || n.label.toLowerCase().includes(t))
                 .map(n => ({label: n.label, href: n.href, hint: 'Page'}));
      if (t) {
        const enc = encodeURIComponent(q.trim());
        if (pages.includes('clients.php'))  items.push({label: 'Search clients for “' + q.trim() + '”',  href: 'clients.php?q=' + enc,  hint: 'Clients'});
        if (pages.includes('accounts.php')) items.push({label: 'Search accounts for “' + q.trim() + '”', href: 'accounts.php?search=' + enc, hint: 'Accounts'});
        if (pages.includes('mail.php'))     items.push({label: 'Search mail for “' + q.trim() + '”',     href: 'mail.php?search=' + enc + '&since=all', hint: 'Mail'});
      }
      active = 0;
      render();
    }
    function render(){
      if (!items.length) { list.innerHTML = '<div class="notif-empty">No matches</div>'; list.style.display = 'block'; return; }
      list.innerHTML = items.map((it, i) =>
        '<a href="' + escHtml(it.href) + '" class="' + (i === active ? 'on' : '') + '" role="option"><span>' + escHtml(it.label) + '</span><small>' + escHtml(it.hint) + '</small></a>'
      ).join('');
      list.style.display = 'block';
    }
    input.addEventListener('focus', () => build(input.value));
    input.addEventListener('input', () => build(input.value));
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, items.length - 1); render(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); render(); }
      else if (e.key === 'Enter') { e.preventDefault(); if (items[active]) window.location.href = items[active].href; }
      else if (e.key === 'Escape') { list.style.display = 'none'; input.blur(); }
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('.search-wrap')) list.style.display = 'none'; });
    document.addEventListener('keydown', (e) => {
      const tag = (document.activeElement && document.activeElement.tagName) || '';
      const editing = /INPUT|TEXTAREA|SELECT/.test(tag) || (document.activeElement && document.activeElement.isContentEditable);
      if (e.key === '/' && !editing && !e.ctrlKey && !e.metaKey && !document.body.dataset.ownSlash) { e.preventDefault(); input.focus(); }
      if (e.key === 'Escape') toggleSidebar(false);
    });
  })();

  // Lead Trail — chronological interactions + reassignment/stage history
  // for one lead, so a handover doesn't lose context on where things stood.
  function viewLeadHistory(leadId, companyName){
    const modal = document.getElementById('leadHistoryModal');
    document.getElementById('leadHistoryCompany').textContent = companyName || '';
    document.getElementById('leadHistoryMeta').textContent = '';
    document.getElementById('leadHistoryBody').innerHTML = '<p style="font-size:13px; color:var(--ink-500);">Loading…</p>';
    modal.style.display = 'flex';

    fetch('lead-history.php?lead_id=' + encodeURIComponent(leadId))
      .then(r => r.json())
      .then(data => {
        const body = document.getElementById('leadHistoryBody');
        if (!data.ok) {
          body.innerHTML = '<p style="font-size:13px; color:var(--ink-500);"></p>'; body.firstChild.textContent = data.error || 'Could not load history.';
          return;
        }
        // Values arrive already HTML-escaped by lead-history.php (e()).
        const h = (t) => String(t ?? '');
        document.getElementById('leadHistoryMeta').innerHTML =
          h(data.lead.reference_number) + ' &middot; <b>' + h(data.lead.stage) + '</b> &middot; Currently with <b>' + h(data.lead.officer_name) + '</b>';

        if (!data.timeline.length) {
          body.innerHTML = '<p style="font-size:13px; color:var(--ink-500);">No activity logged yet.</p>';
          return;
        }
        body.innerHTML = data.timeline.map(item => {
          const badgeClass = item.kind === 'audit' ? 'stage-lead' : 'stage-proposal';
          const notes = item.notes ? '<div style="margin-top:4px; font-size:12.5px; color:var(--ink-700); white-space:pre-wrap;">' + h(item.notes) + '</div>' : '';
          return '<div class="activity-item">' +
            '<span class="activity-dot"></span>' +
            '<div style="flex:1;">' +
              '<div class="activity-text"><span class="badge ' + badgeClass + '" style="font-size:10px;">' + h(item.label) + '</span> &middot; <b>' + h(item.actor) + '</b></div>' +
              notes +
              '<div class="activity-time">' + h(item.when) + '</div>' +
            '</div>' +
          '</div>';
        }).join('');
      })
      .catch(() => {
        document.getElementById('leadHistoryBody').innerHTML = '<p style="font-size:13px; color:var(--ink-500);">Something went wrong loading this lead\'s history.</p>';
      });
  }
</script>
</body>
</html>