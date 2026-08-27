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
$langs->loadLangs(array('mahnwesen@mahnwesen', 'bills', 'mails'));
if (!isModEnabled('mahnwesen') || !empty($user->socid) || !$user->hasRight('mahnwesen', 'case', 'write') || !$user->hasRight('facture', 'lire')) { accessforbidden(); }

$manager = new DunningManager($db);
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
    $result = restrictedArea($user, 'facture', $permissionInvoice->id, 'facture', 'facture');
    if ($manager->resolveNoticeAttempt($attemptId, $resolution, $reason, $user)) {
        setEventMessages($langs->trans('MahnwesenAttemptResolved'), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('MahnwesenAttemptResolveFailed'), null, 'errors');
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
    $result = restrictedArea($user, 'facture', $permissionInvoice->id, 'facture', 'facture');
    if ($manager->settleFeeClaim($feeId, $feeStatus, $reason, $user)) { setEventMessages($langs->trans('MahnwesenFeeSettled'), null, 'mesgs'); }
    else { setEventMessages($langs->trans('MahnwesenFeeSettleFailed'), null, 'errors'); }
    header('Location: '.dol_buildpath('/mahnwesen/attempts.php', 1)); exit;
}

$attempts = $manager->getNoticeAttempts(300);
if ($attempts === false) { setEventMessages($langs->trans('MahnwesenAttemptsUnavailable'), null, 'errors'); $attempts = array(); }

llxHeader('', $langs->trans('MahnwesenSendAttempts'), '', '', 0, 0, '', '', '', 'mod-mahnwesen page-attempts');
print load_fiche_titre($langs->trans('MahnwesenSendAttempts'), '', 'email');
print '<div class="info">'.$langs->trans('MahnwesenAttemptsHelp').'</div><br>';
print '<div class="div-table-responsive"><table class="tagtable liste centpercent">';
print '<tr class="liste_titre"><th>ID</th><th>'.$langs->trans('Invoice').'</th><th>'.$langs->trans('DunningStage').'</th><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('NoticeRecipient').'</th><th class="right">'.$langs->trans('Amount').'</th><th>'.$langs->trans('Status').'</th><th>'.$langs->trans('Action').'</th></tr>';
foreach ($attempts as $attempt) {
    $invoice = new Facture($db);
    if ($invoice->fetch((int) $attempt['fk_facture']) <= 0) { continue; }
    // Apply the same customer/sales-representative scope as the invoice card.
    if (!$user->hasRight('societe', 'client', 'voir')) {
        $sqlAccess = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'societe_commerciaux WHERE fk_soc = '.((int) $invoice->socid).' AND fk_user = '.((int) $user->id).$db->plimit(1);
        $resAccess = $db->query($sqlAccess); $allowed = $resAccess && $db->fetch_object($resAccess); if ($resAccess) { $db->free($resAccess); }
        if (!$allowed) { continue; }
    }
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
}
print '</table></div>';

$fees = $manager->getFeeClaims('', 300);
print '<br>'.load_fiche_titre($langs->trans('MahnwesenFeeLedger'), '', 'payment');
print '<div class="info">'.$langs->trans('MahnwesenFeeLedgerHelp').'</div><br>';
print '<div class="div-table-responsive"><table class="tagtable liste centpercent">';
print '<tr class="liste_titre"><th>ID</th><th>'.$langs->trans('Invoice').'</th><th>'.$langs->trans('DunningStage').'</th><th>'.$langs->trans('Date').'</th><th class="right">'.$langs->trans('Amount').'</th><th>'.$langs->trans('Status').'</th><th>'.$langs->trans('Action').'</th></tr>';
foreach ((array) $fees as $fee) {
    $invoice = new Facture($db); if ($invoice->fetch((int) $fee['fk_facture']) <= 0) { continue; }
    if (!$user->hasRight('societe', 'client', 'voir')) {
        $sqlAccess = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'societe_commerciaux WHERE fk_soc = '.((int) $invoice->socid).' AND fk_user = '.((int) $user->id).$db->plimit(1);
        $resAccess = $db->query($sqlAccess); $allowed = $resAccess && $db->fetch_object($resAccess); if ($resAccess) { $db->free($resAccess); } if (!$allowed) { continue; }
    }
    print '<tr class="oddeven"><td>'.((int) $fee['rowid']).'</td><td>'.$invoice->getNomUrl(1).'</td><td>'.$langs->trans($manager->getStageLabelKey((int) $fee['level'])).'</td><td>'.dol_print_date($db->jdate($fee['date_creation']), 'dayhour').'</td><td class="right">'.price((float) $fee['amount'], 0, $langs, 1, -1, -1, $fee['currency_code']).'</td><td>'.dol_escape_htmltag($fee['status']).'</td><td>';
    if ($fee['status'] === 'open') {
        print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="settle_fee"><input type="hidden" name="fee_id" value="'.((int) $fee['rowid']).'">';
        print '<select name="fee_status"><option value="paid">'.$langs->trans('MahnwesenFeePaid').'</option><option value="waived">'.$langs->trans('MahnwesenFeeWaived').'</option></select> <input type="text" required name="reason" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('Reason')).'"> <button class="button" type="submit">'.$langs->trans('Confirm').'</button></form>';
    } else { print dol_escape_htmltag($fee['settlement_reason']); }
    print '</td></tr>';
}
print '</table></div>';
llxFooter();
$db->close();
