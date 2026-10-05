<?php
/**
 * IEMA CRMOps — Proposal Document Renderer
 * -----------------------------------------------------------------------
 * Renders a proposal as a letterhead-branded HTML document — same visual
 * language as render_invoice_html() in invoices.php (logo, brand red,
 * BILLING_ORG_* letterhead details) so proposals and invoices look like
 * they come from the same organisation.
 *
 * Used two ways, exactly like invoices:
 *   - $forEmail = false: wrapped in a <div class="panel"> for in-app
 *     preview (proposals.php?view=ID), with a Print/Save PDF button.
 *   - $forEmail = true: used directly as the HTML body of the "Send to
 *     Client" email (proposal-send.php) — the client receives the actual
 *     branded document in their inbox, not just a link to one.
 *
 * $p must include: reference_number, company_name, contact_name, sector,
 * schemes_text, value_ngn, created_at, scope_of_work.
 * -----------------------------------------------------------------------
 */

function render_proposal_html(array $p, bool $forEmail = false): string
{
    $containerStyle = $forEmail
        ? 'max-width:760px;margin:0 auto;font-family:Arial,sans-serif;'
        : 'max-width:760px;margin:0 auto;font-family:Arial,sans-serif;background:#fff;padding:40px;';

    $scopeText = trim((string) ($p['scope_of_work'] ?? ''));
    $scopeHtml = $scopeText !== ''
        ? nl2br(e($scopeText))
        : '<span style="color:#999;">Scope of work not yet added — edit this proposal to add it before sending.</span>';

    $schemesLine = !empty($p['schemes_text'])
        ? '<b>Certification Scheme(s):</b>&nbsp;&nbsp;' . e($p['schemes_text']) . '<br>'
        : '';

    return '<div style="' . $containerStyle . '">'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:top;"><img src="' . e(ORG_WEBSITE_BASE_URL) . '/assets/logo.png" style="height:60px;" alt="' . e(BILLING_ORG_NAME) . '"></td>'
        . '<td style="text-align:right;vertical-align:top;"><h1 style="font-family:Arial,sans-serif;font-weight:300;letter-spacing:4px;margin:0;font-size:32px;">PROPOSAL</h1></td>'
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
        . '<div style="color:#999;font-size:11px;letter-spacing:1px;margin-bottom:6px;">PREPARED FOR</div>'
        . '<b>' . e($p['company_name'] ?? '') . '</b><br>'
        . ($p['contact_name'] ? e($p['contact_name']) . '<br>' : '')
        . ($p['sector'] ? e($p['sector']) . '<br>' : '')
        . '</td>'
        . '<td style="text-align:right;vertical-align:top;font-size:13px;line-height:1.8;">'
        . '<b>Reference:</b>&nbsp;&nbsp;' . e($p['reference_number'] ?? '') . '<br>'
        . '<b>Date Issued:</b>&nbsp;&nbsp;' . date('F j, Y', strtotime($p['created_at'] ?? 'now')) . '<br>'
        . $schemesLine
        . '<b>Valid For:</b>&nbsp;&nbsp;30 days from date of issue<br>'
        . '</td>'
        . '</tr></table>'
        . '<div style="margin-top:28px;">'
        . '<div style="font-family:Arial,sans-serif;font-weight:700;font-size:14px;color:#191B20;margin-bottom:10px;border-bottom:2px solid #C8102E;display:inline-block;padding-bottom:4px;">Scope of Work</div>'
        . '<div style="font-size:13.5px;line-height:1.7;color:#333;margin-top:10px;">' . $scopeHtml . '</div>'
        . '</div>'
        . '<div style="background:#FDECEC;border-radius:12px;padding:22px;margin-top:28px;text-align:center;">'
        . '<div style="font-size:12px;color:#7A0C1E;margin-bottom:4px;letter-spacing:0.5px;">PROPOSED VALUE</div>'
        . '<div style="font-size:32px;font-weight:800;color:#A30F26;">&#x20A6;' . number_format((float) ($p['value_ngn'] ?? 0), 2) . '</div>'
        . '<div style="font-size:11px;color:#7A0C1E;margin-top:4px;">Nigerian Naira (NGN)</div>'
        . '</div>'
        . '<div style="margin-top:24px;font-size:11.5px;color:#999;">'
        . 'This proposal is prepared based on the information available to us at the time of issue. '
        . 'Please contact us with any questions or to proceed.'
        . '</div>'
        . '</div>';
}