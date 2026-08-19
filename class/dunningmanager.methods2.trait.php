<?php
/* Auto-split method trait for maintainable source files. */
trait DunningManagerMethods2
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

        if (!$this->checkStorageSchema()) {
            return false;
        }

        $rows = $this->scanDueInvoices($limit);
        if ($rows === false) {
            return false;
        }

        $summary = array('created' => 0, 'updated' => 0, 'level_changed' => 0, 'reopened' => 0, 'closed' => 0, 'unchanged' => 0, 'errors' => 0);

        foreach ($rows as $row) {
            try {
                $this->db->begin();
                $result = $this->syncScannedRow($row, $user);
                if ($result === false) {
                    $this->db->rollback();
                    $summary['errors']++;
                    $this->errors[] = 'Invoice '.$row['invoice_ref'].': '.($this->error ?: 'unknown database error');
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
                try { $this->db->rollback(); } catch (Throwable $ignored) {}
                $summary['errors']++;
                $message = 'Invoice '.$row['invoice_ref'].': '.get_class($e).': '.$e->getMessage();
                $this->errors[] = $message;
                dol_syslog(__METHOD__.' '.$message, LOG_ERR);
            }
        }

        try {
            $closed = $this->closeNoLongerEligibleCasesSafe($user);
            if ($closed < 0) { $summary['errors']++; }
            else { $summary['closed'] = $closed; }
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

    protected function checkStorageSchema()
    {
        $checks = array(
            'mahnwesen_case' => 'SELECT rowid, entity, fk_facture, current_level, paused, status, remaining_amount, last_notice_at, next_action_at, note_private, date_creation, tms, fk_user_create, fk_user_modif FROM '.MAIN_DB_PREFIX.'mahnwesen_case WHERE 1 = 0',
            'mahnwesen_history' => 'SELECT rowid, entity, fk_case, fk_facture, action, level, amount_snapshot, mode, result, recipient, message, date_creation, fk_user_create FROM '.MAIN_DB_PREFIX.'mahnwesen_history WHERE 1 = 0',
        );
        foreach ($checks as $table => $sql) {
            try { $resql = $this->db->query($sql); }
            catch (Throwable $e) {
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

    public function syncInvoiceCase($invoiceId, $user)
    {
        $evaluation = $this->evaluateInvoice((int) $invoiceId);
        if ($evaluation === false) return false;
        $this->db->begin();
        if (empty($evaluation['eligible'])) {
            $case = $this->getCaseByInvoice((int) $invoiceId);
            if ($case && $case['status'] !== 'closed') {
                if (!$this->closeCase($case, $evaluation['remain_to_pay'], $evaluation['reason'], $user)) { $this->db->rollback(); return false; }
                $this->db->commit();
                $this->syncHistoryToAgenda((int) $invoiceId, $user);
                return 'closed';
            }
            $this->db->commit();
            return 'not_eligible';
        }
        $result = $this->syncScannedRow($evaluation['row'], $user);
        if ($result === false) { $this->db->rollback(); return false; }
        $this->db->commit();
        $this->syncHistoryToAgenda((int) $invoiceId, $user);
        return $result;
    }

    public function setPaused($invoiceId, $paused, $user, $note = '', $pauseUntilYmd = '', $mode = 'manual')
    {
        $case = $this->getCaseByInvoice((int) $invoiceId);
        if (!$case) {
            $sync = $this->syncInvoiceCase((int) $invoiceId, $user);
            if ($sync === false) return false;
            $case = $this->getCaseByInvoice((int) $invoiceId);
            if (!$case) { $this->error = 'No dunning case exists for this invoice'; return false; }
        }
        $newPaused = $paused ? 1 : 0;
        $evaluation = $this->evaluateInvoice((int) $invoiceId);
        if ($evaluation === false) return false;
        $level = (int) $case['current_level'];
        $remaining = (float) $case['remaining_amount'];
        $nextAction = $case['next_action_at'];
        if ($newPaused) {
            $pauseUntilYmd = trim((string) $pauseUntilYmd);
            if ($pauseUntilYmd !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $pauseUntilYmd);
                $errors = DateTimeImmutable::getLastErrors();
                if (!$date || (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count'])))) { $this->error = 'Invalid pause-until date'; return false; }
                $today = new DateTimeImmutable(date('Y-m-d', dol_now()));
                if ($date < $today) { $this->error = 'Pause-until date must not be in the past'; return false; }
                $nextAction = $date->format('Y-m-d 00:00:00');
            } else { $nextAction = null; }
        } elseif (!empty($evaluation['eligible'])) {
            $calculatedLevel = (int) $evaluation['row']['stage'];
            $requiredLevel = $this->getNextRequiredLevel((int) $case['id'], $calculatedLevel);
            $level = $calculatedLevel;
            $remaining = (float) $evaluation['row']['remain_to_pay'];
            $futureLevel = $this->getNextFutureLevel($calculatedLevel);
            $nextAction = $requiredLevel > 0 ? $this->calculateWorkflowStageDueAt((int) $case['id'], $evaluation['row']['due_ymd'], $requiredLevel) : ($futureLevel > 0 ? $this->calculateWorkflowStageDueAt((int) $case['id'], $evaluation['row']['due_ymd'], $futureLevel) : null);
        }
        if ((int) $case['paused'] === $newPaused && $note === '' && (($newPaused && (string) $case['next_action_at'] === (string) $nextAction) || !$newPaused)) return true;
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $this->db->begin();
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_case SET paused = '.$newPaused.', current_level = '.$level.', remaining_amount = '.((float) $remaining).', next_action_at = '.($nextAction ? "'".$this->db->escape($nextAction)."'" : 'NULL').', fk_user_modif = '.$uid.' WHERE rowid = '.((int) $case['id']);
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
        $historyAction = $newPaused ? 'paused' : (($mode === 'automatic') ? 'auto_resumed' : 'resumed');
        $historyNote = (string) $note;
        if ($newPaused && $nextAction) $historyNote .= ($historyNote !== '' ? "\n" : '').'Pause bis: '.substr($nextAction, 0, 10);
        if (!$this->addHistory($case['entity'], $case['id'], (int) $invoiceId, $historyAction, $level, $remaining, $mode, 'success', $historyNote, $user)) { $this->db->rollback(); return false; }
        $this->db->commit();
        $this->syncHistoryToAgenda((int) $invoiceId, $user);
        return true;
    }

    public function updateCaseNote($invoiceId, $note, $user)
    {
        $case = $this->getCaseByInvoice((int) $invoiceId);
        if (!$case) { $this->error = 'No dunning case exists for this invoice'; return false; }
        $this->db->begin();
        $sql = "UPDATE ".MAIN_DB_PREFIX."mahnwesen_case SET note_private = '".$this->db->escape((string) $note)."', fk_user_modif = ".((is_object($user) && isset($user->id)) ? (int) $user->id : 0)." WHERE rowid = ".((int) $case['id']);
        if (!$this->db->query($sql)) { $this->error=$this->db->lasterror(); $this->db->rollback(); return false; }
        if (!$this->addHistory((int) $case['entity'], (int) $case['id'], (int) $invoiceId, 'note_changed', (int) $case['current_level'], (float) $case['remaining_amount'], 'manual', 'success', (string) $note, $user)) { $this->db->rollback(); return false; }
        $this->db->commit(); $this->syncHistoryToAgenda((int) $invoiceId, $user); return true;
    }

    public function getCompletedLevels($caseId)
    {
        $completed=array(); if((int)$caseId<=0)return $completed;
        $sql='SELECT DISTINCT level FROM '.MAIN_DB_PREFIX.'mahnwesen_history WHERE fk_case='.((int)$caseId)." AND result = 'success' AND action IN ('notice_sent', 'stage_skipped') AND level BETWEEN 1 AND 4";
        $resql=$this->db->query($sql); if(!$resql){$this->errors[]='Unable to read completed dunning stages: '.$this->db->lasterror();return $completed;}
        while($obj=$this->db->fetch_object($resql))$completed[(int)$obj->level]=true; $this->db->free($resql); return $completed;
    }
    public function getNextRequiredLevel($caseId,$calculatedLevel){$calculatedLevel=max(0,min(4,(int)$calculatedLevel));if($calculatedLevel<=0)return 0;$completed=$this->getCompletedLevels((int)$caseId);for($level=1;$level<=$calculatedLevel;$level++){ $rule=$this->getRuleByLevel($level);if(empty($rule['enabled']))continue;if(empty($completed[$level]))return $level;}return 0;}
    public function getHighestCompletedLevel($caseId){$completed=$this->getCompletedLevels((int)$caseId);return empty($completed)?0:max(array_keys($completed));}
    public function getStageCompletionTimestamp($caseId,$level){if((int)$caseId<=0||(int)$level<=0)return null;$sql='SELECT date_creation FROM '.MAIN_DB_PREFIX.'mahnwesen_history WHERE fk_case='.((int)$caseId).' AND level='.((int)$level)." AND result='success' AND action IN ('notice_sent','stage_skipped') ORDER BY rowid DESC".$this->db->plimit(1);$resql=$this->db->query($sql);if(!$resql)return null;$obj=$this->db->fetch_object($resql);$this->db->free($resql);return ($obj&&!empty($obj->date_creation))?(int)$this->db->jdate($obj->date_creation):null;}
    public function getPreviousEnabledLevel($level){for($previous=((int)$level)-1;$previous>=1;$previous--){$rule=$this->getRuleByLevel($previous);if(!empty($rule['enabled']))return $previous;}return 0;}
    public function calculateWorkflowStageDueAt($caseId,$dueYmd,$level){$calendarDue=$this->calculateStageDueAt($dueYmd,$level);if($calendarDue===null||(int)$level<=1||(int)$caseId<=0)return $calendarDue;$previous=$this->getPreviousEnabledLevel((int)$level);if($previous<=0)return $calendarDue;$completedAt=$this->getStageCompletionTimestamp((int)$caseId,$previous);if(empty($completedAt))return $calendarDue;$thresholds=$this->getStageThresholds();$gapDays=max(0,((int)($thresholds[(int)$level]??0))-((int)($thresholds[$previous]??0)));$afterPrevious=date('Y-m-d H:i:s',strtotime('+'.$gapDays.' days',$completedAt));return ((int)$this->db->jdate($afterPrevious)>(int)$this->db->jdate($calendarDue))?$afterPrevious:$calendarDue;}
    public function getNextFutureLevel($calculatedLevel){for($level=max(0,(int)$calculatedLevel)+1;$level<=4;$level++){if(!empty($this->getRuleByLevel($level)['enabled']))return $level;}return 0;}
    public function calculateStageDueAt($dueYmd,$level){$thresholds=$this->getStageThresholds();$level=(int)$level;if(empty($dueYmd)||!isset($thresholds[$level]))return null;try{$date=new DateTimeImmutable($dueYmd.' 00:00:00');return $date->modify('+'.((int)$thresholds[$level]).' days')->format('Y-m-d H:i:s');}catch(Exception $e){return null;}}
}
