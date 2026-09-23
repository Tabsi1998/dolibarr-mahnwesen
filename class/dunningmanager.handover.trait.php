<?php
/* Handing a case to debt collection or a lawyer: the end state, and the file in Dolibarr's document store (#40). */
trait DunningManagerHandover
{
    /**
     * Hand one case over: automation ends, and the whole file is put into
     * Dolibarr's own document store (#40).
     *
     * The dunning case keeps its history; nothing is deleted and no archive of
     * its own is built. What goes into the store is what exists already: the
     * invoice, the dunning letters as they went out, their checksums, the open
     * claims and the history.
     *
     * @param int $invoiceId Invoice of the case
     * @param string $reason Why it is handed over, in the user's words
     * @param User $user Acting user
     * @return array|false Folder and files, or false
     */
    public function handOverCase($invoiceId, $reason, $user)
    {
        global $conf, $langs;
        require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
        require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
        $reason = trim((string) $reason);
        if ($reason === '') {
            $this->error = $langs->trans('MahnwesenHandoverReasonRequired');
            return false;
        }
        if (!is_object($user) || !method_exists($user, 'hasRight') || !$user->hasRight('mahnwesen', 'case', 'write')) {
            $this->error = 'User is not allowed to hand a dunning case over.';
            return false;
        }
        $case = $this->getCaseByInvoice((int) $invoiceId);
        if (!$case || !in_array($case['status'], array('open', 'fee_open'), true)) {
            $this->error = $langs->trans('MahnwesenHandoverNotOpen');
            return false;
        }
        $invoice = new Facture($this->db);
        if ($invoice->fetch((int) $invoiceId) <= 0 || !$this->canSeeCustomer($user, (int) $invoice->socid)) {
            $this->error = 'The invoice of the dunning case could not be loaded.';
            return false;
        }
        $folder = 'mahnwesen/'.dol_sanitizeFileName((string) $invoice->ref);
        $fullFolder = DOL_DATA_ROOT.'/ecm/'.$folder;
        if (!is_dir($fullFolder) && dol_mkdir($fullFolder) < 0) {
            $this->error = 'The folder of the case file could not be created.';
            return false;
        }
        $copied = array();
        foreach ($this->handoverSources($invoice) as $source) {
            $target = $fullFolder.'/'.dol_sanitizeFileName($source['name']);
            if (!is_readable($source['path']) || dol_copy($source['path'], $target, '0', 1) <= 0) {
                $this->errors[] = 'The file '.$source['name'].' could not be put into the case file.';
                continue;
            }
            $copied[] = array('name' => dol_sanitizeFileName($source['name']), 'sha256' => (string) $source['sha256'],
                'sha256_now' => hash_file('sha256', $target), 'kind' => $source['kind']);
        }
        $summary = $this->handoverSummary($invoice, $case, $reason, $copied);
        $summaryName = dol_sanitizeFileName($invoice->ref.'_Mahnakte.txt');
        if (file_put_contents($fullFolder.'/'.$summaryName, $summary) === false) {
            $this->error = 'The summary of the case file could not be written.';
            return false;
        }
        $copied[] = array('name' => $summaryName, 'sha256' => '', 'sha256_now' => hash('sha256', $summary), 'kind' => 'summary');
        foreach ($copied as $file) {
            $ecm = new EcmFiles($this->db);
            $ecm->filepath = 'ecm/'.$folder;
            $ecm->filename = $file['name'];
            $ecm->label = md5_file($fullFolder.'/'.$file['name']);
            $ecm->fullpath_orig = $fullFolder.'/'.$file['name'];
            $ecm->gen_or_uploaded = 'generated';
            $ecm->description = $langs->transnoentitiesnoconv('MahnwesenHandoverFileDescription').' '.$invoice->ref;
            $ecm->entity = (int) $conf->entity;
            if ($ecm->create($user) <= 0) {
                // The file is on disk; only its index entry is missing.
                $this->errors[] = 'The document store did not index '.$file['name'].': '.$ecm->error;
            }
        }
        $uid = (int) $user->id;
        $this->db->begin();
        $sql = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_case SET status = 'handed_over', next_action_at = NULL, fk_user_modif = ".$uid;
        $sql .= ' WHERE rowid = '.((int) $case['id'])." AND status IN ('open', 'fee_open')";
        if (!$this->db->query($sql)) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return false;
        }
        if (!$this->addHistory((int) $case['entity'], (int) $case['id'], (int) $invoiceId, 'case_handed_over', (int) $case['current_level'],
            (float) $case['remaining_amount'], 'manual', 'success', $reason, $user)) {
            $this->db->rollback();
            return false;
        }
        $this->db->commit();
        $this->syncHistoryToAgenda((int) $invoiceId, $user);
        $this->dispatchEvents($user, 20);
        return array('folder' => 'ecm/'.$folder, 'files' => $copied);
    }

    /**
     * The files that belong to the case: the invoice as it was sent, and every
     * dunning letter of a delivery that went out (#40).
     *
     * @param Facture $invoice Invoice
     * @return array<int,array{path:string,name:string,sha256:string,kind:string}>
     */
    protected function handoverSources($invoice)
    {
        global $conf;
        $sources = array();
        $invoicePdf = DOL_DATA_ROOT.'/'.(string) $invoice->last_main_doc;
        if (!empty($invoice->last_main_doc) && is_readable($invoicePdf)) {
            $sources[] = array('path' => $invoicePdf, 'name' => basename($invoicePdf), 'sha256' => hash_file('sha256', $invoicePdf), 'kind' => 'invoice');
        }
        $sql = 'SELECT f.display_name, f.snapshot_path, f.sha256, a.level FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt_file as f';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'mahnwesen_attempt as a ON a.rowid = f.fk_attempt';
        $sql .= ' WHERE f.entity = '.((int) $conf->entity).' AND a.fk_facture = '.((int) $invoice->id);
        $sql .= " AND a.status = 'sent' AND f.file_role = 'dunning' ORDER BY a.rowid ASC";
        $res = $this->db->query($sql);
        while ($res && ($o = $this->db->fetch_object($res))) {
            if ((string) $o->snapshot_path === '') {
                // The retention removed the copy; its checksum stays in the summary (#42).
                continue;
            }
            $sources[] = array('path' => (string) $o->snapshot_path, 'name' => (string) $o->display_name,
                'sha256' => (string) $o->sha256, 'kind' => 'notice');
        }
        if ($res) {
            $this->db->free($res);
        }
        return $sources;
    }

    /**
     * The summary of the case file: what was sent, what is owed, what happened (#40).
     *
     * @param Facture $invoice Invoice
     * @param array $case Case
     * @param string $reason Reason of the handover
     * @param array $files Files of the folder
     * @return string
     */
    protected function handoverSummary($invoice, $case, $reason, $files)
    {
        global $conf, $langs;
        $lines = array();
        $lines[] = $langs->transnoentitiesnoconv('MahnwesenHandoverTitle').' '.$invoice->ref;
        $lines[] = str_repeat('=', 60);
        $lines[] = $langs->transnoentitiesnoconv('ThirdParty').': '.(!empty($invoice->thirdparty) ? $invoice->thirdparty->name : '#'.$invoice->socid);
        $lines[] = $langs->transnoentitiesnoconv('DateDue').': '.dol_print_date($invoice->date_lim_reglement, '%Y-%m-%d', 'tzserver');
        $lines[] = $langs->transnoentitiesnoconv('RemainToPay').': '.price2num($case['remaining_amount']).' '.$conf->currency;
        $lines[] = $langs->transnoentitiesnoconv('MahnwesenHandoverReason').': '.$reason;
        $lines[] = '';
        $lines[] = $langs->transnoentitiesnoconv('MahnwesenFeeLedger');
        foreach ($this->getOpenClaims((int) $case['id']) as $claim) {
            $lines[] = '  '.$claim['kind'].' '.$langs->transnoentitiesnoconv('DunningStage').' '.((int) $claim['level'])
                .': '.price2num($claim['amount']).' '.$claim['currency_code'];
        }
        $lines[] = '';
        $lines[] = $langs->transnoentitiesnoconv('MahnwesenHandoverFiles');
        foreach ($files as $file) {
            $lines[] = '  '.$file['name'].' ('.$file['kind'].') sha256='.($file['sha256'] !== '' ? $file['sha256'] : $file['sha256_now']);
        }
        $lines[] = '';
        $lines[] = $langs->transnoentitiesnoconv('MahnwesenHistory');
        foreach ($this->getHistoryByInvoice((int) $invoice->id, 200) as $entry) {
            $lines[] = '  '.dol_print_date($this->db->jdate($entry['date_creation']), '%Y-%m-%d %H:%M', 'tzserver')
                .' '.$entry['action'].' '.$langs->transnoentitiesnoconv('DunningStage').' '.((int) $entry['level']).' '.$entry['result'];
        }
        return implode("\n", $lines)."\n";
    }
}
