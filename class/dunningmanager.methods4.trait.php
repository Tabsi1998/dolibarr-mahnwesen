<?php
/* Auto-split method trait for maintainable source files. */
trait DunningManagerMethods4
{

    /**
     * Legacy helper kept for compatibility with older call sites.
     * New mail sends use reserveNoticeAttempt()/finalizeNoticeAttempt().
     *
     * @param array $case Stored case
     * @param string $recipient Recipient email
     * @param int $level Level
     * @param float $amount Amount snapshot
     * @param string $result success|failed
     * @param string $message Audit message
     * @param User $user Acting user
     * @return bool
     */
    public function recordNoticeResult($case, $recipient, $level, $amount, $result, $message, $user)
    {
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $action = ($result === 'success') ? 'notice_sent' : 'notice_failed';
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_history (entity, fk_case, fk_facture, action, level, amount_snapshot, mode, result, recipient, message, date_creation, fk_user_create) VALUES (';
        $sql .= ((int) $case['entity']).', '.((int) $case['id']).', '.((int) $case['invoice_id']).", '".$this->db->escape($action)."', ".((int) $level).', '.((float) $amount).", 'manual', '".$this->db->escape($result)."', '".$this->db->escape((string) $recipient)."', '".$this->db->escape((string) $message)."', '".$this->db->escape($this->db->idate(dol_now()))."', ".$uid.')';
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            return false;
        }
        unset($this->completedLevelsCache[(int) $case['id']]);
        return true;
    }

    /**
     * Mark the case with the timestamp of the successful notice.
     *
     * @param array $case Stored case
     * @param User $user Acting user
     * @return bool
     */
    public function markNoticeSent($case, $user)
    {
        $sql = "UPDATE ".MAIN_DB_PREFIX."mahnwesen_case SET last_notice_at = '".$this->db->escape($this->db->idate(dol_now()))."', fk_user_modif = ".((is_object($user) && isset($user->id)) ? (int) $user->id : 0)." WHERE rowid = ".((int) $case['id']);
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            return false;
        }
        return true;
    }

    /**
     * Evaluate one invoice using the same rules as the dashboard scan.
     *
     * @param int $invoiceId Customer invoice id
     * @return array|false
     */
    public function evaluateInvoice($invoiceId)
    {
        global $conf;
        $invoice = new Facture($this->db);
        if ($invoice->fetch((int) $invoiceId) <= 0) {
            $this->error = 'Unable to fetch invoice id '.((int) $invoiceId);
            return false;
        }

        $remainRaw = $invoice->getRemainToPay(0);
        if (!is_numeric($remainRaw)) {
            $this->error = 'Unable to calculate remaining amount for invoice '.$invoice->ref;
            return false;
        }
        $remain = (float) $remainRaw;
        $reason = '';
        $eligible = true;

        $invoiceEntity = !empty($invoice->entity) ? (int) $invoice->entity : 0;
        $invoiceCurrency = !empty($invoice->multicurrency_code) ? strtoupper((string) $invoice->multicurrency_code) : strtoupper((string) $conf->currency);
        if ($invoiceEntity !== (int) $conf->entity) {
            $eligible = false;
            $reason = 'wrong_entity';
        } elseif ($invoiceCurrency !== strtoupper((string) $conf->currency)) {
            // getRemainToPay() is expressed in the company/base currency in
            // supported Dolibarr versions. Never label that amount as a foreign
            // invoice currency or mix currencies in dashboard totals.
            $eligible = false;
            $reason = 'unsupported_currency';
        } elseif ((int) $invoice->status !== Facture::STATUS_VALIDATED) {
            $eligible = false;
            $reason = 'invoice_not_open';
        } elseif (!$this->isSupportedInvoiceType((int) $invoice->type)) {
            $eligible = false;
            $reason = 'unsupported_type';
        } elseif ($remain <= 0) {
            $eligible = false;
            $reason = 'paid_or_zero_balance';
        } elseif ($remain < $this->getMinimumAmount()) {
            $eligible = false;
            $reason = 'below_minimum';
        } elseif (empty($invoice->date_lim_reglement)) {
            $eligible = false;
            $reason = 'missing_due_date';
        }

        $todayStart = dol_get_first_hour(dol_now(), 'tzserver');
        $todayYmd = dol_print_date($todayStart, '%Y-%m-%d', 'tzserver');
        $dueYmd = !empty($invoice->date_lim_reglement) ? dol_print_date($invoice->date_lim_reglement, '%Y-%m-%d', 'tzserver') : '';
        if ($eligible && (empty($dueYmd) || $dueYmd >= $todayYmd)) {
            $eligible = false;
            $reason = 'not_overdue';
        }

        $daysLate = ($eligible && $dueYmd) ? $this->daysBetween($dueYmd, $todayYmd) : 0;
        $stage = $this->determineStage($daysLate);
        $entity = $invoiceEntity;

        return array(
            'eligible' => $eligible ? 1 : 0,
            'reason' => $reason,
            'remain_to_pay' => $remain,
            'row' => array(
                'invoice_id' => (int) $invoice->id,
                'invoice_ref' => $invoice->ref,
                'invoice_type' => (int) $invoice->type,
                'socid' => (int) $invoice->socid,
                'socname' => '',
                'invoice_date' => $invoice->date,
                'due_date' => $invoice->date_lim_reglement,
                'due_ymd' => $dueYmd,
                'days_late' => $daysLate,
                'total_ttc' => (float) $invoice->total_ttc,
                'remain_to_pay' => $remain,
                'stage' => $stage,
                'stage_key' => $this->getStageLabelKey($stage),
                'paused' => 0,
                'stored_level' => 0,
                'case_status' => '',
                'case_id' => 0,
                'invoice_entity' => $entity,
            ),
        );
    }

    /**
     * @param array $row Scan row
     * @param User $user Acting user
     * @return string|false
     */
    protected function syncScannedRow($row, $user)
    {
        // Serialize case creation/update per invoice. This avoids relying on a
        // duplicate-key fallback, which would leave PostgreSQL transactions in
        // an aborted state after a concurrent INSERT.
        $sqlInvoiceLock = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'facture WHERE rowid = '.((int) $row['invoice_id']).' AND entity = '.((int) $row['invoice_entity']).' FOR UPDATE';
        $resInvoiceLock = $this->db->query($sqlInvoiceLock);
        if (!$resInvoiceLock || !$this->db->fetch_object($resInvoiceLock)) {
            if ($resInvoiceLock) { $this->db->free($resInvoiceLock); }
            $this->error = $this->db->lasterror() ?: 'Unable to lock invoice for case synchronization';
            return false;
        }
        $this->db->free($resInvoiceLock);
        $case = $this->getCaseByInvoice((int) $row['invoice_id']);
        $entity = !empty($row['invoice_entity']) ? (int) $row['invoice_entity'] : 1;
        $calculatedLevel = (int) $row['stage'];
        $remain = (float) $row['remain_to_pay'];
        $requiredLevel = $this->getNextRequiredLevel($case ? (int) $case['id'] : 0, $calculatedLevel);
        // Persist the calendar stage only. The next allowed workflow stage is
        // intentionally derived from immutable history via getNextRequiredLevel().
        $level = $calculatedLevel;
        $futureLevel = $this->getNextFutureLevel($calculatedLevel);
        $nextAction = $requiredLevel > 0 ? $this->calculateWorkflowStageDueAt($case ? (int) $case['id'] : 0, $row['due_ymd'], $requiredLevel) : ($futureLevel > 0 ? $this->calculateWorkflowStageDueAt($case ? (int) $case['id'] : 0, $row['due_ymd'], $futureLevel) : null);
        $nowSql = $this->db->idate(dol_now());

        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;

        if (!$case) {
            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_case (entity, fk_facture, current_level, paused, status, remaining_amount, next_action_at, date_creation, fk_user_create, fk_user_modif) VALUES (';
            $sql .= $entity.', '.((int) $row['invoice_id']).', '.$level.", 0, 'open', ".((float) $remain).', ';
            $sql .= ($nextAction ? "'".$this->db->escape($nextAction)."'" : 'NULL');
            $sql .= ", '".$this->db->escape($nowSql)."', ".$uid.', '.$uid.')';
            if (!$this->db->query($sql)) {
                $this->error = $this->db->lasterror();
                return false;
            }
            $caseId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'mahnwesen_case');
            if ($caseId <= 0) {
                $this->error = 'Unable to get inserted dunning case id';
                return false;
            }
            if (!$this->addHistory($entity, $caseId, (int) $row['invoice_id'], 'case_created', $level, $remain, 'manual', 'success', '', $user)) {
                return false;
            }
            return 'created';
        }

        $oldLevel = (int) $case['current_level'];
        $paused = !empty($case['paused']);
        $wasClosed = ($case['status'] === 'closed');
        $newLevel = $paused ? $oldLevel : $level;
        // A synchronization must not move the workflow due date while paused.
        // The independent pause deadline is stored in mahnwesen_pause.
        if ($paused) {
            $nextAction = $case['next_action_at'];
        }

        $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_case SET';
        $sql .= " status = 'open'";
        $sql .= ', remaining_amount = '.((float) $remain);
        $sql .= ', current_level = '.$newLevel;
        $sql .= ', next_action_at = '.($nextAction ? "'".$this->db->escape($nextAction)."'" : 'NULL');
        $sql .= ', fk_user_modif = '.$uid;
        $sql .= ' WHERE rowid = '.((int) $case['id']);
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            return false;
        }

        if ($wasClosed) {
            if (!$this->addHistory($entity, $case['id'], (int) $row['invoice_id'], 'case_reopened', $newLevel, $remain, 'manual', 'success', '', $user)) {
                return false;
            }
            return 'reopened';
        }
        if (!$paused && $newLevel !== $oldLevel) {
            if (!$this->addHistory($entity, $case['id'], (int) $row['invoice_id'], 'level_changed', $newLevel, $remain, 'manual', 'success', 'from='.$oldLevel.' to='.$newLevel, $user)) {
                return false;
            }
            return 'level_changed';
        }
        if (abs(((float) $case['remaining_amount']) - $remain) > 0.000001) {
            return 'updated';
        }
        return 'unchanged';
    }

    /**
     * Close stored cases whose invoice is no longer eligible for dunning.
     *
     * @param User $user Acting user
     * @return int Number closed, -1 on error
     */
    protected function closeNoLongerEligibleCasesSafe($user)
    {
        global $conf;
        $closed = 0;
        $sql = 'SELECT DISTINCT mc.rowid, mc.entity, mc.fk_facture, mc.current_level, mc.paused, mc.status, mc.remaining_amount';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'mahnwesen_case as mc';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = mc.fk_facture AND f.entity = mc.entity';
        $restrictCustomerVisibility = is_object($user) && !empty($user->id) && !$user->hasRight('societe', 'client', 'voir');
        if ($restrictCustomerVisibility) {
            $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'societe_commerciaux as sc ON sc.fk_soc = f.fk_soc AND sc.fk_user = '.((int) $user->id);
        }
        $sql .= " WHERE mc.status = 'open'";
        $sql .= ' AND mc.entity = '.((int) $conf->entity);
        $sql .= ' ORDER BY mc.rowid ASC'.$this->db->plimit(min(10000, $this->getMaxScan() * 2));
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            $this->errors[] = $this->error;
            return -1;
        }
        $cases = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $cases[] = array(
                'id' => (int) $obj->rowid,
                'entity' => (int) $obj->entity,
                'invoice_id' => (int) $obj->fk_facture,
                'current_level' => (int) $obj->current_level,
                'paused' => (int) $obj->paused,
                'status' => (string) $obj->status,
                'remaining_amount' => (float) $obj->remaining_amount,
            );
        }
        $this->db->free($resql);

        foreach ($cases as $case) {
            try {
                $evaluation = $this->evaluateInvoice($case['invoice_id']);
                if ($evaluation === false) {
                    $this->errors[] = 'Invoice id '.$case['invoice_id'].': '.($this->error ?: 'evaluation failed');
                    continue;
                }
                if (empty($evaluation['eligible'])) {
                    $this->db->begin();
                    if (!$this->closeCase($case, (float) $evaluation['remain_to_pay'], $evaluation['reason'], $user)) {
                        $this->db->rollback();
                        $this->errors[] = 'Invoice id '.$case['invoice_id'].': '.($this->error ?: 'close failed');
                        continue;
                    }
                    $this->db->commit();
                    $closed++;
                }
            } catch (Throwable $e) {
                try {
                    $this->db->rollback();
                } catch (Throwable $ignored) {
                    // Nothing else to do.
                }
                $this->errors[] = 'Invoice id '.$case['invoice_id'].': '.get_class($e).': '.$e->getMessage();
                dol_syslog(__METHOD__.' '.end($this->errors), LOG_ERR);
            }
        }
        return $closed;
    }

    /**
     * @param array $case Stored case
     * @param float $remain Current remaining amount
     * @param string $reason Closure reason
     * @param User $user Acting user
     * @return bool
     */
    protected function closeCase($case, $remain, $reason, $user)
    {
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $hasOpenFee = false;
        if ((float) $remain <= 0.000001) {
            $sqlFee = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_fee WHERE entity = '.((int) $case['entity']).' AND fk_case = '.((int) $case['id'])." AND status = 'open'".$this->db->plimit(1);
            $resFee = $this->db->query($sqlFee);
            if ($resFee) { $hasOpenFee = (bool) $this->db->fetch_object($resFee); $this->db->free($resFee); }
        }
        $newStatus = $hasOpenFee ? 'fee_open' : 'closed';
        $sql = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_case SET status = '".$newStatus."', paused = 0, remaining_amount = ".((float) max(0, $remain));
        $sql .= ', next_action_at = NULL, fk_user_modif = '.$uid;
        $sql .= ' WHERE rowid = '.((int) $case['id']);
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            return false;
        }
        // A closed case is not paused; its pause must not come back when the
        // invoice is reopened.
        $sqlPause = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_pause SET status = 'ended', date_end = '".$this->db->escape($this->db->idate(dol_now()))."', fk_user_end = ".$uid.' WHERE entity = '.((int) $case['entity']).' AND fk_case = '.((int) $case['id'])." AND status = 'active'";
        if (!$this->db->query($sqlPause)) {
            $this->error = $this->db->lasterror();
            return false;
        }
        return $this->addHistory((int) $case['entity'], (int) $case['id'], (int) $case['invoice_id'], $hasOpenFee ? 'invoice_paid_fee_open' : 'case_closed', (int) $case['current_level'], (float) max(0, $remain), 'manual', 'success', $reason, $user);
    }

    /**
     * Add an immutable history row.
     *
     * @param int $entity Entity id
     * @param int $caseId Case id
     * @param int $invoiceId Invoice id
     * @param string $action Action code
     * @param int $level Dunning level snapshot
     * @param float $amount Amount snapshot
     * @param string $mode Mode
     * @param string $result Result
     * @param string $message Message
     * @param User $user Acting user
     * @return bool
     */
    protected function addHistory($entity, $caseId, $invoiceId, $action, $level, $amount, $mode, $result, $message, $user)
    {
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_history (entity, fk_case, fk_facture, action, level, amount_snapshot, mode, result, message, date_creation, fk_user_create) VALUES (';
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $sql .= ((int) $entity).', '.((int) $caseId).', '.((int) $invoiceId).", '".$this->db->escape($action)."', ".((int) $level).', '.((float) $amount).", '".$this->db->escape($mode)."', '".$this->db->escape($result)."', '".$this->db->escape($message)."', '".$this->db->escape($this->db->idate(dol_now()))."', ".$uid.')';
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            return false;
        }
        unset($this->completedLevelsCache[(int) $caseId]);
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
            'days_after_due' => $this->getIntSetting('MAHNWESEN_STAGE'.$level.'_DAYS', $days[$level]),
            'minimum_amount' => 0.0,
            'fee_amount' => $fees[$level],
            'interest_rate' => 0.0,
            'send_email' => 0,
            'generate_pdf' => 1,
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
            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_rule (entity, code, label, level, days_after_due, minimum_amount, fee_amount, interest_rate, send_email, generate_pdf, email_template, enabled, date_creation, fk_user_create, fk_user_modif) VALUES (';
            $sql .= ((int) $conf->entity).", '".$this->db->escape($code)."', '".$this->db->escape($d['label'])."', ".$level.', '.((int) $d['days_after_due']).', 0, '.((float) $d['fee_amount']).", 0, 0, 1, 'internal', 1, '".$this->db->escape($this->db->idate(dol_now()))."', ".$uid.', '.$uid.')';
            if (!$this->db->query($sql)) {
                $this->error = $this->db->lasterror();
                return false;
            }
        }
        $this->rulesCache = null;
        return true;
    }
}
