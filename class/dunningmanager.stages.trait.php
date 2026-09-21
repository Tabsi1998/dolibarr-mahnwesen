<?php
/* The stage table: days, fees, payment period, automatic sending, template per stage, and the amounts a notice asks for. */
trait DunningManagerStages
{
    /** @return array<int,array> */
    public function getRules($refresh = false)
    {
        global $conf;
        if (!$refresh && is_array($this->rulesCache)) {
            return $this->rulesCache;
        }
        $rules = array();
        $sql = 'SELECT rowid, entity, code, label, level, days_after_due, fee_amount, fee_private, payment_days, interest_rate, send_email, email_template, enabled';
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
                    'fee_amount' => (float) $o->fee_amount,
                    'fee_private' => (float) $o->fee_private,
                    'payment_days' => (int) $o->payment_days,
                    'interest_rate' => (float) $o->interest_rate,
                    'send_email' => (int) $o->send_email,
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

    /** Save all settings of a stage; the stage table is their only place (#20). */
    public function saveRule($level, $days, $feeAmount, $sendEmail, $templateRef, $user, $enabled = 1, $feePrivate = 0.0, $paymentDays = 0)
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
        $sql .= ', fee_private = '.max(0.0, (float) $feePrivate).', payment_days = '.max(0, min(365, (int) $paymentDays));
        $sql .= ", email_template = '".$this->db->escape($templateRef)."', enabled = ".$enabled.', fk_user_modif = '.$uid;
        $sql .= ' WHERE entity = '.((int) $conf->entity).' AND level = '.$level;
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
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
     * Calculate date/time when the next higher threshold is reached.
     *
     * @param string $dueYmd Due date YYYY-MM-DD
     * @param int $stage Current calculated/stored stage
     * @return string|null SQL datetime
     */
    /**
     * Return default rule values. fee_amount is the TOTAL dunning-fee amount
     * for that stage, not an amount added again on every stage transition.
     *
     * The jurisdiction-neutral safe default is zero for every stage. Whether a
     * configured fee is applied depends on explicit configuration and the
     * third-party classification below.
     *
     * @param int $level Stage 1..4
     * @return array
     */
    public function getDefaultRule($level)
    {
        $level = max(1, min(4, (int) $level));
        $days = array(1 => 3, 2 => 10, 3 => 20, 4 => 30);
        // Legal/accounting treatment differs by jurisdiction and contract.
        // No monetary fee is enabled by default.
        $fees = array(1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0);
        return array(
            'id' => 0,
            'entity' => 0,
            'code' => 'STAGE'.$level,
            'label' => $this->getStageLabelKey($level),
            'level' => $level,
            'days_after_due' => $days[$level],
            'fee_amount' => $fees[$level],
            'fee_private' => 0.0,
            'payment_days' => 0,
            'interest_rate' => 0.0,
            'send_email' => 0,
            'email_template' => 'internal',
            'enabled' => 1,
        );
    }

    /** Ensure four stage rows exist in the module's own rule table. */
    public function ensureRuleRows($user = null)
    {
        global $conf;
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        for ($level = 1; $level <= 4; $level++) {
            $code = 'STAGE'.$level;
            $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_rule WHERE entity = '.((int) $conf->entity)." AND code = '".$this->db->escape($code)."'".$this->db->plimit(1);
            $res = $this->db->query($sql);
            if (!$res) {
                $this->error = $this->db->lasterror();
                return false;
            }
            $exists = $this->db->fetch_object($res);
            $this->db->free($res);
            if ($exists) {
                continue;
            }
            $d = $this->getDefaultRule($level);
            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_rule (entity, code, label, level, days_after_due, fee_amount, fee_private, payment_days, interest_rate, send_email, email_template, enabled, date_creation, fk_user_create, fk_user_modif) VALUES (';
            $sql .= ((int) $conf->entity).", '".$this->db->escape($code)."', '".$this->db->escape($d['label'])."', ".$level.', '.((int) $d['days_after_due']).', '.((float) $d['fee_amount']).", 0, 0, 0, 0, 'internal', 1, '".$this->db->escape($this->db->idate(dol_now()))."', ".$uid.', '.$uid.')';
            if (!$this->db->query($sql)) {
                $this->error = $this->db->lasterror();
                return false;
            }
        }
        $this->rulesCache = null;
        return true;
    }

    /**
     * Move the stage settings that earlier versions kept as constants into the
     * stage table, then remove the constants (#20).
     *
     * Days lived in MAHNWESEN_STAGE<n>_DAYS as well as in the table, private
     * fees only in MAHNWESEN_PRIVATE_FEE_<n>, payment periods (1.0.5) only in
     * MAHNWESEN_PAYMENT_DAYS_<n>. The table wins for the days; the other two
     * move over. Runs on every activation; without constants it does nothing.
     *
     * @param User|null $user Acting user
     * @return bool
     */
    public function migrateStageSettings($user = null)
    {
        global $conf;
        $hadRows = count($this->getStoredRuleLevels()) === 4;
        if (!$this->ensureRuleRows($user)) {
            return false;
        }
        for ($level = 1; $level <= 4; $level++) {
            $set = array();
            $days = getDolGlobalString('MAHNWESEN_STAGE'.$level.'_DAYS');
            // Only for rows created just now: before the table, the constants were the source.
            if (!$hadRows && $days !== '' && is_numeric($days)) {
                $set[] = 'days_after_due = '.max(0, (int) $days);
            }
            $private = getDolGlobalString('MAHNWESEN_PRIVATE_FEE_'.$level);
            if ($private !== '') {
                $set[] = 'fee_private = '.max(0.0, (float) price2num($private));
            }
            $payment = getDolGlobalString('MAHNWESEN_PAYMENT_DAYS_'.$level);
            if ($payment !== '') {
                $set[] = 'payment_days = '.max(0, min(365, (int) $payment));
            }
            if ($set) {
                $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_rule SET '.implode(', ', $set).' WHERE entity = '.((int) $conf->entity).' AND level = '.$level;
                if (!$this->db->query($sql)) {
                    $this->error = $this->db->lasterror();
                    return false;
                }
            }
            foreach (array('MAHNWESEN_STAGE'.$level.'_DAYS', 'MAHNWESEN_PRIVATE_FEE_'.$level, 'MAHNWESEN_PAYMENT_DAYS_'.$level) as $name) {
                if (getDolGlobalString($name) !== '' || isset($conf->global->$name)) {
                    dolibarr_del_const($this->db, $name, $conf->entity);
                }
            }
        }
        $this->rulesCache = null;
        return true;
    }

    /** Levels that have a row in the stage table of the active entity. */
    protected function getStoredRuleLevels()
    {
        global $conf;
        $levels = array();
        $res = $this->db->query('SELECT level FROM '.MAIN_DB_PREFIX.'mahnwesen_rule WHERE entity = '.((int) $conf->entity).' AND level BETWEEN 1 AND 4');
        while ($res && ($o = $this->db->fetch_object($res))) {
            $levels[(int) $o->level] = true;
        }
        if ($res) {
            $this->db->free($res);
        }
        return $levels;
    }

    /**
     * @return array<int,int>
     */
    /** @return array<int,bool> Level => whether the stage is switched on */
    public function getEnabledLevels()
    {
        $enabled = array();
        foreach ($this->getRules() as $level => $rule) {
            $enabled[(int) $level] = !empty($rule['enabled']);
        }
        return $enabled;
    }

    /** @return array<int,int> Level => days after the due date */
    public function getStageThresholds()
    {
        // getRules() always returns the four stages, stored or default (#20).
        $out = array();
        foreach ($this->getRules() as $level => $rule) {
            $out[(int) $level] = (int) $rule['days_after_due'];
        }
        ksort($out);
        return $out;
    }

    /**
     * Determine dunning stage from days after due date.
     *
     * @param int $daysLate Days after due date
     * @return int 0..4
     */
    public function determineStage($daysLate)
    {
        return MahnwesenWorkflowPolicy::stageForDaysLate($daysLate, $this->getStageThresholds(), $this->getEnabledLevels());
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
        $rule = $this->getRuleByLevel($level);
        return max(0.0, (float) $rule['fee_private']);
    }

    /** Payment period in days that a notice of this stage grants, 0 for none (#64). */
    public function getPaymentDaysForLevel($level)
    {
        $rule = $this->getRuleByLevel($level);
        return max(0, min(365, (int) $rule['payment_days']));
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
}
