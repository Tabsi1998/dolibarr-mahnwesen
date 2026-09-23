<?php
/* Dunning cases: synchronisation, pauses, notes, skipping, closing. */
trait DunningManagerCases
{
    /**
     * Synchronize all currently overdue invoices into persistent dunning cases.
     * No email is sent and no invoice is modified.
     *
     * @param User $user Acting user
     * @param int $limit Max overdue candidates
     * @return array|false Summary or false on error
     */
    public function syncCases($user, $limit = 0)
    {
        $this->error = '';
        $this->errors = array();

        // Fail early with a readable Dolibarr error if the persistent storage
        // is missing or does not match the module version.
        if (!$this->checkStorageSchema()) {
            return false;
        }

        $rows = $this->scanDueInvoices($limit, $user);
        if ($rows === false) {
            return false;
        }

        $summary = array('created' => 0, 'updated' => 0, 'level_changed' => 0, 'reopened' => 0, 'closed' => 0, 'unchanged' => 0, 'errors' => 0, 'failed_invoices' => array());

        // Use one transaction per invoice. A single bad record must not leave
        // an open transaction or turn the whole request into a HTTP 500.
        foreach ($rows as $row) {
            try {
                $this->db->begin();
                $result = $this->syncScannedRow($row, $user);
                if ($result === false) {
                    $this->db->rollback();
                    $summary['errors']++;
                    $this->errors[] = 'Invoice '.$row['invoice_ref'].': '.($this->error ?: 'unknown database error');
                    $summary['failed_invoices'][(int) $row['invoice_id']] = (string) $row['invoice_ref'];
                    continue;
                }
                $this->db->commit();
                $this->syncHistoryToAgenda((int) $row['invoice_id'], $user);
                if (isset($summary[$result])) {
                    $summary[$result]++;
                } else {
                    $summary['updated']++;
                }
            } catch (Throwable $e) {
                try {
                    $this->db->rollback();
                } catch (Throwable $ignored) {
                    // Keep original exception as the useful error.
                }
                $summary['errors']++;
                $message = 'Invoice '.$row['invoice_ref'].': '.get_class($e).': '.$e->getMessage();
                $this->errors[] = $message;
                $summary['failed_invoices'][(int) $row['invoice_id']] = (string) $row['invoice_ref'];
                dol_syslog(__METHOD__.' '.$message, LOG_ERR);
            }
        }

        // Existing cases that are no longer eligible are closed independently.
        try {
            $closed = $this->closeNoLongerEligibleCasesSafe($user);
            if ($closed < 0) {
                $summary['errors']++;
            } else {
                $summary['closed'] = $closed;
            }
        } catch (Throwable $e) {
            $summary['errors']++;
            $this->errors[] = 'Closing obsolete dunning cases failed: '.get_class($e).': '.$e->getMessage();
            dol_syslog(__METHOD__.' close phase fatal: '.get_class($e).': '.$e->getMessage(), LOG_ERR);
        }

        if ($summary['errors'] > 0 && ($summary['created'] + $summary['updated'] + $summary['level_changed'] + $summary['reopened'] + $summary['unchanged'] + $summary['closed']) === 0) {
            $this->error = 'Mahnwesen synchronization failed. See detailed errors.';
            return false;
        }

        return $summary;
    }

    /**
     * Verify the persistent tables before a write operation.
     * The SELECT statements are database-portable and do not modify data.
     *
     * @return bool
     */
    protected function checkStorageSchema()
    {
        $checks = array(
            'mahnwesen_case' => 'SELECT rowid, entity, fk_facture, current_level, paused, status, remaining_amount, last_notice_at, next_action_at, note_private, date_creation, tms, fk_user_create, fk_user_modif FROM '.MAIN_DB_PREFIX.'mahnwesen_case WHERE 1 = 0',
            'mahnwesen_history' => 'SELECT rowid, entity, fk_case, fk_facture, action, level, amount_snapshot, mode, result, recipient, message, date_creation, fk_user_create FROM '.MAIN_DB_PREFIX.'mahnwesen_history WHERE 1 = 0',
            'mahnwesen_attempt' => 'SELECT rowid, entity, fk_case, fk_facture, level, mode, status, recipient, sender, subject, amount_invoice, amount_fee, amount_total, currency_code, reserved_at FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE 1 = 0',
            'mahnwesen_attempt_file' => 'SELECT rowid, entity, fk_attempt, file_role, display_name, snapshot_path, sha256, mime_type, size_bytes, date_creation FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt_file WHERE 1 = 0',
            'mahnwesen_fee' => 'SELECT rowid, entity, fk_case, fk_facture, fk_attempt, level, amount, currency_code, status, date_creation FROM '.MAIN_DB_PREFIX.'mahnwesen_fee WHERE 1 = 0',
            'mahnwesen_pause' => 'SELECT rowid, entity, fk_case, fk_facture, status, pause_until, reason, date_creation, date_end FROM '.MAIN_DB_PREFIX.'mahnwesen_pause WHERE 1 = 0',
            'mahnwesen_run' => 'SELECT rowid, entity, mode, status, run_lock, started_at, finished_at, scanned, synchronized, attempted, sent, skipped, failed, summary, fk_user FROM '.MAIN_DB_PREFIX.'mahnwesen_run WHERE 1 = 0',
        );

        foreach ($checks as $table => $sql) {
            try {
                $resql = $this->db->query($sql);
            } catch (Throwable $e) {
                $this->error = 'Storage check failed for '.MAIN_DB_PREFIX.$table.': '.$e->getMessage();
                $this->errors[] = $this->error;
                dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
                return false;
            }
            if (!$resql) {
                $this->error = 'Storage check failed for '.MAIN_DB_PREFIX.$table.': '.$this->db->lasterror();
                $this->errors[] = $this->error;
                return false;
            }
            $this->db->free($resql);
        }
        return true;
    }

    /**
     * Synchronize one invoice into a dunning case when eligible.
     * If the invoice is no longer eligible, an existing case is closed.
     *
     * @param int $invoiceId Customer invoice id
     * @param User $user Acting user
     * @return string|false Result code
     */
    /**
     * Note that a case has to be re-evaluated (#36).
     *
     * Dolibarr announces a payment that is being removed before it is gone, so
     * the open amount is only final afterwards. The note is worked off by the
     * next Mahnwesen page and by the daily run, with the same evaluation as
     * everything else.
     *
     * @param int[] $invoiceIds Invoices
     * @return bool
     */
    public function markCasesForRecheck($invoiceIds)
    {
        global $conf;
        $ids = array();
        foreach ((array) $invoiceIds as $invoiceId) {
            if ((int) $invoiceId > 0) {
                $ids[] = (int) $invoiceId;
            }
        }
        if (empty($ids)) {
            return true;
        }
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_case SET recheck = 1 WHERE entity = '.((int) $conf->entity);
        $sql .= ' AND fk_facture IN ('.implode(',', $ids).')';
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            return false;
        }
        return true;
    }

    /**
     * Re-evaluate the cases that carry a note, newest first (#36).
     *
     * @param User $user Acting user
     * @param int $limit How many at most
     * @return int|false Number of cases re-evaluated
     */
    public function processRecheckQueue($user, $limit = 50)
    {
        global $conf;
        if (!$this->checkStorageSchema()) {
            return false;
        }
        $sql = 'SELECT rowid, fk_facture FROM '.MAIN_DB_PREFIX.'mahnwesen_case WHERE entity = '.((int) $conf->entity);
        $sql .= ' AND recheck = 1 ORDER BY tms DESC'.$this->db->plimit(max(1, min(500, (int) $limit)));
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $cases = array();
        while ($o = $this->db->fetch_object($res)) {
            $cases[(int) $o->rowid] = (int) $o->fk_facture;
        }
        $this->db->free($res);
        $done = 0;
        foreach ($cases as $caseId => $invoiceId) {
            // The note goes first: a case that cannot be evaluated must not be retried forever.
            $this->db->query('UPDATE '.MAIN_DB_PREFIX.'mahnwesen_case SET recheck = 0 WHERE rowid = '.((int) $caseId));
            if ($this->syncInvoiceCase($invoiceId, $user) === false) {
                $this->errors[] = 'Unable to re-evaluate invoice '.$invoiceId.': '.$this->error;
                continue;
            }
            $done++;
        }
        return $done;
    }

    public function syncInvoiceCase($invoiceId, $user)
    {
        if (!$this->checkStorageSchema()) { return false; }
        $evaluation = $this->evaluateInvoice((int) $invoiceId);
        if ($evaluation === false) {
            return false;
        }

        $this->db->begin();
        if (empty($evaluation['eligible'])) {
            $case = $this->getCaseByInvoice((int) $invoiceId);
            if ($case && $case['status'] === 'open') {
                if (!$this->closeCase($case, $evaluation['remain_to_pay'], $evaluation['reason'], $user)) {
                    $this->db->rollback();
                    return false;
                }
                $this->db->commit();
                $this->syncHistoryToAgenda((int) $invoiceId, $user);
                $this->dispatchEvents($user, 20);
                return 'closed';
            }
            $this->db->commit();
            return 'not_eligible';
        }

        $result = $this->syncScannedRow($evaluation['row'], $user);
        if ($result === false) {
            $this->db->rollback();
            return false;
        }
        $this->db->commit();
        $this->syncHistoryToAgenda((int) $invoiceId, $user);
        return $result;
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
        $profileId = (int) ($row['profile_id'] ?? 0);
        $requiredLevel = $this->getNextRequiredLevel($case ? (int) $case['id'] : 0, $calculatedLevel, $profileId);
        // Persist the calendar stage only. The next allowed workflow stage is
        // intentionally derived from immutable history via getNextRequiredLevel().
        $level = $calculatedLevel;
        $futureLevel = $this->getNextFutureLevel($calculatedLevel, $profileId);
        $nextAction = $requiredLevel > 0 ? $this->calculateWorkflowStageDueAt($case ? (int) $case['id'] : 0, $row['due_ymd'], $requiredLevel, $profileId) : ($futureLevel > 0 ? $this->calculateWorkflowStageDueAt($case ? (int) $case['id'] : 0, $row['due_ymd'], $futureLevel, $profileId) : null);
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
     * Pause or resume a stored case. Resuming immediately synchronizes the
     * stored level to the currently calculated level.
     *
     * @param int $invoiceId Customer invoice id
     * @param bool $paused New paused state
     * @param User $user Acting user
     * @param string $note Optional private note
     * @return bool
     */
    public function setPaused($invoiceId, $paused, $user, $note = '', $pauseUntilYmd = '', $mode = 'manual')
    {
        if (!$this->checkStorageSchema()) { return false; }
        $case = $this->getCaseByInvoice((int) $invoiceId);
        if (!$case) {
            $sync = $this->syncInvoiceCase((int) $invoiceId, $user);
            if ($sync === false) {
                return false;
            }
            $case = $this->getCaseByInvoice((int) $invoiceId);
            if (!$case) {
                $this->error = 'No dunning case exists for this invoice';
                return false;
            }
        }

        $newPaused = $paused ? 1 : 0;
        $evaluation = $this->evaluateInvoice((int) $invoiceId);
        if ($evaluation === false) {
            return false;
        }
        $level = (int) $case['current_level'];
        $remaining = (float) $case['remaining_amount'];
        $nextAction = $case['next_action_at'];
        $pauseUntilSql = null;

        if ($newPaused) {
            // Pause timing is stored separately from the workflow due date.
            // NULL means an indefinite pause.
            $pauseUntilYmd = trim((string) $pauseUntilYmd);
            if ($pauseUntilYmd !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $pauseUntilYmd);
                $errors = DateTimeImmutable::getLastErrors();
                if (!$date || (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count'])))) {
                    $this->error = 'Invalid pause-until date';
                    return false;
                }
                $today = new DateTimeImmutable(date('Y-m-d', dol_now()));
                if ($date < $today) {
                    $this->error = 'Pause-until date must not be in the past';
                    return false;
                }
                $pauseUntilSql = $date->format('Y-m-d 00:00:00');
            }
        } elseif (!empty($evaluation['eligible'])) {
            $calculatedLevel = (int) $evaluation['row']['stage'];
            $profileId = (int) $evaluation['row']['profile_id'];
            $requiredLevel = $this->getNextRequiredLevel((int) $case['id'], $calculatedLevel, $profileId);
            // current_level tracks the calendar stage; completion/sequence is derived from history.
            // This avoids visually downgrading a case merely because an earlier due notice was not sent.
            $level = $calculatedLevel;
            $remaining = (float) $evaluation['row']['remain_to_pay'];
            $futureLevel = $this->getNextFutureLevel($calculatedLevel, $profileId);
            $nextAction = $requiredLevel > 0 ? $this->calculateWorkflowStageDueAt((int) $case['id'], $evaluation['row']['due_ymd'], $requiredLevel, $profileId) : ($futureLevel > 0 ? $this->calculateWorkflowStageDueAt((int) $case['id'], $evaluation['row']['due_ymd'], $futureLevel, $profileId) : null);
        }

        if ((int) $case['paused'] === $newPaused && $note === '' && (($newPaused && (string) ($case['pause_until'] ?? '') === (string) $pauseUntilSql) || !$newPaused)) {
            return true;
        }

        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $this->db->begin();
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_case SET';
        $sql .= ' paused = '.$newPaused;
        $sql .= ', current_level = '.$level;
        $sql .= ', remaining_amount = '.((float) $remaining);
        $sql .= ', next_action_at = '.($nextAction ? "'".$this->db->escape($nextAction)."'" : 'NULL');
        $sql .= ', fk_user_modif = '.$uid;
        $sql .= ' WHERE rowid = '.((int) $case['id']);
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return false;
        }

        $nowSql = $this->db->idate(dol_now());
        $sqlEndPause = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_pause SET status = 'ended', date_end = '".$this->db->escape($nowSql)."', fk_user_end = ".$uid.' WHERE entity = '.((int) $case['entity']).' AND fk_case = '.((int) $case['id'])." AND status = 'active'";
        if (!$this->db->query($sqlEndPause)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
        if ($newPaused) {
            $sqlPause = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_pause (entity, fk_case, fk_facture, status, pause_until, reason, date_creation, fk_user_create) VALUES ('.((int) $case['entity']).', '.((int) $case['id']).', '.((int) $invoiceId).", 'active', ".($pauseUntilSql ? "'".$this->db->escape($pauseUntilSql)."'" : 'NULL').", '".$this->db->escape((string) $note)."', '".$this->db->escape($nowSql)."', ".$uid.')';
            if (!$this->db->query($sqlPause)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
        }

        $historyAction = $newPaused ? 'paused' : (($mode === 'automatic') ? 'auto_resumed' : 'resumed');
        $historyNote = (string) $note;
        if ($newPaused && $pauseUntilSql) {
            $historyNote .= ($historyNote !== '' ? "
" : '').'Pause until: '.substr($pauseUntilSql, 0, 10);
        }
        if (!$this->addHistory($case['entity'], $case['id'], (int) $invoiceId, $historyAction, $level, $remaining, $mode, 'success', $historyNote, $user)) {
            $this->db->rollback();
            return false;
        }
        $this->db->commit();
        $this->syncHistoryToAgenda((int) $invoiceId, $user);
        return true;
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
            if ($this->setPaused($invoiceId, false, $user, $GLOBALS['langs']->transnoentities('MahnwesenAutoResumedNote'), '', 'automatic')) {
                $count++;
            } else {
                $this->errors[] = 'Auto-resume invoice #'.$invoiceId.': '.$this->error;
            }
        }
        return $count;
    }

    /**
     * Update the persistent internal case note. Pause/resume reasons are kept
     * separately in history and do not overwrite this field.
     *
     * @param int $invoiceId Invoice id
     * @param string $note Internal note
     * @param User $user Acting user
     * @return bool
     */
    public function updateCaseNote($invoiceId, $note, $user)
    {
        $case = $this->getCaseByInvoice((int) $invoiceId);
        if (!$case) {
            $this->error = 'No dunning case exists for this invoice';
            return false;
        }
        $this->db->begin();
        $sql = "UPDATE ".MAIN_DB_PREFIX."mahnwesen_case SET note_private = '".$this->db->escape((string) $note)."', fk_user_modif = ".((is_object($user) && isset($user->id)) ? (int) $user->id : 0)." WHERE rowid = ".((int) $case['id']);
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return false;
        }
        if (!$this->addHistory((int) $case['entity'], (int) $case['id'], (int) $invoiceId, 'note_changed', (int) $case['current_level'], (float) $case['remaining_amount'], 'manual', 'success', (string) $note, $user)) {
            $this->db->rollback();
            return false;
        }
        $this->db->commit();
        $this->syncHistoryToAgenda((int) $invoiceId, $user);
        return true;
    }

    /** Explicitly complete the currently due stage without sending it. */
    public function skipCurrentStage($invoiceId, $reason, $user)
    {
        global $conf;
        $reason = trim((string) $reason);
        if ($reason === '') { $this->error = 'A reason is required to skip a dunning stage.'; return false; }
        if (!is_object($user) || !$user->hasRight('facture', 'lire') || !$user->hasRight('mahnwesen', 'case', 'write') || !$user->hasRight('mahnwesen', 'notice', 'send')) { $this->error = 'User is not allowed to skip dunning stages.'; return false; }
        if (!$this->checkStorageSchema()) { return false; }
        $this->db->begin();
        $case = $this->getCaseByInvoice((int) $invoiceId);
        if (!$case) { $this->error = 'No dunning case exists for this invoice.'; $this->db->rollback(); return false; }
        $sqlLock = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_case WHERE rowid = '.((int) $case['id']).' AND entity = '.((int) $conf->entity).' FOR UPDATE';
        $resLock = $this->db->query($sqlLock); $locked = $resLock ? $this->db->fetch_object($resLock) : false; if ($resLock) { $this->db->free($resLock); }
        if (!$locked) { $this->error = 'Unable to lock dunning case.'; $this->db->rollback(); return false; }
        $invoice = new Facture($this->db); if ($invoice->fetch((int) $invoiceId) <= 0) { $this->error = 'Unable to load invoice.'; $this->db->rollback(); return false; }
        if (!$this->canSeeCustomer($user, (int) $invoice->socid)) { $this->error = 'Invoice is outside the user customer scope.'; $this->db->rollback(); return false; }
        $workflow = $this->getWorkflowState((int) $invoiceId);
        $currentCase = $workflow ? $workflow['case'] : false;
        $level = $workflow ? (int) $workflow['next_required_level'] : 0;
        if (!$workflow || empty($workflow['actionable']) || !$currentCase || $currentCase['status'] !== 'open' || !empty($currentCase['paused']) || $level <= 0) { $this->error = 'The workflow changed and no stage can be skipped now.'; $this->db->rollback(); return false; }
        // A notice of this stage may have reached the customer (#17).
        $openAttempt = $this->getOpenAttemptId((int) $case['id'], $level);
        if ($openAttempt === false) { $this->db->rollback(); return false; }
        if ($openAttempt > 0) { $this->error = 'Delivery attempt #'.$openAttempt.' of this stage is not resolved yet. Resolve it on the delivery attempts page before skipping the stage.'; $this->db->rollback(); return false; }
        if (!$this->addHistory((int) $case['entity'], (int) $case['id'], (int) $invoiceId, 'stage_skipped', $level, (float) $workflow['evaluation']['remain_to_pay'], 'manual', 'success', $reason, $user)) { $this->db->rollback(); return false; }
        $this->db->commit();
        $this->syncInvoiceCase((int) $invoiceId, $user);
        $this->syncHistoryToAgenda((int) $invoiceId, $user);
        return true;
    }

    /**
     * Return a stored dunning case for an invoice.
     *
     * @param int $invoiceId Customer invoice id
     * @return array|null
     */
    public function getCaseByInvoice($invoiceId)
    {
        global $conf;
        $sql = 'SELECT rowid, entity, fk_facture, current_level, paused, status, remaining_amount, last_notice_at, next_action_at, note_private, date_creation, tms, fk_user_create, fk_user_modif';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'mahnwesen_case';
        $sql .= ' WHERE fk_facture = '.((int) $invoiceId);
        $sql .= ' AND entity = '.((int) $conf->entity);
        $sql .= ' ORDER BY rowid DESC';
        $sql .= $this->db->plimit(1);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$obj) {
            return null;
        }
        $caseData = array(
            'id' => (int) $obj->rowid,
            'entity' => (int) $obj->entity,
            'invoice_id' => (int) $obj->fk_facture,
            'current_level' => (int) $obj->current_level,
            'paused' => (int) $obj->paused,
            'status' => (string) $obj->status,
            'remaining_amount' => (float) $obj->remaining_amount,
            'last_notice_at' => $obj->last_notice_at,
            'next_action_at' => $obj->next_action_at,
            'note_private' => (string) $obj->note_private,
            'date_creation' => $obj->date_creation,
            'tms' => $obj->tms,
            'fk_user_create' => (int) $obj->fk_user_create,
            'fk_user_modif' => (int) $obj->fk_user_modif,
        );
        $sqlPause = 'SELECT pause_until, reason FROM '.MAIN_DB_PREFIX.'mahnwesen_pause WHERE entity = '.((int) $caseData['entity']).' AND fk_case = '.((int) $caseData['id'])." AND status = 'active' ORDER BY rowid DESC".$this->db->plimit(1);
        $resPause = $this->db->query($sqlPause);
        if ($resPause) {
            $pause = $this->db->fetch_object($resPause);
            if ($pause) { $caseData['pause_until'] = $pause->pause_until; $caseData['pause_reason'] = (string) $pause->reason; $caseData['pause_active'] = 1; }
            $this->db->free($resPause);
        }
        if (!array_key_exists('pause_until', $caseData)) { $caseData['pause_until'] = null; $caseData['pause_reason'] = ''; $caseData['pause_active'] = 0; }
        return $caseData;
    }
}
