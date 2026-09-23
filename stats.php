<?php
/* Dunning figures: how long payment takes, what dunning brings in, where the cases are (#43) */
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

require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);

$langs->loadLangs(array('bills', 'companies', 'mahnwesen@mahnwesen'));
if (!$user->hasRight('mahnwesen', 'dashboard', 'read')) { accessforbidden(); }

$manager = new DunningManager($db);
// Sales representatives see their own customers only, as everywhere else.
$scope = $user->hasRight('societe', 'client', 'voir') ? ''
    : ' INNER JOIN '.MAIN_DB_PREFIX.'societe_commerciaux as sc ON sc.fk_soc = f.fk_soc AND sc.fk_user = '.((int) $user->id);
$entityWhere = ' WHERE c.entity = '.((int) $conf->entity);

/** Run one query and return its rows. */
function mw_stats_rows($db, $sql)
{
    $rows = array();
    $resql = $db->query($sql);
    while ($resql && ($row = $db->fetch_object($resql))) { $rows[] = $row; }
    if ($resql) { $db->free($resql); }
    return $rows;
}

// Days from the due date to the day a notice of that stage went out, and how
// many of those invoices are paid by now.
$stages = array();
$sql = 'SELECT h.level, COUNT(*) as notices, SUM(CASE WHEN f.paye = 1 THEN 1 ELSE 0 END) as paid,';
$sql .= ' AVG(DATEDIFF(h.date_creation, f.date_lim_reglement)) as days_to_notice';
$sql .= ' FROM '.MAIN_DB_PREFIX.'mahnwesen_history as h';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'mahnwesen_case as c ON c.rowid = h.fk_case';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = h.fk_facture'.$scope;
$sql .= $entityWhere." AND h.action = 'notice_sent' AND h.result = 'success' AND h.level BETWEEN 1 AND 4";
$sql .= ' GROUP BY h.level ORDER BY h.level';
foreach (mw_stats_rows($db, $sql) as $row) {
    $stages[(int) $row->level] = array('notices' => (int) $row->notices, 'paid' => (int) $row->paid,
        'days' => $row->days_to_notice === null ? null : (float) $row->days_to_notice);
}

// The open claims of the ledger, per kind.
$claims = array('fee' => 0.0, 'interest' => 0.0);
$sql = 'SELECT fe.kind, SUM(fe.amount) as total FROM '.MAIN_DB_PREFIX.'mahnwesen_fee as fe';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'mahnwesen_case as c ON c.rowid = fe.fk_case';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = fe.fk_facture'.$scope;
$sql .= $entityWhere." AND fe.status = 'open' GROUP BY fe.kind";
foreach (mw_stats_rows($db, $sql) as $row) { $claims[$row->kind === 'interest' ? 'interest' : 'fee'] = (float) $row->total; }

// Open cases per customer and per profile.
$sql = 'SELECT s.rowid as socid, s.nom as name, COUNT(*) as cases, SUM(c.remaining_amount) as amount';
$sql .= ' FROM '.MAIN_DB_PREFIX.'mahnwesen_case as c';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = c.fk_facture'.$scope;
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = f.fk_soc';
$sql .= $entityWhere." AND c.status = 'open' GROUP BY s.rowid, s.nom ORDER BY amount DESC".$db->plimit(20);
$customers = mw_stats_rows($db, $sql);

$profiles = array();
$sql = 'SELECT c.rowid, c.fk_facture, c.remaining_amount FROM '.MAIN_DB_PREFIX.'mahnwesen_case as c';
$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = c.fk_facture'.$scope;
$sql .= $entityWhere." AND c.status = 'open'".$db->plimit(500);
foreach (mw_stats_rows($db, $sql) as $row) {
    $resolution = $manager->resolveProfile((int) $row->fk_facture);
    $label = (string) $resolution['profile']['label'];
    if (!isset($profiles[$label])) { $profiles[$label] = array('cases' => 0, 'amount' => 0.0); }
    $profiles[$label]['cases']++;
    $profiles[$label]['amount'] += (float) $row->remaining_amount;
}
ksort($profiles);

llxHeader('', $langs->trans('MahnwesenStats'), '', '', 0, 0, '', array('/mahnwesen/css/mahnwesen.css'), '', 'mod-mahnwesen page-stats');
print load_fiche_titre($langs->trans('MahnwesenStats'), '', 'chart');
print '<div class="info">'.$langs->trans('MahnwesenStatsIntro').'</div><br>';

print load_fiche_titre($langs->trans('MahnwesenStatsStages'), '', 'bill');
print '<div class="div-table-responsive"><table class="noborder centpercent" id="mahnwesen-stats-stages">';
print '<tr class="liste_titre"><th>'.$langs->trans('DunningStage').'</th><th class="right">'.$langs->trans('MahnwesenStatsNotices').'</th>';
print '<th class="right">'.$langs->trans('MahnwesenStatsDaysToNotice').'</th><th class="right">'.$langs->trans('MahnwesenStatsPaidShare').'</th></tr>';
for ($level = 1; $level <= 4; $level++) {
    $row = isset($stages[$level]) ? $stages[$level] : array('notices' => 0, 'paid' => 0, 'days' => null);
    $share = $row['notices'] > 0 ? round(100 * $row['paid'] / $row['notices']) : 0;
    print '<tr class="oddeven" data-stage="'.$level.'"><td>'.$langs->trans($manager->getStageLabelKey($level)).'</td>';
    print '<td class="right">'.$row['notices'].'</td>';
    print '<td class="right">'.($row['days'] === null ? '-' : price(round($row['days'], 1), 0, $langs, 0, -1, 1)).'</td>';
    print '<td class="right" data-paid-share="'.$share.'">'.($row['notices'] > 0 ? $share.' %' : '-').'</td></tr>';
}
print '</table></div><br>';

print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('MahnwesenPartFee').'</td><td data-open-fee="'.price2num($claims['fee']).'">'.price($claims['fee'], 0, $langs, 1, -1, -1, $conf->currency).'</td>';
print '<td class="titlefield">'.$langs->trans('MahnwesenPartInterest').'</td><td data-open-interest="'.price2num($claims['interest']).'">'.price($claims['interest'], 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
print '</table><br>';

print load_fiche_titre($langs->trans('MahnwesenStatsCustomers'), '', 'company');
print '<div class="div-table-responsive"><table class="noborder centpercent" id="mahnwesen-stats-customers">';
print '<tr class="liste_titre"><th>'.$langs->trans('ThirdParty').'</th><th class="right">'.$langs->trans('MahnwesenStatsCases').'</th><th class="right">'.$langs->trans('OpenAmount').'</th></tr>';
if (empty($customers)) { print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('NoOverdueInvoiceFound').'</span></td></tr>'; }
foreach ($customers as $row) {
    print '<tr class="oddeven"><td><a href="'.dol_escape_htmltag(dol_buildpath('/mahnwesen/customer.php?socid='.((int) $row->socid), 1)).'">'.dol_escape_htmltag($row->name).'</a></td>';
    print '<td class="right">'.((int) $row->cases).'</td><td class="right">'.price((float) $row->amount, 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
}
print '</table></div><br>';

print load_fiche_titre($langs->trans('MahnwesenStatsProfiles'), '', 'generic');
print '<div class="div-table-responsive"><table class="noborder centpercent" id="mahnwesen-stats-profiles">';
print '<tr class="liste_titre"><th>'.$langs->trans('MahnwesenProfile').'</th><th class="right">'.$langs->trans('MahnwesenStatsCases').'</th><th class="right">'.$langs->trans('OpenAmount').'</th></tr>';
if (empty($profiles)) { print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('NoOverdueInvoiceFound').'</span></td></tr>'; }
foreach ($profiles as $label => $row) {
    print '<tr class="oddeven"><td>'.dol_escape_htmltag($label).'</td><td class="right">'.((int) $row['cases']).'</td>';
    print '<td class="right">'.price((float) $row['amount'], 0, $langs, 1, -1, -1, $conf->currency).'</td></tr>';
}
print '</table></div>';
llxFooter();
$db->close();
