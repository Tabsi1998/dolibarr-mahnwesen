<?php
/*
 * Mahnwesen - dunning notice preparation and controlled delivery for Dolibarr
 * GPL-3.0-or-later
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

require_once __DIR__.'/dunningnoticeservice.methods1.trait.php';
require_once __DIR__.'/dunningnoticeservice.methods2.trait.php';
require_once __DIR__.'/dunningnoticeservice.methods3.trait.php';
require_once __DIR__.'/dunningnoticeservice.methods4.trait.php';
require_once __DIR__.'/dunningnoticeservice.methods5.trait.php';

/**
 * Prepare native/internal HTML templates, PDF previews and controlled sends.
 */
class DunningNoticeService
{
    /** @var DoliDB */
    public $db;

    /** @var DunningManager */
    public $manager;

    /** @var string */
    public $error = '';

    /** @var array */
    public $errors = array();
    use DunningNoticeServiceMethods1, DunningNoticeServiceMethods2, DunningNoticeServiceMethods3, DunningNoticeServiceMethods4, DunningNoticeServiceMethods5;
}
