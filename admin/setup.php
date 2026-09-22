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

$manager = new DunningManager($db);
$notice = new DunningNoticeService($db, $manager);
$manager->ensureRuleRows($user);
// Stages and templates are set per dunning profile (#32).
$profiles = $manager->getProfiles();
$profileId = GETPOSTINT('profile');
if (!isset($profiles[$profileId])) { $profileId = $manager->getDefaultProfileId($user); }
$manager->ensureRuleRows($user, $profileId);

$tab = GETPOST('tab', 'aZ09');
if (!in_array($tab, array('general', 'profiles', 'stages', 'templates', 'automation'), true)) { $tab = 'general'; }
$action = GETPOST('action', 'aZ09');
$editProfile = GETPOSTINT('edit') > 0;

function mw4_set_const($db, $name, $value, $entity)
{
    return dolibarr_set_const($db, $name, (string) $value, 'chaine', 0, '', $entity) > 0;
}

function mw4_redirect($tab, $profileId = 0)
{
    header('Location: '.$_SERVER['PHP_SELF'].'?tab='.urlencode($tab).($profileId > 0 ? '&profile='.((int) $profileId) : ''));
    exit;
}

/** Choice of the profile whose stages or templates the tab shows. */
function mw4_profile_selector($tab, $profiles, $profileId, $langs)
{
    $html = '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" class="marginbottomonly"><input type="hidden" name="tab" value="'.dol_escape_htmltag($tab).'">';
    $html .= '<label for="profile">'.$langs->trans('MahnwesenProfile').'</label> <select name="profile" id="profile" class="minwidth200">';
    foreach ($profiles as $id => $profile) {
        $html .= '<option value="'.((int) $id).'"'.((int) $id === (int) $profileId ? ' selected' : '').'>'.dol_escape_htmltag($profile['label'].(empty($profile['active']) ? ' ('.$langs->transnoentitiesnoconv('Disabled').')' : '')).'</option>';
    }
    return $html.'</select> <button class="button smallpaddingimp" type="submit">'.$langs->trans('Refresh').'</button></form>';
}

/** What leads an invoice to a profile, in words. HTML. */
function mw4_profile_scope($manager, $profile, $langs)
{
    if (!empty($profile['is_default'])) { return $langs->trans('MahnwesenProfileAppliesDefault'); }
    $matches = $manager->getMatchesOfProfile((int) $profile['id']);
    $parts = array();
    foreach (array('product_category' => array(0, 'MahnwesenProfileProductCategories'), 'customer_category' => array(2, 'MahnwesenProfileCustomerCategories')) as $kind => $info) {
        if (empty($matches[$kind])) { continue; }
        $choices = $manager->getCategoryChoices($info[0]);
        $names = array();
        foreach ($matches[$kind] as $id) { $names[] = isset($choices[$id]) ? $choices[$id] : '#'.$id; }
        $parts[] = $langs->trans($info[1]).': '.dol_escape_htmltag(implode(', ', $names));
    }
    if ($matches['customer_type'] !== '') {
        $parts[] = $langs->trans('MahnwesenProfileCustomerType').': '.$langs->trans($matches['customer_type'] === 'private' ? 'MahnwesenCustomerTypePrivate' : 'MahnwesenCustomerTypeCompany');
    }
    return $parts ? implode('; ', $parts) : $langs->trans('MahnwesenProfileAppliesChoiceOnly');
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
            if (!$manager->saveRuleTemplate($level, $ref, $user, $profileId)) { $ok = false; break; }
        }
        if ($ok) { $db->commit(); setEventMessages($langs->trans('SetupSaved'), null, 'mesgs'); }
        else { $db->rollback(); setEventMessages($manager->error ?: $langs->trans('Error'), null, 'errors'); }
        mw4_redirect('templates', $profileId);
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
    $days = array(); $paymentDays = array(); $fees = array(); $send = array(); $enabledStages = array(); $errors = array();
    for ($level = 1; $level <= 4; $level++) {
        $days[$level] = GETPOSTINT('stage_days_'.$level);
        $paymentDays[$level] = GETPOSTINT('stage_payment_days_'.$level);
        $fees[$level] = (float) price2num(GETPOST('stage_fee_'.$level, 'alpha'));
        $send[$level] = GETPOSTINT('stage_auto_send_'.$level) > 0 ? 1 : 0;
        $enabledStages[$level] = GETPOSTINT('stage_enabled_'.$level) > 0 ? 1 : 0;
        if ($days[$level] < 0 || $paymentDays[$level] < 0 || $paymentDays[$level] > 365 || $fees[$level] < 0) {
            $errors[] = $langs->trans('MahnwesenStageValuesInvalid', $level);
        }
    }
    if (!($days[1] < $days[2] && $days[2] < $days[3] && $days[3] < $days[4])) {
        $errors[] = $langs->trans('StageDaysMustBeAscending');
    }
    if (array_sum($enabledStages) < 1) { $errors[] = $langs->trans('MahnwesenAtLeastOneStage'); }
    if (empty($errors)) {
        $db->begin();
        $ok = true;
        for ($level = 1; $level <= 4; $level++) {
            // Keep the template chosen on the templates tab.
            $currentTemplate = (string) $manager->getRuleByLevel($level, $profileId)['email_template'];
            if (!$manager->saveRule($level, $days[$level], $fees[$level], $send[$level], $currentTemplate, $user, $enabledStages[$level], $paymentDays[$level], $profileId)) { $ok = false; break; }
        }
        if ($ok) { $db->commit(); setEventMessages($langs->trans('SetupSaved'), null, 'mesgs'); }
        else { $db->rollback(); setEventMessages($manager->error ?: $langs->trans('Error'), null, 'errors'); }
        mw4_redirect('stages', $profileId);
    }
    setEventMessages('', $errors, 'errors');
    $tab = 'stages';
}

if ($action === 'create_profile' || $action === 'add_profile_preset') {
    $newProfile = $action === 'create_profile'
        ? $manager->createProfile(GETPOST('profile_label', 'alphanohtml'), $user)
        : $manager->createProfile('', $user, GETPOST('preset', 'aZ09'));
    if ($newProfile > 0) {
        setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
        header('Location: '.$_SERVER['PHP_SELF'].'?tab=profiles&profile='.$newProfile.'&edit=1');
        exit;
    }
    setEventMessages($manager->error ?: $langs->trans('Error'), null, 'errors');
    $tab = 'profiles';
}

if ($action === 'save_profile') {
    if ($manager->saveProfile($profileId, GETPOST('profile_label', 'alphanohtml'), GETPOSTINT('profile_active'), GETPOSTINT('profile_auto_allowed'),
        GETPOST('profile_final_step', 'aZ09'), GETPOST('product_categories', 'array'), GETPOST('customer_categories', 'array'), GETPOST('customer_type', 'aZ09'), $user)) {
        setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
        mw4_redirect('profiles');
    }
    setEventMessages($manager->error ?: $langs->trans('Error'), null, 'errors');
    $tab = 'profiles';
    $editProfile = true;
}

if ($action === 'confirm_delete_profile' && GETPOST('confirm', 'alpha') === 'yes') {
    if ($manager->deleteProfile($profileId)) {
        setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
        mw4_redirect('profiles');
    }
    setEventMessages($manager->error ?: $langs->trans('Error'), null, 'errors');
    $tab = 'profiles';
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
    $notifyEmail = trim(GETPOST('run_notify_email', 'email'));
    $errors = array();
    if ($notifyEmail !== '' && !filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) { $errors[] = $langs->trans('MahnwesenRunNotifyEmailInvalid'); }
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
            && mw4_set_const($db, 'MAHNWESEN_ALLOW_LANGUAGE_FALLBACK', $allowLanguageFallback, $conf->entity)
            && mw4_set_const($db, 'MAHNWESEN_RUN_NOTIFY_EMAIL', $notifyEmail, $conf->entity);
        if ($ok) { $db->commit(); setEventMessages($langs->trans('SetupSaved'), null, 'mesgs'); }
        else { $db->rollback(); setEventMessages($langs->trans('Error'), null, 'errors'); }
        mw4_redirect('automation');
    }
    setEventMessages('', $errors, 'errors');
    $tab = 'automation';
}

$profiles = $manager->getProfiles(true);
if (!isset($profiles[$profileId])) { $profileId = $manager->getDefaultProfileId($user); }
$rules = $manager->getRules(true, $profileId);
$nativeTemplates = $notice->getNativeTemplates();
$minAmount = getDolGlobalString('MAHNWESEN_MIN_AMOUNT', '1.00');
$maxScan = getDolGlobalInt('MAHNWESEN_MAX_SCAN', 500);
$includeDeposits = getDolGlobalInt('MAHNWESEN_INCLUDE_DEPOSITS', 0);
$fromEmail = getDolGlobalString('MAHNWESEN_FROM_EMAIL');
$manual = getDolGlobalInt('MAHNWESEN_MANUAL_SEND_ENABLED', 0);
$auto = getDolGlobalInt('MAHNWESEN_AUTO_SEND_ENABLED', 0);
$autoMax = getDolGlobalInt('MAHNWESEN_AUTO_SEND_MAX', 10);
$autoRetryMax = getDolGlobalInt('MAHNWESEN_AUTO_RETRY_MAX', 3);
$autoMaxPerCustomer = getDolGlobalInt('MAHNWESEN_AUTO_MAX_PER_CUSTOMER', 1);
$extraAttachmentMax = getDolGlobalInt('MAHNWESEN_MAX_EXTRA_ATTACHMENTS', 5);
$extraAttachmentMb = getDolGlobalInt('MAHNWESEN_MAX_EXTRA_ATTACHMENT_MB', 10);
$policy = getDolGlobalString('MAHNWESEN_AUTO_RECIPIENT_POLICY', 'single_billing');
$notifyEmail = getDolGlobalString('MAHNWESEN_RUN_NOTIFY_EMAIL');
$allowLanguageFallback = getDolGlobalInt('MAHNWESEN_ALLOW_LANGUAGE_FALLBACK', 0);

llxHeader('', $langs->trans('MahnwesenSetup'), '', '', 0, 0, '', array('/mahnwesen/css/mahnwesen.css'), '', 'mod-mahnwesen page-admin');
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('MahnwesenSetup'), $linkback, 'title_setup');

$head = array(
    array($_SERVER['PHP_SELF'].'?tab=general', $langs->trans('MahnwesenTabGeneral'), 'general'),
    array($_SERVER['PHP_SELF'].'?tab=profiles', $langs->trans('MahnwesenTabProfiles'), 'profiles'),
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
    print '<tr><td class="titlefield">'.$langs->trans('MinimumOpenAmount').'</td><td><input class="width100" name="min_amount" value="'.dol_escape_htmltag(price((float) price2num($minAmount), 0, $langs, 0, -1, -1)).'"> '.$conf->currency.'</td><td>'.$langs->trans('MinimumOpenAmountHelp').'</td></tr>';
    print '<tr><td>'.$langs->trans('MaximumScan').'</td><td><input type="number" min="1" max="5000" class="width100" name="max_scan" value="'.((int) $maxScan).'"></td><td>'.$langs->trans('MaximumScanHelp').'</td></tr>';
    print '<tr><td>'.$langs->trans('IncludeDepositInvoices').'</td><td><input type="checkbox" name="include_deposits" value="1"'.($includeDeposits ? ' checked' : '').'></td><td>'.$langs->trans('IncludeDepositInvoicesHelp').'</td></tr>';
    print '</table><div class="center"><button class="button button-save" type="submit">'.$langs->trans('Save').'</button></div></form>';
}

if ($tab === 'profiles') {
    $form = new Form($db);
    print '<div class="info">'.$langs->trans('MahnwesenProfilesIntro').'</div><br>';
    if (!isModEnabled('categorie')) { print '<div class="warning">'.$langs->trans('MahnwesenProfilesNoCategories').'</div><br>'; }
    if ($action === 'delete_profile' && isset($profiles[$profileId]) && empty($profiles[$profileId]['is_default'])) {
        print $form->formconfirm($_SERVER['PHP_SELF'].'?tab=profiles&profile='.$profileId, $langs->trans('MahnwesenProfileDelete'),
            $langs->trans('MahnwesenProfileDeleteConfirm', dol_escape_htmltag($profiles[$profileId]['label'])), 'confirm_delete_profile', '', 0, 1);
    }
    $steps = $manager->getFinalSteps();
    if ($editProfile && isset($profiles[$profileId])) {
        $profile = $profiles[$profileId];
        $matches = $manager->getMatchesOfProfile($profileId);
        $isDefault = !empty($profile['is_default']);
        print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?tab=profiles">';
        print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save_profile"><input type="hidden" name="profile" value="'.((int) $profileId).'">';
        print '<table class="border centpercent tableforfield">';
        print '<tr><td class="titlefield"><label for="profile_label">'.$langs->trans('Label').'</label></td><td><input class="minwidth300" maxlength="255" name="profile_label" id="profile_label" value="'.dol_escape_htmltag($profile['label']).'"></td></tr>';
        print '<tr><td><label for="profile_active">'.$langs->trans('MahnwesenProfileActive').'</label></td><td>'.($isDefault ? $langs->trans('MahnwesenProfileAlwaysActive')
            : '<input type="checkbox" name="profile_active" id="profile_active" value="1"'.(!empty($profile['active']) ? ' checked' : '').'>').'</td></tr>';
        print '<tr><td><label for="profile_auto_allowed">'.$langs->trans('MahnwesenProfileAutoAllowed').'</label></td><td><input type="checkbox" name="profile_auto_allowed" id="profile_auto_allowed" value="1"'.(!empty($profile['auto_allowed']) ? ' checked' : '').'> <span class="opacitymedium">'.$langs->trans('MahnwesenProfileAutoAllowedHelp').'</span></td></tr>';
        print '<tr><td><label for="profile_final_step">'.$langs->trans('MahnwesenFinalStep').'</label></td><td><select name="profile_final_step" id="profile_final_step">';
        foreach ($steps as $step => $stepKey) {
            print '<option value="'.$step.'"'.($profile['final_step'] === $step ? ' selected' : '').'>'.$langs->trans($stepKey).'</option>';
        }
        print '</select></td></tr>';
        if ($isDefault) {
            print '<tr><td>'.$langs->trans('MahnwesenProfileAppliesTo').'</td><td>'.$langs->trans('MahnwesenProfileAppliesDefault').'</td></tr>';
        } else {
            print '<tr><td>'.$langs->trans('MahnwesenProfileProductCategories').'</td><td>'.$form->multiselectarray('product_categories', $manager->getCategoryChoices(0), $matches['product_category'], 0, 0, 'minwidth300').'</td></tr>';
            print '<tr><td>'.$langs->trans('MahnwesenProfileCustomerCategories').'</td><td>'.$form->multiselectarray('customer_categories', $manager->getCategoryChoices(2), $matches['customer_category'], 0, 0, 'minwidth300').'</td></tr>';
            print '<tr><td><label for="customer_type">'.$langs->trans('MahnwesenProfileCustomerType').'</label></td><td><select name="customer_type" id="customer_type"><option value=""></option>';
            foreach (array('private' => 'MahnwesenCustomerTypePrivate', 'company' => 'MahnwesenCustomerTypeCompany') as $type => $typeKey) {
                print '<option value="'.$type.'"'.($matches['customer_type'] === $type ? ' selected' : '').'>'.$langs->trans($typeKey).'</option>';
            }
            print '</select></td></tr>';
        }
        print '</table><div class="center margintoponly"><button class="button button-save" type="submit">'.$langs->trans('Save').'</button> ';
        print '<a class="button button-cancel" href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?tab=profiles').'">'.$langs->trans('Cancel').'</a> ';
        print '<a class="button" href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?tab=stages&profile='.$profileId).'">'.$langs->trans('MahnwesenTabStagesFees').'</a></div></form>';
    } else {
        print '<div class="div-table-responsive"><table class="noborder centpercent">';
        print '<tr class="liste_titre"><th>'.$langs->trans('MahnwesenProfile').'</th><th>'.$langs->trans('MahnwesenProfileAppliesTo').'</th><th>'.$langs->trans('MahnwesenProfileAutoAllowed').'</th><th>'.$langs->trans('MahnwesenFinalStep').'</th><th>'.$langs->trans('Status').'</th><th></th></tr>';
        foreach ($profiles as $id => $profile) {
            print '<tr class="oddeven" data-profile="'.dol_escape_htmltag($profile['code']).'">';
            print '<td><a href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?tab=profiles&profile='.$id.'&edit=1').'">'.dol_escape_htmltag($profile['label']).'</a></td>';
            print '<td>'.mw4_profile_scope($manager, $profile, $langs).'</td>';
            print '<td>'.yn($profile['auto_allowed']).'</td>';
            print '<td>'.$langs->trans(isset($steps[$profile['final_step']]) ? $steps[$profile['final_step']] : 'MahnwesenFinalStepNone').'</td>';
            print '<td>'.(!empty($profile['active']) ? '<span class="badge badge-status4">'.$langs->trans('Enabled').'</span>' : '<span class="badge badge-status8">'.$langs->trans('Disabled').'</span>').'</td>';
            print '<td class="right nowraponall"><a class="editfielda marginrightonly" href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?tab=profiles&profile='.$id.'&edit=1').'">'.img_edit().'</a>';
            print '<a class="marginrightonly" href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?tab=stages&profile='.$id).'">'.$langs->trans('MahnwesenTabStagesFees').'</a>';
            if (empty($profile['is_default'])) {
                print '<a href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?tab=profiles&profile='.$id.'&action=delete_profile&token='.newToken()).'">'.img_delete().'</a>';
            }
            print '</td></tr>';
        }
        print '</table></div><br>';
        print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?tab=profiles" class="marginbottomonly">';
        print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="create_profile">';
        print '<label for="profile_label">'.$langs->trans('MahnwesenProfileNewLabel').'</label> <input class="minwidth200" maxlength="255" name="profile_label" id="profile_label"> ';
        print '<button class="button" type="submit">'.$langs->trans('MahnwesenProfileCreate').'</button></form>';
        $codes = array();
        foreach ($profiles as $profile) { $codes[$profile['code']] = true; }
        foreach ($manager->getProfilePresets() as $preset => $settings) {
            if (isset($codes[$preset])) { continue; }
            print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?tab=profiles" class="inline-block marginrightonly">';
            print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="add_profile_preset"><input type="hidden" name="preset" value="'.$preset.'">';
            print '<button class="button" type="submit">'.$langs->trans('MahnwesenAddPreset', $langs->transnoentitiesnoconv($settings['label'])).'</button></form>';
        }
        print '<div class="opacitymedium margintoponly">'.$langs->trans('MahnwesenPresetHelp').'</div>';
    }
}

if ($tab === 'stages' && isset($profiles[$profileId])) {
    print mw4_profile_selector('stages', $profiles, $profileId, $langs);
    print '<div class="info">'.$langs->trans('MahnwesenStagesProfileIntro', dol_escape_htmltag($profiles[$profileId]['label']), mw4_profile_scope($manager, $profiles[$profileId], $langs)).'</div><br>';
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'?tab=stages">';
    print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save_stages"><input type="hidden" name="profile" value="'.((int) $profileId).'">';
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th>'.$langs->trans('DunningStage').'</th>';
    print '<th>'.$langs->trans('MahnwesenDaysAfterDue').'</th>';
    print '<th>'.$langs->trans('MahnwesenPaymentDays').'</th>';
    print '<th>'.$langs->trans('MahnwesenStageFee').'</th>';
    print '<th>'.$langs->trans('MahnwesenAutoSendAtStage').'</th>';
    print '<th>'.$langs->trans('Enabled').'</th>';
    print '</tr>';
    for ($level = 1; $level <= 4; $level++) {
        $rule = $rules[$level];
        print '<tr class="oddeven"><td><strong>'.$langs->trans($manager->getStageLabelKey($level)).'</strong></td>';
        print '<td><input class="width75" type="number" min="0" name="stage_days_'.$level.'" value="'.((int) $rule['days_after_due']).'"> '.$langs->trans('Days').'</td>';
        print '<td><input class="width75" type="number" min="0" max="365" name="stage_payment_days_'.$level.'" value="'.((int) $rule['payment_days']).'"> '.$langs->trans('Days').'</td>';
        print '<td><input class="width100" type="text" name="stage_fee_'.$level.'" value="'.dol_escape_htmltag(price($rule['fee_amount'], 0, $langs, 0, -1, -1, $conf->currency)).'"> '.$conf->currency.'</td>';
        print '<td><input type="checkbox" name="stage_auto_send_'.$level.'" value="1"'.(!empty($rule['send_email']) ? ' checked' : '').'> '.$langs->trans('MahnwesenAutoSendAtStageHelp').'</td>';
        print '<td><input type="checkbox" name="stage_enabled_'.$level.'" value="1"'.(!empty($rule['enabled']) ? ' checked' : '').'></td></tr>';
    }
    print '</table></div>';
    print '<div class="opacitymedium margintoponly">'.$langs->trans('MahnwesenPaymentDaysHelp').'</div>';
    if (empty($profiles[$profileId]['auto_allowed'])) { print '<div class="warning margintoponly">'.$langs->trans('MahnwesenProfileAutoOffHint').'</div>'; }
    print '<div class="warning margintoponly">'.$langs->trans('MahnwesenPrivateFeeLegalHelp').'</div>';
    print '<div class="warning margintoponly">'.$langs->trans('MahnwesenFeeNoInvoiceMutation').'</div>';
    print '<div class="center"><button class="button button-save" type="submit">'.$langs->trans('Save').'</button></div></form>';
}

if ($tab === 'templates' && isset($profiles[$profileId])) {
    print mw4_profile_selector('templates', $profiles, $profileId, $langs);
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
    print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="save_templates"><input type="hidden" name="profile" value="'.((int) $profileId).'">';
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre"><th>'.$langs->trans('DunningStage').'</th><th>'.$langs->trans('MahnwesenDolibarrTemplateType').'</th><th>'.$langs->trans('MahnwesenTemplateChoice').'</th><th>'.$langs->trans('MahnwesenTemplateUsed').'</th><th>'.$langs->trans('MahnwesenTemplateState').'</th></tr>';
    for ($level = 1; $level <= 4; $level++) {
        $type = $notice->getTemplateTypeForLevel($level);
        $stageTemplates = $notice->getNativeTemplates($level, $user);
        $selectedTemplate = $notice->getTemplate($level, $langs->defaultlang, $user, $profileId);
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
    print '<div class="'.($auto ? 'warning' : 'info').'">'.$langs->trans($auto ? 'MahnwesenAutomationEnabledInfo' : 'MahnwesenAutomationDisabledInfo').'</div>';
    if ($manager->isCoreReminderJobActive()) {
        print '<div class="warning margintoponly">'.$langs->trans('MahnwesenCoreReminderActive', dol_buildpath('/cron/list.php', 1)).'</div>';
    }
    print '<br>';
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
    print '<tr><td>'.$langs->trans('MahnwesenRunNotifyEmail').'</td><td><input class="minwidth300" type="email" name="run_notify_email" value="'.dol_escape_htmltag($notifyEmail).'"></td><td>'.$langs->trans('MahnwesenRunNotifyEmailHelp').'</td></tr>';
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
