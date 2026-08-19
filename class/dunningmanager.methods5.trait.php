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
    public function saveRule($level, $days, $feeAmount, $sendEmail, $templateRef, $user)
    {
        global $conf;
        $level = max(1, min(4, (int) $level));
        $days = max(0, (int) $days);
        $feeAmount = max(0.0, (float) $feeAmount);
        $sendEmail = $sendEmail ? 1 : 0;
        $templateRef = trim((string) $templateRef);
        if ($templateRef === '') {
            $templateRef = 'internal';
        }
        if (!$this->ensureRuleRows($user)) {
            return false;
        }
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_rule SET days_after_due = '.$days.', fee_amount = '.((float) $feeAmount).', send_email = '.$sendEmail;
        $sql .= ", email_template = '".$this->db->escape($templateRef)."', fk_user_modif = ".$uid;
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
            return $this->getPrivateFeeForLevel($level);
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

    /**
     * Resume pauses whose next_action_at date has arrived. Indefinite pauses
     * have next_action_at=NULL and are never resumed automatically.
     *
     * @return int|false Number resumed
     */
    public function resumeExpiredPauses($user)
    {
        global $conf;
        $nowSql = $this->db->idate(dol_now());
        $ids = array();
        $sql = 'SELECT fk_facture FROM '.MAIN_DB_PREFIX.'mahnwesen_case WHERE entity = '.((int) $conf->entity)." AND status = 'open' AND paused = 1 AND next_action_at IS NOT NULL AND next_action_at <= '".$this->db->escape($nowSql)."' ORDER BY next_action_at ASC";
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

        if (!$this->ensureRuleRows($actor)) {
            return 1;
        }
        $resumed = $this->resumeExpiredPauses($actor);
        if ($resumed === false) {
            return 1;
        }
        $sync = $this->syncCases($actor, $this->getMaxScan());
        if ($sync === false) {
            return 1;
        }

        if (!$this->isAutomaticSendEnabled()) {
            $this->output = 'Daily Mahnwesen workflow: resumed '.$resumed.' dated pause(s); synchronized cases: created '.$sync['created'].', level '.$sync['level_changed'].', updated '.$sync['updated'].', closed '.$sync['closed'].'. Automatic email sending is OFF.';
            return 0;
        }

        require_once dol_buildpath('/mahnwesen/class/dunningnotice.class.php', 0);
        $service = new DunningNoticeService($this->db, $this);
        $rows = $this->scanDueInvoices($this->getMaxScan());
        if ($rows === false) {
            return 1;
        }
        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $maxSend = $this->getAutomaticSendMax();
        foreach ($rows as $row) {
            if ($sent >= $maxSend) {
                break;
            }
            $calculatedLevel = (int) $row['stage'];
            if ($calculatedLevel <= 0) { continue; }
            $case = $this->getCaseByInvoice((int) $row['invoice_id']);
            if (!$case || $case['status'] === 'closed' || !empty($case['paused'])) {
                $skipped++;
                continue;
            }
            $workflow = $this->getWorkflowState((int) $row['invoice_id']);
            $level = $workflow ? (int) $workflow['next_required_level'] : 0;
            if (!$workflow || empty($workflow['actionable']) || $level <= 0) {
                $skipped++;
                continue;
            }
            $rule = $this->getRuleByLevel($level);
            if (empty($rule['send_email'])) { continue; }
            if ($this->hasSuccessfulNoticeAtLevel((int) $case['id'], $level) || $this->hasPendingNoticeAtLevel((int) $case['id'], $level) || $this->hasFailedNoticeAtLevel((int) $case['id'], $level)) {
                $skipped++;
                continue;
            }
            $invoice = new Facture($this->db);
            if ($invoice->fetch((int) $row['invoice_id']) <= 0) {
                $failed++;
                $this->errors[] = 'Auto-send invoice #'.$row['invoice_id'].': unable to load invoice';
                continue;
            }
            $invoice->fetch_thirdparty();
            $recipient = $service->getAutomaticRecipient($invoice, $this->getAutomaticRecipientPolicy());
            if ($recipient === '') {
                $skipped++;
                continue;
            }
            $customerLang = (!empty($invoice->thirdparty) && !empty($invoice->thirdparty->default_lang)) ? (string) $invoice->thirdparty->default_lang : (is_object($langs) ? $langs->defaultlang : 'de_DE');
            $template = $service->getTemplate($level, $customerLang, $actor);
            if ($template === false) {
                $failed++;
                $this->errors[] = 'Auto-send '.$invoice->ref.': '.$service->error;
                continue;
            }
            $subject = $service->renderTemplate($template['subject'], $invoice, $case, $level, $customerLang);
            $body = $service->renderTemplate($template['body'], $invoice, $case, $level, $customerLang);
            $attachInvoice = ($template['source'] === 'native')
                ? ((string) ($template['joinfiles'] ?? '') === '1')
                : (getDolGlobalInt('MAHNWESEN_ATTACH_INVOICE_DEFAULT', 1) > 0);
            $result = $service->sendNotice($invoice, $case, $level, $recipient, $subject, $body, $attachInvoice, $actor, 'automatic', isset($template['email_from']) ? $template['email_from'] : '', isset($template['lang']) ? $template['lang'] : $customerLang);
            if ($result === false) {
                $failed++;
                $this->errors[] = 'Auto-send '.$invoice->ref.': '.$service->error;
            } else {
                $sent++;
            }
        }

        $this->output = 'Daily Mahnwesen workflow: resumed '.$resumed.' pause(s); synchronized cases: created '.$sync['created'].', level '.$sync['level_changed'].', updated '.$sync['updated'].', closed '.$sync['closed'].'; automatic sends '.$sent.', skipped '.$skipped.', failed '.$failed.' (limit '.$maxSend.'). Invoices were not modified.';
        dol_syslog(__METHOD__.' '.$this->output, $failed ? LOG_WARNING : LOG_INFO);
        return $failed ? 1 : 0;
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
