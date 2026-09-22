<?php
/* Where a case stands: completed stages, the next stage and when it becomes due, and how that reads for users. */
trait DunningManagerWorkflow
{
    /**
     * Calendar state + sequential workflow state for one invoice.
     *
     * With $simulate the case is taken as the daily synchronisation would
     * leave it, without writing: a missing case is new, a case that is not
     * open opens again, a pause whose date has passed ends (#16).
     */
    public function getWorkflowState($invoiceId, $simulate = false)
    {
        $evaluation = $this->evaluateInvoice((int) $invoiceId);
        if ($evaluation === false) { return false; }
        $case = $this->getCaseByInvoice((int) $invoiceId);
        if ($simulate && !empty($evaluation['eligible'])) {
            if (!$case) {
                $case = array('id' => 0, 'invoice_id' => (int) $invoiceId, 'status' => 'open', 'paused' => 0,
                    'current_level' => (int) $evaluation['row']['stage'], 'remaining_amount' => (float) $evaluation['remain_to_pay']);
            } elseif ($case['status'] !== 'open') {
                $case['status'] = 'open';
            }
            if (!empty($case['paused']) && !empty($case['id']) && $this->isPauseExpired((int) $case['id'])) {
                $case['paused'] = 0;
            }
        }
        $calculated = !empty($evaluation['eligible']) ? (int) $evaluation['row']['stage'] : 0;
        $caseId = $case ? (int) $case['id'] : 0;
        $profileId = (int) $evaluation['row']['profile_id'];
        $required = $this->getNextRequiredLevel($caseId, $calculated, $profileId);
        $future = $this->getNextFutureLevel($calculated, $profileId);
        $dueYmd = !empty($evaluation['row']['due_ymd']) ? (string) $evaluation['row']['due_ymd'] : '';
        $requiredAt = $required > 0 ? $this->calculateWorkflowStageDueAt($caseId, $dueYmd, $required, $profileId) : null;
        $futureAt = $future > 0 ? $this->calculateWorkflowStageDueAt($caseId, $dueYmd, $future, $profileId) : null;
        $requiredReached = ($requiredAt === null || ((int) $this->db->jdate($requiredAt)) <= dol_now());
        $block = $this->getDunningBlock((int) $invoiceId);
        return array(
            'evaluation' => $evaluation,
            'case' => $case,
            'calculated_level' => $calculated,
            'next_required_level' => $required,
            'next_future_level' => $future,
            'completed_levels' => $this->getCompletedLevels($caseId),
            'required_at' => $requiredAt,
            'future_at' => $futureAt,
            'profile_id' => $profileId,
            'profile' => $evaluation['profile'],
            'block' => $block,
            'actionable' => ($case && $case['status'] === 'open' && empty($case['paused']) && $block === null && !empty($evaluation['eligible']) && $required > 0 && $requiredReached) ? 1 : 0,
        );
    }

    /**
     * Check idempotence guard for successful sends of one case/level.
     *
     * @param int $caseId Case id
     * @param int $level Level
     * @return bool
     */
    /**
     * The dunning block of the invoice or its customer that applies today (#37).
     *
     * Dolibarr's own fields hold it: "Nicht mahnen", an optional last day and a
     * reason, on the invoice and on the customer; the invoice's block is named
     * first. A block that cannot be read counts as a block.
     *
     * @param int $invoiceId Invoice id
     * @return array|null scope (invoice, customer or unreadable), reason, until (YYYY-MM-DD or '')
     */
    public function getDunningBlock($invoiceId)
    {
        $sql = 'SELECT '.$this->dunningBlockColumns().' FROM '.MAIN_DB_PREFIX.'facture as f'
            .' LEFT JOIN '.MAIN_DB_PREFIX.'facture_extrafields as fe ON fe.fk_object = f.rowid'
            .' LEFT JOIN '.MAIN_DB_PREFIX.'societe_extrafields as se ON se.fk_object = f.fk_soc'
            .' WHERE f.rowid = '.((int) $invoiceId);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = 'Unable to read the dunning block: '.$this->db->lasterror();
            return array('scope' => 'unreadable', 'reason' => '', 'until' => '');
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $this->blockFromRow($obj);
    }

    /**
     * The columns that say whether a block applies today, for a query that joins
     * the invoice's extra fields as fe and the customer's as se (#37).
     *
     * @return string
     */
    public function dunningBlockColumns()
    {
        return $this->dunningBlockSql('fe').' as invoice_blocked, fe.mahnwesen_block_until as invoice_block_until, fe.mahnwesen_block_reason as invoice_block_reason, '
            .$this->dunningBlockSql('se').' as customer_blocked, se.mahnwesen_block_until as customer_block_until, se.mahnwesen_block_reason as customer_block_reason';
    }

    /**
     * SQL that is 1 when the block in the extra fields under $alias applies
     * today: switched on, and its last day, if any, not passed (#37).
     *
     * @param string $alias fe or se
     * @return string
     */
    public function dunningBlockSql($alias)
    {
        $alias = ($alias === 'se') ? 'se' : 'fe';
        $today = $this->db->escape(dol_print_date(dol_get_first_hour(dol_now(), 'tzserver'), '%Y-%m-%d', 'tzserver'));
        return '(CASE WHEN '.$alias.'.mahnwesen_block = 1 AND ('.$alias.'.mahnwesen_block_until IS NULL OR '.$alias.".mahnwesen_block_until >= '".$today."') THEN 1 ELSE 0 END)";
    }

    /**
     * The block in a row with the columns of dunningBlockColumns(), or null.
     *
     * @param object|null|false $obj Database row
     * @return array|null
     */
    public function blockFromRow($obj)
    {
        foreach (array('invoice', 'customer') as $scope) {
            if ($obj && !empty($obj->{$scope.'_blocked'})) {
                $until = (string) $obj->{$scope.'_block_until'};
                return array('scope' => $scope, 'reason' => (string) $obj->{$scope.'_block_reason'}, 'until' => $until !== '' ? substr($until, 0, 10) : '');
            }
        }
        return null;
    }

    /**
     * The block in one phrase, for example "Mahnsperre am Kunden bis
     * 31.12.2026: Ratenzahlung vereinbart". HTML, the reason escaped.
     *
     * @param array $block From getDunningBlock()
     * @return string
     */
    public function describeBlock($block)
    {
        global $langs;
        if ($block['scope'] === 'unreadable') {
            return $langs->trans('MahnwesenBlockUnreadable');
        }
        $html = $langs->trans($block['scope'] === 'invoice' ? 'MahnwesenBlockOnInvoice' : 'MahnwesenBlockOnCustomer').' '
            .($block['until'] !== '' ? $langs->trans('MahnwesenBlockUntilDate', dol_print_date($this->db->jdate($block['until']), 'day')) : $langs->trans('MahnwesenBlockIndefinite'));
        if (trim($block['reason']) !== '') {
            $html .= ': '.dol_escape_htmltag($block['reason']);
        }
        return $html;
    }

    /** Return completed workflow stages (successful send or audited skip). */
    public function getCompletedLevels($caseId)
    {
        $completed = array();
        if ((int) $caseId <= 0) { return $completed; }
        if (isset($this->completedLevelsCache[(int) $caseId])) { return $this->completedLevelsCache[(int) $caseId]; }
        $sql = 'SELECT DISTINCT level FROM '.MAIN_DB_PREFIX.'mahnwesen_history';
        $sql .= ' WHERE fk_case = '.((int) $caseId);
        $sql .= " AND result = 'success' AND action IN ('notice_sent', 'stage_skipped')";
        $sql .= ' AND level BETWEEN 1 AND 4';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = 'Unable to read completed dunning stages: '.$this->db->lasterror();
            return $completed;
        }
        while ($obj = $this->db->fetch_object($resql)) { $completed[(int) $obj->level] = true; }
        $this->db->free($resql);
        $this->completedLevelsCache[(int) $caseId] = $completed;
        return $completed;
    }

    /** Drop request-local workflow caches before the final SMTP safety check. */
    public function refreshWorkflowCaches($caseId = 0)
    {
        $this->forgetProfiles();
        if ((int) $caseId > 0) { unset($this->completedLevelsCache[(int) $caseId]); }
        else { $this->completedLevelsCache = array(); }
    }

    /** First due, enabled, incomplete stage. This is the anti-skip guard. */
    public function getNextRequiredLevel($caseId, $calculatedLevel, $profileId = 0)
    {
        if ((int) $calculatedLevel <= 0) { return 0; }
        return MahnwesenWorkflowPolicy::nextRequiredLevel($calculatedLevel, $this->getEnabledLevels($profileId), $this->getCompletedLevels((int) $caseId));
    }

    /** Highest completed stage. */
    public function getHighestCompletedLevel($caseId)
    {
        $completed = $this->getCompletedLevels((int) $caseId);
        return empty($completed) ? 0 : max(array_keys($completed));
    }

    /**
     * Timestamp of the successful completion (send or audited skip) of a stage.
     * Used to prevent rapid catch-up escalation when earlier stages were sent late.
     *
     * @param int $caseId Case id
     * @param int $level Stage
     * @return int|null Unix timestamp
     */
    public function getStageCompletionTimestamp($caseId, $level)
    {
        if ((int) $caseId <= 0 || (int) $level <= 0) { return null; }
        $sql = 'SELECT date_creation FROM '.MAIN_DB_PREFIX.'mahnwesen_history';
        $sql .= ' WHERE fk_case = '.((int) $caseId).' AND level = '.((int) $level);
        $sql .= " AND result = 'success' AND action IN ('notice_sent', 'stage_skipped')";
        $sql .= ' ORDER BY rowid DESC'.$this->db->plimit(1);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = 'Unable to read dunning-stage completion date: '.$this->db->lasterror();
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return ($obj && !empty($obj->date_creation)) ? (int) $this->db->jdate($obj->date_creation) : null;
    }

    /** When the last successful notice of a stage was sent, null if none was. */
    public function getStageNoticeTimestamp($caseId, $level)
    {
        if ((int) $caseId <= 0 || (int) $level <= 0) { return null; }
        $sql = 'SELECT date_creation FROM '.MAIN_DB_PREFIX.'mahnwesen_history';
        $sql .= ' WHERE fk_case = '.((int) $caseId).' AND level = '.((int) $level);
        $sql .= " AND result = 'success' AND action = 'notice_sent'";
        $sql .= ' ORDER BY rowid DESC'.$this->db->plimit(1);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->errors[] = 'Unable to read the dunning notice date: '.$this->db->lasterror();
            return null;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return ($obj && !empty($obj->date_creation)) ? (int) $this->db->jdate($obj->date_creation) : null;
    }

    /** Return the closest earlier enabled stage, or 0 if this is the first one. */
    public function getPreviousEnabledLevel($level, $profileId = 0)
    {
        return MahnwesenWorkflowPolicy::previousEnabledLevel($level, $this->getEnabledLevels($profileId));
    }

    /**
     * Effective workflow due date for a stage.
     *
     * Besides the absolute invoice threshold, preserve the configured spacing
     * between two enabled stages after the previous stage was ACTUALLY completed.
     * Example with thresholds 3/10 days: if the reminder is sent late on day 14,
     * the 1st dunning notice becomes actionable no earlier than the start of day
     * 21, not the next cron run. The spacing counts whole days, so a reminder
     * sent in the afternoon does not push the nightly cron to day 22 (#18). This prevents a delayed case from receiving several escalating
     * notices in rapid succession.
     *
     * A sent notice that named a payment deadline also holds the next stage
     * back until the day after that deadline (#64).
     */
    public function calculateWorkflowStageDueAt($caseId, $dueYmd, $level, $profileId = 0)
    {
        $calendarDue = $this->calculateStageDueAt($dueYmd, $level, $profileId);
        if ($calendarDue === null || (int) $level <= 1 || (int) $caseId <= 0) { return $calendarDue; }

        $previous = $this->getPreviousEnabledLevel((int) $level, $profileId);
        if ($previous <= 0) { return $calendarDue; }
        $completedAt = $this->getStageCompletionTimestamp((int) $caseId, $previous);
        if (empty($completedAt)) { return $calendarDue; }

        $thresholds = $this->getStageThresholds($profileId);
        $gapDays = max(0, ((int) ($thresholds[(int) $level] ?? 0)) - ((int) ($thresholds[$previous] ?? 0)));
        $paymentDays = $this->getPaymentDaysForLevel($previous, $profileId);
        $sentAt = $paymentDays > 0 ? $this->getStageNoticeTimestamp((int) $caseId, $previous) : null;
        return MahnwesenWorkflowPolicy::spacedDueAt($calendarDue, $completedAt, $gapDays, $paymentDays, $sentAt);
    }

    /** Next enabled stage whose calendar threshold has not been reached yet. */
    public function getNextFutureLevel($calculatedLevel, $profileId = 0)
    {
        return MahnwesenWorkflowPolicy::nextFutureLevel($calculatedLevel, $this->getEnabledLevels($profileId));
    }

    /** Date at which a concrete stage of a profile becomes due. */
    public function calculateStageDueAt($dueYmd, $level, $profileId = 0)
    {
        $thresholds = $this->getStageThresholds($profileId);
        return MahnwesenWorkflowPolicy::stageDueAt($dueYmd, isset($thresholds[(int) $level]) ? $thresholds[(int) $level] : null);
    }

    /**
     * Whether the daily run would lift the pause of a case now.
     *
     * The same rule as resumeExpiredPauses(): an active pause whose date has
     * passed, or, for pauses from before the pause table, the case's own date.
     *
     * @param int $caseId Case
     * @return bool
     */
    public function isPauseExpired($caseId)
    {
        global $conf;
        $nowSql = $this->db->escape($this->db->idate(dol_now()));
        $sql = 'SELECT c.rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_case c WHERE c.rowid = '.((int) $caseId).' AND c.entity = '.((int) $conf->entity).' AND c.paused = 1 AND (';
        $sql .= 'EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX."mahnwesen_pause p WHERE p.fk_case = c.rowid AND p.entity = c.entity AND p.status = 'active' AND p.pause_until IS NOT NULL AND p.pause_until <= '".$nowSql."')";
        $sql .= " OR (c.next_action_at IS NOT NULL AND c.next_action_at <= '".$nowSql."' AND NOT EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."mahnwesen_pause p2 WHERE p2.fk_case = c.rowid AND p2.entity = c.entity AND p2.status = 'active')))";
        $res = $this->db->query($sql);
        if (!$res) {
            return false;
        }
        $found = (bool) $this->db->fetch_object($res);
        $this->db->free($res);
        return $found;
    }

    /**
     * The next step as one phrase: "2. Mahnung (zeitlich wäre bereits die 3. Mahnung erreicht)".
     *
     * @param int $calculatedLevel Stage reached by the calendar
     * @param int $requiredLevel Next stage the workflow allows
     * @param int $futureLevel Next stage not reached yet
     * @return string HTML
     */
    public function describeNextStep($calculatedLevel, $requiredLevel, $futureLevel)
    {
        global $langs;
        if ((int) $requiredLevel > 0) {
            $html = '<strong>'.$langs->trans($this->getStageLabelKey((int) $requiredLevel)).'</strong>';
            if ((int) $calculatedLevel > (int) $requiredLevel) {
                $html .= ' <span class="opacitymedium">'.$langs->trans('MahnwesenNextStepBehind', $langs->trans($this->getStageLabelKey((int) $calculatedLevel))).'</span>';
            }
            return $html;
        }
        if ((int) $futureLevel > 0) {
            return '<span class="opacitymedium">'.$langs->trans('MahnwesenNoDueWorkflowStage').'</span>';
        }
        return '<span class="badge badge-status4">'.$langs->trans('MahnwesenWorkflowComplete').'</span>';
    }

    /**
     * The amount to pay, with its parts only when a fee applies.
     *
     * @param array $breakdown From getAmountBreakdown()
     * @return string HTML
     */
    public function describeAmountDue($breakdown)
    {
        global $langs, $conf;
        $html = '<strong>'.price((float) $breakdown['total'], 0, $langs, 1, -1, -1, $conf->currency).'</strong>';
        if ((float) $breakdown['fee'] > 0.000001) {
            $html .= ' <span class="opacitymedium">'.$langs->trans('MahnwesenAmountParts',
                price((float) $breakdown['invoice'], 0, $langs, 1, -1, -1, $conf->currency),
                price((float) $breakdown['fee'], 0, $langs, 1, -1, -1, $conf->currency)).'</span>';
        }
        return $html;
    }
}
