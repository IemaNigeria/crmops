<?php
/**
 * IEMA CRMOps — Public "Get a Quote" Form
 * -----------------------------------------------------------------------
 * Replaces the Cognito Forms quote request with one that writes directly
 * into the pipeline: submitting this creates a real Lead (stage 'New',
 * unassigned) instead of landing in a separate inbox someone has to
 * manually re-enter. No login required — this is the public-facing page
 * prospects fill out.
 *
 * Spam protection: a honeypot field (bots fill it, humans never see it)
 * plus a minimum-time check (reject submissions faster than a human could
 * plausibly fill the form). No CAPTCHA/third-party key required.
 *
 * Email: uses PHP's built-in mail() — no third-party library needed.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';

$STANDARDS = [
    'ISO 9001 Quality Management', 'ISO 14001 Environmental Management', 'ISO 22001 Food Management',
    'ISO 13485 Medical Device', 'ISO 20121 Sustainable Events', 'ISO 45001 Occupational Safety and Health',
    'ISO 37001 Anti-Bribery Management Systems', 'ISO/IEC 17025 Testing and Calibration Laboratories',
    'ISO 26000 Social Responsibility', 'ISO 31000 Risk Management', 'ISO 50001 Energy Management',
    'ISO/IEC 27001 Information Security Management', 'ISO 27301 Business Continuity Management System',
    'NDPC Data Protection Compliance', 'ISO/IEC 17065 Product Certification', 'EcoMark', 'Other',
];

$REQUEST_TYPES = [
    'Complete ISO Certification', 'Pre-Assessment Only', 'Complete Audit Only', 'Documentation Review Only',
    'Develop Documents For Cert.', 'ISO Staff Training', 'ISO Requirement Gathering', 'ISO Implementation',
    'ISO Consulting Services', 'Data Protection Service', 'Environmental Protection', 'Marine Survey',
    'Total Quality Management', 'Product Certification', 'NDT Services', 'Waste Management Services',
    'Vulnerability Assessment', 'EcoMark',
];

$CERT_STAGES  = ['Preparation for certification', 'Application Renewal', 'In the middle stage', 'New Request for Service'];
$BUDGET_RANGES = ['1,000,000 - 2,000,000' => 1500000, '2,000,000 - 3,000,000' => 2500000, '3,000,000 - 4,000,000' => 3500000, '5,000,000+' => 5000000];

$error     = '';
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Spam guards ───────────────────────────────────────────────────────
    // Honeypot: real users never fill this (it's visually hidden).
    $honeypot   = trim($_POST['website_url'] ?? '');
    // Minimum fill time: reject anything submitted in under 3 seconds.
    $formLoadedAt = (int) ($_POST['form_loaded_at'] ?? 0);
    $tooFast    = $formLoadedAt > 0 && (time() - $formLoadedAt) < 3;

    // ── Collect fields ────────────────────────────────────────────────────
    $company      = trim($_POST['company_name']  ?? '');
    $firstName    = trim($_POST['first_name']    ?? '');
    $lastName     = trim($_POST['last_name']     ?? '');
    $email        = trim($_POST['email']         ?? '');
    $phone        = trim($_POST['phone']         ?? '');
    $website      = trim($_POST['website']       ?? '');
    $branches     = trim($_POST['branches']      ?? '');
    $addressLine1 = trim($_POST['address_line1'] ?? '');
    $addressLine2 = trim($_POST['address_line2'] ?? '');
    $city         = trim($_POST['city']          ?? '');
    $stateRegion  = trim($_POST['state_region']  ?? '');
    $postalCode   = trim($_POST['postal_code']   ?? '');
    $country      = trim($_POST['country']       ?? '');
    $standard     = trim($_POST['standard']      ?? '');
    $timeframe    = trim($_POST['timeframe']     ?? '');
    $requestType  = trim($_POST['request_type']  ?? '');
    $certStage    = trim($_POST['cert_stage']    ?? '');
    $budget       = trim($_POST['budget']        ?? '');
    $description  = trim($_POST['description']   ?? '');

    if ($honeypot !== '' || $tooFast) {
        // Silently pretend success to bots — don't tip them off.
        $submitted = true;

    } elseif ($company === '' || $email === '') {
        $error = 'Please fill in at least your company name and email so we can reach you.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';

    } else {

        // ── Generate reference number ─────────────────────────────────────
        $refStmt = $pdo->query('SELECT reference_number FROM leads ORDER BY id DESC LIMIT 1');
        $last    = $refStmt->fetchColumn();
        $nextNum = 1;
        if ($last && preg_match('/LEAD-(\d{4})-(\d+)/', $last, $m)) {
            $nextNum = (int) $m[2] + 1;
        }
        $reference = 'LEAD-' . date('Y') . '-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);

        // ── Derived values ────────────────────────────────────────────────
        $contactPerson  = trim("$firstName $lastName");
        $regionParts    = array_filter([$city, $stateRegion, $country]);
        $region         = implode(', ', $regionParts);
        $estimatedValue = $BUDGET_RANGES[$budget] ?? null;
        $fullAddress    = trim("$addressLine1 $addressLine2");

        $quoteFields = array_filter([
            'Address'              => $fullAddress . ($postalCode ? ", $postalCode" : ''),
            'Website'              => $website,
            'Number of branches'   => $branches,
            'Standard requested'   => $standard,
            'Needed within'        => $timeframe,
            'Request type'         => $requestType,
            'Certification stage'  => $certStage,
            'Budget range (NGN)'   => $budget,
            'Business description' => $description,
        ], fn($v) => trim((string) $v) !== '');

        $quoteDetails          = json_encode($quoteFields);
        $quoteDetailsPlainText = implode("\n", array_map(fn($k, $v) => "$k: $v", array_keys($quoteFields), $quoteFields));

        // ── Insert lead ───────────────────────────────────────────────────
        $stmt = $pdo->prepare('
            INSERT INTO leads
                (reference_number, company_name, contact_person, contact_email, contact_phone,
                 sector, lead_source, estimated_value_ngn, country_region, stage, quote_details)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ');
        $stmt->execute([
            $reference,
            $company,
            $contactPerson ?: null,
            $email,
            $phone        ?: null,
            null,
            'Website - Get a Quote',
            $estimatedValue,
            $region       ?: null,
            'New',
            $quoteDetails ?: null,
        ]);
        $newLeadId = (int) $pdo->lastInsertId();

        // ── Link standard to scheme ───────────────────────────────────────
        if ($standard !== '') {
            $schemeStmt = $pdo->prepare('SELECT id FROM schemes WHERE name LIKE ?');
            $schemeStmt->execute(['%' . $standard . '%']);
            $schemeId = $schemeStmt->fetchColumn();
            if (!$schemeId) {
                $insSchemeStmt = $pdo->prepare('INSERT INTO schemes (name) VALUES (?) ON DUPLICATE KEY UPDATE name = name');
                $insSchemeStmt->execute([$standard]);
                $schemeId = $pdo->lastInsertId()
                    ?: $pdo->query('SELECT id FROM schemes WHERE name = ' . $pdo->quote($standard))->fetchColumn();
            }
            if ($schemeId) {
                $pdo->prepare('INSERT IGNORE INTO lead_schemes (lead_id, scheme_id) VALUES (?,?)')
                    ->execute([$newLeadId, $schemeId]);
            }
        }

        // ── Log interaction on lead timeline ──────────────────────────────
        $interactionNotes = "Submitted \"Get a Quote\" form.\n" . $quoteDetailsPlainText;
        $pdo->prepare('INSERT INTO interactions (lead_id, type, interaction_date, notes) VALUES (?,?,NOW(),?)')
            ->execute([$newLeadId, 'Email', $interactionNotes]);

        // ── Audit log ─────────────────────────────────────────────────────
        $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (NULL,?,?,?,?)')
            ->execute(['create', 'lead', $newLeadId, "New lead submitted via Get a Quote form: $reference ($company)"]);

        // ── Email notification → leads@iemacert.com ───────────────────────
        // Uses PHP's built-in mail() — no library required.
        // If emails don't arrive check: SPF/DKIM DNS records, server Sendmail/Postfix config.
        $emailTo      = 'leads@iemacert.com';
        $emailFrom    = 'no-reply@iemacert.com'; // must be on your server's own domain
        $emailSubject = "New Quote Request [$reference] — $company";

        $emailBody  = "A new quote request was submitted via the IEMA website.\n\n";
        $emailBody .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $emailBody .= " CONTACT SUMMARY\n";
        $emailBody .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $emailBody .= "Reference  : $reference\n";
        $emailBody .= "Company    : $company\n";
        $emailBody .= "Contact    : " . ($contactPerson ?: '—') . "\n";
        $emailBody .= "Email      : $email\n";
        $emailBody .= "Phone      : " . ($phone   ?: '—') . "\n";
        $emailBody .= "Region     : " . ($region  ?: '—') . "\n\n";
        $emailBody .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $emailBody .= " QUOTE DETAILS\n";
        $emailBody .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $emailBody .= $quoteDetailsPlainText . "\n\n";
        $emailBody .= "This lead has been saved to IEMA CRMOps and is awaiting assignment.\n";

        $emailHeaders  = "From: IEMA CRMOps <$emailFrom>\r\n";
        $emailHeaders .= "Reply-To: $email\r\n";
        $emailHeaders .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $emailHeaders .= "X-Mailer: PHP/" . phpversion() . "\r\n";

        $mailSent = mail($emailTo, $emailSubject, $emailBody, $emailHeaders);
        if (!$mailSent) {
            // Log silently — never show mail errors to the prospect
            error_log("Quote form: mail() failed for lead $reference ($email)");
        }
        // ── End email notification ────────────────────────────────────────

        $submitted = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Get a Quote — <?php echo e(APP_ORG); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Libre+Franklin:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --red-900: #7A0C1E; --red-700: #A30F26; --red-600: #C8102E; --red-50: #FDF1F3;
      --navy-950: #0B1524; --navy-900: #0F1B2D; --navy-800: #16263D; --navy-700: #1F3350;
      --ink-900: #131A26; --ink-700: #3A4252; --ink-500: #6B7280; --ink-400: #9AA0AB;
      --neutral-50: #FBFAF8; --neutral-100: #F4F2EE; --neutral-300: #D9D4CB;
      --canvas: #F2F0EC; --line: #E8E4DD; --line-soft: #F1EEE9;
      --green: #16A34A; --green-50: #ECFDF3;
      --r-tile: 20px;
      --shadow-tile: 0 1px 2px rgba(15,27,45,0.04), 0 2px 8px -2px rgba(15,27,45,0.05);
      --ring: 0 0 0 4px rgba(200,16,46,0.12);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
      background: var(--canvas);
      color: var(--ink-900);
      -webkit-font-smoothing: antialiased;
      min-height: 100vh;
      padding: 32px 16px;
      overflow-x: hidden;
    }
    .wrap { max-width: 720px; margin: 0 auto; }

    /* ── Banner (navy hero tile) ── */
    .banner {
      position: relative;
      overflow: hidden;
      background: radial-gradient(120% 160% at 100% 0%, var(--navy-700) 0%, var(--navy-900) 55%, var(--navy-950) 100%);
      border: 1px solid var(--navy-800);
      border-radius: var(--r-tile);
      padding: 26px 30px 28px;
      color: #fff;
      margin-bottom: 16px;
    }
    .banner::after {
      content: ''; position: absolute; right: -70px; top: -110px; width: 260px; height: 260px; border-radius: 50%;
      border: 1px solid rgba(255,255,255,0.07);
      box-shadow: 0 0 0 40px rgba(255,255,255,0.025), 0 0 0 80px rgba(255,255,255,0.02);
      pointer-events: none;
    }
    .banner img { height: 30px; display: block; border-radius: 6px; }
    .banner-kicker {
      margin-top: 22px; display: flex; align-items: center; gap: 10px;
      font-size: 11.5px; font-weight: 700; letter-spacing: 1.4px; text-transform: uppercase; color: rgba(255,255,255,0.6);
    }
    .banner-kicker::before { content: ''; width: 18px; height: 2px; border-radius: 2px; background: #E0233F; }
    .banner-title {
      margin-top: 8px; font-family: 'Libre Franklin', 'Inter', sans-serif; font-weight: 800;
      font-size: clamp(26px, 4vw, 32px); letter-spacing: -0.6px; line-height: 1.1; color: #fff;
    }
    .banner-sub { margin-top: 8px; font-size: 14px; line-height: 1.55; color: rgba(255,255,255,0.72); max-width: 480px; }

    /* ── Card (white tile) ── */
    .card {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: var(--r-tile);
      padding: 30px 32px 32px;
      box-shadow: var(--shadow-tile);
    }
    h1 {
      font-family: 'Libre Franklin', 'Inter', sans-serif;
      font-weight: 700;
      font-size: 17px;
      color: var(--ink-900);
      margin-bottom: 4px;
      letter-spacing: -0.2px;
    }
    .subtitle {
      color: var(--ink-500);
      font-size: 13.5px;
      margin-bottom: 22px;
    }

    /* ── Form layout ── */
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
    .field { margin-bottom: 16px; min-width: 0; }
    .field.full { grid-column: 1 / -1; }
    label { display: block; font-size: 12.5px; font-weight: 600; color: var(--ink-700); margin-bottom: 6px; }

    input[type=text],
    input[type=email],
    input[type=tel],
    input[type=url],
    select,
    textarea {
      width: 100%;
      padding: 11px 13px;
      border: 1px solid var(--neutral-300);
      border-radius: 12px;
      font-size: 14px;
      font-family: inherit;
      color: var(--ink-900);
      background: #fff;
      outline: none;
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    input::placeholder, textarea::placeholder { color: var(--ink-400); }
    input:focus, select:focus, textarea:focus { border-color: var(--red-600); box-shadow: var(--ring); }
    select {
      appearance: none; -webkit-appearance: none; padding-right: 38px;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 14px center;
    }
    textarea { min-height: 100px; resize: vertical; }

    /* ── Radio groups as chips ── */
    .radio-group  { display: flex; flex-wrap: wrap; gap: 8px; }
    .radio-item   {
      display: inline-flex; align-items: center; gap: 7px; margin: 0;
      padding: 7px 12px; border: 1px solid var(--line); border-radius: 999px; background: var(--neutral-50);
      font-size: 13px; font-weight: 500; color: var(--ink-700); cursor: pointer;
      transition: border-color 0.15s, background 0.15s, color 0.15s;
    }
    .radio-item:hover { border-color: var(--neutral-300); background: #fff; }
    .radio-item:has(input:checked) { border-color: var(--navy-800); background: var(--navy-800); color: #fff; }
    .radio-item:has(input:focus-visible) { box-shadow: var(--ring); }
    .radio-item input[type=radio] { accent-color: var(--red-600); width: 14px; height: 14px; margin: 0; }

    /* ── Section dividers ── */
    .section-title {
      display: flex; align-items: center; gap: 10px;
      font-family: 'Libre Franklin', 'Inter', sans-serif;
      font-weight: 700;
      font-size: 12px;
      letter-spacing: 0.8px;
      text-transform: uppercase;
      color: var(--navy-700);
      margin: 12px 0 14px;
      padding-top: 18px;
      border-top: 1px solid var(--line-soft);
    }
    .section-title::before { content: ''; width: 3px; height: 14px; border-radius: 2px; background: var(--red-600); }

    /* ── Honeypot (must stay visually hidden but NOT display:none — some bots check) ── */
    .honeypot {
      position: absolute;
      left: -9999px;
      opacity: 0;
      pointer-events: none;
      tab-index: -1;
    }

    /* ── Submit button ── */
    button[type=submit] {
      width: 100%;
      margin-top: 8px;
      padding: 14px;
      border: 1px solid transparent;
      border-radius: 12px;
      background: var(--red-600);
      color: #fff;
      font-size: 15px;
      font-weight: 700;
      font-family: inherit;
      cursor: pointer;
      box-shadow: 0 1px 2px rgba(122,12,30,0.25);
      transition: background 0.15s, transform 0.1s;
    }
    button[type=submit]:hover { background: var(--red-700); }
    button[type=submit]:active { transform: translateY(1px); }
    button[type=submit]:focus-visible { outline: none; box-shadow: var(--ring); }

    /* ── Error alert ── */
    .alert-error {
      background: var(--red-50);
      color: #B42318;
      border: 1px solid #F6D0D5;
      border-radius: 14px;
      padding: 12px 14px;
      font-size: 13px;
      margin-bottom: 18px;
    }

    /* ── Success screen ── */
    .success-card { text-align: center; padding: 20px 10px; }
    .success-icon {
      width: 56px;
      height: 56px;
      border-radius: 16px;
      background: var(--green-50);
      color: var(--green);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 18px;
    }
    .success-card h2 {
      font-family: 'Libre Franklin', 'Inter', sans-serif;
      font-size: 22px;
      color: var(--ink-900);
      margin-bottom: 8px;
    }
    .success-card p { color: var(--ink-500); font-size: 14px; line-height: 1.6; max-width: 460px; margin: 0 auto; }
    .success-ref {
      display: inline-block;
      margin-top: 16px;
      background: var(--navy-800);
      color: #fff;
      font-size: 12px;
      font-weight: 700;
      padding: 6px 14px;
      border-radius: 999px;
      letter-spacing: 0.5px;
    }

    .page-foot { margin-top: 16px; text-align: center; font-size: 12px; color: var(--ink-500); line-height: 1.6; }

    @media (max-width: 600px) {
      body { padding: 16px; }
      .form-grid { grid-template-columns: 1fr; }
      .banner { padding: 22px 20px 24px; }
      .card { padding: 24px 20px 24px; }
    }
  </style>
</head>
<body>
<div class="wrap">

  <div class="banner">
    <img src="assets/logo.png" alt="<?php echo e(APP_ORG); ?>">
    <div class="banner-kicker">IEMA Standards Limited</div>
    <div class="banner-title">Get a quote</div>
    <p class="banner-sub">ISO certification, training and consulting — tell us what you need and our team will respond with a tailored proposal.</p>
  </div>

  <div class="card">

    <?php if ($submitted): ?>

      <!-- ── Success screen ─────────────────────────────────────────── -->
      <div class="success-card">
        <div class="success-icon">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 6L9 17l-5-5"></path>
          </svg>
        </div>
        <h2>Thank you!</h2>
        <p>Your request has been received. A member of our Business Development team will be in touch shortly to discuss your certification needs.</p>
        <?php if (!empty($reference)): ?>
          <span class="success-ref"><?php echo e($reference); ?></span>
        <?php endif; ?>
      </div>

    <?php else: ?>

      <!-- ── Quote form ─────────────────────────────────────────────── -->
      <h1>Your request</h1>
      <p class="subtitle">Tell us about your certification needs and we'll get back to you. Fields marked * are required.</p>

      <?php if ($error): ?>
        <div class="alert-error"><?php echo e($error); ?></div>
      <?php endif; ?>

      <form method="POST" novalidate>

        <!-- Hidden: timestamp for minimum-fill-time check -->
        <input type="hidden" name="form_loaded_at" value="<?php echo time(); ?>">

        <!-- Honeypot: bots fill this, humans never see it -->
        <div class="honeypot" aria-hidden="true">
          <label>Leave this blank</label>
          <input type="text" name="website_url" tabindex="-1" autocomplete="off">
        </div>

        <!-- Company -->
        <div class="field full">
          <label>Company Name *</label>
          <input type="text" name="company_name" required placeholder="Your company or organisation">
        </div>

        <!-- Contact name -->
        <div class="section-title">Contact Person's Name</div>
        <div class="form-grid">
          <div class="field">
            <label>First Name</label>
            <input type="text" name="first_name" placeholder="First">
          </div>
          <div class="field">
            <label>Last Name</label>
            <input type="text" name="last_name" placeholder="Last">
          </div>
        </div>

        <!-- Address -->
        <div class="section-title">Address</div>
        <div class="field full">
          <label>Address Line 1</label>
          <input type="text" name="address_line1" placeholder="Street address">
        </div>
        <div class="field full">
          <label>Address Line 2</label>
          <input type="text" name="address_line2" placeholder="Apartment, suite, unit, etc.">
        </div>
        <div class="form-grid">
          <div class="field">
            <label>City</label>
            <input type="text" name="city" placeholder="City">
          </div>
          <div class="field">
            <label>State / Province / Region</label>
            <input type="text" name="state_region" placeholder="State or region">
          </div>
          <div class="field">
            <label>Postal / Zip Code</label>
            <input type="text" name="postal_code" placeholder="Postal code">
          </div>
          <div class="field">
            <label>Country</label>
            <input type="text" name="country" placeholder="Country">
          </div>
        </div>

        <!-- Contact details -->
        <div class="section-title">Contact Details</div>
        <div class="form-grid">
          <div class="field">
            <label>Email *</label>
            <input type="email" name="email" required placeholder="you@company.com">
          </div>
          <div class="field">
            <label>Number of Branches</label>
            <input type="text" name="branches" placeholder="e.g. 3">
          </div>
          <div class="field">
            <label>Phone</label>
            <input type="tel" name="phone" placeholder="+234 000 0000 000">
          </div>
          <div class="field">
            <label>Website</label>
            <input type="url" name="website" placeholder="https://">
          </div>
        </div>

        <!-- Standard -->
        <div class="section-title">Your Requirements</div>
        <div class="field full">
          <label>Which standard are you interested in?</label>
          <select name="standard">
            <option value="">Select a standard…</option>
            <?php foreach ($STANDARDS as $s): ?>
              <option value="<?php echo e($s); ?>"><?php echo e($s); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Timeframe -->
        <div class="field full">
          <label>How soon do you need it?</label>
          <div class="radio-group">
            <?php foreach (['One Month', '1-3 Month', '3-6 Month'] as $t): ?>
              <label class="radio-item">
                <input type="radio" name="timeframe" value="<?php echo e($t); ?>" <?php echo $t === 'One Month' ? 'checked' : ''; ?>>
                <?php echo e($t); ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Request type -->
        <div class="field full">
          <label>Request Type</label>
          <div class="radio-group">
            <?php foreach ($REQUEST_TYPES as $t): ?>
              <label class="radio-item">
                <input type="radio" name="request_type" value="<?php echo e($t); ?>">
                <?php echo e($t); ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Certification stage -->
        <div class="field full">
          <label>Certification Stage</label>
          <select name="cert_stage">
            <option value="">Select…</option>
            <?php foreach ($CERT_STAGES as $s): ?>
              <option value="<?php echo e($s); ?>"><?php echo e($s); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Budget -->
        <div class="field full">
          <label>Your Budget (NGN)</label>
          <select name="budget">
            <option value="">Select a range…</option>
            <?php foreach (array_keys($BUDGET_RANGES) as $b): ?>
              <option value="<?php echo e($b); ?>"><?php echo e($b); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Description -->
        <div class="field full">
          <label>Brief Description of your Business</label>
          <textarea name="description" placeholder="Tell us what your business does and any relevant background…"></textarea>
        </div>

        <button type="submit">Submit Request</button>

      </form>

    <?php endif; ?>

  </div><!-- /.card -->
  <p class="page-foot">&copy; <?php echo date('Y'); ?> IEMA Standards Limited · 27A Alternative Drive, Chevron, Lekki, Lagos · www.iemacert.com</p>
</div><!-- /.wrap -->
</body>
</html>