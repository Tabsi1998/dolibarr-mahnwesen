<?php
/* Mahnwesen - native Dolibarr FormMail composer with controlled delivery. */
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);
require_once dol_buildpath('/mahnwesen/class/dunningnotice.class.php', 0);

$langs->loadLangs(array('mahnwesen@mahnwesen', 'bills', 'companies', 'mails', 'other'));
if (!isModEnabled('mahnwesen') || !empty($user->socid) || !$user->hasRight('mahnwesen', 'dashboard', 'read') || !$user->hasRight('facture', 'lire')) { accessforbidden(); }

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
if ($id <= 0) { accessforbidden('Missing invoice id'); }
$invoice = new Facture($db);
if ($invoice->fetch($id) <= 0) { dol_print_error($db, $invoice->error); exit; }
$result = restrictedArea($user, 'facture', $invoice->id, '', '', 'fk_soc', 'rowid');
$invoice->fetch_thirdparty();

$manager = new DunningManager($db);
$service = new DunningNoticeService($db, $manager);

// Deliver a generated preview PDF behind the checks above. document.php cannot
// serve it: Dolibarr loads the document hook context only after its own access
// check, and the module has no plain read right that check would accept.
// No "action" parameter, so the page-wide CSRF token rule does not apply to
// this read-only request.
if (GETPOSTISSET('preview_pdf')) {
    if (!$user->hasRight('mahnwesen', 'notice', 'send')) { accessforbidden(); }
    $previewPath = $service->getPreviewPdfPath($invoice, GETPOSTINT('preview_pdf'));
    if ($previewPath === '') { http_response_code(404); print 'Preview not found'; exit; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="'.basename($previewPath).'"');
    header('Content-Length: '.filesize($previewPath));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($previewPath);
    exit;
}

$trackid = 'mahnwesen'.$id.'u'.((int) $user->id);
$formmailSession = new FormMail($db);
$formmailSession->trackid = $trackid;
$extraUploadRoot = $conf->user->dir_output.'/'.$user->id.'/mahnwesen-mail/'.$trackid;
$templateApply = GETPOST('modelselected', 'alpha') && GETPOSTINT('modelmailselected') > 0;

/** Clear only this composer's additional files, never the user's shared temp directory. */
function mw_notice_clear_extra_files($formmail, $allowedRoot)
{
    $attached = $formmail->get_attached_files();
    $root = realpath($allowedRoot);
    foreach (!empty($attached['paths']) && is_array($attached['paths']) ? $attached['paths'] : array() as $path) {
        $candidate = realpath($path);
        if ($candidate && $root && strpos(str_replace('\\', '/', $candidate), rtrim(str_replace('\\', '/', $root), '/').'/') === 0) { dol_delete_file($candidate); }
    }
    $sessionSuffix = empty($formmail->trackid) ? '' : '-'.$formmail->trackid;
    unset($_SESSION['listofpaths'.$sessionSuffix], $_SESSION['listofnames'.$sessionSuffix], $_SESSION['listofmimes'.$sessionSuffix]);
}

// Additional files use Dolibarr's own mail-upload session mechanism. Fixed
// dunning/invoice documents cannot be removed accidentally from the composer.
if (GETPOST('trackid', 'aZ09') !== $trackid || $templateApply) { mw_notice_clear_extra_files($formmailSession, $extraUploadRoot); }
if (GETPOST('addfile', 'alpha')) {
    if (!$user->hasRight('mahnwesen', 'notice', 'send')) { accessforbidden(); }
    dol_mkdir($extraUploadRoot);
    dol_add_file_process($extraUploadRoot, 1, 0, 'addedfile', '', null, $trackid, 0);
    $uploaded = $formmailSession->get_attached_files();
    $uploadKeys = !empty($uploaded['paths']) && is_array($uploaded['paths']) ? array_keys($uploaded['paths']) : array();
    rsort($uploadKeys, SORT_NUMERIC);
    $uploadCountMax = max(0, min(20, getDolGlobalInt('MAHNWESEN_MAX_EXTRA_ATTACHMENTS', 5)));
    $uploadBytesMax = max(1, min(100, getDolGlobalInt('MAHNWESEN_MAX_EXTRA_ATTACHMENT_MB', 10))) * 1024 * 1024;
    $uploadRejected = false;
    foreach ($uploadKeys as $uploadKey) {
        $uploadPath = isset($uploaded['paths'][$uploadKey]) ? realpath($uploaded['paths'][$uploadKey]) : false;
        $uploadSize = $uploadPath && is_file($uploadPath) ? filesize($uploadPath) : false;
        if ((int) $uploadKey >= $uploadCountMax || $uploadSize === false || $uploadSize <= 0 || $uploadSize > $uploadBytesMax) {
            $uploadRootReal = realpath($extraUploadRoot);
            if ($uploadPath && $uploadRootReal && strpos(str_replace('\\', '/', $uploadPath), rtrim(str_replace('\\', '/', $uploadRootReal), '/').'/') === 0) { dol_delete_file($uploadPath); }
            $formmailSession->remove_attached_files((int) $uploadKey);
            $uploadRejected = true;
        }
    }
    if ($uploadRejected) { setEventMessages($langs->trans('MahnwesenAttachmentRejected'), null, 'warnings'); }
    $action = 'composer';
}
$removeExtra = GETPOSTINT('remove_extra');
if ($removeExtra > 0) {
    if (!$user->hasRight('mahnwesen', 'notice', 'send')) { accessforbidden(); }
    $attachedBeforeRemoval = $formmailSession->get_attached_files();
    $removeIndex = $removeExtra - 1;
    if (isset($attachedBeforeRemoval['paths'][$removeIndex])) {
        $candidate = realpath($attachedBeforeRemoval['paths'][$removeIndex]);
        $allowedRoot = realpath($extraUploadRoot);
        if ($candidate && $allowedRoot && strpos(str_replace('\\', '/', $candidate), rtrim(str_replace('\\', '/', $allowedRoot), '/').'/') === 0) { dol_delete_file($candidate); }
        $formmailSession->remove_attached_files($removeIndex);
    }
    $action = 'composer';
}

$workflow = $manager->getWorkflowState($id);
if ($workflow === false) { setEventMessages($manager->error, $manager->errors, 'errors'); }
$case = $workflow ? $workflow['case'] : false;
$evaluation = $workflow ? $workflow['evaluation'] : false;
$level = $workflow ? (int) $workflow['next_required_level'] : 0;
$calculatedLevel = $workflow ? (int) $workflow['calculated_level'] : 0;
$displayedRemaining = $evaluation ? (float) $evaluation['remain_to_pay'] : ($case ? (float) $case['remaining_amount'] : 0.0);
$preparedLevel = GETPOSTISSET('prepared_level') ? GETPOSTINT('prepared_level') : $level;
$preparedRemaining = $displayedRemaining;
if (GETPOSTISSET('prepared_remaining')) { $postedRemaining = GETPOST('prepared_remaining', 'alphanohtml'); if (is_numeric($postedRemaining)) { $preparedRemaining = (float) $postedRemaining; } }

if (!$case) { setEventMessages($langs->trans('NoticeRequiresCase'), null, 'errors'); }
elseif ($case['status'] !== 'open') { setEventMessages($langs->trans('NoticeCaseClosed'), null, 'errors'); }
elseif (!empty($case['paused'])) { setEventMessages($langs->trans('NoticeCasePaused'), null, 'warnings'); }
elseif ($calculatedLevel > 0 && $level <= 0) { setEventMessages($langs->trans('MahnwesenNoDueWorkflowStage'), null, 'mesgs'); }

$customerLang = (!empty($invoice->thirdparty) && !empty($invoice->thirdparty->default_lang)) ? (string) $invoice->thirdparty->default_lang : $langs->defaultlang;
$templateId = GETPOSTINT('modelmailselected');
$template = ($templateId > 0 && $level > 0) ? $service->getTemplateById($templateId, $level, $customerLang, $user) : false;
if ($template === false && $level > 0) { $template = $service->getTemplate($level, $customerLang, $user); }
if ($template === false && $level > 0) { setEventMessages($service->error, $service->errors, 'errors'); }
if ($template !== false) { $templateId = (int) $template['source_id']; }
$templateLang = ($template !== false && !empty($template['lang'])) ? (string) $template['lang'] : $customerLang;
$dummyCase = $case ?: array('remaining_amount' => 0, 'next_action_at' => null, 'paused' => 0);
if ($evaluation) { $dummyCase['remaining_amount'] = (float) $evaluation['remain_to_pay']; }
$subject = $template !== false ? $service->renderTemplate($template['subject'], $invoice, $dummyCase, $level, $templateLang) : '';
$body = $template !== false ? $service->renderTemplate($template['body'], $invoice, $dummyCase, $level, $templateLang) : '';
if (GETPOSTISSET('subject') && !$templateApply) { $subject = trim(GETPOST('subject', 'restricthtml')); }
if (GETPOSTISSET('message') && !$templateApply) { $body = trim(GETPOST('message', 'restricthtml')); }
$subject = trim(dol_string_nohtmltag((string) $subject));

$recipientOptions = $service->getRecipientOptions($invoice);
$recipientFormOptions = array();
foreach ($recipientOptions as $option) {
    $key = $option['source'] === 'thirdparty' ? 'thirdparty' : (string) ((int) $option['contact_id']);
    $recipientFormOptions[$key] = array('label' => (string) $option['label']);
}
$receiver = GETPOST('receiver', 'array');
if (!is_array($receiver)) { $receiver = $receiver !== '' ? array($receiver) : array(); }
if (empty($receiver) && count($recipientFormOptions) === 1 && !getDolGlobalInt('MAIN_MAIL_NO_WITH_TO_SELECTED')) {
    $receiver = array((string) key($recipientFormOptions));
    $_POST['receiver'] = $receiver;
}
$recipientOption = count($receiver) === 1 ? $service->getRecipientOptionByFormValue($invoice, reset($receiver)) : false;
$selectedRecipient = $recipientOption ? (string) $recipientOption['email'] : '';

$templateFrom = $template !== false ? (string) $template['email_from'] : '';
$preferredFrom = $service->getFromEmail($templateFrom);
$defaultFromType = $service->getNativeSenderType($preferredFrom, $user);
$fromType = GETPOSTISSET('fromtype') ? GETPOST('fromtype', 'alpha') : $defaultFromType;
if ($templateApply && $template !== false && $templateFrom !== '') { $fromType = 'from_template_'.$templateId; }
$selectedFrom = $service->resolveNativeSender($fromType, $templateId, $user, $preferredFrom);
$cc = trim(GETPOST('sendtocc', 'nohtml'));
$bcc = trim(GETPOST('sendtoccc', 'nohtml'));
$invoicePdf = $service->getInvoicePdfPath($invoice);
$templateAttachDefault = ($template !== false && (string) ($template['joinfiles'] ?? '') === '1');
// A browser does not send an unticked checkbox, so the hidden marker tells a
// deliberate "no invoice PDF" apart from a first view of the composer.
$attachInvoice = (GETPOSTISSET('attach_invoice_shown') && !$templateApply) ? ($invoicePdf !== '' && GETPOSTINT('attach_invoice') > 0) : ($invoicePdf !== '' && $templateAttachDefault);
$deliveryReceipt = GETPOSTINT('deliveryreceipt') > 0;
$previewInfo = null;

$attached = $formmailSession->get_attached_files();
$extraAttachments = array();
if (!empty($attached['paths']) && is_array($attached['paths'])) {
    $extraAllowedRoot = realpath($extraUploadRoot);
    foreach ($attached['paths'] as $key => $path) {
        $extraRealPath = realpath($path);
        if (!$extraRealPath || !$extraAllowedRoot || strpos(str_replace('\\', '/', $extraRealPath), rtrim(str_replace('\\', '/', $extraAllowedRoot), '/').'/') !== 0) { continue; }
        $extraAttachments[] = array('path' => $extraRealPath, 'name' => isset($attached['names'][$key]) ? $attached['names'][$key] : basename($extraRealPath), 'mime' => isset($attached['mimes'][$key]) ? $attached['mimes'][$key] : 'application/octet-stream');
    }
}

if ($action === 'generate_preview' || $action === 'generate_document') {
    if (!$user->hasRight('mahnwesen', 'notice', 'send')) { accessforbidden(); }
    if (!$workflow || empty($workflow['actionable']) || $template === false || $level <= 0 || !$recipientOption) {
        setEventMessages($langs->trans('NoticeNotReady'), null, 'errors');
    } else {
        $previewInfo = $service->generatePdf($invoice, $dummyCase, $level, $body, $action === 'generate_preview', $templateLang, array('contact_id' => (int) $recipientOption['contact_id']));
        if ($previewInfo === false) { setEventMessages($service->error, $service->errors, 'errors'); }
        elseif ($action === 'generate_document') {
            if ($manager->recordGeneratedDocument($id, $level, $previewInfo['relative'], $user, 'manual')) { setEventMessages($langs->trans('MahnwesenPdfGenerated'), null, 'mesgs'); }
            else { setEventMessages($manager->error, $manager->errors, 'warnings'); }
        } else { setEventMessages($langs->trans('NoticePreviewGenerated'), null, 'mesgs'); }
    }
} elseif ($action === 'send_notice' && GETPOST('sendmail', 'alpha')) {
    if (!$user->hasRight('mahnwesen', 'notice', 'send')) { accessforbidden(); }
    if (!$service->isManualSendEnabled()) { setEventMessages($langs->trans('ManualSendDisabled'), null, 'errors'); }
    elseif (GETPOSTINT('confirm_send') !== 1) { setEventMessages($langs->trans('NoticeConfirmationRequired'), null, 'errors'); }
    elseif (!$recipientOption) { setEventMessages($langs->trans('NoticeRecipientInvalid'), null, 'errors'); }
    elseif ($selectedFrom === '') { setEventMessages($langs->trans('NoticeNoSenderEmail'), null, 'errors'); }
    else {
        $syncCheck = $manager->syncInvoiceCase($id, $user);
        $currentWorkflow = ($syncCheck === false) ? false : $manager->getWorkflowState($id);
        $currentCase = $currentWorkflow ? $currentWorkflow['case'] : false;
        $currentRequired = $currentWorkflow ? (int) $currentWorkflow['next_required_level'] : 0;
        $stateChanged = (!$currentWorkflow || empty($currentWorkflow['actionable']) || !$currentCase || $preparedLevel <= 0 || $currentRequired !== $preparedLevel || abs((float) $currentCase['remaining_amount'] - $preparedRemaining) > 0.000001);
        if ($stateChanged) { setEventMessages($langs->trans('NoticeStateChanged'), null, 'errors'); }
        elseif ($manager->hasSuccessfulNoticeAtLevel((int) $currentCase['id'], $preparedLevel)) { setEventMessages($langs->trans('NoticeAlreadySentAtLevel'), null, 'errors'); }
        elseif ($manager->hasPendingNoticeAtLevel((int) $currentCase['id'], $preparedLevel)) { setEventMessages($langs->trans('NoticeSendPendingAtLevel'), null, 'errors'); }
        else {
            $sendResult = $service->sendNotice($invoice, $currentCase, $preparedLevel, $selectedRecipient, $subject, $body, $attachInvoice, $user, 'manual', $selectedFrom, $templateLang, $cc, $bcc, $templateId, $extraAttachments, $deliveryReceipt);
            if ($sendResult === false) { setEventMessages($service->error, $service->errors, 'errors'); }
            else {
                mw_notice_clear_extra_files($formmailSession, $extraUploadRoot);
                setEventMessages($langs->trans('NoticeSentSuccess', $selectedRecipient), null, 'mesgs');
                header('Location: '.dol_buildpath('/mahnwesen/invoice.php?id='.$id, 1)); exit;
            }
        }
    }
}

$workflow = $manager->getWorkflowState($id);
$case = $workflow ? $workflow['case'] : false;
$evaluation = $workflow ? $workflow['evaluation'] : false;
$level = $workflow ? (int) $workflow['next_required_level'] : 0;
$calculatedLevel = $workflow ? (int) $workflow['calculated_level'] : 0;
$sendPending = ($case && $level > 0) ? $manager->hasPendingNoticeAtLevel((int) $case['id'], $level) : false;
$manualSendEnabled = $service->isManualSendEnabled();
$caseForAmounts = $case ?: array('remaining_amount' => (float) ($evaluation ? $evaluation['remain_to_pay'] : 0));
if ($evaluation) { $caseForAmounts['remaining_amount'] = (float) $evaluation['remain_to_pay']; }
$breakdown = $level > 0 ? $manager->getAmountBreakdown($invoice, $caseForAmounts, $level) : array('invoice' => 0, 'fee' => 0, 'total' => 0);
$expectedDunningFilename = $level > 0 ? $service->getFinalPdfFilename($invoice, $level) : '';
$canOperate = $workflow && !empty($workflow['actionable']) && $template !== false && $user->hasRight('mahnwesen', 'notice', 'send');

llxHeader('', $langs->trans('PrepareDunningNotice').' - '.$invoice->ref, '', '', 0, 0, '', '', '', 'mod-mahnwesen page-notice');
$head = facture_prepare_head($invoice);
print dol_get_fiche_head($head, 'mahnwesen', $langs->trans('InvoiceCustomer'), -1, 'bill');
print '<div class="fichecenter"><div class="fichehalfleft"><table class="border centpercent tableforfield">';
print '<tr><td class="titlefieldmiddle">'.$langs->trans('Invoice').'</td><td>'.$invoice->getNomUrl(1).'</td></tr>';
print '<tr><td>'.$langs->trans('ThirdParty').'</td><td>'.$invoice->thirdparty->getNomUrl(1).'</td></tr>';
print '<tr><td>'.$langs->trans('DaysOverdue').'</td><td>'.((int) ($evaluation ? $evaluation['row']['days_late'] : 0)).'</td></tr></table></div>';
print '<div class="fichehalfright"><table class="border centpercent tableforfield">';
print '<tr><td class="titlefieldmiddle">'.$langs->trans('CalculatedStage').'</td><td><strong>'.($calculatedLevel > 0 ? $langs->trans($manager->getStageLabelKey($calculatedLevel)) : '-').'</strong></td></tr>';
print '<tr><td>'.$langs->trans('MahnwesenNextRequiredStage').'</td><td><strong>'.($level > 0 ? $langs->trans($manager->getStageLabelKey($level)) : $langs->trans('MahnwesenNoDueWorkflowStage')).'</strong></td></tr>';
print '<tr><td>'.$langs->trans('MahnwesenDunningTotal').'</td><td><strong>'.price($breakdown['total'], 0, $langs, 1, -1, -1, $conf->currency).'</strong> <span class="opacitymedium">('.price($breakdown['invoice'], 0, $langs, 1, -1, -1, $conf->currency).' + '.price($breakdown['fee'], 0, $langs, 1, -1, -1, $conf->currency).')</span></td></tr>';
print '</table></div></div><div class="clearboth"></div>'.dol_get_fiche_end();

print '<div id="formmailbeforetitle"></div>'.load_fiche_titre($langs->trans('SendMail'), '', 'email');
if (!$manualSendEnabled) { print '<div class="warning">'.$langs->trans('ManualSendDisabledHelp').'</div><br>'; }
if ($sendPending) { print '<div class="warning">'.$langs->trans('NoticeSendPendingAtLevel').'</div><br>'; }
if (empty($recipientOptions)) { print '<div class="error">'.$langs->trans('NoticeNoRecipient').'</div><br>'; }
if ($level > 0 && !empty($workflow['required_at']) && ((int) $db->jdate($workflow['required_at'])) > dol_now()) { print '<div class="info">'.$langs->trans('MahnwesenSequentialCooldownInfo', $langs->trans($manager->getStageLabelKey($level)), dol_print_date($db->jdate($workflow['required_at']), 'day')).'</div><br>'; }

$attachmentHtml = '<div class="mahnwesen-fixed-document">'.img_mime($expectedDunningFilename).' <strong>'.dol_escape_htmltag($expectedDunningFilename).'</strong> <span class="badge badge-status4">'.$langs->trans('MahnwesenMandatoryAttachment').'</span>';
if ($previewInfo && !empty($previewInfo['preview'])) { $previewUrl = dol_buildpath('/mahnwesen/notice.php?id='.$id.'&preview_pdf='.$level, 1); $attachmentHtml .= ' <a target="_blank" rel="noopener" href="'.dol_escape_htmltag($previewUrl).'">'.img_picto($langs->trans('View'), 'view').'</a>'; }
$attachmentHtml .= '</div>';
if ($invoicePdf !== '') {
    $invoiceRelative = $service->getInvoicePdfRelativePath($invoice);
    $invoiceUrl = DOL_URL_ROOT.'/document.php?modulepart=invoice&file='.urlencode($invoiceRelative).((int) $invoice->entity > 1 ? '&entity='.((int) $invoice->entity) : '');
    $attachmentHtml .= '<div><input type="hidden" name="attach_invoice_shown" value="1"><label><input type="checkbox" name="attach_invoice" value="1"'.($attachInvoice ? ' checked' : '').'> '.img_mime(basename($invoicePdf)).' <strong>'.dol_escape_htmltag(basename($invoicePdf)).'</strong></label> <a target="_blank" rel="noopener" href="'.dol_escape_htmltag($invoiceUrl).'">'.img_picto($langs->trans('View'), 'view').'</a></div>';
}
$attachmentFormFile = new FormFile($db);
foreach ($extraAttachments as $key => $extra) {
    $attachmentPreview = '';
    $normalizedDataRoot = rtrim(str_replace('\\', '/', DOL_DATA_ROOT), '/');
    $normalizedExtraPath = str_replace('\\', '/', $extra['path']);
    if (strpos($normalizedExtraPath, $normalizedDataRoot.'/') === 0) {
        $relativeExtra = substr($normalizedExtraPath, strlen($normalizedDataRoot));
        $previewParts = array();
        if (preg_match('#^/([^/]+)/(.+)$#', $relativeExtra, $previewParts)) { $attachmentPreview = $attachmentFormFile->showPreview(array(), $previewParts[1], $previewParts[2], 0, ((int) $invoice->entity === 1 ? '' : 'entity='.((int) $invoice->entity))); }
    }
    $attachmentHtml .= '<div>'.img_mime($extra['name']).' '.dol_escape_htmltag($extra['name']).' '.$attachmentPreview.' <button class="button smallpaddingimp" type="submit" name="remove_extra" value="'.($key + 1).'">'.img_delete($langs->trans('Remove')).'</button></div>';
}
$maxFiles = max(0, min(20, getDolGlobalInt('MAHNWESEN_MAX_EXTRA_ATTACHMENTS', 5)));
if (count($extraAttachments) < $maxFiles) { $attachmentHtml .= '<div class="margintoponly"><input type="file" class="flat" name="addedfile[]" multiple> <button class="button smallpaddingimp" type="submit" name="addfile" value="1">'.$langs->trans('MailingAddFile').'</button></div>'; }
$attachmentHtml .= '<div class="opacitymedium small">'.$langs->trans('MahnwesenAttachmentLimits', $maxFiles, getDolGlobalInt('MAHNWESEN_MAX_EXTRA_ATTACHMENT_MB', 10)).'</div>';

$formmail = new FormMail($db);
$formmail->withform = 0;
$formmail->trackid = $trackid;
$formmail->withfrom = 1; $formmail->withfromreadonly = 1; $formmail->fromtype = $fromType; $formmail->frommail = $preferredFrom; $formmail->fromname = is_object($mysoc) ? (string) $mysoc->name : ''; $formmail->fromalsorobot = 1;
$formmail->withto = $recipientFormOptions; $formmail->withtofree = 0; $formmail->withtocc = 1; $formmail->withtoccc = 1;
$formmail->withtopic = $subject; $formmail->withfile = $attachmentHtml; $formmail->withbody = $body; $formmail->withdeliveryreceipt = 1; $formmail->withcancel = 0;
$formmail->withfckeditor = -1; $formmail->withlayout = 'email'; $formmail->withaiprompt = 'html';
$substitutionContext = $level > 0 ? $service->getTemplateSubstitutions($invoice, $dummyCase, $level, $templateLang) : array('substitutions' => array());
$formmail->substit = $substitutionContext['substitutions'];
$formmail->param['models'] = $level > 0 ? $service->getTemplateTypeForLevel($level) : 'none';
$formmail->param['models_id'] = $templateId; $formmail->param['langsmodels'] = $templateLang; $formmail->param['id'] = $id; $formmail->param['object_entity'] = $invoice->entity;

// FormMail normally clears the user's shared temp directory on template
// application. The selected values are already rendered above, so present
// them as posted composer values and suppress only that destructive cleanup.
if ($templateApply) {
    $_POST['subject'] = $subject;
    $_POST['message'] = $body;
    $_POST['fromtype'] = $fromType;
    unset($_POST['modelselected'], $_GET['modelselected']);
}

print '<form method="POST" name="mailform" id="mailform" enctype="multipart/form-data" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'#formmailbeforetitle">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="trackid" value="'.dol_escape_htmltag($trackid).'">';
print '<input type="hidden" name="prepared_level" value="'.((int) $level).'"><input type="hidden" name="prepared_remaining" value="'.dol_escape_htmltag(number_format((float) ($evaluation ? $evaluation['remain_to_pay'] : 0), 6, '.', '')).'">';
print '<input type="hidden" name="confirm_send" id="mahnwesen_confirm_send" value="0"><input type="hidden" name="action" id="mahnwesen_action" value="composer">';
print $formmail->get_form('addfile', 'remove_extra');
print '<div class="center">';
if ($canOperate) {
    print '<button class="button" type="submit" onclick="document.getElementById(\'mahnwesen_action\').value=\'generate_preview\';">'.img_picto('', 'view').' '.$langs->trans('MahnwesenGeneratePreview').'</button> ';
    print '<button class="button" type="submit" onclick="document.getElementById(\'mahnwesen_action\').value=\'generate_document\';">'.img_picto('', 'pdf').' '.$langs->trans('MahnwesenGenerateDocument').'</button> ';
}
if ($canOperate && $manualSendEnabled && !$sendPending && $selectedFrom !== '' && !empty($recipientOptions)) {
    $confirmText = dol_escape_js($langs->trans('MahnwesenSendConfirmJs', $level > 0 ? $langs->trans($manager->getStageLabelKey($level)) : ''));
    print '<button class="button button-add" id="sendmail" type="submit" name="sendmail" value="1" onclick="if(!window.confirm(\''.$confirmText.'\')){return false;} document.getElementById(\'mahnwesen_confirm_send\').value=\'1\'; document.getElementById(\'mahnwesen_action\').value=\'send_notice\';">'.img_picto('', 'email').' '.$langs->trans('SendMail').'</button> ';
} else { print '<span class="butActionRefused">'.img_picto('', 'email').' '.$langs->trans('SendMail').'</span> '; }
print '<a class="button button-cancel" href="'.dol_escape_htmltag(dol_buildpath('/mahnwesen/invoice.php?id='.$id, 1)).'">'.$langs->trans('Cancel').'</a></div></form>';

if ($previewInfo && !empty($previewInfo['preview'])) {
    $previewUrl = dol_buildpath('/mahnwesen/notice.php?id='.$id.'&preview_pdf='.$level, 1);
    print '<br>'.load_fiche_titre($langs->trans('MahnwesenCompleteMailPreview'), '', 'view');
    print '<div class="mahnwesen-preview-grid"><section class="mahnwesen-mail-preview"><div><strong>'.$langs->trans('MailFrom').':</strong> '.dol_escape_htmltag($selectedFrom).'</div><div><strong>'.$langs->trans('MailTo').':</strong> '.dol_escape_htmltag($selectedRecipient).'</div><div><strong>'.$langs->trans('MailTopicShort').':</strong> '.dol_escape_htmltag($subject).'</div><hr><div class="mahnwesen-mail-body">'.$service->asHtml($body).'</div></section>';
    print '<section class="mahnwesen-document-preview"><iframe title="'.dol_escape_htmltag($langs->trans('MahnwesenDocumentPreview')).'" src="'.dol_escape_htmltag($previewUrl).'#view=FitH"></iframe><div class="center"><a class="button" target="_blank" rel="noopener" href="'.dol_escape_htmltag($previewUrl).'">'.img_picto('', 'pdf').' '.$langs->trans('MahnwesenOpenPreview').'</a></div></section></div>';
}
print '<div class="opacitymedium small">'.$langs->trans('MahnwesenSequentialSafetyFooter').'</div>';
llxFooter();
$db->close();
