<?php
/* Auto-split method trait for maintainable source files. */
trait DunningManagerMethods1
{
    /** @var DoliDB */
    public $db;

    /** @var string */
    public $error = '';

    /** @var array */
    public $errors = array();

    /** @var string Cron output */
    public $output = '';

    /** @var array Diagnostic counters from last scan */
    public $diagnostics = array();

    /** @var array|null Cached stage rules */
    protected $rulesCache = null;

    /** @var array<int,array<int,bool>> Completed-stage cache for one request. */
    protected $completedLevelsCache = array();

    /**
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Scan overdue invoices without changing data.
     *
     * The SQL query applies cheap, deterministic filters before the scan limit.
     * The PHP checks remain as a second safety layer around Dolibarr's balance
     * calculation and invoice-type semantics.
     *
     * @param int $limit Maximum number of overdue invoice candidates fetched
     * @param User|null $visibilityUser Restrict rows to the user's customer visibility; null is the entity-local cron scope
     * @return array|false Rows or false on database error
     */
    public function scanDueInvoices($limit = 0, $visibilityUser = null)
    {
        global $conf;
        $this->error = '';
        $this->errors = array();
        $this->diagnostics = array();

        $limit = (int) $limit;
        if ($limit <= 0) {
            $limit = $this->getMaxScan();
        }

        $todayStart = dol_get_first_hour(dol_now(), 'tzserver');
        $todaySql = $this->db->idate($todayStart);
        $todayYmd = dol_print_date($todayStart, '%Y-%m-%d', 'tzserver');
        if (empty($todayYmd)) {
            $todayYmd = date('Y-m-d');
        }

        $minAmount = $this->getMinimumAmount();
        $this->diagnostics = $this->loadRawDiagnostics($todaySql, $visibilityUser);
        $this->diagnostics['scan_limit'] = $limit;
        $this->diagnostics['minimum_amount'] = $minAmount;
        $this->diagnostics['include_deposits'] = $this->includeDepositInvoices() ? 1 : 0;
        $this->diagnostics['scanned_candidates'] = 0;
        $this->diagnostics['excluded_type'] = 0;
        $this->diagnostics['excluded_no_balance'] = 0;
        $this->diagnostics['excluded_below_minimum'] = 0;
        $this->diagnostics['excluded_invalid_due_date'] = 0;
        $this->diagnostics['excluded_fetch_error'] = 0;
        $this->diagnostics['included'] = 0;
        $this->diagnostics['type_standard'] = 0;
        $this->diagnostics['type_replacement'] = 0;
        $this->diagnostics['type_credit_note'] = 0;
        $this->diagnostics['type_deposit'] = 0;
        $this->diagnostics['type_proforma'] = 0;
        $this->diagnostics['type_situation'] = 0;
        $this->diagnostics['type_other'] = 0;

        $sql = 'SELECT DISTINCT f.rowid, f.entity as invoice_entity, f.fk_soc, f.date_lim_reglement, f.type, f.fk_statut, f.paye, s.nom as socname';
        $sql .= ', mc.rowid as case_id, mc.paused as case_paused, mc.current_level as case_level, mc.status as case_status';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'facture as f';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = f.fk_soc';
        $restrictCustomerVisibility = is_object($visibilityUser) && !empty($visibilityUser->id) && method_exists($visibilityUser, 'hasRight') && !$visibilityUser->hasRight('societe', 'client', 'voir');
        if ($restrictCustomerVisibility) {
            $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'societe_commerciaux as sc ON sc.fk_soc = f.fk_soc AND sc.fk_user = '.((int) $visibilityUser->id);
        }
        $sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'mahnwesen_case as mc';
        $sql .= ' ON mc.fk_facture = f.rowid AND mc.entity = f.entity';
        // A complete Dolibarr entity context (company identity, rules, sender,
        // document roots and constants) cannot safely be switched mid-run.
        // Consequently Mahnwesen automation is deliberately entity-local.
        $sql .= ' WHERE f.entity = '.((int) $conf->entity);
        $sql .= ' AND f.fk_statut = '.((int) Facture::STATUS_VALIDATED);
        $sql .= ' AND f.paye = 0';
        $supportedTypes = array(Facture::TYPE_STANDARD, Facture::TYPE_REPLACEMENT, Facture::TYPE_SITUATION);
        if ($this->includeDepositInvoices()) { $supportedTypes[] = Facture::TYPE_DEPOSIT; }
        $sql .= ' AND f.type IN ('.implode(',', array_map('intval', $supportedTypes)).')';
        $sql .= ' AND f.date_lim_reglement IS NOT NULL';
        $sql .= " AND f.date_lim_reglement < '".$this->db->escape($todaySql)."'";
        $sql .= ' ORDER BY f.date_lim_reglement ASC, f.rowid ASC';
        // Over-fetch because credits/deposits can reduce getRemainToPay() to
        // zero even while paye is not set. Stopping only after $limit eligible
        // rows prevents a small set of such invoices from starving the queue.
        $candidateLimit = min(10000, max($limit, $limit * 10));
        $sql .= $this->db->plimit($candidateLimit);

        dol_syslog(__METHOD__.' SQL='.$sql, LOG_DEBUG);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            $this->errors[] = $this->error;
            return false;
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $this->diagnostics['scanned_candidates']++;
            $this->countType((int) $obj->type);

            if (!$this->isSupportedInvoiceType((int) $obj->type)) {
                $this->diagnostics['excluded_type']++;
                continue;
            }

            $invoice = new Facture($this->db);
            if ($invoice->fetch((int) $obj->rowid) <= 0) {
                $this->diagnostics['excluded_fetch_error']++;
                $this->errors[] = 'Unable to fetch invoice id '.((int) $obj->rowid);
                continue;
            }

            // Use Dolibarr core calculation: payments + deposits + credit notes.
            $remainRaw = $invoice->getRemainToPay(0);
            if (!is_numeric($remainRaw)) {
                $this->diagnostics['excluded_fetch_error']++;
                $this->errors[] = 'Unable to calculate remaining amount for invoice '.$invoice->ref;
                continue;
            }
            $remain = (float) $remainRaw;

            if ($remain <= 0) {
                $this->diagnostics['excluded_no_balance']++;
                continue;
            }
            if ($remain < $minAmount) {
                $this->diagnostics['excluded_below_minimum']++;
                continue;
            }

            $dueYmd = dol_print_date($invoice->date_lim_reglement, '%Y-%m-%d', 'tzserver');
            if (empty($dueYmd)) {
                $this->diagnostics['excluded_invalid_due_date']++;
                continue;
            }

            $daysLate = $this->daysBetween($dueYmd, $todayYmd);
            $stage = $this->determineStage($daysLate);
            $caseId = isset($obj->case_id) ? (int) $obj->case_id : 0;
            $nextRequiredLevel = $this->getNextRequiredLevel($caseId, $stage);
            $highestCompletedLevel = $this->getHighestCompletedLevel($caseId);
            $nextRequiredAt = $nextRequiredLevel > 0 ? $this->calculateWorkflowStageDueAt($caseId, $dueYmd, $nextRequiredLevel) : null;
            $nextFutureLevel = $this->getNextFutureLevel($stage);
            $nextFutureAt = $nextFutureLevel > 0 ? $this->calculateWorkflowStageDueAt($caseId, $dueYmd, $nextFutureLevel) : null;

            $rows[] = array(
                'invoice_id' => (int) $invoice->id,
                'invoice_ref' => $invoice->ref,
                'invoice_type' => (int) $invoice->type,
                'socid' => (int) $invoice->socid,
                'socname' => (string) $obj->socname,
                'invoice_date' => $invoice->date,
                'due_date' => $invoice->date_lim_reglement,
                'due_ymd' => $dueYmd,
                'days_late' => $daysLate,
                'total_ttc' => (float) $invoice->total_ttc,
                'remain_to_pay' => $remain,
                'stage' => $stage,
                'stage_key' => $this->getStageLabelKey($stage),
                'next_required_level' => $nextRequiredLevel,
                'next_required_key' => $this->getStageLabelKey($nextRequiredLevel),
                'next_required_at' => $nextRequiredAt,
                'next_future_level' => $nextFutureLevel,
                'next_future_at' => $nextFutureAt,
                'highest_completed_level' => $highestCompletedLevel,
                'paused' => !empty($obj->case_paused),
                'stored_level' => isset($obj->case_level) ? (int) $obj->case_level : 0,
                'case_status' => isset($obj->case_status) ? (string) $obj->case_status : '',
                'case_id' => isset($obj->case_id) ? (int) $obj->case_id : 0,
                'invoice_entity' => isset($obj->invoice_entity) ? (int) $obj->invoice_entity : 1,
            );
            $this->diagnostics['included']++;
            if (count($rows) >= $limit) { break; }
        }

        $this->db->free($resql);
        return $rows;
    }

    /**
     * Raw database diagnostics before balance/type filtering.
     *
     * @param string $todaySql SQL date/time for start of current server day
     * @return array
     */
    protected function loadRawDiagnostics($todaySql, $visibilityUser = null)
    {
        global $conf;
        $diag = array(
            'all_invoices_entity' => 0,
            'validated_status1' => 0,
            'validated_no_due_date' => 0,
            'validated_due_today_or_future' => 0,
            'validated_overdue_raw' => 0,
            'validated_paye_flag_set' => 0,
        );

        $sql = 'SELECT COUNT(*) as all_invoices_entity';
        $sql .= ', SUM(CASE WHEN f.fk_statut = '.((int) Facture::STATUS_VALIDATED).' THEN 1 ELSE 0 END) as validated_status1';
        $sql .= ', SUM(CASE WHEN f.fk_statut = '.((int) Facture::STATUS_VALIDATED).' AND f.date_lim_reglement IS NULL THEN 1 ELSE 0 END) as validated_no_due_date';
        $sql .= ', SUM(CASE WHEN f.fk_statut = '.((int) Facture::STATUS_VALIDATED).' AND f.date_lim_reglement IS NOT NULL AND f.date_lim_reglement >= \''.$this->db->escape($todaySql).'\' THEN 1 ELSE 0 END) as validated_due_today_or_future';
        $sql .= ', SUM(CASE WHEN f.fk_statut = '.((int) Facture::STATUS_VALIDATED).' AND f.date_lim_reglement IS NOT NULL AND f.date_lim_reglement < \''.$this->db->escape($todaySql).'\' THEN 1 ELSE 0 END) as validated_overdue_raw';
        $sql .= ', SUM(CASE WHEN f.fk_statut = '.((int) Facture::STATUS_VALIDATED).' AND f.paye = 1 THEN 1 ELSE 0 END) as validated_paye_flag_set';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'facture as f';
        $restrictCustomerVisibility = is_object($visibilityUser) && !empty($visibilityUser->id) && method_exists($visibilityUser, 'hasRight') && !$visibilityUser->hasRight('societe', 'client', 'voir');
        if ($restrictCustomerVisibility) {
            $sql .= ' WHERE EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'societe_commerciaux as sc WHERE sc.fk_soc = f.fk_soc AND sc.fk_user = '.((int) $visibilityUser->id).')';
            $sql .= ' AND f.entity = '.((int) $conf->entity);
        } else {
            $sql .= ' WHERE f.entity = '.((int) $conf->entity);
        }

        dol_syslog(__METHOD__.' SQL='.$sql, LOG_DEBUG);
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                foreach ($diag as $key => $value) {
                    if (isset($obj->$key)) {
                        $diag[$key] = (int) $obj->$key;
                    }
                }
            }
            $this->db->free($resql);
        } else {
            $this->errors[] = 'Diagnostic query failed: '.$this->db->lasterror();
        }

        return $diag;
    }

    /**
     * @param int $type Invoice type
     * @return void
     */
    protected function countType($type)
    {
        switch ((int) $type) {
            case Facture::TYPE_STANDARD:
                $this->diagnostics['type_standard']++;
                break;
            case Facture::TYPE_REPLACEMENT:
                $this->diagnostics['type_replacement']++;
                break;
            case Facture::TYPE_CREDIT_NOTE:
                $this->diagnostics['type_credit_note']++;
                break;
            case Facture::TYPE_DEPOSIT:
                $this->diagnostics['type_deposit']++;
                break;
            case Facture::TYPE_PROFORMA:
                $this->diagnostics['type_proforma']++;
                break;
            case Facture::TYPE_SITUATION:
                $this->diagnostics['type_situation']++;
                break;
            default:
                $this->diagnostics['type_other']++;
                break;
        }
    }

    /**
     * @param int $type Invoice type
     * @return bool
     */
    public function isSupportedInvoiceType($type)
    {
        $allowed = array(
            Facture::TYPE_STANDARD,
            Facture::TYPE_REPLACEMENT,
            Facture::TYPE_SITUATION,
        );
        if ($this->includeDepositInvoices()) {
            $allowed[] = Facture::TYPE_DEPOSIT;
        }
        return in_array((int) $type, $allowed, true);
    }

    /**
     * @return bool
     */
    public function includeDepositInvoices()
    {
        return getDolGlobalInt('MAHNWESEN_INCLUDE_DEPOSITS', 0) > 0;
    }

    /**
     * Determine dunning stage from days after due date.
     *
     * @param int $daysLate Days after due date
     * @return int 0..4
     */
    public function determineStage($daysLate)
    {
        $daysLate = max(0, (int) $daysLate);
        $thresholds = $this->getStageThresholds();
        $rules = $this->getRules();
        $stage = 0;

        foreach ($thresholds as $level => $days) {
            if (!empty($rules[(int) $level]['enabled']) && $daysLate >= $days) {
                $stage = (int) $level;
            }
        }

        return $stage;
    }

    /**
     * @return array<int,int>
     */
    public function getStageThresholds()
    {
        $rules = $this->getRules();
        $out = array();
        foreach ($rules as $level => $rule) {
            $out[(int) $level] = (int) $rule['days_after_due'];
        }
        if (count($out) === 4) {
            ksort($out);
            return $out;
        }
        return array(
            1 => $this->getIntSetting('MAHNWESEN_STAGE1_DAYS', 3),
            2 => $this->getIntSetting('MAHNWESEN_STAGE2_DAYS', 10),
            3 => $this->getIntSetting('MAHNWESEN_STAGE3_DAYS', 20),
            4 => $this->getIntSetting('MAHNWESEN_STAGE4_DAYS', 30),
        );
    }

    /**
     * @return float
     */
    public function getMinimumAmount()
    {
        $value = getDolGlobalString('MAHNWESEN_MIN_AMOUNT');
        if ($value === '') {
            $value = '1.00';
        }
        return max(0.0, (float) price2num($value));
    }

    /**
     * @return int
     */
    public function getMaxScan()
    {
        return max(1, $this->getIntSetting('MAHNWESEN_MAX_SCAN', 500));
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
            if ($pause) { $caseData['pause_until'] = $pause->pause_until; $caseData['pause_reason'] = (string) $pause->reason; }
            $this->db->free($resPause);
        }
        if (!array_key_exists('pause_until', $caseData)) { $caseData['pause_until'] = null; $caseData['pause_reason'] = ''; }
        return $caseData;
    }

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
}
