<?php
/* Scanning overdue invoices and evaluating one invoice. Reads only. */
trait DunningManagerScan
{
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

            // The invoice's profile holds its stages (#32).
            $profile = $this->resolveProfile((int) $invoice->id);
            $profileId = (int) $profile['profile_id'];
            $daysLate = $this->daysBetween($dueYmd, $todayYmd);
            $stage = $this->determineStage($daysLate, $profileId);
            $caseId = isset($obj->case_id) ? (int) $obj->case_id : 0;
            $nextRequiredLevel = $this->getNextRequiredLevel($caseId, $stage, $profileId);
            $highestCompletedLevel = $this->getHighestCompletedLevel($caseId);
            $nextRequiredAt = $nextRequiredLevel > 0 ? $this->calculateWorkflowStageDueAt($caseId, $dueYmd, $nextRequiredLevel, $profileId) : null;
            $nextFutureLevel = $this->getNextFutureLevel($stage, $profileId);
            $nextFutureAt = $nextFutureLevel > 0 ? $this->calculateWorkflowStageDueAt($caseId, $dueYmd, $nextFutureLevel, $profileId) : null;

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
                'profile_id' => $profileId,
                'profile_label' => (string) $profile['profile']['label'],
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
        $profile = $this->resolveProfile((int) $invoice->id);
        $stage = $this->determineStage($daysLate, (int) $profile['profile_id']);
        $entity = $invoiceEntity;

        return array(
            'eligible' => $eligible ? 1 : 0,
            'reason' => $reason,
            'remain_to_pay' => $remain,
            'profile' => $profile,
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
                'profile_id' => (int) $profile['profile_id'],
                'profile_label' => (string) $profile['profile']['label'],
            ),
        );
    }

    /**
     * Date-only difference, robust around DST changes.
     *
     * @param string $from YYYY-MM-DD
     * @param string $to YYYY-MM-DD
     * @return int
     */
    protected function daysBetween($from, $to)
    {
        try {
            $a = new DateTimeImmutable($from.' 12:00:00');
            $b = new DateTimeImmutable($to.' 12:00:00');
            return max(0, (int) $a->diff($b)->days);
        } catch (Exception $e) {
            return 0;
        }
    }
}
