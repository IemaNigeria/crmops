<?php
/**
 * IEMA Portal connector — CRMops adapter.
 * The only app-specific file: how CRMops finds or creates the person and
 * starts its own session. (AuditOps/HRops/FINops get their own version.)
 */
require_once __DIR__ . '/client.php';

/** Load the app (database + session) without requiring a login. */
function iema_sso_bootstrap(): void
{
    global $pdo;
    require_once dirname(__DIR__) . '/config.php';
}

/** Where to go after sign-in when no specific page was requested. */
function iema_sso_adapter_home(): string { return '/dashboard.php'; }

/** The app's own login page (used when the connector is switched off). */
function iema_sso_adapter_login_page(): string { return '/login.php'; }

/** Email of whoever is signed in to CRMops right now, or null. */
function iema_sso_adapter_current_email(): ?string
{
    return !empty($_SESSION['user_id']) ? ($_SESSION['user_email'] ?? null) : null;
}

/**
 * Sign the person in to CRMops. Returns true, or a message explaining why not.
 */
function iema_sso_adapter_sign_in(array $c)
{
    global $pdo;
    $email = strtolower($c['email']);
    $role  = (string) ($c['role'] ?? '');
    $validRole = array_key_exists($role, ROLES);

    $st = $pdo->prepare('SELECT id, name, email, role, status FROM users WHERE LOWER(email) = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();

    if (!$u) {
        if (!IEMA_SSO_AUTO_CREATE) return 'Your Portal account has access to CRMops, but no CRMops user exists for ' . $email . '. Ask IT to add you.';
        if (!$validRole) return 'The Portal gave you a CRMops role (“' . $role . '”) that CRMops doesn’t recognise. Ask IT to fix your access.';
        // The random password is never used: this person signs in via the Portal.
        $pdo->prepare('INSERT INTO users (name, email, password_hash, role, status) VALUES (?,?,?,?,?)')
            ->execute([mb_substr((string) $c['name'], 0, 150), $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $role, 'active']);
        $st->execute([$email]);
        $u = $st->fetch();
        audit_crm((int) $u['id'], 'create', 'Account created from IEMA Portal access (' . $role . ')');
    }

    if ($u['status'] !== 'active') return 'Your CRMops account is suspended. Contact IT.';

    // The Portal is the source of truth for the role.
    if ($validRole && $role !== $u['role']) {
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $u['id']]);
        audit_crm((int) $u['id'], 'update', 'Role set by IEMA Portal: ' . $u['role'] . ' → ' . $role);
        $u['role'] = $role;
    }

    session_regenerate_id(true);
    $_SESSION['user_id']       = (int) $u['id'];
    $_SESSION['user_name']     = $u['name'];
    $_SESSION['user_email']    = $u['email'];
    $_SESSION['role']          = $u['role'];
    $_SESSION['last_activity'] = time();
    iema_sso_mark_session($c);

    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
    audit_crm((int) $u['id'], 'login', 'Signed in via IEMA Portal');
    return true;
}

/** End the CRMops session. */
function iema_sso_adapter_sign_out(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

/** The Portal changed this person's CRMops role while they were signed in. */
function iema_sso_adapter_apply_role(string $role): void
{
    global $pdo;
    if (!array_key_exists($role, ROLES) || empty($_SESSION['user_id'])) return;
    $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $_SESSION['user_id']]);
    $_SESSION['role'] = $role;
}

function audit_crm(int $userId, string $action, string $details): void
{
    global $pdo;
    try {
        $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)')
            ->execute([$userId, $action, 'user', $userId, $details]);
    } catch (Throwable $e) { /* audit is best-effort */ }
}
