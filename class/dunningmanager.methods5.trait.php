<?php
/* Auto-split method trait for maintainable source files. */
trait DunningManagerMethods5
{

    /** @return array<int,array> */
    public function getRules($refresh = false)
    {
        global $conf;
        if (!$refresh && is_array($this->rulesCache)) {
            return $this->rulesCache;
        }
        $rules = array();
        $sql = 'SELECT rowid, entity, code, label, level, days_after_due, minimum_amount, fee_amount, interest_rate, send_email, generate_pdf, email_template, enabled';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'mahnwesen_rule WHERE entity = '.((int) $conf->entity).' AND level BETWEEN 1 AND 4 ORDER BY level ASC';
        $res = $this->db->query($sql);
        if ($res) {
            while ($o = $this->db->fetch_object($res)) {
                $rules[(int) $o->level] = array(
                    'id' => (int) $o->rowid,
                    'entity' => (int) $o->entity,
                    'code' => (string) $o->code,
                    'label' => (string) $o->label,
                    'level' => (int) $o->level,
                    'days_after_due' => (int) $o->days_after_due,
                    'minimum_amount' => (float) $o->minimum_amount,
                    'fee_amount' => (float) $o->fee_amount,
                    'interest_rate' => (float) $o->interest_rate,
                    'send_email' => (int) $o->send_email,
                    'generate_pdf' => (int) $o->generate_pdf,
                    'email_template' => $o->email_template !== null && $o->email_template !== '' ? (string) $o->email_template : 'internal',
                    'enabled' => (int) $o->enabled,
                );
            }
            $this->db->free($res);
        }
        for ($level = 1; $level <= 4; $level++) {
            if (!isset($rules[$level])) {
                $rules[$level] = $this->getDefaultRule($level);
            }
        }
        ksort($rules);
        $this->rulesCache = $rules;
        return $rules;
    }

    /** @return array */
    public function getRuleByLevel($level)
    {
        $rules = $this->getRules();
        $level = max(1, min(4, (int) $level));
        return $rules[$level];
    }

    /** Save a complete stage rule into our own table and keep old constants in sync. */
    public function saveRule($level, $days, $feeAmount, $sendEmail, $templateRef, $user, $enabled = 1)
    {
        global $conf;
        $level = max(1, min(4, (int) $level));
        $days = max(0, (int) $days);
        $feeAmount = max(0.0, (float) $feeAmount);
        $sendEmail = $sendEmail ? 1 : 0;
        $enabled = $enabled ? 1 : 0;
        $templateRef = trim((string) $templateRef);
        if ($templateRef === '') {
            $templateRef = 'internal';
        }
        if (!$this->ensureRuleRows($user)) {
            return false;
        }
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_rule SET days_after_due = '.$days.', fee_amount = '.((float) $feeAmount).', send_email = '.$sendEmail;
        $sql .= ", email_template = '".$this->db->escape($templateRef)."', enabled = ".$enabled.', fk_user_modif = '.$uid;
        $sql .= ' WHERE entity = '.((int) $conf->entity).' AND level = '.$level;
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            return false;
        }
        if (dolibarr_set_const($this->db, 'MAHNWESEN_STAGE'.$level.'_DAYS', (string) $days, 'chaine', 0, '', $conf->entity) <= 0) {
            $this->error = 'Unable to persist stage threshold constant';
            return false;
        }
        $this->rulesCache = null;
        return true;
    }

    /** Remember which email template a stage uses: native:auto or native:<id>. */
    public function saveRuleTemplate($level, $templateRef, $user)
    {
        global $conf;
        $level = max(1, min(4, (int) $level));
        if (!preg_match('/^native:(auto|\d+)$/', (string) $templateRef)) {
            $this->error = 'Invalid template reference';
            return false;
        }
        if (!$this->ensureRuleRows($user)) {
            return false;
        }
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $sql = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_rule SET email_template = '".$this->db->escape((string) $templateRef)."', fk_user_modif = ".$uid;
        $sql .= ' WHERE entity = '.((int) $conf->entity).' AND level = '.$level;
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $this->rulesCache = null;
        return true;
    }

    /**
     * Classify a Dolibarr third party for SAFE fee automation. This is a
     * master-data heuristic, not a legal determination of the transaction.
     *
     * @return array{class:string,code:string,label:string}
     */
    public function classifyThirdparty($thirdparty)
    {
        if (!is_object($thirdparty) || empty($thirdparty->id)) {
            return array('class' => 'unknown', 'code' => '', 'label' => '');
        }
        $sql = 'SELECT t.code, t.libelle FROM '.MAIN_DB_PREFIX.'societe s LEFT JOIN '.MAIN_DB_PREFIX.'c_typent t ON t.id = s.fk_typent WHERE s.rowid = '.((int) $thirdparty->id);
        $res = $this->db->query($sql);
        if (!$res) {
            return array('class' => 'unknown', 'code' => '', 'label' => '');
        }
        $o = $this->db->fetch_object($res);
        $this->db->free($res);
        $code = $o ? (string) $o->code : '';
        $label = $o ? (string) $o->libelle : '';
        if ($code === 'TE_PRIVATE') {
            return array('class' => 'consumer', 'code' => $code, 'label' => $label);
        }
        if (in_array($code, array('TE_STARTUP', 'TE_GROUP', 'TE_MEDIUM', 'TE_SMALL', 'TE_WHOLE', 'TE_RETAIL'), true)) {
            return array('class' => 'business', 'code' => $code, 'label' => $label);
        }
        if ($code === 'TE_ADMIN') {
            return array('class' => 'special', 'code' => $code, 'label' => $label);
        }
        return array('class' => 'unknown', 'code' => $code, 'label' => $label);
    }

    /** @return string Translation key */
    public function getCustomerClassLabelKey($class)
    {
        switch ((string) $class) {
            case 'business': return 'CustomerClassBusiness';
            case 'consumer': return 'CustomerClassConsumer';
            case 'special': return 'CustomerClassSpecial';
            default: return 'CustomerClassUnknown';
        }
    }

    /** Return configured business/B2B fee for a stage. */
    public function getBusinessFeeForLevel($level)
    {
        $rule = $this->getRuleByLevel($level);
        return max(0.0, (float) $rule['fee_amount']);
    }

    /** Return configured private-person/consumer fee for a stage. */
    public function getPrivateFeeForLevel($level)
    {
        $level = max(1, min(4, (int) $level));
        $value = getDolGlobalString('MAHNWESEN_PRIVATE_FEE_'.$level);
        if ($value === '') {
            return 0.0;
        }
        return max(0.0, (float) price2num($value));
    }

    /** Payment period in days that a notice of this stage grants, 0 for none (#64). */
    public function getPaymentDaysForLevel($level)
    {
        $level = max(1, min(4, (int) $level));
        return max(0, min(365, getDolGlobalInt('MAHNWESEN_PAYMENT_DAYS_'.$level, 0)));
    }

    /** Payment deadline of a notice of this stage written at $from (default now), null without a period. */
    public function getPaymentDeadline($level, $from = null)
    {
        $days = $this->getPaymentDaysForLevel($level);
        if ($days <= 0) {
            return null;
        }
        return (int) strtotime('+'.$days.' days', $from === null ? dol_now() : (int) $from);
    }

    /**
     * Return the fee actually applied for a stage/customer.
     *
     * Business and private-person amounts are configured independently. The
     * third-party master-data classification selects the matching column.
     * Unknown/special customer types remain fee-free unless the explicit
     * compatibility switch MAHNWESEN_UNKNOWN_FEES_ALLOWED is enabled, in
     * which case the more conservative private-person amount is used.
     */
    public function getFeeForLevel($level, $thirdparty)
    {
        $classification = $this->classifyThirdparty($thirdparty);
        if ($classification['class'] === 'business') {
            return $this->getBusinessFeeForLevel($level);
        }
        if ($classification['class'] === 'consumer') {
            return getDolGlobalInt('MAHNWESEN_PRIVATE_FEES_ALLOWED', 0) ? $this->getPrivateFeeForLevel($level) : 0.0;
        }
        if (($classification['class'] === 'unknown' || $classification['class'] === 'special') && getDolGlobalInt('MAHNWESEN_UNKNOWN_FEES_ALLOWED')) {
            return $this->getPrivateFeeForLevel($level);
        }
        return 0.0;
    }

    /** @return array{invoice:float,fee:float,total:float,classification:array} */
    public function getAmountBreakdown($invoice, $case, $level)
    {
        if (empty($invoice->thirdparty)) {
            $invoice->fetch_thirdparty();
        }
        $base = isset($case['remaining_amount']) ? max(0.0, (float) $case['remaining_amount']) : 0.0;
        $fee = $this->getFeeForLevel($level, $invoice->thirdparty);
        return array(
            'invoice' => $base,
            'fee' => $fee,
            'total' => $base + $fee,
            'classification' => $this->classifyThirdparty($invoice->thirdparty),
        );
    }

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

    /** Return true when an automatic attempt already failed at this stage. */
    public function hasFailedNoticeAtLevel($caseId, $level)
    {
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_history WHERE fk_case = '.((int) $caseId).' AND level = '.((int) $level)." AND action = 'notice_failed' AND result = 'failed' AND mode = 'automatic'".$this->db->plimit(1);
        $res = $this->db->query($sql);
        if (!$res) {
            return false;
        }
        $found = (bool) $this->db->fetch_object($res);
        $this->db->free($res);
        return $found;
    }

    /** Count unresolved automatic failures so transient faults can retry safely. */
    public function getAutomaticFailureCount($caseId, $level)
    {
        $sql = 'SELECT COUNT(*) as cnt FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE fk_case = '.((int) $caseId).' AND level = '.((int) $level)." AND mode = 'automatic' AND status = 'failed'";
        $res = $this->db->query($sql);
        if (!$res) { return PHP_INT_MAX; }
        $o = $this->db->fetch_object($res); $this->db->free($res);
        return $o ? (int) $o->cnt : 0;
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
     * Resume pauses whose separate pause_until date has arrived. Indefinite
     * pauses have pause_until=NULL and are never resumed automatically.
     *
     * @return int|false Number resumed
     */
    public function resumeExpiredPauses($user)
    {
        global $conf;
        $nowSql = $this->db->idate(dol_now());
        $ids = array();
        $sql = 'SELECT c.fk_facture, p.pause_until as resume_at FROM '.MAIN_DB_PREFIX.'mahnwesen_case c INNER JOIN '.MAIN_DB_PREFIX.'mahnwesen_pause p ON p.fk_case = c.rowid AND p.entity = c.entity AND p.status = \'active\'';
        $sql .= ' WHERE c.entity = '.((int) $conf->entity)." AND c.status = 'open' AND c.paused = 1 AND p.pause_until IS NOT NULL AND p.pause_until <= '".$this->db->escape($nowSql)."'";
        $sql .= ' UNION SELECT c.fk_facture, c.next_action_at as resume_at FROM '.MAIN_DB_PREFIX.'mahnwesen_case c WHERE c.entity = '.((int) $conf->entity)." AND c.status = 'open' AND c.paused = 1 AND c.next_action_at IS NOT NULL AND c.next_action_at <= '".$this->db->escape($nowSql)."' AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."mahnwesen_pause p2 WHERE p2.entity = c.entity AND p2.fk_case = c.rowid AND p2.status = 'active') ORDER BY resume_at ASC";
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            return false;
        }
        while ($o = $this->db->fetch_object($res)) {
            $ids[] = (int) $o->fk_facture;
        }
        $this->db->free($res);
        $count = 0;
        foreach ($ids as $invoiceId) {
            if ($this->setPaused($invoiceId, false, $user, 'Automatisch fortgesetzt: Pausenende erreicht', '', 'automatic')) {
                $count++;
            } else {
                $this->errors[] = 'Auto-resume invoice #'.$invoiceId.': '.$this->error;
            }
        }
        return $count;
    }

    public function calculateNextActionAt($dueYmd, $stage)
    {
        $thresholds = $this->getStageThresholds();
        $nextLevel = ((int) $stage) + 1;
        if (!isset($thresholds[$nextLevel]) || empty($dueYmd)) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($dueYmd.' 00:00:00');
            $date = $date->modify('+'.((int) $thresholds[$nextLevel]).' days');
            return $date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
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
     * @param int $stage Stage
     * @return string Translation key
     */
    public function getStageLabelKey($stage)
    {
        switch ((int) $stage) {
            case 1:
                return 'DunningStage1';
            case 2:
                return 'DunningStage2';
            case 3:
                return 'DunningStage3';
            case 4:
                return 'DunningStage4';
            default:
                return 'DunningStageNone';
        }
    }

    /**
     * Date-only difference, robust around DST changes.
     *
     * @param string $from YYYY-MM-DD
     * @param string $to YYYY-MM-DD
     * @return int
     */
    protected function daysBetween($from, $to)
    {
        try {
            $a = new DateTimeImmutable($from.' 12:00:00');
            $b = new DateTimeImmutable($to.' 12:00:00');
            return max(0, (int) $a->diff($b)->days);
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * @param string $key Constant name
     * @param int $default Default value
     * @return int
     */
    protected function getIntSetting($key, $default)
    {
        $value = getDolGlobalString($key);
        if ($value === '') {
            return (int) $default;
        }
        return (int) $value;
    }

}
