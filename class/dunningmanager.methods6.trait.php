<?php
/* Automatic sending: one decision for the cron and the dry run, and aids for operators. */
trait DunningManagerMethods6
{
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
     * Whether the daily run would lift the pause of a case now.
     *
     * The same rule as resumeExpiredPauses(): an active pause whose date has
     * passed, or, for pauses from before the pause table, the case's own date.
     *
     * @param int $caseId Case
     * @return bool
     */
    public function isPauseExpired($caseId)
    {
        global $conf;
        $nowSql = $this->db->escape($this->db->idate(dol_now()));
        $sql = 'SELECT c.rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_case c WHERE c.rowid = '.((int) $caseId).' AND c.entity = '.((int) $conf->entity).' AND c.paused = 1 AND (';
        $sql .= 'EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX."mahnwesen_pause p WHERE p.fk_case = c.rowid AND p.entity = c.entity AND p.status = 'active' AND p.pause_until IS NOT NULL AND p.pause_until <= '".$nowSql."')";
        $sql .= " OR (c.next_action_at IS NOT NULL AND c.next_action_at <= '".$nowSql."' AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."mahnwesen_pause p2 WHERE p2.fk_case = c.rowid AND p2.entity = c.entity AND p2.status = 'active')))";
        $res = $this->db->query($sql);
        if (!$res) {
            return false;
        }
        $found = (bool) $this->db->fetch_object($res);
        $this->db->free($res);
        return $found;
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
