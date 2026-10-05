<?php
/**
 * IEMA CRMOps — Mail Attachment Download
 * -----------------------------------------------------------------------
 * Streams one MIME part of a message the logged-in user owns. Never
 * caches to disk — reads and streams directly from the IMAP connection.
 *
 * Supports dotted part numbers (e.g. "2", "2.1", "3.2") so multi-
 * attachment messages all download correctly.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_login();

$uid    = (int) ($_SESSION['user_id'] ?? 0);
$stmt   = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$uid]);
$user   = $stmt->fetch();

$folder   = $_GET['folder']   ?? 'INBOX';
$msgno    = (int) ($_GET['msgno']    ?? 0);
$part     = $_GET['part']     ?? '1';       // e.g. "2" or "2.1"
$filename = $_GET['filename'] ?? 'attachment';

// Basic sanity — only digits and dots allowed in part numbers.
if (!preg_match('/^[\d.]+$/', $part)) {
    http_response_code(400);
    exit('Invalid part number.');
}

if (empty($user['mail_email']) || $msgno <= 0) {
    http_response_code(400);
    exit('Invalid request.');
}

// ── Open mailbox ──────────────────────────────────────────────────────────
$mboxResult = open_user_mailbox($user);
if (!$mboxResult['ok']) {
    http_response_code(502);
    exit('Could not connect to mailbox: ' . $mboxResult['error']);
}
$mbox = $mboxResult['mbox'];

if ($folder !== 'INBOX') {
    // Only folders that actually exist in this user's mailbox.
    if (!preg_match('/^[\p{L}\p{N} ._\-\/&+,()]+$/u', $folder)) { imap_close($mbox); http_response_code(400); exit('Invalid folder.'); }
    $port     = (int) ($user['mail_imap_port'] ?: 993);
    $certFlag = !empty($user['mail_skip_cert_check']) ? '/novalidate-cert' : '';
    $imapRef  = '{' . $user['mail_imap_host'] . ':' . $port . '/imap/ssl' . $certFlag . '}';
    @imap_reopen($mbox, $imapRef . $folder);
}

// ── Fetch the raw bytes for this MIME part ────────────────────────────────
// imap_fetchbody() uses dotted section notation natively (RFC 3501 §6.4.5).
$data = @imap_fetchbody($mbox, $msgno, $part, FT_PEEK);

if ($data === false || $data === '') {
    imap_close($mbox);
    http_response_code(404);
    exit('Attachment data not found.');
}

// ── Determine the transfer encoding for this specific part ────────────────
// Walk imap_fetchstructure()'s part tree using the same dotted path so we
// find the right node regardless of nesting depth.
$encoding = 0; // default: 7BIT / no decode needed
$topStructure = @imap_fetchstructure($mbox, $msgno);

if ($topStructure) {
    $segments = explode('.', $part);
    $node     = $topStructure;

    foreach ($segments as $seg) {
        $idx = (int) $seg - 1;
        if (isset($node->parts[$idx])) {
            $node = $node->parts[$idx];
        }
        // If there are no sub-parts at this level the node IS the target.
    }

    $encoding = $node->encoding ?? 0;
}

imap_close($mbox);

// IMAP transfer-encoding constants:
//  0 = 7BIT, 1 = 8BIT, 2 = BINARY, 3 = BASE64, 4 = QUOTED-PRINTABLE, 5 = OTHER
switch ($encoding) {
    case 3: $data = base64_decode($data);            break;
    case 4: $data = quoted_printable_decode($data);  break;
    default: /* nothing */                            break;
}

// ── Stream to browser ─────────────────────────────────────────────────────
$cleanName    = str_replace(['"', '\\', "\r", "\n", '/'], '_', basename($filename));
$asciiName    = preg_replace('/[^\x20-\x7E]/', '_', $cleanName);
$safeFilename = rawurlencode($cleanName);
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . $safeFilename);
header('Content-Length: ' . strlen($data));
header('Cache-Control: no-store');
echo $data;