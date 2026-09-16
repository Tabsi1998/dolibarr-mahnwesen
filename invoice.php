<?php
/* Mahnwesen development - compact customer invoice dunning tab */
if (!defined('CSRFCHECK_WITH_TOKEN')) { define('CSRFCHECK_WITH_TOKEN', 1); }

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) { $res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php'; }
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__); $i = strlen($tmp)-1; $j = strlen($tmp2)-1;
while ($i > 0 && $j > 0 && isset($tmp[$i], $tmp2[$j]) && $tmp[$i] === $tmp2[$j]) { $i--; $j--; }
if (!$res && $i > 0 && file_exists(substr($tmp, 0, $i + 1).'/main.inc.php')) { $res = @include substr($tmp, 0, $i + 1).'/main.inc.php'; }
if (!$res && file_exists('../main.inc.php')) { $res = @include '../main.inc.php'; }
if (!$res && file_exists('../../main.inc.php')) { $res = @include '../../main.inc.php'; }
if (!$res) { die('Include of main fails'); }

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);
require_once dol_buildpath('/mahnwesen/class/dunningnotice.class.php', 0);

$langs->loadLangs(array('mahnwesen@mahnwesen', 'bills', 'companies', 'users', 'agenda'));
if (!isModEnabled('mahnwesen') || !empty($user->socid) || !$user->hasRight('mahnwesen', 'dashboard', 'read') || !$user->hasRight('facture', 'lire')) { accessforbidden(); }

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$edit = GETPOST('edit', 'aZ09');
$pauseReason = GETPOST('pause_reason', 'nohtml');
$pauseUntil = GETPOST('pause_until', 'alphanohtml');
$caseNote = GETPOST('case_note', 'nohtml');
$skipReason = GETPOST('skip_reason', 'nohtml');
if ($id <= 0) { accessforbidden('Missing invoice id'); }

$invoice = new Facture($db);
if ($invoice->fetch($id) <= 0) { dol_print_error($db, $invoice->error); exit; }
$result = restrictedArea($user, 'facture', $invoice->id, '', '', 'fk_soc', 'rowid');
$invoice->fetch_thirdparty();
$manager = new DunningManager($db);
$noticeService = new DunningNoticeService($db, $manager);

function mahnwesenInvoiceRedirect($invoiceId)
{
    header('Location: '.dol_buildpath('/mahnwesen/invoice.php?id='.(int) $invoiceId, 1));
    exit;
}

if ($action === 'sync_case') {
    if (!$user->hasRight('mahnwesen', 'case', 'write')) { accessforbidden(); }
    $result = $manager->syncInvoiceCase($id, $user);
    if ($result === false) { setEventMessages($manager->error, $manager->errors, 'errors'); }
    else { setEventMessages($langs->trans('SingleCaseSyncResult', $langs->trans('SyncResult'.ucfirst($result))), null, 'mesgs'); }
    mahnwesenInvoiceRedirect($id);
} elseif ($action === 'pause_case' || $action === 'resume_case') {
    if (!$user->hasRight('mahnwesen', 'case', 'write')) { accessforbidden(); }
    $paused = ($action === 'pause_case');
    if ($manager->setPaused($id, $paused, $user, $pauseReason, $paused ? $pauseUntil : '')) {
        setEventMessages($langs->trans($paused ? 'CasePausedMessage' : 'CaseResumedMessage'), null, 'mesgs');
    } else { setEventMessages($manager->error, $manager->errors, 'errors'); }
    mahnwesenInvoiceRedirect($id);
} elseif ($action === 'update_note') {
    if (!$user->hasRight('mahnwesen', 'case', 'write')) { accessforbidden(); }
    if ($manager->updateCaseNote($id, $caseNote, $user)) { setEventMessages($langs->trans('CaseNoteSaved'), null, 'mesgs'); }
    else { setEventMessages($manager->error, $manager->errors, 'errors'); }
    mahnwesenInvoiceRedirect($id);
} elseif ($action === 'skip_stage') {
    if (!$user->hasRight('mahnwesen', 'case', 'write') || !$user->hasRight('mahnwesen', 'notice', 'send')) { accessforbidden(); }
    if ($manager->skipCurrentStage($id, $skipReason, $user)) { setEventMessages($langs->trans('MahnwesenStageSkipped'), null, 'mesgs'); }
    else { setEventMessages($langs->trans('MahnwesenStageSkipFailed'), null, 'errors'); }
    mahnwesenInvoiceRedirect($id);
} elseif ($action === 'generate_notice_pdf') {
    if (!$user->hasRight('mahnwesen', 'notice', 'send')) { accessforbidden(); }
    $pdfWorkflow = $manager->getWorkflowState($id);
    $pdfCase = $pdfWorkflow ? $pdfWorkflow['case'] : false;
    $pdfLevel = $pdfWorkflow ? (int) $pdfWorkflow['next_required_level'] : 0;
    if (!$pdfWorkflow || empty($pdfWorkflow['actionable']) || !$pdfCase || $pdfLevel <= 0) {
        setEventMessages($langs->trans('NoticeNotReady'), null, 'errors');
    } else {
        $customerLang = !empty($invoice->thirdparty->default_lang) ? (string) $invoice->thirdparty->default_lang : $langs->defaultlang;
        $pdfTemplate = $noticeService->getTemplate($pdfLevel, $customerLang, $user);
        if ($pdfTemplate === false) {
            setEventMessages($noticeService->error, $noticeService->errors, 'errors');
        } else {
            $pdfLang = !empty($pdfTemplate['lang']) ? (string) $pdfTemplate['lang'] : $customerLang;
            $pdfBody = $noticeService->renderTemplate($pdfTemplate['body'], $invoice, $pdfCase, $pdfLevel, $pdfLang);
            $pdfInfo = $noticeService->generatePdf($invoice, $pdfCase, $pdfLevel, $pdfBody, false, $pdfLang);
            if ($pdfInfo === false) {
                setEventMessages($noticeService->error, $noticeService->errors, 'errors');
            } else {
                if ($manager->recordGeneratedDocument($id, $pdfLevel, $pdfInfo['relative'], $user, 'manual')) {
                    setEventMessages($langs->trans('MahnwesenPdfGenerated'), null, 'mesgs');
                } else {
                    setEventMessages($manager->error, $manager->errors, 'warnings');
                }
            }
        }
    }
    mahnwesenInvoiceRedirect($id);
}

$workflow = $manager->getWorkflowState($id);
if ($workflow === false) {
    setEventMessages($manager->error, $manager->errors, 'errors');
    $workflow = array('evaluation'=>array('eligible'=>0,'reason'=>'load_error','remain_to_pay'=>0,'row'=>array('days_late'=>0,'stage'=>0,'stage_key'=>'DunningStageNone','due_ymd'=>'')), 'case'=>false, 'calculated_level'=>0, 'next_required_level'=>0, 'next_future_level'=>0, 'completed_levels'=>array(), 'required_at'=>null, 'future_at'=>null, 'actionable'=>0);
}
$evaluation = $workflow['evaluation'];
$case = $workflow['case'];
$calculatedLevel = (int) $workflow['calculated_level'];
$requiredLevel = (int) $workflow['next_required_level'];
$futureLevel = (int) $workflow['next_future_level'];
$classification = $manager->classifyThirdparty($invoice->thirdparty);
$amountLevel = $requiredLevel > 0 ? $requiredLevel : ($case ? (int) $case['current_level'] : $calculatedLevel);
$caseForAmounts = $case ?: array('remaining_amount' => (float) $evaluation['remain_to_pay']);
$breakdown = $amountLevel > 0 ? $manager->getAmountBreakdown($invoice, $caseForAmounts, $amountLevel) : array('invoice'=>(float)$evaluation['remain_to_pay'],'fee'=>0.0,'total'=>(float)$evaluation['remain_to_pay'],'classification'=>$classification);

llxHeader('', $langs->trans('Mahnwesen').' - '.$invoice->ref, '', '', 0, 0, '', '', '', 'mod-mahnwesen page-invoice');
$head = facture_prepare_head($invoice);
print dol_get_fiche_head($head, 'mahnwesen', $langs->trans('InvoiceCustomer'), -1, 'bill');

// Same visual object identity as the regular invoice tabs.
print '<div class="fichecenter">';
print dol_banner_tab($invoice, 'ref', '', 0, 'ref', 'ref');
print '</div>';

print '<br>'.load_fiche_titre($langs->trans('DunningCase'), '', 'bill');
if (!$case) {
    print '<div class="warning">'.$langs->trans('CaseNotCreatedHelp').'</div>';
    if ($user->hasRight('mahnwesen', 'case', 'write') && !empty($evaluation['eligible'])) {
        print '<div class="tabsAction"><form method="POST" class="inline-block" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
        print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="sync_case">';
        print '<button class="butAction" type="submit">'.$langs->trans('CreateDunningCase').'</button></form></div>';
    }
} else {
    $statusHtml = $case['status'] === 'closed'
        ? '<span class="badge badge-status0">'.$langs->trans('CaseClosed').'</span>'
        : ($case['status'] === 'fee_open'
            ? '<span class="badge badge-status1">'.$langs->trans('MahnwesenCaseFeeOpen').'</span>'
        : (!empty($case['paused'])
            ? '<span class="badge badge-status1">'.$langs->trans('Paused').'</span>'
            : '<span class="badge badge-status4">'.$langs->trans('CaseActive').'</span>'));
    $calendarStageHtml = $calculatedLevel > 0
        ? '<strong>'.$langs->trans($manager->getStageLabelKey($calculatedLevel)).'</strong>'
        : '<span class="opacitymedium">'.$langs->trans('DunningStageNone').'</span>';
    if ($requiredLevel > 0) {
        $requiredStageHtml = '<strong>'.$langs->trans($manager->getStageLabelKey($requiredLevel)).'</strong>';
    } elseif ($futureLevel > 0) {
        $requiredStageHtml = '<span class="opacitymedium">'.$langs->trans('MahnwesenNoDueWorkflowStage').'</span>';
    } else {
        $requiredStageHtml = '<span class="badge badge-status4">'.$langs->trans('MahnwesenWorkflowComplete').'</span>';
    }
    $timingHtml = '-';
    if ($requiredLevel > 0 && !empty($workflow['required_at'])) {
        $requiredTs = (int) $db->jdate($workflow['required_at']);
        $timingHtml = ($requiredTs > dol_now())
            ? $langs->trans('MahnwesenStageAvailableAt', '<strong>'.dol_print_date($requiredTs, 'day').'</strong>')
            : $langs->trans('MahnwesenStageDueSince').' <strong>'.dol_print_date($requiredTs, 'day').'</strong>';
    } elseif ($futureLevel > 0 && !empty($workflow['future_at'])) {
        $timingHtml = $langs->trans($manager->getStageLabelKey($futureLevel)).' - '.dol_print_date($db->jdate($workflow['future_at']), 'day');
    }

    // One compact four-column field table instead of two widely separated half tables.
    print '<table class="border centpercent tableforfield">';
    print '<tr><td class="titlefield">'.$langs->trans('CaseStatus').'</td><td>'.$statusHtml.'</td><td class="titlefield">'.$langs->trans('CalculatedStage').'</td><td>'.$calendarStageHtml.'</td></tr>';
    print '<tr><td>'.$langs->trans('StoredOpenAmount').'</td><td>'.price((float) $case['remaining_amount'], 0, $langs, 1, -1, -1, $conf->currency).'</td><td>'.$langs->trans('MahnwesenNextRequiredStage').'</td><td>'.$requiredStageHtml.'</td></tr>';
    print '<tr><td>'.$langs->trans('DaysOverdue').'</td><td>'.((int) $evaluation['row']['days_late']).'</td><td>'.$langs->trans('NextAction').'</td><td>'.$timingHtml.'</td></tr>';
    print '<tr><td>'.$langs->trans('MahnwesenCustomerMasterType').'</td><td>'.$langs->trans($manager->getCustomerClassLabelKey($classification['class'])).' <span class="opacitymedium">'.dol_escape_htmltag($classification['code']).'</span></td><td>'.$langs->trans('MahnwesenLastNoticeAt').'</td><td>'.(!empty($case['last_notice_at']) ? dol_print_date($db->jdate($case['last_notice_at']), 'dayhour') : '-').'</td></tr>';
    print '<tr><td>'.$langs->trans('MahnwesenDunningFee').'</td><td>'.price((float) $breakdown['fee'], 0, $langs, 1, -1, -1, $conf->currency).'</td><td><strong>'.$langs->trans('MahnwesenDunningTotal').'</strong></td><td><strong>'.price((float) $breakdown['total'], 0, $langs, 1, -1, -1, $conf->currency).'</strong></td></tr>';
    print '</table>';

    if ($calculatedLevel > $requiredLevel && $requiredLevel > 0) {
        print '<br><div class="info">'.$langs->trans('MahnwesenSequentialGuardInfo', $langs->trans($manager->getStageLabelKey($calculatedLevel)), $langs->trans($manager->getStageLabelKey($requiredLevel))).'</div>';
    }

    // Compact read mode, similar to Dolibarr's note fields. Only the selected row
    // expands into an edit form after clicking its pencil.
    print '<br>'.load_fiche_titre($langs->trans('MahnwesenInternalProcessing'), '', 'note');
    print '<table class="border centpercent tableforfield">';

    print '<tr><td class="titlefield">'.$langs->trans('MahnwesenCaseNote').'</td><td>';
    if ($edit !== 'note') {
        print trim((string) $case['note_private']) !== ''
            ? dol_nl2br(dol_escape_htmltag($case['note_private']))
            : '<span class="opacitymedium">'.$langs->trans('None').'</span>';
        if ($user->hasRight('mahnwesen', 'case', 'write')) {
            print ' <a class="editfielda marginleftonly" href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?id='.$id.'&edit=note').'">'.img_picto($langs->trans('Edit'), 'edit').'</a>';
        }
    } else {
        print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
        print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="update_note">';
        print '<textarea name="case_note" class="centpercent" rows="5" placeholder="'.dol_escape_htmltag($langs->trans('MahnwesenCaseNotePlaceholder')).'">'.dol_escape_htmltag($case['note_private']).'</textarea>';
        print '<div class="margintoponly"><button class="button button-save" type="submit">'.$langs->trans('Save').'</button> <a class="button button-cancel" href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?id='.$id).'">'.$langs->trans('Cancel').'</a></div>';
        print '</form>';
    }
    print '</td></tr>';

    print '<tr><td>'.$langs->trans('MahnwesenPauseControl').'</td><td>';
    if (!empty($case['paused'])) {
        print '<span class="badge badge-status1">'.$langs->trans('Paused').'</span> ';
        // A pause row without a date is indefinite. Only pauses from before 0.6,
        // which have no pause row, kept their end date in next_action_at.
        $displayPauseUntil = !empty($case['pause_active']) ? $case['pause_until'] : $case['next_action_at'];
        print $displayPauseUntil
            ? $langs->trans('MahnwesenPauseUntil').' <strong>'.dol_print_date($db->jdate($displayPauseUntil), 'day').'</strong>'
            : '<strong>'.$langs->trans('MahnwesenPauseIndefinite').'</strong>';
        if ($user->hasRight('mahnwesen', 'case', 'write')) {
            print '<form method="POST" class="inline-block marginleftonly" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
            print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="resume_case">';
            print '<button type="submit" class="button">'.$langs->trans('ResumeCase').'</button></form>';
        }
    } elseif ($edit !== 'pause') {
        print '<span class="opacitymedium">'.$langs->trans('MahnwesenNotPaused').'</span>';
        if ($user->hasRight('mahnwesen', 'case', 'write')) {
            print ' <a class="editfielda marginleftonly" href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?id='.$id.'&edit=pause').'">'.img_picto($langs->trans('Edit'), 'edit').'</a>';
        }
    } else {
        print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
        print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="pause_case">';
        print '<div class="bold">'.$langs->trans('MahnwesenPauseReason').'</div>';
        print '<textarea name="pause_reason" class="centpercent" rows="3" placeholder="'.dol_escape_htmltag($langs->trans('MahnwesenPauseReasonPlaceholder')).'"></textarea>';
        print '<div class="margintoponly"><span class="bold">'.$langs->trans('MahnwesenPauseUntil').'</span> ';
        print '<input type="date" name="pause_until" class="flat"> <span class="opacitymedium">'.$langs->trans('MahnwesenPauseDateHelp').'</span></div>';
        print '<div class="margintoponly"><button class="button" type="submit">'.$langs->trans('PauseCase').'</button> <a class="button button-cancel" href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?id='.$id).'">'.$langs->trans('Cancel').'</a></div>';
        print '</form>';
    }
    print '</td></tr>';
    print '</table>';

    // One clear action bar. The primary action is always the first incomplete
    // due stage, never merely the latest calendar threshold.
    print '<div class="tabsAction">';
    if (!empty($workflow['actionable']) && $user->hasRight('mahnwesen', 'notice', 'send')) {
        $label = $langs->trans('MahnwesenSendStage', $langs->trans($manager->getStageLabelKey($requiredLevel)));
        print '<a class="butAction" href="'.dol_escape_htmltag(dol_buildpath('/mahnwesen/notice.php?id='.$id, 1)).'">'.img_picto('', 'email').' '.$label.'</a>';
        print '<form method="POST" class="inline-block" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
        print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="generate_notice_pdf">';
        print '<button class="butAction" type="submit">'.img_picto('', 'pdf').' '.$langs->trans('MahnwesenGenerateLinkedPdf', $langs->trans($manager->getStageLabelKey($requiredLevel))).'</button></form>';
        if ($user->hasRight('mahnwesen', 'case', 'write')) {
            print '<form method="POST" class="inline-block" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" onsubmit="return window.confirm(\''.dol_escape_js($langs->trans('MahnwesenSkipStageConfirm')).'\');">';
            print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="skip_stage">';
            print '<input required type="text" name="skip_reason" maxlength="255" placeholder="'.dol_escape_htmltag($langs->trans('MahnwesenSkipReason')).'"> <button class="butActionDelete" type="submit">'.$langs->trans('MahnwesenSkipStage').'</button></form>';
        }
    } elseif (!empty($case['paused'])) {
        print '<span class="butActionRefused classfortooltip" title="'.dol_escape_htmltag($langs->trans('NoticeCasePaused')).'">'.$langs->trans('MahnwesenPrepareNotice').'</span>';
    } elseif ($requiredLevel > 0 && !empty($workflow['required_at']) && ((int) $db->jdate($workflow['required_at'])) > dol_now()) {
        $waitTitle = $langs->trans('MahnwesenSequentialCooldownInfo', $langs->trans($manager->getStageLabelKey($requiredLevel)), dol_print_date($db->jdate($workflow['required_at']), 'day'));
        print '<span class="butActionRefused classfortooltip" title="'.dol_escape_htmltag($waitTitle).'">'.$langs->trans('MahnwesenPrepareStage', $langs->trans($manager->getStageLabelKey($requiredLevel))).'</span>';
    } elseif ($futureLevel > 0 && !empty($workflow['future_at'])) {
        print '<span class="butActionRefused classfortooltip" title="'.dol_escape_htmltag($langs->trans('MahnwesenWaitingForFutureStage', dol_print_date($db->jdate($workflow['future_at']), 'day'))).'">'.$langs->trans('MahnwesenPrepareNotice').'</span>';
    }
    print '</div>';
    if ($user->hasRight('mahnwesen', 'case', 'write')) {
        print '<div class="right opacitymedium small"><form method="POST" class="inline-block" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
        print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="sync_case">';
        print '<button class="button bordertransp" type="submit">'.img_picto('', 'refresh').' '.$langs->trans('SyncThisCase').'</button></form></div>';
    }
}

// Mahnwesen history remains immutable in module tables and is mirrored into
// Dolibarr's invoice Agenda. Avoid duplicating the full history in this tab.
$history = $manager->getHistoryByInvoice($id, 100);
if (isModEnabled('agenda')) {
    $agendaUrl = dol_buildpath('/compta/facture/agenda.php?id='.$id, 1);
    print '<div class="right opacitymedium small margintoponly">'.img_picto('', 'calendar').' <a href="'.dol_escape_htmltag($agendaUrl).'">'.$langs->trans('MahnwesenOpenInvoiceAgenda').'</a></div>';
} else {
    print '<br>'.load_fiche_titre($langs->trans('DunningHistory'), '', 'action');
    print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Event').'</td><td>'.$langs->trans('DunningStage').'</td><td>'.$langs->trans('Amount').'</td><td>'.$langs->trans('Message').'</td></tr>';
    if (empty($history)) { print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">'.$langs->trans('NoDunningHistory').'</span></td></tr>'; }
    foreach ($history as $item) {
        print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($item['date_creation']), 'dayhour').'</td><td>'.$langs->trans($manager->getHistoryActionLabelKey($item['action'])).'</td><td>'.($item['level'] > 0 ? $langs->trans($manager->getStageLabelKey((int)$item['level'])) : '-').'</td><td>'.price((float)$item['amount_snapshot'],0,$langs,1,-1,-1,$conf->currency).'</td><td>'.dol_nl2br(dol_escape_htmltag($item['message'])).'</td></tr>';
    }
    print '</table></div>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
