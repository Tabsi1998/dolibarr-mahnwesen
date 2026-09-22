<?php
/*
 * Prepare a fresh Dolibarr for the runtime checks. Called in stages, each
 * printing one JSON object with the ids the checks need:
 *
 *   base     company, mail server, core modules, customers and overdue invoices
 *   enabled  after the module was enabled from the module list: its settings,
 *            users with module rights, the cron job
 *   reset    after the upgrade test: back to a Dolibarr that never had the
 *            module, apart from its files
 *   profiles products in categories and invoices for them, for the dunning
 *            profiles (#32)
 *   member   a member with a subscription whose invoice Dolibarr links to it (#58)
 *   payment  one overdue invoice of 40 for the payment checks (#36)
 *
 * Passwords come from the environment only.
 */

require __DIR__.'/bootstrap.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

global $db, $conf, $langs, $mysoc;

$admin = rt_admin($db);
$user = $admin;
$GLOBALS['user'] = $admin;
$stage = isset($argv[1]) ? $argv[1] : '';

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

/** Create, validate and print one invoice that fell due $daysOverdue days ago; $lines as (text, amount, product). */
function rt_invoice($db, $admin, $customer, $amount, $daysOverdue, $billingContactId = 0, $lines = array())
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
    foreach ($lines ?: array(array('Runtime-Leistung', $amount, 0)) as $line) {
        if ($invoice->addline($line[0], $line[1], 1, 20, 0, 0, (int) $line[2]) <= 0) {
            rt_fail('invoice line: '.$invoice->error);
        }
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

if ($stage === 'base') {
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

    foreach (array('modSociete', 'modFacture', 'modAgenda', 'modCron') as $module) {
        $result = activateModule($module);
        if (!empty($result['errors'])) {
            rt_fail('activating '.$module.' failed: '.implode(' | ', (array) $result['errors']));
        }
    }
    $conf->setValues($db);
    $admin->loadRights('', 1);

    $countryId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_country WHERE code = 'AT'");
    $company = rt_customer($db, $admin, 'Runtime GmbH', 'buchhaltung@runtime-gmbh.test', 'TE_SMALL', $countryId);
    $private = rt_customer($db, $admin, 'Rita Privat', 'rita@privat.test', 'TE_PRIVATE', $countryId);

    $contact = new Contact($db);
    $contact->socid = $company->id;
    $contact->firstname = 'Berta';
    $contact->lastname = 'Billing';
    $contact->email = 'berta.billing@runtime-gmbh.test';
    $contact->statut = 1;
    if ($contact->create($admin) <= 0) {
        rt_fail('contact: '.$contact->error);
    }

    $invoices = array(
        'company_overdue' => rt_invoice($db, $admin, $company, 100, 35, (int) $contact->id),
        'company_recent' => rt_invoice($db, $admin, $company, 50, 1, (int) $contact->id),
        'private_overdue' => rt_invoice($db, $admin, $private, 80, 12),
        'company_renamed' => rt_invoice($db, $admin, $company, 30, 20, (int) $contact->id),
    );

    // PDF models and document settings can name the invoice PDF differently from
    // REF/REF.pdf. Rename one and point last_main_doc at it, as such a model would.
    $renamed = $invoices['company_renamed'];
    $oldPath = DOL_DATA_ROOT.'/'.$renamed['last_main_doc'];
    $newRelative = dirname($renamed['last_main_doc']).'/'.$renamed['ref'].'-signed.pdf';
    if (!rename($oldPath, DOL_DATA_ROOT.'/'.$newRelative)) {
        rt_fail('could not rename '.$oldPath);
    }
    rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."facture SET last_main_doc = '".$db->escape($newRelative)."' WHERE rowid = ".((int) $renamed['id']));
    $invoices['company_renamed']['last_main_doc'] = $newRelative;

    print json_encode(array(
        'dolibarr' => DOL_VERSION,
        'php' => PHP_VERSION,
        'customers' => array('company' => (int) $company->id, 'private' => (int) $private->id),
        'contacts' => array('billing' => (int) $contact->id),
        'invoices' => $invoices,
    ), JSON_PRETTY_PRINT)."\n";
    exit(0);
}

if ($stage === 'enabled') {
    $salesPassword = (string) getenv('RT_SALES_PASSWORD');
    $otherPassword = (string) getenv('RT_OTHER_PASSWORD');
    if ($salesPassword === '' || $otherPassword === '') {
        rt_fail('RT_SALES_PASSWORD and RT_OTHER_PASSWORD must be set');
    }
    if (!isModEnabled('mahnwesen')) {
        rt_fail('Mahnwesen is not enabled; enable it from the module list first');
    }
    rt_const($db, 'MAHNWESEN_MANUAL_SEND_ENABLED', '1');
    rt_const($db, 'MAHNWESEN_FROM_EMAIL', 'mahnwesen@runtime-verein.test');
    $admin->loadRights('', 1);

    $restricted = array(
        array('facture', 'lire'),
        array('societe', 'lire'),
        array('mahnwesen', 'dashboard', 'read'),
        array('mahnwesen', 'case', 'write'),
        array('mahnwesen', 'notice', 'send'),
    );
    $salesId = rt_user($db, $admin, 'rtsales', $salesPassword, $restricted);
    $otherId = rt_user($db, $admin, 'rtother', $otherPassword, $restricted);
    $company = new Societe($db);
    if ($company->fetch(0, 'Runtime GmbH') <= 0 || $company->add_commercial($admin, $salesId) < 0) {
        rt_fail('sales representative: '.$company->error);
    }

    $cronId = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."cronjob WHERE classesname = '/mahnwesen/class/dunningmanager.class.php' AND methodename = 'doScheduledJob'");
    print json_encode(array(
        'users' => array('admin' => (int) $admin->id, 'sales' => $salesId, 'other' => $otherId),
        'cron_job' => $cronId,
    ), JSON_PRETTY_PRINT)."\n";
    exit(0);
}

if ($stage === 'legacy') {
    // Cases and stage rows as an earlier version of the module makes them, with its own code (#23).
    require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);
    $manager = new DunningManager($db);
    if (!$manager->ensureRuleRows($admin)) {
        rt_fail('stage rows: '.$manager->error);
    }
    if ($manager->syncCases($admin) === false) {
        rt_fail('synchronise: '.$manager->error);
    }
    print json_encode(array('legacy' => 1))."\n";
    exit(0);
}

if ($stage === 'reset') {
    // After the upgrade test: back to a Dolibarr that never had the module, apart from its files.
    require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
    unActivateModule('modMahnwesen');
    $statements = array(
        "DELETE FROM ".MAIN_DB_PREFIX."const WHERE name LIKE 'MAHNWESEN\\_%'",
        "DELETE FROM ".MAIN_DB_PREFIX."c_email_templates WHERE module = 'mahnwesen' OR type_template LIKE 'mahnwesen\\_%'",
        "DELETE FROM ".MAIN_DB_PREFIX."cronjob WHERE classesname = '/mahnwesen/class/dunningmanager.class.php'",
        "DELETE FROM ".MAIN_DB_PREFIX."actioncomm WHERE ref_ext LIKE 'mahnwesen-%'",
        "SET FOREIGN_KEY_CHECKS = 0",
    );
    $tables = rt_value($db, "SELECT GROUP_CONCAT(table_name) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '".MAIN_DB_PREFIX."mahnwesen\\_%'");
    foreach (array_filter(explode(',', (string) $tables)) as $table) {
        $statements[] = "DROP TABLE ".$table;
    }
    $statements[] = "SET FOREIGN_KEY_CHECKS = 1";
    // The module's fields on customers and invoices (#37), declaration and column.
    $statements[] = "DELETE FROM ".MAIN_DB_PREFIX."extrafields WHERE name LIKE 'mahnwesen\\_%'";
    $columns = rt_value($db, "SELECT GROUP_CONCAT(CONCAT(table_name, ' ', column_name)) FROM information_schema.columns WHERE table_schema = DATABASE()"
        ." AND table_name IN ('".MAIN_DB_PREFIX."societe_extrafields', '".MAIN_DB_PREFIX."facture_extrafields') AND column_name LIKE 'mahnwesen\\_%'");
    foreach (array_filter(explode(',', (string) $columns)) as $pair) {
        list($table, $column) = explode(' ', $pair);
        $statements[] = "ALTER TABLE ".$table." DROP COLUMN ".$column;
    }
    foreach ($statements as $sql) {
        rt_exec($db, $sql);
    }
    dol_delete_dir_recursive(DOL_DATA_ROOT.'/mahnwesen');
    print json_encode(array('reset' => 1))."\n";
    exit(0);
}

if ($stage === 'profiles') {
    // Membership fees and merchandise as products in their categories, and a
    // dues invoice, a merchandise invoice and one with both (#32).
    require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
    require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
    foreach (array('modCategorie', 'modProduct') as $module) {
        $result = activateModule($module);
        if (!empty($result['errors'])) {
            rt_fail('activating '.$module.' failed: '.implode(' | ', (array) $result['errors']));
        }
    }
    $conf->setValues($db);
    $admin->loadRights('', 1);
    $categories = array();
    $products = array();
    foreach (array('membership' => array('Mitgliedsbeitrag', 'RT-BEITRAG', 60), 'merchandise' => array('Merchandise', 'RT-SHIRT', 25)) as $key => $info) {
        $category = new Categorie($db);
        $category->label = $info[0];
        $category->type = Categorie::TYPE_PRODUCT;
        if ($category->create($admin) <= 0) {
            rt_fail('category '.$info[0].': '.$category->error);
        }
        $product = new Product($db);
        $product->ref = $info[1];
        $product->label = $info[0];
        $product->type = Product::TYPE_PRODUCT;
        $product->price = $info[2];
        $product->price_base_type = 'HT';
        $product->tva_tx = 20;
        $product->status = 1;
        $product->status_buy = 0;
        if ($product->create($admin) <= 0 || $category->add_type($product, Categorie::TYPE_PRODUCT) < 0) {
            rt_fail('product '.$info[1].': '.$product->error.' '.$category->error);
        }
        $categories[$key] = (int) $category->id;
        $products[$key] = array($info[0], $info[2], (int) $product->id);
    }
    $member = new Societe($db);
    if ($member->fetch(0, 'Rita Privat') <= 0) {
        rt_fail('customer Rita Privat: '.$member->error);
    }
    print json_encode(array(
        'categories' => $categories,
        'invoices' => array(
            'membership' => rt_invoice($db, $admin, $member, 0, 25, 0, array($products['membership'])),
            'merchandise' => rt_invoice($db, $admin, $member, 0, 25, 0, array($products['merchandise'])),
            'mixed' => rt_invoice($db, $admin, $member, 0, 25, 0, array($products['membership'], $products['merchandise'])),
        ),
    ), JSON_PRETTY_PRINT)."\n";
    exit(0);
}

if ($stage === 'member') {
    // A member of the club, a subscription, and the invoice Dolibarr links to it (#58).
    require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
    require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';
    require_once DOL_DOCUMENT_ROOT.'/adherents/class/subscription.class.php';
    $result = activateModule('modAdherent');
    if (!empty($result['errors'])) {
        rt_fail('activating modAdherent failed: '.implode(' | ', (array) $result['errors']));
    }
    $conf->setValues($db);
    $admin->loadRights('', 1);

    $customer = new Societe($db);
    if ($customer->fetch(0, 'Rita Privat') <= 0) {
        rt_fail('customer Rita Privat: '.$customer->error);
    }
    $type = new AdherentType($db);
    $type->label = 'Runtime Mitglied';
    $type->subscription = 1;
    $type->status = 1;
    if ($type->create($admin) <= 0) {
        rt_fail('member type: '.$type->error);
    }
    $member = new Adherent($db);
    $member->firstname = 'Rita';
    $member->lastname = 'Privat';
    $member->login = 'rt-rita';
    $member->morphy = 'phy';
    $member->typeid = (int) $type->id;
    $member->email = 'rita@privat.test';
    $member->statut = 1;
    $member->socid = (int) $customer->id;
    if ($member->create($admin) <= 0) {
        rt_fail('member: '.$member->error.' '.implode(' | ', (array) $member->errors));
    }
    $member->fetch((int) $member->id);
    $subscription = new Subscription($db);
    $subscription->fk_adherent = (int) $member->id;
    $subscription->fk_type = (int) $type->id;
    $subscription->dateh = dol_now() - (90 * 86400);
    $subscription->datef = dol_now() + (275 * 86400);
    $subscription->amount = 60;
    $subscription->note_public = 'Runtime';
    if ($subscription->create($admin) <= 0) {
        rt_fail('subscription: '.$subscription->error);
    }
    // The dues invoice, linked to the subscription the way Dolibarr's member card links it.
    $invoice = new Facture($db);
    $invoice->socid = (int) $customer->id;
    $invoice->type = Facture::TYPE_STANDARD;
    $invoice->date = dol_now() - (44 * 86400);
    $invoice->cond_reglement_id = (int) rt_value($db, "SELECT rowid FROM ".MAIN_DB_PREFIX."c_payment_term WHERE code = 'RECEP'");
    $invoice->linked_objects['subscription'] = (int) $subscription->id;
    if ($invoice->create($admin) <= 0) {
        rt_fail('dues invoice: '.$invoice->error.' '.implode(' | ', (array) $invoice->errors));
    }
    if ($invoice->addline('Mitgliedsbeitrag 2026 (Beitragslauf)', 60, 1, 0) <= 0) {
        rt_fail('dues invoice line: '.$invoice->error);
    }
    if ($invoice->validate($admin) <= 0) {
        rt_fail('validate dues invoice: '.$invoice->error);
    }
    rt_exec($db, "UPDATE ".MAIN_DB_PREFIX."facture SET date_lim_reglement = '".$db->idate(dol_now() - (30 * 86400))."' WHERE rowid = ".((int) $invoice->id));
    $invoice->fetch((int) $invoice->id);
    $outputlangs = new Translate('', $GLOBALS['conf']);
    $outputlangs->setDefaultLang('de_DE');
    $invoice->generateDocument('sponge', $outputlangs);
    print json_encode(array(
        'member' => (int) $member->id,
        'subscription' => (int) $subscription->id,
        'invoice' => array('id' => (int) $invoice->id, 'ref' => (string) $invoice->ref),
    ), JSON_PRETTY_PRINT)."\n";
    exit(0);
}

if ($stage === 'payment') {
    // One overdue invoice of 40 EUR, for payments in two steps (#36).
    $customer = new Societe($db);
    if ($customer->fetch(0, 'Runtime GmbH') <= 0) {
        rt_fail('customer Runtime GmbH: '.$customer->error);
    }
    print json_encode(array('invoice' => rt_invoice($db, $admin, $customer, 40, 18, 0, array(array('Runtime-Zahlungsfall', 40, 0)))), JSON_PRETTY_PRINT)."\n";
    exit(0);
}

if ($stage === 'pay') {
    // A customer payment through Dolibarr's own class, so its triggers fire (#36).
    require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
    $invoiceId = isset($argv[2]) ? (int) $argv[2] : 0;
    $amount = isset($argv[3]) ? (float) $argv[3] : 0.0;
    if ($invoiceId <= 0 || $amount <= 0) {
        rt_fail('pay needs an invoice id and an amount');
    }
    $payment = new Paiement($db);
    $payment->datepaye = dol_now();
    $payment->amounts = array($invoiceId => $amount);
    $payment->paiementid = (int) rt_value($db, "SELECT id FROM ".MAIN_DB_PREFIX."c_paiement WHERE code = 'VIR'");
    $payment->num_payment = 'RT-'.dol_now();
    if ($payment->create($admin) <= 0) {
        rt_fail('payment: '.$payment->error.' '.implode(' | ', (array) $payment->errors));
    }
    // Dolibarr's payment page closes the invoice when nothing is left.
    $invoice = new Facture($db);
    if ($invoice->fetch($invoiceId) > 0 && (float) $invoice->getRemainToPay(0) <= 0 && $invoice->setPaid($admin) <= 0) {
        rt_fail('set invoice paid: '.$invoice->error);
    }
    print json_encode(array('payment' => (int) $payment->id))."\n";
    exit(0);
}

if ($stage === 'unpay') {
    // Cancelling a payment, through Dolibarr's own class (#36).
    require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
    $payment = new Paiement($db);
    if ($payment->fetch(isset($argv[2]) ? (int) $argv[2] : 0) <= 0) {
        rt_fail('payment not found');
    }
    // Dolibarr refuses to remove a payment from a closed invoice, so the
    // invoice is opened again first, exactly as its page does.
    $payment->fetchObjectLinked(null, 'facture');
    $invoices = array();
    $res = $db->query('SELECT fk_facture FROM '.MAIN_DB_PREFIX.'paiement_facture WHERE fk_paiement = '.((int) $payment->id));
    while ($res && ($row = $db->fetch_object($res))) {
        $invoices[] = (int) $row->fk_facture;
    }
    foreach ($invoices as $invoiceId) {
        $invoice = new Facture($db);
        if ($invoice->fetch($invoiceId) > 0 && (int) $invoice->paye === 1 && $invoice->setUnpaid($admin) <= 0) {
            rt_fail('reopen invoice: '.$invoice->error);
        }
    }
    if ($payment->delete($admin) <= 0) {
        rt_fail('cancel payment: '.$payment->error.' '.implode(' | ', (array) $payment->errors));
    }
    print json_encode(array('cancelled' => 1))."
";
    exit(0);
}

rt_fail('unknown stage "'.$stage.'", use base, enabled, legacy, reset, profiles, member, payment, pay or unpay');
