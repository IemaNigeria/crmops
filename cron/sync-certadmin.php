<?php
/**
 * IEMA CRMOps — Daily CertAdmin Sync
 * -----------------------------------------------------------------------
 * Pulls all unique company names from CertAdmin, inserts any that don't
 * already exist in CRMops, and auto-assigns them to the account manager
 * with the fewest active clients (load-balanced).
 *
 * Schedule in cPanel Cron Jobs (once per day, e.g. 6:00 AM):
 *   curl -s "https://crmops.iemacert.com/cron/sync-certadmin.php?token=YOUR_CRON_SECRET"
 *
 * Or via CLI if your host allows it:
 *   php /home/iemacert/crmops.iemacert.com/cron/sync-certadmin.php
 * -----------------------------------------------------------------------
 */

// Prevent config.php from starting a session (not needed for cron)
// and from calling require_login() which would redirect
define('RUNNING_AS_CRON', true);

require_once __DIR__ . '/../config.php';

// Auth — validate token for URL-based execution, skip for CLI
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $token = $_GET['token'] ?? '';
    if ($token !== CRON_SECRET) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
    header('Content-Type: application/json');
}

$log     = [];
$started = date('Y-m-d H:i:s');
$log[]   = "=== CertAdmin Sync started at $started ===";

// ---- Step 1: Fetch all companies from CertAdmin -------------------------
$url = CERTADMIN_API_URL . '?action=all_companies&api_key=' . urlencode(CERTADMIN_API_KEY);
$ch  = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . CERTADMIN_API_KEY],
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    $msg = "ERROR: Could not reach CertAdmin API (HTTP $httpCode)";
    $log[] = $msg;
    error_log('CertAdmin sync: ' . $msg);
    if (!$isCli) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $msg]); }
    else { echo implode("\n", $log) . "\n"; }
    exit;
}

$data = json_decode($response, true);
if (empty($data['ok']) || empty($data['companies'])) {
    $msg = "ERROR: CertAdmin returned no companies";
    $log[] = $msg;
    if (!$isCli) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $msg]); }
    else { echo implode("\n", $log) . "\n"; }
    exit;
}

$companies = $data['companies'];
$log[] = "Fetched " . count($companies) . " unique companies from CertAdmin";

// ---- Step 2: Get existing company names ---------------------------------
$existingStmt = $pdo->query('SELECT LOWER(TRIM(company_name)) FROM clients');
$existing     = array_flip($existingStmt->fetchAll(PDO::FETCH_COLUMN));

// ---- Step 3: Find account managers for load-balanced assignment ---------
$crmStmt = $pdo->query("
    SELECT u.id, COUNT(c.id) AS client_count
    FROM users u
    LEFT JOIN clients c ON c.assigned_crm_id = u.id AND c.status = 'active'
    WHERE u.role = 'account_manager' AND u.status = 'active'
    GROUP BY u.id
    ORDER BY client_count ASC
");
$crmManagers = $crmStmt->fetchAll();
$crmIndex    = 0; // round-robin index

// ---- Step 4: Insert new companies ---------------------------------------
$insertStmt = $pdo->prepare(
    'INSERT INTO clients (company_name, primary_contact_email, primary_contact_phone, status, assigned_crm_id) VALUES (?,?,?,?,?)'
);

$inserted   = 0;
$skipped    = 0;
$errors     = [];

foreach ($companies as $c) {
    $name = trim($c['company_name'] ?? '');
    if ($name === '') { $skipped++; continue; }
    if (isset($existing[strtolower($name)])) { $skipped++; continue; }

    // Pick the next account manager (round-robin among available ones)
    $assignedCrm = null;
    if (!empty($crmManagers)) {
        $assignedCrm = $crmManagers[$crmIndex % count($crmManagers)]['id'];
        $crmIndex++;
    }

    try {
        $insertStmt->execute([
            $name,
            $c['email'] ?: null,
            $c['phone'] ?: null,
            'active',
            $assignedCrm,
        ]);
        $inserted++;
        $existing[strtolower($name)] = true; // prevent duplicates within same run
    } catch (Exception $e) {
        $errors[] = $name . ': ' . $e->getMessage();
        error_log('CertAdmin sync insert error: ' . $e->getMessage());
    }
}

// ---- Step 5: Log the run ------------------------------------------------
$finished = date('Y-m-d H:i:s');
$log[]    = "Inserted: $inserted new companies";
$log[]    = "Skipped:  $skipped (already existed)";
if (!empty($errors)) {
    $log[] = "Errors:   " . count($errors);
    foreach (array_slice($errors, 0, 5) as $err) { $log[] = "  - $err"; }
}
$log[] = "=== Sync finished at $finished ===";

// Write to sync_log table
try {
    $pdo->prepare("INSERT INTO sync_log (sync_type, entity_type, entity_id, status, details) VALUES (?,?,?,?,?)")
        ->execute([
            'certadmin_daily_sync',
            'client',
            null,
            empty($errors) ? 'success' : 'partial',
            "Inserted: $inserted | Skipped: $skipped | Errors: " . count($errors) . " | Finished: $finished",
        ]);
} catch (Exception $e) {
    error_log('CertAdmin sync: could not write sync_log — ' . $e->getMessage());
}

$output = implode("\n", $log);
if ($isCli) {
    echo $output . "\n";
} else {
    echo json_encode([
        'ok'       => true,
        'inserted' => $inserted,
        'skipped'  => $skipped,
        'errors'   => count($errors),
        'log'      => $log,
    ]);
}