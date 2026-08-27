<?php
/* Lightweight, dependency-free unit tests for pure safety policies. */

if (!defined('LOG_ERR')) { define('LOG_ERR', 3); }
if (!defined('MAIN_DB_PREFIX')) { define('MAIN_DB_PREFIX', 'llx_'); }
function dol_syslog($message, $level = 0) {}
function getDolGlobalInt($key, $default = 0) { return $default; }
function getDolGlobalString($key, $default = '') { return $default; }

if (!class_exists('Facture')) {
    class Facture
    {
        const TYPE_STANDARD = 0;
        const TYPE_REPLACEMENT = 1;
        const TYPE_CREDIT_NOTE = 2;
        const TYPE_DEPOSIT = 3;
        const TYPE_PROFORMA = 4;
        const TYPE_SITUATION = 5;
        const STATUS_VALIDATED = 1;
    }
}

require_once __DIR__.'/../class/dunningmanager.methods1.trait.php';
require_once __DIR__.'/../class/dunningmanager.methods4.trait.php';
require_once __DIR__.'/../class/dunningnoticeservice.methods1.trait.php';
require_once __DIR__.'/../class/dunningnoticeservice.methods2.trait.php';

function mwAssert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, "FAIL: ".$message."\n"); exit(1); }
}

class StagePolicyFixture
{
    use DunningManagerMethods1;
    public function getRules($refresh = false)
    {
        return array(
            1 => array('days_after_due' => 3, 'enabled' => 1),
            2 => array('days_after_due' => 10, 'enabled' => 1),
            3 => array('days_after_due' => 20, 'enabled' => 1),
            4 => array('days_after_due' => 30, 'enabled' => 1),
        );
    }
}
$stage = new StagePolicyFixture(null);
mwAssert($stage->determineStage(2) === 0, 'stage before first threshold');
mwAssert($stage->determineStage(3) === 1, 'first stage threshold');
mwAssert($stage->determineStage(30) === 4, 'last stage threshold');
mwAssert(!$stage->isSupportedInvoiceType(Facture::TYPE_CREDIT_NOTE), 'credit notes must stay excluded');

class RuleDefaultsFixture
{
    use DunningManagerMethods4;
    protected function getIntSetting($key, $default) { return $default; }
    public function getStageLabelKey($level) { return 'DunningStage'.$level; }
}
$defaults = new RuleDefaultsFixture();
mwAssert((float) $defaults->getDefaultRule(2)['fee_amount'] === 0.0, 'fees must default to zero');

class TemplatePolicyFixture
{
    use DunningNoticeServiceMethods1;
    public $db;
    public $manager;
    public $error = '';
    public function getNativeTemplates($level = 0)
    {
        return array(
            1 => array('lang'=>'de_DE', 'defaultfortype'=>1, 'label'=>'DE'),
            2 => array('lang'=>'en_US', 'defaultfortype'=>0, 'label'=>'EN'),
        );
    }
}
$templates = new TemplatePolicyFixture(null, null);
$selected = $templates->getDefaultNativeTemplateForLevel(1, 'en_GB');
mwAssert($selected !== false && $selected['label'] === 'EN', 'same-language template must beat another-language default');

class FailingRecipientDb
{
    public function query($sql) { return false; }
    public function lasterror() { return 'synthetic database failure'; }
}
class RecipientPolicyFixture
{
    use DunningNoticeServiceMethods2;
    public $db;
    public $error = '';
    public $errors = array();
    public $recipientLookupFailed = false;
}
$recipientService = new RecipientPolicyFixture();
$recipientService->db = new FailingRecipientDb();
$invoice = new stdClass(); $invoice->id = 42; $invoice->thirdparty = new stdClass(); $invoice->thirdparty->email = 'fallback@example.test';
mwAssert($recipientService->getRecipientOptions($invoice) === array(), 'recipient lookup errors must fail closed');
mwAssert($recipientService->recipientLookupFailed === true, 'recipient lookup failure flag');

echo "Policy tests: OK\n";
