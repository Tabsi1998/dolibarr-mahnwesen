<?php
/* The dunning cases and claims of one customer, as a tab of the third party (#41) */
if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i], $tmp2[$j]) && $tmp[$i] === $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, $i + 1).'/main.inc.php')) {
    $res = @include substr($tmp, 0, $i + 1).'/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, $i + 1)).'/main.inc.php')) {
    $res = @include dirname(substr($tmp, 0, $i + 1)).'/main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) { $res = @include '../../main.inc.php'; }
if (!$res && file_exists('../../../main.inc.php')) { $res = @include '../../../main.inc.php'; }
if (!$res && file_exists('../../../../main.inc.php')) { $res = @include '../../../../main.inc.php'; }
if (!$res) { die('Include of main fails'); }

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);

$langs->loadLangs(array('bills', 'companies', 'mahnwesen@mahnwesen'));

$socid = GETPOSTINT('socid');
if (!$socid) { $socid = GETPOSTINT('id'); }
if ($socid <= 0) { accessforbidden(); }
if (!$user->hasRight('mahnwesen', 'dashboard', 'read') || !$user->hasRight('societe', 'lire')) { accessforbidden(); }

$manager = new DunningManager($db);
if (!$manager->canSeeCustomer($user, $socid)) { accessforbidden(); }

$customer = new Societe($db);
if ($customer->fetch($socid) <= 0) { accessforbidden(); }

// The cases of this customer, from the stored ones: no invoice is loaded for the list (#26).
$cases = array();
$sql = 'SELECT c.rowid, c.fk_facture, c.current_level, c.paused, c.status, c.remaining_amount, c.next_action_at, f.ref, f.date_lim_reglement';
$sql .= ' FROM '.MAIN_DB_PREFIX.'mahnwesen_case as c INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = c.fk_facture';
$sql .= ' WHERE c.entity = '.((int) $conf->entity).' AND f.fk_soc = '.((int) $socid);
$sql .= ' ORDER BY c.status ASC, f.date_lim_reglement ASC';
$resCases = $db->query($sql);
while ($resCases && ($row = $db->fetch_object($resCases))) { $cases[] = $row; }
if ($resCases) { $db->free($resCases); }

$claims = array('fee' => 0.0, 'interest' => 0.0);
$sql = 'SELECT kind, SUM(amount) as total FROM '.MAIN_DB_PREFIX.'mahnwesen_fee as fe';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = fe.fk_facture';
$sql .= ' WHERE fe.entity = '.((int) $conf->entity).' AND f.fk_soc = '.((int) $socid)." AND fe.status = 'open' GROUP BY kind";
$resClaims = $db->query($sql);
while ($resClaims && ($row = $db->fetch_object($resClaims))) { $claims[$row->kind === 'interest' ? 'interest' : 'fee'] = (float) $row->total; }
if ($resClaims) { $db->free($resClaims); }

llxHeader('', $langs->trans('Mahnwesen').' - '.$customer->name, '', '', 0, 0, '', array('/mahnwesen/css/mahnwesen.css'), '', 'mod-mahnwesen page-customer');
$head = societe_prepare_head($customer);
print dol_get_fiche_head($head, 'mahnwesen', $langs->trans('ThirdParty'), -1, 'company');
dol_banner_tab($customer, 'socid', '', ($user->socid ? 0 : 1), 'rowid', 'nom');
print '<div class="fichecenter"><br>';

print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('MahnwesenOpenCases').'</td><td>'.count(array_filter($cases, function ($case) { return $case->status === 'open'; })).'</td>';
print '<td class="titlefield">'.$langs->trans('MahnwesenPartFee').'</td><td>'.price($claims['fee'], 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
print '<tr><td>'.$langs->trans('MahnwesenPartInterest').'</td><td>'.price($claims['interest'], 0, $langs, 1, -1, -1, $conf->currency).'</td>';
print '<td>'.$langs->trans('OpenAmount').'</td><td>'.price(array_sum(array_map(function ($case) { return $case->status === 'open' ? (float) $case->remaining_amount : 0.0; }, $cases)), 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
print '</table><br>';

print '<div class="div-table-responsive"><table class="tagtable liste centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('Invoice').'</th><th>'.$langs->trans('DateDue').'</th><th class="right">'.$langs->trans('RemainToPay').'</th>';
print '<th>'.$langs->trans('MahnwesenCalendarStage').'</th><th>'.$langs->trans('NextAction').'</th><th>'.$langs->trans('Status').'</th></tr>';
if (empty($cases)) {
    print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans('NoOverdueInvoiceFound').'</span></td></tr>';
}
foreach ($cases as $case) {
    print '<tr class="oddeven" data-case="'.((int) $case->rowid).'">';
    print '<td class="nowraponall"><a href="'.dol_escape_htmltag(dol_buildpath('/mahnwesen/invoice.php?id='.((int) $case->fk_facture), 1)).'">'.dol_escape_htmltag($case->ref).'</a></td>';
    print '<td>'.dol_print_date($db->jdate($case->date_lim_reglement), 'day').'</td>';
    print '<td class="right">'.price((float) $case->remaining_amount, 0, $langs, 1, -1, -1, $conf->currency).'</td>';
    print '<td>'.$langs->trans((int) $case->current_level > 0 ? 'DunningStage'.((int) $case->current_level) : 'DunningStageNone').'</td>';
    print '<td>'.(!empty($case->next_action_at) ? dol_print_date($db->jdate($case->next_action_at), 'day') : '').'</td>';
    if ($case->status !== 'open') {
        print '<td><span class="badge badge-status0">'.$langs->trans($case->status === 'fee_open' ? 'MahnwesenCaseFeeOpen' : 'CaseClosed').'</span></td>';
    } else {
        print '<td><span class="badge '.(!empty($case->paused) ? 'badge-status1">'.$langs->trans('Paused') : 'badge-status4">'.$langs->trans('CaseActive')).'</span></td>';
    }
    print '</tr>';
}
print '</table></div>';
print '</div>';
print dol_get_fiche_end();
llxFooter();
$db->close();
