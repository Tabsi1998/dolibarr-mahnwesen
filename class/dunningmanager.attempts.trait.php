<?php
/* Delivery attempts: reserve, record files, finalise, resolve. */
trait DunningManagerAttempts
{
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

    /** Id of a reserved, sending or ambiguous attempt of a stage, 0 if none, false on a database error. */
    public function getOpenAttemptId($caseId, $level)
    {
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE fk_case = '.((int) $caseId).' AND level = '.((int) $level);
        $sql .= " AND status IN ('reserved', 'sending', 'ambiguous') ORDER BY rowid".$this->db->plimit(1);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = 'Unable to read open delivery attempts: '.$this->db->lasterror();
            return false;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ? (int) $obj->rowid : 0;
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
        $profileId = (int) $evaluation['row']['profile_id'];
        $requiredLevel = $this->getNextRequiredLevel($lockedCase['id'], (int) $evaluation['row']['stage'], $profileId);
        $requiredAt = $requiredLevel > 0 ? $this->calculateWorkflowStageDueAt($lockedCase['id'], (string) $evaluation['row']['due_ymd'], $requiredLevel, $profileId) : null;
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
        if ((string) $mode === 'manual' || (string) $mode === 'postal') {
            if (!is_object($user) || !$user->hasRight('facture', 'lire') || !$user->hasRight('mahnwesen', 'notice', 'send')) {
                $this->error = 'User is not allowed to read or send notices for invoices.'; $this->db->rollback(); return false;
            }
            if (!$this->canSeeCustomer($user, (int) $lockedInvoice->socid)) { $this->error = 'Invoice is outside the user customer scope.'; $this->db->rollback(); return false; }
        }
        $contactId = !empty($snapshot['contact_id']) ? (int) $snapshot['contact_id'] : 0;
        if ((string) $mode === 'postal') {
            // A letter by post is for a customer without an email address (#39).
            $sqlRecipient = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'societe WHERE rowid = '.((int) $lockedInvoice->socid)." AND (email IS NULL OR email = '')".$this->db->plimit(1);
        } elseif ($contactId > 0) {
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
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_attempt (entity, fk_case, fk_facture, level, mode, status, recipient, fk_socpeople, sender, cc, bcc, subject, body_html, amount_invoice, amount_fee, amount_interest, amount_total, currency_code, fk_email_template, template_lang, reserved_at, fk_user_create) VALUES (';
        $sql .= $lockedCase['entity'].', '.$lockedCase['id'].', '.$lockedCase['invoice_id'].', '.((int) $level).", '".$this->db->escape((string) $mode)."', 'reserved', '".$this->db->escape((string) $recipient)."', ".(!empty($snapshot['contact_id']) ? (int) $snapshot['contact_id'] : 'NULL').", '".$this->db->escape((string) ($snapshot['sender'] ?? ''))."', '".$this->db->escape((string) ($snapshot['cc'] ?? ''))."', '".$this->db->escape((string) ($snapshot['bcc'] ?? ''))."', '".$this->db->escape((string) ($snapshot['subject'] ?? ''))."', '".$this->db->escape((string) ($snapshot['body_html'] ?? ''))."', ".$currentAmount.', '.((float) ($snapshot['fee'] ?? 0)).', '.((float) ($snapshot['interest'] ?? 0)).', '.((float) ($snapshot['total'] ?? $currentAmount)).", '".$this->db->escape((string) $conf->currency)."', ".(!empty($snapshot['template_id']) ? (int) $snapshot['template_id'] : 'NULL').", '".$this->db->escape((string) ($snapshot['template_lang'] ?? ''))."', '".$this->db->escape($nowSql)."', ".$uid.')';
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

    /** The subject and text of a reserved attempt, when they are only known after its reservation (#38). */
    public function updateNoticeAttemptMessage($attemptId, $subject, $bodyHtml)
    {
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_attempt SET subject = \''.$this->db->escape((string) $subject).'\', body_html = \''.$this->db->escape((string) $bodyHtml).'\' WHERE rowid = '.((int) $attemptId)." AND status = 'reserved'";
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return false; }
        return true;
    }

    /** Persist one exact attachment snapshot for an attempt. */
    public function addNoticeAttemptFile($attemptId, $role, $displayName, $path, $mime = '')
    {
        global $conf;
        $role = in_array($role, array('dunning', 'invoice', 'additional'), true) ? $role : 'additional';
        if ((int) $attemptId <= 0 || !is_file($path) || !is_readable($path)) { $this->error = 'Attachment snapshot is unavailable.'; return false; }
        $hash = hash_file('sha256', $path);
        $size = filesize($path);
        if ($hash === false || $size === false) { $this->error = 'Unable to hash attachment snapshot.'; return false; }
        $sqlCheck = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE rowid = '.((int) $attemptId).' AND entity = '.((int) $conf->entity)." AND status = 'reserved'".$this->db->plimit(1);
        $resCheck = $this->db->query($sqlCheck); $attempt = $resCheck ? $this->db->fetch_object($resCheck) : false; if ($resCheck) { $this->db->free($resCheck); }
        if (!$attempt) { $this->error = 'Reserved attempt not found for attachment snapshot.'; return false; }
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_attempt_file (entity, fk_attempt, file_role, display_name, snapshot_path, sha256, mime_type, size_bytes, date_creation) VALUES (';
        $sql .= ((int) $conf->entity).', '.((int) $attemptId).", '".$this->db->escape($role)."', '".$this->db->escape((string) $displayName)."', '".$this->db->escape((string) $path)."', '".$this->db->escape((string) $hash)."', '".$this->db->escape((string) $mime)."', ".((int) $size).", '".$this->db->escape($this->db->idate(dol_now()))."')";
        if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); return false; }
        return true;
    }

    /** Return one evidence file of the active entity with its attempt's invoice. */
    public function getNoticeAttemptFile($fileId)
    {
        global $conf;
        $sql = 'SELECT f.rowid, f.fk_attempt, f.file_role, f.display_name, f.snapshot_path, f.sha256, f.mime_type, f.size_bytes, a.fk_facture';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt_file f INNER JOIN '.MAIN_DB_PREFIX.'mahnwesen_attempt a ON a.rowid = f.fk_attempt AND a.entity = f.entity';
        $sql .= ' WHERE f.entity = '.((int) $conf->entity).' AND f.rowid = '.((int) $fileId).$this->db->plimit(1);
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return false; }
        $o = $this->db->fetch_object($res);
        $this->db->free($res);
        return $o ? (array) $o : null;
    }

    /** Return attachment metadata grouped by attempt id in one bounded query. */
    public function getNoticeAttemptFilesMap($attemptIds)
    {
        global $conf;
        $ids = array();
        foreach ((array) $attemptIds as $attemptId) { if ((int) $attemptId > 0) { $ids[(int) $attemptId] = (int) $attemptId; } }
        if (empty($ids)) { return array(); }
        $map = array();
        $sql = 'SELECT rowid, fk_attempt, file_role, display_name, snapshot_path, sha256, mime_type, size_bytes, date_creation FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt_file WHERE entity = '.((int) $conf->entity).' AND fk_attempt IN ('.implode(',', array_values($ids)).') ORDER BY fk_attempt, rowid';
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return false; }
        while ($o = $this->db->fetch_object($res)) {
            $attemptId = (int) $o->fk_attempt;
            if (!isset($map[$attemptId])) { $map[$attemptId] = array(); }
            $map[$attemptId][] = (array) $o;
        }
        $this->db->free($res);
        return $map;
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
     * @param int $attemptId Reserved attempt
     * @param bool $success True on SMTP success
     * @param string $message Audit message
     * @param array $case Stored case
     * @param User $user Acting user
     * @param bool $ambiguous The outcome is unknown
     * @param string $messageId Message-ID of the sent email
     * @return bool
     */
    /**
     * The events of a delivered notice: the notice itself, and the last stage
     * of its profile when that was the one sent (#59).
     *
     * @param object $attempt Attempt row
     * @param User $user Acting user
     * @return bool
     */
    protected function recordNoticeEvents($attempt, $user)
    {
        $entity = (int) $attempt->entity;
        $caseId = (int) $attempt->fk_case;
        $invoiceId = (int) $attempt->fk_facture;
        $level = (int) $attempt->level;
        if (!$this->recordEvent($entity, $caseId, $invoiceId, 'MAHNWESEN_NOTICE_SENT', $level, $user)) {
            return false;
        }
        $profileId = (int) $this->resolveProfile($invoiceId)['profile_id'];
        $enabled = array_keys(array_filter($this->getEnabledLevels($profileId)));
        if ($enabled && $level >= max($enabled)) {
            return $this->recordEvent($entity, $caseId, $invoiceId, 'MAHNWESEN_CASE_FINAL_STAGE', $level, $user);
        }
        return true;
    }

    /**
     * Delete mail texts and attachment copies that are older than the
     * retention period; keep what proves the delivery (#42).
     *
     * Without a period nothing is deleted. Date, recipient, subject, size and
     * checksum of every attempt stay, so an old delivery can still be shown
     * and checked; only the body and the copied files go.
     *
     * @param User|null $user Acting user
     * @param int $limit How many attempts at most
     * @return array|false Counts, or false on a database error
     */
    public function applyRetention($user = null, $limit = 200)
    {
        global $conf;
        $days = getDolGlobalInt('MAHNWESEN_RETENTION_DAYS', 0);
        $result = array('days' => $days, 'bodies' => 0, 'files' => 0);
        if ($days <= 0) {
            return $result;
        }
        $limit = max(1, min(1000, (int) $limit));
        $olderThan = "'".$this->db->escape($this->db->idate(dol_now() - ($days * 86400)))."'";
        $where = ' WHERE entity = '.((int) $conf->entity)." AND status IN ('sent', 'failed', 'resolved', 'ambiguous')";
        $where .= ' AND reserved_at < '.$olderThan;
        // The files first: their rows keep name, size and checksum (#42).
        $sql = 'SELECT f.rowid, f.snapshot_path FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt_file as f';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'mahnwesen_attempt as a ON a.rowid = f.fk_attempt';
        $sql .= ' WHERE f.entity = '.((int) $conf->entity).' AND a.reserved_at < '.$olderThan;
        $sql .= " AND f.snapshot_path <> '' ORDER BY f.rowid ASC".$this->db->plimit($limit);
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $files = array();
        while ($o = $this->db->fetch_object($res)) {
            $files[(int) $o->rowid] = (string) $o->snapshot_path;
        }
        $this->db->free($res);
        foreach ($files as $fileId => $path) {
            if ($path !== '' && is_file($path) && !@unlink($path)) {
                $this->errors[] = 'Unable to delete the archived copy '.$path;
                continue;
            }
            if (!$this->db->query('UPDATE '.MAIN_DB_PREFIX."mahnwesen_attempt_file SET snapshot_path = '' WHERE rowid = ".((int) $fileId))) {
                $this->error = $this->db->lasterror();
                return false;
            }
            $result['files']++;
        }
        // Then the mail bodies; everything that proves the delivery stays.
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt'.$where." AND body_html <> '' ORDER BY rowid ASC".$this->db->plimit($limit);
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $attempts = array();
        while ($o = $this->db->fetch_object($res)) {
            $attempts[] = (int) $o->rowid;
        }
        $this->db->free($res);
        if ($attempts) {
            if (!$this->db->query('UPDATE '.MAIN_DB_PREFIX."mahnwesen_attempt SET body_html = '' WHERE rowid IN (".implode(',', $attempts).')')) {
                $this->error = $this->db->lasterror();
                return false;
            }
            $result['bodies'] = count($attempts);
        }
        return $result;
    }

    public function finalizeNoticeAttempt($attemptId, $success, $message, $case, $user, $ambiguous = false, $messageId = '')
    {
        $this->db->begin();

        $sqlLock = 'SELECT rowid, entity, fk_case, fk_facture, level, mode, recipient, amount_invoice, amount_fee, amount_interest, currency_code FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE rowid = '.((int) $attemptId)." AND status IN ('reserved', 'sending') FOR UPDATE";
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
            if (!$this->recordNoticeEvents($attempt, $user)) { $this->db->rollback(); return false; }
            $sqlCase = "UPDATE ".MAIN_DB_PREFIX."mahnwesen_case SET last_notice_at = '".$this->db->escape($this->db->idate(dol_now()))."', fk_user_modif = ".((is_object($user) && isset($user->id)) ? (int) $user->id : 0)." WHERE rowid = ".((int) $case['id']);
            if (!$this->db->query($sqlCase)) {
                $this->error = $this->db->lasterror();
                $this->db->rollback();
                return false;
            }
            if (!$this->bookNoticeFee($attempt, (int) $attemptId, $uid, $nowSql)) { $this->db->rollback(); return false; }
        }

        $this->db->commit();
        // Events and Agenda are projections: both after the authoritative
        // transaction committed, so neither can roll back or duplicate the
        // dunning workflow state (#59).
        $this->dispatchEvents($user, 20);
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
        $sql = 'SELECT rowid, fk_case, fk_facture, level, mode, status, recipient, sender, cc, bcc, subject, body_html, amount_invoice, amount_fee, amount_total, currency_code, fk_email_template, template_lang, pdf_path, pdf_sha256, invoice_pdf_path, invoice_pdf_sha256, mail_message_id, error_message, reserved_at, sent_at, resolved_at FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE entity = '.((int) $conf->entity).' ORDER BY reserved_at DESC, rowid DESC'.$this->db->plimit(max(1, min(1000, (int) $limit)));
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
        $sql = 'SELECT rowid, fk_case, fk_facture, level, mode, status, recipient, sender, cc, bcc, subject, body_html, amount_invoice, amount_fee, amount_total, currency_code, fk_email_template, template_lang, pdf_path, pdf_sha256, invoice_pdf_path, invoice_pdf_sha256, mail_message_id, error_message, reserved_at, sent_at, resolved_at FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE entity = '.((int) $conf->entity).' AND rowid = '.((int) $attemptId).$this->db->plimit(1);
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
        $sql = 'SELECT rowid, entity, fk_case, fk_facture, level, mode, recipient, amount_invoice, amount_fee, amount_interest, currency_code, reserved_at FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE rowid = '.((int) $attemptId).' AND entity = '.((int) $conf->entity).' AND status IN '.$allowedStatuses;
        $sql .= " AND (status IN ('ambiguous', 'failed') OR reserved_at <= '".$this->db->escape($recoveryCutoff)."') FOR UPDATE";
        $res = $this->db->query($sql); $attempt = $res ? $this->db->fetch_object($res) : false;
        if (!$attempt) { if ($res) { $this->db->free($res); } $this->error = 'Recoverable attempt not found or the 15-minute safety delay has not elapsed.'; $this->db->rollback(); return false; }
        $this->db->free($res);
        $permissionInvoice = new Facture($this->db);
        if ($permissionInvoice->fetch((int) $attempt->fk_facture) <= 0) { $this->error = 'Invoice for the attempt could not be loaded.'; $this->db->rollback(); return false; }
        if (!$this->canSeeCustomer($user, (int) $permissionInvoice->socid)) { $this->error = 'Invoice is outside the user customer scope.'; $this->db->rollback(); return false; }
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
            // The notice went out when it was attempted. A later notice of a
            // higher stage keeps its date (#17).
            $attemptedSql = $this->db->escape((string) $attempt->reserved_at);
            $sql = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_case SET last_notice_at = CASE WHEN last_notice_at IS NULL OR last_notice_at < '".$attemptedSql."' THEN '".$attemptedSql."' ELSE last_notice_at END, fk_user_modif = ".$uid.' WHERE rowid = '.((int) $attempt->fk_case);
            if (!$this->db->query($sql)) { $this->error = $this->db->lasterror(); $this->db->rollback(); return false; }
            if (!$this->bookNoticeFee($attempt, (int) $attemptId, $uid, $nowSql)) { $this->db->rollback(); return false; }
            if (!$this->recordNoticeEvents($attempt, $user)) { $this->db->rollback(); return false; }
        }
        $this->db->commit();
        $this->syncHistoryToAgenda((int) $attempt->fk_facture, $user);
        $this->dispatchEvents($user, 20);
        return true;
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

    /** Count unresolved automatic failures so transient faults can retry safely. */
    public function getAutomaticFailureCount($caseId, $level)
    {
        $sql = 'SELECT COUNT(*) as cnt FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE fk_case = '.((int) $caseId).' AND level = '.((int) $level)." AND mode = 'automatic' AND status = 'failed'";
        $res = $this->db->query($sql);
        if (!$res) { return PHP_INT_MAX; }
        $o = $this->db->fetch_object($res); $this->db->free($res);
        return $o ? (int) $o->cnt : 0;
    }
}
