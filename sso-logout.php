<?php
/**
 * IEMA Portal connector — signs this app out when the person signs out of
 * the Portal (or of any other IEMA app). Loaded by the Portal in a hidden
 * frame with a short-lived signed request.
 */
require_once __DIR__ . '/iema-sso/adapter.php';

iema_sso_bootstrap();
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
// The Portal loads this page in a hidden frame. Allow that — and only that —
// even if the app normally forbids framing (X-Frame-Options: DENY).
header_remove('X-Frame-Options');
header('Content-Security-Policy: frame-ancestors ' . rtrim(IEMA_SSO_PORTAL_URL, '/'));

$claims = iema_sso_verify((string) ($_GET['t'] ?? ''), 'logout');
if ($claims) {
    $current = iema_sso_adapter_current_email();
    if ($current !== null && strtolower($current) === strtolower($claims['email'])) {
        iema_sso_adapter_sign_out();
    }
}
echo '<!DOCTYPE html><title>signed out</title>';
