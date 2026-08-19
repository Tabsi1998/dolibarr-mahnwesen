<?php
/* Mahnwesen development - Dolibarr-style dunning email composer */
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
require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);
require_once dol_buildpath('/mahnwesen/class/dunningnotice.class.php', 0);

$langs->loadLangs(array('mahnwesen@mahnwesen', 'bills', 'companies', 'mails'));
if (!isModEnabled('mahnwesen') || !empty($user->socid) || !$user->hasRight('mahnwesen', 'dashboard', 'read')) { accessforbidden(); }

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
if ($id <= 0) { accessforbidden('Missing invoice id'); }

$invoice = new Facture($db);
if ($invoice->fetch($id) <= 0) { dol_print_error($db, $invoice->error); exit; }
$invoice->fetch_thirdparty();
$manager = new DunningManager($db);
$manager->ensureRuleRows($user);
$service = new DunningNoticeService($db, $manager);
$workflow = $manager->getWorkflowState($id);
if ($workflow === false) { setEventMessages($manager->error, $manager->errors, 'errors'); }
$case = $workflow ? $workflow['case'] : false;
$evaluation = $workflow ? $workflow['evaluation'] : false;
$level = $workflow ? (int) $workflow['next_required_level'] : 0;
$calculatedLevel = $workflow ? (int) $workflow['calculated_level'] : 0;
$displayedLevel = $level;
$displayedRemaining = $evaluation ? (float) $evaluation['remain_to_pay'] : ($case ? (float) $case['remaining_amount'] : 0.0);
// The irreversible send must compare against the exact state the user reviewed
// on the previous rendered form, not against a freshly recomputed state at the
// beginning of the POST request.
$preparedLevel = ($action === 'send_notice' && GETPOSTISSET('prepared_level')) ? GETPOSTINT('prepared_level') : $displayedLevel;
$preparedRemaining = $displayedRemaining;
if ($action === 'send_notice' && GETPOSTISSET('prepared_remaining')) {
    $postedRemaining = GETPOST('prepared_remaining', 'alphanohtml');
    if (is_numeric($postedRemaining)) { $preparedRemaining = (float) $postedRemaining; }
}

if (!$case) { setEventMessages($langs->trans('NoticeRequiresCase'), null, 'errors'); }
elseif ($case['status'] === 'closed') { setEventMessages($langs->trans('NoticeCaseClosed'), null, 'errors'); }
elseif (!empty($case['paused'])) { setEventMessages($langs->trans('NoticeCasePaused'), null, 'warnings'); }
elseif ($calculatedLevel > 0 && $level <= 0) { setEventMessages($langs->trans('MahnwesenNoDueWorkflowStage'), null, 'mesgs'); }

$customerLang = (!empty($invoice->thirdparty) && !empty($invoice->thirdparty->default_lang)) ? (string) $invoice->thirdparty->default_lang : $langs->defaultlang;
$templates = $level > 0 ? $service->getNativeTemplates($level) : array();
$templateId = GETPOSTINT('template_id');
$template = false;
if ($templateId > 0 && $level > 0) { $template = $service->getNativeTemplateById($templateId, $level); }
if ($template === false && $level > 0) { $template = $service->getTemplate($level, $customerLang, $user); }
if ($template === false && $level > 0) { setEventMessages($service->error, $service->errors, 'errors'); }
if ($template !== false) { $templateId = (int) $template['source_id']; }

$templateLang = ($template !== false && !empty($template['lang'])) ? (string) $template['lang'] : $customerLang;
$templateFrom = ($template !== false && !empty($template['email_from'])) ? (string) $template['email_from'] : '';
$fromEmail = $service->getFromEmail($templateFrom);
$senderOptions = $service->getSenderOptions($user, $templateFrom);
$selectedFrom = $fromEmail;
if (in_array($action, array('apply_template', 'generate_preview', 'generate_document', 'send_notice'), true) && GETPOSTISSET('from_email')) {
    $postedFrom = trim(GETPOST('from_email', 'email'));
    foreach ($senderOptions as $senderOption) {
        if (strcasecmp($postedFrom, $senderOption['email']) === 0) { $selectedFrom = $senderOption['email']; break; }
    }
}
$fromEmail = $selectedFrom;
$dummyCase = $case ?: array('remaining_amount'=>0, 'next_action_at'=>null, 'paused'=>0);
if ($evaluation) { $dummyCase['remaining_amount'] = (float) $evaluation['remain_to_pay']; }
$subject = $template !== false ? $service->renderTemplate($template['subject'], $invoice, $dummyCase, $level, $templateLang) : '';
$body = $template !== false ? $service->renderTemplate($template['body'], $invoice, $dummyCase, $level, $templateLang) : '';
$recipients = $service->getRecipientOptions($invoice);
$selectedRecipient = !empty($recipients) ? $recipients[0]['email'] : '';
$cc = '';
$bcc = '';
$invoicePdf = $service->getInvoicePdfPath($invoice);
$templateAttachDefault = ($template !== false && $template['source'] === 'native') ? ((string) ($template['joinfiles'] ?? '') === '1') : (getDolGlobalInt('MAHNWESEN_ATTACH_INVOICE_DEFAULT', 1) > 0);
$attachInvoice = ($invoicePdf !== '' && $templateAttachDefault);
$previewInfo = null;

// Preserve user edits for preview/send. Selecting another template deliberately
// reloads its rendered subject and HTML body.
if (in_array($action, array('apply_template', 'generate_preview', 'generate_document', 'send_notice'), true)) {
    if (GETPOSTISSET('recipient')) { $selectedRecipient = trim(GETPOST('recipient', 'email')); }
    $cc = trim(GETPOST('cc', 'nohtml'));
    $bcc = trim(GETPOST('bcc', 'nohtml'));
    $attachInvoice = GETPOSTINT('attach_invoice') > 0;
    // Applying a template deliberately replaces subject/body with the selected
    // Dolibarr template while keeping recipient/CC/BCC/attachment choices.
    if ($action !== 'apply_template') {
        if (GETPOSTISSET('subject')) { $subject = trim(GETPOST('subject', 'nohtml')); }
        if (GETPOSTISSET('body')) { $body = trim(GETPOST('body', 'restricthtml')); }
    }
}

$recipientAllowed = false;
foreach ($recipients as $r) { if (strcasecmp($r['email'], $selectedRecipient) === 0) { $recipientAllowed = true; break; } }

if ($action === 'generate_preview') {
    if (!$user->hasRight('mahnwesen', 'notice', 'send')) { accessforbidden(); }
    if (!$workflow || empty($workflow['actionable']) || $template === false || $level <= 0) {
        setEventMessages($langs->trans('NoticeNotReady'), null, 'errors');
    } else {
        $previewInfo = $service->generatePdf($invoice, $dummyCase, $level, $body, true, $templateLang);
        if ($previewInfo === false) { setEventMessages($service->error, $service->errors, 'errors'); }
        else { setEventMessages($langs->trans('NoticePreviewGenerated'), null, 'mesgs'); }
    }
} elseif ($action === 'generate_document') {
    if (!$user->hasRight('mahnwesen', 'notice', 'send')) { accessforbidden(); }
    if (!$workflow || empty($workflow['actionable']) || !$case || $template === false || $level <= 0) {
        setEventMessages($langs->trans('NoticeNotReady'), null, 'errors');
    } else {
        $finalInfo = $service->generatePdf($invoice, $case, $level, $body, false, $templateLang);
        if ($finalInfo === false) {
            setEventMessages($service->error, $service->errors, 'errors');
        } else {
            if ($manager->recordGeneratedDocument($id, $level, $finalInfo['relative'], $user, 'manual')) {
                setEventMessages($langs->trans('MahnwesenPdfGenerated'), null, 'mesgs');
            } else {
                setEventMessages($manager->error, $manager->errors, 'warnings');
            }
        }
    }
} elseif ($action === 'send_notice') {
    if (!$user->hasRight('mahnwesen', 'notice', 'send')) { accessforbidden(); }
    if (!$service->isManualSendEnabled()) {
        setEventMessages($langs->trans('ManualSendDisabled'), null, 'errors');
    } elseif (GETPOSTINT('confirm_send') !== 1) {
        setEventMessages($langs->trans('NoticeConfirmationRequired'), null, 'errors');
    } elseif (!$recipientAllowed) {
        setEventMessages($langs->trans('NoticeRecipientInvalid'), null, 'errors');
    } else {
        // Re-sync and re-read immediately before irreversible send. The user
        // must review again if stage or remaining amount changed while this
        // composer was open; otherwise old HTML/PDF amounts could be mailed.
        $syncCheck = $manager->syncInvoiceCase($id, $user);
        $currentWorkflow = ($syncCheck === false) ? false : $manager->getWorkflowState($id);
        $currentCase = $currentWorkflow ? $currentWorkflow['case'] : false;
        $currentRequired = $currentWorkflow ? (int) $currentWorkflow['next_required_level'] : 0;
        $stateChanged = (!$currentWorkflow || empty($currentWorkflow['actionable']) || !$currentCase || $preparedLevel <= 0 || $currentRequired !== (int) $preparedLevel || abs(((float) $currentCase['remaining_amount']) - $preparedRemaining) > 0.000001);
        if ($stateChanged) {
            setEventMessages($langs->trans('NoticeStateChanged'), null, 'errors');
        } elseif ($manager->hasSuccessfulNoticeAtLevel((int) $currentCase['id'], $preparedLevel)) {
            setEventMessages($langs->trans('NoticeAlreadySentAtLevel'), null, 'errors');
        } elseif ($manager->hasPendingNoticeAtLevel((int) $currentCase['id'], $preparedLevel)) {
            setEventMessages($langs->trans('NoticeSendPendingAtLevel'), null, 'errors');
        } else {
            $case = $currentCase;
            $level = $preparedLevel;
            $result = $service->sendNotice($invoice, $case, $level, $selectedRecipient, $subject, $body, $attachInvoice, $user, 'manual', $selectedFrom, $templateLang, $cc, $bcc);
            if ($result === false) { setEventMessages($service->error, $service->errors, 'errors'); }
            else {
                setEventMessages($langs->trans('NoticeSentSuccess', $selectedRecipient), null, 'mesgs');
                header('Location: '.dol_buildpath('/mahnwesen/invoice.php?id='.$id, 1)); exit;
            }
        }
    }
}

// Refresh display state after actions.
$workflow = $manager->getWorkflowState($id);
$case = $workflow ? $workflow['case'] : false;
$evaluation = $workflow ? $workflow['evaluation'] : false;
$level = $workflow ? (int) $workflow['next_required_level'] : 0;
$calculatedLevel = $workflow ? (int) $workflow['calculated_level'] : 0;
$sendPending = ($case && $level > 0) ? $manager->hasPendingNoticeAtLevel((int) $case['id'], $level) : false;
$manualSendEnabled = $service->isManualSendEnabled();
$caseForAmounts = $case ?: array('remaining_amount' => (float) ($evaluation ? $evaluation['remain_to_pay'] : 0));
if ($evaluation) { $caseForAmounts['remaining_amount'] = (float) $evaluation['remain_to_pay']; }
$breakdown = ($level > 0) ? $manager->getAmountBreakdown($invoice, $caseForAmounts, $level) : array('invoice'=>(float)($evaluation ? $evaluation['remain_to_pay'] : 0),'fee'=>0,'total'=>(float)($evaluation ? $evaluation['remain_to_pay'] : 0),'classification'=>$manager->classifyThirdparty($invoice->thirdparty));
$existingDunningPdf = ($level > 0) ? $service->getExistingFinalPdfInfo($invoice, $level) : false;
$expectedDunningFilename = ($level > 0) ? $service->getFinalPdfFilename($invoice, $level) : '';
$invoiceRelative = !empty($invoice->last_main_doc) ? ltrim((string) $invoice->last_main_doc, '/') : (dol_sanitizeFileName($invoice->ref).'/'.dol_sanitizeFileName($invoice->ref).'.pdf');
$canOperate = $workflow && !empty($workflow['actionable']) && $template !== false && $user->hasRight('mahnwesen', 'notice', 'send');

llxHeader('', $langs->trans('PrepareDunningNotice').' - '.$invoice->ref, '', '', 0, 0, '', '', '', 'mod-mahnwesen page-notice');
$head = facture_prepare_head($invoice);
print dol_get_fiche_head($head, 'mahnwesen', $langs->trans('InvoiceCustomer'), -1, 'bill');

// Compact context bar: user can see why this exact stage is being sent.
print '<div class="fichecenter"><div class="fichehalfleft"><table class="border centpercent tableforfield">';
print '<tr><td class="titlefieldmiddle">'.$langs->trans('Invoice').'</td><td>'.$invoice->getNomUrl(1).'</td></tr>';
print '<tr><td>'.$langs->trans('ThirdParty').'</td><td>'.$invoice->thirdparty->getNomUrl(1).'</td></tr>';
print '<tr><td>'.$langs->trans('DaysOverdue').'</td><td>'.((int) ($evaluation ? $evaluation['row']['days_late'] : 0)).'</td></tr>';
print '</table></div><div class="fichehalfright"><table class="border centpercent tableforfield">';
print '<tr><td class="titlefieldmiddle">'.$langs->trans('CalculatedStage').'</td><td><strong>'.($calculatedLevel > 0 ? $langs->trans($manager->getStageLabelKey($calculatedLevel)) : '-').'</strong></td></tr>';
print '<tr><td>'.$langs->trans('MahnwesenNextRequiredStage').'</td><td><strong>'.($level > 0 ? $langs->trans($manager->getStageLabelKey($level)) : $langs->trans('MahnwesenNoDueWorkflowStage')).'</strong></td></tr>';
print '<tr><td>'.$langs->trans('MahnwesenDunningTotal').'</td><td><strong>'.price($breakdown['total'], 0, $langs, 1, -1, -1, $conf->currency).'</strong> <span class="opacitymedium">('.price($breakdown['invoice'], 0, $langs, 1, -1, -1, $conf->currency).' + '.price($breakdown['fee'], 0, $langs, 1, -1, -1, $conf->currency).')</span></td></tr>';
print '</table></div></div><div class="clearboth"></div>'.dol_get_fiche_end();

print '<br>'.load_fiche_titre($langs->trans('MahnwesenEmailComposer'), '', 'email');
if ($calculatedLevel > $level && $level > 0) { print '<div class="info">'.$langs->trans('MahnwesenSequentialGuardInfo', $langs->trans($manager->getStageLabelKey($calculatedLevel)), $langs->trans($manager->getStageLabelKey($level))).'</div><br>'; }
if ($level > 0 && !empty($workflow['required_at']) && ((int) $db->jdate($workflow['required_at'])) > dol_now()) { print '<div class="info">'.$langs->trans('MahnwesenSequentialCooldownInfo', $langs->trans($manager->getStageLabelKey($level)), dol_print_date($db->jdate($workflow['required_at']), 'day')).'</div><br>'; }
if (!$manualSendEnabled) { print '<div class="warning">'.$langs->trans('ManualSendDisabledHelp').'</div><br>'; }
if ($sendPending) { print '<div class="warning">'.$langs->trans('NoticeSendPendingAtLevel').'</div><br>'; }
if ($fromEmail === '') { print '<div class="error">'.$langs->trans('NoticeNoSenderEmail').'</div><br>'; }
if (empty($recipients)) { print '<div class="error">'.$langs->trans('NoticeNoRecipient').'</div><br>'; }

$templatePayload = array();
foreach ($templates as $tpl) {
    $tplLang = !empty($tpl['lang']) ? (string) $tpl['lang'] : $customerLang;
    $templatePayload[(int) $tpl['id']] = array(
        'subject' => $service->renderTemplate((string) $tpl['topic'], $invoice, $dummyCase, $level, $tplLang),
        'body' => $service->renderTemplate((string) $tpl['content'], $invoice, $dummyCase, $level, $tplLang),
        'from' => $service->getFromEmail((string) $tpl['email_from']),
        'attach_invoice' => ((string) $tpl['joinfiles'] === '1') ? 1 : 0,
    );
}

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" onsubmit="var f=this;setTimeout(function(){var bs=f.querySelectorAll(\'button[type=submit]\');for(var i=0;i<bs.length;i++){bs[i].disabled=true;}},0);">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="id" value="'.$id.'">';
print '<input type="hidden" name="prepared_level" value="'.((int) $level).'">';
print '<input type="hidden" name="prepared_remaining" value="'.dol_escape_htmltag(number_format((float) ($evaluation ? $evaluation['remain_to_pay'] : 0), 6, '.', '')).'">';

print '<div class="center marginbottomonly mahnwesen-templatebar"><span class="opacitymedium marginrightonly">'.$langs->trans('MahnwesenEmailTemplateLabel').'</span> ';
if (!empty($templates)) {
    print '<select name="template_id" id="mahnwesen_template_id" class="minwidth300">';
    foreach ($templates as $tpl) {
        print '<option value="'.((int) $tpl['id']).'"'.(((int) $tpl['id'] === (int) $templateId) ? ' selected' : '').'>'.dol_escape_htmltag($tpl['label']).(!empty($tpl['defaultfortype']) ? ' - '.$langs->trans('Default') : '').'</option>';
    }
    print '</select> <button class="button" type="button" id="mahnwesen_apply_template">'.$langs->trans('MahnwesenApplyEmailTemplate').'</button>';
} else { print '<span class="error">'.$langs->trans('MahnwesenNoTemplateForStage').'</span>'; }
print '</div>';

print '<div class="fichecenter"><table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('From').'</td><td>';
if (!empty($senderOptions)) {
    print '<select name="from_email" id="mahnwesen_from_email" class="minwidth500">';
    foreach ($senderOptions as $senderOption) {
        $sel = strcasecmp($fromEmail, $senderOption['email']) === 0 ? ' selected' : '';
        print '<option value="'.dol_escape_htmltag($senderOption['email']).'"'.$sel.'>'.dol_escape_htmltag($senderOption['label']).'</option>';
    }
    print '</select>';
} else { print '<span class="error">'.$langs->trans('NotAvailable').'</span>'; }
print '</td></tr>';
print '<tr><td>'.$langs->trans('To').'</td><td>';
if (!empty($recipients)) {
    print '<select name="recipient" class="minwidth500">';
    foreach ($recipients as $r) {
        $selected = strcasecmp($selectedRecipient, $r['email']) === 0 ? ' selected' : '';
        $src = $r['source'] === 'billing_contact' ? $langs->trans('RecipientSourceBilling') : $langs->trans('RecipientSourceThirdparty');
        print '<option value="'.dol_escape_htmltag($r['email']).'"'.$selected.'>'.dol_escape_htmltag($r['label'].' - '.$src).'</option>';
    }
    print '</select>';
} else { print '-'; }
print '</td></tr>';
print '<tr><td>'.$langs->trans('MahnwesenCopyTo').'</td><td><input type="text" name="cc" class="minwidth500" value="'.dol_escape_htmltag($cc).'" placeholder="name@example.com, zweite@example.com"></td></tr>';
print '<tr><td>'.$langs->trans('MahnwesenBlindCopyTo').'</td><td><input type="text" name="bcc" class="minwidth500" value="'.dol_escape_htmltag($bcc).'" placeholder="name@example.com"></td></tr>';
print '<tr><td><strong>'.$langs->trans('MahnwesenEmailSubject').'</strong></td><td><input type="text" id="mahnwesen_subject" name="subject" class="centpercent" maxlength="255" value="'.dol_escape_htmltag($subject).'"></td></tr>';
print '<tr><td>'.$langs->trans('AttachedFiles').'</td><td>';
// Dunning PDF: exactly one deterministic final filename per invoice/stage.
print '<div class="margintoponly marginbottomonly">'.img_picto('', 'pdf').' <strong>'.dol_escape_htmltag($expectedDunningFilename).'</strong> ';
if ($existingDunningPdf !== false) {
    $dunningUrl = DOL_URL_ROOT.'/document.php?modulepart=invoice&file='.urlencode($existingDunningPdf['relative']);
    print '<a class="marginleftonly" target="_blank" rel="noopener" href="'.dol_escape_htmltag($dunningUrl).'">'.img_picto($langs->trans('View'), 'view').'</a>';
    print ' <span class="badge badge-status4 marginleftonly">'.$langs->trans('MahnwesenAttachmentReady').'</span>';
} else {
    print '<span class="opacitymedium marginleftonly">'.$langs->trans('DunningPdfWillBeAttached').'</span>';
}
print '</div>';
if ($invoicePdf !== '') {
    $invoiceUrl = DOL_URL_ROOT.'/document.php?modulepart=invoice&file='.urlencode($invoiceRelative);
    print '<div class="margintoponly"><label><input type="checkbox" id="mahnwesen_attach_invoice" name="attach_invoice" value="1"'.($attachInvoice ? ' checked' : '').'> '.img_picto('', 'pdf').' <strong>'.dol_escape_htmltag(basename($invoicePdf)).'</strong></label> ';
    print '<a class="marginleftonly" target="_blank" rel="noopener" href="'.dol_escape_htmltag($invoiceUrl).'">'.img_picto($langs->trans('View'), 'view').'</a>';
    print ' <span class="opacitymedium marginleftonly">'.$langs->trans('AttachOriginalInvoicePdf').'</span></div>';
} else {
    print '<div class="opacitymedium margintoponly">'.$langs->trans('OriginalInvoicePdfUnavailable').'</div>';
}
print '</td></tr>';
print '</table></div>';

print '<br><div class="fieldrequired bold">'.$langs->trans('Message').'</div>';
$editorEnabled = getDolGlobalString('FCKEDITOR_ENABLE_MAIL') ? 1 : 0;
$editor = new DolEditor('body', $body, '', 300, 'dolibarr_mailings', 'In', true, true, $editorEnabled, 10, '100%');
print '<div class="mahnwesen-mail-editor">'.$editor->Create(1).'</div>';

print '<input type="hidden" name="confirm_send" id="mahnwesen_confirm_send" value="0">';
print '<div class="tabsAction">';
$sendLabel = $level > 0 ? $langs->trans('MahnwesenSendStage', $langs->trans($manager->getStageLabelKey($level))) : $langs->trans('SendMail');
if ($canOperate && $manualSendEnabled && !$sendPending && $fromEmail !== '' && !empty($recipients)) {
    $confirmText = dol_escape_js($langs->trans('MahnwesenSendConfirmJs', $level > 0 ? $langs->trans($manager->getStageLabelKey($level)) : ''));
    print '<button class="butAction button-save" type="submit" name="action" value="send_notice" onclick="if(!window.confirm(\''.$confirmText.'\')){return false;} document.getElementById(\'mahnwesen_confirm_send\').value=\'1\';">'.img_picto('', 'email').' '.$sendLabel.'</button>';
} else {
    $reason = !$manualSendEnabled ? $langs->trans('ManualSendDisabledHelp') : ($sendPending ? $langs->trans('NoticeSendPendingAtLevel') : (($fromEmail === '' || empty($recipients)) ? $langs->trans('NoticeNotReady') : ''));
    print '<span class="butActionRefused classfortooltip" title="'.dol_escape_htmltag($reason).'">'.img_picto('', 'email').' '.$sendLabel.'</span>';
}
print '<a class="butAction" href="'.dol_escape_htmltag(dol_buildpath('/mahnwesen/invoice.php?id='.$id, 1)).'">'.$langs->trans('Cancel').'</a>';
print '</div>';
print '</form>';

print '<div class="opacitymedium small">'.$langs->trans('MahnwesenSequentialSafetyFooter').'</div>';
if (!empty($templatePayload)) {
    print '<script>';
    print 'window.MAHNWESEN_TEMPLATE_DATA='.json_encode($templatePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).';';
    print <<<'JS'
(function(){
  var sel=document.getElementById('mahnwesen_template_id');
  if(!sel) return;
  var apply=document.getElementById('mahnwesen_apply_template');
  if(!apply) return;
  apply.addEventListener('click', function(){
    var d=window.MAHNWESEN_TEMPLATE_DATA[String(sel.value)] || window.MAHNWESEN_TEMPLATE_DATA[sel.value];
    if(!d) return;
    var subject=document.getElementById('mahnwesen_subject');
    if(subject) subject.value=d.subject || '';
    var from=document.getElementById('mahnwesen_from_email');
    if(from && d.from){
      for(var i=0;i<from.options.length;i++){ if(from.options[i].value.toLowerCase()===String(d.from).toLowerCase()){ from.selectedIndex=i; break; } }
    }
    var attach=document.getElementById('mahnwesen_attach_invoice');
    if(attach) attach.checked=!!d.attach_invoice;
    if(window.CKEDITOR && CKEDITOR.instances && CKEDITOR.instances.body){ CKEDITOR.instances.body.setData(d.body || ''); }
    else { var body=document.getElementById('body'); if(body) body.value=d.body || ''; }
  });
})();
JS;
    print '</script>';
}
llxFooter();
$db->close();
