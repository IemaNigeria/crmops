<?php
/**
 * IEMA Portal connector — shared client (identical in every app).
 * -----------------------------------------------------------------------
 * Verifies the signed passes the Portal hands over, stops a pass being
 * used twice, and re-checks every few minutes that the person is still
 * active in the Portal (so a suspension takes effect everywhere).
 *
 * App-specific behaviour lives in adapter.php next to this file.
 * Settings live in sso-config.php.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/sso-config.php';

function iema_sso_enabled(): bool
{
    return defined('IEMA_SSO_SECRET') && strlen(IEMA_SSO_SECRET) >= 32 && IEMA_SSO_SECRET !== 'PASTE_THE_CONNECTION_KEY_FROM_THE_PORTAL_HERE';
}

function iema_sso_b64url_decode(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}
function iema_sso_b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/**
 * Check a pass from the Portal. Returns its claims, or null if it is
 * forged, expired, meant for another app, the wrong kind, or already used.
 */
function iema_sso_verify(string $token, string $expectType): ?array
{
    $GLOBALS['iema_sso_last_error'] = 'missing';
    $fail = function (string $why) { $GLOBALS['iema_sso_last_error'] = $why; return null; };
    if (!iema_sso_enabled()) return $fail('disabled');
    if (substr_count($token, '.') !== 1 || strlen($token) > 4096) return $fail('malformed');
    [$payload, $sig] = explode('.', $token, 2);
    $c = json_decode(iema_sso_b64url_decode($payload), true);
    if (!is_array($c)) return $fail('malformed');
    // Pass meant for a different app (e.g. IEMA_SSO_APP_KEY set wrongly here).
    if (($c['aud'] ?? '') !== IEMA_SSO_APP_KEY) return $fail('wrong_app');
    $expected = iema_sso_b64url(hash_hmac('sha256', $payload, IEMA_SSO_SECRET, true));
    if (!hash_equals($expected, $sig)) return $fail('bad_signature');   // wrong key here, or forged

    $now = time();
    if (($c['typ'] ?? '') !== $expectType) return $fail('malformed');
    if ((int) ($c['exp'] ?? 0) < $now - 5) return $fail('expired');            // small clock allowance
    if ((int) ($c['iat'] ?? 0) > $now + 30) return $fail('clock');
    if (empty($c['jti']) || !preg_match('/^[a-f0-9]{16,64}$/', (string) $c['jti'])) return $fail('malformed');
    if (!iema_sso_claim_jti((string) $c['jti'])) return $fail('replayed');
    if (empty($c['email']) || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) return $fail('malformed');
    $GLOBALS['iema_sso_last_error'] = null;
    return $c;
}

/** Why the last iema_sso_verify() failed (null if it succeeded). */
function iema_sso_last_error(): ?string { return $GLOBALS['iema_sso_last_error'] ?? null; }

/** Record a pass ID; false if it has been seen before. */
function iema_sso_claim_jti(string $jti): bool
{
    $dir = __DIR__ . '/.used';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); @file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
    // Tidy up passes older than 10 minutes (they have expired anyway).
    if (random_int(1, 20) === 1) {
        foreach ((array) glob($dir . '/*.j') as $f) if (@filemtime($f) < time() - 600) @unlink($f);
    }
    $fh = @fopen($dir . '/' . $jti . '.j', 'x');   // 'x' fails if it already exists
    if ($fh === false) return false;
    fclose($fh);
    return true;
}

function iema_sso_safe_return(?string $p, string $fallback): string
{
    $p = (string) $p;
    return (preg_match('#^/(?!/)[^\s\\\\]*$#', $p) && strlen($p) < 500 && $p !== '/') ? $p : $fallback;
}

function iema_sso_login_url(string $return = '/'): string
{
    return rtrim(IEMA_SSO_PORTAL_URL, '/') . '/login.php?app=' . urlencode(IEMA_SSO_APP_KEY) . '&return=' . urlencode($return);
}
function iema_sso_logout_url(): string
{
    return rtrim(IEMA_SSO_PORTAL_URL, '/') . '/logout.php?from=' . urlencode(IEMA_SSO_APP_KEY);
}
function iema_sso_portal_url(): string { return rtrim(IEMA_SSO_PORTAL_URL, '/') . '/index.php'; }

/** Remember in the app's session that this person came through the Portal. */
function iema_sso_mark_session(array $claims): void
{
    $_SESSION['iema_sso'] = [
        'email'   => strtolower($claims['email']),
        'role'    => (string) ($claims['role'] ?? ''),
        'apps'    => iema_sso_clean_apps($claims['apps'] ?? []),
        'checked' => time(),
    ];
}

/** Keep only well-formed app entries (key => display name). */
function iema_sso_clean_apps($apps): array
{
    $out = [];
    if (!is_array($apps)) return $out;
    foreach ($apps as $k => $n) {
        if (is_string($k) && preg_match('/^[a-z0-9_]{1,20}$/', $k) && is_string($n)) $out[$k] = mb_substr($n, 0, 40);
    }
    return $out;
}

// ── Switching between apps ───────────────────────────────────────────────

/** The person's other IEMA apps (key => name), if they came via the Portal. */
function iema_sso_other_apps(): array
{
    if (!iema_sso_enabled()) return [];
    $apps = $_SESSION['iema_sso']['apps'] ?? [];
    unset($apps[IEMA_SSO_APP_KEY]);
    return is_array($apps) ? $apps : [];
}

/**
 * One click into another app. The Portal is still signed in, so it hands
 * the person straight over — no password (it asks only if the Portal
 * session has timed out).
 */
function iema_sso_switch_url(string $app): string
{
    return rtrim(IEMA_SSO_PORTAL_URL, '/') . '/login.php?app=' . urlencode($app);
}

/**
 * A small floating "IEMA apps" switcher, for apps without a shared menu
 * to put the links in. Empty when the person has no other apps.
 */
function iema_sso_switcher_html(): string
{
    $others = iema_sso_other_apps();
    if (!$others) return '';
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $grid = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>';
    $items = '';
    foreach ($others as $k => $n) {
        $items .= '<a role="menuitem" href="' . $h(iema_sso_switch_url($k)) . '"><span class="iema-sw-dot">' . $h(mb_substr($n, 0, 1)) . '</span><span>Open ' . $h($n) . '</span></a>';
    }
    $items .= '<div class="iema-sw-sep"></div><a role="menuitem" href="' . $h(iema_sso_portal_url()) . '"><span class="iema-sw-dot iema-sw-home">' . $grid . '</span><span>IEMA Portal home</span></a>';
    return <<<HTML
<div class="iema-sw" id="iemaSwitcher">
<style>
.iema-sw{position:fixed;left:16px;bottom:16px;z-index:2147483000;font:500 14px/1.3 Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
.iema-sw-btn{display:flex;align-items:center;gap:8px;background:#16263D;color:#fff;border:0;border-radius:999px;padding:9px 14px;cursor:pointer;box-shadow:0 6px 20px rgba(19,26,38,.25);font:inherit}
.iema-sw-btn:hover{background:#1E3352}.iema-sw-btn:focus-visible{outline:2px solid #C8102E;outline-offset:2px}
.iema-sw-menu{position:absolute;left:0;bottom:calc(100% + 8px);min-width:230px;background:#fff;border:1px solid #E8E4DD;border-radius:14px;padding:6px;box-shadow:0 16px 40px rgba(19,26,38,.18)}
.iema-sw-menu[hidden]{display:none}
.iema-sw-cap{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#6B7280;padding:8px 10px 4px}
.iema-sw-menu a{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;color:#131A26;text-decoration:none}
.iema-sw-menu a:hover,.iema-sw-menu a:focus-visible{background:#F4F2EE;outline:none}
.iema-sw-dot{width:26px;height:26px;border-radius:8px;background:#16263D;color:#fff;display:grid;place-items:center;font-weight:700;font-size:13px;flex:none}
.iema-sw-home{background:#F4F2EE;color:#16263D}
.iema-sw-sep{height:1px;background:#E8E4DD;margin:4px 6px}
@media print{.iema-sw{display:none}}
</style>
<div class="iema-sw-menu" id="iemaSwMenu" role="menu" hidden><div class="iema-sw-cap">Switch app</div>{$items}</div>
<button type="button" class="iema-sw-btn" aria-haspopup="menu" aria-expanded="false" aria-controls="iemaSwMenu">{$grid}<span>IEMA apps</span></button>
<script>(function(){var w=document.getElementById('iemaSwitcher'),b=w.querySelector('.iema-sw-btn'),m=w.querySelector('.iema-sw-menu');
function set(o){m.hidden=!o;b.setAttribute('aria-expanded',o?'true':'false');if(o){var f=m.querySelector('a');f&&f.focus();}}
b.addEventListener('click',function(e){e.stopPropagation();set(m.hidden);});
document.addEventListener('click',function(e){if(!w.contains(e.target))set(false);});
document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!m.hidden){set(false);b.focus();}});})();</script>
</div>
HTML;
}

/**
 * Add the switcher to every HTML page this request outputs (just before
 * </body>). Downloads, JSON and partial responses are left untouched.
 */
function iema_sso_inject_switcher(): void
{
    if (!iema_sso_other_apps()) return;
    ob_start(function (string $buf): string {
        foreach (headers_list() as $hd) {
            if (stripos($hd, 'Content-Type:') === 0 && stripos($hd, 'text/html') === false) return $buf;
            if (stripos($hd, 'Content-Disposition:') === 0) return $buf;
        }
        $pos = strripos($buf, '</body>');
        if ($pos === false || strpos($buf, 'id="iemaSwitcher"') !== false) return $buf;
        return substr($buf, 0, $pos) . iema_sso_switcher_html() . substr($buf, $pos);
    });
}

/**
 * Ask the Portal whether this person is still active with access to this
 * app. Returns ['active' => bool, 'role' => ?string], or null if the
 * Portal couldn't be reached (the app then carries on — fail-open, so a
 * Portal outage doesn't throw everyone out mid-task).
 */
function iema_sso_remote_status(string $email): ?array
{
    $ts  = time();
    $sig = iema_sso_b64url(hash_hmac('sha256', 'status|' . IEMA_SSO_APP_KEY . '|' . strtolower($email) . '|' . $ts, IEMA_SSO_SECRET, true));
    $url = rtrim(IEMA_SSO_PORTAL_URL, '/') . '/api/status.php?' . http_build_query(['app' => IEMA_SSO_APP_KEY, 'email' => strtolower($email), 'ts' => $ts, 'sig' => $sig]);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 4]]));
        $code = $body === false ? 0 : 200;
    }
    if ($code !== 200 || !$body) {
        error_log('IEMA SSO: Portal status check failed (HTTP ' . $code . ')');
        return null;
    }
    $j = json_decode($body, true);
    return is_array($j) && !empty($j['ok']) ? ['active' => !empty($j['active']), 'role' => $j['role'] ?? null, 'apps' => $j['apps'] ?? null] : null;
}

/**
 * Call on every page of a signed-in session (the connector's header hook
 * does this). Every IEMA_SSO_RECHECK_SECONDS it confirms with the Portal.
 */
function iema_sso_page_check(): void
{
    if (!iema_sso_enabled() || empty($_SESSION['iema_sso']['email'])) return;
    if (time() - (int) ($_SESSION['iema_sso']['checked'] ?? 0) < IEMA_SSO_RECHECK_SECONDS) return;

    $_SESSION['iema_sso']['checked'] = time();
    $st = iema_sso_remote_status($_SESSION['iema_sso']['email']);
    if ($st === null) return;
    if (!$st['active']) {
        iema_sso_adapter_sign_out();
        header('Location: ' . iema_sso_login_url($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
    if (is_array($st['apps'])) $_SESSION['iema_sso']['apps'] = iema_sso_clean_apps($st['apps']);
    if ($st['role'] && $st['role'] !== ($_SESSION['iema_sso']['role'] ?? '')) {
        $_SESSION['iema_sso']['role'] = $st['role'];
        iema_sso_adapter_apply_role($st['role']);
    }
}
