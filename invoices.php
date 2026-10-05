<?php
/**
 * IEMA CRMOps — Invoices
 * -----------------------------------------------------------------------
 * Generated after a Proposal is marked Signed. Replicates the existing
 * Wave invoice format exactly so this can replace issuing invoices
 * through a separate tool.
 *
 * Trigger: proposals.php "Create Invoice" link (appears when status=Signed)
 * Who generates: BD Officer or BD Manager who owns the proposal
 *
 * Fixes applied (2025):
 *  1. Print/PDF — removed .panel:first-child from print CSS; added .no-print
 *     utility class instead so only the right elements are suppressed.
 *  2. Edit invoice — new ?edit= route, edit form pre-populated from DB,
 *     edit_invoice POST handler, and Edit button on the view page (draft/sent only).
 *  3. Bill-to contact name + phone — new columns bill_to_contact and
 *     bill_to_phone on the invoices table (ALTER TABLE below); exposed in
 *     create form, edit form, and rendered in invoice HTML via invoice-render.php.
 * -----------------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/invoice-render.php';
require_login();

$role     = current_role();
$uid      = (int) ($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['user_name'] ?? 'CRMOps User';
$error    = '';
$success  = '';

// ---------------------------------------------------------------------------
// Auditor scope — strictly limited to clients they have a surveillance task for
// ---------------------------------------------------------------------------
$isAuditor = ($role === 'auditor');
$myAuditorClientIds = [];
if ($isAuditor) {
    $tcStmt = $pdo->prepare('SELECT DISTINCT client_id FROM surveillance_tasks WHERE assigned_to = ? AND client_id IS NOT NULL');
    $tcStmt->execute([$uid]);
    $myAuditorClientIds = array_map('intval', $tcStmt->fetchAll(PDO::FETCH_COLUMN));
}

/** True if this invoice's client is one the current auditor is allowed to touch. Non-auditors always pass. */
function auditor_can_touch_invoice(bool $isAuditor, array $allowedClientIds, ?array $invoice): bool
{
    if (!$isAuditor) return true;
    if (!$invoice || empty($invoice['client_id'])) return false;
    return in_array((int) $invoice['client_id'], $allowedClientIds, true);
}

// A bare ?new=1 with no client_id would leave an auditor filling a form that
// gets rejected on submit — fall back to the scoped list instead.
if ($isAuditor && isset($_GET['new']) && !isset($_GET['client_id'])) {
    unset($_GET['new']);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function next_invoice_number(PDO $pdo): string
{
    $stmt = $pdo->query('SELECT invoice_number FROM invoices ORDER BY id DESC LIMIT 1');
    $last = $stmt->fetchColumn();
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        return (string) ((int) $m[1] + 1);
    }
    return (string) (100000000000000 + random_int(100000, 999999));
}

/**
 * Collect and validate the common invoice field set from $_POST.
 * Returns ['ok'=>true,'data'=>[...]] or ['ok'=>false,'error'=>'...'].
 */
function collect_invoice_fields(): array
{
    $billToName    = trim($_POST['bill_to_name']    ?? '');
    $billToContact = trim($_POST['bill_to_contact'] ?? '');   // NEW: contact person
    $billToPhone   = trim($_POST['bill_to_phone']   ?? '');   // NEW: phone number
    $billToAddress = trim($_POST['bill_to_address'] ?? '');
    $billToEmail   = trim($_POST['bill_to_email']   ?? '');
    $issueDate     = $_POST['issue_date'] ?? date('Y-m-d');
    $dueDate       = $_POST['due_date']   ?? date('Y-m-d');
    $discount      = (float) ($_POST['discount']  ?? 0);
    $vatRate       = (float) ($_POST['vat_rate']  ?? BILLING_DEFAULT_VAT_RATE);
    $notes         = trim($_POST['notes'] ?? 'Payment is 100%');

    $itemDescriptions = $_POST['item_description'] ?? [];
    $itemCodes        = $_POST['item_code']        ?? [];
    $itemQtys         = $_POST['item_qty']         ?? [];
    $itemPrices       = $_POST['item_price']       ?? [];

    if ($billToName === '' || empty(array_filter($itemDescriptions))) {
        return ['ok' => false, 'error' => 'Please provide a bill-to name and at least one line item.'];
    }

    $subtotal = 0;
    $items    = [];
    foreach ($itemDescriptions as $i => $desc) {
        $desc = trim($desc);
        if ($desc === '') continue;
        $qty    = max(1, (int) ($itemQtys[$i]   ?? 1));
        $price  = (float) ($itemPrices[$i] ?? 0);
        $amount = $qty * $price;
        $subtotal += $amount;
        $items[] = [
            'code'   => trim($itemCodes[$i] ?? ''),
            'desc'   => $desc,
            'qty'    => $qty,
            'price'  => $price,
            'amount' => $amount,
        ];
    }
    $vatAmount = round(($subtotal - $discount) * ($vatRate / 100), 2);
    $total     = round($subtotal - $discount + $vatAmount, 2);

    return [
        'ok'   => true,
        'data' => compact(
            'billToName','billToContact','billToPhone','billToAddress','billToEmail',
            'issueDate','dueDate','discount','vatRate','vatAmount','notes',
            'subtotal','total','items'
        ),
    ];
}

// ===========================================================================
// POST — Create invoice
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_invoice') {
    $clientId   = (int) ($_POST['client_id']   ?? 0) ?: null;
    $leadId     = (int) ($_POST['lead_id']     ?? 0) ?: null;
    $proposalId = (int) ($_POST['proposal_id'] ?? 0) ?: null;

    if ($isAuditor && (!$clientId || !in_array($clientId, $myAuditorClientIds, true))) {
        $error = 'You can only generate invoices for a client you have an assigned surveillance task for.';
    } else {
        $f = collect_invoice_fields();
        if (!$f['ok']) {
            $error = $f['error'];
        } else {
            $d = $f['data'];
            $invoiceNumber = next_invoice_number($pdo);

            $stmt = $pdo->prepare('
                INSERT INTO invoices
                    (invoice_number, client_id, lead_id, proposal_id,
                     bill_to_name, bill_to_contact, bill_to_phone,
                     bill_to_address, bill_to_email,
                     issue_date, due_date,
                     subtotal_ngn, discount_ngn, vat_rate, vat_amount_ngn, total_ngn,
                     payment_account_note, notes, created_by, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ');
            $stmt->execute([
                $invoiceNumber, $clientId, $leadId, $proposalId,
                $d['billToName'], $d['billToContact'] ?: null, $d['billToPhone'] ?: null,
                $d['billToAddress'] ?: null, $d['billToEmail'] ?: null,
                $d['issueDate'], $d['dueDate'],
                $d['subtotal'], $d['discount'], $d['vatRate'], $d['vatAmount'], $d['total'],
                BILLING_PAYMENT_ACCOUNT, $d['notes'], $uid, 'draft',
            ]);
            $invoiceId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare('
                INSERT INTO invoice_items
                    (invoice_id, service_code, description, quantity, unit_price_ngn, amount_ngn, sort_order)
                VALUES (?,?,?,?,?,?,?)
            ');
            foreach ($d['items'] as $order => $item) {
                $itemStmt->execute([$invoiceId, $item['code'] ?: null, $item['desc'], $item['qty'], $item['price'], $item['amount'], $order]);
            }

            $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)')
                ->execute([$uid, 'create', 'invoice', $invoiceId, "Created invoice $invoiceNumber for {$d['billToName']} — ₦" . number_format($d['total'])]);

            header('Location: invoices.php?view=' . $invoiceId . '&created=1');
            exit;
        }
    }
}

// ===========================================================================
// POST — Edit invoice
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_invoice') {
    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $invStmt   = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
    $invStmt->execute([$invoiceId]);
    $invoice = $invStmt->fetch();

    if (!$invoice) {
        $error = 'Invoice not found.';
    } elseif (!auditor_can_touch_invoice($isAuditor, $myAuditorClientIds, $invoice)) {
        $error = 'Unauthorized.';
    } elseif (!in_array($invoice['status'], ['draft', 'sent'], true)) {
        $error = 'Only draft or sent invoices can be edited.';
    } else {
        $f = collect_invoice_fields();
        if (!$f['ok']) {
            $error = $f['error'];
        } else {
            $d = $f['data'];

            $pdo->prepare('
                UPDATE invoices SET
                    bill_to_name    = ?,
                    bill_to_contact = ?,
                    bill_to_phone   = ?,
                    bill_to_address = ?,
                    bill_to_email   = ?,
                    issue_date      = ?,
                    due_date        = ?,
                    subtotal_ngn    = ?,
                    discount_ngn    = ?,
                    vat_rate        = ?,
                    vat_amount_ngn  = ?,
                    total_ngn       = ?,
                    notes           = ?
                WHERE id = ?
            ')->execute([
                $d['billToName'], $d['billToContact'] ?: null, $d['billToPhone'] ?: null,
                $d['billToAddress'] ?: null, $d['billToEmail'] ?: null,
                $d['issueDate'], $d['dueDate'],
                $d['subtotal'], $d['discount'], $d['vatRate'], $d['vatAmount'], $d['total'],
                $d['notes'],
                $invoiceId,
            ]);

            // Replace all line items
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);
            $itemStmt = $pdo->prepare('
                INSERT INTO invoice_items
                    (invoice_id, service_code, description, quantity, unit_price_ngn, amount_ngn, sort_order)
                VALUES (?,?,?,?,?,?,?)
            ');
            foreach ($d['items'] as $order => $item) {
                $itemStmt->execute([$invoiceId, $item['code'] ?: null, $item['desc'], $item['qty'], $item['price'], $item['amount'], $order]);
            }

            $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)')
                ->execute([$uid, 'update', 'invoice', $invoiceId, "Edited invoice {$invoice['invoice_number']} — new total ₦" . number_format($d['total'])]);

            header('Location: invoices.php?view=' . $invoiceId . '&updated=1');
            exit;
        }
    }
}

// ===========================================================================
// POST — Send / record payment / cancel
// ===========================================================================
$justRecordedPaymentId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['send_invoice','record_payment','cancel_invoice'], true)) {
    $invoiceId = (int) ($_POST['invoice_id'] ?? 0);
    $invStmt   = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
    $invStmt->execute([$invoiceId]);
    $invoice = $invStmt->fetch();

    if (!$invoice) {
        $error = 'Invoice not found.';
    } elseif (!auditor_can_touch_invoice($isAuditor, $myAuditorClientIds, $invoice)) {
        $error = 'Unauthorized.';

    // ---- Send ---------------------------------------------------------------
    } elseif ($_POST['action'] === 'send_invoice') {
        if (empty($invoice['bill_to_email'])) {
            $error = 'This invoice has no bill-to email — add one before sending.';
        } else {
            $itemsStmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order');
            $itemsStmt->execute([$invoiceId]);
            $invItems = $itemsStmt->fetchAll();

            if ($isAuditor) {
                $taskStmt = $pdo->prepare('SELECT assigned_by FROM surveillance_tasks WHERE assigned_to = ? AND client_id = ? ORDER BY created_at DESC LIMIT 1');
                $taskStmt->execute([$uid, (int) $invoice['client_id']]);
                $assignedBy = (int) $taskStmt->fetchColumn();

                $senderStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $senderStmt->execute([$assignedBy ?: $uid]);
                $sender = $senderStmt->fetch();

                $meStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
                $meStmt->execute([$uid]);
                $me = $meStmt->fetch();
                $displayEmail = $me['email'] ?? '';
                $displayName  = $me['name']  ?? $userName;
            } else {
                $senderStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $senderStmt->execute([$uid]);
                $sender = $senderStmt->fetch();
                $displayEmail = $sender['mail_email'] ?? '';
                $displayName  = $userName;
            }

            if (empty($sender['mail_email']) || empty($sender['mail_smtp_host'])) {
                $error = $isAuditor
                    ? "The account manager who assigned this client's surveillance task hasn't set up their mailbox yet — ask them to configure it in Mail Settings before invoices can be sent."
                    : 'Set up your mailbox on Mail Settings before sending invoices.';
            } else {
                $password  = decrypt_secret($sender['mail_password_enc']);
                $htmlBody  = render_invoice_html($invoice, $invItems, true);
                $plainBody = "Invoice {$invoice['invoice_number']} from " . BILLING_ORG_NAME . "\nTotal due: ₦" . number_format($invoice['total_ngn'], 2) . "\n\nPayment account: " . BILLING_PAYMENT_ACCOUNT;

                $result = smtp_send_mail(
                    $sender['mail_smtp_host'], (int) $sender['mail_smtp_port'],
                    $sender['mail_email'], $password,
                    $displayEmail, $displayName,
                    $invoice['bill_to_email'],
                    "Invoice {$invoice['invoice_number']} from " . BILLING_ORG_NAME,
                    $htmlBody, $plainBody, null,
                    !empty($sender['mail_skip_cert_check'])
                );
                if ($result['ok']) {
                    imap_append_to_sent($sender, $result['raw_mime']);
                    $pdo->prepare("UPDATE invoices SET status='sent', sent_at=NOW() WHERE id=?")->execute([$invoiceId]);
                    $success = "Invoice sent to {$invoice['bill_to_email']}.";
                    $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)')->execute([$uid, 'update', 'invoice', $invoiceId, "Sent invoice {$invoice['invoice_number']}"]);
                } else {
                    $error = 'Could not send: ' . $result['error'];
                }
            }
        }

    // ---- Record payment -----------------------------------------------------
    } elseif ($_POST['action'] === 'record_payment') {
        $amountPaid  = (float) ($_POST['amount_paid']    ?? 0);
        $paymentDate = trim($_POST['payment_date']        ?? date('Y-m-d'));
        $reference   = trim($_POST['payment_reference']   ?? '');
        $notes       = trim($_POST['payment_notes']       ?? '');

        if ($amountPaid <= 0) {
            $error = 'Please enter a valid payment amount.';
        } else {
            $pdo->prepare('INSERT INTO invoice_payments (invoice_id, amount_ngn, payment_date, reference, notes, recorded_by) VALUES (?,?,?,?,?,?)')
                ->execute([$invoiceId, $amountPaid, $paymentDate, $reference ?: null, $notes ?: null, $uid]);
            $justRecordedPaymentId = (int) $pdo->lastInsertId();

            $totalPaidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_ngn), 0) FROM invoice_payments WHERE invoice_id = ?');
            $totalPaidStmt->execute([$invoiceId]);
            $totalPaid = (float) $totalPaidStmt->fetchColumn();

            $outstanding = round($invoice['total_ngn'] - $totalPaid, 2);

            if ($outstanding <= 0) {
                $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=?")->execute([$invoiceId]);
                $success = "Payment of ₦" . number_format($amountPaid, 2) . " recorded. Invoice is now fully paid. Review the receipt below before sending it to the client.";
            } else {
                $pdo->prepare("UPDATE invoices SET status='part-paid' WHERE id=?")->execute([$invoiceId]);
                $success = "Payment of ₦" . number_format($amountPaid, 2) . " recorded. Outstanding balance: ₦" . number_format($outstanding, 2) . ". Review the receipt below before sending it to the client.";
            }

            $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)')->execute([$uid, 'update', 'invoice', $invoiceId, "Payment of ₦" . number_format($amountPaid, 2) . " recorded. Total paid: ₦" . number_format($totalPaid, 2)]);

            // Refresh invoice row so the view below reflects the new status
            $invStmt->execute([$invoiceId]);
            $invoice = $invStmt->fetch();
        }

    // ---- Cancel -------------------------------------------------------------
    } elseif ($_POST['action'] === 'cancel_invoice') {
        $pdo->prepare("UPDATE invoices SET status='cancelled' WHERE id=?")->execute([$invoiceId]);
        $success = 'Invoice cancelled.';
    }
}

// ===========================================================================
// Load view data (single invoice + payments)
// ===========================================================================
$viewingInvoice  = null;
$viewingItems    = [];
$viewingPayments = [];
$totalPaid       = 0;
$outstanding     = 0;

if (isset($_GET['view'])) {
    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
    $stmt->execute([(int) $_GET['view']]);
    $viewingInvoice = $stmt->fetch();
    if (!auditor_can_touch_invoice($isAuditor, $myAuditorClientIds, $viewingInvoice)) {
        $viewingInvoice = null;
    }
    if ($viewingInvoice) {
        $itemsStmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order');
        $itemsStmt->execute([$viewingInvoice['id']]);
        $viewingItems = $itemsStmt->fetchAll();

        $paymentsStmt = $pdo->prepare('SELECT p.*, u.name AS recorded_by_name FROM invoice_payments p LEFT JOIN users u ON u.id = p.recorded_by WHERE p.invoice_id = ? ORDER BY p.payment_date ASC, p.created_at ASC');
        $paymentsStmt->execute([$viewingInvoice['id']]);
        $viewingPayments = $paymentsStmt->fetchAll();

        $totalPaid   = array_sum(array_column($viewingPayments, 'amount_ngn'));
        $outstanding = round($viewingInvoice['total_ngn'] - $totalPaid, 2);
    }
}

// ===========================================================================
// Load edit data (existing invoice for pre-population)
// ===========================================================================
$editInvoice = null;
$editItems   = [];

if (isset($_GET['edit'])) {
    $editStmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
    $editStmt->execute([(int) $_GET['edit']]);
    $editInvoice = $editStmt->fetch();

    if (!auditor_can_touch_invoice($isAuditor, $myAuditorClientIds, $editInvoice)) {
        $editInvoice = null;
        $error = 'Invoice not found or access denied.';
    } elseif ($editInvoice && !in_array($editInvoice['status'], ['draft', 'sent'], true)) {
        $error = 'Only draft or sent invoices can be edited.';
        $editInvoice = null;
    }

    if ($editInvoice) {
        $editItemsStmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order');
        $editItemsStmt->execute([$editInvoice['id']]);
        $editItems = $editItemsStmt->fetchAll();
    }
}

// ===========================================================================
// Prefill (create form) — from signed proposal, client_id, or ?new=1
// ===========================================================================
$prefill = null;

if (!$viewingInvoice && !$editInvoice) {
    if (isset($_GET['from_proposal'])) {
        $pStmt = $pdo->prepare("
            SELECT p.*, c.company_name AS client_company, c.address AS client_address,
                   c.primary_contact_email AS client_email, c.primary_contact_name AS client_contact,
                   c.primary_contact_phone AS client_phone, l.company_name AS lead_company
            FROM proposals p
            LEFT JOIN clients c ON c.id = p.client_id
            LEFT JOIN leads   l ON l.id = p.lead_id
            WHERE p.id = ? AND p.status = 'Signed'
        ");
        $pStmt->execute([(int) $_GET['from_proposal']]);
        $prefill = $pStmt->fetch();
        if (!$prefill) {
            $error = 'Proposal not found or not yet signed.';
        }
    } elseif (isset($_GET['client_id'])) {
        $clientIdParam = (int) $_GET['client_id'];
        if ($isAuditor && !in_array($clientIdParam, $myAuditorClientIds, true)) {
            $error = 'You can only generate invoices for a client you have an assigned surveillance task for.';
        } else {
            $cStmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
            $cStmt->execute([$clientIdParam]);
            $clientRow = $cStmt->fetch();
            if (!$clientRow) {
                $error = 'Client not found.';
            } else {
                $prefill = [
                    'id'               => null,
                    'client_id'        => $clientRow['id'],
                    'lead_id'          => null,
                    'value_ngn'        => null,
                    'client_company'   => $clientRow['company_name'],
                    'client_contact'   => $clientRow['primary_contact_name']  ?? '',
                    'client_phone'     => $clientRow['primary_contact_phone'] ?? '',
                    'client_address'   => $clientRow['address'] ?? '',
                    'client_email'     => $clientRow['primary_contact_email'] ?? '',
                    'lead_company'     => '',
                    'item_description' => 'Surveillance Audit',
                ];
            }
        }
    } elseif (isset($_GET['new']) && !$isAuditor) {
        $prefill = [
            'id'               => null,
            'client_id'        => null,
            'lead_id'          => null,
            'value_ngn'        => null,
            'client_company'   => '',
            'client_contact'   => '',
            'client_phone'     => '',
            'client_address'   => '',
            'client_email'     => '',
            'lead_company'     => '',
            'item_description' => '',
        ];
    }
}

// ===========================================================================
// Invoice list
// ===========================================================================
if ($isAuditor) {
    if (!empty($myAuditorClientIds)) {
        $ph   = implode(',', array_fill(0, count($myAuditorClientIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE client_id IN ($ph) ORDER BY created_at DESC");
        $stmt->execute($myAuditorClientIds);
        $invoices = $stmt->fetchAll();
    } else {
        $invoices = [];
    }
} else {
    $stmt     = $pdo->query('SELECT * FROM invoices ORDER BY created_at DESC');
    $invoices = $stmt->fetchAll();
}

$statusBadge = [
    'draft'     => 'stage-lead',
    'sent'      => 'stage-proposal',
    'part-paid' => 'stage-negotiation',
    'paid'      => 'stage-client',
    'overdue'   => 'stage-lost',
    'cancelled' => 'stage-lost',
];

// ---------------------------------------------------------------------------
// Presentation-only summary figures (derived from $invoices loaded above)
// ---------------------------------------------------------------------------
$ivToday = date('Y-m-d');
$ivIsPastDue = fn(array $inv): bool => !in_array($inv['status'], ['paid', 'cancelled', 'draft'], true) && !empty($inv['due_date']) && $inv['due_date'] < $ivToday;
$ivCount = ['draft' => 0, 'sent' => 0, 'part-paid' => 0, 'paid' => 0, 'overdue' => 0, 'cancelled' => 0];
$ivAwaitValue = 0.0; $ivAwaitCount = 0; $ivPaidValue = 0.0; $ivPastDue = 0; $ivDraftValue = 0.0;
foreach ($invoices as $inv) {
    if (isset($ivCount[$inv['status']])) { $ivCount[$inv['status']]++; }
    if (in_array($inv['status'], ['sent', 'part-paid', 'overdue'], true)) { $ivAwaitValue += (float) $inv['total_ngn']; $ivAwaitCount++; }
    if ($inv['status'] === 'paid')  { $ivPaidValue += (float) $inv['total_ngn']; }
    if ($inv['status'] === 'draft') { $ivDraftValue += (float) $inv['total_ngn']; }
    if ($ivIsPastDue($inv)) { $ivPastDue++; }
}
$ivFmtM = fn($v) => '₦' . number_format($v / 1000000, 1) . 'M';

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ── Invoices page (bento) ───────────────────────────────────────────────── */
.iv-num{ font-variant-numeric:tabular-nums; font-weight:600; color:var(--ink-900); white-space:nowrap; }
.iv-due-late, .iv-kv dd.iv-due-late{ color:#B42318; font-weight:600; }
.iv-toolbar{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.iv-toolbar .iv-ref{ font-family:'Libre Franklin',sans-serif; font-weight:700; color:var(--ink-900); font-size:14px; }
.iv-toolbar .iv-right{ margin-left:auto; display:flex; gap:8px; flex-wrap:wrap; }
.iv-back .icon{ transform:rotate(90deg); }
.iv-doc{ background:var(--neutral-50); align-self:start; }
.iv-doc-inner{ background:#fff; border:1px solid var(--line); border-radius:14px; overflow-x:auto; }
.iv-side-col{ display:flex; flex-direction:column; gap:var(--gap); min-width:0; }
.iv-kv{ display:grid; grid-template-columns:auto minmax(0,1fr); gap:9px 14px; font-size:13px; }
.iv-kv dt{ color:var(--ink-500); font-weight:500; }
.iv-kv dd{ color:var(--ink-900); font-weight:600; text-align:right; overflow-wrap:anywhere; }
.iv-pay{ display:flex; align-items:center; gap:12px; padding:11px 0; border-bottom:1px solid var(--line-soft); }
.iv-pay:last-child, .iv-pay:has(+ .tile-foot){ border-bottom:none; }
.iv-pay .amt{ font-family:'Libre Franklin',sans-serif; font-weight:800; color:var(--green-700); font-size:14px; }
.iv-pay .meta{ font-size:11.5px; color:var(--ink-500); margin-top:2px; overflow-wrap:anywhere; }
.iv-pay .icon-button{ width:32px; height:32px; border-radius:9px; margin-left:auto; flex-shrink:0; }
.iv-pay .icon-button .icon{ width:15px; height:15px; }
.iv-hero-meter{ height:8px; border-radius:999px; background:rgba(255,255,255,0.12); overflow:hidden; margin-top:16px; }
.iv-hero-meter > span{ display:block; height:100%; background:var(--green); border-radius:inherit; }
.iv-hero-split{ display:flex; justify-content:space-between; gap:10px; font-size:12px; color:rgba(255,255,255,0.7); margin-top:8px; }
.iv-hero-split b{ color:#fff; font-weight:700; }
/* Forms */
.iv-line{ display:grid; grid-template-columns:120px minmax(0,1fr) 70px 150px 36px; gap:8px; align-items:end; margin-bottom:8px; }
.iv-line .form-field label{ font-size:11px; }
.iv-line + .iv-line .form-field label{ display:none; }
.iv-line-remove{ padding:9px 10px !important; color:var(--red-600) !important; }
@media (max-width:760px){
  .iv-line + .iv-line .form-field label{ display:block; }
  .iv-line{ grid-template-columns:minmax(0,1fr) minmax(0,1fr) 36px; padding:12px; border:1px solid var(--line-soft); border-radius:12px; background:var(--neutral-50); }
  .iv-line > :nth-child(2){ grid-column:1 / 3; grid-row:1; }
  .iv-line > :nth-child(5){ grid-column:3; grid-row:1; }
  .iv-line > :nth-child(1){ grid-column:1; grid-row:2; }
  .iv-line > :nth-child(3){ grid-column:2 / 4; grid-row:2; }
  .iv-line > :nth-child(4){ grid-column:1 / -1; grid-row:3; }
}
.iv-sum-row{ display:flex; justify-content:space-between; gap:10px; font-size:13px; padding:7px 0; color:var(--ink-700); }
.iv-sum-row span:last-child{ font-variant-numeric:tabular-nums; font-weight:600; color:var(--ink-900); }
.iv-sum-total{ border-top:1px solid var(--line); margin-top:6px; padding-top:12px; font-size:15px; }
.iv-sum-total span{ font-family:'Libre Franklin',sans-serif; font-weight:800 !important; color:var(--ink-900) !important; }
.iv-form-note{ font-size:12px; color:var(--ink-500); line-height:1.5; }
.iv-sticky{ position:sticky; top:84px; }
@media (max-width:1024px){ .iv-sticky{ position:static; } }
/* Modals */
.iv-modal{ display:none; position:fixed; inset:0; background:rgba(11,21,36,0.5); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:20px; }
.iv-modal-card{ background:#fff; border-radius:var(--r-tile); padding:24px; width:100%; box-shadow:var(--shadow-pop); border:1px solid var(--line); }
.iv-modal-card h3{ font-size:16px; }
.iv-receipt-grid{ display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; }
@media (max-width:600px){ .iv-receipt-grid{ grid-template-columns:1fr; } }
@media (max-width:1024px){
  .iv-side-col{ order:-1; }
  .bento > .invoice-actions{ order:-2; }
}
@media (max-width:760px){
  .iv-doc-inner > div{ padding:22px !important; min-width:640px; }
  .iv-toolbar .iv-right{ margin-left:0; width:100%; }
}

/* ── Print / Save-PDF ────────────────────────────────────────────────────── */
@media print {
  .sidebar, .topbar, .page-head,
  .no-print,
  #recordPaymentModal, #receiptSendModal { display: none !important; }
  main { padding: 0 !important; }
  .bento { display:block !important; }
  .iv-doc { border:none !important; padding:0 !important; background:#fff !important; box-shadow:none !important; }
  .iv-doc-inner { border:none !important; }
}
</style>

<!-- ========================================================================
     PAGE HEADER
========================================================================= -->
<div class="page-head">
  <div>
    <div class="eyebrow">Billing</div>
    <h1>Invoices</h1>
    <p>Generated from signed proposals — matches your existing Wave invoice format.</p>
  </div>
  <?php if (!$viewingInvoice && !$prefill && !$editInvoice && !$isAuditor): ?>
    <div class="page-actions">
      <a href="invoices.php?new=1" class="btn-primary">
        <?php echo icon('plus'); ?> New Invoice
      </a>
    </div>
  <?php endif; ?>
</div>

<?php if ($error):   ?><div class="alert error"><?php echo icon('alert'); ?><span><?php echo e($error); ?></span></div><?php endif; ?>
<?php if ($success): ?><div class="alert success"><?php echo icon('check'); ?><span><?php echo e($success); ?></span></div><?php endif; ?>
<?php if (isset($_GET['created'])): ?><div class="alert success"><?php echo icon('check'); ?><span>Invoice created successfully.</span></div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert success"><?php echo icon('check'); ?><span>Invoice updated successfully.</span></div><?php endif; ?>

<?php
// ===========================================================================
// BRANCH A — View single invoice
// ===========================================================================
if ($viewingInvoice):
  $vTotal   = (float) $viewingInvoice['total_ngn'];
  $vPaidPct = $vTotal > 0 ? (int) min(100, round($totalPaid / $vTotal * 100)) : 0;
  $vLate    = $ivIsPastDue($viewingInvoice);
?>

  <div class="bento">
  <!-- Actions bar (suppressed on print) -->
  <section class="tile pad-sm span-12 invoice-actions no-print">
    <div class="iv-toolbar">
      <a href="invoices.php" class="btn-secondary btn-sm iv-back"><?php echo icon('chevron'); ?> Back to list</a>
      <span class="iv-ref">#<?php echo e($viewingInvoice['invoice_number']); ?></span>
      <span class="badge <?php echo e($statusBadge[$viewingInvoice['status']] ?? ''); ?>"><?php echo e(ucfirst($viewingInvoice['status'])); ?></span>
      <div class="iv-right">
        <!-- Edit — only for draft / sent -->
        <?php if (in_array($viewingInvoice['status'], ['draft','sent'], true)): ?>
          <a href="invoices.php?edit=<?php echo (int) $viewingInvoice['id']; ?>" class="btn-secondary">
            <?php echo icon('edit'); ?> Edit
          </a>
        <?php endif; ?>
        <!-- Record payment -->
        <?php if (!in_array($viewingInvoice['status'], ['paid','cancelled'], true)): ?>
          <button type="button" class="btn-secondary" onclick="document.getElementById('recordPaymentModal').style.display='flex'">
            <?php echo icon('check'); ?> Record Payment
          </button>
        <?php endif; ?>
        <!-- Print / PDF -->
        <button type="button" class="btn-secondary" onclick="window.print()"><?php echo icon('download'); ?> Print / Save PDF</button>
        <!-- Send / Resend -->
        <?php if (in_array($viewingInvoice['status'], ['draft','sent','part-paid'], true)): ?>
          <form method="POST">
            <input type="hidden" name="action"     value="send_invoice">
            <input type="hidden" name="invoice_id" value="<?php echo (int) $viewingInvoice['id']; ?>">
            <button type="submit" class="btn-primary"><?php echo icon('send'); ?> <?php echo $viewingInvoice['status'] === 'sent' ? 'Resend' : 'Send'; ?> to Client</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- The printable invoice body -->
  <section class="tile iv-doc span-8 pad-sm">
    <div class="iv-doc-inner">
      <?php echo render_invoice_html($viewingInvoice, $viewingItems); ?>
    </div>
  </section>

  <div class="span-4 iv-side-col no-print">
    <section class="tile tile--navy">
      <div class="tile-head" style="margin-bottom:0;">
        <span class="tile-label"><?php echo $viewingInvoice['status'] === 'cancelled' ? 'Cancelled invoice' : ($outstanding <= 0 ? 'Paid in full' : 'Outstanding balance'); ?></span>
        <div class="tile-icon"><?php echo icon($outstanding <= 0 && $viewingInvoice['status'] !== 'cancelled' ? 'check' : 'briefcase'); ?></div>
      </div>
      <div class="tile-value">₦<?php echo number_format($outstanding <= 0 ? $vTotal : max(0, $outstanding), 2); ?></div>
      <div class="tile-sub">
        <?php if ($outstanding <= 0): ?>
          Invoice total settled<?php echo !empty($viewingInvoice['paid_at']) ? ' on ' . e(date('j M Y', strtotime($viewingInvoice['paid_at']))) : ''; ?>
        <?php else: ?>
          Due <?php echo e(date('j M Y', strtotime($viewingInvoice['due_date']))); ?><?php echo $vLate ? ' · past due' : ''; ?>
        <?php endif; ?>
      </div>
      <div class="iv-hero-meter"><span style="width:<?php echo (int) $vPaidPct; ?>%;"></span></div>
      <div class="iv-hero-split">
        <span>Paid <b>₦<?php echo number_format($totalPaid, 2); ?></b></span>
        <span>of <b>₦<?php echo number_format($vTotal, 2); ?></b></span>
      </div>
    </section>

    <section class="tile">
      <div class="tile-head"><h2 class="tile-title">Bill to</h2></div>
      <dl class="iv-kv">
        <dt>Company</dt><dd><?php echo e($viewingInvoice['bill_to_name']); ?></dd>
        <dt>Contact</dt><dd><?php echo e($viewingInvoice['bill_to_contact'] ?? '') ?: '—'; ?></dd>
        <dt>Phone</dt><dd><?php echo e($viewingInvoice['bill_to_phone'] ?? '') ?: '—'; ?></dd>
        <dt>Email</dt><dd><?php echo e($viewingInvoice['bill_to_email'] ?? '') ?: '—'; ?></dd>
        <dt>Issued</dt><dd><?php echo e(date('j M Y', strtotime($viewingInvoice['issue_date']))); ?></dd>
        <dt>Due</dt><dd class="<?php echo $vLate ? 'iv-due-late' : ''; ?>"><?php echo e(date('j M Y', strtotime($viewingInvoice['due_date']))); ?></dd>
      </dl>
    </section>

    <!-- Payment history (suppressed on print) -->
    <section class="tile">
      <div class="tile-head">
        <h2 class="tile-title">Payment History</h2>
        <span class="badge navy"><?php echo count($viewingPayments); ?> payment<?php echo count($viewingPayments) === 1 ? '' : 's'; ?></span>
      </div>
      <?php if (empty($viewingPayments)): ?>
        <p class="iv-form-note">No payments recorded yet.<?php if (!in_array($viewingInvoice['status'], ['paid','cancelled'], true)): ?> Use <b>Record Payment</b> when funds arrive — you'll get to review the receipt before it's sent.<?php endif; ?></p>
      <?php else: ?>
        <?php foreach ($viewingPayments as $pay): ?>
          <div class="iv-pay">
            <div style="min-width:0;">
              <div class="amt">₦<?php echo number_format($pay['amount_ngn'], 2); ?></div>
              <div class="meta">
                <?php echo date('j M Y', strtotime($pay['payment_date'])); ?>
                · Ref: <?php echo e($pay['reference'] ?? '—'); ?>
                · <?php echo e($pay['recorded_by_name'] ?? '—'); ?>
              </div>
              <?php if (!empty($pay['notes'])): ?><div class="meta"><?php echo e($pay['notes']); ?></div><?php endif; ?>
            </div>
            <button type="button" class="icon-button" title="Preview and send the receipt for this payment"
              onclick="openReceiptSendModal(<?php echo (int) $pay['id']; ?>)">
              <?php echo icon('mail'); ?>
            </button>
          </div>
        <?php endforeach; ?>
        <div class="tile-foot" style="justify-content:space-between; border-top:1px solid var(--line); margin-top:0; padding-top:12px; font-size:13px; font-weight:700;">
          <span>Total Paid: ₦<?php echo number_format($totalPaid, 2); ?></span>
          <span style="color:<?php echo $outstanding <= 0 ? 'var(--green-700)' : '#B42318'; ?>">
            <?php echo $outstanding <= 0 ? 'Fully Paid ✓' : 'Outstanding: ₦' . number_format($outstanding, 2); ?>
          </span>
        </div>
      <?php endif; ?>
    </section>
  </div>
  </div>

  <!-- Record Payment Modal -->
  <div id="recordPaymentModal" class="iv-modal" style="display:none; z-index:100;">
    <div class="iv-modal-card" style="max-width:480px;">
      <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:14px;">
        <div>
          <h3>Record Payment</h3>
          <p style="font-size:12px; color:var(--ink-500); margin-top:2px;">
            Invoice total: ₦<?php echo number_format($viewingInvoice['total_ngn'], 2); ?>
            <?php if ($outstanding > 0 && $outstanding < $viewingInvoice['total_ngn']): ?>
              &nbsp;|&nbsp; Outstanding: ₦<?php echo number_format($outstanding, 2); ?>
            <?php endif; ?>
          </p>
        </div>
        <button type="button" onclick="document.getElementById('recordPaymentModal').style.display='none'" class="btn-ghost btn-sm" aria-label="Close">✕</button>
      </div>
      <form method="POST">
        <input type="hidden" name="action"     value="record_payment">
        <input type="hidden" name="invoice_id" value="<?php echo (int) $viewingInvoice['id']; ?>">
        <div class="form-grid">
          <div class="form-field">
            <label>Amount Received (₦) *</label>
            <input type="number" name="amount_paid" step="0.01" min="0.01"
              max="<?php echo max(0, $outstanding); ?>"
              value="<?php echo $outstanding > 0 ? number_format($outstanding, 2, '.', '') : ''; ?>"
              placeholder="0.00" required>
          </div>
          <div class="form-field">
            <label>Payment Date *</label>
            <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
          </div>
          <div class="form-field full">
            <label>Payment Reference</label>
            <input type="text" name="payment_reference" placeholder="e.g. bank transfer ref, cheque number">
          </div>
          <div class="form-field full">
            <label>Notes</label>
            <input type="text" name="payment_notes" placeholder="Optional notes">
          </div>
          <div class="form-field full" style="font-size:11.5px; color:var(--ink-500);">
            You'll be able to preview the receipt and send it to the client right after recording — nothing is emailed automatically.
          </div>
        </div>
        <div class="form-actions">
          <button type="button" class="btn-secondary" onclick="document.getElementById('recordPaymentModal').style.display='none'">Cancel</button>
          <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Record Payment</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Receipt Preview / Send Modal -->
  <div id="receiptSendModal" class="iv-modal" style="display:none; z-index:101;">
    <div class="iv-modal-card" style="max-width:760px; max-height:90vh; overflow-y:auto;">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
        <h3 style="font-size:17px;">Review &amp; Send Receipt</h3>
        <button type="button" onclick="closeReceiptSendModal()" class="btn-ghost btn-sm" aria-label="Close">✕</button>
      </div>
      <p style="font-size:12.5px; color:var(--ink-500); margin-bottom:16px;">This is exactly what the client will receive — review it before sending.</p>

      <div id="receiptSendLoading" style="text-align:center; padding:30px; color:var(--ink-500);">
        <div style="font-size:13px;">⏳ Preparing receipt…</div>
      </div>

      <div id="receiptSendForm" style="display:none;">
        <div class="iv-receipt-grid">
          <div class="form-field">
            <label>To</label>
            <input type="email" id="receiptSendTo" placeholder="client@example.com">
          </div>
          <div class="form-field">
            <label>Subject</label>
            <input type="text" id="receiptSendSubject">
          </div>
        </div>
        <div class="form-field" style="margin-bottom:14px;">
          <label>Note to client <span style="font-weight:400; color:var(--ink-500);">(optional — appears above the receipt)</span></label>
          <textarea id="receiptSendMessage" style="min-height:70px; line-height:1.6;" placeholder="e.g. Thank you for your payment — please find your receipt below."></textarea>
        </div>

        <div id="receiptSendStatus" style="display:none; padding:10px 14px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:12px;"></div>

        <div style="border:1px solid var(--line); border-radius:14px; padding:16px; background:var(--neutral-50); max-height:420px; overflow:auto; margin-bottom:16px;">
          <div id="receiptSendPreview"></div>
        </div>

        <div style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap;">
          <button type="button" class="btn-secondary" onclick="closeReceiptSendModal()">Cancel</button>
          <button type="button" class="btn-primary" id="receiptSendBtn" onclick="sendReceiptEmail()">
            <?php echo icon('send'); ?> Send Receipt
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
  var currentReceiptPaymentId = 0;

  function closeReceiptSendModal() {
    document.getElementById('receiptSendModal').style.display = 'none';
  }

  function openReceiptSendModal(paymentId) {
    currentReceiptPaymentId = paymentId;
    document.getElementById('receiptSendLoading').style.display = 'block';
    document.getElementById('receiptSendForm').style.display    = 'none';
    document.getElementById('receiptSendStatus').style.display  = 'none';
    document.getElementById('receiptSendModal').style.display   = 'flex';

    fetch('receipt-preview.php?payment_id=' + encodeURIComponent(paymentId))
      .then(r => r.json())
      .then(data => {
        document.getElementById('receiptSendLoading').style.display = 'none';
        document.getElementById('receiptSendForm').style.display    = 'block';
        if (!data.ok) {
          showReceiptSendStatus('error', 'Could not load receipt: ' + (data.error || 'Unknown error'));
          return;
        }
        document.getElementById('receiptSendTo').value      = data.to_email || '';
        document.getElementById('receiptSendSubject').value = data.subject  || '';
        document.getElementById('receiptSendMessage').value = '';
        document.getElementById('receiptSendPreview').innerHTML = data.receipt_html || '';
        if (!data.to_email) {
          showReceiptSendStatus('info', 'No email address on this invoice — enter one below before sending.');
        }
      })
      .catch(() => {
        document.getElementById('receiptSendLoading').style.display = 'none';
        document.getElementById('receiptSendForm').style.display    = 'block';
        showReceiptSendStatus('error', 'Could not reach the server. Please try again.');
      });
  }

  function sendReceiptEmail() {
    var toEmail = document.getElementById('receiptSendTo').value.trim();
    var subject = document.getElementById('receiptSendSubject').value.trim();
    var message = document.getElementById('receiptSendMessage').value.trim();

    if (!toEmail) { showReceiptSendStatus('error', 'Please enter the recipient email address.'); return; }
    if (!subject) { showReceiptSendStatus('error', 'Please enter a subject.'); return; }

    var sendBtn = document.getElementById('receiptSendBtn');
    sendBtn.disabled    = true;
    sendBtn.textContent = 'Sending…';

    fetch('receipt-send.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ payment_id: currentReceiptPaymentId, to_email: toEmail, subject: subject, message: message })
    })
    .then(r => r.json())
    .then(data => {
      sendBtn.disabled  = false;
      sendBtn.innerHTML = '<?php echo addslashes(icon("send")); ?> Send Receipt';
      if (data.ok) {
        showReceiptSendStatus('success', '✅ ' + data.message);
        setTimeout(() => { closeReceiptSendModal(); }, 1800);
      } else {
        showReceiptSendStatus('error', '❌ ' + (data.error || 'Send failed.'));
      }
    })
    .catch(() => {
      sendBtn.disabled  = false;
      sendBtn.innerHTML = '<?php echo addslashes(icon("send")); ?> Send Receipt';
      showReceiptSendStatus('error', 'Network error — please try again.');
    });
  }

  function showReceiptSendStatus(type, msg) {
    var el = document.getElementById('receiptSendStatus');
    el.style.display    = 'block';
    el.style.background = type === 'success' ? 'var(--green-50)' : type === 'error' ? 'var(--red-50)' : 'var(--blue-50)';
    el.style.color      = type === 'success' ? 'var(--green-700)' : type === 'error' ? '#B42318' : '#1E40AF';
    el.textContent = msg;
  }

  <?php if ($justRecordedPaymentId): ?>
  document.addEventListener('DOMContentLoaded', function () {
    openReceiptSendModal(<?php echo (int) $justRecordedPaymentId; ?>);
  });
  <?php endif; ?>
  </script>

<?php
// ===========================================================================
// BRANCH B — Edit existing invoice
// ===========================================================================
elseif ($editInvoice): ?>

  <form method="POST" class="iv-form">
    <input type="hidden" name="action"     value="edit_invoice">
    <input type="hidden" name="invoice_id" value="<?php echo (int) $editInvoice['id']; ?>">

    <div class="bento">
      <section class="tile span-12 pad-sm">
        <div class="iv-toolbar">
          <a href="invoices.php?view=<?php echo (int) $editInvoice['id']; ?>" class="btn-secondary btn-sm iv-back"><?php echo icon('chevron'); ?> Back to invoice</a>
          <h2 class="tile-title" style="font-size:16px;">Edit Invoice <?php echo e($editInvoice['invoice_number']); ?></h2>
          <span class="badge <?php echo e($statusBadge[$editInvoice['status']] ?? ''); ?>"><?php echo e(ucfirst($editInvoice['status'])); ?></span>
          <span class="iv-form-note" style="margin-left:auto;">Changes apply immediately. Only draft and sent invoices can be edited.</span>
        </div>
      </section>

      <!-- Bill-to details -->
      <section class="tile span-8">
        <div class="tile-head"><h2 class="tile-title"><?php echo icon('building'); ?> Bill To</h2></div>
        <div class="form-grid">
          <div class="form-field full">
            <label>Company / Organisation Name *</label>
            <input type="text" name="bill_to_name" value="<?php echo e($editInvoice['bill_to_name']); ?>" required>
          </div>
          <div class="form-field">
            <label>Contact Person</label>
            <input type="text" name="bill_to_contact" value="<?php echo e($editInvoice['bill_to_contact'] ?? ''); ?>" placeholder="e.g. John Adeyemi">
          </div>
          <div class="form-field">
            <label>Phone Number</label>
            <input type="tel" name="bill_to_phone" value="<?php echo e($editInvoice['bill_to_phone'] ?? ''); ?>" placeholder="e.g. +234 801 234 5678">
          </div>
          <div class="form-field full">
            <label>Email Address</label>
            <input type="email" name="bill_to_email" value="<?php echo e($editInvoice['bill_to_email'] ?? ''); ?>">
          </div>
          <div class="form-field full">
            <label>Address</label>
            <textarea name="bill_to_address" style="min-height:60px;"><?php echo e($editInvoice['bill_to_address'] ?? ''); ?></textarea>
          </div>
        </div>
      </section>

      <!-- Dates -->
      <section class="tile span-4 flex-col">
        <div class="tile-head"><h2 class="tile-title"><?php echo icon('calendar'); ?> Dates</h2></div>
        <div class="form-field">
          <label>Invoice Date</label>
          <input type="date" name="issue_date" value="<?php echo e($editInvoice['issue_date']); ?>">
        </div>
        <div class="form-field" style="margin-top:14px;">
          <label>Payment Due</label>
          <input type="date" name="due_date" value="<?php echo e($editInvoice['due_date']); ?>">
        </div>
        <p class="iv-form-note" style="margin-top:auto; padding-top:14px;">Invoice #<?php echo e($editInvoice['invoice_number']); ?> keeps its number when edited.</p>
      </section>

      <!-- Line items -->
      <section class="tile span-8">
        <div class="tile-head">
          <h2 class="tile-title"><?php echo icon('file'); ?> Line Items</h2>
          <button type="button" class="btn-secondary btn-sm" onclick="addLineItem()"><?php echo icon('plus'); ?> Add line item</button>
        </div>
        <div id="lineItems">
          <?php foreach ($editItems as $item): ?>
          <div class="line-item-row iv-line">
            <div class="form-field"><label>Code</label><input type="text" name="item_code[]" value="<?php echo e($item['service_code'] ?? ''); ?>" placeholder="e.g. ISO9001"></div>
            <div class="form-field"><label>Description *</label><input type="text" name="item_description[]" value="<?php echo e($item['description']); ?>"></div>
            <div class="form-field"><label>Qty</label><input type="number" name="item_qty[]" value="<?php echo (int) $item['quantity']; ?>" min="1"></div>
            <div class="form-field"><label>Unit Price (₦)</label><input type="number" name="item_price[]" step="0.01" value="<?php echo (float) $item['unit_price_ngn']; ?>"></div>
            <div><button type="button" class="btn-ghost iv-line-remove" onclick="removeLineItem(this)" title="Remove row">✕</button></div>
          </div>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- Totals -->
      <section class="tile span-4 flex-col">
        <div class="tile-head"><h2 class="tile-title">Totals &amp; Notes</h2></div>
        <div class="form-grid">
          <div class="form-field"><label>Discount (₦)</label><input type="number" name="discount" value="<?php echo (float) $editInvoice['discount_ngn']; ?>" step="0.01"></div>
          <div class="form-field"><label>VAT Rate (%)</label><input type="number" name="vat_rate" value="<?php echo (float) $editInvoice['vat_rate']; ?>" step="0.01"></div>
          <div class="form-field full"><label>Notes / Payment Terms</label><input type="text" name="notes" value="<?php echo e($editInvoice['notes'] ?? ''); ?>"></div>
        </div>
        <div class="iv-summary" style="margin-top:16px;">
          <div class="iv-sum-row"><span>Subtotal</span><span data-sum="subtotal">—</span></div>
          <div class="iv-sum-row"><span>Discount</span><span data-sum="discount">—</span></div>
          <div class="iv-sum-row"><span>VAT</span><span data-sum="vat">—</span></div>
          <div class="iv-sum-row iv-sum-total"><span>Total</span><span data-sum="total">—</span></div>
        </div>
        <div class="form-actions" style="margin-top:auto; padding-top:16px;">
          <a href="invoices.php?view=<?php echo (int) $editInvoice['id']; ?>" class="btn-secondary">Cancel</a>
          <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Save Changes</button>
        </div>
      </section>
    </div>
  </form>

<?php
// ===========================================================================
// BRANCH C — Create invoice form
// ===========================================================================
elseif ($prefill !== null): ?>

  <form method="POST" class="iv-form">
    <input type="hidden" name="action" value="create_invoice">
    <?php if (!empty($prefill['id'])): ?>
      <input type="hidden" name="proposal_id" value="<?php echo (int) $prefill['id']; ?>">
    <?php endif; ?>
    <?php if (!empty($prefill['lead_id'])): ?>
      <input type="hidden" name="lead_id" value="<?php echo (int) $prefill['lead_id']; ?>">
    <?php endif; ?>
    <?php if (!empty($prefill['client_id'])): ?>
      <input type="hidden" name="client_id" value="<?php echo (int) $prefill['client_id']; ?>">
    <?php endif; ?>

    <div class="bento">
      <section class="tile span-12 pad-sm">
        <div class="iv-toolbar">
          <a href="invoices.php" class="btn-secondary btn-sm iv-back"><?php echo icon('chevron'); ?> Back to list</a>
          <h2 class="tile-title" style="font-size:16px;">
            <?php
              if (!empty($prefill['client_company'])) echo 'Create Invoice — ' . e($prefill['client_company']);
              elseif (!empty($prefill['lead_company'])) echo 'Create Invoice — ' . e($prefill['lead_company']);
              else echo 'New Invoice';
            ?>
          </h2>
          <?php if (!empty($prefill['reference_number'])): ?>
            <span class="badge navy">From <?php echo e($prefill['reference_number']); ?></span>
          <?php endif; ?>
          <span class="iv-form-note" style="margin-left:auto;">Fill in the details below. All amounts in Nigerian Naira (₦).</span>
        </div>
      </section>

      <!-- Bill-to details -->
      <section class="tile span-8">
        <div class="tile-head"><h2 class="tile-title"><?php echo icon('building'); ?> Bill To</h2></div>
        <div class="form-grid">
          <div class="form-field full">
            <label>Company / Organisation Name *</label>
            <input type="text" name="bill_to_name"
              value="<?php echo e($prefill['client_company'] ?? $prefill['lead_company'] ?? ''); ?>"
              required>
          </div>
          <div class="form-field">
            <label>Contact Person</label>
            <input type="text" name="bill_to_contact"
              value="<?php echo e($prefill['client_contact'] ?? ''); ?>"
              placeholder="e.g. John Adeyemi">
          </div>
          <div class="form-field">
            <label>Phone Number</label>
            <input type="tel" name="bill_to_phone"
              value="<?php echo e($prefill['client_phone'] ?? ''); ?>"
              placeholder="e.g. +234 801 234 5678">
          </div>
          <div class="form-field full">
            <label>Email Address</label>
            <input type="email" name="bill_to_email"
              value="<?php echo e($prefill['client_email'] ?? ''); ?>">
          </div>
          <div class="form-field full">
            <label>Address</label>
            <textarea name="bill_to_address" style="min-height:60px;"><?php echo e($prefill['client_address'] ?? ''); ?></textarea>
          </div>
        </div>
      </section>

      <!-- Dates -->
      <section class="tile span-4 flex-col">
        <div class="tile-head"><h2 class="tile-title"><?php echo icon('calendar'); ?> Dates</h2></div>
        <div class="form-field">
          <label>Invoice Date</label>
          <input type="date" name="issue_date" value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="form-field" style="margin-top:14px;">
          <label>Payment Due</label>
          <input type="date" name="due_date" value="<?php echo date('Y-m-d'); ?>">
        </div>
        <p class="iv-form-note" style="margin-top:auto; padding-top:14px;">The invoice number is assigned automatically when you create it.</p>
      </section>

      <!-- Line items -->
      <section class="tile span-8">
        <div class="tile-head">
          <h2 class="tile-title"><?php echo icon('file'); ?> Line Items</h2>
          <button type="button" class="btn-secondary btn-sm" onclick="addLineItem()"><?php echo icon('plus'); ?> Add line item</button>
        </div>
        <div id="lineItems">
          <div class="line-item-row iv-line">
            <div class="form-field"><label>Code</label><input type="text" name="item_code[]" placeholder="e.g. ISO9001"></div>
            <div class="form-field"><label>Description *</label><input type="text" name="item_description[]" value="<?php echo e($prefill['item_description'] ?? ''); ?>"></div>
            <div class="form-field"><label>Qty</label><input type="number" name="item_qty[]" value="1" min="1"></div>
            <div class="form-field"><label>Unit Price (₦)</label><input type="number" name="item_price[]" step="0.01" placeholder="0.00" value="<?php echo ($prefill['value_ngn'] !== null) ? (float) $prefill['value_ngn'] : ''; ?>"></div>
            <div><button type="button" class="btn-ghost iv-line-remove" onclick="removeLineItem(this)" title="Remove row">✕</button></div>
          </div>
        </div>
      </section>

      <!-- Totals -->
      <section class="tile span-4 flex-col">
        <div class="tile-head"><h2 class="tile-title">Totals &amp; Notes</h2></div>
        <div class="form-grid">
          <div class="form-field"><label>Discount (₦)</label><input type="number" name="discount" value="0" step="0.01"></div>
          <div class="form-field"><label>VAT Rate (%)</label><input type="number" name="vat_rate" value="<?php echo e((string) BILLING_DEFAULT_VAT_RATE); ?>" step="0.01"></div>
          <div class="form-field full"><label>Notes / Payment Terms</label><input type="text" name="notes" value="Payment is 100%"></div>
        </div>
        <div class="iv-summary" style="margin-top:16px;">
          <div class="iv-sum-row"><span>Subtotal</span><span data-sum="subtotal">—</span></div>
          <div class="iv-sum-row"><span>Discount</span><span data-sum="discount">—</span></div>
          <div class="iv-sum-row"><span>VAT</span><span data-sum="vat">—</span></div>
          <div class="iv-sum-row iv-sum-total"><span>Total</span><span data-sum="total">—</span></div>
        </div>
        <div class="form-actions" style="margin-top:auto; padding-top:16px;">
          <a href="invoices.php" class="btn-secondary">Cancel</a>
          <button type="submit" class="btn-primary"><?php echo icon('check'); ?> Create Invoice</button>
        </div>
      </section>
    </div>
  </form>

<?php
// ===========================================================================
// BRANCH D — Invoice list
// ===========================================================================
else: ?>

  <div class="bento">
    <section class="tile tile--navy span-6 flex-col">
      <div class="tile-head" style="margin-bottom:0;">
        <span class="tile-label">Awaiting payment</span>
        <div class="tile-icon"><?php echo icon('briefcase'); ?></div>
      </div>
      <div class="tile-value" style="font-size:36px;"><?php echo $ivFmtM($ivAwaitValue); ?></div>
      <div class="tile-sub"><?php echo (int) $ivAwaitCount; ?> invoice<?php echo $ivAwaitCount === 1 ? '' : 's'; ?> sent, part-paid or overdue (invoice totals)</div>
      <div style="flex:1; min-height:14px;"></div>
      <div class="stat-row" style="grid-template-columns:repeat(3,minmax(0,1fr));">
        <div class="stat"><div class="v"><?php echo (int) $ivCount['sent']; ?></div><div class="l">Sent</div></div>
        <div class="stat"><div class="v"><?php echo (int) $ivCount['part-paid']; ?></div><div class="l">Part-paid</div></div>
        <div class="stat"><div class="v"><?php echo (int) $ivCount['overdue']; ?></div><div class="l">Overdue</div></div>
      </div>
    </section>

    <section class="tile span-2 flex-col">
      <div class="tile-head" style="margin-bottom:0;">
        <span class="tile-label">Past due</span>
        <div class="tile-icon"><?php echo icon('alert'); ?></div>
      </div>
      <div class="tile-value"><?php echo (int) $ivPastDue; ?></div>
      <div class="tile-sub">Unpaid after due date</div>
    </section>

    <section class="tile span-2 flex-col">
      <div class="tile-head" style="margin-bottom:0;">
        <span class="tile-label">Paid</span>
        <div class="tile-icon green"><?php echo icon('check'); ?></div>
      </div>
      <div class="tile-value"><?php echo $ivFmtM($ivPaidValue); ?></div>
      <div class="tile-sub"><?php echo (int) $ivCount['paid']; ?> settled in full</div>
    </section>

    <section class="tile span-2 flex-col">
      <div class="tile-head" style="margin-bottom:0;">
        <span class="tile-label">Drafts</span>
        <div class="tile-icon navy"><?php echo icon('edit'); ?></div>
      </div>
      <div class="tile-value"><?php echo (int) $ivCount['draft']; ?></div>
      <div class="tile-sub"><?php echo $ivFmtM($ivDraftValue); ?> not yet sent</div>
    </section>

    <section class="tile span-12">
      <div class="tile-head">
        <h2 class="tile-title"><?php echo icon('briefcase'); ?> All Invoices <span class="badge navy" id="ivCountBadge"><?php echo count($invoices); ?></span></h2>
        <?php if (!empty($invoices)): ?>
        <div class="filter-chips" id="ivFilters">
          <button type="button" class="filter-chip active" onclick="filterInvoices('all', this)">All</button>
          <?php foreach (['draft' => 'Draft', 'sent' => 'Sent', 'part-paid' => 'Part-paid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'cancelled' => 'Cancelled'] as $k => $lbl): if (empty($ivCount[$k])) continue; ?>
            <button type="button" class="filter-chip" onclick="filterInvoices('<?php echo e($k); ?>', this)"><?php echo e($lbl); ?> · <?php echo (int) $ivCount[$k]; ?></button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php if (empty($invoices)): ?>
        <div class="empty-state">
          <div class="icon-button"><?php echo icon('briefcase'); ?></div>
          <p><?php echo $isAuditor
              ? 'No invoices yet. Generate one from a client on your <a href="surveillance-tasks.php" style="color:var(--red-600);">Surveillance Tasks</a> page.'
              : 'No invoices yet. Go to <a href="proposals.php" style="color:var(--red-600);">Proposals</a>, sign a proposal, and the "Create Invoice" link will appear.';
          ?></p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Bill To</th>
                <th>Contact</th>
                <th>Total</th>
                <th>Status</th>
                <th>Issued</th>
                <th>Due</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($invoices as $inv): $late = $ivIsPastDue($inv); ?>
              <tr data-status="<?php echo e($inv['status']); ?>">
                <td>
                  <div class="row-name"><?php echo e($inv['bill_to_name']); ?></div>
                  <div class="row-sub">#<?php echo e($inv['invoice_number']); ?></div>
                </td>
                <td class="row-sub">
                  <?php if (!empty($inv['bill_to_contact'])): ?>
                    <?php echo e($inv['bill_to_contact']); ?>
                    <?php if (!empty($inv['bill_to_phone'])): ?>
                      <br><span style="font-size:11px; color:var(--ink-400);"><?php echo e($inv['bill_to_phone']); ?></span>
                    <?php endif; ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td class="iv-num">&#x20A6;<?php echo number_format($inv['total_ngn'], 2); ?></td>
                <td><span class="badge <?php echo e($statusBadge[$inv['status']] ?? ''); ?>"><?php echo e(ucfirst($inv['status'])); ?></span></td>
                <td style="white-space:nowrap;"><?php echo date('j M Y', strtotime($inv['issue_date'])); ?></td>
                <td style="white-space:nowrap;" class="<?php echo $late ? 'iv-due-late' : ''; ?>"><?php echo date('j M Y', strtotime($inv['due_date'])); ?></td>
                <td style="text-align:right;"><a href="invoices.php?view=<?php echo (int) $inv['id']; ?>" class="btn-secondary btn-sm">View</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="empty-state" id="ivFilterEmpty" style="display:none;"><p>No invoices with this status.</p></div>
      <?php endif; ?>
    </section>
  </div>

  <script>
  function filterInvoices(status, btn){
    document.querySelectorAll('#ivFilters .filter-chip').forEach(function(c){ c.classList.toggle('active', c === btn); });
    var shown = 0;
    document.querySelectorAll('tr[data-status]').forEach(function(tr){
      var on = status === 'all' || tr.dataset.status === status;
      tr.style.display = on ? '' : 'none';
      if (on) shown++;
    });
    document.getElementById('ivCountBadge').textContent = shown;
    document.getElementById('ivFilterEmpty').style.display = shown ? 'none' : 'block';
  }
  </script>

<?php endif; ?>

<!-- Shared JS for line item rows (used by both create and edit forms) -->
<script>
function addLineItem() {
  var container = document.getElementById('lineItems');
  if (!container) return;
  var row = document.createElement('div');
  row.className = 'line-item-row iv-line';
  row.innerHTML =
    '<div class="form-field"><input type="text"   name="item_code[]"        placeholder="Code"></div>' +
    '<div class="form-field"><input type="text"   name="item_description[]" placeholder="Description"></div>' +
    '<div class="form-field"><input type="number" name="item_qty[]"         value="1" min="1"></div>' +
    '<div class="form-field"><input type="number" name="item_price[]"       step="0.01" placeholder="0.00"></div>' +
    '<div><button type="button" class="btn-ghost iv-line-remove" onclick="removeLineItem(this)" title="Remove row">✕</button></div>';
  container.appendChild(row);
  updateInvoiceSummary();
}

function removeLineItem(btn) {
  var row = btn.closest('.line-item-row');
  var container = document.getElementById('lineItems');
  // Keep at least one row so the form stays valid
  if (container && container.querySelectorAll('.line-item-row').length > 1) {
    row.remove();
  }
  updateInvoiceSummary();
}

// Read-only running totals beside the form (mirrors collect_invoice_fields();
// the server still computes the stored figures).
function updateInvoiceSummary() {
  var form = document.querySelector('form.iv-form');
  if (!form) return;
  var num = function (v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; };
  var subtotal = 0;
  form.querySelectorAll('.line-item-row').forEach(function (row) {
    var desc = row.querySelector('[name="item_description[]"]');
    if (!desc || desc.value.trim() === '') return;
    var qty = Math.max(1, parseInt(row.querySelector('[name="item_qty[]"]').value, 10) || 1);
    subtotal += qty * num(row.querySelector('[name="item_price[]"]').value);
  });
  var discount = num(form.querySelector('[name="discount"]').value);
  var vatRate  = num(form.querySelector('[name="vat_rate"]').value);
  var vat      = Math.round((subtotal - discount) * (vatRate / 100) * 100) / 100;
  var total    = Math.round((subtotal - discount + vat) * 100) / 100;
  var fmt = function (n) { return '₦' + n.toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var set = function (k, v) { var el = form.querySelector('[data-sum="' + k + '"]'); if (el) el.textContent = v; };
  set('subtotal', fmt(subtotal)); set('discount', discount ? '−' + fmt(discount) : fmt(0));
  set('vat', fmt(vat) + ' (' + vatRate + '%)'); set('total', fmt(total));
}
(function () {
  var form = document.querySelector('form.iv-form');
  if (!form) return;
  form.addEventListener('input', updateInvoiceSummary);
  updateInvoiceSummary();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
