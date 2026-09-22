<?php
/* Automatic sending: settings, the cron run and the dry run's shared decision, run records, operator aids. */
trait DunningManagerAutomation
{
    /** @return bool */
    public function isAutomaticSendEnabled()
    {
        return getDolGlobalInt('MAHNWESEN_AUTO_SEND_ENABLED') > 0;
    }

    /** @return int */
    public function getAutomaticSendMax()
    {
        return max(1, min(100, $this->getIntSetting('MAHNWESEN_AUTO_SEND_MAX', 10)));
    }

    /** Maximum failed automatic attempts before operator intervention. */
    public function getAutomaticRetryMax()
    {
        return max(1, min(10, $this->getIntSetting('MAHNWESEN_AUTO_RETRY_MAX', 3)));
    }

    /** Maximum automatic emails for one customer in a single run. */
    public function getAutomaticMaxPerCustomer()
    {
        return max(1, min(20, $this->getIntSetting('MAHNWESEN_AUTO_MAX_PER_CUSTOMER', 1)));
    }

    /** @return string */
    public function getAutomaticRecipientPolicy()
    {
        $value = getDolGlobalString('MAHNWESEN_AUTO_RECIPIENT_POLICY');
        return in_array($value, array('single_billing', 'first_billing'), true) ? $value : 'single_billing';
    }

    /** Start a persistent automation-run audit row. */
    public function beginAutomationRun($mode, $user)
    {
        global $conf;
        $mode = in_array($mode, array('cron', 'dry_run', 'manual'), true) ? $mode : 'cron';
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        // A nullable unique lock prevents overlapping cron deliveries. A lock
        // left behind by a hard process crash is expired after six hours.
        if ($mode === 'cron') {
            $stale = $this->db->idate(dol_now() - 21600);
            $now = $this->db->idate(dol_now());
            $sqlStale = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_run SET status = 'failed', finished_at = '".$this->db->escape($now)."', run_lock = NULL, summary = 'Expired stale automation lock after six hours.' WHERE entity = ".((int) $conf->entity)." AND status = 'running' AND run_lock = 'automatic' AND started_at < '".$this->db->escape($stale)."'";
            if (!$this->db->query($sqlStale)) { $this->error = $this->db->lasterror(); return false; }
        }
        $lockValue = $mode === 'cron' ? "'automatic'" : 'NULL';
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_run (entity, mode, status, run_lock, started_at, fk_user) VALUES ('.((int) $conf->entity).", '".$this->db->escape($mode)."', 'running', ".$lockValue.", '".$this->db->escape($this->db->idate(dol_now()))."', ".$uid.')';
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return false; }
        $id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'mahnwesen_run');
        if ($id <= 0) { $this->error = 'Unable to obtain the automation run id.'; return false; }
        return $id;
    }

    /** Finish one automation audit row with bounded counters. */
    public function finishAutomationRun($runId, $status, $counters, $summary)
    {
        global $conf;
        if ((int) $runId <= 0) { return false; }
        $status = in_array($status, array('success', 'warning', 'failed'), true) ? $status : 'failed';
        $summary = (string) $summary;
        if (strlen($summary) > 60000) {
            $summary = substr($summary, 0, 60000);
            while ($summary !== '' && !preg_match('//u', $summary)) { $summary = substr($summary, 0, -1); }
        }
        $keys = array('scanned', 'synchronized', 'attempted', 'sent', 'skipped', 'failed');
        $sql = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_run SET status = '".$this->db->escape($status)."', run_lock = NULL, finished_at = '".$this->db->escape($this->db->idate(dol_now()))."'";
        foreach ($keys as $key) { $sql .= ', '.$key.' = '.max(0, (int) (isset($counters[$key]) ? $counters[$key] : 0)); }
        $sql .= ", summary = '".$this->db->escape($summary)."' WHERE rowid = ".((int) $runId).' AND entity = '.((int) $conf->entity)." AND status = 'running'";
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return false; }
        $sqlCheck = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_run WHERE rowid = '.((int) $runId).' AND entity = '.((int) $conf->entity)." AND status = '".$this->db->escape($status)."' AND run_lock IS NULL".$this->db->plimit(1);
        $resCheck = $this->db->query($sqlCheck); $finished = $resCheck ? $this->db->fetch_object($resCheck) : false; if ($resCheck) { $this->db->free($resCheck); }
        if (!$finished) { $this->error = 'Automation run could not be finalized.'; return false; }
        return true;
    }

    /** Return recent cron/dry-run history for the active entity. */
    public function getAutomationRuns($limit = 100)
    {
        global $conf;
        $rows = array();
        $sql = 'SELECT rowid, mode, status, started_at, finished_at, scanned, synchronized, attempted, sent, skipped, failed, summary, fk_user FROM '.MAIN_DB_PREFIX.'mahnwesen_run WHERE entity = '.((int) $conf->entity).' ORDER BY started_at DESC, rowid DESC'.$this->db->plimit(max(1, min(500, (int) $limit)));
        $res = $this->db->query($sql); if (!$res) { $this->error = $this->db->lasterror(); return false; }
        while ($o = $this->db->fetch_object($res)) { $rows[] = (array) $o; }
        $this->db->free($res); return $rows;
    }

    /**
     * Daily workflow entry point. It always synchronizes module-owned case
     * state and releases dated pauses. Email sending only happens when the
     * global automatic-send switch AND the current stage's send_email flag are
     * enabled. The invoice itself is never modified.
     *
     * @return int 0 if OK, non-zero if a technical error occurred
     */
    public function doScheduledJob()
    {
        global $user, $langs, $conf;
        dol_syslog(__METHOD__.' start', LOG_INFO);
        $this->output = '';
        $this->error = '';
        $this->errors = array();

        $actor = (is_object($user) ? $user : new stdClass());
        if (!isset($actor->id)) {
            $actor->id = 0;
        }

        $counters = array('scanned' => 0, 'synchronized' => 0, 'attempted' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0);
        $runId = $this->beginAutomationRun('cron', $actor);
        if ($runId === false) {
            return 1;
        }

        if (!$this->ensureRuleRows($actor)) {
            $this->finishCronRun($runId, 'failed', $counters, 'Unable to initialize workflow rules: '.$this->error);
            return 1;
        }
        // Problems with single invoices or pauses end the run as a warning
        // naming them; the other invoices are still dunned (#15). Only a
        // failure of the whole step stops delivery.
        $warnings = array();
        $resumed = $this->resumeExpiredPauses($actor);
        if ($resumed === false) {
            $this->finishCronRun($runId, 'failed', $counters, 'Unable to resume expired pauses: '.$this->error);
            return 1;
        }
        // A pause that could not be lifted keeps its case paused, so it is not sent.
        $warnings = array_merge($warnings, $this->errors);
        $sync = $this->syncCases($actor, $this->getMaxScan());
        if ($sync === false) {
            $this->finishCronRun($runId, 'failed', $counters, 'Unable to synchronize cases: '.$this->error.($this->errors ? ' | '.implode(' | ', $this->errors) : ''));
            return 1;
        }
        $counters['synchronized'] = (int) $sync['created'] + (int) $sync['updated'] + (int) $sync['level_changed'] + (int) $sync['reopened'] + (int) $sync['closed'] + (int) $sync['unchanged'];
        $brokenInvoices = !empty($sync['failed_invoices']) ? $sync['failed_invoices'] : array();
        $warnings = array_merge($warnings, $this->errors);
        $this->errors = array();
        $warningText = $warnings ? ' Skipped because of errors: '.implode(' | ', array_unique($warnings)) : '';

        if (!$this->isAutomaticSendEnabled()) {
            $this->output = 'Daily Mahnwesen workflow: resumed '.$resumed.' dated pause(s); synchronized cases: created '.$sync['created'].', level '.$sync['level_changed'].', updated '.$sync['updated'].', closed '.$sync['closed'].'. Automatic email sending is OFF.'.$warningText;
            return ($this->finishCronRun($runId, $warnings ? 'warning' : 'success', $counters, $this->output) && !$warnings) ? 0 : 1;
        }

        require_once dol_buildpath('/mahnwesen/class/dunningnotice.class.php', 0);
        $service = new DunningNoticeService($this->db, $this);
        $rows = $this->scanDueInvoices($this->getMaxScan());
        if ($rows === false) {
            $this->finishCronRun($runId, 'failed', $counters, 'Unable to scan due invoices: '.$this->error);
            return 1;
        }
        $counters['scanned'] = count($rows);
        $sent = 0;
        $attempted = 0;
        $skipped = 0;
        $failed = 0;
        // The dry run takes the same decision for every invoice (#16).
        $budget = $this->newAutomaticBudget($brokenInvoices);
        $maxSend = $budget['max'];
        $maxRetry = $budget['retry_max'];
        $maxPerCustomer = $budget['max_per_customer'];
        foreach ($rows as $row) {
            $decision = $this->decideAutomaticSend($row, $service, $budget);
            if ($decision['detail'] === 'run_limit_reached') {
                break;
            }
            if ($decision['decision'] === 'skip') {
                $skipped++;
                continue;
            }
            if ($decision['decision'] === 'fail') {
                $failed++;
                $this->errors[] = 'Auto-send '.$row['invoice_ref'].': '.$decision['message'];
                continue;
            }
            if ($decision['decision'] !== 'send') {
                continue;
            }
            $invoice = $decision['invoice'];
            $case = $decision['case'];
            $level = (int) $decision['level'];
            $template = $decision['template'];
            $customerLang = $decision['lang'];
            $subject = $service->renderTemplate($template['subject'], $invoice, $case, $level, $customerLang);
            $body = $service->renderTemplate($template['body'], $invoice, $case, $level, $customerLang);
            // Only native templates exist; their "join files" flag decides.
            $attachInvoice = ((string) ($template['joinfiles'] ?? '') === '1');
            $attempted++;
            $result = $service->sendNotice($invoice, $case, $level, $decision['recipient'], $subject, $body, $attachInvoice, $actor, 'automatic', isset($template['email_from']) ? $template['email_from'] : '', isset($template['lang']) ? $template['lang'] : $customerLang, '', '', !empty($template['source_id']) ? (int) $template['source_id'] : 0);
            if ($result === false) {
                $failed++;
                $this->errors[] = 'Auto-send '.$invoice->ref.': '.$service->error;
            } else {
                $sent++;
            }
        }

        $counters['attempted'] = $attempted;
        $counters['sent'] = $sent;
        $counters['skipped'] = $skipped;
        $counters['failed'] = $failed;
        // The second scan repeats the invoices the synchronisation could not read.
        $warnings = array_unique(array_merge($warnings, array_filter($this->errors, function ($message) {
            return strpos($message, 'Auto-send ') !== 0;
        })));
        $failures = array_filter($this->errors, function ($message) {
            return strpos($message, 'Auto-send ') === 0;
        });
        $this->output = 'Daily Mahnwesen workflow: resumed '.$resumed.' pause(s); synchronized cases: created '.$sync['created'].', level '.$sync['level_changed'].', updated '.$sync['updated'].', closed '.$sync['closed'].'; automatic attempts '.$attempted.', sent '.$sent.', skipped '.$skipped.', failed '.$failed.' (attempt limit '.$maxSend.', retry limit '.$maxRetry.', per-customer limit '.$maxPerCustomer.'). Invoices were not modified.';
        if ($failures) { $this->output .= ' Failed: '.implode(' | ', $failures); }
        if ($warnings) { $this->output .= ' Skipped because of errors: '.implode(' | ', $warnings); }
        $problems = $failed || $warnings;
        $runFinalized = $this->finishCronRun($runId, $problems ? 'warning' : 'success', $counters, $this->output);
        dol_syslog(__METHOD__.' '.$this->output, $problems ? LOG_WARNING : LOG_INFO);
        return ($problems || !$runFinalized) ? 1 : 0;
    }

    /**
     * Limits of one automatic run and what it used so far.
     *
     * @param array $brokenInvoices Invoice id => reference of invoices the synchronisation could not handle
     * @return array
     */
    public function newAutomaticBudget($brokenInvoices = array())
    {
        return array(
            'max' => $this->getAutomaticSendMax(),
            'retry_max' => $this->getAutomaticRetryMax(),
            'max_per_customer' => $this->getAutomaticMaxPerCustomer(),
            'policy' => $this->getAutomaticRecipientPolicy(),
            'attempted' => 0,
            'per_customer' => array(),
            'broken' => (array) $brokenInvoices,
        );
    }

    /**
     * What the cron does with one scanned invoice (#16).
     *
     * The decision is send, wait (nothing due yet), off (the stage does not
     * send automatically), skip or fail. The cron counts skip as skipped and
     * fail as failed, and does not count wait and off. The dry run asks the
     * same question with $simulate, for the cases as the daily synchronisation
     * would leave them: a missing case is new, a case that is not open opens
     * again, a pause whose date has passed ends. Nothing is written. A send
     * decision takes its place in the budget.
     *
     * @param array $row Row of scanDueInvoices()
     * @param DunningNoticeService $service Notice service
     * @param array $budget From newAutomaticBudget(), updated
     * @param bool $simulate Dry run
     * @return array decision, detail, message, level, invoice, case, recipient, template, lang
     */
    public function decideAutomaticSend($row, $service, &$budget, $simulate = false)
    {
        global $langs;
        $result = array('decision' => 'wait', 'detail' => 'not_due', 'message' => '', 'level' => 0, 'invoice' => null,
            'case' => null, 'recipient' => '', 'template' => null, 'lang' => '');
        if ($budget['attempted'] >= $budget['max']) {
            return array_merge($result, array('decision' => 'skip', 'detail' => 'run_limit_reached'));
        }
        if ((int) $row['stage'] <= 0) {
            return $result;
        }
        if (isset($budget['broken'][(int) $row['invoice_id']])) {
            return array_merge($result, array('decision' => 'skip', 'detail' => 'invoice_broken'));
        }
        $workflow = $this->getWorkflowState((int) $row['invoice_id'], $simulate);
        $case = $workflow ? $workflow['case'] : false;
        $result['case'] = $case;
        if (!$case || $case['status'] !== 'open') {
            return array_merge($result, array('decision' => 'skip', 'detail' => $case ? 'case_'.$case['status'] : 'case_missing'));
        }
        if (!empty($case['paused'])) {
            return array_merge($result, array('decision' => 'skip', 'detail' => 'paused'));
        }
        if (!empty($workflow['block'])) {
            return array_merge($result, array('decision' => 'skip', 'detail' => 'blocked_'.$workflow['block']['scope']));
        }
        $level = (int) $workflow['next_required_level'];
        $result['level'] = $level;
        if (empty($workflow['actionable']) || $level <= 0) {
            return array_merge($result, array('decision' => 'skip', 'detail' => 'not_due'));
        }
        $rule = $this->getRuleByLevel($level);
        if (empty($rule['send_email'])) {
            return array_merge($result, array('decision' => 'off', 'detail' => 'stage_auto_disabled'));
        }
        $caseId = (int) $case['id'];
        if ($caseId > 0 && $this->hasSuccessfulNoticeAtLevel($caseId, $level)) {
            return array_merge($result, array('decision' => 'skip', 'detail' => 'already_sent'));
        }
        if ($caseId > 0 && $this->hasPendingNoticeAtLevel($caseId, $level)) {
            return array_merge($result, array('decision' => 'skip', 'detail' => 'attempt_pending'));
        }
        if ($caseId > 0 && $this->getAutomaticFailureCount($caseId, $level) >= $budget['retry_max']) {
            return array_merge($result, array('decision' => 'skip', 'detail' => 'retry_limit_reached'));
        }
        $invoice = new Facture($this->db);
        if ($invoice->fetch((int) $row['invoice_id']) <= 0) {
            return array_merge($result, array('decision' => 'fail', 'detail' => 'invoice_load_failed', 'message' => 'unable to load invoice'));
        }
        $invoice->fetch_thirdparty();
        $result['invoice'] = $invoice;
        $customerId = (int) $invoice->socid;
        if ($customerId > 0 && !empty($budget['per_customer'][$customerId]) && $budget['per_customer'][$customerId] >= $budget['max_per_customer']) {
            return array_merge($result, array('decision' => 'skip', 'detail' => 'customer_limit_reached'));
        }
        $recipient = $service->getAutomaticRecipientOption($invoice, $budget['policy']);
        if ($recipient === false) {
            return array_merge($result, array('decision' => 'skip', 'detail' => $service->recipientLookupFailed ? 'recipient_lookup_failed' : 'recipient_ambiguous'));
        }
        $lang = (!empty($invoice->thirdparty) && !empty($invoice->thirdparty->default_lang)) ? (string) $invoice->thirdparty->default_lang : (is_object($langs) ? $langs->defaultlang : 'de_DE');
        // Public templates only: the result must not depend on who runs the cron or the dry run.
        $template = $service->getTemplate($level, $lang, null);
        if ($template === false) {
            return array_merge($result, array('decision' => 'fail', 'detail' => 'template_missing', 'message' => $service->error));
        }
        if ($service->getFromEmail((string) ($template['email_from'] ?? '')) === '') {
            return array_merge($result, array('decision' => 'fail', 'detail' => 'sender_missing', 'message' => 'no sender address is configured'));
        }
        if ((string) ($template['joinfiles'] ?? '') === '1' && $service->getInvoicePdfPath($invoice) === '') {
            return array_merge($result, array('decision' => 'fail', 'detail' => 'invoice_pdf_missing', 'message' => 'the template attaches the invoice PDF, which does not exist'));
        }
        $budget['attempted']++;
        if ($customerId > 0) {
            $budget['per_customer'][$customerId] = (isset($budget['per_customer'][$customerId]) ? $budget['per_customer'][$customerId] : 0) + 1;
        }
        return array_merge($result, array('decision' => 'send', 'detail' => 'ready', 'recipient' => (string) $recipient['email'],
            'template' => $template, 'lang' => $lang));
    }

    /**
     * Allow every failed automatic attempt of one cron run to be sent again (#21).
     *
     * Only failed attempts: nothing of them reached the mail server. Ambiguous
     * attempts may have been delivered and are still resolved one by one.
     *
     * @param int $runId Automation run
     * @param string $reason Reason recorded with each attempt
     * @param User $user Acting user
     * @return int|false Number of attempts released
     */
    public function releaseFailedAttemptsOfRun($runId, $reason, $user)
    {
        global $conf;
        if (trim((string) $reason) === '') {
            $this->error = 'A reason is required.';
            return false;
        }
        $sql = 'SELECT started_at, finished_at, mode FROM '.MAIN_DB_PREFIX.'mahnwesen_run WHERE rowid = '.((int) $runId).' AND entity = '.((int) $conf->entity);
        $res = $this->db->query($sql);
        $run = $res ? $this->db->fetch_object($res) : false;
        if ($res) {
            $this->db->free($res);
        }
        if (!$run || $run->mode !== 'cron') {
            $this->error = 'Automatic run not found.';
            return false;
        }
        $until = !empty($run->finished_at) ? (string) $run->finished_at : $this->db->idate(dol_now());
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE entity = '.((int) $conf->entity)." AND mode = 'automatic' AND status = 'failed'";
        $sql .= " AND reserved_at >= '".$this->db->escape((string) $run->started_at)."' AND reserved_at <= '".$this->db->escape($until)."' ORDER BY rowid";
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $ids = array();
        while ($o = $this->db->fetch_object($res)) {
            $ids[] = (int) $o->rowid;
        }
        $this->db->free($res);
        $released = 0;
        foreach ($ids as $attemptId) {
            if (!$this->resolveNoticeAttempt($attemptId, 'allow_retry', $reason, $user)) {
                $this->errors[] = 'Attempt #'.$attemptId.': '.$this->error;
                continue;
            }
            $released++;
        }
        return $released;
    }

    /**
     * Whether Dolibarr's own reminder on the invoice due date is switched on (#21).
     *
     * Its emails and the module's notices would both reach the customer.
     *
     * @return bool
     */
    public function isCoreReminderJobActive()
    {
        global $conf;
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX."cronjob WHERE methodename = 'sendEmailsRemindersOnInvoiceDueDate' AND status = 1";
        $sql .= ' AND entity IN (0, '.((int) $conf->entity).')'.$this->db->plimit(1);
        $res = $this->db->query($sql);
        if (!$res) {
            return false;
        }
        $found = (bool) $this->db->fetch_object($res);
        $this->db->free($res);
        return $found;
    }

    /**
     * Finish a cron run and tell the operator when it did not end cleanly (#21).
     *
     * @param int $runId Automation run
     * @param string $status success, warning or failed
     * @param array $counters Run counters
     * @param string $summary Summary
     * @return bool Whether the run row was finished
     */
    protected function finishCronRun($runId, $status, $counters, $summary)
    {
        $finished = $this->finishAutomationRun($runId, $status, $counters, $summary);
        if ($status !== 'success') {
            $this->notifyRunProblem((int) $runId, $status, $summary);
        }
        return $finished;
    }

    /**
     * Email the configured address about a failed run or one with warnings.
     *
     * A failed notification is logged and never changes the run.
     *
     * @param int $runId Automation run
     * @param string $status warning or failed
     * @param string $summary Summary of the run
     * @return bool Whether an email was handed to the mail server
     */
    protected function notifyRunProblem($runId, $status, $summary)
    {
        global $langs;
        $to = trim(getDolGlobalString('MAHNWESEN_RUN_NOTIFY_EMAIL'));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $from = getDolGlobalString('MAHNWESEN_FROM_EMAIL') ?: getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
        if ($from === '') {
            dol_syslog(__METHOD__.' no sender address, run notification not sent', LOG_WARNING);
            return false;
        }
        if (is_object($langs)) {
            $langs->load('mahnwesen@mahnwesen');
        }
        require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
        $subject = $langs->transnoentities($status === 'failed' ? 'MahnwesenRunNotifySubjectFailed' : 'MahnwesenRunNotifySubjectWarning', $runId);
        $body = $langs->transnoentities('MahnwesenRunNotifyBody', $runId, $summary, dol_buildpath('/mahnwesen/attempts.php', 2));
        try {
            $mail = new CMailFile($subject, $to, $from, $body, array(), array(), array(), '', '', 0, 0, '', '', 'mahnwesen-run'.$runId, '', 'standard');
            if ($mail->sendfile()) {
                return true;
            }
            dol_syslog(__METHOD__.' run notification failed: '.$mail->error, LOG_WARNING);
        } catch (Throwable $e) {
            dol_syslog(__METHOD__.' run notification failed: '.$e->getMessage(), LOG_WARNING);
        }
        return false;
    }
}
