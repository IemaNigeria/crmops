<?php
/**
 * IEMA CRMOps — Application Configuration
 * -----------------------------------------------------------------------
 * Central config: database connection, session bootstrap, app constants,
 * and role definitions. Included at the top of every protected page.
 *
 * SECURITY NOTE:
 * Keep this file outside the public webroot if possible, or block direct
 * HTTP access to it via your webserver config (.htaccess / nginx rule).
 * Never commit real credentials to version control — move DB_* values
 * into environment variables (getenv()) once you set up a proper
 * deployment pipeline. They are written as constants here only because
 * that's the simplest starting point for a single-server PHP app.
 * -----------------------------------------------------------------------
 */

// ---------------------------------------------------------------------
// Error reporting — tighten this in production (log to file, don't display)
// ---------------------------------------------------------------------
define('APP_ENV', 'development'); // 'development' | 'production'

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/logs/php-error.log');
}

// ---------------------------------------------------------------------
// Session bootstrap
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE && !defined('RUNNING_AS_CRON')) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    // Enable this once the site is served over HTTPS:
    // ini_set('session.cookie_secure', '1');
    session_start();
}

define('SESSION_TIMEOUT_SECONDS', 1800); // 30 minutes of inactivity

// ---------------------------------------------------------------------
// Database connection
// ---------------------------------------------------------------------
define('DB_HOST', 'CHANGE_ME');
define('DB_PORT', 'CHANGE_ME');
define('DB_NAME', 'CHANGE_ME');
define('DB_USER', 'CHANGE_ME');
define('DB_PASS', 'CHANGE_ME');
define('DB_CHARSET', 'utf8mb4');

/** @var PDO $pdo Global PDO connection, available to every included page */
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $pdo = new PDO(DB_HOST !== '' ? $dsn : '', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    if (APP_ENV === 'development') {
        die('Database connection failed: ' . $e->getMessage());
    }
    error_log('DB connection failed: ' . $e->getMessage());
    die('We could not connect to CRMOps right now. Please try again shortly, or contact IT Support.');
}

// ---------------------------------------------------------------------
// Application constants
// ---------------------------------------------------------------------
define('APP_NAME', 'IEMA CRMOps');
define('APP_ORG', 'IEMA Standards Limited');

// Used in email signatures (logo image + website link). ORG_WEBSITE_BASE_URL
// must be a full https:// URL — the signature logo is loaded from
// {ORG_WEBSITE_BASE_URL}/assets/logo.png, i.e. this app's own hosted logo.
// Change ORG_WEBSITE_DISPLAY to your actual public website if different
// from this app's own domain.
define('ORG_WEBSITE_BASE_URL', 'https://crmops.iemacert.com');
define('ORG_WEBSITE_DISPLAY', 'www.iemacert.com');

// ---------------------------------------------------------------------
// Invoice billing details
// -----------------------------------------------------------------------
// NOTE: the sample invoice you shared shows the billing entity as
// "Institute For Enterprise Management and Analytics" at www.iiema.org —
// different from APP_ORG ("IEMA Standards Limited") and ORG_WEBSITE_DISPLAY
// used elsewhere in the app. I've matched the invoice header to your
// actual sample exactly, but flagging this in case that's not intentional
// — if invoices should say "IEMA Standards Limited" instead, change
// BILLING_ORG_NAME below.
// -----------------------------------------------------------------------
define('BILLING_ORG_NAME', 'IEMA Standards Limited');
define('BILLING_ORG_ADDRESS', '27A Alternative Drive, Off Chevron Drive, Lekki Phase 2, Lagos, Lagos, Nigeria');
define('BILLING_ORG_PHONE', '+234-7034600322');
define('BILLING_ORG_WEBSITE', 'www.iemacert.com');
define('BILLING_PAYMENT_ACCOUNT', 'CHANGE_ME');
define('BILLING_DEFAULT_VAT_RATE', 7.50);
define('APP_BASE_URL', '/'); // adjust if the app lives in a subfolder

// ---------------------------------------------------------------------
// AuditOps sync
// -----------------------------------------------------------------------
// CRMOps and AuditOps run as separate applications but share the same
// MySQL server. Rather than an HTTP API, we sync clients directly via a
// second database connection — simpler and no extra moving parts.
//
// This requires the CRMOps database user to have SELECT/INSERT/UPDATE
// on the AuditOps `clients` table specifically. Run this once, as root,
// in phpMyAdmin (adjust the DB user name if yours differs from
// iemacert_crm):
//
//   GRANT SELECT, INSERT, UPDATE ON iemacert_audit.clients TO 'iemacert_crm'@'localhost';
//   FLUSH PRIVILEGES;
//
// If that grant hasn't been run yet, sync attempts fail silently (logged,
// not shown to the user) and never block the CRM action itself — creating
// or editing a client in CRMOps always succeeds regardless of whether
// AuditOps sync worked.
// -----------------------------------------------------------------------
define('AUDITOPS_DB_NAME', 'CHANGE_ME');

/** @var PDO|null Lazily-created connection to the AuditOps database. */
$GLOBALS['auditops_pdo'] = null;

function get_auditops_pdo(): ?PDO
{
    if ($GLOBALS['auditops_pdo'] !== null) {
        return $GLOBALS['auditops_pdo'] ?: null;
    }
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, AUDITOPS_DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $GLOBALS['auditops_pdo'] = $pdo;
        return $pdo;
    } catch (PDOException $e) {
        error_log('AuditOps sync: could not connect — ' . $e->getMessage());
        $GLOBALS['auditops_pdo'] = false;
        return null;
    }
}

/**
 * Push a CRMOps client record into AuditOps' clients table.
 *
 * First sync: inserts a new AuditOps row and stores its id back on the
 * CRMOps client (auditops_sync_id) so future syncs UPDATE that exact row
 * instead of re-matching by name — avoids creating duplicates on repeat
 * syncs (AuditOps' own table already has a few from unrelated double-
 * submits, so name-matching alone isn't reliable there).
 *
 * Never throws — sync failures are logged server-side only, and the
 * calling CRM action (create/update/convert) always succeeds regardless.
 */
function sync_client_to_auditops(PDO $crmPdo, array $client): void
{
    $auditPdo = get_auditops_pdo();
    if (!$auditPdo) {
        log_sync_event($crmPdo, 'client_push', 'client', $client['id'] ?? null, false, 'Could not connect to AuditOps database — check the grant is set up (see notes above).');
        return;
    }

    try {
        $name          = $client['company_name'];
        $sector        = $client['sector'] ?? '';
        $contactPerson = $client['primary_contact_name'] ?? '';
        $contactEmail  = $client['primary_contact_email'] ?? '';
        $contactPhone  = $client['primary_contact_phone'] ?? '';
        $isActive      = ($client['status'] ?? 'active') !== 'inactive' ? 1 : 0;

        if (!empty($client['auditops_sync_id'])) {
            $stmt = $auditPdo->prepare('UPDATE clients SET name=?, sector=?, contact_person=?, contact_email=?, contact_phone=?, is_active=? WHERE id=?');
            $stmt->execute([$name, $sector, $contactPerson, $contactEmail, $contactPhone, $isActive, $client['auditops_sync_id']]);

            if ($stmt->rowCount() === 0) {
                // The linked AuditOps row no longer exists (deleted there) — recreate it.
                $client['auditops_sync_id'] = null;
            }
        }

        if (empty($client['auditops_sync_id'])) {
            $insertStmt = $auditPdo->prepare('INSERT INTO clients (name, sector, contact_person, contact_email, contact_phone, is_active) VALUES (?,?,?,?,?,?)');
            $insertStmt->execute([$name, $sector, $contactPerson, $contactEmail, $contactPhone, $isActive]);
            $newAuditId = $auditPdo->lastInsertId();

            $updateSyncId = $crmPdo->prepare('UPDATE clients SET auditops_sync_id = ? WHERE id = ?');
            $updateSyncId->execute([$newAuditId, $client['id']]);

            log_sync_event($crmPdo, 'client_push', 'client', $client['id'] ?? null, true, "Created AuditOps client #$newAuditId for \"$name\"");
        } else {
            log_sync_event($crmPdo, 'client_push', 'client', $client['id'] ?? null, true, "Updated AuditOps client #{$client['auditops_sync_id']} for \"$name\"");
        }
    } catch (Exception $e) {
        error_log('AuditOps sync failed for client #' . ($client['id'] ?? '?') . ': ' . $e->getMessage());
        log_sync_event($crmPdo, 'client_push', 'client', $client['id'] ?? null, false, $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// CertAdmin (certadmin.iiema.org) — read-only certificate/expiry lookup
// -----------------------------------------------------------------------
// CertAdmin integration — via HTTPS API (not direct DB connection)
// -----------------------------------------------------------------------
// CertAdmin is on a different cPanel account (iiema.org vs iemacert.com)
// so a direct MySQL connection doesn't work across account boundaries even
// on the same shared server. Instead, CRMops calls a small PHP endpoint
// on certadmin.iiema.org which uses its own local DB connection and
// returns certificate data as JSON.
//
// Setup:
//   1. Upload certadmin-api.php to certadmin.iiema.org (root folder or
//      any subfolder — just update CERTADMIN_API_URL below to match)
//   2. The API key below must match the API_KEY constant in that file
//   3. That's it — no database grants needed
//
// Fails silently if the endpoint is unreachable — never blocks any other
// page or action in CRMops.
// -----------------------------------------------------------------------
define('CERTADMIN_API_URL', 'CHANGE_ME');
define('CERTADMIN_API_KEY', 'CHANGE_ME');

/**
 * Look up certificates for a client by company name, via the CertAdmin
 * API endpoint. Returns an empty array on any failure — never throws.
 */
function get_certadmin_certificates(string $companyName): array
{
    if (trim($companyName) === '') return [];

    $url = CERTADMIN_API_URL . '?' . http_build_query(['company' => $companyName]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . CERTADMIN_API_KEY],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log('CertAdmin API call failed for "' . $companyName . '": HTTP ' . $httpCode . ' ' . $curlError);
        return [];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['ok']) || !isset($data['certificates'])) {
        error_log('CertAdmin API returned unexpected response for "' . $companyName . '": ' . substr($response, 0, 200));
        return [];
    }

    return $data['certificates'];
}

/**
 * Fetch aggregate certificate stats for a list of company names.
 * Makes ONE API call regardless of how many companies are passed —
 * used by accounts.php to populate summary stat cards efficiently.
 * Pass an empty array to get stats across all certificates.
 */
function get_certadmin_stats(array $companies = []): ?array
{
    $url = CERTADMIN_API_URL . '?action=stats';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['companies' => array_values($companies)]),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . CERTADMIN_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log('CertAdmin stats API failed: HTTP ' . $httpCode);
        return null;
    }
    $data = json_decode($response, true);
    return (is_array($data) && !empty($data['ok'])) ? $data['stats'] : null;
}

// ---------------------------------------------------------------------
// Surveillance audits — mirrors the exact formula CertAdmin's own
// dashboard uses (dashboard.php on certadmin.iiema.org): certificates
// run a 3-year cycle from issue_date; the year-1 and year-2 anniversaries
// are surveillance checkpoints (not renewals). A checkpoint is "due" for
// SURVEY_GRACE_DAYS after the anniversary, then "overdue" until the next
// checkpoint (or expiry) arrives. Computed locally from issue_date/status
// already returned by get_certadmin_certificates() — no CertAdmin API
// change needed for this part.
// -----------------------------------------------------------------------
define('SURVEY_GRACE_DAYS', 14);

/**
 * Returns null (no surveillance action needed), or an array describing
 * the current checkpoint: ['state' => 'due'|'overdue', 'year' => 1|2,
 * 'anniversary' => 'M d, Y', 'days' => n].
 */
function certadmin_surveillance_status(?string $issueDate, ?string $status): ?array
{
    if (strtolower((string) $status) !== 'active' || empty($issueDate)) return null;
    $issueTs = strtotime($issueDate);
    if (!$issueTs) return null;

    $todayTs  = strtotime(date('Y-m-d'));
    $expireTs = strtotime('+3 years', $issueTs);
    if ($todayTs >= $expireTs) return null; // fully out of its certification cycle

    $anniversaries = [1 => strtotime('+1 year', $issueTs), 2 => strtotime('+2 years', $issueTs)];
    $result = null;
    foreach ($anniversaries as $year => $annivTs) {
        $daysSince = floor(($todayTs - $annivTs) / 86400);
        if ($daysSince < 0) break; // haven't reached this checkpoint yet — keep prior result
        if ($daysSince <= SURVEY_GRACE_DAYS) {
            $result = ['state' => 'due', 'year' => $year, 'anniversary' => date('M d, Y', $annivTs), 'days' => (int) $daysSince];
        } else {
            $result = ['state' => 'overdue', 'year' => $year, 'anniversary' => date('M d, Y', $annivTs), 'days' => (int) $daysSince - SURVEY_GRACE_DAYS];
        }
    }
    return $result;
}

/**
 * Plain-text snapshot of a company's currently due/overdue surveillance
 * checkpoints, pulled live from CertAdmin. Used by surveillance-tasks.php
 * to show what a task actually covers — both as a live preview when
 * assigning, and as a permanent snapshot stored on the task itself so the
 * assignee has full context even though they may not have access to
 * accounts.php or the CertAdmin lookup endpoints directly (an assignee
 * can be any active user, not just an Account Manager).
 */
function certadmin_surveillance_summary(string $companyName): string
{
    $certs = get_certadmin_certificates($companyName);
    $lines = [];
    foreach ($certs as $c) {
        $sv = certadmin_surveillance_status($c['issue_date'] ?? null, $c['status'] ?? null);
        if (!$sv) continue;
        $lines[] = ($c['certification_type'] ?? 'Certificate') . ' (Cert #' . ($c['certificate_number'] ?? 'N/A') . ') — Year ' . $sv['year'] . ' surveillance '
            . ($sv['state'] === 'overdue' ? 'OVERDUE by ' . $sv['days'] . ' day(s) past grace' : 'due — ' . $sv['days'] . ' day(s) into the checkpoint window')
            . ' (anniversary ' . $sv['anniversary'] . ')';
    }
    return $lines ? implode("\n", $lines) : 'No certificates currently due for surveillance were found in CertAdmin at the time of assignment.';
}

/** Record a sync attempt (success or failure) so it's visible in-app, not just in server logs. */
function log_sync_event(PDO $crmPdo, string $syncType, string $entityType, ?int $entityId, bool $success, string $details): void
{
    try {
        $stmt = $crmPdo->prepare('INSERT INTO sync_log (sync_type, entity_type, entity_id, status, details) VALUES (?,?,?,?,?)');
        $stmt->execute([$syncType, $entityType, $entityId, $success ? 'success' : 'failed', substr($details, 0, 255)]);
    } catch (Exception $e) {
        error_log('Could not write sync_log entry: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Scheduled AI drafts — cron access
// -----------------------------------------------------------------------
// cron/generate-followup-drafts.php can be triggered two ways:
//   1. CLI cron (preferred, if your host gives you shell access):
//        php /full/path/to/cron/generate-followup-drafts.php
//   2. URL-based cron (common on shared hosting / cPanel "Cron Jobs" that
//      only offer curl/wget): the script also accepts a GET request with
//      a secret token so randos on the internet can't trigger it:
//        curl "https://yourdomain.com/cron/generate-followup-drafts.php?token=YOUR_SECRET"
// Change this to your own random string before relying on option 2.
// -----------------------------------------------------------------------
define('CRON_SECRET', 'CHANGE_ME');

/**
 * Attempt to send an email via the server's configured mail transport
 * (PHP's mail(), which relies on sendmail/postfix being set up on the
 * host — this is NOT reliable on all hosts, especially shared hosting
 * without a properly configured MTA, and mail sent this way is more
 * likely to land in spam without SPF/DKIM set up for your domain).
 *
 * Returns true on success. Callers MUST NOT assume true means the email
 * was actually delivered — only that the server accepted it for sending.
 * The UI always offers a mailto: fallback so approval never dead-ends
 * even if this fails.
 */
function attempt_send_email(string $to, string $subject, string $body, string $fromName, string $fromEmail): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $headers = "From: {$fromName} <{$fromEmail}>\r\nReply-To: {$fromEmail}\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($to, $subject, $body, $headers);
}

// ---------------------------------------------------------------------
// In-app Mail (per-user IMAP inbox + authenticated SMTP send)
// -----------------------------------------------------------------------
// Each user connects their own existing cPanel mailbox via mail-settings.php.
// Credentials are encrypted at rest — never stored or logged in plain text.
//
// Change this to a long random string before relying on it in production;
// if it ever changes, every already-saved mailbox password becomes
// undecryptable and users will need to re-enter it.
// -----------------------------------------------------------------------
define('MAIL_ENCRYPTION_KEY', 'CHANGE_ME');

function encrypt_secret(string $plaintext): string
{
    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', hash('sha256', MAIL_ENCRYPTION_KEY, true), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $ciphertext);
}

function decrypt_secret(?string $encoded): ?string
{
    if (!$encoded) return null;
    $raw = base64_decode($encoded);
    if ($raw === false || strlen($raw) < 17) return null;
    $iv         = substr($raw, 0, 16);
    $ciphertext = substr($raw, 16);
    $plain      = openssl_decrypt($ciphertext, 'AES-256-CBC', hash('sha256', MAIL_ENCRYPTION_KEY, true), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? null : $plain;
}

/** True if this server's PHP has the imap extension enabled. */
function imap_extension_available(): bool
{
    return function_exists('imap_open');
}

/**
 * Open an IMAP connection for a user's configured mailbox.
 * Returns ['ok' => bool, 'mbox' => resource|null, 'error' => string].
 */
function open_user_mailbox(array $user): array
{
    if (!imap_extension_available()) {
        return ['ok' => false, 'mbox' => null, 'error' => "This server's PHP install doesn't have the IMAP extension enabled. Ask your hosting provider to enable php-imap."];
    }
    if (empty($user['mail_email']) || empty($user['mail_password_enc']) || empty($user['mail_imap_host'])) {
        return ['ok' => false, 'mbox' => null, 'error' => "Mail isn't configured yet. Set it up on the Mail Settings page."];
    }
    $password = decrypt_secret($user['mail_password_enc']);
    if ($password === null) {
        return ['ok' => false, 'mbox' => null, 'error' => 'Stored mailbox password could not be decrypted. Please re-enter it in Mail Settings.'];
    }

    $port    = (int) ($user['mail_imap_port'] ?: 993);
    $mailbox = '{' . $user['mail_imap_host'] . ':' . $port . '/imap/ssl' . (!empty($user['mail_skip_cert_check']) ? '/novalidate-cert' : '') . '}INBOX';

    imap_timeout(IMAP_OPENTIMEOUT, 10);
    imap_timeout(IMAP_READTIMEOUT, 15);

    $errorHandler = set_error_handler(function () { return true; });
    $mbox = @imap_open($mailbox, $user['mail_email'], $password, 0, 1);
    set_error_handler($errorHandler);

    if (!$mbox) {
        $err = imap_last_error() ?: 'Unknown IMAP connection error.';
        return ['ok' => false, 'mbox' => null, 'error' => "Could not connect: $err"];
    }
    return ['ok' => true, 'mbox' => $mbox, 'error' => ''];
}

// PHPMailer — standalone install expected at PHPMailer/src/ next to config.php.
$phpMailerAvailable = false;
$phpMailerPath      = __DIR__ . '/PHPMailer/src/';
if (
    file_exists($phpMailerPath . 'Exception.php') &&
    file_exists($phpMailerPath . 'PHPMailer.php') &&
    file_exists($phpMailerPath . 'SMTP.php')
) {
    require_once $phpMailerPath . 'Exception.php';
    require_once $phpMailerPath . 'PHPMailer.php';
    require_once $phpMailerPath . 'SMTP.php';
    $phpMailerAvailable = true;
}

/**
 * Send via authenticated SMTP using PHPMailer.
 *
 * Parameters
 * ----------
 * $host, $port, $username, $password  — SMTP connection details
 * $fromEmail, $fromName               — envelope From
 * $to                                 — primary recipient (single address)
 * $subject                            — message subject
 * $htmlBody                           — HTML part (may contain inline CSS / img tags)
 * $plainBody                          — plain-text fallback
 * $inReplyTo                          — Message-ID to thread against (optional)
 * $skipCertCheck                      — relax SSL hostname verification for shared-
 *                                       hosting mail servers whose cert covers the
 *                                       host's own shared hostname
 * $attachments                        — array of ['path' => tmp path, 'name' => filename]
 * $cc                                 — comma-separated Cc addresses (optional)
 * $bcc                                — comma-separated Bcc addresses (optional)
 *
 * Returns ['ok' => bool, 'error' => string, 'raw_mime' => string]
 * raw_mime is the exact sent message so the caller can APPEND an identical
 * copy to the Sent folder via imap_append_to_sent().
 */
function smtp_send_mail(
    string  $host,
    int     $port,
    string  $username,
    string  $password,
    string  $fromEmail,
    string  $fromName,
    string  $to,
    string  $subject,
    string  $htmlBody,
    string  $plainBody,
    ?string $inReplyTo    = null,
    bool    $skipCertCheck = false,
    array   $attachments  = [],
    string  $cc           = '',
    string  $bcc          = ''
): array {
    global $phpMailerAvailable;
    if (!$phpMailerAvailable) {
        return ['ok' => false, 'error' => 'PHPMailer is not installed at PHPMailer/src/ next to config.php.', 'raw_mime' => ''];
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        // ── Transport ────────────────────────────────────────────────────
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        $mail->SMTPSecure = ($port === 465)
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout    = 15;

        if ($skipCertCheck) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        // ── Addresses ────────────────────────────────────────────────────
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        if ($cc !== '') {
            foreach (array_filter(array_map('trim', explode(',', $cc))) as $addr) {
                if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $mail->addCC($addr);
                }
            }
        }

        if ($bcc !== '') {
            foreach (array_filter(array_map('trim', explode(',', $bcc))) as $addr) {
                if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $mail->addBCC($addr);
                }
            }
        }

        // ── Content ──────────────────────────────────────────────────────
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody;

        // ── Attachments — must be added BEFORE preSend() ─────────────────
        foreach ($attachments as $att) {
            $path = $att['path'] ?? '';
            $name = $att['name'] ?? basename($path);
            if ($path !== '' && is_readable($path)) {
                $mail->addAttachment($path, $name);
            } else {
                error_log('smtp_send_mail: skipping unreadable attachment "' . $name . '" at "' . $path . '"');
            }
        }

        // ── Threading ────────────────────────────────────────────────────
        if ($inReplyTo) {
            $mail->addCustomHeader('In-Reply-To', $inReplyTo);
            $mail->addCustomHeader('References',  $inReplyTo);
        }

        // ── Send — split into preSend/postSend so we can capture raw MIME
        //    (getSentMIMEMessage() must be called between the two).
        $mail->preSend();
        $rawMime = $mail->getSentMIMEMessage();
        $mail->postSend();

        return ['ok' => true, 'error' => '', 'raw_mime' => $rawMime];

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        return ['ok' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage(), 'raw_mime' => ''];
    }
}

/**
 * Save a copy of a just-sent message into the user's Sent folder via IMAP
 * APPEND — SMTP submission never does this automatically, so without this
 * step sent mail would never appear anywhere in the mailbox.
 */
function imap_append_to_sent(array $user, string $rawMime): bool
{
    if (!imap_extension_available() || empty($rawMime)) return false;

    $sentFolderNames = ['INBOX.Sent', 'Sent', 'Sent Items', 'INBOX.Sent Items'];
    $port     = (int) ($user['mail_imap_port'] ?: 993);
    $certFlag = !empty($user['mail_skip_cert_check']) ? '/novalidate-cert' : '';
    $imapRef  = '{' . $user['mail_imap_host'] . ':' . $port . '/imap/ssl' . $certFlag . '}';
    $password = decrypt_secret($user['mail_password_enc']);
    if ($password === null) return false;

    imap_timeout(IMAP_OPENTIMEOUT, 10);
    $errorHandler = set_error_handler(function () { return true; });
    $mbox = @imap_open($imapRef . 'INBOX', $user['mail_email'], $password, 0, 1);
    set_error_handler($errorHandler);
    if (!$mbox) return false;

    $rawFolders = @imap_list($mbox, $imapRef, '*') ?: [];
    $sentFolder = null;
    foreach ($rawFolders as $f) {
        $short = str_replace($imapRef, '', $f);
        if (in_array($short, $sentFolderNames, true) || stripos($short, 'sent') !== false) {
            $sentFolder = $short;
            break;
        }
    }
    if (!$sentFolder) { imap_close($mbox); return false; }

    // Normalise line endings — imap_append requires CRLF.
    $rawMime = str_replace("\r\n", "\n", $rawMime);
    $rawMime = str_replace("\n", "\r\n", $rawMime);

    $result = @imap_append($mbox, $imapRef . $sentFolder, $rawMime, '\\Seen');
    imap_close($mbox);
    return (bool) $result;
}

/**
 * Build the email signature as both HTML (with logo) and plain-text.
 * Falls back gracefully for any field the user hasn't filled in.
 */
function build_signature_html(array $user): string
{
    $name    = e($user['name'] ?? '');
    $title   = e($user['signature_title'] ?? '');
    $email   = e($user['mail_email'] ?? '');
    $phone   = e($user['signature_phone'] ?? '');
    $logoUrl = rtrim(ORG_WEBSITE_BASE_URL, '/') . '/assets/logo.png';

    $lines = "<p style=\"margin:0;font-weight:700;color:#191B20;\">{$name}</p>";
    if ($title) $lines .= "<p style=\"margin:0;color:#3C3F48;\">{$title}</p>";
    $lines .= "<p style=\"margin:6px 0 0;color:#3C3F48;\">" . e(APP_ORG) . "</p>";

    $contactBits = [];
    if ($email) $contactBits[] = "<a href=\"mailto:{$email}\" style=\"color:#C8102E;text-decoration:none;\">{$email}</a>";
    if ($phone) $contactBits[] = $phone;
    if ($contactBits) $lines .= "<p style=\"margin:2px 0 0;color:#3C3F48;\">" . implode(' &nbsp;|&nbsp; ', $contactBits) . "</p>";

    if (defined('ORG_WEBSITE_DISPLAY') && ORG_WEBSITE_DISPLAY) {
        $lines .= "<p style=\"margin:2px 0 0;\"><a href=\"" . e(ORG_WEBSITE_BASE_URL) . "\" style=\"color:#C8102E;text-decoration:none;\">" . e(ORG_WEBSITE_DISPLAY) . "</a></p>";
    }

    return "<table cellpadding=\"0\" cellspacing=\"0\" style=\"margin-top:18px;border-top:2px solid #C8102E;padding-top:12px;font-family:Arial,sans-serif;font-size:13px;\"><tr>"
        . "<td style=\"padding-right:14px;vertical-align:top;\"><img src=\"{$logoUrl}\" alt=\"" . e(APP_ORG) . "\" style=\"height:40px;display:block;\"></td>"
        . "<td style=\"vertical-align:top;\">{$lines}</td>"
        . "</tr></table>";
}

function build_signature_text(array $user): string
{
    $lines = [$user['name'] ?? ''];
    if (!empty($user['signature_title'])) $lines[] = $user['signature_title'];
    $lines[]     = APP_ORG;
    $contactBits = array_filter([$user['mail_email'] ?? '', $user['signature_phone'] ?? '']);
    if ($contactBits) $lines[] = implode(' | ', $contactBits);
    if (defined('ORG_WEBSITE_DISPLAY') && ORG_WEBSITE_DISPLAY) $lines[] = ORG_WEBSITE_DISPLAY;
    return "\n\n--\n" . implode("\n", array_filter($lines));
}

// ---------------------------------------------------------------------
// AI features
// -----------------------------------------------------------------------
define('GROQ_API_KEY', 'CHANGE_ME');
// llama-3.3-70b-versatile was deprecated by Groq for free/developer-tier
// keys on 16 Aug 2026 — switched to their recommended replacement.
// See: https://console.groq.com/docs/deprecations
define('GROQ_MODEL', 'openai/gpt-oss-120b');

/**
 * Call Groq's API to generate text. Returns ['ok' => bool, 'text' => string].
 * Fails closed — any error returns ok=false with a human-readable message.
 */
function ai_generate(string $prompt, int $maxTokens = 500): array
{
    if (GROQ_API_KEY === '') {
        return ['ok' => false, 'text' => "AI drafting isn't configured yet. An IT Administrator needs to set GROQ_API_KEY in config.php (free key at console.groq.com/keys)."];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'text' => "This server's PHP install doesn't have the cURL extension enabled. Ask your hosting provider to enable php-curl, then try again."];
    }
    if (strlen(GROQ_API_KEY) < 50) {
        return ['ok' => false, 'text' => 'The configured GROQ_API_KEY looks too short (' . strlen(GROQ_API_KEY) . ' characters) — it may have been truncated when copied into config.php. Please re-copy the full key from console.groq.com/keys.'];
    }

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'model'      => GROQ_MODEL,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ]),
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'text' => 'Could not reach the AI service (network/firewall issue on this server): ' . $curlError];
    }
    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'text' => 'AI service returned an unreadable response (HTTP ' . $httpCode . '). Raw: ' . substr($response, 0, 200)];
    }
    if ($httpCode !== 200 || !isset($data['choices'][0]['message']['content'])) {
        $msg = $data['error']['message'] ?? "Unexpected response (HTTP $httpCode).";
        return ['ok' => false, 'text' => 'AI drafting failed: ' . $msg];
    }
    return ['ok' => true, 'text' => trim($data['choices'][0]['message']['content'])];
}

/**
 * Render a lead's quote_details JSON (captured on the public "Get a Quote"
 * form — business description, standard requested, budget range,
 * certification stage, timeframe, address, etc.) as plain text for an AI
 * prompt or an email draft. This is what makes AI proposal drafts respond
 * to what the prospect actually asked for, instead of generic boilerplate.
 * Shared by ai-draft.php and proposal-send-draft.php.
 */
function format_quote_details(?string $quoteDetailsJson): string
{
    if (!$quoteDetailsJson) {
        return "No \"Get a Quote\" submission on file for this lead (created manually).\n";
    }
    $fields = json_decode($quoteDetailsJson, true);
    if (!is_array($fields) || empty($fields)) {
        return "No \"Get a Quote\" submission on file for this lead (created manually).\n";
    }
    $lines = '';
    foreach ($fields as $label => $value) {
        if (trim((string) $value) === '') continue;
        $lines .= "- $label: $value\n";
    }
    return $lines !== '' ? $lines : "No \"Get a Quote\" submission on file for this lead (created manually).\n";
}

/**
 * Rule-based lead score (0-100). Transparent, deterministic, no API call.
 */
function compute_lead_score(array $lead, ?string $lastInteractionAt, float $maxDealValue): int
{
    $stageWeights = [
        'New' => 10, 'Contacted' => 25, 'Needs Assessment' => 45,
        'Proposal Sent' => 65, 'Negotiation' => 80, 'Won' => 100, 'Lost' => 0,
    ];
    $stageScore = $stageWeights[$lead['stage']] ?? 10;

    $daysSinceContact = $lastInteractionAt
        ? (time() - strtotime($lastInteractionAt)) / 86400
        : (time() - strtotime($lead['created_at'])) / 86400;
    $recencyScore = $daysSinceContact <= 3 ? 100 : ($daysSinceContact <= 7 ? 75 : ($daysSinceContact <= 14 ? 45 : ($daysSinceContact <= 30 ? 20 : 5)));

    $value      = (float) ($lead['estimated_value_ngn'] ?? 0);
    $valueScore = $maxDealValue > 0 ? min(100, round(($value / $maxDealValue) * 100)) : 50;

    $daysSinceUpdate = (time() - strtotime($lead['updated_at'])) / 86400;
    $momentumPenalty = $daysSinceUpdate > 21 ? 20 : ($daysSinceUpdate > 10 ? 8 : 0);

    $score = ($stageScore * 0.45) + ($recencyScore * 0.30) + ($valueScore * 0.25) - $momentumPenalty;
    return (int) max(0, min(100, round($score)));
}

// ---------------------------------------------------------------------
// Roles
// ---------------------------------------------------------------------
define('ROLES', [
    'bd_officer'      => 'BD Officer',
    'bd_manager'      => 'BD Manager',
    'management'      => 'Management',
    'it_admin'        => 'IT Administrator',
    'account_manager' => 'Client Relationship Manager',
    'auditor'         => 'Auditor',
]);

define('ROLE_NAV', [
    'bd_officer' => [
        ['label' => 'Dashboard',       'icon' => 'grid',      'href' => 'dashboard.php'],
        ['label' => 'Mail',            'icon' => 'send',      'href' => 'mail.php'],
        ['label' => 'My Leads',        'icon' => 'target',    'href' => 'leads.php'],
        ['label' => 'Team Pipeline',   'icon' => 'trend',     'href' => 'pipeline.php'],
        ['label' => 'Lead Assignment', 'icon' => 'target',    'href' => 'assign.php'],
        ['label' => 'Follow-ups',      'icon' => 'clock',     'href' => 'followups.php'],
        ['label' => 'AI Drafts',       'icon' => 'mail',      'href' => 'ai-drafts.php'],
        ['label' => 'Clients',         'icon' => 'users',     'href' => 'clients.php'],
        ['label' => 'Proposals',       'icon' => 'file',      'href' => 'proposals.php'],
        ['label' => 'Invoices',        'icon' => 'briefcase', 'href' => 'invoices.php'],
        ['label' => 'Surveillance Tasks', 'icon' => 'clipboard', 'href' => 'surveillance-tasks.php'],
    ],
    'bd_manager' => [
        ['label' => 'Dashboard',       'icon' => 'grid',      'href' => 'dashboard.php'],
        ['label' => 'Mail',            'icon' => 'send',      'href' => 'mail.php'],
        ['label' => 'My Leads',        'icon' => 'target',    'href' => 'leads.php'],
        ['label' => 'Team Pipeline',   'icon' => 'trend',     'href' => 'pipeline.php'],
        ['label' => 'Lead Assignment', 'icon' => 'target',    'href' => 'assign.php'],
        ['label' => 'Follow-ups',      'icon' => 'clock',     'href' => 'followups.php'],
        ['label' => 'AI Drafts',       'icon' => 'mail',      'href' => 'ai-drafts.php'],
        ['label' => 'Clients',         'icon' => 'users',     'href' => 'clients.php'],
        ['label' => 'Proposals',       'icon' => 'file',      'href' => 'proposals.php'],
        ['label' => 'BD Team',         'icon' => 'users',     'href' => 'team.php'],
        ['label' => 'Invoices',        'icon' => 'briefcase', 'href' => 'invoices.php'],
        ['label' => 'Performance',     'icon' => 'chart',     'href' => 'performance.php'],
        ['label' => 'Surveillance Tasks', 'icon' => 'clipboard', 'href' => 'surveillance-tasks.php'],
    ],
    'management' => [
        ['label' => 'Dashboard',            'icon' => 'grid',      'href' => 'dashboard.php'],
        ['label' => 'Mail',                 'icon' => 'send',      'href' => 'mail.php'],
        ['label' => 'New Leads',            'icon' => 'target',    'href' => 'leads.php?stage=New'],
        ['label' => 'Quotes & Proposals',   'icon' => 'file',      'href' => 'proposals.php'],
        ['label' => 'Invoices',             'icon' => 'briefcase', 'href' => 'invoices.php'],
        ['label' => 'Lead Assignment',      'icon' => 'target',    'href' => 'assign.php'],
        ['label' => 'Pipeline Intelligence','icon' => 'trend',     'href' => 'intelligence.php'],
        ['label' => 'Commercial Reports',   'icon' => 'file',      'href' => 'reports.php'],
        ['label' => 'Renewals & Upsell',    'icon' => 'refresh',   'href' => 'renewals.php'],
        ['label' => 'Surveillance Tasks',   'icon' => 'clipboard', 'href' => 'surveillance-tasks.php'],
        ['label' => 'Escalations',          'icon' => 'alert',     'href' => 'escalations.php'],
        ['label' => 'User Accounts',        'icon' => 'users',     'href' => 'users.php'],
    ],
    'it_admin' => [
        ['label' => 'Dashboard',       'icon' => 'grid',    'href' => 'dashboard.php'],
        ['label' => 'Mail',            'icon' => 'send',    'href' => 'mail.php'],
        ['label' => 'Lead Assignment', 'icon' => 'target',  'href' => 'assign.php'],
        ['label' => 'Surveillance Tasks', 'icon' => 'clipboard', 'href' => 'surveillance-tasks.php'],
        ['label' => 'User Management', 'icon' => 'users',   'href' => 'users.php'],
        ['label' => 'Escalations',     'icon' => 'alert',   'href' => 'escalations.php'],
        ['label' => 'Sync History',    'icon' => 'refresh', 'href' => 'sync-log.php'],
        ['label' => 'System Settings', 'icon' => 'settings','href' => 'settings.php'],
        ['label' => 'Audit Log',       'icon' => 'file',    'href' => 'audit-log.php'],
    ],
    'account_manager' => [
        ['label' => 'Dashboard',       'icon' => 'grid',      'href' => 'dashboard.php'],
        ['label' => 'Mail',            'icon' => 'send',      'href' => 'mail.php'],
        ['label' => 'My Accounts',     'icon' => 'users',     'href' => 'accounts.php'],
        ['label' => 'My Leads',        'icon' => 'target',    'href' => 'leads.php'],
        ['label' => 'Team Pipeline',   'icon' => 'trend',     'href' => 'pipeline.php'],
        ['label' => 'Lead Assignment', 'icon' => 'target',    'href' => 'assign.php'],
        ['label' => 'Follow-ups',      'icon' => 'clock',     'href' => 'followups.php'],
        ['label' => 'AI Drafts',       'icon' => 'mail',      'href' => 'ai-drafts.php'],
        ['label' => 'Clients',         'icon' => 'users',     'href' => 'clients.php'],
        ['label' => 'Proposals',       'icon' => 'file',      'href' => 'proposals.php'],
        ['label' => 'Invoices',        'icon' => 'briefcase', 'href' => 'invoices.php'],
        ['label' => 'Surveillance Tasks', 'icon' => 'clipboard', 'href' => 'surveillance-tasks.php'],
        ['label' => 'Escalations',     'icon' => 'alert',     'href' => 'escalations.php'],
    ],
    // Auditors are a deliberately minimal role: an account that exists
    // solely to receive assigned surveillance follow-ups and act on them —
    // including generating an invoice and recording payment for the
    // client they're auditing. No leads/clients-at-large/proposals/wider
    // financial access — see require_login()'s auditor guard below, which
    // enforces this even if someone types another page's URL directly,
    // and invoices.php's own scoping, which limits an auditor to only the
    // clients they have an assigned surveillance task for.
    'auditor' => [
        ['label' => 'Dashboard',          'icon' => 'grid',      'href' => 'dashboard.php'],
        ['label' => 'Surveillance Tasks', 'icon' => 'clipboard', 'href' => 'surveillance-tasks.php'],
        ['label' => 'Invoices',           'icon' => 'briefcase', 'href' => 'invoices.php'],
    ],
]);

// ---------------------------------------------------------------------
// Helper functions
// ---------------------------------------------------------------------

/**
 * Require an authenticated session before rendering a protected page.
 * Redirects to login.php if there's no valid session or it has expired.
 */
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . APP_BASE_URL . 'login.php');
        exit;
    }

    if (
        isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS
    ) {
        session_unset();
        session_destroy();
        header('Location: ' . APP_BASE_URL . 'login.php?expired=1');
        exit;
    }

    $_SESSION['last_activity'] = time();

    // Auditors are a deliberately minimal role — a login plus a task list,
    // nothing else. This is enforced centrally here (not just by hiding
    // nav items in ROLE_NAV) so that typing the URL of any other page
    // directly can never expose leads, clients, proposals or financials
    // to an auditor account.
    if (($_SESSION['role'] ?? '') === 'auditor') {
        // Deliberately no mail-settings.php — auditors already have their own
        // mailbox on AuditOps with their own signature, and are not asked to
        // set up a second, conflicting one here. Reminders they send are
        // relayed through the assigning account manager's CRMOps mailbox
        // instead, with the From/Reply-To swapped to the auditor's own
        // login email — see cert-reminder-send.php.
        $auditorAllowedPages = [
            'dashboard.php', 'surveillance-tasks.php', 'logout.php', 'notifications.php',
            'cert-reminder-draft.php', 'cert-reminder-send.php',
            'invoices.php', 'receipt-preview.php', 'receipt-send.php',
        ];
        $currentPage = basename(parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH) ?: '');
        if (!in_array($currentPage, $auditorAllowedPages, true)) {
            header('Location: ' . APP_BASE_URL . 'surveillance-tasks.php');
            exit;
        }
    }
}

/** Get the current user's role, defaulting to bd_officer if unset. */
function current_role(): string
{
    return $_SESSION['role'] ?? 'bd_officer';
}

/** Get the display label for the current user's role. */
function current_role_label(): string
{
    $roles = ROLES;
    return $roles[current_role()] ?? 'User';
}

/** Get the nav items for the current user's role. */
function nav_for_role(string $role): array
{
    $nav = ROLE_NAV;
    return $nav[$role] ?? $nav['bd_officer'];
}

/** True if the current user's role is in the given whitelist. */
function role_is(array $allowed): bool
{
    return in_array(current_role(), $allowed, true);
}

/** Escape helper to keep views tidy. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}