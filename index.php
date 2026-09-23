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
// Scan figures exist only right after a synchronisation (#26).
$diag = array();
$showDiagnostics = false;

if ($action === 'sync_cases') {
    if (!$user->hasRight('mahnwesen', 'case', 'write')) {
        accessforbidden();
    }
    try {
        $summary = $manager->syncCases($user, $maxScan);
        $diag = $manager->diagnostics;
        $showDiagnostics = true;
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
    $result = restrictedArea($user, 'facture', $permissionInvoice->id, '', '', 'fk_soc', 'rowid');
    $paused = ($action === 'pause_case');
    if ($manager->setPaused($facid, $paused, $user)) {
        setEventMessages($langs->trans($paused ? 'CasePausedMessage' : 'CaseResumedMessage'), null, 'mesgs');
    } else {
        setEventMessages($manager->error, $manager->errors, 'errors');
    }
}

$dryRunRows = array();
$dryRunCounts = array();
if ($action === 'dry_run') {
    if (!$user->hasRight('mahnwesen', 'automation', 'dryrun')) { accessforbidden(); }
    // The cron's decision for every invoice, as if it ran now (#16): all
    // customers in the cron's order, since limits depend on them, the cases
    // as the synchronisation would leave them, nothing written. The table
    // shows only invoices this user may see.
    $dryService = new DunningNoticeService($db, $manager);
    $dryRunId = $manager->beginAutomationRun('dry_run', $user);
    if ($dryRunId === false) { setEventMessages($langs->trans('MahnwesenDryRunAuditFailed'), null, 'warnings'); }
    $dryAll = $manager->scanDueInvoices($manager->getMaxScan());
    if ($dryAll === false) { $dryAll = array(); setEventMessages($manager->error, $manager->errors, 'errors'); }
    $dryBudget = $manager->newAutomaticBudget();
    $dryRunCounts = array('send' => 0, 'skip' => 0, 'fail' => 0, 'off' => 0, 'wait' => 0, 'shown' => 0);
    foreach ($dryAll as $row) {
        $dry = $manager->decideAutomaticSend($row, $dryService, $dryBudget, true);
        $dryRunCounts[$dry['decision']]++;
        if ($dry['decision'] === 'wait' || !$manager->canSeeCustomer($user, (int) $row['socid'])) { continue; }
        $dryRunCounts['shown']++;
        $dryRunRows[] = array('row' => $row, 'level' => $dry['level'], 'decision' => $dry['decision'], 'detail' => $dry['detail']);
    }
    if ($dryRunId !== false) {
        $dryCounters = array('scanned' => count($dryAll), 'synchronized' => 0, 'attempted' => 0, 'sent' => 0, 'skipped' => $dryRunCounts['skip'], 'failed' => $dryRunCounts['fail']);
        $drySummary = 'Dry run: '.$dryRunCounts['send'].' ready, '.$dryRunCounts['skip'].' skipped, '.$dryRunCounts['fail'].' failing, '.$dryRunCounts['off'].' without automatic sending. No state changed and no email sent.';
        if (!$manager->finishAutomationRun($dryRunId, 'success', $dryCounters, $drySummary)) {
            setEventMessages($langs->trans('MahnwesenDryRunAuditFailed'), null, 'warnings');
        }
    }
}

// Cases a removed payment left behind are re-evaluated before anything is shown (#36).
if ($user->hasRight('mahnwesen', 'case', 'write')) { $manager->processRecheckQueue($user); }

// The list and the figures come from the stored cases (#26): no invoice is
// loaded to show them. Sales representatives see their customers only.
$scopeJoin = $user->hasRight('societe', 'client', 'voir') ? ''
    : ' INNER JOIN '.MAIN_DB_PREFIX.'societe_commerciaux as sc ON sc.fk_soc = f.fk_soc AND sc.fk_user = '.((int) $user->id);
$from = ' FROM '.MAIN_DB_PREFIX.'mahnwesen_case as c INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = c.fk_facture'
    .' INNER JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = f.fk_soc'.$scopeJoin
    .' LEFT JOIN '.MAIN_DB_PREFIX.'facture_extrafields as fe ON fe.fk_object = f.rowid'
    .' LEFT JOIN '.MAIN_DB_PREFIX.'societe_extrafields as se ON se.fk_object = s.rowid'
    .' WHERE c.entity = '.((int) $conf->entity);
// 1 or 2 when a block on the invoice or the customer applies today (#37).
$blockSum = '('.$manager->dunningBlockSql('fe').' + '.$manager->dunningBlockSql('se').')';

$figures = array('cases' => 0, 'amount' => 0.0, 'levels' => array(0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0));
$resFigures = $db->query('SELECT c.current_level, COUNT(*) as n, SUM(c.remaining_amount) as amount'.$from." AND c.status = 'open' GROUP BY c.current_level");
while ($resFigures && ($o = $db->fetch_object($resFigures))) {
    $figures['cases'] += (int) $o->n;
    $figures['amount'] += (float) $o->amount;
    $figures['levels'][max(0, min(4, (int) $o->current_level))] += (int) $o->n;
}
if ($resFigures) { $db->free($resFigures); }

$searchRef = trim(GETPOST('search_ref', 'alphanohtml'));
$searchCompany = trim(GETPOST('search_company', 'alphanohtml'));
$searchLevel = GETPOST('search_level', 'alpha');
$searchStatus = GETPOST('search_status', 'aZ09') ?: 'active';
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
    $searchRef = $searchCompany = $searchLevel = '';
    $searchStatus = 'active';
}
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = strtoupper(GETPOST('sortorder', 'aZ09comma')) === 'DESC' ? 'DESC' : 'ASC';
$sortable = array('f.ref', 's.nom', 'f.date_lim_reglement', 'c.remaining_amount', 'c.current_level', 'c.next_action_at');
if (!in_array($sortfield, $sortable, true)) { $sortfield = 'f.date_lim_reglement'; }
$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : (int) $conf->liste_limit;
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
$page = max(0, (int) $page);
$offset = $limit * $page;

$where = '';
if ($searchRef !== '') { $where .= natural_search('f.ref', $searchRef); }
if ($searchCompany !== '') { $where .= natural_search('s.nom', $searchCompany); }
if ($searchLevel !== '' && ctype_digit((string) $searchLevel)) { $where .= ' AND c.current_level = '.((int) $searchLevel); }
$nowSql = "'".$db->escape($db->idate(dol_now()))."'";
$statusFilters = array(
    'active' => " AND c.status = 'open'",
    'due' => " AND c.status = 'open' AND c.paused = 0 AND ".$blockSum." = 0 AND c.next_action_at IS NOT NULL AND c.next_action_at <= ".$nowSql,
    'paused' => " AND c.status = 'open' AND c.paused = 1",
    'blocked' => " AND c.status = 'open' AND ".$blockSum." > 0",
    'closed' => " AND c.status IN ('closed', 'fee_open', 'handed_over')",
    'all' => '',
);
if (!isset($statusFilters[$searchStatus])) { $searchStatus = 'active'; }
$where .= $statusFilters[$searchStatus];
$param = '&search_status='.urlencode($searchStatus).($searchRef !== '' ? '&search_ref='.urlencode($searchRef) : '')
    .($searchCompany !== '' ? '&search_company='.urlencode($searchCompany) : '').($searchLevel !== '' ? '&search_level='.urlencode($searchLevel) : '')
    .($limit != $conf->liste_limit ? '&limit='.$limit : '');

$total = 0;
$resCount = $db->query('SELECT COUNT(*) as n'.$from.$where);
if ($resCount && ($o = $db->fetch_object($resCount))) { $total = (int) $o->n; }
if ($resCount) { $db->free($resCount); }
$cases = array();
$sqlList = 'SELECT c.rowid as case_id, c.fk_facture, c.current_level, c.paused, c.status, c.remaining_amount, c.next_action_at,'
    .' f.ref, f.date_lim_reglement, f.fk_soc, s.nom as socname, '.$manager->dunningBlockColumns().$from.$where
    .' ORDER BY '.$sortfield.' '.$sortorder.', c.rowid ASC'.$db->plimit($limit, $offset);
$resList = $db->query($sqlList);
if (!$resList) { setEventMessages($db->lasterror(), null, 'errors'); }
while ($resList && ($o = $db->fetch_object($resList))) { $cases[] = $o; }
if ($resList) { $db->free($resList); }

llxHeader('', $langs->trans('MahnwesenDashboard'), '', '', 0, 0, '', array('/mahnwesen/css/mahnwesen.css'), '', 'mod-mahnwesen page-index');
print load_fiche_titre($langs->trans('MahnwesenDashboard'), '', 'bill');
$form = new Form($db);

print '<div class="info"><strong>'.$langs->trans('MahnwesenV04Mode').'</strong> - '.$langs->trans('MahnwesenV04DashboardIntro').'</div>';
$autoEnabled = getDolGlobalInt('MAHNWESEN_AUTO_SEND_ENABLED', 0) > 0;
print '<div class="'.($autoEnabled ? 'warning' : 'opacitymedium').' margintoponly">'.$langs->trans($autoEnabled ? 'MahnwesenDashboardAutoOn' : 'MahnwesenDashboardAutoOff').'</div>';
if ($manager->isCoreReminderJobActive()) {
    print '<div class="warning margintoponly">'.$langs->trans('MahnwesenCoreReminderActive', dol_buildpath('/cron/list.php', 1)).'</div>';
}
print '<br>';

if ($user->hasRight('mahnwesen', 'case', 'write')) {
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="marginbottomonly" onsubmit="var b=this.querySelector(\'input[type=submit]\'); if(b){b.disabled=true;}">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="sync_cases">';
    print '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('SyncDunningCases')).'">';
    print ' <span class="opacitymedium">'.$langs->trans('SyncDunningCasesHelp').'</span>';
    print '</form><br>';
}
if ($user->hasRight('mahnwesen', 'automation', 'dryrun')) {
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="marginbottomonly"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="dry_run"><button class="button" type="submit">'.$langs->trans('MahnwesenAutomaticDryRun').'</button> <span class="opacitymedium">'.$langs->trans('MahnwesenAutomaticDryRunHelp').'</span></form><br>';
}

if ($action === 'dry_run' && !empty($dryRunCounts)) {
    print load_fiche_titre($langs->trans('MahnwesenAutomaticDryRunResult'), '', 'debug');
    print '<div class="info" id="mahnwesen-dry-run-summary">'.$langs->trans('MahnwesenDryRunSummary', $dryRunCounts['send'], $dryRunCounts['skip'], $dryRunCounts['fail'], $dryRunCounts['off']).' '.$langs->trans('MahnwesenDryRunShown', $dryRunCounts['shown']).'</div>';
}
if (!empty($dryRunRows)) {
    print '<div class="div-table-responsive"><table class="tagtable liste centpercent"><tr class="liste_titre"><th>'.$langs->trans('Invoice').'</th><th>'.$langs->trans('ThirdParty').'</th><th>'.$langs->trans('MahnwesenProfile').'</th><th>'.$langs->trans('DunningStage').'</th><th>'.$langs->trans('Decision').'</th><th>'.$langs->trans('Reason').'</th></tr>';
    foreach ($dryRunRows as $dry) {
        $r = $dry['row'];
        $url = dol_buildpath('/mahnwesen/invoice.php?id='.(int) $r['invoice_id'], 1);
        print '<tr class="oddeven"><td><a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag($r['invoice_ref']).'</a></td><td>'.dol_escape_htmltag($r['socname']).'</td><td data-profile="'.((int) $r['profile_id']).'">'.dol_escape_htmltag($r['profile_label']).'</td><td>'.($dry['level'] > 0 ? $langs->trans($manager->getStageLabelKey($dry['level'])) : '-').'</td><td data-decision="'.dol_escape_htmltag($dry['decision']).'">'.$langs->trans('MahnwesenDryRunDecision_'.$dry['decision']).'</td><td data-detail="'.dol_escape_htmltag($dry['detail']).'">'.($dry['decision'] === 'send' ? '' : $langs->trans('MahnwesenDryRunDetail_'.$dry['detail'])).'</td></tr>';
    }
    print '</table></div><br>';
}

print '<div class="fichecenter">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('Metric').'</th><th class="right">'.$langs->trans('Value').'</th></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('OverdueOpenInvoices').'</td><td class="right"><strong>'.$figures['cases'].'</strong></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('OpenAmount').'</td><td class="right"><strong>'.price($figures['amount'], 0, $langs, 1, -1, -1, $conf->currency).'</strong></td></tr>';
for ($level = 0; $level <= 4; $level++) {
    print '<tr class="oddeven"><td>'.$langs->trans($level > 0 ? 'DunningStage'.$level : 'MahnwesenNoWorkflowActionDue').'</td><td class="right">'.$figures['levels'][$level].'</td></tr>';
}
print '</table>';
print '</div><br>';

if ($showDiagnostics) {
    // What the synchronisation just scanned, and why invoices were left out.
    print '<details class="marginbottomonly"><summary>'.$langs->trans('ScanDiagnostics').'</summary>';
    print '<div class="opacitymedium">'.$langs->trans('ScanDiagnosticsHelp').'</div>';
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
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
        print '<tr class="oddeven"><td>'.$langs->trans($labelKey).'</td><td class="right">'.(isset($diag[$key]) ? (int) $diag[$key] : 0).'</td></tr>';
    }
    print '</table></div>';
    if (isset($diag['scanned_candidates'], $diag['validated_overdue_raw']) && (int) $diag['scanned_candidates'] < (int) $diag['validated_overdue_raw']) {
        print '<div class="warning">'.$langs->trans('ScanLimitReachedRaw', $maxScan, (int) $diag['validated_overdue_raw']).'</div>';
    }
    print '</details>';
}

// The cases as a standard Dolibarr list: filters, sorting, pages (#26).
print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" id="mahnwesen-case-list">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'"><input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print_barre_liste($langs->trans('DunningCases'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', count($cases), $total, 'bill', 0, '', '', $limit);
print '<div class="div-table-responsive"><table class="tagtable liste centpercent">';
$statusOptions = array('active' => 'MahnwesenListActive', 'due' => 'MahnwesenListDue', 'paused' => 'Paused', 'blocked' => 'MahnwesenListBlocked', 'closed' => 'CaseClosed', 'all' => 'All');
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input class="flat maxwidth100" type="text" name="search_ref" value="'.dol_escape_htmltag($searchRef).'"></td>';
print '<td class="liste_titre"><input class="flat maxwidth150" type="text" name="search_company" value="'.dol_escape_htmltag($searchCompany).'"></td>';
print '<td class="liste_titre"></td><td class="liste_titre"></td><td class="liste_titre"></td>';
print '<td class="liste_titre"><select class="flat" name="search_level"><option value=""></option>';
for ($level = 0; $level <= 4; $level++) {
    print '<option value="'.$level.'"'.((string) $searchLevel === (string) $level ? ' selected' : '').'>'.$langs->trans($level > 0 ? 'DunningStage'.$level : 'DunningStageNone').'</option>';
}
print '</select></td><td class="liste_titre"></td>';
print '<td class="liste_titre"><select class="flat" name="search_status">';
foreach ($statusOptions as $value => $labelKey) {
    print '<option value="'.$value.'"'.($searchStatus === $value ? ' selected' : '').'>'.$langs->trans($labelKey).'</option>';
}
print '</select></td>';
print '<td class="liste_titre center">'.$form->showFilterButtons().'</td></tr>';
print '<tr class="liste_titre">';
print_liste_field_titre('Invoice', $_SERVER['PHP_SELF'], 'f.ref', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('ThirdParty', $_SERVER['PHP_SELF'], 's.nom', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('DateDue', $_SERVER['PHP_SELF'], 'f.date_lim_reglement', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('DaysOverdue', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('RemainToPay', $_SERVER['PHP_SELF'], 'c.remaining_amount', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('MahnwesenCalendarStage', $_SERVER['PHP_SELF'], 'c.current_level', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('NextAction', $_SERVER['PHP_SELF'], 'c.next_action_at', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Status', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'center ');
print '</tr>';
if (empty($cases)) {
    print '<tr class="oddeven"><td colspan="9"><span class="opacitymedium">'.$langs->trans('NoOverdueInvoiceFound').'</span></td></tr>';
}
$today = dol_now();
foreach ($cases as $case) {
    $due = $db->jdate($case->date_lim_reglement);
    $caseUrl = dol_buildpath('/mahnwesen/invoice.php?id='.((int) $case->fk_facture), 1);
    print '<tr class="oddeven" data-case="'.((int) $case->case_id).'">';
    print '<td class="nowraponall"><a href="'.dol_escape_htmltag($caseUrl).'">'.dol_escape_htmltag($case->ref).'</a></td>';
    print '<td class="tdoverflowmax200"><a href="'.dol_escape_htmltag(DOL_URL_ROOT.'/societe/card.php?socid='.((int) $case->fk_soc)).'">'.dol_escape_htmltag($case->socname).'</a></td>';
    print '<td>'.dol_print_date($due, 'day').'</td>';
    print '<td class="right">'.($due ? max(0, (int) floor(($today - $due) / 86400)) : '').'</td>';
    print '<td class="right"><strong>'.price((float) $case->remaining_amount, 0, $langs, 1, -1, -1, $conf->currency).'</strong></td>';
    print '<td>'.$langs->trans((int) $case->current_level > 0 ? 'DunningStage'.((int) $case->current_level) : 'DunningStageNone').'</td>';
    print '<td>'.(!empty($case->next_action_at) ? dol_print_date($db->jdate($case->next_action_at), 'day') : '').'</td>';
    $block = $manager->blockFromRow($case);
    if ($case->status !== 'open') {
        print '<td><span class="badge badge-status0">'.$langs->trans($case->status === 'fee_open' ? 'MahnwesenCaseFeeOpen'
            : ($case->status === 'handed_over' ? 'MahnwesenCaseHandedOver' : 'CaseClosed')).'</span></td>';
    } elseif ($block !== null) {
        print '<td><span class="badge badge-status8 classfortooltip" title="'.dol_escape_htmltag(dol_string_nohtmltag($manager->describeBlock($block))).'">'.$langs->trans('MahnwesenBlocked').'</span>'
            .($block['reason'] !== '' ? ' <span class="small">'.dol_escape_htmltag($block['reason']).'</span>' : '').'</td>';
    } else {
        print '<td><span class="badge '.(!empty($case->paused) ? 'badge-status1">'.$langs->trans('Paused') : 'badge-status4">'.$langs->trans('CaseActive')).'</span></td>';
    }
    print '<td class="center nowrap">';
    if ($user->hasRight('mahnwesen', 'case', 'write') && $case->status === 'open') {
        print '<a class="button smallpaddingimp" href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?action='.(!empty($case->paused) ? 'resume_case' : 'pause_case').'&facid='.((int) $case->fk_facture).'&token='.newToken()).'">'.$langs->trans(!empty($case->paused) ? 'Resume' : 'Pause').'</a>';
    }
    print '</td></tr>';
}
print '</table></div></form>';

print '<br><div class="opacitymedium">'.$langs->trans('MahnwesenV04SafetyFooter').'</div>';
llxFooter();
$db->close();
