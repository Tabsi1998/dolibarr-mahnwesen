<?php
/* The fee ledger: fees and interest booked by delivered notices, paid or waived. */
trait DunningManagerFees
{
    /**
     * Book the fee of a delivered notice inside the caller's transaction.
     *
     * The fee of a stage is the total owed from that stage on, so a new fee
     * replaces the open fees of its own and lower stages. A notice confirmed
     * late, after a higher stage already charged its fee, is recorded as
     * superseded at once and never displaces the higher fee (#17).
     */
    protected function bookNoticeFee($attempt, $attemptId, $uid, $nowSql)
    {
        if (!$this->bookNoticeInterest($attempt, $attemptId, $uid, $nowSql)) { return false; }
        if ((float) $attempt->amount_fee <= 0.000001) { return true; }
        $where = ' WHERE entity = '.((int) $attempt->entity).' AND fk_case = '.((int) $attempt->fk_case);
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_fee'.$where." AND kind = 'fee' AND level > ".((int) $attempt->level)." AND status <> 'superseded' ORDER BY rowid".$this->db->plimit(1);
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return false; }
        $higher = $this->db->fetch_object($res);
        $this->db->free($res);
        $status = 'open';
        $settlement = 'NULL, NULL, NULL';
        if ($higher) {
            $status = 'superseded';
            $settlement = "'".$this->db->escape($nowSql)."', '".$this->db->escape('Fee #'.((int) $higher->rowid).' of a higher stage already applies')."', ".((int) $uid);
        } else {
            $sql = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_fee SET status = 'superseded', date_settlement = '".$this->db->escape($nowSql)."', settlement_reason = '".$this->db->escape('Replaced by attempt #'.((int) $attemptId))."', fk_user_settlement = ".((int) $uid).$where." AND kind = 'fee' AND status = 'open' AND level <= ".((int) $attempt->level);
            if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return false; }
        }
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_fee (entity, fk_case, fk_facture, fk_attempt, level, kind, amount, currency_code, status, date_creation, fk_user_create, date_settlement, settlement_reason, fk_user_settlement) VALUES (';
        $sql .= ((int) $attempt->entity).', '.((int) $attempt->fk_case).', '.((int) $attempt->fk_facture).', '.((int) $attemptId).', '.((int) $attempt->level).", 'fee', ".((float) $attempt->amount_fee).", '".$this->db->escape((string) $attempt->currency_code)."', '".$status."', '".$this->db->escape($nowSql)."', ".((int) $uid).', '.$settlement.')';
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return false; }
        return true;
    }

    /**
     * Book the interest a delivered notice named (#33).
     *
     * Interest grows with every day, so a new claim replaces the open interest
     * claim of the same case; nothing is added twice.
     */
    protected function bookNoticeInterest($attempt, $attemptId, $uid, $nowSql)
    {
        if (!isset($attempt->amount_interest) || (float) $attempt->amount_interest <= 0.000001) { return true; }
        $where = ' WHERE entity = '.((int) $attempt->entity).' AND fk_case = '.((int) $attempt->fk_case)." AND kind = 'interest'";
        $sql = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_fee SET status = 'superseded', date_settlement = '".$this->db->escape($nowSql)."', settlement_reason = '".$this->db->escape('Replaced by the interest of attempt #'.((int) $attemptId))."', fk_user_settlement = ".((int) $uid).$where." AND status = 'open'";
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return false; }
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_fee (entity, fk_case, fk_facture, fk_attempt, level, kind, amount, currency_code, status, date_creation, fk_user_create) VALUES (';
        $sql .= ((int) $attempt->entity).', '.((int) $attempt->fk_case).', '.((int) $attempt->fk_facture).', '.((int) $attemptId).', '.((int) $attempt->level).", 'interest', ".((float) $attempt->amount_interest).", '".$this->db->escape((string) $attempt->currency_code)."', 'open', '".$this->db->escape($nowSql)."', ".((int) $uid).')';
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return false; }
        return true;
    }

    /** Return fee claims tracked by the module for the active entity. */
    public function getFeeClaims($status = '', $limit = 300)
    {
        global $conf;
        $rows = array();
        $sql = 'SELECT rowid, fk_case, fk_facture, fk_attempt, level, kind, amount, currency_code, status, date_creation, date_settlement, settlement_reason FROM '.MAIN_DB_PREFIX.'mahnwesen_fee WHERE entity = '.((int) $conf->entity);
        if (in_array($status, array('open', 'paid', 'waived', 'superseded'), true)) { $sql .= " AND status = '".$this->db->escape($status)."'"; }
        $sql .= ' ORDER BY date_creation DESC, rowid DESC'.$this->db->plimit(max(1, min(1000, (int) $limit)));
        $res = $this->db->query($sql); if (!$res) { $this->error = $this->db->lasterror(); return false; }
        while ($o = $this->db->fetch_object($res)) { $rows[] = (array) $o; }
        $this->db->free($res); return $rows;
    }

    /** Return one module fee claim from the active entity. */
    public function getFeeClaim($feeId)
    {
        global $conf;
        $sql = 'SELECT rowid, fk_case, fk_facture, fk_attempt, level, amount, currency_code, status, date_creation, date_settlement, settlement_reason FROM '.MAIN_DB_PREFIX.'mahnwesen_fee WHERE entity = '.((int) $conf->entity).' AND rowid = '.((int) $feeId).$this->db->plimit(1);
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return false; }
        $o = $this->db->fetch_object($res); $this->db->free($res);
        return $o ? (array) $o : null;
    }

    /** Mark a module fee claim as paid or waived with a mandatory reason. */
    public function settleFeeClaim($feeId, $status, $reason, $user)
    {
        global $conf;
        if (!in_array($status, array('paid', 'waived'), true) || trim((string) $reason) === '') { $this->error = 'A valid fee status and reason are required.'; return false; }
        if (!is_object($user) || !method_exists($user, 'hasRight') || !$user->hasRight('facture', 'lire') || !$user->hasRight('mahnwesen', 'case', 'write')) { $this->error = 'User is not allowed to settle fee claims.'; return false; }
        $this->db->begin();
        $sql = 'SELECT rowid, fk_case, fk_facture, level, amount FROM '.MAIN_DB_PREFIX.'mahnwesen_fee WHERE rowid = '.((int) $feeId).' AND entity = '.((int) $conf->entity)." AND status = 'open' FOR UPDATE";
        $res = $this->db->query($sql); $fee = $res ? $this->db->fetch_object($res) : false;
        if (!$fee) { if ($res) { $this->db->free($res); } $this->error = 'Open fee claim not found.'; $this->db->rollback(); return false; }
        $this->db->free($res);
        $permissionInvoice = new Facture($this->db);
        if ($permissionInvoice->fetch((int) $fee->fk_facture) <= 0) { $this->error = 'Invoice for the fee claim could not be loaded.'; $this->db->rollback(); return false; }
        if (!$this->canSeeCustomer($user, (int) $permissionInvoice->socid)) { $this->error = 'Invoice is outside the user customer scope.'; $this->db->rollback(); return false; }
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0; $nowSql = $this->db->idate(dol_now());
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_fee SET status = \''.$status.'\', date_settlement = \''.$this->db->escape($nowSql).'\', settlement_reason = \''.$this->db->escape((string) $reason).'\', fk_user_settlement = '.$uid.' WHERE rowid = '.((int) $feeId)." AND status = 'open'";
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
        $historyAction = $status === 'paid' ? 'fee_paid' : 'fee_waived';
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_history (entity, fk_case, fk_facture, action, level, amount_snapshot, mode, result, message, date_creation, fk_user_create) VALUES ('.((int) $conf->entity).', '.((int) $fee->fk_case).', '.((int) $fee->fk_facture).", '".$historyAction."', ".((int) $fee->level).', '.((float) $fee->amount).", 'manual', 'success', '".$this->db->escape((string) $reason)."', '".$this->db->escape($nowSql)."', ".$uid.')';
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
        $sqlOpen = 'SELECT COUNT(*) as cnt FROM '.MAIN_DB_PREFIX.'mahnwesen_fee WHERE entity = '.((int) $conf->entity).' AND fk_case = '.((int) $fee->fk_case)." AND status = 'open'";
        $resOpen = $this->db->query($sqlOpen); $open = $resOpen ? $this->db->fetch_object($resOpen) : false; if ($resOpen) { $this->db->free($resOpen); }
        if ($open && (int) $open->cnt === 0) {
            $sqlCase = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_case SET status = 'closed', next_action_at = NULL, fk_user_modif = ".$uid.' WHERE rowid = '.((int) $fee->fk_case)." AND status = 'fee_open'";
            if (!$this->db->query($sqlCase)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
        }
        $this->db->commit(); $this->syncHistoryToAgenda((int) $fee->fk_facture, $user); return true;
    }
}
