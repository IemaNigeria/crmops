<?php
/**
 * IEMA CRMOps — Invoice & Receipt Document Renderer
 * -----------------------------------------------------------------------
 * Shared by invoices.php (in-app preview, "Send to Client"), and the
 * receipt preview/send flow (receipt-preview.php, receipt-send.php) so
 * both pages render the exact same branded documents from one place
 * instead of duplicating markup.
 *
 * Updated: added bill_to_contact and bill_to_phone to both renderers.
 *   - bill_to_contact: the contact person at the client organisation
 *   - bill_to_phone:   their phone number
 * Both fields are optional — the renderer skips them cleanly if empty.
 * -----------------------------------------------------------------------
 */

/** Renders a payment receipt as HTML — same style as the invoice but says RECEIPT */
function render_receipt_html(array $invoice, float $amountPaid, float $totalPaid, float $outstanding, string $paymentDate, string $reference): string
{
    $fullyPaid = $outstanding <= 0;

    // Build the "RECEIVED FROM" block — company name, then optional contact
    // person + phone on separate lines, then email.
    $receivedFromBlock = '<b>' . e($invoice['bill_to_name']) . '</b>';
    if (!empty($invoice['bill_to_contact'])) {
        $receivedFromBlock .= '<br>' . e($invoice['bill_to_contact']);
    }
    if (!empty($invoice['bill_to_phone'])) {
        $receivedFromBlock .= '<br>' . e($invoice['bill_to_phone']);
    }
    if (!empty($invoice['bill_to_email'])) {
        $receivedFromBlock .= '<br>' . e($invoice['bill_to_email']);
    }

    return '<div style="max-width:760px;margin:0 auto;font-family:Arial,sans-serif;background:#fff;padding:40px;">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:top;"><img src="' . e(ORG_WEBSITE_BASE_URL) . '/assets/logo.png" style="height:60px;" alt="' . e(BILLING_ORG_NAME) . '"></td>'
        . '<td style="text-align:right;vertical-align:top;">'
        . '<h1 style="font-family:Arial,sans-serif;font-weight:300;letter-spacing:4px;margin:0;font-size:36px;">RECEIPT</h1>'
        . ($fullyPaid
            ? '<div style="color:#065F46;font-weight:700;font-size:13px;margin-top:4px;">✓ PAYMENT COMPLETE</div>'
            : '<div style="color:#92400E;font-weight:700;font-size:13px;margin-top:4px;">PART PAYMENT</div>')
        . '</td></tr></table>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;"><tr><td style="text-align:right;font-size:13px;color:#444;">'
        . '<b>' . e(BILLING_ORG_NAME) . '</b><br>' . nl2br(e(BILLING_ORG_ADDRESS)) . '<br><br>'
        . e(BILLING_ORG_PHONE) . '<br>' . e(BILLING_ORG_WEBSITE)
        . '</td></tr></table>'
        . '<hr style="border:none;border-top:1px solid #ddd;margin:20px 0;">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:top;font-size:13px;">'
        . '<div style="color:#999;font-size:11px;letter-spacing:1px;margin-bottom:6px;">RECEIVED FROM</div>'
        . $receivedFromBlock
        . '</td>'
        . '<td style="text-align:right;vertical-align:top;font-size:13px;line-height:1.8;">'
        . '<b>Invoice Number:</b>&nbsp;&nbsp;' . e($invoice['invoice_number']) . '<br>'
        . '<b>Payment Date:</b>&nbsp;&nbsp;' . date('F j, Y', strtotime($paymentDate)) . '<br>'
        . ($reference ? '<b>Reference:</b>&nbsp;&nbsp;' . e($reference) . '<br>' : '')
        . '</td></tr></table>'
        . '<div style="background:' . ($fullyPaid ? '#ECFDF5' : '#FFF7ED') . ';border-radius:12px;padding:24px;margin-top:24px;text-align:center;">'
        . '<div style="font-size:13px;color:#6B7280;margin-bottom:4px;">Amount Received</div>'
        . '<div style="font-size:36px;font-weight:800;color:' . ($fullyPaid ? '#065F46' : '#92400E') . ';">&#x20A6;' . number_format($amountPaid, 2) . '</div>'
        . '</div>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;font-size:13px;">'
        . '<tr><td style="padding:6px 0;border-bottom:1px solid #eee;">Invoice Total:</td><td style="text-align:right;padding:6px 0;border-bottom:1px solid #eee;">&#x20A6;' . number_format($invoice['total_ngn'], 2) . '</td></tr>'
        . '<tr><td style="padding:6px 0;border-bottom:1px solid #eee;">This Payment:</td><td style="text-align:right;padding:6px 0;border-bottom:1px solid #eee;color:#065F46;font-weight:700;">&#x20A6;' . number_format($amountPaid, 2) . '</td></tr>'
        . '<tr><td style="padding:6px 0;border-bottom:1px solid #eee;">Total Paid to Date:</td><td style="text-align:right;padding:6px 0;border-bottom:1px solid #eee;">&#x20A6;' . number_format($totalPaid, 2) . '</td></tr>'
        . '<tr><td style="padding:8px 0;"><b>' . ($fullyPaid ? 'Balance Remaining:' : 'Outstanding Balance:') . '</b></td><td style="text-align:right;padding:8px 0;font-weight:700;color:' . ($fullyPaid ? '#065F46' : '#991B1B') . ';">&#x20A6;' . number_format(max(0, $outstanding), 2) . '</td></tr>'
        . '</table>'
        . '<div style="margin-top:20px;font-size:12px;color:#999;">' . e(BILLING_PAYMENT_ACCOUNT) . '</div>'
        . '</div>';
}

/** Renders the invoice as HTML matching the Wave template layout. */
function render_invoice_html(array $invoice, array $items, bool $forEmail = false): string
{
    $rows = '';
    foreach ($items as $item) {
        $rows .= '<tr>'
            . '<td style="padding:12px 8px;border-bottom:1px solid #eee;vertical-align:top;">'
            . ($item['service_code'] ? '<b>' . e($item['service_code']) . '</b><br>' : '')
            . nl2br(e($item['description']))
            . '</td>'
            . '<td style="padding:12px 8px;border-bottom:1px solid #eee;text-align:center;">' . (int) $item['quantity'] . '</td>'
            . '<td style="padding:12px 8px;border-bottom:1px solid #eee;text-align:right;">&#x20A6;' . number_format($item['unit_price_ngn'], 2) . '</td>'
            . '<td style="padding:12px 8px;border-bottom:1px solid #eee;text-align:right;">&#x20A6;' . number_format($item['amount_ngn'], 2) . '</td>'
            . '</tr>';
    }

    $containerStyle = $forEmail
        ? 'max-width:760px;margin:0 auto;font-family:Arial,sans-serif;'
        : 'max-width:760px;margin:0 auto;font-family:Arial,sans-serif;background:#fff;padding:40px;';

    $discountRow = $invoice['discount_ngn'] > 0
        ? '<tr><td style="padding:4px 0;">Discount:</td><td style="text-align:right;">(&#x20A6;' . number_format($invoice['discount_ngn'], 2) . ')</td></tr>'
        : '';

    $vatLabel = rtrim(rtrim(number_format($invoice['vat_rate'], 2), '0'), '.');

    // Build the "BILL TO" block — company name first, then optional contact
    // person and phone on their own lines, then address, then email.
    // Each piece is only rendered if it is not empty so the document stays
    // clean even for older invoices that pre-date these fields.
    $billToBlock = '<b>' . e($invoice['bill_to_name']) . '</b>';

    if (!empty($invoice['bill_to_contact'])) {
        $billToBlock .= '<br>' . e($invoice['bill_to_contact']);
    }
    if (!empty($invoice['bill_to_phone'])) {
        $billToBlock .= '<br>' . e($invoice['bill_to_phone']);
    }
    if (!empty($invoice['bill_to_address'])) {
        // nl2br so multi-line addresses render correctly
        $billToBlock .= '<br>' . nl2br(e($invoice['bill_to_address']));
    }
    if (!empty($invoice['bill_to_email'])) {
        $billToBlock .= '<br><br>' . e($invoice['bill_to_email']);
    }

    return '<div style="' . $containerStyle . '">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:top;"><img src="' . e(ORG_WEBSITE_BASE_URL) . '/assets/logo.png" style="height:60px;" alt="' . e(BILLING_ORG_NAME) . '"></td>'
        . '<td style="text-align:right;vertical-align:top;"><h1 style="font-family:Arial,sans-serif;font-weight:300;letter-spacing:4px;margin:0;font-size:36px;">INVOICE</h1></td>'
        . '</tr></table>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;"><tr><td style="text-align:right;font-size:13px;color:#444;">'
        . '<b>' . e(BILLING_ORG_NAME) . '</b><br>'
        . nl2br(e(BILLING_ORG_ADDRESS)) . '<br><br>'
        . e(BILLING_ORG_PHONE) . '<br>'
        . e(BILLING_ORG_WEBSITE)
        . '</td></tr></table>'
        . '<hr style="border:none;border-top:1px solid #ddd;margin:20px 0;">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:top;font-size:13px;">'
        . '<div style="color:#999;font-size:11px;letter-spacing:1px;margin-bottom:6px;">BILL TO</div>'
        . $billToBlock
        . '</td>'
        . '<td style="text-align:right;vertical-align:top;font-size:13px;line-height:1.8;">'
        . '<b>Invoice Number:</b>&nbsp;&nbsp;' . e($invoice['invoice_number']) . '<br>'
        . '<b>Invoice Date:</b>&nbsp;&nbsp;' . date('F j, Y', strtotime($invoice['issue_date'])) . '<br>'
        . '<b>Payment Due:</b>&nbsp;&nbsp;' . date('F j, Y', strtotime($invoice['due_date'])) . '<br>'
        . '<b>Amount Due (NGN):</b>&nbsp;&nbsp;<b>&#x20A6;' . number_format($invoice['total_ngn'], 2) . '</b>'
        . '</td>'
        . '</tr></table>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px;border-collapse:collapse;">'
        . '<tr style="background:#C8102E;color:#fff;">'
        . '<th style="padding:10px 8px;text-align:left;">Service</th>'
        . '<th style="padding:10px 8px;text-align:center;">Quantity</th>'
        . '<th style="padding:10px 8px;text-align:right;">Price</th>'
        . '<th style="padding:10px 8px;text-align:right;">Amount</th>'
        . '</tr>'
        . $rows
        . '</table>'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;">'
        . '<tr><td></td><td style="width:260px;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">'
        . '<tr><td style="padding:4px 0;">Subtotal:</td><td style="text-align:right;">&#x20A6;' . number_format($invoice['subtotal_ngn'], 2) . '</td></tr>'
        . $discountRow
        . '<tr><td style="padding:4px 0;">VAT/ ' . $vatLabel . '%:</td><td style="text-align:right;">&#x20A6;' . number_format($invoice['vat_amount_ngn'], 2) . '</td></tr>'
        . '<tr><td style="padding:8px 0 4px;border-top:1px solid #ddd;"><b>Total:</b></td><td style="text-align:right;padding:8px 0 4px;border-top:1px solid #ddd;"><b>&#x20A6;' . number_format($invoice['total_ngn'], 2) . '</b></td></tr>'
        . '<tr><td style="padding:4px 0;"><b>Amount Due (NGN):</b></td><td style="text-align:right;"><b>&#x20A6;' . number_format($invoice['total_ngn'], 2) . '</b></td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '<div style="margin-top:30px;font-size:12px;color:#666;"><b>Notes / Terms</b><br>' . e($invoice['notes'] ?? '') . '</div>'
        . '<div style="margin-top:10px;font-size:11px;color:#999;">' . e($invoice['payment_account_note'] ?? '') . '</div>'
        . '</div>';
}