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

require_once __DIR__.'/../class/mahnwesenworkflowpolicy.class.php';
require_once __DIR__.'/../class/dunningmanager.scan.trait.php';
require_once __DIR__.'/../class/dunningmanager.stages.trait.php';
require_once __DIR__.'/../class/dunningmanager.workflow.trait.php';
require_once __DIR__.'/../class/dunningmanager.automation.trait.php';
require_once __DIR__.'/../class/dunningnoticeservice.templates.trait.php';
require_once __DIR__.'/../class/dunningnoticeservice.recipients.trait.php';
require_once __DIR__.'/../class/dunningnoticeservice.delivery.trait.php';

function mwAssert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, "FAIL: ".$message."\n"); exit(1); }
}

class StagePolicyFixture
{
    use DunningManagerScan, DunningManagerStages;
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

class DateOnlyDb
{
    public function jdate($value) { return strtotime((string) $value); }
}
class StageSpacingFixture
{
    use DunningManagerWorkflow;
    public $db;
    public $completedAt;
    public $paymentDays = 0;
    public function getStageThresholds() { return array(1 => 3, 2 => 10, 3 => 20, 4 => 30); }
    public function getPreviousEnabledLevel($level) { return (int) $level - 1; }
    public function getStageCompletionTimestamp($caseId, $level) { return $this->completedAt; }
    public function getStageNoticeTimestamp($caseId, $level) { return $this->completedAt; }
    public function getPaymentDaysForLevel($level) { return $this->paymentDays; }
}
$spacing = new StageSpacingFixture();
$spacing->db = new DateOnlyDb();
$spacing->completedAt = strtotime('2026-01-14 14:00:00');
mwAssert($spacing->calculateWorkflowStageDueAt(7, '2026-01-01', 2) === '2026-01-21 00:00:00',
    'a reminder sent in the afternoon makes the next stage due at the start of the day seven days later (#18)');
$spacing->completedAt = strtotime('2026-01-02 14:00:00');
mwAssert($spacing->calculateWorkflowStageDueAt(7, '2026-01-01', 2) === '2026-01-11 00:00:00',
    'an early reminder leaves the calendar threshold in charge');
$spacing->completedAt = strtotime('2026-01-14 14:00:00');
$spacing->paymentDays = 10;
mwAssert($spacing->calculateWorkflowStageDueAt(7, '2026-01-01', 2) === '2026-01-25 00:00:00',
    'a payment deadline holds the next stage back until the day after it (#64)');

class RuleDefaultsFixture
{
    use DunningManagerStages;
    protected function getIntSetting($key, $default) { return $default; }
    public function getStageLabelKey($level) { return 'DunningStage'.$level; }
}
$defaults = new RuleDefaultsFixture();
mwAssert((float) $defaults->getDefaultRule(2)['fee_amount'] === 0.0, 'fees must default to zero');

class AutomationDefaultsFixture
{
    use DunningManagerAutomation, DunningManagerStages;
    protected function getIntSetting($key, $default) { return $default; }
}
$automationDefaults = new AutomationDefaultsFixture();
mwAssert($automationDefaults->getAutomaticRetryMax() === 3, 'automatic retries must have a safe default');
mwAssert($automationDefaults->getAutomaticMaxPerCustomer() === 1, 'automatic delivery must default to one email per customer and run');

class TemplatePolicyFixture
{
    use DunningNoticeServiceTemplates;
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

class OwnTemplateFixture
{
    use DunningNoticeServiceTemplates;
    public $db;
    public $manager;
    public $error = '';
    public $rows = array();
    public function getNativeTemplates($level = 0, $user = null) { return $this->rows; }
}
$own = new OwnTemplateFixture(null, null);
$own->rows = array(
    1 => array('lang' => 'de_DE', 'defaultfortype' => 1, 'module' => 'mahnwesen', 'label' => 'Starter DE'),
    2 => array('lang' => '', 'defaultfortype' => 0, 'module' => '', 'label' => 'Own without language'),
    3 => array('lang' => 'en_US', 'defaultfortype' => 1, 'module' => '', 'label' => 'Own English'),
);
$selected = $own->getDefaultNativeTemplateForLevel(3, 'de_DE');
mwAssert($selected !== false && $selected['label'] === 'Own without language', 'an own template without language must beat the German starter');
$own->rows[2]['lang'] = 'fr_FR';
$selected = $own->getDefaultNativeTemplateForLevel(3, 'de_DE');
mwAssert($selected !== false && $selected['label'] === 'Starter DE', 'an own template in another language must not beat a fitting starter');

class FailingRecipientDb
{
    public function query($sql) { return false; }
    public function lasterror() { return 'synthetic database failure'; }
}
class RecipientPolicyFixture
{
    use DunningNoticeServiceRecipients;
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

class SendFailureFixture
{
    use DunningNoticeServiceDelivery;
}
$sendFailure = new SendFailureFixture();
$smtpMail = function ($sendmode, $log) {
    $mail = new stdClass();
    $mail->sendmode = $sendmode;
    $mail->smtps = new stdClass();
    $mail->smtps->log = $log;
    return $mail;
};
mwAssert($sendFailure->failedBeforeMessageData($smtpMail('smtps', '')) === true, 'no answer from the mail server means nothing was delivered (#14)');
mwAssert($sendFailure->failedBeforeMessageData($smtpMail('smtps', "220 mail ESMTP\r\n250 hello\r\n550 5.7.1 sender rejected\r\n")) === true,
    'a rejected sender before DATA means nothing was delivered (#14)');
mwAssert($sendFailure->failedBeforeMessageData($smtpMail('smtps', "220 mail ESMTP\r\n250 hello\r\n250 ok\r\n250 ok\r\n354 go ahead\r\n451 local error\r\n")) === false,
    'a failure after the server accepted the message data stays ambiguous (#14)');
mwAssert($sendFailure->failedBeforeMessageData($smtpMail('mail', '')) === false, 'PHP mail() gives no protocol trace, so its failures stay ambiguous');
mwAssert($sendFailure->failedBeforeMessageData(null) === true, 'a mailer that could not be built sent nothing');

// ------------------------------------------------------------ workflow policy (#29)
$thresholds = array(1 => 3, 2 => 10, 3 => 20, 4 => 30);
$all = array(1 => true, 2 => true, 3 => true, 4 => true);
mwAssert(MahnwesenWorkflowPolicy::stageForDaysLate(2, $thresholds, $all) === 0, 'no stage before the first threshold');
mwAssert(MahnwesenWorkflowPolicy::stageForDaysLate(3, $thresholds, $all) === 1, 'the threshold day itself reaches the stage');
mwAssert(MahnwesenWorkflowPolicy::stageForDaysLate(-5, $thresholds, $all) === 0, 'an invoice not yet due has no stage');
mwAssert(MahnwesenWorkflowPolicy::stageForDaysLate(25, $thresholds, array(1 => true, 2 => true, 3 => false, 4 => true)) === 2,
    'a switched-off stage is never the calendar stage');
mwAssert(MahnwesenWorkflowPolicy::nextRequiredLevel(4, $all, array()) === 1, 'stages go in order: the reminder first');
mwAssert(MahnwesenWorkflowPolicy::nextRequiredLevel(4, $all, array(1 => true, 2 => true)) === 3, 'the first stage not completed');
mwAssert(MahnwesenWorkflowPolicy::nextRequiredLevel(4, array(1 => false, 2 => true, 3 => true, 4 => true), array()) === 2,
    'a switched-off stage is skipped');
mwAssert(MahnwesenWorkflowPolicy::nextRequiredLevel(2, $all, array(1 => true, 2 => true)) === 0, 'nothing due once the calendar stage is completed');
mwAssert(MahnwesenWorkflowPolicy::nextRequiredLevel(0, $all, array()) === 0, 'nothing due before the first threshold');
mwAssert(MahnwesenWorkflowPolicy::previousEnabledLevel(3, array(1 => true, 2 => false, 3 => true)) === 1, 'the previous stage skips a switched-off one');
mwAssert(MahnwesenWorkflowPolicy::previousEnabledLevel(1, $all) === 0, 'the reminder has no previous stage');
mwAssert(MahnwesenWorkflowPolicy::nextFutureLevel(2, array(1 => true, 2 => true, 3 => false, 4 => true)) === 4, 'the next stage skips a switched-off one');
mwAssert(MahnwesenWorkflowPolicy::nextFutureLevel(4, $all) === 0, 'nothing follows the last stage');
mwAssert(MahnwesenWorkflowPolicy::stageDueAt('2026-01-31', 30) === '2026-03-02 00:00:00', 'the due date counts calendar days across a month end');
mwAssert(MahnwesenWorkflowPolicy::stageDueAt('2026-03-25', 3) === '2026-03-28 00:00:00', 'the due date is not moved by a clock change');
mwAssert(MahnwesenWorkflowPolicy::stageDueAt('', 3) === null && MahnwesenWorkflowPolicy::stageDueAt('2026-01-01', null) === null,
    'no due date without a date or a threshold');
$calendar = '2026-01-11 00:00:00';
mwAssert(MahnwesenWorkflowPolicy::spacedDueAt($calendar, null, 7, 0, null) === $calendar, 'without a completed stage the calendar decides');
mwAssert(MahnwesenWorkflowPolicy::spacedDueAt($calendar, strtotime('2026-01-14 23:59:59'), 7, 0, null) === '2026-01-21 00:00:00',
    'the spacing counts whole days, even from a notice sent just before midnight (#18)');
mwAssert(MahnwesenWorkflowPolicy::spacedDueAt($calendar, strtotime('2026-01-14 14:00:00'), 7, 10, strtotime('2026-01-14 14:00:00')) === '2026-01-25 00:00:00',
    'a payment deadline holds the next stage until the day after it (#64)');
mwAssert(MahnwesenWorkflowPolicy::spacedDueAt($calendar, strtotime('2026-01-14 14:00:00'), 7, 10, null) === '2026-01-21 00:00:00',
    'a skipped stage named no deadline, so only the spacing counts');
mwAssert(MahnwesenWorkflowPolicy::spacedDueAt('2026-02-01 00:00:00', strtotime('2026-01-14 14:00:00'), 7, 3, strtotime('2026-01-14 14:00:00')) === '2026-02-01 00:00:00',
    'a later calendar date wins over spacing and deadline');

echo "Policy tests: OK\n";
