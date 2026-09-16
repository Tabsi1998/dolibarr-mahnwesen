<?php
/*
 * Prepare a fresh Dolibarr for the runtime checks: company, mail server,
 * modules, users, customers and overdue invoices. Prints one JSON object with
 * the ids the checks need. Passwords come from the environment only.
 */

require __DIR__.'/bootstrap.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

global $db, $conf, $langs, $mysoc;

$admin = rt_admin($db);
$user = $admin;
$GLOBALS['user'] = $admin;

$salesPassword = (string) getenv('RT_SALES_PASSWORD');
$otherPassword = (string) getenv('RT_OTHER_PASSWORD');
if ($salesPassword === '' || $otherPassword === '') {
    rt_fail('RT_SALES_PASSWORD and RT_OTHER_PASSWORD must be set');
}

// Company identity and outgoing mail through the Mailpit container.
rt_const($db, 'MAIN_LANG_DEFAULT', 'de_DE');
rt_const($db, 'MAIN_MONNAIE', 'EUR');
rt_const($db, 'MAIN_INFO_SOCIETE_NOM', 'Runtime Verein');
rt_const($db, 'MAIN_INFO_SOCIETE_ADDRESS', 'Teststrasse 1');
rt_const($db, 'MAIN_INFO_SOCIETE_ZIP', '6020');
rt_const($db, 'MAIN_INFO_SOCIETE_TOWN', 'Innsbruck');
rt_const($db, 'MAIN_INFO_SOCIETE_MAIL', 'office@runtime-verein.test');
rt_const($db, 'MAIN_MAIL_EMAIL_FROM', 'robot@runtime-verein.test');
rt_const($db, 'MAIN_MAIL_SENDMODE', 'smtps');
rt_const($db, 'MAIN_MAIL_SMTP_SERVER', getenv('RT_SMTP_HOST') ?: 'mail');
rt_const($db, 'MAIN_MAIL_SMTP_PORT', getenv('RT_SMTP_PORT') ?: '1025');
rt_const($db, 'MAIN_MAIL_EMAIL_TLS', '0');
rt_const($db, 'MAIN_MAIL_EMAIL_STARTTLS', '0');
rt_const($db, 'MAIN_DISABLE_ALL_MAILS', '0');
rt_const($db, 'CRON_KEY', getenv('RT_CRON_KEY') ?: '');

foreach (array('modSociete', 'modFacture', 'modAgenda', 'modCron', 'modMahnwesen') as $module) {
    $result = activateModule($module);
    if (!empty($result['errors'])) {
        rt_fail('activating '.$module.' failed: '.implode(' | ', (array) $result['errors']));
    }
}
$conf->setValues($db);
if (!isModEnabled('mahnwesen')) {
    rt_fail('Mahnwesen is not enabled after activation');
}
rt_const($db, 'MAHNWESEN_MANUAL_SEND_ENABLED', '1');
rt_const($db, 'MAHNWESEN_FROM_EMAIL', 'mahnwesen@runtime-verein.test');
$admin->loadRights('', 1);

$countryId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");

/** Create an internal user with the given rights. */
function rt_user($db, $admin, $login, $password, $rights)
{
    $new = new User($db);
    $new->login = $login;
    $new->lastname = ucfirst($login);
    $new->firstname = 'Runtime';
    $new->email = $login.'@runtime-verein.test';
    $new->admin = 0;
    $new->entity = 1;
    if ($new->create($admin) <= 0) {
        rt_fail('user '.$login.': '.$new->error);
    }
    if ($new->setPassword($admin, $password) === -1) {
        rt_fail('password for '.$login.': '.$new->error);
    }
    foreach ($rights as $pair) {
        $id = rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module = '".$db->escape($pair[0])."' AND perms = '".$db->escape($pair[1])."'".(isset($pair[2]) ? " AND subperms = '".$db->escape($pair[2])."'" : " AND (subperms IS NULL OR subperms = '')")." AND entity = 1");
        if (!$id) {
            rt_fail('right '.implode('/', $pair).' does not exist');
        }
        $new->addrights((int) $id);
    }
    return (int) $new->id;
}

$restricted = array(
    array('facture', 'lire'),
    array('societe', 'lire'),
    array('mahnwesen', 'dashboard', 'read'),
    array('mahnwesen', 'case', 'write'),
    array('mahnwesen', 'notice', 'send'),
);
$salesId = rt_user($db, $admin, 'rtsales', $salesPassword, $restricted);
$otherId = rt_user($db, $admin, 'rtother', $otherPassword, $restricted);

/** Create a customer. */
function rt_customer($db, $admin, $name, $email, $typentCode, $countryId)
{
    $customer = new Societe($db);
    $customer->name = $name;
    $customer->client = 1;
    $customer->code_client = -1;
    $customer->email = $email;
    $customer->address = 'Kundenweg 7';
    $customer->zip = '6020';
    $customer->town = 'Innsbruck';
    $customer->country_id = $countryId;
    $customer->default_lang = 'de_DE';
    $customer->typent_id = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."c_typent WHERE code = '".$db->escape($typentCode)."'");
    if ($customer->create($admin) <= 0) {
        rt_fail('customer '.$name.': '.$customer->error.' '.implode(' | ', (array) $customer->errors));
    }
    return $customer;
}

$company = rt_customer($db, $admin, 'Runtime GmbH', 'buchhaltung@runtime-gmbh.test', 'TE_SMALL', $countryId);
$private = rt_customer($db, $admin, 'Rita Privat', 'rita@privat.test', 'TE_PRIVATE', $countryId);
if ($company->add_commercial($admin, $salesId) < 0) {
    rt_fail('sales representative: '.$company->error);
}

$contact = new Contact($db);
$contact->socid = $company->id;
$contact->firstname = 'Berta';
$contact->lastname = 'Billing';
$contact->email = 'berta.billing@runtime-gmbh.test';
$contact->statut = 1;
if ($contact->create($admin) <= 0) {
    rt_fail('contact: '.$contact->error);
}

/** Create, validate and print one invoice that fell due $daysOverdue days ago. */
function rt_invoice($db, $admin, $customer, $amount, $daysOverdue, $billingContactId = 0)
{
    global $langs;
    $invoice = new Facture($db);
    $invoice->socid = $customer->id;
    $invoice->type = Facture::TYPE_STANDARD;
    $invoice->date = dol_now() - (($daysOverdue + 14) * 86400);
    $invoice->cond_reglement_id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_payment_term WHERE code = 'RECEP'");
    $invoice->mode_reglement_id = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = 'VIR'");
    if ($invoice->create($admin) <= 0) {
        rt_fail('invoice for '.$customer->name.': '.$invoice->error.' '.implode(' | ', (array) $invoice->errors));
    }
    if ($invoice->addline('Runtime-Leistung', $amount, 1, 20) <= 0) {
        rt_fail('invoice line: '.$invoice->error);
    }
    if ($billingContactId > 0 && $invoice->add_contact($billingContactId, 'BILLING', 'external') <= 0) {
        rt_fail('billing contact: '.$invoice->error);
    }
    if ($invoice->validate($admin) <= 0) {
        rt_fail('validate invoice: '.$invoice->error.' '.implode(' | ', (array) $invoice->errors));
    }
    // A test needs a due date in the past; Dolibarr offers no API to backdate it.
    rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."facture SET date_lim_reglement = '".$db->idate(dol_now() - ($daysOverdue * 86400))."' WHERE rowid = ".((int) $invoice->id));
    $invoice->fetch($invoice->id);
    $outputlangs = new Translate('', $GLOBALS['conf']);
    $outputlangs->setDefaultLang('de_DE');
    if ($invoice->generateDocument('sponge', $outputlangs) <= 0) {
        rt_fail('invoice PDF: '.$invoice->error);
    }
    $invoice->fetch($invoice->id);
    return array('id' => (int) $invoice->id, 'ref' => (string) $invoice->ref, 'last_main_doc' => (string) $invoice->last_main_doc);
}

$invoices = array(
    'company_overdue' => rt_invoice($db, $admin, $company, 100, 35, (int) $contact->id),
    'company_recent' => rt_invoice($db, $admin, $company, 50, 1, (int) $contact->id),
    'private_overdue' => rt_invoice($db, $admin, $private, 80, 12),
);

$cronId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."cronjob WHERE classesname = '/mahnwesen/class/dunningmanager.class.php' AND methodename = 'doScheduledJob'");

print json_encode(array(
    'dolibarr' => DOL_VERSION,
    'php' => PHP_VERSION,
    'users' => array('admin' => (int) $admin->id, 'sales' => $salesId, 'other' => $otherId),
    'customers' => array('company' => (int) $company->id, 'private' => (int) $private->id),
    'contacts' => array('billing' => (int) $contact->id),
    'invoices' => $invoices,
    'cron_job' => $cronId,
), JSON_PRETTY_PRINT)."\n";
