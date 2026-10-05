<?php
/**
 * IEMA CRMOps — Forgot Password
 * -----------------------------------------------------------------------
 * No outbound email service is configured yet, so this intentionally does
 * NOT attempt a token-based reset flow (building that without a mailer
 * and without rate-limiting would be a security liability). For now this
 * routes the request to IT Admin, who can reset a password directly via
 * users.php. Revisit once SMTP/transactional email is available.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';

$submitted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Log the request for IT Admin follow-up (no email sent — see note above).
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();
        $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)');
        $audit->execute([$userId ?: null, 'update', 'user', $userId ?: null, "Password reset requested for $email"]);
        $submitted = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — <?php echo e(APP_NAME); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/crm-bento.css">
<style>
  /* Page-scoped. Tokens come from assets/crm-bento.css. */
  body{ min-height:100vh; background:var(--canvas); display:flex; flex-direction:column; align-items:center; justify-content:center; padding:32px 16px; }
  html,body{ height:auto; }
  .fp{ width:100%; max-width:440px; margin:auto 0; }
  .fp-tile{ padding:0; overflow:hidden; }
  .fp-band{
    background:radial-gradient(120% 160% at 100% 0%, var(--navy-700) 0%, var(--navy-900) 60%, var(--navy-950) 100%);
    padding:22px 28px; display:flex; align-items:center; justify-content:space-between; gap:12px;
  }
  .fp-band img{ height:26px; width:auto; display:block; border-radius:6px; }
  .fp-band span{ font-size:11.5px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase; color:rgba(255,255,255,0.6); }
  .fp-body{ padding:28px 28px 24px; }
  .fp-icon{ width:44px; height:44px; border-radius:12px; background:var(--red-50); color:var(--red-600); display:flex; align-items:center; justify-content:center; margin-bottom:16px; }
  .fp-icon svg{ width:20px; height:20px; }
  .fp-icon.ok{ background:var(--green-50); color:var(--green); }
  h1{ font-size:22px; font-weight:800; letter-spacing:-0.3px; color:var(--ink-900); }
  .fp-body p{ margin-top:8px; font-size:13.5px; color:var(--ink-500); line-height:1.6; }
  .fp-body form{ margin-top:20px; display:flex; flex-direction:column; gap:14px; }
  .fp-body .form-field input{ padding:12px 14px; font-size:14px; border-radius:12px; }
  .fp-body .btn-primary{ width:100%; padding:13px 16px; font-size:14.5px; font-weight:700; border-radius:12px; }
  .fp-body .btn-primary:focus-visible{ outline:none; box-shadow:var(--ring); }
  .fp-body .alert{ margin:16px 0 0; }
  .fp-back{ margin-top:18px; padding-top:16px; border-top:1px solid var(--line-soft); text-align:center; }
  .fp-back a{ font-size:13px; font-weight:600; color:var(--navy-700); display:inline-flex; align-items:center; gap:6px; }
  .fp-back a:hover{ color:var(--red-600); }
  .fp-foot{ margin-top:16px; text-align:center; font-size:12px; color:var(--ink-500); }
  @media (max-width:480px){ .fp-body{ padding:24px 20px 20px; } .fp-band{ padding:18px 20px; } }
</style>
</head>
<body>
  <div class="fp" role="main">
    <section class="tile fp-tile">
      <div class="fp-band">
        <img src="assets/logo.png" alt="<?php echo e(APP_ORG); ?>">
        <span>CRMops</span>
      </div>
      <div class="fp-body">
        <?php if ($submitted): ?>
          <div class="fp-icon ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg></div>
          <h1>Request logged</h1>
          <div class="alert success" role="status"><span>Thanks — your request has been logged. Your IT Administrator will reach out to reset your password directly, since automated email reset isn't set up yet.</span></div>
        <?php else: ?>
          <div class="fp-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg></div>
          <h1>Forgot your password?</h1>
          <p>Automated email reset isn't configured for CRMOps yet. Enter your work email and IT will follow up to reset it manually.</p>
          <form method="POST">
            <div class="form-field">
              <label for="fpEmail">Work email</label>
              <input type="email" id="fpEmail" name="email" placeholder="you@iema-standards.com" autocomplete="email" required>
            </div>
            <button type="submit" class="btn-primary">Notify IT Admin</button>
          </form>
        <?php endif; ?>
        <div class="fp-back"><a href="login.php">← Back to Sign In</a></div>
      </div>
    </section>
    <div class="fp-foot">&copy; <?php echo date('Y'); ?> IEMA Standards Limited</div>
  </div>
</body>
</html>
