<?php
/**
 * IEMA CRMOps — Scheduled Follow-up Draft Generator
 * -----------------------------------------------------------------------
 * Run this on a schedule (daily is reasonable). It does NOT send anything.
 * It finds leads that match the same "stale" rule used by the dashboard's
 * Smart Suggestions (open stage, no contact in 14+ days), and — if there
 * isn't already a pending draft for that lead — asks the AI to draft a
 * follow-up email, then saves it to scheduled_email_drafts with status
 * 'pending_review'. A human must approve it on ai-drafts.php before
 * anything goes out.
 *
 * HOW TO SCHEDULE:
 *
 *   Option A — CLI cron (if your host gives shell access), once a day:
 *     0 7 * * * php /full/path/to/iemacrmops/cron/generate-followup-drafts.php
 *
 *   Option B — URL-based cron (cPanel "Cron Jobs" that only run curl/wget):
 *     0 7 * * * curl -s "https://yourdomain.com/cron/generate-followup-drafts.php?token=YOUR_CRON_SECRET"
 *     (set CRON_SECRET in config.php to something random first)
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/../config.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    // URL-triggered: require the shared secret so this can't be spammed
    // by anyone who finds the URL.
    if (!hash_equals(CRON_SECRET, $_GET['token'] ?? '')) {
        http_response_code(403);
        echo "Forbidden.\n";
        exit;
    }
    header('Content-Type: text/plain');
}

echo "[" . date('Y-m-d H:i:s') . "] Starting follow-up draft generation...\n";

$staleStmt = $pdo->query("
    SELECT l.id, l.company_name, l.stage,
           COALESCE((SELECT MAX(interaction_date) FROM interactions i WHERE i.lead_id = l.id), l.created_at) AS last_touch
    FROM leads l
    WHERE l.stage IN ('New','Contacted','Needs Assessment','Proposal Sent','Negotiation')
    HAVING last_touch <= DATE_SUB(NOW(), INTERVAL 14 DAY)
");
$staleLeads = $staleStmt->fetchAll();

echo "Found " . count($staleLeads) . " stale lead(s).\n";

$created = 0;
$skipped = 0;

foreach ($staleLeads as $lead) {
    // Don't duplicate — skip if a pending draft already exists for this lead.
    $existingStmt = $pdo->prepare("SELECT id FROM scheduled_email_drafts WHERE lead_id = ? AND status = 'pending_review'");
    $existingStmt->execute([$lead['id']]);
    if ($existingStmt->fetch()) {
        $skipped++;
        continue;
    }

    $fullLeadStmt = $pdo->prepare('SELECT l.*, c.sector AS client_sector FROM leads l LEFT JOIN clients c ON c.id = l.client_id WHERE l.id = ?');
    $fullLeadStmt->execute([$lead['id']]);
    $fullLead = $fullLeadStmt->fetch();

    $historyStmt = $pdo->prepare('SELECT type, interaction_date, notes FROM interactions WHERE lead_id = ? ORDER BY interaction_date DESC LIMIT 3');
    $historyStmt->execute([$lead['id']]);
    $historyText = '';
    foreach ($historyStmt->fetchAll() as $h) {
        $historyText .= "- {$h['type']} on " . date('j M Y', strtotime($h['interaction_date'])) . ": " . ($h['notes'] ?: 'no notes') . "\n";
    }
    if ($historyText === '') $historyText = "No interactions logged yet.\n";

    $prompt = "You're a Business Development Officer at IEMA Standards Limited, a certification and standards organisation in Nigeria. "
        . "Draft a short follow-up email (under 100 words) to {$fullLead['company_name']}, currently at the '{$fullLead['stage']}' stage, "
        . "who hasn't been contacted in over 14 days. Sector: {$fullLead['sector']}. Recent interaction history:\n{$historyText}\n"
        . "Also provide a short subject line on the first line prefixed with 'Subject: ', then the email body after a blank line. "
        . "Use ONLY the facts given. Do not invent names, dates, or figures not stated above.";

    $result = ai_generate($prompt, 300);

    if (!$result['ok']) {
        echo "  Lead #{$lead['id']} ({$lead['company_name']}): AI generation failed — {$result['text']}\n";
        continue;
    }

    // Split "Subject: ..." from the body if the model followed the format;
    // fall back to a generic subject if it didn't.
    $text = trim($result['text']);
    $subject = "Following up — {$fullLead['company_name']}";
    $body = $text;
    if (preg_match('/^Subject:\s*(.+)$/mi', $text, $m)) {
        $subject = trim($m[1]);
        $body = trim(preg_replace('/^Subject:\s*.+$/mi', '', $text, 1));
    }

    $insertStmt = $pdo->prepare('INSERT INTO scheduled_email_drafts (lead_id, subject, body, status) VALUES (?,?,?,?)');
    $insertStmt->execute([$lead['id'], $subject, $body, 'pending_review']);

    $usageStmt = $pdo->prepare('INSERT INTO ai_usage_log (user_id, feature, lead_id, success) VALUES (NULL, "followup", ?, 1)');
    $usageStmt->execute([$lead['id']]);

    echo "  Lead #{$lead['id']} ({$lead['company_name']}): draft queued for review.\n";
    $created++;
}

echo "Done. Created: $created, skipped (already queued): $skipped.\n";
