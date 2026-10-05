<?php
/**
 * IEMA CRMOps — Clients (FR-2.1 to FR-2.7)
 */
require_once __DIR__ . '/config.php';
require_login();

$role = current_role();
$uid  = (int) ($_SESSION['user_id'] ?? 0);
$error = '';
$success = '';

function validate_client_fields(string $company, string $rc, string $email): string
{
    if ($company === '') return 'Company name is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Please enter a valid contact email address.'; // FR-2.7
    if ($rc !== '' && !preg_match('/^[A-Za-z0-9\-\/]{3,40}$/', $rc)) return 'RC number contains invalid characters.'; // FR-2.7
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry_sync') {
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();

    if ($client) {
        sync_client_to_auditops($pdo, $client);

        $checkStmt = $pdo->prepare('SELECT auditops_sync_id FROM clients WHERE id = ?');
        $checkStmt->execute([$clientId]);
        $newSyncId = $checkStmt->fetchColumn();

        if ($newSyncId) {
            $success = "\"{$client['company_name']}\" synced to AuditOps successfully.";
        } else {
            $error = 'Could not sync "' . e($client['company_name']) . '" to AuditOps. Check that the database grant is set up correctly (see config.php notes), or contact IT.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_client') {
    $company = trim($_POST['company_name'] ?? '');
    $rc      = trim($_POST['rc_number'] ?? '');
    $sector  = trim($_POST['sector'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $size    = trim($_POST['company_size'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $cname   = trim($_POST['primary_contact_name'] ?? '');
    $cemail  = trim($_POST['primary_contact_email'] ?? '');
    $cphone  = trim($_POST['primary_contact_phone'] ?? '');

    $error = validate_client_fields($company, $rc, $cemail);

    if ($error === '') {
        // FR-2.6: flag possible duplicate on RC number or company-name similarity before saving.
        $dupStmt = $pdo->prepare('SELECT id, company_name FROM clients WHERE rc_number = ? OR company_name LIKE ? LIMIT 1');
        $dupStmt->execute([$rc ?: '___none___', '%' . $company . '%']);
        $dup = $dupStmt->fetch();

        if ($dup && empty($_POST['confirm_duplicate'])) {
            $error = 'Possible duplicate found: "' . e($dup['company_name']) . '" already exists. Review it, or tick "This is a different company" and save again.';
            $_SESSION['_pending_client'] = $_POST;
        } else {
            $stmt = $pdo->prepare('INSERT INTO clients (company_name, rc_number, address, sector, company_size, website, primary_contact_name, primary_contact_email, primary_contact_phone, status) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$company, $rc ?: null, $address, $sector, $size, $website, $cname, $cemail, $cphone, 'prospect']);
            $newId = (int) $pdo->lastInsertId();

            sync_client_to_auditops($pdo, [
                'id' => $newId, 'company_name' => $company, 'sector' => $sector,
                'primary_contact_name' => $cname, 'primary_contact_email' => $cemail,
                'primary_contact_phone' => $cphone, 'status' => 'prospect', 'auditops_sync_id' => null,
            ]);

            $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
            $audit->execute([$uid, 'create', 'client', $newId, "Created client $company"]);

            $success = "Client \"$company\" created.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_client') {
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $company = trim($_POST['company_name'] ?? '');
    $rc      = trim($_POST['rc_number'] ?? '');
    $sector  = trim($_POST['sector'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $size    = trim($_POST['company_size'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $cname   = trim($_POST['primary_contact_name'] ?? '');
    $cemail  = trim($_POST['primary_contact_email'] ?? '');
    $cphone  = trim($_POST['primary_contact_phone'] ?? '');
    $status  = $_POST['status'] ?? 'prospect';
    $isStrategic = isset($_POST['is_strategic']) ? 1 : 0;

    $error = validate_client_fields($company, $rc, $cemail);

    if ($clientId <= 0) {
        $error = 'Client not found.';
    } elseif (!in_array($status, ['prospect', 'active', 'inactive'], true)) {
        $error = 'Invalid status.';
    } elseif ($error === '') {
        // Same duplicate check as create, but excluding this client itself.
        $dupStmt = $pdo->prepare('SELECT id, company_name FROM clients WHERE id != ? AND (rc_number = ? OR company_name LIKE ?) LIMIT 1');
        $dupStmt->execute([$clientId, $rc ?: '___none___', '%' . $company . '%']);
        $dup = $dupStmt->fetch();

        if ($dup && empty($_POST['confirm_duplicate'])) {
            $error = 'Possible duplicate: "' . e($dup['company_name']) . '" is a different client record with a matching name/RC number. Review it, or confirm to save anyway.';
            $_SESSION['_pending_update'] = $_POST;
        } else {
            $stmt = $pdo->prepare('UPDATE clients SET company_name=?, rc_number=?, address=?, sector=?, company_size=?, website=?, primary_contact_name=?, primary_contact_email=?, primary_contact_phone=?, status=?, is_strategic=? WHERE id=?');
            $stmt->execute([$company, $rc ?: null, $address, $sector, $size, $website, $cname, $cemail, $cphone, $status, $isStrategic, $clientId]);

            $syncIdStmt = $pdo->prepare('SELECT auditops_sync_id FROM clients WHERE id = ?');
            $syncIdStmt->execute([$clientId]);
            sync_client_to_auditops($pdo, [
                'id' => $clientId, 'company_name' => $company, 'sector' => $sector,
                'primary_contact_name' => $cname, 'primary_contact_email' => $cemail,
                'primary_contact_phone' => $cphone, 'status' => $status,
                'auditops_sync_id' => $syncIdStmt->fetchColumn(),
            ]);

            $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
            $audit->execute([$uid, 'update', 'client', $clientId, "Updated client details for $company"]);

            $success = "\"$company\" updated.";
            unset($_SESSION['_pending_update']);
        }
    }
}

$search = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if ($search !== '') {
    $where = 'WHERE company_name LIKE ? OR rc_number LIKE ?';
    $params = ['%' . $search . '%', '%' . $search . '%'];
}
$stmt = $pdo->prepare("SELECT * FROM clients $where ORDER BY created_at DESC");
$stmt->execute($params);
$clients = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<?php
// Summary numbers for the bento tiles — derived only from $clients (already loaded above).
$clientTotal = count($clients);
$clientActive = $clientProspect = $clientInactive = $clientStrategic = $clientSynced = 0;
foreach ($clients as $c) {
    if ($c['status'] === 'active') $clientActive++;
    elseif ($c['status'] === 'prospect') $clientProspect++;
    else $clientInactive++;
    if (!empty($c['is_strategic'])) $clientStrategic++;
    if (!empty($c['auditops_sync_id'])) $clientSynced++;
}
$syncPct = $clientTotal > 0 ? (int) round($clientSynced / $clientTotal * 100) : 0;
?>
<style>
/* clients.php — page-scoped bento helpers */
#newClientForm{ scroll-margin-top:84px; }
.client-form-grid{ grid-template-columns:repeat(4, minmax(0,1fr)); }
.client-form-grid .form-field.full{ grid-column:span 2; }
@media (max-width:1024px){ .client-form-grid{ grid-template-columns:repeat(2, minmax(0,1fr)); } }
@media (max-width:700px){ .client-form-grid{ grid-template-columns:1fr; } .client-form-grid .form-field.full{ grid-column:1 / -1; } }
.client-search{ width:300px; max-width:100%; padding:7px 12px; }
.client-name-btn{ background:none; border:none; padding:0; cursor:pointer; text-align:left; font:inherit; }
.client-name-btn .row-name{ color:var(--navy-700); }
.client-name-btn:hover .row-name{ color:var(--red-600); text-decoration:underline; }
.clients-table td{ white-space:nowrap; }
.clients-table td.wrap{ white-space:normal; min-width:180px; }
.clients-table .row-sub{ display:block; margin-top:2px; }
.sync-retry{ border:none; cursor:pointer; font-family:inherit; }
.sync-retry:hover{ background:var(--red-50); color:var(--red-700); }
.tile .meter{ margin-top:14px; }
.tile-value small{ font-size:14px; font-weight:600; color:var(--ink-500); letter-spacing:0; }
.tile--navy .tile-value small{ color:rgba(255,255,255,0.65); }
@media (max-width:760px){ .bento > .tile.m-half{ grid-column:span 6 !important; } .bento > .tile.m-half .tile-icon{ display:none; } }
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Business Development</div>
    <h1>Clients</h1>
    <p>Client and contact records for Business Development purposes. Click a company name for full details.</p>
  </div>
  <div class="page-actions">
    <button class="btn-primary" onclick="document.getElementById('newClientForm').style.display='block'; this.style.display='none';">
      <?php echo icon('plus'); ?> New Client
    </button>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert error">
    <?php echo icon('alert'); ?>
    <span>
      <?php echo $error; /* DB values inside are escaped where the message is built */ ?>
      <?php if (!empty($_SESSION['_pending_client'])): ?>
        <form method="POST" style="margin-top:8px;">
          <input type="hidden" name="action" value="create_client">
          <?php foreach ($_SESSION['_pending_client'] as $k => $v): if ($k === 'confirm_duplicate') continue; ?>
            <input type="hidden" name="<?php echo e($k); ?>" value="<?php echo e($v); ?>">
          <?php endforeach; ?>
          <input type="hidden" name="confirm_duplicate" value="1">
          <button type="submit" class="btn-secondary btn-sm" style="margin-top:6px;">This is a different company — save anyway</button>
        </form>
      <?php endif; ?>
      <?php if (!empty($_SESSION['_pending_update'])): ?>
        <form method="POST" style="margin-top:8px;">
          <input type="hidden" name="action" value="update_client">
          <?php foreach ($_SESSION['_pending_update'] as $k => $v): if ($k === 'confirm_duplicate') continue; ?>
            <input type="hidden" name="<?php echo e($k); ?>" value="<?php echo e($v); ?>">
          <?php endforeach; ?>
          <input type="hidden" name="confirm_duplicate" value="1">
          <button type="submit" class="btn-secondary btn-sm" style="margin-top:6px;">Save anyway</button>
        </form>
      <?php endif; ?>
    </span>
  </div>
<?php endif; ?>
<?php if ($success): ?><div class="alert success"><?php echo icon('check'); ?><span><?php echo e($success); ?></span></div><?php unset($_SESSION['_pending_client']); endif; ?>

<div class="bento">
  <section class="tile span-12" id="newClientForm" style="display:none;">
    <div class="tile-head">
      <h2 class="tile-title"><span class="tile-icon" style="width:30px;height:30px;border-radius:9px;"><?php echo icon('building'); ?></span> New Client</h2>
      <span class="tile-sub" style="margin:0;">Saved as a prospect and synced to AuditOps.</span>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="create_client">
      <div class="form-grid client-form-grid">
        <div class="form-field"><label>Company Name *</label><input type="text" name="company_name" required></div>
        <div class="form-field"><label>RC Number</label><input type="text" name="rc_number" placeholder="RC123456"></div>
        <div class="form-field"><label>Sector</label><input type="text" name="sector"></div>
        <div class="form-field"><label>Company Size</label>
          <select name="company_size"><option>1-50</option><option>50-100</option><option>100-250</option><option>250-500</option><option>500+</option></select>
        </div>
        <div class="form-field full"><label>Address</label><input type="text" name="address"></div>
        <div class="form-field"><label>Website</label><input type="text" name="website" placeholder="company.example"></div>
        <div class="form-field"><label>Primary Contact Name</label><input type="text" name="primary_contact_name"></div>
        <div class="form-field"><label>Primary Contact Email</label><input type="email" name="primary_contact_email"></div>
        <div class="form-field"><label>Primary Contact Phone</label><input type="text" name="primary_contact_phone" placeholder="+234..."></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="document.getElementById('newClientForm').style.display='none';">Cancel</button>
        <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Save Client</button>
      </div>
    </form>
  </section>

  <section class="tile tile--navy span-3 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Active clients</span>
      <span class="tile-icon"><?php echo icon('building'); ?></span>
    </div>
    <div class="tile-value"><?php echo $clientActive; ?> <small>of <?php echo $clientTotal; ?></small></div>
    <div class="tile-sub"><?php echo $search !== '' ? 'Matching “' . e($search) . '”' : 'All client records'; ?></div>
  </section>

  <section class="tile span-3 flex-col m-half">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Prospects</span>
      <span class="tile-icon navy"><?php echo icon('target'); ?></span>
    </div>
    <div class="tile-value"><?php echo $clientProspect; ?></div>
    <div class="tile-sub"><?php echo $clientInactive; ?> inactive</div>
  </section>

  <section class="tile span-3 flex-col m-half">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">Strategic</span>
      <span class="tile-icon amber"><?php echo icon('shield'); ?></span>
    </div>
    <div class="tile-value"><?php echo $clientStrategic; ?></div>
    <div class="tile-sub">Strategic accounts</div>
  </section>

  <section class="tile span-3 flex-col">
    <div class="tile-head" style="margin-bottom:0;">
      <span class="tile-label">AuditOps sync</span>
      <span class="tile-icon green"><?php echo icon('refresh'); ?></span>
    </div>
    <div class="tile-value"><?php echo $syncPct; ?>%</div>
    <div class="tile-sub"><?php echo $clientSynced; ?> synced · <?php echo $clientTotal - $clientSynced; ?> pending</div>
    <div class="meter"><span style="width:<?php echo $syncPct; ?>%; background:var(--green);"></span></div>
  </section>

  <section class="tile span-12">
    <div class="tile-head">
      <h2 class="tile-title">Client records <span class="badge navy"><?php echo $clientTotal; ?></span></h2>
      <form method="GET" class="search-box client-search">
        <?php echo icon('search'); ?>
        <input type="text" name="q" placeholder="Search company or RC number…" value="<?php echo e($search); ?>">
      </form>
    </div>
  <?php if (empty($clients)): ?>
    <div class="empty-state">
      <div class="icon-button"><?php echo icon('building'); ?></div>
      <p>No clients yet. Click "New Client" to add one.</p>
    </div>
  <?php else: ?>
  <div class="table-wrap">
  <table class="clients-table">
    <thead><tr><th>Company</th><th>RC Number</th><th>Sector</th><th>Primary Contact</th><th>Status</th><th>AuditOps</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($clients as $c): ?>
      <tr>
        <td class="wrap">
          <button type="button" class="client-name-btn" onclick="openClientModal(<?php echo (int) $c['id']; ?>)">
            <span class="row-name"><?php echo e($c['company_name']); ?></span>
          </button>
          <?php if ($c['is_strategic']): ?> <span class="badge stage-negotiation">Strategic</span><?php endif; ?>
        </td>
        <td><?php echo e($c['rc_number'] ?? '—'); ?></td>
        <td class="wrap" style="min-width:120px;"><?php echo e($c['sector'] ?? '—'); ?></td>
        <td><?php echo e($c['primary_contact_name'] ?? '—'); ?><span class="row-sub"><?php echo e($c['primary_contact_email'] ?? ''); ?></span></td>
        <td><span class="badge <?php echo $c['status'] === 'active' ? 'stage-client' : 'stage-lead'; ?>"><?php echo e(ucfirst($c['status'])); ?></span></td>
        <td>
          <?php if (!empty($c['auditops_sync_id'])): ?>
            <span class="badge stage-client" title="Synced to AuditOps (id #<?php echo (int) $c['auditops_sync_id']; ?>)">✓ Synced</span>
          <?php else: ?>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="retry_sync">
              <input type="hidden" name="client_id" value="<?php echo (int) $c['id']; ?>">
              <button type="submit" class="badge stage-lost sync-retry" title="Click to retry syncing to AuditOps">✕ Not synced — retry</button>
            </form>
          <?php endif; ?>
        </td>
        <td>
          <button type="button" class="btn-ghost" style="padding:6px 9px;" title="Edit" onclick="openClientModal(<?php echo (int) $c['id']; ?>, true)">
            <?php echo icon('edit'); ?>
          </button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  </section>
</div>

<!-- Client details / edit modal -->
<div id="clientModal" style="display:none; position:fixed; inset:0; background:rgba(11,21,36,0.45); backdrop-filter:blur(4px); z-index:100; align-items:center; justify-content:center; padding:20px;">
  <div style="background:#fff; border-radius:var(--r-tile); border:1px solid var(--line); padding:26px; max-width:560px; width:100%; max-height:88vh; overflow-y:auto; box-shadow:var(--shadow-pop);">

    <!-- VIEW MODE -->
    <div id="clientViewMode">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
        <h3 id="viewCompanyName" style="font-family:'Libre Franklin',sans-serif; font-size:19px; font-weight:800;"></h3>
        <button type="button" onclick="closeClientModal()" class="btn-ghost" style="padding:4px 8px;">✕</button>
      </div>
      <div id="viewStrategicBadge" style="margin-bottom:14px;"></div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px 20px; font-size:13.5px;">
        <div><div class="row-sub">RC Number</div><div id="viewRc" style="font-weight:600; margin-top:2px;"></div></div>
        <div><div class="row-sub">Status</div><div id="viewStatus" style="margin-top:2px;"></div></div>
        <div><div class="row-sub">Sector</div><div id="viewSector" style="font-weight:600; margin-top:2px;"></div></div>
        <div><div class="row-sub">Company Size</div><div id="viewSize" style="font-weight:600; margin-top:2px;"></div></div>
        <div style="grid-column:1/-1;"><div class="row-sub">Address</div><div id="viewAddress" style="font-weight:600; margin-top:2px;"></div></div>
        <div><div class="row-sub">Website</div><div id="viewWebsite" style="font-weight:600; margin-top:2px;"></div></div>
        <div><div class="row-sub">Primary Contact</div><div id="viewContactName" style="font-weight:600; margin-top:2px;"></div></div>
        <div><div class="row-sub">Contact Email</div><div id="viewContactEmail" style="font-weight:600; margin-top:2px;"></div></div>
        <div><div class="row-sub">Contact Phone</div><div id="viewContactPhone" style="font-weight:600; margin-top:2px;"></div></div>
        <div><div class="row-sub">Client Since</div><div id="viewCreated" style="font-weight:600; margin-top:2px;"></div></div>
        <div><div class="row-sub">AuditOps Sync</div><div id="viewAuditOpsSync" style="font-weight:600; margin-top:2px;"></div></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="closeClientModal()">Close</button>
        <button type="button" class="btn-primary" onclick="switchToEditMode()"><?php echo icon('edit'); ?> Edit</button>
      </div>
    </div>

    <!-- EDIT MODE -->
    <form method="POST" id="clientEditForm" style="display:none;">
      <input type="hidden" name="action" value="update_client">
      <input type="hidden" name="client_id" id="editClientId">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
        <h3 style="font-family:'Libre Franklin',sans-serif; font-size:16px;">Edit Client</h3>
        <button type="button" onclick="closeClientModal()" class="btn-ghost" style="padding:4px 8px;">✕</button>
      </div>
      <div class="form-grid">
        <div class="form-field full"><label>Company Name *</label><input type="text" name="company_name" id="editCompanyName" required></div>
        <div class="form-field"><label>RC Number</label><input type="text" name="rc_number" id="editRc"></div>
        <div class="form-field"><label>Sector</label><input type="text" name="sector" id="editSector"></div>
        <div class="form-field"><label>Company Size</label>
          <select name="company_size" id="editSize"><option value="">—</option><option>1-50</option><option>50-100</option><option>100-250</option><option>250-500</option><option>500+</option></select>
        </div>
        <div class="form-field"><label>Status</label>
          <select name="status" id="editStatus"><option value="prospect">Prospect</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
        </div>
        <div class="form-field full"><label>Address</label><input type="text" name="address" id="editAddress"></div>
        <div class="form-field"><label>Website</label><input type="text" name="website" id="editWebsite"></div>
        <div class="form-field"><label>Primary Contact Name</label><input type="text" name="primary_contact_name" id="editContactName"></div>
        <div class="form-field"><label>Primary Contact Email</label><input type="email" name="primary_contact_email" id="editContactEmail"></div>
        <div class="form-field"><label>Primary Contact Phone</label><input type="text" name="primary_contact_phone" id="editContactPhone"></div>
        <div class="form-field full checkbox-row">
          <input type="checkbox" name="is_strategic" id="editStrategic" value="1">
          <label for="editStrategic" style="margin:0;">Strategic client</label>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-secondary" onclick="switchToViewMode()">Cancel</button>
        <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Save Changes</button>
      </div>
    </form>

  </div>
</div>

<script>
const CLIENTS_DATA = <?php echo json_encode(array_column($clients, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function fmtDate(iso){
  if (!iso) return '—';
  const d = new Date(iso.replace(' ', 'T'));
  return d.toLocaleDateString('en-GB', { day:'numeric', month:'short', year:'numeric' });
}

function openClientModal(id, editMode){
  const c = CLIENTS_DATA[id];
  if (!c) return;

  document.getElementById('viewCompanyName').textContent = c.company_name;
  document.getElementById('viewStrategicBadge').innerHTML = c.is_strategic == 1 ? '<span class="badge stage-negotiation">Strategic Client</span>' : '';
  document.getElementById('viewRc').textContent = c.rc_number || '—';
  document.getElementById('viewStatus').innerHTML = '<span class="badge ' + (c.status === 'active' ? 'stage-client' : 'stage-lead') + '">' + c.status.charAt(0).toUpperCase() + c.status.slice(1) + '</span>';
  document.getElementById('viewSector').textContent = c.sector || '—';
  document.getElementById('viewSize').textContent = c.company_size || '—';
  document.getElementById('viewAddress').textContent = c.address || '—';
  document.getElementById('viewWebsite').textContent = c.website || '—';
  document.getElementById('viewContactName').textContent = c.primary_contact_name || '—';
  document.getElementById('viewContactEmail').textContent = c.primary_contact_email || '—';
  document.getElementById('viewContactPhone').textContent = c.primary_contact_phone || '—';
  document.getElementById('viewCreated').textContent = fmtDate(c.created_at);
  document.getElementById('viewAuditOpsSync').innerHTML = c.auditops_sync_id
    ? '<span class="badge stage-client">✓ Synced (id #' + c.auditops_sync_id + ')</span>'
    : '<span class="badge stage-lost">✕ Not synced</span>';

  document.getElementById('editClientId').value = c.id;
  document.getElementById('editCompanyName').value = c.company_name || '';
  document.getElementById('editRc').value = c.rc_number || '';
  document.getElementById('editSector').value = c.sector || '';
  document.getElementById('editSize').value = c.company_size || '';
  document.getElementById('editStatus').value = c.status || 'prospect';
  document.getElementById('editAddress').value = c.address || '';
  document.getElementById('editWebsite').value = c.website || '';
  document.getElementById('editContactName').value = c.primary_contact_name || '';
  document.getElementById('editContactEmail').value = c.primary_contact_email || '';
  document.getElementById('editContactPhone').value = c.primary_contact_phone || '';
  document.getElementById('editStrategic').checked = c.is_strategic == 1;

  document.getElementById('clientModal').style.display = 'flex';
  if (editMode) { switchToEditMode(); } else { switchToViewMode(); }
}

function switchToEditMode(){
  document.getElementById('clientViewMode').style.display = 'none';
  document.getElementById('clientEditForm').style.display = 'block';
}
function switchToViewMode(){
  document.getElementById('clientViewMode').style.display = 'block';
  document.getElementById('clientEditForm').style.display = 'none';
}
function closeClientModal(){
  document.getElementById('clientModal').style.display = 'none';
}

<?php if (!empty($_SESSION['_pending_update'])): ?>
// Reopen the edit modal on the same client if a duplicate check needs confirming
openClientModal(<?php echo (int) $_SESSION['_pending_update']['client_id']; ?>, true);
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
