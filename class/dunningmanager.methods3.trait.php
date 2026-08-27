<?php
/* Auto-split method trait for maintainable source files. */
trait DunningManagerMethods3
{

    /** Calendar state + sequential workflow state for one invoice. */
    public function getWorkflowState($invoiceId)
    {
        $evaluation = $this->evaluateInvoice((int) $invoiceId);
        if ($evaluation === false) { return false; }
        $case = $this->getCaseByInvoice((int) $invoiceId);
        $calculated = !empty($evaluation['eligible']) ? (int) $evaluation['row']['stage'] : 0;
        $caseId = $case ? (int) $case['id'] : 0;
        $required = $this->getNextRequiredLevel($caseId, $calculated);
        $future = $this->getNextFutureLevel($calculated);
        $dueYmd = !empty($evaluation['row']['due_ymd']) ? (string) $evaluation['row']['due_ymd'] : '';
        $requiredAt = $required > 0 ? $this->calculateWorkflowStageDueAt($caseId, $dueYmd, $required) : null;
        $futureAt = $future > 0 ? $this->calculateWorkflowStageDueAt($caseId, $dueYmd, $future) : null;
        $requiredReached = ($requiredAt === null || ((int) $this->db->jdate($requiredAt)) <= dol_now());
        return array(
            'evaluation' => $evaluation,
            'case' => $case,
            'calculated_level' => $calculated,
            'next_required_level' => $required,
            'next_future_level' => $future,
            'completed_levels' => $this->getCompletedLevels($caseId),
            'required_at' => $requiredAt,
            'future_at' => $futureAt,
            'actionable' => ($case && $case['status'] === 'open' && empty($case['paused']) && !empty($evaluation['eligible']) && $required > 0 && $requiredReached) ? 1 : 0,
        );
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

    /**
     * Record a final dunning PDF generated into the invoice document area.
     * Preview PDFs are deliberately not audited.
     *
     * @param int $invoiceId Invoice id
     * @param int $level Dunning stage
     * @param string $relative Relative file path inside invoice documents
     * @param User $user Acting user
     * @param string $mode manual|automatic
     * @return bool
     */
    public function recordGeneratedDocument($invoiceId, $level, $relative, $user, $mode = 'manual')
    {
        $case = $this->getCaseByInvoice((int) $invoiceId);
        if (!$case) {
            $this->error = 'No dunning case exists for this invoice';
            return false;
        }
        $evaluation = $this->evaluateInvoice((int) $invoiceId);
        $amount = ($evaluation && isset($evaluation['remain_to_pay'])) ? (float) $evaluation['remain_to_pay'] : (float) $case['remaining_amount'];
        $message = 'PDF: '.trim((string) $relative);
        if (!$this->addHistory((int) $case['entity'], (int) $case['id'], (int) $invoiceId, 'document_generated', (int) $level, $amount, (string) $mode, 'success', $message, $user)) {
            return false;
        }
        $this->syncHistoryToAgenda((int) $invoiceId, $user);
        return true;
    }

    public function hasSuccessfulNoticeAtLevel($caseId, $level)
    {
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_history';
        $sql .= ' WHERE fk_case = '.((int) $caseId);
        $sql .= " AND action = 'notice_sent' AND result = 'success'";
        $sql .= ' AND level = '.((int) $level);
        $sql .= $this->db->plimit(1);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $found = (bool) $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $found;
    }

    /**
     * Check whether a send attempt is currently reserved for one case/level.
     * A pending reservation intentionally blocks retries if the process dies
     * after SMTP accepted the message but before audit finalization completed.
     *
     * @param int $caseId Case id
     * @param int $level Level
     * @return bool
     */
    public function hasPendingNoticeAtLevel($caseId, $level)
    {
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt';
        $sql .= ' WHERE fk_case = '.((int) $caseId);
        $sql .= " AND status IN ('reserved', 'sending', 'ambiguous')";
        $sql .= ' AND level = '.((int) $level);
        $sql .= $this->db->plimit(1);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $found = (bool) $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $found;
    }

    /**
     * Reserve a manual notice send before calling the irreversible mailer.
     * The case row is locked so two concurrent requests cannot both reserve
     * the same dunning level.
     *
     * @param array $case Stored case
     * @param string $recipient Recipient email
     * @param int $level Level
     * @param float $amount Amount snapshot
     * @param string $message Audit message
     * @param User $user Acting user
     * @param array $snapshot Immutable content/configuration snapshot
     * @return array|false Attempt and freshly validated case snapshot
     */
    public function reserveNoticeAttempt($case, $recipient, $level, $amount, $message, $user, $mode = 'manual', $snapshot = array())
    {
        global $conf;
        $this->db->begin();

        // Serialize reservations for this dunning case.
        $sqlLock = 'SELECT rowid, entity, fk_facture, current_level, paused, status, remaining_amount, last_notice_at, next_action_at FROM '.MAIN_DB_PREFIX.'mahnwesen_case';
        $sqlLock .= ' WHERE rowid = '.((int) $case['id']).' AND entity = '.((int) $conf->entity).' FOR UPDATE';
        $resLock = $this->db->query($sqlLock);
        $locked = $resLock ? $this->db->fetch_object($resLock) : false;
        if (!$resLock || !$locked) {
            $this->error = $this->db->lasterror() ?: 'Unable to lock dunning case for send reservation';
            if ($resLock) {
                $this->db->free($resLock);
            }
            $this->db->rollback();
            return false;
        }
        $this->db->free($resLock);

        $lockedCase = array(
            'id' => (int) $locked->rowid,
            'entity' => (int) $locked->entity,
            'invoice_id' => (int) $locked->fk_facture,
            'current_level' => (int) $locked->current_level,
            'paused' => (int) $locked->paused,
            'status' => (string) $locked->status,
            'remaining_amount' => (float) $locked->remaining_amount,
            'last_notice_at' => $locked->last_notice_at,
            'next_action_at' => $locked->next_action_at,
        );
        if ($lockedCase['invoice_id'] !== (int) $case['invoice_id'] || $lockedCase['status'] !== 'open' || !empty($lockedCase['paused'])) {
            $this->error = 'Dunning case changed, is closed or is paused.';
            $this->db->rollback();
            return false;
        }

        // Re-read the invoice while the case is locked. Every irreversible
        // path, including cron, must pass this one eligibility gate.
        $evaluation = $this->evaluateInvoice($lockedCase['invoice_id']);
        if ($evaluation === false || empty($evaluation['eligible'])) {
            $this->error = $evaluation === false ? ($this->error ?: 'Invoice evaluation failed.') : 'Invoice is no longer eligible for dunning: '.$evaluation['reason'];
            $this->db->rollback();
            return false;
        }
        $currentAmount = (float) $evaluation['remain_to_pay'];
        $requiredLevel = $this->getNextRequiredLevel($lockedCase['id'], (int) $evaluation['row']['stage']);
        $requiredAt = $requiredLevel > 0 ? $this->calculateWorkflowStageDueAt($lockedCase['id'], (string) $evaluation['row']['due_ymd'], $requiredLevel) : null;
        if ($requiredLevel !== (int) $level || ($requiredAt && (int) $this->db->jdate($requiredAt) > dol_now())) {
            $this->error = 'Dunning stage is no longer due or the sequential cooldown is active.';
            $this->db->rollback();
            return false;
        }
        if (abs($currentAmount - (float) $amount) > 0.000001) {
            $this->error = 'Outstanding amount changed. Reload and review the notice again.';
            $this->db->rollback();
            return false;
        }
        $lockedCase['remaining_amount'] = $currentAmount;
        $lockedInvoice = new Facture($this->db);
        if ($lockedInvoice->fetch($lockedCase['invoice_id']) <= 0) { $this->error = 'Unable to reload invoice for fee validation.'; $this->db->rollback(); return false; }
        $lockedInvoice->fetch_thirdparty();
        if ((string) $mode === 'manual') {
            if (!is_object($user) || !$user->hasRight('facture', 'lire') || !$user->hasRight('mahnwesen', 'notice', 'send')) {
                $this->error = 'User is not allowed to read or send notices for invoices.'; $this->db->rollback(); return false;
            }
            if (!$user->hasRight('societe', 'client', 'voir')) {
                $sqlVisibility = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'societe_commerciaux WHERE fk_soc = '.((int) $lockedInvoice->socid).' AND fk_user = '.((int) $user->id).$this->db->plimit(1);
                $resVisibility = $this->db->query($sqlVisibility); $visible = $resVisibility ? $this->db->fetch_object($resVisibility) : false;
                if ($resVisibility) { $this->db->free($resVisibility); }
                if (!$visible) { $this->error = 'Invoice is outside the user customer scope.'; $this->db->rollback(); return false; }
            }
        }
        $contactId = !empty($snapshot['contact_id']) ? (int) $snapshot['contact_id'] : 0;
        if ($contactId > 0) {
            $sqlRecipient = 'SELECT sp.rowid FROM '.MAIN_DB_PREFIX.'element_contact ec INNER JOIN '.MAIN_DB_PREFIX.'c_type_contact tc ON tc.rowid = ec.fk_c_type_contact INNER JOIN '.MAIN_DB_PREFIX.'socpeople sp ON sp.rowid = ec.fk_socpeople';
            $sqlRecipient .= ' WHERE ec.element_id = '.$lockedCase['invoice_id']." AND tc.element = 'facture' AND tc.source = 'external' AND tc.code = 'BILLING' AND sp.statut = 1 AND sp.rowid = ".$contactId." AND LOWER(sp.email) = LOWER('".$this->db->escape((string) $recipient)."')".$this->db->plimit(1);
        } else {
            $sqlRecipient = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.((int) $lockedInvoice->socid)." AND LOWER(email) = LOWER('".$this->db->escape((string) $recipient)."')".$this->db->plimit(1);
        }
        $resRecipient = $this->db->query($sqlRecipient); $validRecipient = $resRecipient ? $this->db->fetch_object($resRecipient) : false;
        if ($resRecipient) { $this->db->free($resRecipient); }
        if (!$validRecipient) { $this->error = 'Recipient changed or is no longer eligible.'; $this->db->rollback(); return false; }
        $currentBreakdown = $this->getAmountBreakdown($lockedInvoice, $lockedCase, (int) $level);
        if (isset($snapshot['fee']) && abs((float) $snapshot['fee'] - (float) $currentBreakdown['fee']) > 0.000001) {
            $this->error = 'Dunning fee changed. Reload and review the notice again.';
            $this->db->rollback();
            return false;
        }

        $sqlCheck = 'SELECT rowid, action, result FROM '.MAIN_DB_PREFIX.'mahnwesen_history';
        $sqlCheck .= ' WHERE fk_case = '.$lockedCase['id'].' AND level = '.((int) $level);
        $sqlCheck .= " AND action = 'notice_sent' AND result = 'success'";
        $sqlCheck .= $this->db->plimit(1);
        $resCheck = $this->db->query($sqlCheck);
        if (!$resCheck) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return false;
        }
        $existing = $this->db->fetch_object($resCheck);
        $this->db->free($resCheck);
        if ($existing) {
            $this->error = 'A successful notice already exists for this dunning level.';
            $this->db->rollback();
            return false;
        }

        $sqlCheck = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE fk_case = '.$lockedCase['id'].' AND level = '.((int) $level)." AND status IN ('reserved', 'sending', 'ambiguous')".$this->db->plimit(1);
        $resCheck = $this->db->query($sqlCheck);
        if (!$resCheck) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
        $existing = $this->db->fetch_object($resCheck);
        $this->db->free($resCheck);
        if ($existing) { $this->error = 'A notice send is already pending or has an ambiguous delivery state.'; $this->db->rollback(); return false; }

        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $nowSql = $this->db->idate(dol_now());
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_attempt (entity, fk_case, fk_facture, level, mode, status, recipient, fk_socpeople, sender, cc, bcc, subject, body_html, amount_invoice, amount_fee, amount_total, currency_code, fk_email_template, template_lang, reserved_at, fk_user_create) VALUES (';
        $sql .= $lockedCase['entity'].', '.$lockedCase['id'].', '.$lockedCase['invoice_id'].', '.((int) $level).", '".$this->db->escape((string) $mode)."', 'reserved', '".$this->db->escape((string) $recipient)."', ".(!empty($snapshot['contact_id']) ? (int) $snapshot['contact_id'] : 'NULL').", '".$this->db->escape((string) ($snapshot['sender'] ?? ''))."', '".$this->db->escape((string) ($snapshot['cc'] ?? ''))."', '".$this->db->escape((string) ($snapshot['bcc'] ?? ''))."', '".$this->db->escape((string) ($snapshot['subject'] ?? ''))."', '".$this->db->escape((string) ($snapshot['body_html'] ?? ''))."', ".$currentAmount.', '.((float) ($snapshot['fee'] ?? 0)).', '.((float) ($snapshot['total'] ?? $currentAmount)).", '".$this->db->escape((string) $conf->currency)."', ".(!empty($snapshot['template_id']) ? (int) $snapshot['template_id'] : 'NULL').", '".$this->db->escape((string) ($snapshot['template_lang'] ?? ''))."', '".$this->db->escape($nowSql)."', ".$uid.')';
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return false;
        }
        $attemptId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'mahnwesen_attempt');
        if ($attemptId <= 0) {
            $this->error = 'Unable to get send attempt id';
            $this->db->rollback();
            return false;
        }

        $historyMessage = 'attempt_id='.$attemptId."\n".(string) $message;
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_history (entity, fk_case, fk_facture, action, level, amount_snapshot, mode, result, recipient, message, date_creation, fk_user_create) VALUES (';
        $sql .= $lockedCase['entity'].', '.$lockedCase['id'].', '.$lockedCase['invoice_id'].", 'attempt_reserved', ".((int) $level).', '.$currentAmount.", '".$this->db->escape((string) $mode)."', 'success', '".$this->db->escape((string) $recipient)."', '".$this->db->escape($historyMessage)."', '".$this->db->escape($nowSql)."', ".$uid.')';
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }

        $this->db->commit();
        return array('id' => $attemptId, 'case' => $lockedCase, 'amount' => $currentAmount, 'breakdown' => $currentBreakdown, 'required_at' => $requiredAt);
    }

    /** Persist attachment paths and hashes before handing control to SMTP. */
    public function updateNoticeAttemptArtifacts($attemptId, $pdfInfo, $invoicePdf = '')
    {
        $pdfHash = (!empty($pdfInfo['fullpath']) && is_readable($pdfInfo['fullpath'])) ? hash_file('sha256', $pdfInfo['fullpath']) : '';
        $invoiceHash = ($invoicePdf !== '' && is_readable($invoicePdf)) ? hash_file('sha256', $invoicePdf) : '';
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_attempt SET pdf_path = \''.$this->db->escape((string) ($pdfInfo['relative'] ?? '')).'\', pdf_sha256 = \''.$this->db->escape((string) $pdfHash).'\', invoice_pdf_path = \''.$this->db->escape((string) $invoicePdf).'\', invoice_pdf_sha256 = \''.$this->db->escape((string) $invoiceHash).'\' WHERE rowid = '.((int) $attemptId)." AND status = 'reserved'";
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return false; }
        $sqlCheck = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE rowid = '.((int) $attemptId)." AND status = 'reserved'".$this->db->plimit(1);
        $resCheck = $this->db->query($sqlCheck); $current = $resCheck ? $this->db->fetch_object($resCheck) : false;
        if ($resCheck) { $this->db->free($resCheck); }
        if (!$current) { $this->error = 'Send attempt changed while attachments were generated.'; return false; }
        return true;
    }

    /** Mark the exact point at which an SMTP outcome may become ambiguous. */
    public function markNoticeAttemptSending($attemptId)
    {
        $sql = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_attempt SET status = 'sending' WHERE rowid = ".((int) $attemptId)." AND status = 'reserved'";
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return false; }
        $sqlCheck = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE rowid = '.((int) $attemptId)." AND status = 'sending'".$this->db->plimit(1);
        $resCheck = $this->db->query($sqlCheck); $current = $resCheck ? $this->db->fetch_object($resCheck) : false;
        if ($resCheck) { $this->db->free($resCheck); }
        if (!$current) { $this->error = 'Send attempt changed before SMTP delivery.'; return false; }
        return true;
    }

    /**
     * Finalize a previously reserved send attempt.
     * On successful SMTP delivery, history and last_notice_at are committed
     * together. If this finalization fails, the pending reservation remains
     * and blocks an unsafe retry.
     *
     * @param int $historyId Reservation history id
     * @param bool $success True on SMTP success
     * @param string $message Audit message
     * @param array $case Stored case
     * @param User $user Acting user
     * @return bool
     */
    public function finalizeNoticeAttempt($attemptId, $success, $message, $case, $user, $ambiguous = false, $messageId = '')
    {
        $this->db->begin();

        $sqlLock = 'SELECT rowid, entity, fk_case, fk_facture, level, mode, recipient, amount_invoice, amount_fee, currency_code FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE rowid = '.((int) $attemptId)." AND status IN ('reserved', 'sending') FOR UPDATE";
        $resLock = $this->db->query($sqlLock);
        $attempt = $resLock ? $this->db->fetch_object($resLock) : false;
        if (!$resLock || !$attempt) {
            $this->error = $this->db->lasterror() ?: 'Pending send attempt not found during audit finalization';
            if ($resLock) {
                $this->db->free($resLock);
            }
            $this->db->rollback();
            return false;
        }
        $this->db->free($resLock);

        $status = $success ? 'sent' : ($ambiguous ? 'ambiguous' : 'failed');
        $action = $success ? 'notice_sent' : ($ambiguous ? 'notice_ambiguous' : 'notice_failed');
        $result = $success ? 'success' : ($ambiguous ? 'pending' : 'failed');
        $nowSql = $this->db->idate(dol_now());
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_attempt SET status = \''.$this->db->escape($status).'\', error_message = \''.$this->db->escape($success ? '' : (string) $message).'\', mail_message_id = \''.$this->db->escape((string) $messageId).'\'';
        if ($success) { $sql .= ", sent_at = '".$this->db->escape($nowSql)."'"; }
        $sql .= ' WHERE rowid = '.((int) $attemptId)." AND status IN ('reserved', 'sending')";
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return false;
        }

        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $auditMessage = 'attempt_id='.((int) $attemptId)."\n".(string) $message;
        $sqlHistory = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_history (entity, fk_case, fk_facture, action, level, amount_snapshot, mode, result, recipient, message, date_creation, fk_user_create) VALUES (';
        $sqlHistory .= ((int) $attempt->entity).', '.((int) $attempt->fk_case).', '.((int) $attempt->fk_facture).", '".$this->db->escape($action)."', ".((int) $attempt->level).', '.((float) $attempt->amount_invoice).", '".$this->db->escape((string) $attempt->mode)."', '".$this->db->escape($result)."', '".$this->db->escape((string) $attempt->recipient)."', '".$this->db->escape($auditMessage)."', '".$this->db->escape($nowSql)."', ".$uid.')';
        if (!$this->db->query($sqlHistory)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
        unset($this->completedLevelsCache[(int) $attempt->fk_case]);

        if ($success) {
            $sqlCase = "UPDATE ".MAIN_DB_PREFIX."mahnwesen_case SET last_notice_at = '".$this->db->escape($this->db->idate(dol_now()))."', fk_user_modif = ".((is_object($user) && isset($user->id)) ? (int) $user->id : 0)." WHERE rowid = ".((int) $case['id']);
            if (!$this->db->query($sqlCase)) {
                $this->error = $this->db->lasterror();
                $this->db->rollback();
                return false;
            }
            if ((float) $attempt->amount_fee > 0.000001) {
                $sqlSupersede = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_fee SET status = 'superseded', date_settlement = '".$this->db->escape($nowSql)."', settlement_reason = '".$this->db->escape('Replaced by attempt #'.((int) $attemptId))."', fk_user_settlement = ".$uid.' WHERE entity = '.((int) $attempt->entity).' AND fk_case = '.((int) $attempt->fk_case)." AND status = 'open'";
                if (!$this->db->query($sqlSupersede)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
                $sqlFee = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_fee (entity, fk_case, fk_facture, fk_attempt, level, amount, currency_code, status, date_creation, fk_user_create) VALUES (';
                $sqlFee .= ((int) $attempt->entity).', '.((int) $attempt->fk_case).', '.((int) $attempt->fk_facture).', '.((int) $attemptId).', '.((int) $attempt->level).', '.((float) $attempt->amount_fee).", '".$this->db->escape((string) $attempt->currency_code)."', 'open', '".$this->db->escape($nowSql)."', ".$uid.')';
                if (!$this->db->query($sqlFee)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
            }
        }

        $this->db->commit();
        // Agenda is a user-facing projection only. Mirror after the authoritative
        // transaction committed so an Agenda problem can never roll back or
        // duplicate the dunning workflow state.
        $this->syncHistoryToAgenda((int) $case['invoice_id'], $user);
        return true;
    }

    /** Return recent attempts for the active entity. */
    public function getNoticeAttempts($limit = 200)
    {
        global $conf;
        $rows = array();
        $sql = 'SELECT rowid, fk_case, fk_facture, level, mode, status, recipient, sender, subject, amount_invoice, amount_fee, amount_total, currency_code, pdf_path, pdf_sha256, error_message, reserved_at, sent_at, resolved_at FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE entity = '.((int) $conf->entity).' ORDER BY reserved_at DESC, rowid DESC'.$this->db->plimit(max(1, min(1000, (int) $limit)));
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return false; }
        while ($o = $this->db->fetch_object($res)) { $rows[] = (array) $o; }
        $this->db->free($res);
        return $rows;
    }

    /** Return one attempt from the active entity without a recency limit. */
    public function getNoticeAttempt($attemptId)
    {
        global $conf;
        $sql = 'SELECT rowid, fk_case, fk_facture, level, mode, status, recipient, sender, subject, amount_invoice, amount_fee, amount_total, currency_code, pdf_path, pdf_sha256, error_message, reserved_at, sent_at, resolved_at FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE entity = '.((int) $conf->entity).' AND rowid = '.((int) $attemptId).$this->db->plimit(1);
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return false; }
        $o = $this->db->fetch_object($res); $this->db->free($res);
        return $o ? (array) $o : null;
    }

    /** Resolve an ambiguous attempt after an operator checked the mailbox. */
    public function resolveNoticeAttempt($attemptId, $resolution, $reason, $user)
    {
        global $conf;
        if (!in_array($resolution, array('confirmed_sent', 'allow_retry'), true) || trim((string) $reason) === '') { $this->error = 'A valid resolution and reason are required.'; return false; }
        if (!is_object($user) || !method_exists($user, 'hasRight') || !$user->hasRight('facture', 'lire') || !$user->hasRight('mahnwesen', 'case', 'write') || !$user->hasRight('mahnwesen', 'notice', 'send')) { $this->error = 'User is not allowed to resolve delivery attempts.'; return false; }
        $this->db->begin();
        $allowedStatuses = $resolution === 'confirmed_sent' ? "('ambiguous', 'sending')" : "('ambiguous', 'failed', 'reserved', 'sending')";
        $recoveryCutoff = $this->db->idate(dol_now() - 900);
        $sql = 'SELECT rowid, entity, fk_case, fk_facture, level, mode, recipient, amount_invoice, amount_fee, currency_code FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE rowid = '.((int) $attemptId).' AND entity = '.((int) $conf->entity).' AND status IN '.$allowedStatuses;
        $sql .= " AND (status IN ('ambiguous', 'failed') OR reserved_at <= '".$this->db->escape($recoveryCutoff)."') FOR UPDATE";
        $res = $this->db->query($sql); $attempt = $res ? $this->db->fetch_object($res) : false;
        if (!$attempt) { if ($res) { $this->db->free($res); } $this->error = 'Recoverable attempt not found or the 15-minute safety delay has not elapsed.'; $this->db->rollback(); return false; }
        $this->db->free($res);
        $permissionInvoice = new Facture($this->db);
        if ($permissionInvoice->fetch((int) $attempt->fk_facture) <= 0) { $this->error = 'Invoice for the attempt could not be loaded.'; $this->db->rollback(); return false; }
        if (!$user->hasRight('societe', 'client', 'voir')) {
            $sqlVisibility = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'societe_commerciaux WHERE fk_soc = '.((int) $permissionInvoice->socid).' AND fk_user = '.((int) $user->id).$this->db->plimit(1);
            $resVisibility = $this->db->query($sqlVisibility); $visible = $resVisibility ? $this->db->fetch_object($resVisibility) : false; if ($resVisibility) { $this->db->free($resVisibility); }
            if (!$visible) { $this->error = 'Invoice is outside the user customer scope.'; $this->db->rollback(); return false; }
        }
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $nowSql = $this->db->idate(dol_now());
        $newStatus = $resolution === 'confirmed_sent' ? 'sent' : 'resolved';
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_attempt SET status = \''.$newStatus.'\', resolved_at = \''.$this->db->escape($nowSql).'\', fk_user_resolve = '.$uid.', error_message = \''.$this->db->escape((string) $reason).'\'';
        if ($resolution === 'confirmed_sent') { $sql .= ", sent_at = COALESCE(sent_at, '".$this->db->escape($nowSql)."')"; }
        $sql .= ' WHERE rowid = '.((int) $attemptId).' AND status IN '.$allowedStatuses;
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
        unset($this->completedLevelsCache[(int) $attempt->fk_case]);
        $action = $resolution === 'confirmed_sent' ? 'notice_sent' : 'attempt_retry_allowed';
        $result = $resolution === 'confirmed_sent' ? 'success' : 'success';
        $msg = 'attempt_id='.((int) $attemptId)."\nresolution=".$resolution."\nreason=".(string) $reason;
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_history (entity, fk_case, fk_facture, action, level, amount_snapshot, mode, result, recipient, message, date_creation, fk_user_create) VALUES ('.((int) $attempt->entity).', '.((int) $attempt->fk_case).', '.((int) $attempt->fk_facture).", '".$action."', ".((int) $attempt->level).', '.((float) $attempt->amount_invoice).", 'manual', '".$result."', '".$this->db->escape((string) $attempt->recipient)."', '".$this->db->escape($msg)."', '".$this->db->escape($nowSql)."', ".$uid.')';
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
        if ($resolution === 'confirmed_sent') {
            $sql = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_case SET last_notice_at = '".$this->db->escape($nowSql)."', fk_user_modif = ".$uid.' WHERE rowid = '.((int) $attempt->fk_case);
            if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
            if ((float) $attempt->amount_fee > 0.000001) {
                $sqlSupersede = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_fee SET status = 'superseded', date_settlement = '".$this->db->escape($nowSql)."', settlement_reason = '".$this->db->escape('Replaced by attempt #'.((int) $attemptId))."', fk_user_settlement = ".$uid.' WHERE entity = '.((int) $attempt->entity).' AND fk_case = '.((int) $attempt->fk_case)." AND status = 'open'";
                if (!$this->db->query($sqlSupersede)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
                $sqlFee = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_fee (entity, fk_case, fk_facture, fk_attempt, level, amount, currency_code, status, date_creation, fk_user_create) VALUES ('.((int) $attempt->entity).', '.((int) $attempt->fk_case).', '.((int) $attempt->fk_facture).', '.((int) $attemptId).', '.((int) $attempt->level).', '.((float) $attempt->amount_fee).", '".$this->db->escape((string) $attempt->currency_code)."', 'open', '".$this->db->escape($nowSql)."', ".$uid.')';
                if (!$this->db->query($sqlFee)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
            }
        }
        $this->db->commit();
        $this->syncHistoryToAgenda((int) $attempt->fk_facture, $user);
        return true;
    }

    /** Return fee claims tracked by the module for the active entity. */
    public function getFeeClaims($status = '', $limit = 300)
    {
        global $conf;
        $rows = array();
        $sql = 'SELECT rowid, fk_case, fk_facture, fk_attempt, level, amount, currency_code, status, date_creation, date_settlement, settlement_reason FROM '.MAIN_DB_PREFIX.'mahnwesen_fee WHERE entity = '.((int) $conf->entity);
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
        if (!$user->hasRight('societe', 'client', 'voir')) {
            $sqlVisibility = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'societe_commerciaux WHERE fk_soc = '.((int) $permissionInvoice->socid).' AND fk_user = '.((int) $user->id).$this->db->plimit(1);
            $resVisibility = $this->db->query($sqlVisibility); $visible = $resVisibility ? $this->db->fetch_object($resVisibility) : false; if ($resVisibility) { $this->db->free($resVisibility); }
            if (!$visible) { $this->error = 'Invoice is outside the user customer scope.'; $this->db->rollback(); return false; }
        }
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
