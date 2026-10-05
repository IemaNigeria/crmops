<?php
/**
 * IEMA CRMOps — Mail Settings
 * -----------------------------------------------------------------------
 * Each user connects their own existing cPanel email account. This does
 * NOT create mailboxes — the account must already exist on the mail
 * server. Password is encrypted at rest (see MAIL_ENCRYPTION_KEY).
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_login();

$uid = (int) ($_SESSION['user_id'] ?? 0);
$error = '';
$success = '';

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $email = trim($_POST['mail_email'] ?? '');
        $password = $_POST['mail_password'] ?? '';
        $imapHost = trim($_POST['mail_imap_host'] ?? '');
        $imapPort = (int) ($_POST['mail_imap_port'] ?? 993);
        $smtpHost = trim($_POST['mail_smtp_host'] ?? '');
        $smtpPort = (int) ($_POST['mail_smtp_port'] ?? 465);
        $skipCertCheck = isset($_POST['mail_skip_cert_check']) ? 1 : 0;
        $signatureTitle = trim($_POST['signature_title'] ?? '');
        $signaturePhone = trim($_POST['signature_phone'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid mailbox email address.';
        } elseif ($imapHost === '' || $smtpHost === '') {
            $error = 'IMAP and SMTP server addresses are required.';
        } else {
            if ($password !== '') {
                $encPassword = encrypt_secret($password);
                $stmt = $pdo->prepare('UPDATE users SET mail_email=?, mail_password_enc=?, mail_imap_host=?, mail_imap_port=?, mail_smtp_host=?, mail_smtp_port=?, mail_skip_cert_check=?, signature_title=?, signature_phone=? WHERE id=?');
                $stmt->execute([$email, $encPassword, $imapHost, $imapPort, $smtpHost, $smtpPort, $skipCertCheck, $signatureTitle ?: null, $signaturePhone ?: null, $uid]);
            } else {
                // Leave password untouched if they didn't re-enter it
                $stmt = $pdo->prepare('UPDATE users SET mail_email=?, mail_imap_host=?, mail_imap_port=?, mail_smtp_host=?, mail_smtp_port=?, mail_skip_cert_check=?, signature_title=?, signature_phone=? WHERE id=?');
                $stmt->execute([$email, $imapHost, $imapPort, $smtpHost, $smtpPort, $skipCertCheck, $signatureTitle ?: null, $signaturePhone ?: null, $uid]);
            }
            $success = 'Mail settings saved.';

            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$uid]);
            $user = $stmt->fetch();
        }
    } elseif ($action === 'test_connection') {
        $result = open_user_mailbox($user);
        if ($result['ok']) {
            $count = imap_num_msg($result['mbox']);
            imap_close($result['mbox']);
            $success = "Connected successfully — found $count message(s) in the inbox.";
        } else {
            $error = 'Connection test failed: ' . $result['error'];
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<?php
$msConnected = !empty($user['mail_email']);
$msImapOk    = imap_extension_available();
?>
<style>
.ms-kpi { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
.ms-email { font-family:'Libre Franklin',sans-serif; font-size:19px; font-weight:800; letter-spacing:-0.2px; margin-top:12px; color:#fff; overflow-wrap:anywhere; line-height:1.25; }
.ms-status { display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:999px; margin-top:10px; }
.ms-status.on { background:rgba(74,222,128,0.14); color:#86EFAC; }
.ms-status.off { background:rgba(255,255,255,0.1); color:rgba(255,255,255,0.75); }
.ms-status i { width:7px; height:7px; border-radius:50%; background:currentColor; }
.tile--navy .stat .v { color:#fff; font-size:14px; overflow-wrap:anywhere; }
.ms-section { display:flex; align-items:flex-start; gap:10px; margin:26px 0 6px; padding-top:20px; border-top:1px solid var(--line); }
.ms-section .tile-icon { width:32px; height:32px; border-radius:10px; }
.ms-section .tile-icon .icon { width:16px; height:16px; }
.ms-section h3 { font-size:14.5px; font-weight:700; }
.ms-hint { font-size:12px; color:var(--ink-500); line-height:1.5; }
.ms-check { display:flex; gap:10px; align-items:flex-start; background:var(--neutral-50); border:1px solid var(--line-soft); border-radius:12px; padding:11px 12px; }
.ms-check input { margin-top:2px; flex-shrink:0; width:16px; height:16px; }
.ms-check label { margin:0 !important; font-weight:500 !important; line-height:1.5; color:var(--ink-700) !important; }
.ms-preview { background:var(--neutral-50); border:1px solid var(--line-soft); border-radius:12px; padding:14px; }
.ms-preview-inner { background:#fff; border:1px solid var(--line); border-radius:10px; padding:14px; overflow-x:auto; }
.ms-defaults { list-style:none; display:flex; flex-direction:column; gap:8px; }
.ms-defaults li { display:flex; justify-content:space-between; gap:10px; font-size:12.5px; color:var(--ink-700); padding:8px 10px; background:var(--neutral-50); border-radius:10px; }
.ms-defaults b { color:var(--ink-900); }
.ms-side { display:flex; flex-direction:column; gap:var(--gap); min-width:0; }
@media (min-width:1025px){ .ms-side { position:sticky; top:88px; align-self:start; } }
@media (max-width:1024px){ .ms-side { display:contents; } .ms-side > .tile { grid-column:1 / -1; } .ms-side > .tile--navy { order:-1; } }
@media (max-width:760px){ .ms-preview { padding:8px; } .ms-preview-inner { padding:10px; } }
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Your account</div>
    <h1>Mail Settings</h1>
    <p>Connect your existing email account to send and receive mail without leaving CRMOps.</p>
  </div>
</div>

<?php if ($error): ?><div class="alert error"><?php echo icon('alert'); ?><span><?php echo e($error); ?></span></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?php echo icon('check'); ?><span><?php echo e($success); ?></span></div><?php endif; ?>

<?php if (!imap_extension_available()): ?>
<div class="alert error">
  <?php echo icon('alert'); ?>
  <span>This server's PHP install doesn't have the IMAP extension enabled. Mail can't work until an IT Administrator asks the hosting provider to enable <code>php-imap</code>. SMTP sending doesn't need this and will still work once configured below.</span>
</div>
<?php endif; ?>

<div class="bento">
  <section class="tile span-8">
    <div class="tile-head">
      <h2 class="tile-title"><?php echo icon('mail'); ?> Your Mailbox</h2>
      <span class="tile-sub" style="margin:0;">Existing cPanel account — this doesn't create mailboxes</span>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="save_settings">
      <div class="form-grid">
        <div class="form-field">
          <label>Email Address</label>
          <input type="email" name="mail_email" value="<?php echo e($user['mail_email'] ?? ''); ?>" placeholder="you@iema-standards.com" required>
        </div>
        <div class="form-field">
          <label>Mailbox Password <?php echo !empty($user['mail_email']) ? '(leave blank to keep current)' : ''; ?></label>
          <input type="password" name="mail_password" placeholder="<?php echo !empty($user['mail_email']) ? '••••••••' : 'Your cPanel email password'; ?>" autocomplete="new-password">
        </div>
        <div class="form-field">
          <label>IMAP Server</label>
          <input type="text" name="mail_imap_host" value="<?php echo e($user['mail_imap_host'] ?? 'mail.iema-standards.com'); ?>" placeholder="mail.yourdomain.com">
        </div>
        <div class="form-field">
          <label>IMAP Port</label>
          <input type="text" name="mail_imap_port" value="<?php echo e((string) ($user['mail_imap_port'] ?? 993)); ?>">
        </div>
        <div class="form-field">
          <label>SMTP Server</label>
          <input type="text" name="mail_smtp_host" value="<?php echo e($user['mail_smtp_host'] ?? 'mail.iema-standards.com'); ?>" placeholder="mail.yourdomain.com">
        </div>
        <div class="form-field">
          <label>SMTP Port</label>
          <input type="text" name="mail_smtp_port" value="<?php echo e((string) ($user['mail_smtp_port'] ?? 465)); ?>">
        </div>
        <div class="form-field full ms-check">
          <input type="checkbox" name="mail_skip_cert_check" id="skipCert" value="1" <?php echo !empty($user['mail_skip_cert_check']) ? 'checked' : ''; ?>>
          <label for="skipCert">Skip certificate hostname check — needed on some shared hosting where the SSL certificate doesn't match your domain (encryption still applies, only the hostname match is relaxed)</label>
        </div>
      </div>

      <div class="ms-section">
        <div class="tile-icon navy"><?php echo icon('edit'); ?></div>
        <div>
          <h3>Email Signature</h3>
          <div class="ms-hint">Automatically added to every message you send. Name and email come from your account — just fill in the rest.</div>
        </div>
      </div>
      <div class="form-grid" style="margin-top:14px;">
        <div class="form-field">
          <label>Job Title / Position</label>
          <input type="text" name="signature_title" value="<?php echo e($user['signature_title'] ?? ''); ?>" placeholder="e.g. Business Development Officer">
        </div>
        <div class="form-field">
          <label>Phone Number</label>
          <input type="text" name="signature_phone" value="<?php echo e($user['signature_phone'] ?? ''); ?>" placeholder="+234...">
        </div>
      </div>

      <div class="form-field" style="margin-top:16px;">
        <label>Preview</label>
        <div class="ms-preview">
          <div class="ms-preview-inner">
            <?php echo build_signature_html([
              'name' => $userName ?? ($_SESSION['user_name'] ?? 'Your Name'),
              'signature_title' => $user['signature_title'] ?? '',
              'mail_email' => $user['mail_email'] ?? 'you@iema-standards.com',
              'signature_phone' => $user['signature_phone'] ?? '',
            ]); ?>
          </div>
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Save Settings</button>
      </div>
    </form>
  </section>

  <div class="span-4 ms-side">
    <section class="tile tile--navy">
      <div class="ms-kpi">
        <div class="tile-label">Connected mailbox</div>
        <div class="tile-icon"><?php echo icon('mail'); ?></div>
      </div>
      <div class="ms-email"><?php echo $msConnected ? e($user['mail_email']) : 'Not connected'; ?></div>
      <span class="ms-status <?php echo $msConnected ? 'on' : 'off'; ?>"><i></i><?php echo $msConnected ? 'Credentials saved' : 'Add your mailbox to start'; ?></span>
      <div class="stat-row" style="margin-top:16px;">
        <div class="stat"><div class="v"><?php echo e((string) ($user['mail_imap_port'] ?? 993)); ?></div><div class="l">IMAP port</div></div>
        <div class="stat"><div class="v"><?php echo e((string) ($user['mail_smtp_port'] ?? 465)); ?></div><div class="l">SMTP port</div></div>
      </div>
      <?php if (!empty($user['mail_email'])): ?>
      <form method="POST" style="margin-top:16px;">
        <input type="hidden" name="action" value="test_connection">
        <button type="submit" class="btn-secondary" style="width:100%;"><?php echo icon('refresh'); ?> Test Connection</button>
      </form>
      <?php endif; ?>
    </section>

    <section class="tile">
      <div class="tile-head"><h2 class="tile-title">Standard cPanel defaults</h2></div>
      <ul class="ms-defaults">
        <li><span>IMAP (SSL)</span><b>993</b></li>
        <li><span>SMTP (SSL)</span><b>465</b></li>
        <li><span>PHP IMAP extension</span><b style="color:<?php echo $msImapOk ? 'var(--green-700)' : 'var(--red-600)'; ?>;"><?php echo $msImapOk ? 'Enabled' : 'Missing'; ?></b></li>
      </ul>
      <p class="ms-hint" style="margin-top:12px;">If your host uses different values, check cPanel → Email Accounts → Connect Devices for the exact settings.</p>
    </section>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
