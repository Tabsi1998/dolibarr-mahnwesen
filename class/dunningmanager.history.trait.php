<?php
/* The module's own history of a case, and Dolibarr's agenda as its visible copy. */
trait DunningManagerHistory
{
    /**
     * Return history entries for an invoice.
     *
     * @param int $invoiceId Customer invoice id
     * @param int $limit Max rows
     * @return array
     */
    public function getHistoryByInvoice($invoiceId, $limit = 100)
    {
        global $conf;
        $limit = max(1, min(500, (int) $limit));
        $rows = array();
        $sql = 'SELECT rowid, fk_case, fk_facture, action, level, amount_snapshot, mode, result, recipient, message, date_creation, fk_user_create';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'mahnwesen_history';
        $sql .= ' WHERE fk_facture = '.((int) $invoiceId);
        $sql .= ' AND entity = '.((int) $conf->entity);
        $sql .= ' ORDER BY date_creation DESC, rowid DESC';
        $sql .= $this->db->plimit($limit);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return $rows;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'id' => (int) $obj->rowid,
                'case_id' => (int) $obj->fk_case,
                'invoice_id' => (int) $obj->fk_facture,
                'action' => (string) $obj->action,
                'level' => (int) $obj->level,
                'amount_snapshot' => (float) $obj->amount_snapshot,
                'mode' => (string) $obj->mode,
                'result' => (string) $obj->result,
                'recipient' => (string) $obj->recipient,
                'message' => (string) $obj->message,
                'date_creation' => $obj->date_creation,
                'fk_user_create' => (int) $obj->fk_user_create,
            );
        }
        $this->db->free($resql);
        return $rows;
    }

    /** Return the recent immutable workflow history for the active entity. */
    public function getRecentHistory($limit = 300)
    {
        global $conf;
        $rows = array();
        $sql = 'SELECT rowid, fk_case, fk_facture, action, level, amount_snapshot, mode, result, recipient, message, date_creation, fk_user_create FROM '.MAIN_DB_PREFIX.'mahnwesen_history WHERE entity = '.((int) $conf->entity).' ORDER BY date_creation DESC, rowid DESC'.$this->db->plimit(max(1, min(1000, (int) $limit)));
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return false; }
        while ($o = $this->db->fetch_object($res)) { $rows[] = (array) $o; }
        $this->db->free($res);
        return $rows;
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
        // Other modules learn of the change through its event (#59).
        $types = $this->getEventTypes();
        if ($result === 'success' && isset($types[$action])) {
            return $this->recordEvent((int) $entity, (int) $caseId, (int) $invoiceId, $types[$action], (int) $level, $user);
        }
        return true;
    }

    /**
     * Mirror module audit history into Dolibarr Agenda. The module table remains
     * the workflow source of truth; ActionComm is an idempotent user-facing
     * projection linked to the customer invoice.
     */
    public function syncHistoryToAgenda($invoiceId, $fallbackUser = null, $limit = 250)
    {
        global $langs, $conf;
        if (!isModEnabled('agenda')) { return 0; }
        require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
        $invoice = new Facture($this->db);
        if ($invoice->fetch((int) $invoiceId) <= 0) { return 0; }
        $invoice->fetch_thirdparty();
        $history = $this->getHistoryByInvoice((int) $invoiceId, max(1, min(1000, (int) $limit)));
        if (empty($history)) { return 0; }
        $history = array_reverse($history);
        $created = 0;
        foreach ($history as $row) {
            if ((string) $row['action'] === 'notice_sending' && (string) $row['result'] === 'pending') { continue; }
            $refExt = 'mahnwesen-history-'.((int) $row['id']);
            $sql = 'SELECT id FROM '.MAIN_DB_PREFIX."actioncomm WHERE ref_ext = '".$this->db->escape($refExt)."'".$this->db->plimit(1);
            $res = $this->db->query($sql);
            if ($res) {
                $exists = (bool) $this->db->fetch_object($res);
                $this->db->free($res);
                if ($exists) { continue; }
            }
            $actor = null;
            if (!empty($row['fk_user_create'])) {
                $tmpuser = new User($this->db);
                if ($tmpuser->fetch((int) $row['fk_user_create']) > 0) { $actor = $tmpuser; }
            }
            if (!$actor && $fallbackUser instanceof User) { $actor = $fallbackUser; }
            if (!$actor) { $actor = new User($this->db); $actor->id = 0; }
            $event = new ActionComm($this->db);
            $event->type_code = 'AC_OTH_AUTO';
            $event->code = 'AC_OTH_AUTO';
            $event->label = $this->getAgendaLabelForHistory($row);
            $event->note_private = $this->getAgendaNoteForHistory($row);
            $event->datep = !empty($row['date_creation']) ? $this->db->jdate($row['date_creation']) : dol_now();
            $event->datef = $event->datep;
            $event->percentage = ActionComm::EVENT_FINISHED;
            $event->userownerid = isset($actor->id) ? (int) $actor->id : 0;
            $event->socid = (int) $invoice->socid;
            $event->elementid = (int) $invoice->id;
            $event->fk_element = (int) $invoice->id;
            $event->elementtype = 'facture';
            $event->ref_ext = $refExt;
            $event->extraparams = array('mahnwesen_history_id' => (int) $row['id'], 'mahnwesen_action' => (string) $row['action']);
            if (!empty($row['recipient'])) { $event->email_to = (string) $row['recipient']; }
            $mailMeta = $this->getHistoryMailMetadata((string) $row['message']);
            if (property_exists($event, 'email_subject') && !empty($mailMeta['subject'])) { $event->email_subject = $mailMeta['subject']; }
            if (property_exists($event, 'email_from') && !empty($mailMeta['from'])) { $event->email_from = $mailMeta['from']; }
            if (property_exists($event, 'email_tocc') && !empty($mailMeta['cc'])) { $event->email_tocc = $mailMeta['cc']; }
            if (property_exists($event, 'email_tobcc') && !empty($mailMeta['bcc'])) { $event->email_tobcc = $mailMeta['bcc']; }
            $result = $event->create($actor, 1);
            if ($result > 0) { $created++; }
            else { dol_syslog(__METHOD__.' Unable to mirror history #'.((int) $row['id']).': '.$event->error, LOG_WARNING); }
        }
        return $created;
    }

    /** Extract email metadata stored in notice audit text without changing old history rows. */
    protected function getHistoryMailMetadata($message)
    {
        $result = array('subject' => '', 'from' => '', 'cc' => '', 'bcc' => '');
        $prefixes = array('Betreff:' => 'subject', 'Subject:' => 'subject', 'Von:' => 'from', 'From:' => 'from', 'CC:' => 'cc', 'BCC:' => 'bcc');
        foreach (preg_split('/\r?\n/', (string) $message) as $line) {
            foreach ($prefixes as $prefix => $key) {
                if (strpos($line, $prefix) === 0) {
                    $result[$key] = trim(substr($line, strlen($prefix)));
                    break;
                }
            }
        }
        return $result;
    }

    /** Return the translation key for one immutable Mahnwesen history action. */
    public function getHistoryActionLabelKey($action)
    {
        $map = array(
            'case_created' => 'HistoryActionCaseCreated',
            'case_reopened' => 'HistoryActionCaseReopened',
            'case_closed' => 'HistoryActionCaseClosed',
            'level_changed' => 'HistoryActionLevelChanged',
            'paused' => 'HistoryActionPaused',
            'resumed' => 'HistoryActionResumed',
            'auto_resumed' => 'MahnwesenHistoryActionAutoResumed',
            'note_changed' => 'HistoryActionNoteChanged',
            'notice_sending' => 'HistoryActionNoticeSending',
            'attempt_reserved' => 'MahnwesenHistoryActionAttemptReserved',
            'notice_sent' => 'HistoryActionNoticeSent',
            'notice_failed' => 'HistoryActionNoticeFailed',
            'notice_ambiguous' => 'MahnwesenHistoryActionNoticeAmbiguous',
            'attempt_retry_allowed' => 'MahnwesenHistoryActionRetryAllowed',
            'invoice_paid_fee_open' => 'MahnwesenHistoryActionInvoicePaidFeeOpen',
            'fee_paid' => 'MahnwesenHistoryActionFeePaid',
            'fee_waived' => 'MahnwesenHistoryActionFeeWaived',
            'stage_skipped' => 'HistoryActionStageSkipped',
            'document_generated' => 'MahnwesenHistoryActionDocumentGenerated',
        );
        return isset($map[(string) $action]) ? $map[(string) $action] : 'HistoryActionOther';
    }

    protected function getAgendaLabelForHistory($row)
    {
        global $langs;
        $base = $langs->trans($this->getHistoryActionLabelKey((string) $row['action']));
        if ((int) $row['level'] > 0) {
            $base .= ' - '.$langs->trans($this->getStageLabelKey((int) $row['level']));
        }
        return $base;
    }

    protected function getAgendaNoteForHistory($row)
    {
        global $langs, $conf;
        $lines = array();
        if ((int) $row['level'] > 0) {
            $lines[] = $langs->trans('DunningStage').': '.$langs->trans($this->getStageLabelKey((int) $row['level']));
        }
        $lines[] = $langs->trans('Amount').': '.price((float) $row['amount_snapshot'], 0, $langs, 1, -1, -1, $conf->currency);
        if (!empty($row['recipient'])) {
            $lines[] = $langs->trans('NoticeRecipient').': '.$row['recipient'];
        }
        if (!empty($row['result'])) {
            $lines[] = $langs->trans('MahnwesenHistoryResult').': '.$langs->trans('MahnwesenHistoryResult'.ucfirst((string) $row['result']));
        }
        if (trim((string) $row['message']) !== '') {
            $lines[] = '';
            $lines[] = (string) $row['message'];
        }
        return implode("\n", $lines);
    }
}
