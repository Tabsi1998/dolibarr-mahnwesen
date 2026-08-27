<?php
/*
 * Mahnwesen - Dolibarr custom module
 * GPL-3.0-or-later
 */

// Load Dolibarr environment using the same resilient strategy as ModuleBuilder.
// Force Dolibarr CSRF token validation for all POST actions on this page.
if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', 1);
}

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] === $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, $i + 1).'/main.inc.php')) {
    $res = @include substr($tmp, 0, $i + 1).'/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, $i + 1)).'/main.inc.php')) {
    $res = @include dirname(substr($tmp, 0, $i + 1)).'/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
    $res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
    $res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
    $res = @include '../../../main.inc.php';
}
if (!$res) {
    die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);
require_once dol_buildpath('/mahnwesen/class/dunningnotice.class.php', 0);

$langs->loadLangs(array('mahnwesen@mahnwesen', 'bills', 'companies'));

if (!isModEnabled('mahnwesen')) {
    accessforbidden('Module not enabled');
}
if (!empty($user->socid)) {
    accessforbidden();
}
if (!$user->hasRight('mahnwesen', 'dashboard', 'read')) {
    accessforbidden();
}
if (!$user->hasRight('facture', 'lire')) {
    accessforbidden();
}

$manager = new DunningManager($db);
$maxScan = $manager->getMaxScan();
$action = GETPOST('action', 'aZ09');
$facid = GETPOSTINT('facid');

if ($action === 'sync_cases') {
    if (!$user->hasRight('mahnwesen', 'case', 'write')) {
        accessforbidden();
    }
    try {
        $summary = $manager->syncCases($user, $maxScan);
        if ($summary === false) {
            $messages = !empty($manager->errors) ? $manager->errors : array($manager->error ?: $langs->trans('SyncFailedUnknown'));
            setEventMessages($manager->error, $messages, 'errors');
        } else {
            setEventMessages($langs->transnoentities('SyncSummary', (int) $summary['created'], (int) $summary['level_changed'], (int) $summary['updated'], (int) $summary['closed'], (int) $summary['unchanged']), null, 'mesgs');
            if (!empty($summary['errors'])) {
                setEventMessages('', $manager->errors, 'warnings');
            }
        }
    } catch (Throwable $e) {
        // Never expose a raw PHP 500 for a controlled manual operation.
        // Keep the technical message concise and log the full exception server-side.
        try {
            $db->rollback();
        } catch (Throwable $ignored) {
            // Nothing else to do here.
        }
        dol_syslog('Mahnwesen sync fatal: '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine(), LOG_ERR);
        setEventMessages($langs->trans('SyncFailedUnknown'), null, 'errors');
    }
} elseif (($action === 'pause_case' || $action === 'resume_case') && $facid > 0) {
    if (!$user->hasRight('mahnwesen', 'case', 'write')) {
        accessforbidden();
    }
    require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
    $permissionInvoice = new Facture($db);
    if ($permissionInvoice->fetch($facid) <= 0) { accessforbidden(); }
    $result = restrictedArea($user, 'facture', $permissionInvoice->id, 'facture', 'facture');
    $paused = ($action === 'pause_case');
    if ($manager->setPaused($facid, $paused, $user)) {
        setEventMessages($langs->trans($paused ? 'CasePausedMessage' : 'CaseResumedMessage'), null, 'mesgs');
    } else {
        setEventMessages($manager->error, $manager->errors, 'errors');
    }
}

$rows = $manager->scanDueInvoices($maxScan, $user);
if ($rows === false) {
    setEventMessages($manager->error, $manager->errors, 'errors');
    $rows = array();
} elseif (!empty($manager->errors)) {
    setEventMessages('', $manager->errors, 'warnings');
}
$diag = $manager->diagnostics;
$dryRunRows = array();
if ($action === 'dry_run') {
    $dryService = new DunningNoticeService($db, $manager);
    foreach ($rows as $row) {
        $decision = 'ready'; $detail = '';
        $case = $manager->getCaseByInvoice((int) $row['invoice_id']);
        $workflow = $manager->getWorkflowState((int) $row['invoice_id']);
        $level = $workflow ? (int) $workflow['next_required_level'] : 0;
        if (!$case) { $decision = 'blocked'; $detail = 'case_missing'; }
        elseif ($case['status'] !== 'open') { $decision = 'blocked'; $detail = 'case_'.$case['status']; }
        elseif (!empty($case['paused'])) { $decision = 'blocked'; $detail = 'paused'; }
        elseif (!$workflow || empty($workflow['actionable']) || $level <= 0) { $decision = 'blocked'; $detail = 'not_due'; }
        elseif (empty($manager->getRuleByLevel($level)['send_email'])) { $decision = 'blocked'; $detail = 'stage_auto_disabled'; }
        elseif ($manager->hasSuccessfulNoticeAtLevel((int) $case['id'], $level)) { $decision = 'blocked'; $detail = 'already_sent'; }
        elseif ($manager->hasPendingNoticeAtLevel((int) $case['id'], $level)) { $decision = 'blocked'; $detail = 'attempt_pending'; }
        elseif ($manager->getAutomaticFailureCount((int) $case['id'], $level) >= 3) { $decision = 'blocked'; $detail = 'retry_limit_reached'; }
        else {
            $dryInvoice = new Facture($db);
            if ($dryInvoice->fetch((int) $row['invoice_id']) <= 0) { $decision = 'blocked'; $detail = 'invoice_load_failed'; }
            else {
                $dryInvoice->fetch_thirdparty();
                $recipient = $dryService->getAutomaticRecipientOption($dryInvoice, $manager->getAutomaticRecipientPolicy());
                $customerLang = !empty($dryInvoice->thirdparty->default_lang) ? (string) $dryInvoice->thirdparty->default_lang : $langs->defaultlang;
                if ($recipient === false) { $decision = 'blocked'; $detail = $dryService->recipientLookupFailed ? 'recipient_lookup_failed' : 'recipient_ambiguous'; }
                else {
                    $dryTemplate = $dryService->getTemplate($level, $customerLang, $user);
                    if ($dryTemplate === false) { $decision = 'blocked'; $detail = 'template_missing'; }
                    elseif ($dryService->getFromEmail((string) ($dryTemplate['email_from'] ?? '')) === '') { $decision = 'blocked'; $detail = 'sender_missing'; }
                    elseif ((string) ($dryTemplate['joinfiles'] ?? '') === '1' && $dryService->getInvoicePdfPath($dryInvoice) === '') { $decision = 'blocked'; $detail = 'invoice_pdf_missing'; }
                }
            }
        }
        $dryRunRows[] = array('row' => $row, 'level' => $level, 'decision' => $decision, 'detail' => $detail);
    }
}

$counts = array(0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0);
$totalRemain = 0.0;
foreach ($rows as $row) {
    // Dashboard KPIs intentionally count the NEXT REQUIRED workflow step,
    // not merely the calendar threshold. This prevents a 14-day overdue
    // invoice from appearing as "1. Mahnung" while its unsent reminder is
    // still the next legally/operationally required action.
    $stage = isset($row['next_required_level']) ? (int) $row['next_required_level'] : (int) $row['stage'];
    if (!isset($counts[$stage])) {
        $counts[$stage] = 0;
    }
    $counts[$stage]++;
    $totalRemain += (float) $row['remain_to_pay'];
}

llxHeader('', $langs->trans('MahnwesenDashboard'), '', '', 0, 0, '', '', '', 'mod-mahnwesen page-index');
print load_fiche_titre($langs->trans('MahnwesenDashboard'), '', 'bill');

print '<div class="info"><strong>'.$langs->trans('MahnwesenV04Mode').'</strong> - '.$langs->trans('MahnwesenV04DashboardIntro').'</div>';
$autoEnabled = getDolGlobalInt('MAHNWESEN_AUTO_SEND_ENABLED', 0) > 0;
print '<div class="'.($autoEnabled ? 'warning' : 'opacitymedium').' margintoponly">'.$langs->trans($autoEnabled ? 'MahnwesenDashboardAutoOn' : 'MahnwesenDashboardAutoOff').'</div><br>';

if ($user->hasRight('mahnwesen', 'case', 'write')) {
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="marginbottomonly" onsubmit="var b=this.querySelector(\'input[type=submit]\'); if(b){b.disabled=true;}">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="sync_cases">';
    print '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('SyncDunningCases')).'">';
    print ' <span class="opacitymedium">'.$langs->trans('SyncDunningCasesHelp').'</span>';
    print '</form><br>';
}
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="marginbottomonly"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="dry_run"><button class="button" type="submit">'.$langs->trans('MahnwesenAutomaticDryRun').'</button> <span class="opacitymedium">'.$langs->trans('MahnwesenAutomaticDryRunHelp').'</span></form><br>';

if (!empty($dryRunRows)) {
    print load_fiche_titre($langs->trans('MahnwesenAutomaticDryRunResult'), '', 'debug');
    print '<div class="div-table-responsive"><table class="tagtable liste centpercent"><tr class="liste_titre"><th>'.$langs->trans('Invoice').'</th><th>'.$langs->trans('ThirdParty').'</th><th>'.$langs->trans('DunningStage').'</th><th>'.$langs->trans('Decision').'</th><th>'.$langs->trans('Reason').'</th></tr>';
    foreach ($dryRunRows as $dry) {
        $r = $dry['row'];
        $url = dol_buildpath('/mahnwesen/invoice.php?id='.(int) $r['invoice_id'], 1);
        print '<tr class="oddeven"><td><a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag($r['invoice_ref']).'</a></td><td>'.dol_escape_htmltag($r['socname']).'</td><td>'.($dry['level'] > 0 ? $langs->trans($manager->getStageLabelKey($dry['level'])) : '-').'</td><td>'.$langs->trans($dry['decision'] === 'ready' ? 'MahnwesenDryRunReady' : 'MahnwesenDryRunBlocked').'</td><td><code>'.dol_escape_htmltag($dry['detail']).'</code></td></tr>';
    }
    print '</table></div><br>';
}

print '<div class="fichecenter">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Metric').'</th>';
print '<th class="right">'.$langs->trans('Value').'</th>';
print '</tr>';
print '<tr class="oddeven"><td>'.$langs->trans('OverdueOpenInvoices').'</td><td class="right"><strong>'.count($rows).'</strong></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('OpenAmount').'</td><td class="right"><strong>'.price($totalRemain, 0, $langs, 1, -1, -1, $conf->currency).'</strong></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('MahnwesenNoWorkflowActionDue').'</td><td class="right">'.$counts[0].'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DunningStage1').'</td><td class="right">'.$counts[1].'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DunningStage2').'</td><td class="right">'.$counts[2].'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DunningStage3').'</td><td class="right">'.$counts[3].'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DunningStage4').'</td><td class="right">'.$counts[4].'</td></tr>';
print '</table>';
print '</div><br>';

// Diagnostic section: deliberately visible in v0.1.1 so we can identify why an invoice is excluded.
print load_fiche_titre($langs->trans('ScanDiagnostics'), '', 'debug');
print '<div class="opacitymedium">'.$langs->trans('ScanDiagnosticsHelp').'</div>';
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('DiagnosticCheck').'</th><th class="right">'.$langs->trans('Value').'</th></tr>';

$diagnosticRows = array(
    'all_invoices_entity' => 'DiagAllInvoicesEntity',
    'validated_status1' => 'DiagValidatedStatus1',
    'validated_no_due_date' => 'DiagValidatedNoDueDate',
    'validated_due_today_or_future' => 'DiagValidatedFutureDue',
    'validated_overdue_raw' => 'DiagValidatedOverdueRaw',
    'scanned_candidates' => 'DiagScannedCandidates',
    'excluded_type' => 'DiagExcludedType',
    'excluded_no_balance' => 'DiagExcludedNoBalance',
    'excluded_below_minimum' => 'DiagExcludedBelowMinimum',
    'excluded_invalid_due_date' => 'DiagExcludedInvalidDue',
    'excluded_fetch_error' => 'DiagExcludedFetchError',
    'included' => 'DiagIncluded',
);
foreach ($diagnosticRows as $key => $labelKey) {
    $value = isset($diag[$key]) ? (int) $diag[$key] : 0;
    print '<tr class="oddeven"><td>'.$langs->trans($labelKey).'</td><td class="right">'.$value.'</td></tr>';
}
print '</table>';
print '</div>';

$rawOverdue = isset($diag['validated_overdue_raw']) ? (int) $diag['validated_overdue_raw'] : 0;
$excludedType = isset($diag['excluded_type']) ? (int) $diag['excluded_type'] : 0;
$excludedNoBalance = isset($diag['excluded_no_balance']) ? (int) $diag['excluded_no_balance'] : 0;
$excludedBelow = isset($diag['excluded_below_minimum']) ? (int) $diag['excluded_below_minimum'] : 0;
if ($rawOverdue === 0) {
    print '<br><div class="warning">'.$langs->trans('DiagHintNoRawOverdue').'</div>';
} elseif (empty($rows) && $excludedType > 0) {
    print '<br><div class="warning">'.$langs->trans('DiagHintTypesExcluded', $excludedType).'</div>';
} elseif (empty($rows) && ($excludedNoBalance > 0 || $excludedBelow > 0)) {
    print '<br><div class="warning">'.$langs->trans('DiagHintBalanceExcluded', $excludedNoBalance, $excludedBelow).'</div>';
}

print '<br>'.load_fiche_titre($langs->trans('CandidateTypes'), '', '');
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('InvoiceType').'</th><th class="right">'.$langs->trans('OverdueCandidatesInScan').'</th><th>'.$langs->trans('Handling').'</th></tr>';
$typeRows = array(
    'type_standard' => array('InvoiceTypeStandard', 1),
    'type_replacement' => array('InvoiceTypeReplacement', 1),
    'type_credit_note' => array('InvoiceTypeCreditNote', 0),
    'type_deposit' => array('InvoiceTypeDeposit', $manager->includeDepositInvoices() ? 1 : 0),
    'type_proforma' => array('InvoiceTypeProforma', 0),
    'type_situation' => array('InvoiceTypeSituation', 1),
    'type_other' => array('InvoiceTypeOther', 0),
);
foreach ($typeRows as $key => $typeData) {
    $value = isset($diag[$key]) ? (int) $diag[$key] : 0;
    print '<tr class="oddeven"><td>'.$langs->trans($typeData[0]).'</td><td class="right">'.$value.'</td><td>'.($typeData[1] ? $langs->trans('Included') : $langs->trans('Excluded')).'</td></tr>';
}
print '</table>';
print '</div><br>';

print load_fiche_titre($langs->trans('OverdueInvoices'), '', '');
print '<div class="div-table-responsive">';
print '<table class="tagtable liste centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Invoice').'</th>';
print '<th>'.$langs->trans('ThirdParty').'</th>';
print '<th>'.$langs->trans('DateInvoice').'</th>';
print '<th>'.$langs->trans('DateDue').'</th>';
print '<th class="right">'.$langs->trans('DaysOverdue').'</th>';
print '<th class="right">'.$langs->trans('AmountTTC').'</th>';
print '<th class="right">'.$langs->trans('RemainToPay').'</th>';
print '<th>'.$langs->trans('MahnwesenCalendarStage').'</th>';
print '<th>'.$langs->trans('MahnwesenNextRequiredStage').'</th>';
print '<th>'.$langs->trans('DunningCase').'</th>';
print '<th class="center">'.$langs->trans('Actions').'</th>';
print '</tr>';

if (empty($rows)) {
    print '<tr class="oddeven"><td colspan="11"><span class="opacitymedium">'.$langs->trans('NoOverdueInvoiceFound').'</span></td></tr>';
} else {
    foreach ($rows as $row) {
        $invoiceUrl = DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $row['invoice_id']);
        $socUrl = DOL_URL_ROOT.'/societe/card.php?socid='.((int) $row['socid']);
        print '<tr class="oddeven">';
        print '<td><a href="'.dol_escape_htmltag($invoiceUrl).'">'.dol_escape_htmltag($row['invoice_ref']).'</a></td>';
        print '<td><a href="'.dol_escape_htmltag($socUrl).'">'.dol_escape_htmltag($row['socname']).'</a></td>';
        print '<td>'.dol_print_date($row['invoice_date'], 'day').'</td>';
        print '<td>'.dol_print_date($row['due_date'], 'day').'</td>';
        print '<td class="right">'.((int) $row['days_late']).'</td>';
        print '<td class="right">'.price($row['total_ttc'], 0, $langs, 1, -1, -1, $conf->currency).'</td>';
        print '<td class="right"><strong>'.price($row['remain_to_pay'], 0, $langs, 1, -1, -1, $conf->currency).'</strong></td>';
        print '<td><span class="opacitymedium">'.$langs->trans($row['stage_key']).'</span></td>';
        print '<td>';
        $requiredLevel = isset($row['next_required_level']) ? (int) $row['next_required_level'] : 0;
        if ($requiredLevel > 0) {
            print '<span class="badge badge-status4"><strong>'.$langs->trans($manager->getStageLabelKey($requiredLevel)).'</strong></span>';
            if (!empty($row['next_required_at']) && ((int) $db->jdate($row['next_required_at'])) > dol_now()) {
                print '<br><span class="opacitymedium">'.$langs->trans('MahnwesenStageAvailableAt', dol_print_date($db->jdate($row['next_required_at']), 'day')).'</span>';
            }
        } elseif (!empty($row['next_future_level']) && !empty($row['next_future_at'])) {
            print '<span class="opacitymedium">'.$langs->trans('MahnwesenWaitingForFutureStage', dol_print_date($db->jdate($row['next_future_at']), 'day')).'</span>';
        } else {
            print '<span class="opacitymedium">'.$langs->trans('MahnwesenWorkflowComplete').'</span>';
        }
        print '</td>';
        $caseUrl = dol_buildpath('/mahnwesen/invoice.php?id='.((int) $row['invoice_id']), 1);
        print '<td>';
        if (!empty($row['case_id'])) {
            print '<a href="'.dol_escape_htmltag($caseUrl).'">';
            if (!empty($row['paused'])) {
                print '<span class="badge badge-status0">'.$langs->trans('Paused').'</span>';
            } else {
                print '<span class="badge badge-status4">'.$langs->trans('CaseActive').'</span>';
            }
            print '</a>';
            $lastCompleted = isset($row['highest_completed_level']) ? (int) $row['highest_completed_level'] : 0;
            print '<br><span class="opacitymedium">'.$langs->trans('MahnwesenLastCompletedStage').': '.($lastCompleted > 0 ? $langs->trans($manager->getStageLabelKey($lastCompleted)) : $langs->trans('MahnwesenNoneYet')).'</span>';
        } else {
            print '<a href="'.dol_escape_htmltag($caseUrl).'" class="opacitymedium">'.$langs->trans('CaseNotCreated').'</a>';
        }
        print '</td>';
        print '<td class="center nowrap">';
        if ($user->hasRight('mahnwesen', 'case', 'write') && !empty($row['case_id'])) {
            print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline" onsubmit="var b=this.querySelector(\'input[type=submit]\'); if(b){b.disabled=true;}">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="facid" value="'.((int) $row['invoice_id']).'">';
            print '<input type="hidden" name="action" value="'.(!empty($row['paused']) ? 'resume_case' : 'pause_case').'">';
            print '<input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans(!empty($row['paused']) ? 'Resume' : 'Pause')).'">';
            print '</form>';
        }
        print '</td>';
        print '</tr>';
    }
}
print '</table>';
print '</div>';

if (isset($diag['scanned_candidates']) && isset($diag['validated_overdue_raw']) && (int) $diag['scanned_candidates'] < (int) $diag['validated_overdue_raw']) {
    print '<br><div class="warning">'.$langs->trans('ScanLimitReachedRaw', $maxScan, (int) $diag['validated_overdue_raw']).'</div>';
}

print '<br><div class="opacitymedium">'.$langs->trans('MahnwesenV04SafetyFooter').'</div>';
llxFooter();
$db->close();
