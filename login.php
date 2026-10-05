<?php
/**
 * IEMA CRMOps — Login
 * -----------------------------------------------------------------------
 * Authenticates against the `users` table (see schema.sql). No accounts
 * are self-registered — IT Admin or Management create every login from
 * the User Accounts page, then hand the credentials to the person
 * directly. This page intentionally shows no demo/sample credentials.
 * -----------------------------------------------------------------------
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/iema-sso/client.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// IEMA Portal single sign-on. When the connector is switched on and
// enforced, everyone signs in at the Portal; this page's own form stays
// available at login.php?local=1 as an emergency door for IT Admins only.
$ssoOn       = iema_sso_enabled();
$localOnlyIT = $ssoOn && IEMA_SSO_ENFORCE;
if ($localOnlyIT && !isset($_GET['local']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . iema_sso_login_url('/dashboard.php'));
    exit;
}

$error = '';
$expired = isset($_GET['expired']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both your email address and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role, status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Incorrect email or password. Please try again, or contact your IT Administrator.';
        } elseif ($user['status'] !== 'active') {
            $error = 'This account has been suspended. Contact your IT Administrator.';
        } elseif ($localOnlyIT && $user['role'] !== 'it_admin') {
            $error = 'Please sign in through IEMA Portal. This page is only for IT Admins when the Portal is unavailable.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id']       = $user['id'];
            $_SESSION['user_name']     = $user['name'];
            $_SESSION['user_email']    = $user['email'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['last_activity'] = time();

            $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);

            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — <?php echo e(APP_NAME); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/crm-bento.css">
<style>
  /* Page-scoped: auth bento. Tokens come from assets/crm-bento.css. */
  body{ min-height:100vh; background:var(--canvas); overflow-x:hidden; }
  .auth{
    min-height:100vh; display:flex; flex-direction:column; justify-content:center;
    max-width:1180px; margin:0 auto; padding:32px 24px;
  }
  .auth-grid{
    display:grid; gap:var(--gap);
    grid-template-columns:repeat(3, minmax(0,1fr)) minmax(360px, 1.2fr);
    grid-template-areas:
      "hero hero hero form"
      "t1   t2   t3   form";
    grid-template-rows:minmax(360px, auto) auto;
  }
  .a-hero{ grid-area:hero; } .a-form{ grid-area:form; }
  .a-t1{ grid-area:t1; } .a-t2{ grid-area:t2; } .a-t3{ grid-area:t3; }

  /* Hero (navy) */
  .a-hero{ display:flex; flex-direction:column; padding:30px 32px; overflow:hidden; }
  .a-hero::after{
    content:''; position:absolute; right:-80px; bottom:-120px; width:340px; height:340px; border-radius:50%;
    border:1px solid rgba(255,255,255,0.07); box-shadow:0 0 0 48px rgba(255,255,255,0.025), 0 0 0 96px rgba(255,255,255,0.02);
    pointer-events:none;
  }
  .hero-top{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
  .hero-logo{ height:30px; width:auto; display:block; border-radius:7px; }
  .hero-status{
    display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:999px;
    background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.10);
    font-size:11.5px; font-weight:600; color:rgba(255,255,255,0.78);
  }
  .hero-status i{ width:7px; height:7px; border-radius:50%; background:#34D399; box-shadow:0 0 0 3px rgba(52,211,153,0.18); }
  .hero-copy{ margin-top:auto; padding-top:48px; max-width:520px; position:relative; z-index:1; }
  .hero-kicker{ font-size:11.5px; font-weight:700; letter-spacing:1.4px; text-transform:uppercase; color:rgba(255,255,255,0.6); display:flex; align-items:center; gap:10px; }
  .hero-kicker::before{ content:''; width:18px; height:2px; border-radius:2px; background:var(--red-500); }
  .a-hero h1{ margin-top:14px; font-size:clamp(34px, 4vw, 48px); font-weight:800; line-height:1.06; letter-spacing:-0.8px; color:#fff; }
  .a-hero h1 span{ display:block; margin-top:10px; font-size:clamp(18px, 1.7vw, 22px); line-height:1.3; letter-spacing:-0.3px; color:rgba(255,255,255,0.62); font-weight:600; }
  .a-hero .hero-desc{ margin-top:14px; font-size:15px; line-height:1.6; color:rgba(255,255,255,0.72); max-width:460px; }

  /* Small info tiles */
  .mini{ display:flex; flex-direction:column; justify-content:space-between; gap:12px; padding:18px; }
  .mini .tile-icon{ width:34px; height:34px; border-radius:10px; }
  .mini .tile-icon svg{ width:17px; height:17px; }
  .mini b{ display:block; font-family:'Libre Franklin','Inter',sans-serif; font-size:13.5px; font-weight:700; color:var(--ink-900); line-height:1.3; }
  .mini small{ display:block; margin-top:3px; font-size:12px; color:var(--ink-500); line-height:1.45; }

  /* Form tile */
  .a-form{ padding:34px 32px 26px; display:flex; flex-direction:column; }
  .a-form .eyebrow{ margin-bottom:10px; }
  .a-form h2{ font-size:26px; font-weight:800; letter-spacing:-0.4px; color:var(--ink-900); }
  .a-form .lead{ margin-top:6px; color:var(--ink-500); font-size:14px; line-height:1.5; }
  .a-form .alert{ margin:20px 0 0; }
  .a-form form{ margin-top:22px; display:flex; flex-direction:column; gap:16px; }
  .a-form .form-field label{ font-size:12.5px; }
  .a-form .form-field input{ padding:12px 14px; font-size:14px; border-radius:12px; border-color:var(--neutral-300); }
  .label-row{ display:flex; align-items:baseline; justify-content:space-between; gap:10px; margin-bottom:6px; }
  .label-row label{ margin-bottom:0 !important; }
  .sso-btn{ display:flex; align-items:center; justify-content:center; gap:10px; text-decoration:none; background:var(--navy-800) !important; margin-top:20px; }
  .sso-btn:hover{ background:var(--navy-700) !important; }
  .sso-btn svg{ width:18px; height:18px; }
  .sso-or{ display:flex; align-items:center; gap:12px; margin:18px 0 2px; color:var(--ink-500); font-size:12px; }
  .sso-or::before, .sso-or::after{ content:''; flex:1; height:1px; background:var(--line, #E8E4DD); }
  .forgot{ font-size:12.5px; font-weight:600; color:var(--red-600); }
  .forgot:hover{ text-decoration:underline; color:var(--red-700); }
  .pw-wrap{ position:relative; }
  .pw-wrap input{ padding-right:46px !important; }
  .icon-btn{
    position:absolute; right:6px; top:50%; transform:translateY(-50%); width:34px; height:34px;
    border:none; background:transparent; cursor:pointer; display:flex; align-items:center; justify-content:center;
    border-radius:9px; color:var(--ink-500);
  }
  .icon-btn:hover{ background:var(--neutral-100); color:var(--ink-900); }
  .icon-btn:focus-visible{ outline:none; box-shadow:var(--ring); }
  .icon-btn svg{ width:18px; height:18px; }
  .btn-signin{ width:100%; margin-top:6px; padding:13px 16px; font-size:15px; font-weight:700; border-radius:12px; }
  .btn-signin:focus-visible{ outline:none; box-shadow:var(--ring); }
  .access-note{
    margin-top:auto; display:flex; align-items:flex-start; gap:10px; padding:13px 14px;
    background:var(--neutral-50); border:1px solid var(--line-soft); border-radius:var(--r-md);
  }
  .access-wrap{ margin-top:22px; flex:1; display:flex; flex-direction:column; }
  .access-note svg{ width:16px; height:16px; flex-shrink:0; margin-top:1px; color:var(--navy-700); }
  .access-note p{ font-size:12.5px; color:var(--ink-700); line-height:1.55; }
  .auth-foot{ margin-top:18px; display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:12px; color:var(--ink-500); padding:0 4px; }

  @media (max-width:1024px){
    .auth-grid{
      grid-template-columns:repeat(3, minmax(0,1fr));
      grid-template-areas:
        "hero hero hero"
        "form form form"
        "t1   t2   t3";
      grid-template-rows:auto;
    }
    .a-form{ max-width:none; }
  }
  @media (max-width:760px){
    .auth{ padding:16px; justify-content:flex-start; }
    .auth-grid{
      grid-template-columns:minmax(0,1fr);
      grid-template-areas: "form" "hero" "t1" "t2" "t3";
    }
    .a-form{ padding:26px 20px 22px; }
    .a-hero{ padding:24px 22px; }
    .hero-copy{ padding-top:28px; }
    .a-hero h1{ font-size:32px; }
    .a-hero h1 span{ font-size:17px; }
    .auth-foot{ justify-content:center; text-align:center; }
    .mini{ flex-direction:row; align-items:center; justify-content:flex-start; gap:14px; padding:14px 16px; }
    .d-br{ display:none; }
  }
  @media (prefers-reduced-motion: reduce){ *{ transition:none !important; } }
</style>
</head>
<body>
<main class="auth">
  <div class="auth-grid">

    <!-- Sign-in form -->
    <section class="tile a-form" aria-labelledby="signinTitle">
      <div class="eyebrow">CRMops</div>
      <h2 id="signinTitle">Welcome back</h2>
      <p class="lead">Sign in with your IEMA work account to continue.</p>

      <?php if ($error): ?>
        <div class="alert error" role="alert">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
          <span><?php echo e($error); ?></span>
        </div>
      <?php elseif ($expired): ?>
        <div class="alert info" role="status">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
          <span>Your session expired. Please sign in again.</span>
        </div>
      <?php endif; ?>

      <?php if ($ssoOn): ?>
        <a class="btn-primary btn-signin sso-btn" href="<?php echo e(iema_sso_login_url('/dashboard.php')); ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"></rect><rect x="14" y="3" width="7" height="7" rx="1.5"></rect><rect x="3" y="14" width="7" height="7" rx="1.5"></rect><rect x="14" y="14" width="7" height="7" rx="1.5"></rect></svg>
          Sign in with IEMA Portal
        </a>
        <div class="sso-or"><span><?php echo $localOnlyIT ? 'IT emergency sign-in' : 'or use your CRMops password'; ?></span></div>
      <?php endif; ?>

      <form method="POST" action="login.php<?php echo $localOnlyIT ? '?local=1' : ''; ?>" novalidate>
        <div class="form-field">
          <label for="email">Email address</label>
          <input type="email" id="email" name="email" autocomplete="username" placeholder="you@iemacert.com" value="<?php echo e($_POST['email'] ?? ''); ?>" required>
        </div>

        <div class="form-field">
          <div class="label-row">
            <label for="password">Password</label>
            <a href="forgot-password.php" class="forgot">Forgot password?</a>
          </div>
          <div class="pw-wrap">
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="button" class="icon-btn" id="togglePassword" aria-label="Show password" aria-pressed="false">
              <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path><circle cx="12" cy="12" r="3"></circle>
              </svg>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-primary btn-signin">Sign in</button>
      </form>

      <div class="access-wrap">
        <div class="access-note">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path><circle cx="10" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          <p>New to CRMops? Accounts are created by your <b>IT Administrator</b> or <b>Management</b> — reach out to them for access.</p>
        </div>
      </div>
    </section>

    <!-- Navy hero -->
    <section class="tile tile--navy a-hero">
      <div class="hero-top">
        <img class="hero-logo" src="assets/logo.png" alt="<?php echo e(APP_ORG); ?>">
        <span class="hero-status"><i></i>All systems operational</span>
      </div>
      <div class="hero-copy">
        <div class="hero-kicker">IEMA Standards Limited</div>
        <h1>CRMops <span>Business development &amp; client management</span></h1>
        <p class="hero-desc">Leads, proposals, certified clients and invoicing for IEMA's ISO certification, training and consulting work — in one place.</p>
      </div>
    </section>

    <!-- Small tiles -->
    <section class="tile mini a-t1">
      <div class="tile-icon navy">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15l-3.5 2 1-4-3-2.7 4-.3L12 6l1.5 4 4 .3-3 2.7 1 4z"></path><circle cx="12" cy="12" r="10"></circle></svg>
      </div>
      <div><b>ISO 9001 · 14001 ·<br class="d-br"> 45001 · 27001</b><small>and more schemes, end to end</small></div>
    </section>
    <section class="tile mini a-t2">
      <div class="tile-icon green">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4M8 2v4M3 10h18"></path><path d="M9 16l2 2 4-4"></path></svg>
      </div>
      <div><b>Surveillance &amp; renewals tracked</b><small>No certificate cycle slips through</small></div>
    </section>
    <section class="tile mini a-t3">
      <div class="tile-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
      </div>
      <div><b>Secure sign-in</b><small>Role-based access, set up by IT</small></div>
    </section>

  </div>

  <footer class="auth-foot">
    <span>&copy; <?php echo date('Y'); ?> IEMA Standards Limited. All rights reserved.</span>
    <span>Chevron, Lekki, Lagos</span>
  </footer>
</main>

<script>
  const toggleBtn = document.getElementById('togglePassword');
  const pwdInput = document.getElementById('password');
  const eyeIcon = document.getElementById('eyeIcon');
  const eyeOpen = '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path><circle cx="12" cy="12" r="3"></circle>';
  const eyeClosed = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a20.6 20.6 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 7 11 7a20.6 20.6 0 0 1-3.22 4.39M14.12 14.12a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
  toggleBtn.addEventListener('click', () => {
    const isPassword = pwdInput.type === 'password';
    pwdInput.type = isPassword ? 'text' : 'password';
    eyeIcon.innerHTML = isPassword ? eyeClosed : eyeOpen;
    toggleBtn.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
    toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
  });
</script>
</body>
</html>
