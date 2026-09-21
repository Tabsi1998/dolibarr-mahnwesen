<?php
/* Mahnwesen - controlled recovery of delivery attempts */
if (!defined('CSRFCHECK_WITH_TOKEN')) { define('CSRFCHECK_WITH_TOKEN', 1); }

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) { $res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php'; }
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i], $tmp2[$j]) && $tmp[$i] === $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, $i + 1).'/main.inc.php')) { $res = @include substr($tmp, 0, $i + 1).'/main.inc.php'; }
if (!$res && file_exists('../main.inc.php')) { $res = @include '../main.inc.php'; }
if (!$res && file_exists('../../main.inc.php')) { $res = @include '../../main.inc.php'; }
if (!$res) { die('Include of main fails'); }

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);
require_once dol_buildpath('/mahnwesen/class/dunningnotice.class.php', 0);
$langs->loadLangs(array('mahnwesen@mahnwesen', 'bills', 'mails'));
if (!isModEnabled('mahnwesen') || !empty($user->socid) || !$user->hasRight('mahnwesen', 'case', 'write') || !$user->hasRight('facture', 'lire')) { accessforbidden(); }

$manager = new DunningManager($db);
$service = new DunningNoticeService($db, $manager);

// Deliver one evidence file of a delivery attempt behind the invoice's access
// check. No "action" parameter: a read-only request needs no CSRF token.
if (GETPOSTISSET('evidence')) {
    $evidence = $manager->getNoticeAttemptFile(GETPOSTINT('evidence'));
    if (!is_array($evidence)) { accessforbidden(); }
    $evidenceInvoice = new Facture($db);
    if ($evidenceInvoice->fetch((int) $evidence['fk_facture']) <= 0) { accessforbidden(); }
    restrictedArea($user, 'facture', $evidenceInvoice->id, '', '', 'fk_soc', 'rowid');
    $evidencePath = $service->getAttemptEvidencePath($evidence, $evidenceInvoice);
    if ($evidencePath === '') { http_response_code(404); print 'Evidence file not found'; exit; }
    $evidenceMime = preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#i', (string) $evidence['mime_type']) ? (string) $evidence['mime_type'] : 'application/octet-stream';
    header('Content-Type: '.$evidenceMime);
    header('Content-Disposition: attachment; filename="'.dol_sanitizeFileName((string) $evidence['display_name']).'"');
    header('Content-Length: '.filesize($evidencePath));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($evidencePath);
    exit;
}

$action = GETPOST('action', 'aZ09');
$attemptId = GETPOSTINT('attempt_id');
if ($action === 'resolve_attempt' && $attemptId > 0) {
    if (!$user->hasRight('mahnwesen', 'notice', 'send')) { accessforbidden(); }
    $resolution = GETPOST('resolution', 'aZ09');
    $reason = trim(GETPOST('reason', 'nohtml'));
    $permissionAttempt = $manager->getNoticeAttempt($attemptId);
    $invoiceId = is_array($permissionAttempt) ? (int) $permissionAttempt['fk_facture'] : 0;
    if ($invoiceId <= 0) { accessforbidden(); }
    $permissionInvoice = new Facture($db);
    if ($permissionInvoice->fetch($invoiceId) <= 0) { accessforbidden(); }
    $result = restrictedArea($user, 'facture', $permissionInvoice->id, '', '', 'fk_soc', 'rowid');
    if ($manager->resolveNoticeAttempt($attemptId, $resolution, $reason, $user)) {
        setEventMessages($langs->trans('MahnwesenAttemptResolved'), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('MahnwesenAttemptResolveFailed'), array($manager->error), 'errors');
    }
    header('Location: '.dol_buildpath('/mahnwesen/attempts.php', 1)); exit;
}
if ($action === 'release_run' && GETPOSTINT('run_id') > 0) {
    if (!$user->hasRight('mahnwesen', 'notice', 'send')) { accessforbidden(); }
    $released = $manager->releaseFailedAttemptsOfRun(GETPOSTINT('run_id'), trim(GETPOST('reason', 'nohtml')), $user);
    if ($released === false) {
        setEventMessages($langs->trans('MahnwesenAttemptResolveFailed'), array($manager->error), 'errors');
    } else {
        setEventMessages($langs->trans('MahnwesenRunAttemptsReleased', $released), $manager->errors, $manager->errors ? 'warnings' : 'mesgs');
    }
    header('Location: '.dol_buildpath('/mahnwesen/attempts.php', 1)); exit;
}
$feeId = GETPOSTINT('fee_id');
if ($action === 'settle_fee' && $feeId > 0) {
    $feeStatus = GETPOST('fee_status', 'aZ09');
    $reason = trim(GETPOST('reason', 'nohtml'));
    $permissionFee = $manager->getFeeClaim($feeId); $invoiceId = is_array($permissionFee) ? (int) $permissionFee['fk_facture'] : 0;
    if ($invoiceId <= 0) { accessforbidden(); }
    $permissionInvoice = new Facture($db); if ($permissionInvoice->fetch($invoiceId) <= 0) { accessforbidden(); }
    $result = restrictedArea($user, 'facture', $permissionInvoice->id, '', '', 'fk_soc', 'rowid');
    if ($manager->settleFeeClaim($feeId, $feeStatus, $reason, $user)) { setEventMessages($langs->trans('MahnwesenFeeSettled'), null, 'mesgs'); }
    else { setEventMessages($langs->trans('MahnwesenFeeSettleFailed'), array($manager->error), 'errors'); }
    header('Location: '.dol_buildpath('/mahnwesen/attempts.php', 1)); exit;
}

$attempts = $manager->getNoticeAttempts(300);
if ($attempts === false) { setEventMessages($langs->trans('MahnwesenAttemptsUnavailable'), null, 'errors'); $attempts = array(); }
$attemptFileMap = $manager->getNoticeAttemptFilesMap(array_column($attempts, 'rowid'));
if ($attemptFileMap === false) { $attemptFileMap = array(); }
$invoiceCache = array();
$visibleSocCache = array();

function mw_attempts_get_invoice($db, $invoiceId, &$cache)
{
    $invoiceId = (int) $invoiceId;
    if (!array_key_exists($invoiceId, $cache)) {
        $invoice = new Facture($db);
        $cache[$invoiceId] = $invoice->fetch($invoiceId) > 0 ? $invoice : false;
    }
    return $cache[$invoiceId];
}

function mw_attempts_can_view_invoice($db, $user, $invoice, &$socCache)
{
    global $manager;
    return $manager->canSeeCustomer($user, (int) $invoice->socid);
}

llxHeader('', $langs->trans('MahnwesenSendAttempts'), '', '', 0, 0, '', array('/mahnwesen/css/mahnwesen.css'), '', 'mod-mahnwesen page-attempts');
print load_fiche_titre($langs->trans('MahnwesenSendAttempts'), '', 'email');
print '<div class="info">'.$langs->trans('MahnwesenAttemptsHelp').'</div><br>';
print '<div class="div-table-responsive"><table class="tagtable liste centpercent">';
print '<tr class="liste_titre"><th>ID</th><th>'.$langs->trans('Invoice').'</th><th>'.$langs->trans('DunningStage').'</th><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('NoticeRecipient').'</th><th class="right">'.$langs->trans('Amount').'</th><th>'.$langs->trans('Status').'</th><th>'.$langs->trans('Action').'</th></tr>';
foreach ($attempts as $attempt) {
    $invoice = mw_attempts_get_invoice($db, (int) $attempt['fk_facture'], $invoiceCache);
    if (!$invoice) { continue; }
    // Apply the same customer/sales-representative scope as the invoice card.
    if (!mw_attempts_can_view_invoice($db, $user, $invoice, $visibleSocCache)) { continue; }
    $invoiceUrl = dol_buildpath('/compta/facture/card.php?facid='.(int) $invoice->id, 1);
    print '<tr class="oddeven"><td>'.((int) $attempt['rowid']).'</td><td><a href="'.dol_escape_htmltag($invoiceUrl).'">'.dol_escape_htmltag($invoice->ref).'</a></td>';
    print '<td>'.$langs->trans($manager->getStageLabelKey((int) $attempt['level'])).'</td><td>'.dol_print_date($db->jdate($attempt['reserved_at']), 'dayhour').'</td>';
    print '<td>'.dol_escape_htmltag($attempt['recipient']).'</td><td class="right">'.price((float) $attempt['amount_total'], 0, $langs, 1, -1, -1, $attempt['currency_code']).'</td>';
    print '<td>'.dol_escape_htmltag($attempt['status']).'</td><td>';
    $attemptIsStale = in_array($attempt['status'], array('reserved', 'sending'), true) && ((int) $db->jdate($attempt['reserved_at']) <= dol_now() - 900);
    if ($user->hasRight('mahnwesen', 'notice', 'send') && (in_array($attempt['status'], array('ambiguous', 'failed'), true) || $attemptIsStale)) {
        print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
        print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="resolve_attempt"><input type="hidden" name="attempt_id" value="'.((int) $attempt['rowid']).'">';
        print '<select name="resolution">';
        if (in_array($attempt['status'], array('sending', 'ambiguous'), true)) { print '<option value="confirmed_sent">'.$langs->trans('MahnwesenConfirmDelivered').'</option>'; }
        print '<option value="allow_retry">'.$langs->trans('MahnwesenAllowRetry').'</option></select> ';
        print '<input type="text" required name="reason" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('Reason')).'"> ';
        print '<button class="button" type="submit">'.$langs->trans('Confirm').'</button></form>';
    } else { print '-'; }
    print '</td></tr>';
    $attemptFiles = isset($attemptFileMap[(int) $attempt['rowid']]) ? $attemptFileMap[(int) $attempt['rowid']] : array();
    print '<tr class="oddeven"><td></td><td colspan="7"><details><summary>'.$langs->trans('MahnwesenAttemptDetails').'</summary>';
    print '<table class="border centpercent tableforfield margintoponly">';
    print '<tr><td class="titlefield">'.$langs->trans('From').'</td><td>'.dol_escape_htmltag((string) $attempt['sender']).'</td></tr>';
    print '<tr><td>'.$langs->trans('Subject').'</td><td>'.dol_escape_htmltag((string) $attempt['subject']).'</td></tr>';
    print '<tr><td>'.$langs->trans('Message').'</td><td><details><summary>'.$langs->trans('Show').'</summary><pre class="small">'.dol_escape_htmltag((string) $attempt['body_html']).'</pre></details></td></tr>';
    if (!empty($attempt['cc'])) { print '<tr><td>CC</td><td>'.dol_escape_htmltag((string) $attempt['cc']).'</td></tr>'; }
    if (!empty($attempt['bcc'])) { print '<tr><td>BCC</td><td>'.dol_escape_htmltag((string) $attempt['bcc']).'</td></tr>'; }
    print '<tr><td>'.$langs->trans('MahnwesenTemplateAudit').'</td><td>#'.((int) $attempt['fk_email_template']).' / '.dol_escape_htmltag((string) $attempt['template_lang']).' / '.dol_escape_htmltag((string) $attempt['mode']).'</td></tr>';
    if (!empty($attempt['mail_message_id'])) { print '<tr><td>Message-ID</td><td><code>'.dol_escape_htmltag((string) $attempt['mail_message_id']).'</code></td></tr>'; }
    if (!empty($attempt['pdf_sha256'])) { print '<tr><td>'.$langs->trans('MahnwesenDunningPdfHash').'</td><td><code>'.dol_escape_htmltag((string) $attempt['pdf_sha256']).'</code></td></tr>'; }
    if (!empty($attempt['invoice_pdf_sha256'])) { print '<tr><td>'.$langs->trans('MahnwesenInvoicePdfHash').'</td><td><code>'.dol_escape_htmltag((string) $attempt['invoice_pdf_sha256']).'</code></td></tr>'; }
    if (!empty($attempt['error_message'])) { print '<tr><td>'.$langs->trans('Error').'</td><td>'.nl2br(dol_escape_htmltag((string) $attempt['error_message'])).'</td></tr>'; }
    print '</table>';
    if (is_array($attemptFiles) && !empty($attemptFiles)) {
        print '<div class="div-table-responsive margintoponly"><table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('MahnwesenAttachmentRole').'</th><th>'.$langs->trans('File').'</th><th class="right">'.$langs->trans('Size').'</th><th>SHA-256</th></tr>';
        $roleKey = array('dunning' => 'MahnwesenAttachmentRoleDunning', 'invoice' => 'MahnwesenAttachmentRoleInvoice', 'additional' => 'MahnwesenAttachmentRoleAdditional');
        foreach ($attemptFiles as $attemptFile) {
            $role = isset($roleKey[$attemptFile['file_role']]) ? $langs->trans($roleKey[$attemptFile['file_role']]) : (string) $attemptFile['file_role'];
            $evidenceUrl = dol_buildpath('/mahnwesen/attempts.php?evidence='.((int) $attemptFile['rowid']), 1);
            print '<tr class="oddeven"><td>'.dol_escape_htmltag($role).'</td><td><a href="'.dol_escape_htmltag($evidenceUrl).'">'.img_mime((string) $attemptFile['display_name']).' '.dol_escape_htmltag((string) $attemptFile['display_name']).'</a></td><td class="right">'.dol_print_size((int) $attemptFile['size_bytes']).'</td><td><code>'.dol_escape_htmltag((string) $attemptFile['sha256']).'</code></td></tr>';
        }
        print '</table></div>';
    }
    print '</details></td></tr>';
}
print '</table></div>';

$fees = $manager->getFeeClaims('', 300);
print '<br>'.load_fiche_titre($langs->trans('MahnwesenFeeLedger'), '', 'payment');
print '<div class="info">'.$langs->trans('MahnwesenFeeLedgerHelp').'</div><br>';
print '<div class="div-table-responsive"><table class="tagtable liste centpercent">';
print '<tr class="liste_titre"><th>ID</th><th>'.$langs->trans('Invoice').'</th><th>'.$langs->trans('DunningStage').'</th><th>'.$langs->trans('Date').'</th><th class="right">'.$langs->trans('Amount').'</th><th>'.$langs->trans('Status').'</th><th>'.$langs->trans('Action').'</th></tr>';
foreach ((array) $fees as $fee) {
    $invoice = mw_attempts_get_invoice($db, (int) $fee['fk_facture'], $invoiceCache); if (!$invoice) { continue; }
    if (!mw_attempts_can_view_invoice($db, $user, $invoice, $visibleSocCache)) { continue; }
    print '<tr class="oddeven"><td>'.((int) $fee['rowid']).'</td><td>'.$invoice->getNomUrl(1).'</td><td>'.$langs->trans($manager->getStageLabelKey((int) $fee['level'])).'</td><td>'.dol_print_date($db->jdate($fee['date_creation']), 'dayhour').'</td><td class="right">'.price((float) $fee['amount'], 0, $langs, 1, -1, -1, $fee['currency_code']).'</td><td>'.dol_escape_htmltag($fee['status']).'</td><td>';
    if ($fee['status'] === 'open') {
        print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="settle_fee"><input type="hidden" name="fee_id" value="'.((int) $fee['rowid']).'">';
        print '<select name="fee_status"><option value="paid">'.$langs->trans('MahnwesenFeePaid').'</option><option value="waived">'.$langs->trans('MahnwesenFeeWaived').'</option></select> <input type="text" required name="reason" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('Reason')).'"> <button class="button" type="submit">'.$langs->trans('Confirm').'</button></form>';
    } else { print dol_escape_htmltag($fee['settlement_reason']); }
    print '</td></tr>';
}
print '</table></div>';

$runs = $manager->getAutomationRuns(100);
print '<br>'.load_fiche_titre($langs->trans('MahnwesenAutomationHistory'), '', 'technic');
print '<div class="info">'.$langs->trans('MahnwesenAutomationHistoryHelp').'</div><br>';
print '<div class="div-table-responsive"><table class="tagtable liste centpercent">';
print '<tr class="liste_titre"><th>ID</th><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('Mode').'</th><th>'.$langs->trans('Status').'</th><th class="right">'.$langs->trans('MahnwesenScanned').'</th><th class="right">'.$langs->trans('MahnwesenSynchronized').'</th><th class="right">'.$langs->trans('MahnwesenAttempted').'</th><th class="right">'.$langs->trans('MahnwesenSent').'</th><th class="right">'.$langs->trans('MahnwesenSkipped').'</th><th class="right">'.$langs->trans('MahnwesenFailed').'</th></tr>';
foreach ((array) $runs as $run) {
    print '<tr class="oddeven"><td>'.((int) $run['rowid']).'</td><td>'.dol_print_date($db->jdate($run['started_at']), 'dayhour').'</td><td>'.dol_escape_htmltag((string) $run['mode']).'</td><td>'.dol_escape_htmltag((string) $run['status']).'</td><td class="right">'.((int) $run['scanned']).'</td><td class="right">'.((int) $run['synchronized']).'</td><td class="right">'.((int) $run['attempted']).'</td><td class="right">'.((int) $run['sent']).'</td><td class="right">'.((int) $run['skipped']).'</td><td class="right">'.((int) $run['failed']).'</td></tr>';
    if (!empty($run['summary'])) { print '<tr class="oddeven"><td></td><td colspan="9" class="opacitymedium">'.dol_escape_htmltag((string) $run['summary']).'</td></tr>'; }
    // After a mail server outage: send the failed attempts of this run again (#21).
    if ($run['mode'] === 'cron' && (int) $run['failed'] > 0 && $user->hasRight('mahnwesen', 'notice', 'send')) {
        print '<tr class="oddeven"><td></td><td colspan="9"><form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
        print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="release_run"><input type="hidden" name="run_id" value="'.((int) $run['rowid']).'">';
        print '<input type="text" required name="reason" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('Reason')).'"> ';
        print '<button class="button smallpaddingimp" type="submit">'.$langs->trans('MahnwesenReleaseRunAttempts').'</button> <span class="opacitymedium">'.$langs->trans('MahnwesenReleaseRunAttemptsHelp').'</span></form></td></tr>';
    }
}
print '</table></div>';

$history = $manager->getRecentHistory(300);
print '<br>'.load_fiche_titre($langs->trans('MahnwesenCompleteHistory'), '', 'history');
print '<div class="info">'.$langs->trans('MahnwesenCompleteHistoryHelp').'</div><br>';
print '<div class="div-table-responsive"><table class="tagtable liste centpercent">';
print '<tr class="liste_titre"><th>ID</th><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('Invoice').'</th><th>'.$langs->trans('Action').'</th><th>'.$langs->trans('DunningStage').'</th><th>'.$langs->trans('Mode').'</th><th>'.$langs->trans('MahnwesenHistoryResult').'</th><th>'.$langs->trans('NoticeRecipient').'</th><th>'.$langs->trans('Details').'</th></tr>';
foreach ((array) $history as $event) {
    $historyInvoiceId = (int) $event['fk_facture'];
    $historyInvoice = mw_attempts_get_invoice($db, $historyInvoiceId, $invoiceCache);
    if (!$historyInvoice) { continue; }
    if (!mw_attempts_can_view_invoice($db, $user, $historyInvoice, $visibleSocCache)) { continue; }
    print '<tr class="oddeven"><td>'.((int) $event['rowid']).'</td><td>'.dol_print_date($db->jdate($event['date_creation']), 'dayhour').'</td><td>'.$historyInvoice->getNomUrl(1).'</td><td>'.$langs->trans($manager->getHistoryActionLabelKey((string) $event['action'])).'</td><td>'.((int) $event['level'] > 0 ? $langs->trans($manager->getStageLabelKey((int) $event['level'])) : '-').'</td><td>'.dol_escape_htmltag((string) $event['mode']).'</td><td>'.dol_escape_htmltag((string) $event['result']).'</td><td>'.dol_escape_htmltag((string) $event['recipient']).'</td><td><details><summary>'.$langs->trans('Show').'</summary>'.nl2br(dol_escape_htmltag((string) $event['message'])).'</details></td></tr>';
}
print '</table></div>';
llxFooter();
$db->close();
