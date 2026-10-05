<?php
/**
 * IEMA CRMOps — Mail
 * -----------------------------------------------------------------------
 * Webmail on top of the user's own IMAP/SMTP mailbox, laid out as a bento
 * grid: summary tiles · folders · message list · reading pane.
 *
 * What this page does
 * ─────────────────────────────
 *  • Read    — HTML mail in a sandboxed iframe (no scripts), plain text
 *              fallback, attachments, To/Cc shown, prev/next navigation.
 *  • Compose — rich-text editor, To/Cc/Bcc with several addresses each,
 *              attachments (typed correctly, not octet-stream), signature.
 *  • Reply / Reply all / Forward — quoted original, threading headers,
 *              forward carries the original attachments; the original is
 *              flagged \Answered / $Forwarded after sending.
 *  • Drafts  — "Save draft" + autosave into the IMAP Drafts folder (so the
 *              draft is also visible in Outlook/Roundcube); opening a
 *              draft reloads it into the composer; sending deletes it.
 *  • Search  — full-text (subject, sender, recipients, body) with date
 *              range and All/Unread/Flagged filters; works across dates
 *              (the old page silently hid mail before 1 March).
 *  • Organise — mark read/unread, flag, move to folder, archive, delete
 *              (to Trash; permanently from Trash), bulk select.
 *  • Keyboard — c compose · r reply · a reply all · f forward · j/k next/
 *              previous · s flag · u unread · e archive · # delete · / search.
 *
 * Every state-changing request is a POST with a per-session CSRF token.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_login();

$uid      = (int) ($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['user_name'] ?? 'CRMOps User';

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

if (empty($_SESSION['mail_csrf'])) {
    $_SESSION['mail_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['mail_csrf'];

if (empty($user['mail_email'])) {
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="page-head"><div><h1>Mail</h1><p>Connect your mailbox to get started.</p></div></div>
    <div class="bento">
      <section class="tile tile--navy span-7">
        <div class="tile-label">Mailbox</div>
        <div class="tile-value" style="font-size:24px;">Not connected yet</div>
        <p style="margin-top:8px;font-size:13.5px;">Add your IEMA mailbox (the same details you use in Outlook or cPanel webmail) to read, search and send mail from CRMops.</p>
        <div class="tile-foot"><a href="mail-settings.php" class="btn-primary"><?php echo icon('settings'); ?> Go to Mail Settings</a></div>
      </section>
      <section class="tile span-5">
        <div class="tile-title">What you get</div>
        <div class="activity-item"><span class="activity-dot"></span><div class="activity-text">Inbox, Sent, Drafts and your own folders</div></div>
        <div class="activity-item"><span class="activity-dot"></span><div class="activity-text">Reply, reply all, forward with attachments</div></div>
        <div class="activity-item"><span class="activity-dot"></span><div class="activity-text">Drafts that save automatically</div></div>
        <div class="activity-item"><span class="activity-dot"></span><div class="activity-text">Search across all your mail</div></div>
      </section>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ═════════════════════════════════════════════════════════════════════════
// MIME HELPERS
// ═════════════════════════════════════════════════════════════════════════

/**
 * Walk the MIME tree and return the best HTML and plain-text part paths,
 * plus every attachment (dotted part path + filename + encoding + size).
 */
function mime_walk(object $node, string $prefix = ''): array
{
    $result = ['html_part' => null, 'plain_part' => null, 'attachments' => []];

    if (empty($node->parts)) {
        $subtype  = strtolower($node->subtype ?? '');
        $disp     = strtolower($node->disposition ?? '');
        $filename = '';
        foreach (($node->dparameters ?? []) as $p) {
            if (strtolower($p->attribute) === 'filename') { $filename = imap_utf8($p->value); break; }
        }
        if ($filename === '') {
            foreach (($node->parameters ?? []) as $p) {
                if (strtolower($p->attribute) === 'name') { $filename = imap_utf8($p->value); break; }
            }
        }
        $isAttachment = $disp === 'attachment' || ($filename !== '' && $disp !== 'inline');
        $path = $prefix ?: '1';

        if ($isAttachment) {
            $result['attachments'][] = [
                'part'     => $path,
                'filename' => $filename ?: 'attachment',
                'encoding' => (int) ($node->encoding ?? 0),
                'bytes'    => (int) ($node->bytes ?? 0),
            ];
        } elseif ($subtype === 'html') {
            $result['html_part'] = ['path' => $path, 'encoding' => (int) ($node->encoding ?? 0), 'node' => $node];
        } elseif ($subtype === 'plain') {
            $result['plain_part'] = ['path' => $path, 'encoding' => (int) ($node->encoding ?? 0), 'node' => $node];
        }
        return $result;
    }

    foreach ($node->parts as $idx => $child) {
        $childPath = $prefix === '' ? (string) ($idx + 1) : $prefix . '.' . ($idx + 1);
        $sub = mime_walk($child, $childPath);
        $result['attachments'] = array_merge($result['attachments'], $sub['attachments']);
        if ($sub['html_part']  && $result['html_part']  === null) $result['html_part']  = $sub['html_part'];
        if ($sub['plain_part'] && $result['plain_part'] === null) $result['plain_part'] = $sub['plain_part'];
    }
    return $result;
}

function mime_decode(string $raw, int $encoding): string
{
    switch ($encoding) {
        case 3:  return (string) base64_decode($raw);
        case 4:  return quoted_printable_decode($raw);
        default: return $raw;
    }
}

function to_utf8(string $text, string $charset = 'UTF-8'): string
{
    $charset = strtoupper(trim($charset));
    if ($charset === 'UTF-8' || $charset === '' || $charset === 'US-ASCII') return $text;
    $converted = @iconv($charset, 'UTF-8//TRANSLIT//IGNORE', $text);
    return $converted !== false ? $converted : $text;
}

function mime_part_charset(?object $node): string
{
    if (!$node) return 'UTF-8';
    foreach (($node->parameters ?? []) as $p) {
        if (strtolower($p->attribute) === 'charset') return strtoupper($p->value);
    }
    return 'UTF-8';
}

/** Fetch and decode the HTML/plain body of a message (by sequence number). */
function mail_fetch_body($mbox, int $msgno): array
{
    $out = ['html' => '', 'plain' => '', 'attachments' => []];
    $structure = @imap_fetchstructure($mbox, $msgno);
    if (!$structure) return $out;

    $map = mime_walk($structure);
    $out['attachments'] = $map['attachments'];

    foreach (['html_part' => 'html', 'plain_part' => 'plain'] as $key => $slot) {
        if ($map[$key]) {
            $raw = @imap_fetchbody($mbox, $msgno, $map[$key]['path'], FT_PEEK);
            $out[$slot] = to_utf8(mime_decode($raw ?: '', $map[$key]['encoding']), mime_part_charset($map[$key]['node']));
        }
    }
    if ($out['html'] === '' && $out['plain'] === '') {
        $raw = @imap_body($mbox, $msgno, FT_PEEK);
        $dec = to_utf8(mime_decode($raw ?: '', (int) ($structure->encoding ?? 0)), mime_part_charset($structure));
        if (strtolower($structure->subtype ?? '') === 'html') $out['html'] = $dec; else $out['plain'] = $dec;
    }
    return $out;
}

// ═════════════════════════════════════════════════════════════════════════
// COMPOSE HELPERS
// ═════════════════════════════════════════════════════════════════════════

/** Server-side sanitiser for the rich-text editor's HTML. */
function sanitize_compose_html(string $html): string
{
    $allowedTags = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'a', 'blockquote', 'div', 'span'];
    if (trim($html) === '') return '';

    // Drop whole blocks whose text must never survive as body copy.
    $html = preg_replace('#<(style|script|head|title|xml)\b[^>]*>.*?</\1>#is', '', $html);

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $wrapper = $doc->getElementsByTagName('div')->item(0);
    if (!$wrapper) return '';
    _sanitize_compose_node($wrapper, $allowedTags);

    $inner = '';
    foreach ($wrapper->childNodes as $child) $inner .= $doc->saveHTML($child);
    return $inner;
}

function _sanitize_compose_node(DOMNode $node, array $allowedTags): void
{
    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child->nodeType === XML_COMMENT_NODE) { $node->removeChild($child); continue; }
        if ($child->nodeType !== XML_ELEMENT_NODE) continue;

        _sanitize_compose_node($child, $allowedTags);
        $tag = strtolower($child->nodeName);

        if (in_array($tag, ['script', 'style', 'meta', 'link', 'head', 'title', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select'], true)) {
            $node->removeChild($child);
            continue;
        }
        if (!in_array($tag, $allowedTags, true)) {
            while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
            $node->removeChild($child);
            continue;
        }
        // Normalise div/span to p / plain text containers.
        if ($tag === 'span') {
            while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
            $node->removeChild($child);
            continue;
        }

        $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
        foreach (iterator_to_array($child->attributes) as $attr) $child->removeAttribute($attr->name);

        if ($tag === 'a') {
            // Only safe link schemes survive — no javascript:, data:, vbscript:.
            if ($href !== '' && preg_match('#^(https?://|mailto:|tel:)#i', $href)) {
                $child->setAttribute('href', $href);
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer');
            }
        } elseif ($tag === 'p' || $tag === 'div') {
            $child->setAttribute('style', 'margin:0 0 14px 0;');
        } elseif ($tag === 'ul' || $tag === 'ol') {
            $child->setAttribute('style', 'margin:0 0 14px 0;padding-left:22px;');
        } elseif ($tag === 'blockquote') {
            $child->setAttribute('style', 'margin:0 0 14px 0;padding:4px 0 4px 14px;border-left:3px solid #d0d0d0;color:#555;');
        }
    }
}

function compose_html_to_plain_text(string $html): string
{
    $text = preg_replace('#<(style|script|head|title)\b[^>]*>.*?</\1>#is', '', $html);
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<\/(p|div|tr|h[1-6])>/i', "\n\n", $text);
    $text = preg_replace('/<\/li>/i', "\n", $text);
    $text = preg_replace('/<li[^>]*>/i', '• ', $text);
    $text = preg_replace('/<blockquote[^>]*>/i', "\n> ", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+\n/", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", trim($text));
    return $text;
}

/**
 * Parse "a@x.com, Name <b@y.com>; c@z.com" into ['valid' => [...], 'invalid' => [...]].
 */
function parse_address_list(string $raw): array
{
    $valid = []; $invalid = [];
    foreach (preg_split('/[,;\n]+/', $raw) as $piece) {
        $piece = trim($piece);
        if ($piece === '') continue;
        if (preg_match('/<([^<>]+)>\s*$/', $piece, $m)) $piece = trim($m[1]);
        $piece = trim($piece, " \t\"'");
        if (filter_var($piece, FILTER_VALIDATE_EMAIL)) {
            $valid[strtolower($piece)] = $piece;
        } else {
            $invalid[] = $piece;
        }
    }
    return ['valid' => array_values($valid), 'invalid' => $invalid];
}

/** Address objects from imap_headerinfo → list of bare emails. */
function addr_list_from_header(array $objs, ?string $excludeEmail = null): array
{
    $out = [];
    foreach ($objs as $a) {
        if (empty($a->mailbox) || empty($a->host) || $a->host === '.SYNTAX-ERROR.') continue;
        $addr = $a->mailbox . '@' . $a->host;
        if ($excludeEmail !== null && strtolower($addr) === strtolower($excludeEmail)) continue;
        $out[strtolower($addr)] = $addr;
    }
    return array_values($out);
}

function addr_display(array $objs): string
{
    $parts = [];
    foreach ($objs as $a) {
        if (empty($a->mailbox) || empty($a->host)) continue;
        $addr = $a->mailbox . '@' . $a->host;
        $name = isset($a->personal) && $a->personal !== '' ? trim(imap_utf8($a->personal), '"') : '';
        $parts[] = $name !== '' ? $name . ' <' . $addr . '>' : $addr;
    }
    return implode(', ', $parts);
}

// ═════════════════════════════════════════════════════════════════════════
// MAILBOX HELPERS
// ═════════════════════════════════════════════════════════════════════════

function mail_imap_ref(array $user): string
{
    $port     = (int) ($user['mail_imap_port'] ?: 993);
    $certFlag = !empty($user['mail_skip_cert_check']) ? '/novalidate-cert' : '';
    return '{' . $user['mail_imap_host'] . ':' . $port . '/imap/ssl' . $certFlag . '}';
}

function mail_folder_label(string $name): string
{
    $decoded = function_exists('mb_convert_encoding') ? @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP') : $name;
    $decoded = $decoded ?: $name;
    $decoded = preg_replace('/^INBOX[.\/]/i', '', $decoded);
    return str_replace(['.', '/'], ' › ', $decoded);
}

/**
 * All folders with a role (inbox/sent/drafts/archive/junk/trash/custom),
 * special folders first. Returns [ ['name','label','role','icon'] … ].
 */
function mail_list_folders($mbox, string $imapRef): array
{
    $raw = @imap_list($mbox, $imapRef, '*') ?: [$imapRef . 'INBOX'];
    $roles = [
        'sent'    => ['sent'],
        'drafts'  => ['draft'],
        'archive' => ['archive'],
        'junk'    => ['junk', 'spam'],
        'trash'   => ['trash', 'deleted', 'bin'],
    ];
    $order = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'junk' => 4, 'trash' => 5, 'custom' => 6];
    $labels = ['inbox' => 'Inbox', 'sent' => 'Sent', 'drafts' => 'Drafts', 'archive' => 'Archive', 'junk' => 'Junk', 'trash' => 'Trash'];
    $taken = [];
    $folders = [];
    foreach ($raw as $f) {
        $short = str_replace($imapRef, '', $f);
        if ($short === '') continue;
        $lower = strtolower($short);
        $role = 'custom';
        if ($lower === 'inbox') {
            $role = 'inbox';
        } else {
            $leaf = strtolower(preg_replace('/^INBOX[.\/]/i', '', $short));
            foreach ($roles as $r => $needles) {
                foreach ($needles as $n) {
                    if (strpos($leaf, $n) === 0 && empty($taken[$r])) { $role = $r; break 2; }
                }
            }
        }
        if ($role !== 'custom') $taken[$role] = true;
        $folders[] = [
            'name'  => $short,
            'label' => $labels[$role] ?? mail_folder_label($short),
            'role'  => $role,
        ];
    }
    if (!in_array('inbox', array_column($folders, 'role'), true)) {
        array_unshift($folders, ['name' => 'INBOX', 'label' => 'Inbox', 'role' => 'inbox']);
    }
    usort($folders, fn($a, $b) => [$order[$a['role']], $a['label']] <=> [$order[$b['role']], $b['label']]);
    return $folders;
}

function mail_find_role(array $folders, string $role): ?string
{
    foreach ($folders as $f) if ($f['role'] === $role) return $f['name'];
    return null;
}

/** Make sure a Drafts folder exists (cPanel/Dovecot convention INBOX.Drafts). */
function mail_ensure_drafts($mbox, string $imapRef, array &$folders): ?string
{
    $name = mail_find_role($folders, 'drafts');
    if ($name) return $name;
    foreach (['INBOX.Drafts', 'Drafts'] as $candidate) {
        if (@imap_createmailbox($mbox, imap_utf7_encode_safe($imapRef . $candidate))) {
            @imap_subscribe($mbox, $imapRef . $candidate);
            $folders[] = ['name' => $candidate, 'label' => 'Drafts', 'role' => 'drafts'];
            return $candidate;
        }
    }
    return null;
}

function imap_utf7_encode_safe(string $s): string
{
    return function_exists('mb_convert_encoding') ? (string) mb_convert_encoding($s, 'UTF7-IMAP', 'UTF-8') : $s;
}

function mail_reopen($mbox, string $imapRef, string $folder): bool
{
    return (bool) @imap_reopen($mbox, $imapRef . $folder);
}

/** Validate a list of UIDs from the client. */
function mail_uid_list($raw): array
{
    $list = is_array($raw) ? $raw : explode(',', (string) $raw);
    $out = [];
    foreach ($list as $v) { $v = (int) $v; if ($v > 0) $out[$v] = $v; }
    return array_values($out);
}

/**
 * Read an attachment part out of a stored message into a temp file, so it
 * can be re-attached when forwarding or when a saved draft is sent.
 */
function mail_extract_part($mbox, string $imapRef, string $folder, int $uidNum, string $part): ?array
{
    if (!preg_match('/^\d+(\.\d+)*$/', $part)) return null;
    if (!mail_reopen($mbox, $imapRef, $folder)) return null;
    $msgno = (int) @imap_msgno($mbox, $uidNum);
    if ($msgno <= 0) return null;
    $structure = @imap_fetchstructure($mbox, $msgno);
    if (!$structure) return null;
    $info = null;
    foreach (mime_walk($structure)['attachments'] as $a) if ($a['part'] === $part) { $info = $a; break; }
    if (!$info) return null;
    $data = @imap_fetchbody($mbox, $msgno, $part, FT_PEEK);
    if ($data === false) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'crmatt');
    file_put_contents($tmp, mime_decode($data, $info['encoding']));
    return ['path' => $tmp, 'name' => $info['filename']];
}

/**
 * Build (and for 'send', deliver) a message with PHPMailer.
 *  $o = [to[], cc[], bcc[], subject, body_html (clean), include_signature,
 *        in_reply_to, references, attachments[['path','name']], headers[k=>v]]
 * Drafts are built with Mailer='sendmail' so that To/Cc/Bcc/Subject are all
 * kept in the stored copy — nothing is ever handed to sendmail.
 */
function mail_build_message(array $user, string $password, string $fromName, array $o, bool $isDraft): array
{
    global $phpMailerAvailable;
    if (!$phpMailerAvailable) return ['ok' => false, 'error' => 'PHPMailer is not installed at PHPMailer/src/.', 'raw_mime' => ''];

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        if ($isDraft) {
            $mail->Mailer = 'sendmail';
        } else {
            $port = (int) $user['mail_smtp_port'];
            $mail->isSMTP();
            $mail->Host       = $user['mail_smtp_host'];
            $mail->Port       = $port;
            $mail->SMTPAuth   = true;
            $mail->Username   = $user['mail_email'];
            $mail->Password   = $password;
            $mail->SMTPSecure = $port === 465 ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout    = 20;
            if (!empty($user['mail_skip_cert_check'])) {
                $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
            }
        }

        $mail->CharSet  = 'UTF-8';
        $mail->Encoding = 'quoted-printable';
        $mail->setFrom($user['mail_email'], $fromName);
        foreach ($o['to']  as $a) $mail->addAddress($a);
        foreach ($o['cc']  as $a) $mail->addCC($a);
        foreach ($o['bcc'] as $a) $mail->addBCC($a);

        // A draft may have no recipients yet; PHPMailer insists on one, so
        // park the sender in Bcc and mark it for removal when reloaded.
        if ($isDraft && !$o['to'] && !$o['cc'] && !$o['bcc']) {
            $mail->addBCC($user['mail_email']);
            $mail->addCustomHeader('X-CRMops-Placeholder-Bcc', '1');
        }

        $mail->isHTML(true);
        $mail->Subject = $o['subject'];

        if ($isDraft) {
            // Store the editor HTML as-is, so reopening the draft gives back
            // exactly what was typed (signature/wrapper are added on send).
            $mail->Body    = $o['body_html'] !== '' ? $o['body_html'] : '<p></p>';
            $mail->AltBody = compose_html_to_plain_text($o['body_html']) ?: ' ';
            $mail->addCustomHeader('X-CRMops-Draft', '1');
            $mail->addCustomHeader('X-CRMops-Signature', $o['include_signature'] ? '1' : '0');
        } else {
            $content = $o['body_html'] . ($o['include_signature'] ? build_signature_html($user) : '');
            $mail->Body =
                '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>' .
                '<body style="margin:0;padding:0;background:#ffffff;">' .
                '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#1a1a1a;text-align:left;max-width:680px;padding:8px 4px;">' .
                $content .
                '</div></body></html>';
            $plain = compose_html_to_plain_text($o['body_html']) . ($o['include_signature'] ? build_signature_text($user) : '');
            $mail->AltBody = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $plain));
        }

        foreach (($o['headers'] ?? []) as $k => $v) {
            if ($v !== '' && $v !== null) $mail->addCustomHeader($k, (string) $v);
        }
        if (!empty($o['in_reply_to'])) {
            $mail->addCustomHeader('In-Reply-To', $o['in_reply_to']);
            $mail->addCustomHeader('References', trim(($o['references'] ?? '') . ' ' . $o['in_reply_to']));
        }

        foreach ($o['attachments'] as $att) {
            if (!is_readable($att['path'])) continue;
            $mail->addAttachment($att['path'], $att['name'], \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64,
                \PHPMailer\PHPMailer\PHPMailer::filenameToType($att['name']));
        }

        $mail->preSend();
        $raw = $mail->getSentMIMEMessage();
        if (!$isDraft) $mail->postSend();

        $raw = str_replace("\r\n", "\n", $raw);
        $raw = str_replace("\n", "\r\n", $raw);
        return ['ok' => true, 'error' => '', 'raw_mime' => $raw];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage(), 'raw_mime' => ''];
    }
}

/** UID of the message just APPENDed — read UIDNEXT before appending. */
function mail_uidnext($mbox, string $imapRef, string $folder): int
{
    $st = @imap_status($mbox, $imapRef . $folder, SA_UIDNEXT);
    return $st ? (int) $st->uidnext : 0;
}

function mail_delete_uids($mbox, string $imapRef, string $folder, array $uids): void
{
    if (!$uids || !mail_reopen($mbox, $imapRef, $folder)) return;
    @imap_delete($mbox, implode(',', $uids), FT_UID);
    @imap_expunge($mbox);
}

function mail_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════
// AJAX ACTIONS (POST + CSRF)
// ═════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $sentToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!is_string($sentToken) || !hash_equals($csrfToken, $sentToken)) {
        mail_json(['ok' => false, 'message' => 'Your session has expired. Reload the page and try again.'], 403);
    }

    $action   = (string) $_POST['action'];
    $password = decrypt_secret($user['mail_password_enc']);
    if ($password === null) {
        mail_json(['ok' => false, 'message' => 'Mailbox password could not be decrypted. Re-enter it in Mail Settings.']);
    }

    $mboxResult = open_user_mailbox($user);
    if (!$mboxResult['ok'] && $action !== 'send') {
        mail_json(['ok' => false, 'message' => $mboxResult['error']]);
    }
    $mbox    = $mboxResult['mbox'] ?? null;
    $imapRef = mail_imap_ref($user);
    $folders = $mbox ? mail_list_folders($mbox, $imapRef) : [['name' => 'INBOX', 'label' => 'Inbox', 'role' => 'inbox']];
    $folderNames = array_column($folders, 'name');
    $validFolder = fn($f) => is_string($f) && in_array($f, $folderNames, true);

    // ── Send / Save draft ───────────────────────────────────────────────
    if ($action === 'send' || $action === 'save_draft') {
        $isDraft = $action === 'save_draft';
        $to  = parse_address_list((string) ($_POST['to']  ?? ''));
        $cc  = parse_address_list((string) ($_POST['cc']  ?? ''));
        $bcc = parse_address_list((string) ($_POST['bcc'] ?? ''));
        $invalid = array_merge($to['invalid'], $cc['invalid'], $bcc['invalid']);
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $bodyHtml = sanitize_compose_html((string) ($_POST['body_html'] ?? ''));
        $includeSignature = !empty($_POST['include_signature']);
        $inReplyTo  = trim((string) ($_POST['in_reply_to'] ?? ''));
        $references = trim((string) ($_POST['references'] ?? ''));
        $draftUid   = (int) ($_POST['draft_uid'] ?? 0);
        $mode       = in_array($_POST['mode'] ?? '', ['reply', 'reply_all', 'forward'], true) ? $_POST['mode'] : 'new';
        $origFolder = (string) ($_POST['orig_folder'] ?? '');
        $origUid    = (int) ($_POST['orig_uid'] ?? 0);
        // Header values must never carry line breaks.
        $inReplyTo  = preg_replace('/[\r\n]+/', ' ', $inReplyTo);
        $references = preg_replace('/[\r\n]+/', ' ', $references);

        if (!$isDraft) {
            if ($invalid) {
                mail_json(['ok' => false, 'message' => 'These addresses don\'t look right: ' . implode(', ', array_slice($invalid, 0, 5)) . '.']);
            }
            if (!$to['valid'] && !$cc['valid'] && !$bcc['valid']) {
                mail_json(['ok' => false, 'message' => 'Add at least one recipient.']);
            }
            if (trim(strip_tags($bodyHtml)) === '' && empty($_FILES['attachments']['name'][0]) && empty($_POST['carry'])) {
                mail_json(['ok' => false, 'message' => 'Write a message before sending.']);
            }
        }

        // Attachments: new uploads + ones carried over from a stored
        // message (forwarded original, or a previously saved draft).
        $attachments = [];
        $tempFiles   = [];
        $totalBytes  = 0;
        if (!empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['name'] as $i => $origName) {
                $err = $_FILES['attachments']['error'][$i];
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                    mail_json(['ok' => false, 'message' => '"' . $origName . '" is larger than the server allows.']);
                }
                if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['attachments']['tmp_name'][$i])) continue;
                $totalBytes += (int) $_FILES['attachments']['size'][$i];
                $attachments[] = ['path' => $_FILES['attachments']['tmp_name'][$i], 'name' => str_replace(["\r", "\n", '"'], '', basename($origName))];
            }
        }
        $carry = json_decode((string) ($_POST['carry'] ?? '[]'), true);
        if (is_array($carry) && $mbox) {
            foreach (array_slice($carry, 0, 30) as $c) {
                if (!is_array($c) || !$validFolder($c['folder'] ?? null)) continue;
                $ex = mail_extract_part($mbox, $imapRef, $c['folder'], (int) ($c['uid'] ?? 0), (string) ($c['part'] ?? ''));
                if ($ex) { $attachments[] = $ex; $tempFiles[] = $ex['path']; $totalBytes += filesize($ex['path']); }
            }
        }
        if ($totalBytes > 25 * 1024 * 1024) {
            foreach ($tempFiles as $t) @unlink($t);
            mail_json(['ok' => false, 'message' => 'Attachments add up to more than 25 MB — most mail servers will reject that.']);
        }

        $headers = [];
        if ($isDraft) {
            $headers['X-CRMops-Mode'] = $mode;
            if ($origUid > 0 && $validFolder($origFolder)) $headers['X-CRMops-Orig'] = $origFolder . '|' . $origUid;
        }

        $built = mail_build_message($user, $password, $userName, [
            'to' => $to['valid'], 'cc' => $cc['valid'], 'bcc' => $bcc['valid'],
            'subject' => $subject !== '' ? $subject : ($isDraft ? '' : '(no subject)'),
            'body_html' => $bodyHtml, 'include_signature' => $includeSignature,
            'in_reply_to' => $inReplyTo, 'references' => $references,
            'attachments' => $attachments, 'headers' => $headers,
        ], $isDraft);
        foreach ($tempFiles as $t) @unlink($t);

        if (!$built['ok']) {
            mail_json(['ok' => false, 'message' => ($isDraft ? 'Could not save the draft: ' : 'Could not send: ') . $built['error']]);
        }

        if ($isDraft) {
            if (!$mbox) mail_json(['ok' => false, 'message' => 'Could not reach your mailbox to save the draft.']);
            $draftsFolder = mail_ensure_drafts($mbox, $imapRef, $folders);
            if (!$draftsFolder) mail_json(['ok' => false, 'message' => 'Your mailbox has no Drafts folder and one could not be created.']);
            $newUid = mail_uidnext($mbox, $imapRef, $draftsFolder);
            $ok = @imap_append($mbox, $imapRef . $draftsFolder, $built['raw_mime'], '\\Draft \\Seen');
            if (!$ok) mail_json(['ok' => false, 'message' => 'The mail server refused to store the draft: ' . (imap_last_error() ?: 'unknown error')]);
            if ($draftUid > 0 && $draftUid !== $newUid) mail_delete_uids($mbox, $imapRef, $draftsFolder, [$draftUid]);

            // Report the stored attachments back so the composer can refer
            // to them (instead of re-uploading) on the next save or on send.
            $carryOut = [];
            if ($newUid > 0 && mail_reopen($mbox, $imapRef, $draftsFolder)) {
                $msgno = (int) @imap_msgno($mbox, $newUid);
                $st = $msgno > 0 ? @imap_fetchstructure($mbox, $msgno) : null;
                if ($st) foreach (mime_walk($st)['attachments'] as $a) {
                    $carryOut[] = ['folder' => $draftsFolder, 'uid' => $newUid, 'part' => $a['part'], 'name' => $a['filename'], 'bytes' => $a['bytes']];
                }
            }
            @imap_close($mbox);
            mail_json(['ok' => true, 'message' => 'Draft saved', 'draft_uid' => $newUid, 'drafts_folder' => $draftsFolder, 'carry' => $carryOut, 'saved_at' => date('g:i a')]);
        }

        // Sent: copy to Sent, flag the original, remove the draft.
        $savedToSent = imap_append_to_sent($user, $built['raw_mime']);
        if ($mbox) {
            if ($origUid > 0 && $validFolder($origFolder) && mail_reopen($mbox, $imapRef, $origFolder)) {
                @imap_setflag_full($mbox, (string) $origUid, $mode === 'forward' ? '$Forwarded' : '\\Answered', ST_UID);
            }
            if ($draftUid > 0 && ($df = mail_find_role($folders, 'drafts'))) mail_delete_uids($mbox, $imapRef, $df, [$draftUid]);
            @imap_close($mbox);
        }

        $all = array_merge($to['valid'], $cc['valid'], $bcc['valid']);
        $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)')
            ->execute([$uid, 'create', 'mail_sent', null,
                'Sent email to ' . implode(', ', array_slice($all, 0, 6)) . (count($all) > 6 ? ' +' . (count($all) - 6) : '')
                . ' — "' . mb_substr($subject, 0, 120) . '"' . (count($attachments) ? ' (' . count($attachments) . ' attachment(s))' : '')]);

        mail_json(['ok' => true, 'message' => 'Sent to ' . implode(', ', array_slice($all, 0, 3)) . (count($all) > 3 ? ' and ' . (count($all) - 3) . ' more' : '') . '.'
            . ($savedToSent ? '' : ' (A copy could not be saved to Sent — the message was still delivered.)')]);
    }

    // ── Everything below needs the mailbox ──────────────────────────────
    $folder = (string) ($_POST['folder'] ?? 'INBOX');
    if (!$validFolder($folder)) mail_json(['ok' => false, 'message' => 'Unknown folder.']);
    $uids = mail_uid_list($_POST['uids'] ?? '');

    // ── Open a draft in the composer ────────────────────────────────────
    if ($action === 'get_draft') {
        $draftUid = (int) ($_POST['uid'] ?? 0);
        if (!mail_reopen($mbox, $imapRef, $folder)) mail_json(['ok' => false, 'message' => 'Could not open that folder.']);
        $msgno = (int) @imap_msgno($mbox, $draftUid);
        $h = $msgno > 0 ? @imap_headerinfo($mbox, $msgno) : false;
        if (!$h) mail_json(['ok' => false, 'message' => 'That draft no longer exists.']);
        $rawHeader = (string) @imap_fetchheader($mbox, $msgno);
        $hdr = fn(string $n) => preg_match('/^' . preg_quote($n, '/') . ':\s*(.+)$/mi', $rawHeader, $m) ? trim($m[1]) : '';
        $body = mail_fetch_body($mbox, $msgno);
        $html = $body['html'] !== '' ? sanitize_compose_html($body['html']) : nl2br(e($body['plain']));
        $bcc = addr_list_from_header($h->bcc ?? []);
        if ($hdr('X-CRMops-Placeholder-Bcc') === '1') {
            $bcc = array_values(array_filter($bcc, fn($a) => strtolower($a) !== strtolower($user['mail_email'])));
        }
        $orig = explode('|', $hdr('X-CRMops-Orig'), 2);
        $carry = [];
        foreach ($body['attachments'] as $a) {
            $carry[] = ['folder' => $folder, 'uid' => $draftUid, 'part' => $a['part'], 'name' => $a['filename'], 'bytes' => $a['bytes']];
        }
        @imap_close($mbox);
        mail_json(['ok' => true, 'draft' => [
            'uid'         => $draftUid,
            'is_draft'    => $folder === mail_find_role($folders, 'drafts'),
            'to'          => implode(', ', addr_list_from_header($h->to ?? [])),
            'cc'          => implode(', ', addr_list_from_header($h->cc ?? [])),
            'bcc'         => implode(', ', $bcc),
            'subject'     => isset($h->subject) ? imap_utf8($h->subject) : '',
            'body_html'   => $html,
            'in_reply_to' => $hdr('In-Reply-To'),
            'references'  => $hdr('References'),
            'signature'   => $hdr('X-CRMops-Signature') !== '0',
            'mode'        => $hdr('X-CRMops-Mode') ?: 'new',
            'orig_folder' => $orig[0] ?? '',
            'orig_uid'    => (int) ($orig[1] ?? 0),
            'carry'       => $carry,
        ]]);
    }

    if (!$uids) mail_json(['ok' => false, 'message' => 'No messages selected.']);
    if (!mail_reopen($mbox, $imapRef, $folder)) mail_json(['ok' => false, 'message' => 'Could not open that folder.']);
    $seq = implode(',', $uids);
    $n   = count($uids);
    $s   = $n === 1 ? '' : 's';

    switch ($action) {
        case 'mark_read':   @imap_setflag_full($mbox, $seq, '\\Seen', ST_UID);      $msg = "Marked $n message$s as read."; break;
        case 'mark_unread': @imap_clearflag_full($mbox, $seq, '\\Seen', ST_UID);    $msg = "Marked $n message$s as unread."; break;
        case 'flag':        @imap_setflag_full($mbox, $seq, '\\Flagged', ST_UID);   $msg = "Flagged $n message$s."; break;
        case 'unflag':      @imap_clearflag_full($mbox, $seq, '\\Flagged', ST_UID); $msg = "Removed flag from $n message$s."; break;

        case 'move':
        case 'archive':
            $target = $action === 'archive' ? mail_find_role($folders, 'archive') : (string) ($_POST['target'] ?? '');
            if ($action === 'archive' && !$target) mail_json(['ok' => false, 'message' => 'Your mailbox has no Archive folder. Create one in webmail, or use Move to.']);
            if (!$validFolder($target) || $target === $folder) mail_json(['ok' => false, 'message' => 'Pick a different folder.']);
            if (!@imap_mail_move($mbox, $seq, $target, CP_UID)) mail_json(['ok' => false, 'message' => 'Move failed: ' . (imap_last_error() ?: 'unknown error')]);
            @imap_expunge($mbox);
            $label = '';
            foreach ($folders as $f) if ($f['name'] === $target) $label = $f['label'];
            $msg = "Moved $n message$s to $label.";
            break;

        case 'delete':
            $trash = mail_find_role($folders, 'trash');
            if ($trash && $folder !== $trash) {
                if (!@imap_mail_move($mbox, $seq, $trash, CP_UID)) mail_json(['ok' => false, 'message' => 'Delete failed: ' . (imap_last_error() ?: 'unknown error')]);
                @imap_expunge($mbox);
                $msg = "Moved $n message$s to Trash.";
            } else {
                @imap_delete($mbox, $seq, FT_UID);
                @imap_expunge($mbox);
                $msg = "Permanently deleted $n message$s.";
            }
            break;

        default:
            mail_json(['ok' => false, 'message' => 'Unknown action.']);
    }
    @imap_close($mbox);
    mail_json(['ok' => true, 'message' => $msg]);
}

// ═════════════════════════════════════════════════════════════════════════
// PAGE DATA (GET)
// ═════════════════════════════════════════════════════════════════════════
$perPage      = 40;
$mailPage     = max(1, (int) ($_GET['p'] ?? $_GET['mailpage'] ?? 1));
$searchQuery  = trim((string) ($_GET['search'] ?? ''));
$filter       = in_array($_GET['filter'] ?? '', ['unread', 'flagged'], true) ? $_GET['filter'] : 'all';
$sinceParam   = (string) ($_GET['since'] ?? 'all');
$sinceOptions = ['all' => 'Any time', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 3 months', '365d' => 'Last 12 months'];
if (!isset($sinceOptions[$sinceParam]) && !(preg_match('/^\d{4}-\d{2}-\d{2}$/', $sinceParam) && strtotime($sinceParam) && strtotime($sinceParam) <= time())) {
    $sinceParam = 'all';
}
$sinceTs = null;
if (preg_match('/^(\d+)d$/', $sinceParam, $m)) $sinceTs = strtotime('-' . (int) $m[1] . ' days');
elseif ($sinceParam !== 'all') $sinceTs = strtotime($sinceParam);

$folders         = [];
$messages        = [];
$totalFiltered   = 0;
$connectionError = '';
$currentFolder   = (string) ($_GET['folder'] ?? 'INBOX');
$folderStats     = [];
$stats           = ['unread' => 0, 'flagged' => 0, 'drafts' => 0, 'today' => 0];
$openMessage     = null;
$viewUid         = (int) ($_GET['uid'] ?? 0);
$imapRef         = mail_imap_ref($user);

$mboxResult = open_user_mailbox($user);
if ($mboxResult['ok']) {
    $mbox    = $mboxResult['mbox'];
    $folders = mail_list_folders($mbox, $imapRef);

    // Unread / total per folder (special folders + up to 20 custom).
    $customSeen = 0;
    foreach ($folders as $f) {
        if ($f['role'] === 'custom' && ++$customSeen > 20) continue;
        $st = @imap_status($mbox, $imapRef . $f['name'], SA_MESSAGES | SA_UNSEEN);
        $folderStats[$f['name']] = ['messages' => (int) ($st->messages ?? 0), 'unseen' => (int) ($st->unseen ?? 0)];
    }
    $inboxName = mail_find_role($folders, 'inbox') ?? 'INBOX';
    $draftsName = mail_find_role($folders, 'drafts');
    $stats['unread'] = $folderStats[$inboxName]['unseen'] ?? 0;
    $stats['drafts'] = $draftsName ? ($folderStats[$draftsName]['messages'] ?? 0) : 0;
    $stats['flagged'] = count(@imap_search($mbox, 'FLAGGED', SE_UID) ?: []);
    $stats['today']   = count(@imap_search($mbox, 'SINCE "' . date('d-M-Y') . '"', SE_UID) ?: []);

    // Legacy ?view=<msgno> links → uid.
    if (!in_array($currentFolder, array_column($folders, 'name'), true)) $currentFolder = $inboxName;
    if ($currentFolder !== 'INBOX' && !mail_reopen($mbox, $imapRef, $currentFolder)) {
        $connectionError = 'Could not open that folder.';
        $currentFolder = 'INBOX';
        mail_reopen($mbox, $imapRef, 'INBOX');
    }
    if (!$viewUid && isset($_GET['view'])) $viewUid = (int) @imap_uid($mbox, (int) $_GET['view']);

    // ── Build the search criteria ─────────────────────────────────────
    $crit = [];
    if ($sinceTs) $crit[] = 'SINCE "' . date('d-M-Y', $sinceTs) . '"';
    if ($filter === 'unread')  $crit[] = 'UNSEEN';
    if ($filter === 'flagged') $crit[] = 'FLAGGED';
    $base = $crit ? implode(' ', $crit) : 'ALL';

    if ($searchQuery !== '') {
        $term = str_replace(['"', '\\', "\r", "\n"], ' ', $searchQuery);
        $term = trim(preg_replace('/\s+/', ' ', $term));
        $prefix = $crit ? implode(' ', $crit) . ' ' : '';
        $found = [];
        // TEXT covers headers (From/To/Cc/Subject) and body. Some servers
        // reject a UTF-8 charset argument, so retry without it.
        $r = @imap_search($mbox, $prefix . 'TEXT "' . $term . '"', SE_UID, 'UTF-8');
        if ($r === false) $r = @imap_search($mbox, $prefix . 'TEXT "' . $term . '"', SE_UID);
        if ($r) $found = $r;
        // Belt and braces: servers whose TEXT index skips some headers.
        foreach (['SUBJECT', 'FROM', 'TO', 'CC'] as $field) {
            $r2 = @imap_search($mbox, $prefix . $field . ' "' . $term . '"', SE_UID, 'UTF-8');
            if ($r2) $found = array_merge($found, $r2);
        }
        $uidList = array_values(array_unique(array_map('intval', $found)));
    } else {
        $uidList = array_map('intval', @imap_search($mbox, $base, SE_UID) ?: []);
    }
    rsort($uidList, SORT_NUMERIC);   // UIDs increase with arrival → newest first
    @imap_errors(); @imap_alerts();  // "no messages" is not an error worth logging

    $totalFiltered = count($uidList);
    $totalPages    = max(1, (int) ceil($totalFiltered / $perPage));
    $mailPage      = min($mailPage, $totalPages);
    $pageUids      = array_slice($uidList, ($mailPage - 1) * $perPage, $perPage);

    if ($pageUids) {
        $ov = @imap_fetch_overview($mbox, implode(',', $pageUids), FT_UID) ?: [];
        $byUid = [];
        foreach ($ov as $o) $byUid[(int) $o->uid] = $o;
        foreach ($pageUids as $u) {
            if (!isset($byUid[$u])) continue;
            $o = $byUid[$u];
            $messages[] = [
                'uid'      => $u,
                'subject'  => isset($o->subject) && $o->subject !== '' ? imap_utf8($o->subject) : '(no subject)',
                'from'     => isset($o->from) ? imap_utf8($o->from) : 'Unknown sender',
                'to'       => isset($o->to) ? imap_utf8($o->to) : '',
                'date'     => $o->date ?? '',
                'ts'       => isset($o->date) ? (int) strtotime($o->date) : 0,
                'seen'     => !empty($o->seen),
                'flagged'  => !empty($o->flagged),
                'answered' => !empty($o->answered),
                'draft'    => !empty($o->draft),
            ];
        }
    }

    // ── Open message ──────────────────────────────────────────────────
    if ($viewUid > 0) {
        $msgno = (int) @imap_msgno($mbox, $viewUid);
        $header = $msgno > 0 ? @imap_headerinfo($mbox, $msgno) : false;
        if ($header) {
            $body = mail_fetch_body($mbox, $msgno);
            $myEmail = strtolower(trim($user['mail_email']));

            $fromList  = addr_list_from_header($header->from ?? []);
            $replyList = addr_list_from_header(!empty($header->reply_to) ? $header->reply_to : ($header->from ?? []));
            $toList    = addr_list_from_header($header->to ?? []);
            $ccList    = addr_list_from_header($header->cc ?? []);
            $fromEmail = $fromList[0] ?? '';
            $fromName  = isset($header->from[0]->personal) && $header->from[0]->personal !== '' ? trim(imap_utf8($header->from[0]->personal), '"') : $fromEmail;

            // Reply all = reply-to/from + original To, Cc = original Cc; never ourselves.
            $notMe = fn(array $l) => array_values(array_filter($l, fn($a) => strtolower($a) !== $myEmail));
            $raTo  = $notMe(array_values(array_unique(array_merge($replyList, $toList))));
            $raCc  = array_values(array_diff($notMe($ccList), $raTo));
            if (!$raTo && $fromEmail !== '') $raTo = [$fromEmail];   // replying to our own sent mail

            $plain = $body['html'] !== '' ? compose_html_to_plain_text($body['html']) : trim($body['plain']);
            $subject = isset($header->subject) && $header->subject !== '' ? imap_utf8($header->subject) : '(no subject)';
            $dateStr = !empty($header->date) ? date('D, j M Y \a\t g:i a', strtotime($header->date)) : '';
            $baseSubject = preg_replace('/^((re|fw|fwd|aw|wg)\s*:\s*)+/i', '', $subject);

            $quoteHtml = '<p><br></p><p>On ' . e($dateStr) . ', ' . e($fromName) . ' &lt;' . e($fromEmail) . '&gt; wrote:</p>'
                . '<blockquote>' . nl2br(e(mb_substr($plain, 0, 20000))) . '</blockquote>';
            $fwdHtml = '<p><br></p><p>---------- Forwarded message ----------<br>'
                . 'From: ' . e(addr_display($header->from ?? [])) . '<br>'
                . 'Date: ' . e($dateStr) . '<br>'
                . 'Subject: ' . e($subject) . '<br>'
                . 'To: ' . e(addr_display($header->to ?? []))
                . (!empty($header->cc) ? '<br>Cc: ' . e(addr_display($header->cc)) : '') . '</p>'
                . '<p>' . nl2br(e(mb_substr($plain, 0, 20000))) . '</p>';

            $refs = '';
            $rawHeader = (string) @imap_fetchheader($mbox, $msgno);
            if (preg_match('/^References:\s*((?:.+)(?:\r?\n[ \t].+)*)/mi', $rawHeader, $mm)) $refs = trim(preg_replace('/\s+/', ' ', $mm[1]));

            // imap_headerinfo reports new mail as Recent='N' rather than Unseen='U'.
            $isUnseen = trim((string) ($header->Unseen ?? '')) === 'U' || trim((string) ($header->Recent ?? '')) === 'N';
            $isFlagged = trim((string) ($header->Flagged ?? '')) === 'F';
            if ($isUnseen) {
                @imap_setflag_full($mbox, (string) $viewUid, '\\Seen', ST_UID);
                if (isset($folderStats[$currentFolder])) $folderStats[$currentFolder]['unseen'] = max(0, $folderStats[$currentFolder]['unseen'] - 1);
                if ($currentFolder === $inboxName) $stats['unread'] = max(0, $stats['unread'] - 1);
                foreach ($messages as &$mm2) if ($mm2['uid'] === $viewUid) $mm2['seen'] = true;
                unset($mm2);
            }

            $openMessage = [
                'uid'         => $viewUid,
                'msgno'       => $msgno,
                'subject'     => $subject,
                'fromName'    => $fromName,
                'fromEmail'   => $fromEmail,
                'toDisplay'   => addr_display($header->to ?? []),
                'ccDisplay'   => addr_display($header->cc ?? []),
                'date'        => $header->date ?? '',
                'html'        => $body['html'],
                'plain'       => $body['plain'],
                'attachments' => $body['attachments'],
                'flagged'     => $isFlagged,
                'compose'     => [
                    'uid'        => $viewUid,
                    'folder'     => $currentFolder,
                    'messageId'  => trim((string) ($header->message_id ?? '')),
                    'references' => $refs,
                    'replyTo'    => implode(', ', $replyList ?: [$fromEmail]),
                    'replyAllTo' => implode(', ', $raTo),
                    'replyAllCc' => implode(', ', $raCc),
                    'reSubject'  => 'Re: ' . $baseSubject,
                    'fwdSubject' => 'Fwd: ' . $baseSubject,
                    'quoteHtml'  => $quoteHtml,
                    'fwdHtml'    => $fwdHtml,
                    'attachments'=> array_map(fn($a) => ['folder' => $currentFolder, 'uid' => $viewUid, 'part' => $a['part'], 'name' => $a['filename'], 'bytes' => $a['bytes']], $body['attachments']),
                ],
            ];
        }
    }
    @imap_close($mbox);
} else {
    $connectionError = $mboxResult['error'];
    $folders = [['name' => 'INBOX', 'label' => 'Inbox', 'role' => 'inbox']];
}

$folderRole = 'inbox';
$folderLabel = 'Inbox';
foreach ($folders as $f) if ($f['name'] === $currentFolder) { $folderRole = $f['role']; $folderLabel = $f['label']; }
$isDraftsFolder = $folderRole === 'drafts';
$isSentFolder   = $folderRole === 'sent';
$isTrashFolder  = $folderRole === 'trash';
$hasArchive     = (bool) mail_find_role($folders, 'archive');
$draftsFolderName = mail_find_role($folders, 'drafts') ?? '';
$totalPages     = max(1, (int) ceil($totalFiltered / $perPage));

/** Build a mail.php URL keeping the current view state. */
function mail_url(array $over = []): string
{
    global $currentFolder, $searchQuery, $filter, $sinceParam, $mailPage;
    $q = array_merge([
        'folder' => $currentFolder,
        'search' => $searchQuery,
        'filter' => $filter,
        'since'  => $sinceParam,
        'p'      => $mailPage,
    ], $over);
    $q = array_filter($q, fn($v, $k) => !($v === '' || $v === null || ($k === 'filter' && $v === 'all') || ($k === 'since' && $v === 'all') || ($k === 'p' && (int) $v <= 1) || ($k === 'folder' && $v === 'INBOX')), ARRAY_FILTER_USE_BOTH);
    return 'mail.php' . ($q ? '?' . http_build_query($q) : '');
}

function mail_short_date(int $ts): string
{
    if ($ts <= 0) return '';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return date('g:i a', $ts);
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    if (date('Y', $ts) === date('Y')) return date('j M', $ts);
    return date('j M Y', $ts);
}

function mail_sender_name(string $from): string
{
    if (preg_match('/^\s*"?([^"<]+?)"?\s*<[^>]+>\s*$/', $from, $m)) return trim($m[1]);
    return trim($from, " <>\"");
}

function mail_initials(string $name): string
{
    $name = preg_replace('/[^\p{L}\s]/u', ' ', $name);
    $parts = preg_split('/\s+/', trim($name));
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $p) $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    return $ini ?: '?';
}

function mail_avatar_hue(string $s): int { return hexdec(substr(md5(strtolower($s)), 0, 2)) % 6; }

function mail_bytes(int $b): string
{
    if ($b <= 0) return '';
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b / 1024) . ' KB';
    return round($b / 1048576, 1) . ' MB';
}

/** Icons this page needs beyond the shared set. */
function mx_icon(string $name): string
{
    $p = [
        'star'      => '<path d="M12 2.8l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.6l-5.8 3.1 1.1-6.5L2.6 9.6l6.5-.9z"/>',
        'reply'     => '<path d="M9 17l-5-5 5-5"/><path d="M4 12h11a5 5 0 0 1 5 5v2"/>',
        'reply-all' => '<path d="M7 17l-5-5 5-5"/><path d="M12 17l-5-5 5-5"/><path d="M7 12h9a5 5 0 0 1 5 5v2"/>',
        'forward'   => '<path d="M15 17l5-5-5-5"/><path d="M20 12H9a5 5 0 0 0-5 5v2"/>',
        'archive'   => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><path d="M10 12h4"/>',
        'inbox'     => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'clip'      => '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
        'folder'    => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'mail-open' => '<path d="M21.2 8.4c.5.38.8.97.8 1.6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V10a2 2 0 0 1 .8-1.6l8-6a2 2 0 0 1 2.4 0l8 6z"/><path d="M22 10l-10 7L2 10"/>',
        'printer'   => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'arrow-left'=> '<path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/>',
        'link'      => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'up'        => '<path d="M18 15l-6-6-6 6"/>',
        'down'      => '<path d="M6 9l6 6 6-6"/>',
        'keyboard'  => '<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M8 14h8"/>',
        'save'      => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
        'minimize'  => '<path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/>',
        'maximize'  => '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
        'quote'     => '<path d="M3 21c3 0 7-1 7-8V5c0-1.25-.76-2.02-2-2H4c-1.25 0-2 .75-2 1.97V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .01-1 1.03V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.76-2.02-2-2h-4c-1.25 0-2 .75-2 1.97V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3c0 1 0 1 1 1z"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ($p[$name] ?? '') . '</svg>';
}

function mail_folder_icon(string $role): string
{
    return match ($role) {
        'inbox'   => mx_icon('inbox'),
        'sent'    => icon('send'),
        'drafts'  => icon('edit'),
        'archive' => mx_icon('archive'),
        'junk'    => icon('alert'),
        'trash'   => icon('trash'),
        default   => mx_icon('folder'),
    };
}

$pageTitle = $openMessage ? $openMessage['subject'] . ' · Mail' : $folderLabel . ' · Mail';
require_once __DIR__ . '/includes/header.php';
define('CRMOPS_MAIL_VIEW', true);
require __DIR__ . '/includes/mail-view.php';
require_once __DIR__ . '/includes/footer.php';
