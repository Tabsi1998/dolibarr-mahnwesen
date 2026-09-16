<?php
/* Mahnwesen setup for Dolibarr */
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);
require_once dol_buildpath('/mahnwesen/class/dunningnotice.class.php', 0);

$langs->loadLangs(array('admin', 'mails', 'mahnwesen@mahnwesen'));
if (!$user->admin) { accessforbidden(); }
if (!isModEnabled('mahnwesen')) { accessforbidden(); }

// Self-heal the two module parts that are required for native email-template
// integration. This also repairs installations that upgraded while the module
// stayed enabled. The next request will reload the persisted values normally.
$requiredHooks = array('emailtemplates', 'invoicecard');
$hookConst = getDolGlobalString('MAIN_MODULE_MAHNWESEN_HOOKS');
$storedHooks = json_decode((string) $hookConst, true);
if (!is_array($storedHooks)) {
    $storedHooks = preg_split('/[,;:]+/', trim((string) $hookConst), -1, PREG_SPLIT_NO_EMPTY);
}
$storedHooks = array_values(array_unique(array_merge((array) $storedHooks, $requiredHooks)));
sort($storedHooks);
if (json_encode($storedHooks) !== (string) $hookConst) {
    dolibarr_set_const($db, 'MAIN_MODULE_MAHNWESEN_HOOKS', json_encode($storedHooks), 'chaine', 0, '', $conf->entity);
}
dolibarr_set_const($db, 'MAIN_MODULE_MAHNWESEN_JS', json_encode(array('/mahnwesen/js/mahnwesen-emailtemplates.js')), 'chaine', 0, '', $conf->entity);
if (!isset($conf->modules_parts['hooks']) || !is_array($conf->modules_parts['hooks'])) { $conf->modules_parts['hooks'] = array(); }
$conf->modules_parts['hooks']['mahnwesen'] = $storedHooks;

$manager = new DunningManager($db);
$notice = new DunningNoticeService($db, $manager);
$manager->ensureRuleRows($user);

$tab = GETPOST('tab', 'aZ09');
if (!in_array($tab, array('general', 'stages', 'templates', 'automation'), true)) { $tab = 'general'; }
$action = GETPOST('action', 'aZ09');

function mw4_set_const($db, $name, $value, $entity)
{
    return dolibarr_set_const($db, $name, (string) $value, 'chaine', 0, '', $entity) > 0;
}

function mw4_redirect($tab)
{
    header('Location: '.$_SERVER['PHP_SELF'].'?tab='.urlencode($tab));
    exit;
}

if ($action === 'create_starter_templates') {
    $created = $notice->createDefaultNativeTemplates($user);
    if ($created === false) { setEventMessages($langs->trans('MahnwesenStarterTemplatesFailed'), null, 'errors'); }
    else { setEventMessages($langs->trans('MahnwesenStarterTemplatesCreated', (int) $created['created'], (int) $created['existing']), null, 'mesgs'); }
    mw4_redirect('templates');
}

if ($action === 'save_templates') {
    $choices = array();
    $errors = array();
    for ($level = 1; $level <= 4; $level++) {
        $choice = GETPOST('template_'.$level, 'aZ09');
        if ($choice === 'auto' || $choice === '') {
            $choices[$level] = 'native:auto';
        } elseif (ctype_digit($choice) && $notice->getNativeTemplateById((int) $choice, $level, $user) !== false) {
            $choices[$level] = 'native:'.((int) $choice);
        } else {
            $errors[] = $langs->trans('MahnwesenTemplateInvalid', $langs->trans($manager->getStageLabelKey($level)));
        }
    }
    if (empty($errors)) {
        $db->begin();
        $ok = true;
        foreach ($choices as $level => $ref) {
            if (!$manager->saveRuleTemplate($level, $ref, $user)) { $ok = false; break; }
        }
        if ($ok) { $db->commit(); setEventMessages($langs->trans('SetupSaved'), null, 'mesgs'); }
        else { $db->rollback(); setEventMessages($manager->error ?: $langs->trans('Error'), null, 'errors'); }
        mw4_redirect('templates');
    }
    setEventMessages('', $errors, 'errors');
    $tab = 'templates';
}

if ($action === 'save_general') {
    $minAmount = (float) price2num(GETPOST('min_amount', 'alpha'));
    $maxScan = GETPOSTINT('max_scan');
    $includeDeposits = GETPOSTINT('include_deposits') > 0 ? 1 : 0;
    $errors = array();
    if ($minAmount < 0) { $errors[] = $langs->trans('MinAmountInvalid'); }
    if ($maxScan < 1 || $maxScan > 5000) { $errors[] = $langs->trans('MaxScanInvalid'); }
    if (empty($errors)) {
        $db->begin();
        $ok = mw4_set_const($db, 'MAHNWESEN_MIN_AMOUNT', $minAmount, $conf->entity)
            && mw4_set_const($db, 'MAHNWESEN_MAX_SCAN', $maxScan, $conf->entity)
            && mw4_set_const($db, 'MAHNWESEN_INCLUDE_DEPOSITS', $includeDeposits, $conf->entity);
        if ($ok) { $db->commit(); setEventMessages($langs->trans('SetupSaved'), null, 'mesgs'); }
        else { $db->rollback(); setEventMessages($langs->trans('Error'), null, 'errors'); }
        mw4_redirect('general');
    }
    setEventMessages('', $errors, 'errors');
    $tab = 'general';
}

if ($action === 'save_stages') {
    $days = array(); $paymentDays = array(); $businessFees = array(); $privateStageFees = array(); $send = array(); $enabledStages = array(); $errors = array();
    for ($level = 1; $level <= 4; $level++) {
        $days[$level] = GETPOSTINT('stage_days_'.$level);
        $paymentDays[$level] = GETPOSTINT('stage_payment_days_'.$level);
        $businessFees[$level] = (float) price2num(GETPOST('stage_business_fee_'.$level, 'alpha'));
        $privateStageFees[$level] = (float) price2num(GETPOST('stage_private_fee_'.$level, 'alpha'));
        $send[$level] = GETPOSTINT('stage_auto_send_'.$level) > 0 ? 1 : 0;
        $enabledStages[$level] = GETPOSTINT('stage_enabled_'.$level) > 0 ? 1 : 0;
        if ($days[$level] < 0 || $paymentDays[$level] < 0 || $paymentDays[$level] > 365 || $businessFees[$level] < 0 || $privateStageFees[$level] < 0) {
            $errors[] = $langs->trans('MahnwesenStageValuesInvalid', $level);
        }
    }
    if (!($days[1] < $days[2] && $days[2] < $days[3] && $days[3] < $days[4])) {
        $errors[] = $langs->trans('StageDaysMustBeAscending');
    }
    if (array_sum($enabledStages) < 1) { $errors[] = $langs->trans('MahnwesenAtLeastOneStage'); }
    $privateFeesAllowed = GETPOSTINT('private_fees_allowed') > 0 ? 1 : 0;
    $unknownFees = GETPOSTINT('unknown_fees_allowed') > 0 ? 1 : 0;
    if (empty($errors)) {
        $db->begin();
        $ok = true;
        for ($level = 1; $level <= 4; $level++) {
            // Keep the template chosen on the templates tab.
            $currentTemplate = (string) $manager->getRuleByLevel($level)['email_template'];
            if (!$manager->saveRule($level, $days[$level], $businessFees[$level], $send[$level], $currentTemplate, $user, $enabledStages[$level])) { $ok = false; break; }
            if (!mw4_set_const($db, 'MAHNWESEN_PRIVATE_FEE_'.$level, $privateStageFees[$level], $conf->entity)) { $ok = false; break; }
            if (!mw4_set_const($db, 'MAHNWESEN_PAYMENT_DAYS_'.$level, $paymentDays[$level], $conf->entity)) { $ok = false; break; }
        }
        if ($ok) { $ok = mw4_set_const($db, 'MAHNWESEN_PRIVATE_FEES_ALLOWED', $privateFeesAllowed, $conf->entity); }
        if ($ok) { $ok = mw4_set_const($db, 'MAHNWESEN_UNKNOWN_FEES_ALLOWED', $unknownFees, $conf->entity); }
        if ($ok) { $db->commit(); setEventMessages($langs->trans('SetupSaved'), null, 'mesgs'); }
        else { $db->rollback(); setEventMessages($manager->error ?: $langs->trans('Error'), null, 'errors'); }
        mw4_redirect('stages');
    }
    setEventMessages('', $errors, 'errors');
    $tab = 'stages';
}

if ($action === 'save_automation') {
    $fromEmail = trim(GETPOST('from_email', 'email'));
    $manual = GETPOSTINT('manual_send_enabled') > 0 ? 1 : 0;
    $auto = GETPOSTINT('auto_send_enabled') > 0 ? 1 : 0;
    $max = GETPOSTINT('auto_send_max');
    $retryMax = GETPOSTINT('auto_retry_max');
    $maxPerCustomer = GETPOSTINT('auto_max_per_customer');
    $extraAttachmentMax = GETPOSTINT('max_extra_attachments');
    $extraAttachmentMb = GETPOSTINT('max_extra_attachment_mb');
    $policy = GETPOST('recipient_policy', 'aZ09');
    $allowLanguageFallback = GETPOSTINT('allow_language_fallback') > 0 ? 1 : 0;
    $errors = array();
    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) { $errors[] = $langs->trans('SenderEmailInvalid'); }
    if ($max < 1 || $max > 100) { $errors[] = $langs->trans('MahnwesenAutoSendMaxInvalid'); }
    if ($retryMax < 1 || $retryMax > 10) { $errors[] = $langs->trans('MahnwesenAutoRetryMaxInvalid'); }
    if ($maxPerCustomer < 1 || $maxPerCustomer > 20) { $errors[] = $langs->trans('MahnwesenAutoPerCustomerInvalid'); }
    if ($extraAttachmentMax < 0 || $extraAttachmentMax > 20) { $errors[] = $langs->trans('MahnwesenExtraAttachmentMaxInvalid'); }
    if ($extraAttachmentMb < 1 || $extraAttachmentMb > 100) { $errors[] = $langs->trans('MahnwesenExtraAttachmentMbInvalid'); }
    if (!in_array($policy, array('single_billing', 'first_billing'), true)) { $errors[] = $langs->trans('MahnwesenRecipientPolicyInvalid'); }
    // The explicit confirmation is needed to switch automatic sending on, not
    // for every later save while it stays on.
    if ($auto && !getDolGlobalInt('MAHNWESEN_AUTO_SEND_ENABLED', 0) && !GETPOSTINT('auto_send_confirm')) { $errors[] = $langs->trans('MahnwesenAutoSendConfirmationRequired'); }
    if (empty($errors)) {
        $db->begin();
        $ok = mw4_set_const($db, 'MAHNWESEN_FROM_EMAIL', $fromEmail, $conf->entity)
            && mw4_set_const($db, 'MAHNWESEN_MANUAL_SEND_ENABLED', $manual, $conf->entity)
            && mw4_set_const($db, 'MAHNWESEN_AUTO_SEND_ENABLED', $auto, $conf->entity)
            && mw4_set_const($db, 'MAHNWESEN_AUTO_SEND_MAX', $max, $conf->entity)
            && mw4_set_const($db, 'MAHNWESEN_AUTO_RETRY_MAX', $retryMax, $conf->entity)
            && mw4_set_const($db, 'MAHNWESEN_AUTO_MAX_PER_CUSTOMER', $maxPerCustomer, $conf->entity)
            && mw4_set_const($db, 'MAHNWESEN_MAX_EXTRA_ATTACHMENTS', $extraAttachmentMax, $conf->entity)
            && mw4_set_const($db, 'MAHNWESEN_MAX_EXTRA_ATTACHMENT_MB', $extraAttachmentMb, $conf->entity)
            && mw4_set_const($db, 'MAHNWESEN_AUTO_RECIPIENT_POLICY', $policy, $conf->entity)
            && mw4_set_const($db, 'MAHNWESEN_ALLOW_LANGUAGE_FALLBACK', $allowLanguageFallback, $conf->entity);
        if ($ok) { $db->commit(); setEventMessages($langs->trans('SetupSaved'), null, 'mesgs'); }
        else { $db->rollback(); setEventMessages($langs->trans('Error'), null, 'errors'); }
        mw4_redirect('automation');
    }
    setEventMessages('', $errors, 'errors');
    $tab = 'automation';
}

$rules = $manager->getRules(true);
$nativeTemplates = $notice->getNativeTemplates();
$minAmount = getDolGlobalString('MAHNWESEN_MIN_AMOUNT', '1.00');
$maxScan = getDolGlobalInt('MAHNWESEN_MAX_SCAN', 500);
$includeDeposits = getDolGlobalInt('MAHNWESEN_INCLUDE_DEPOSITS', 0);
$unknownFees = getDolGlobalInt('MAHNWESEN_UNKNOWN_FEES_ALLOWED', 0);
$privateFeesAllowed = getDolGlobalInt('MAHNWESEN_PRIVATE_FEES_ALLOWED', 0);
$fromEmail = getDolGlobalString('MAHNWESEN_FROM_EMAIL');
$manual = getDolGlobalInt('MAHNWESEN_MANUAL_SEND_ENABLED', 0);
$auto = getDolGlobalInt('MAHNWESEN_AUTO_SEND_ENABLED', 0);
$autoMax = getDolGlobalInt('MAHNWESEN_AUTO_SEND_MAX', 10);
$autoRetryMax = getDolGlobalInt('MAHNWESEN_AUTO_RETRY_MAX', 3);
$autoMaxPerCustomer = getDolGlobalInt('MAHNWESEN_AUTO_MAX_PER_CUSTOMER', 1);
$extraAttachmentMax = getDolGlobalInt('MAHNWESEN_MAX_EXTRA_ATTACHMENTS', 5);
$extraAttachmentMb = getDolGlobalInt('MAHNWESEN_MAX_EXTRA_ATTACHMENT_MB', 10);
$policy = getDolGlobalString('MAHNWESEN_AUTO_RECIPIENT_POLICY', 'single_billing');
$allowLanguageFallback = getDolGlobalInt('MAHNWESEN_ALLOW_LANGUAGE_FALLBACK', 0);

llxHeader('', $langs->trans('MahnwesenSetup'), '', '', 0, 0, '', '', '', 'mod-mahnwesen page-admin');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('MahnwesenSetup'), $linkback, 'title_setup');

$head = array(
    array($_SERVER['PHP_SELF'].'?tab=general', $langs->trans('MahnwesenTabGeneral'), 'general'),
    array($_SERVER['PHP_SELF'].'?tab=stages', $langs->trans('MahnwesenTabStagesFees'), 'stages'),
    array($_SERVER['PHP_SELF'].'?tab=templates', $langs->trans('MahnwesenTabTemplates'), 'templates'),
    array($_SERVER['PHP_SELF'].'?tab=automation', $langs->trans('MahnwesenTabAutomation'), 'automation'),
);
print dol_get_fiche_head($head, $tab, $langs->trans('MahnwesenSetup'), -1, 'bill');

if ($tab === 'general') {
    print '<div class="info">'.$langs->trans('MahnwesenGeneralIntro').'</div><br>';
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?tab=general">';
    print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save_general">';
    print '<table class="border centpercent tableforfield">';
    print '<tr><td class="titlefield">'.$langs->trans('MinimumOpenAmount').'</td><td><input class="width100" name="min_amount" value="'.dol_escape_htmltag((string) $minAmount).'"></td><td>'.$langs->trans('MinimumOpenAmountHelp').'</td></tr>';
    print '<tr><td>'.$langs->trans('MaximumScan').'</td><td><input type="number" min="1" max="5000" class="width100" name="max_scan" value="'.((int) $maxScan).'"></td><td>'.$langs->trans('MaximumScanHelp').'</td></tr>';
    print '<tr><td>'.$langs->trans('IncludeDepositInvoices').'</td><td><input type="checkbox" name="include_deposits" value="1"'.($includeDeposits ? ' checked' : '').'></td><td>'.$langs->trans('IncludeDepositInvoicesHelp').'</td></tr>';
    print '</table><div class="center"><button class="button button-save" type="submit">'.$langs->trans('Save').'</button></div></form>';
}

if ($tab === 'stages') {
    print '<div class="info">'.$langs->trans('MahnwesenFeesSimpleIntro').'</div><br>';
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?tab=stages">';
    print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save_stages">';
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th>'.$langs->trans('DunningStage').'</th>';
    print '<th>'.$langs->trans('MahnwesenDaysAfterDue').'</th>';
    print '<th>'.$langs->trans('MahnwesenPaymentDays').'</th>';
    print '<th>'.$langs->trans('MahnwesenPrivatePersonFee').'</th>';
    print '<th>'.$langs->trans('MahnwesenBusinessFee').'</th>';
    print '<th>'.$langs->trans('MahnwesenAutoSendAtStage').'</th>';
    print '<th>'.$langs->trans('Enabled').'</th>';
    print '</tr>';
    for ($level = 1; $level <= 4; $level++) {
        $rule = $rules[$level];
        $privateFee = $manager->getPrivateFeeForLevel($level);
        print '<tr class="oddeven"><td><strong>'.$langs->trans($manager->getStageLabelKey($level)).'</strong></td>';
        print '<td><input class="width75" type="number" min="0" name="stage_days_'.$level.'" value="'.((int) $rule['days_after_due']).'"> '.$langs->trans('Days').'</td>';
        print '<td><input class="width75" type="number" min="0" max="365" name="stage_payment_days_'.$level.'" value="'.((int) $manager->getPaymentDaysForLevel($level)).'"> '.$langs->trans('Days').'</td>';
        print '<td><input class="width100" type="text" name="stage_private_fee_'.$level.'" value="'.dol_escape_htmltag(price($privateFee, 0, $langs, 0, -1, -1, $conf->currency)).'"> '.$conf->currency.'</td>';
        print '<td><input class="width100" type="text" name="stage_business_fee_'.$level.'" value="'.dol_escape_htmltag(price($rule['fee_amount'], 0, $langs, 0, -1, -1, $conf->currency)).'"> '.$conf->currency.'</td>';
        print '<td><input type="checkbox" name="stage_auto_send_'.$level.'" value="1"'.(!empty($rule['send_email']) ? ' checked' : '').'> '.$langs->trans('MahnwesenAutoSendAtStageHelp').'</td>';
        print '<td><input type="checkbox" name="stage_enabled_'.$level.'" value="1"'.(!empty($rule['enabled']) ? ' checked' : '').'></td></tr>';
    }
    print '</table></div>';
    print '<div class="opacitymedium margintoponly">'.$langs->trans('MahnwesenPaymentDaysHelp').'</div><br>';
    print '<div class="info">'.$langs->trans('MahnwesenFeePresetExplanation').'</div>';
    print '<table class="border centpercent tableforfield margintoponly">';
    print '<tr><td class="titlefield">'.$langs->trans('MahnwesenPrivateFees').'</td><td><input type="checkbox" name="private_fees_allowed" value="1"'.($privateFeesAllowed ? ' checked' : '').'></td><td>'.$langs->trans('MahnwesenPrivateFeesHelp').'</td></tr>';
    print '<tr><td class="titlefield">'.$langs->trans('MahnwesenUnknownFees').'</td><td><input type="checkbox" name="unknown_fees_allowed" value="1"'.($unknownFees ? ' checked' : '').'></td><td>'.$langs->trans('MahnwesenUnknownFeesHelpV041').'</td></tr>';
    print '</table>';
    print '<div class="warning margintoponly">'.$langs->trans('MahnwesenPrivateFeeLegalHelp').'</div>';
    print '<div class="warning margintoponly">'.$langs->trans('MahnwesenFeeNoInvoiceMutation').'</div>';
    print '<div class="center"><button class="button button-save" type="submit">'.$langs->trans('Save').'</button></div></form>';
}

if ($tab === 'templates') {
    print '<div class="info">'.$langs->trans('MahnwesenNativeTemplateIntroV042').'</div><br>';
    print '<div class="info">'.$langs->trans('MahnwesenNativeTemplateOnlyInfo').'</div><br>';
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?tab=templates"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="create_starter_templates"><button class="button" type="submit">'.$langs->trans('MahnwesenCreateStarterTemplates').'</button></form><br>';

    $runtimeHook = false;
    if (!empty($conf->modules_parts['hooks']['mahnwesen'])) {
        $h = $conf->modules_parts['hooks']['mahnwesen'];
        $runtimeHook = (is_array($h) ? in_array('emailtemplates', $h, true) : strpos((string) $h, 'emailtemplates') !== false);
    }
    print '<table class="border centpercent tableforfield">';
    print '<tr><td class="titlefieldmiddle">'.$langs->trans('MahnwesenNativeTemplateLocation').'</td><td><strong>'.$langs->trans('MahnwesenNativeTemplatePath').'</strong></td></tr>';
    print '<tr><td>'.$langs->trans('MahnwesenTemplateHookStatus').'</td><td>'.($runtimeHook ? '<span class="badge badge-status4">'.$langs->trans('MahnwesenHookReady').'</span>' : '<span class="badge badge-status1">'.$langs->trans('MahnwesenHookRepairPending').'</span>').'</td></tr>';
    print '</table><br>';

    $typeLabels = array(
        1 => 'EmailTemplateTypePaymentReminder',
        2 => 'EmailTemplateTypeDunning1',
        3 => 'EmailTemplateTypeDunning2',
        4 => 'EmailTemplateTypeDunning3',
    );
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?tab=templates">';
    print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save_templates">';
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre"><th>'.$langs->trans('DunningStage').'</th><th>'.$langs->trans('MahnwesenDolibarrTemplateType').'</th><th>'.$langs->trans('MahnwesenTemplateChoice').'</th><th>'.$langs->trans('MahnwesenTemplateUsed').'</th><th>'.$langs->trans('MahnwesenTemplateState').'</th></tr>';
    for ($level = 1; $level <= 4; $level++) {
        $type = $notice->getTemplateTypeForLevel($level);
        $stageTemplates = $notice->getNativeTemplates($level, $user);
        $selectedTemplate = $notice->getTemplate($level, $langs->defaultlang, $user);
        $selectedName = ($selectedTemplate !== false && !empty($selectedTemplate['label'])) ? (string) $selectedTemplate['label'] : '-';
        $currentRef = (string) $rules[$level]['email_template'];
        print '<tr class="oddeven">';
        print '<td><strong>'.$langs->trans($manager->getStageLabelKey($level)).'</strong></td>';
        print '<td>'.$langs->trans($typeLabels[$level]).'<br><span class="opacitymedium"><code>'.dol_escape_htmltag($type).'</code></span></td>';
        print '<td><select name="template_'.$level.'" id="template_'.$level.'" class="minwidth200">';
        print '<option value="auto"'.(strpos($currentRef, 'native:') !== 0 || $currentRef === 'native:auto' ? ' selected' : '').'>'.dol_escape_htmltag($langs->trans('MahnwesenTemplateAuto')).'</option>';
        foreach ($stageTemplates as $templateRow) {
            $optionLabel = (string) $templateRow['label'].($templateRow['lang'] !== '' ? ' ('.$templateRow['lang'].')' : '');
            if ($templateRow['module'] === 'mahnwesen') { $optionLabel .= ' - '.$langs->trans('MahnwesenTemplateStarterMark'); }
            print '<option value="'.((int) $templateRow['id']).'"'.($currentRef === 'native:'.((int) $templateRow['id']) ? ' selected' : '').'>'.dol_escape_htmltag($optionLabel).'</option>';
        }
        print '</select></td>';
        print '<td>'.dol_escape_htmltag($selectedName).'</td>';
        print '<td>'.(!empty($stageTemplates) ? '<span class="badge badge-status4">'.$langs->trans('MahnwesenTemplateReady').'</span>' : '<span class="badge badge-status1">'.$langs->trans('MahnwesenTemplateMissing').'</span>').'</td>';
        print '</tr>';
    }
    print '</table></div>';
    print '<div class="opacitymedium margintoponly">'.$langs->trans('MahnwesenTemplateChoiceHelp').'</div>';
    print '<div class="center margintoponly"><button class="button button-save" type="submit">'.$langs->trans('Save').'</button></div></form><br>';

    $tokenRows = array(
        '__MAHNWESEN_STAGE__' => 'MahnwesenTokenStageDesc',
        '__MAHNWESEN_OPEN_AMOUNT__' => 'MahnwesenTokenOpenAmountDesc',
        '__MAHNWESEN_FEE__' => 'MahnwesenTokenFeeDesc',
        '__MAHNWESEN_TOTAL__' => 'MahnwesenTokenTotalDesc',
        '__MAHNWESEN_CUSTOMER_CLASS__' => 'MahnwesenTokenCustomerClassDesc',
        '__MAHNWESEN_NEXT_STAGE_DATE__' => 'MahnwesenTokenNextStageDesc',
        '__MAHNWESEN_FEE_PARAGRAPH__' => 'MahnwesenTokenFeeParagraphDesc',
        '__MAHNWESEN_PAYMENT_DEADLINE__' => 'MahnwesenTokenPaymentDeadlineDesc',
        '__MAHNWESEN_PAYMENT_DAYS__' => 'MahnwesenTokenPaymentDaysDesc',
        '{INVOICE_REF}' => 'MahnwesenTokenInvoiceRefDesc',
        '{CUSTOMER_NAME}' => 'MahnwesenTokenCustomerNameDesc',
        '{INVOICE_DATE}' => 'MahnwesenTokenInvoiceDateDesc',
        '{DUE_DATE}' => 'MahnwesenTokenDueDateDesc',
        '{TODAY}' => 'MahnwesenTokenTodayDesc',
        '{COMPANY_NAME}' => 'MahnwesenTokenCompanyNameDesc',
    );
    print load_fiche_titre($langs->trans('MahnwesenTemplateVariables').' '.img_picto($langs->trans('MahnwesenTemplateVariablesTooltip'), 'info'), '', 'info');
    print '<div class="opacitymedium marginbottomonly">'.$langs->trans('MahnwesenTemplateVariablesIntro').'</div>';
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre"><th>'.$langs->trans('Variable').'</th><th>'.$langs->trans('Description').'</th></tr>';
    foreach ($tokenRows as $token => $descKey) {
        print '<tr class="oddeven"><td><code>'.dol_escape_htmltag($token).'</code></td><td>'.$langs->trans($descKey).'</td></tr>';
    }
    print '</table></div>';
    print '<div class="info margintoponly">'.$langs->trans('MahnwesenTemplateNativeVariablesNote').'</div>';
}

if ($tab === 'automation') {
    print '<div class="'.($auto ? 'warning' : 'info').'">'.$langs->trans($auto ? 'MahnwesenAutomationEnabledInfo' : 'MahnwesenAutomationDisabledInfo').'</div><br>';
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?tab=automation">';
    print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save_automation">';
    print '<table class="border centpercent tableforfield">';
    print '<tr><td class="titlefield">'.$langs->trans('NoticeSenderEmail').'</td><td><input class="minwidth300" type="email" name="from_email" value="'.dol_escape_htmltag($fromEmail).'"></td><td>'.$langs->trans('NoticeSenderEmailHelp').'</td></tr>';
    print '<tr><td>'.$langs->trans('EnableManualSend').'</td><td><input type="checkbox" name="manual_send_enabled" value="1"'.($manual ? ' checked' : '').'></td><td>'.$langs->trans('EnableManualSendHelp').'</td></tr>';
    print '<tr><td>'.$langs->trans('MahnwesenAttachInvoiceSetting').'</td><td></td><td>'.$langs->trans('MahnwesenAttachInvoiceFromTemplate').'</td></tr>';
    print '<tr><td>'.$langs->trans('MahnwesenRecipientPolicy').'</td><td><select name="recipient_policy">';
    print '<option value="single_billing"'.($policy === 'single_billing' ? ' selected' : '').'>'.$langs->trans('MahnwesenRecipientPolicySingle').'</option>';
    print '<option value="first_billing"'.($policy === 'first_billing' ? ' selected' : '').'>'.$langs->trans('MahnwesenRecipientPolicyFirst').'</option>';
    print '</select></td><td>'.$langs->trans('MahnwesenRecipientPolicyHelp').'</td></tr>';
    print '<tr><td>'.$langs->trans('MahnwesenLanguageFallback').'</td><td><input type="checkbox" name="allow_language_fallback" value="1"'.($allowLanguageFallback ? ' checked' : '').'></td><td>'.$langs->trans('MahnwesenLanguageFallbackHelp').'</td></tr>';
    print '<tr><td>'.$langs->trans('MahnwesenAutoSendMax').'</td><td><input class="width75" type="number" min="1" max="100" name="auto_send_max" value="'.((int) $autoMax).'"></td><td>'.$langs->trans('MahnwesenAutoSendMaxHelp').'</td></tr>';
    print '<tr><td>'.$langs->trans('MahnwesenAutoRetryMax').'</td><td><input class="width75" type="number" min="1" max="10" name="auto_retry_max" value="'.((int) $autoRetryMax).'"></td><td>'.$langs->trans('MahnwesenAutoRetryMaxHelp').'</td></tr>';
    print '<tr><td>'.$langs->trans('MahnwesenAutoPerCustomer').'</td><td><input class="width75" type="number" min="1" max="20" name="auto_max_per_customer" value="'.((int) $autoMaxPerCustomer).'"></td><td>'.$langs->trans('MahnwesenAutoPerCustomerHelp').'</td></tr>';
    print '<tr><td>'.$langs->trans('MahnwesenExtraAttachmentMax').'</td><td><input class="width75" type="number" min="0" max="20" name="max_extra_attachments" value="'.((int) $extraAttachmentMax).'"></td><td>'.$langs->trans('MahnwesenExtraAttachmentMaxHelp').'</td></tr>';
    print '<tr><td>'.$langs->trans('MahnwesenExtraAttachmentMb').'</td><td><input class="width75" type="number" min="1" max="100" name="max_extra_attachment_mb" value="'.((int) $extraAttachmentMb).'"></td><td>'.$langs->trans('MahnwesenExtraAttachmentMbHelp').'</td></tr>';
    print '<tr><td><strong>'.$langs->trans('MahnwesenEnableAutoSend').'</strong></td><td><input type="checkbox" name="auto_send_enabled" value="1"'.($auto ? ' checked' : '').'></td><td>'.$langs->trans('MahnwesenEnableAutoSendHelp').'</td></tr>';
    print '<tr><td>'.$langs->trans('MahnwesenAutoSendConfirmation').'</td><td><input type="checkbox" name="auto_send_confirm" value="1"></td><td><strong>'.$langs->trans('MahnwesenAutoSendConfirmationHelp').'</strong></td></tr>';
    print '</table>';
    print '<div class="warning margintoponly">'.$langs->trans('MahnwesenAutomationSafetyHelp').'</div>';
    print '<div class="info margintoponly">'.$langs->trans('MahnwesenReactivationHelp').'</div>';
    print '<div class="center"><button class="button button-save" type="submit">'.$langs->trans('Save').'</button></div></form>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
