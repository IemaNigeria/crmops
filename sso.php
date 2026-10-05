<?php
/**
 * IEMA Portal connector — receives people from the Portal.
 * The Portal POSTs a signed, single-use pass here; if it checks out the
 * person is signed in to this app and sent to the page they asked for.
 * (Same file in every app — the app-specific part is iema-sso/adapter.php.)
 */
require_once __DIR__ . '/iema-sso/adapter.php';   // loads the app + client.php

iema_sso_bootstrap();
header('Cache-Control: no-store');

if (!iema_sso_enabled()) {
    // The connection key hasn't been pasted into iema-sso/sso-config.php
    // yet, so this app can't check passes from the Portal. Say so plainly
    // instead of quietly showing the app's own login page.
    http_response_code(503);
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Portal connection not set up</title>
    <style>body{font-family:Inter,system-ui,sans-serif;background:#F2F0EC;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}
    .c{background:#fff;border:1px solid #E8E4DD;border-radius:20px;padding:30px;max-width:460px}
    h1{font-size:18px;color:#131A26;margin:0 0 8px}p{color:#6B7280;font-size:14px;line-height:1.55}code{background:#F4F2EE;padding:2px 6px;border-radius:6px;font-size:13px}
    a{display:inline-block;margin-top:8px;background:#16263D;color:#fff;padding:10px 16px;border-radius:11px;text-decoration:none;font-weight:600;font-size:14px}</style></head>
    <body><div class="c"><h1>This app isn’t connected to IEMA Portal yet</h1>
    <p>An administrator needs to copy this app’s connection key from <b>Portal → People &amp; access → App connections</b> into <code>iema-sso/sso-config.php</code> (the <code>IEMA_SSO_SECRET</code> line).</p>
    <p>Until then, sign in with this app’s own login page.</p>
    <a href="<?php echo htmlspecialchars(iema_sso_adapter_login_page(), ENT_QUOTES, 'UTF-8'); ?>">Go to this app’s login</a></div></body></html><?php
    exit;
}

$token  = (string) ($_POST['iema_sso'] ?? '');
$claims = $token !== '' ? iema_sso_verify($token, 'login') : null;

if (!$claims) {
    $why = iema_sso_last_error();
    if (in_array($why, ['expired', 'replayed', 'missing'], true)) {
        // Usually the Back button replaying an old hand-off: get a fresh one.
        header('Location: ' . iema_sso_login_url('/'));
        exit;
    }
    // A set-up problem (wrong key / wrong app key / server clock): sending the
    // person back to the Portal would just loop, so explain instead.
    error_log('IEMA SSO: hand-off refused (' . $why . ') for app key ' . IEMA_SSO_APP_KEY);
    http_response_code(400);
    $msgs = [
        'wrong_app'     => 'This app’s <code>IEMA_SSO_APP_KEY</code> in <code>iema-sso/sso-config.php</code> doesn’t match the app the Portal is signing you in to. It should be one of auditops, crmops, hrops or finops — the one for this app.',
        'bad_signature' => 'The connection key in <code>iema-sso/sso-config.php</code> (<code>IEMA_SSO_SECRET</code>) doesn’t match this app’s key in the Portal (People &amp; access → App connections). Each app has its own key.',
        'clock'         => 'This server’s clock is out of step with the Portal’s. Ask your host to enable time sync (NTP).',
    ];
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Portal connection problem</title>
    <style>body{font-family:Inter,system-ui,sans-serif;background:#F2F0EC;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}
    .c{background:#fff;border:1px solid #E8E4DD;border-radius:20px;padding:30px;max-width:480px}
    h1{font-size:18px;color:#131A26;margin:0 0 8px}p{color:#6B7280;font-size:14px;line-height:1.55}code{background:#F4F2EE;padding:2px 6px;border-radius:6px;font-size:13px}
    a{display:inline-block;margin-top:8px;background:#16263D;color:#fff;padding:10px 16px;border-radius:11px;text-decoration:none;font-weight:600;font-size:14px}</style></head>
    <body><div class="c"><h1>Couldn’t sign you in from IEMA Portal</h1>
    <p><?php echo $msgs[$why] ?? 'The sign-in pass from the Portal wasn’t valid. Try again from the Portal; if it keeps happening, ask IT to check this app’s Portal connection settings.'; ?></p>
    <p style="font-size:12px">Reference: <?php echo htmlspecialchars((string) $why, ENT_QUOTES, 'UTF-8'); ?></p>
    <a href="<?php echo htmlspecialchars(iema_sso_portal_url(), ENT_QUOTES, 'UTF-8'); ?>">Back to IEMA Portal</a></div></body></html><?php
    exit;
}

$result = iema_sso_adapter_sign_in($claims);
if ($result !== true) {
    http_response_code(403);
    $msg = is_string($result) ? $result : 'You can’t open this app with that account.';
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Access problem</title>
    <style>body{font-family:Inter,system-ui,sans-serif;background:#F2F0EC;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}
    .c{background:#fff;border:1px solid #E8E4DD;border-radius:20px;padding:30px;max-width:420px;text-align:center}
    h1{font-size:18px;color:#131A26;margin:0 0 8px}p{color:#6B7280;font-size:14px;line-height:1.55}
    a{display:inline-block;margin-top:12px;background:#16263D;color:#fff;padding:10px 16px;border-radius:11px;text-decoration:none;font-weight:600;font-size:14px}</style></head>
    <body><div class="c"><h1>Can’t open this app</h1><p><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></p>
    <a href="<?php echo htmlspecialchars(iema_sso_portal_url(), ENT_QUOTES, 'UTF-8'); ?>">Back to IEMA Portal</a></div></body></html><?php
    exit;
}

header('Location: ' . iema_sso_safe_return($_POST['return'] ?? '/', iema_sso_adapter_home()));
exit;
