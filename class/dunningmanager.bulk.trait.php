<?php
/* Several cases at once: sending by email, and the letters of customers without one as a batch to print (#39). */
trait DunningManagerBulk
{
    /**
     * Send the due notice of several cases, each through the same checks as a
     * single notice (#39).
     *
     * A case that is not due, paused, blocked, without a recipient or without
     * a template is skipped and named; nothing is forced.
     *
     * @param int[] $invoiceIds Invoices from the list
     * @param DunningNoticeService $service Notice service
     * @param User $user Acting user
     * @return array sent, skipped (invoice ref => reason)
     */
    public function sendNoticesForInvoices($invoiceIds, $service, $user)
    {
        global $langs;
        $result = array('sent' => 0, 'skipped' => array());
        $budget = $this->newAutomaticBudget();
        // A person is acting, so the run limits of the cron do not apply.
        $budget['max'] = count($invoiceIds) + 1;
        $budget['max_per_customer'] = count($invoiceIds) + 1;
        foreach ($this->rowsForInvoices($invoiceIds) as $row) {
            $ref = (string) $row['invoice_ref'];
            if (!$this->canSeeCustomer($user, (int) $row['socid'])) {
                $result['skipped'][$ref] = $langs->trans('MahnwesenBulkSkippedScope');
                continue;
            }
            $decision = $this->decideAutomaticSend($row, $service, $budget, false);
            if ($decision['decision'] !== 'send') {
                $result['skipped'][$ref] = $langs->trans('MahnwesenDryRunDetail_'.$decision['detail']);
                continue;
            }
            $invoice = $decision['invoice'];
            $case = $decision['case'];
            $level = (int) $decision['level'];
            $template = $decision['template'];
            $lang = (string) $decision['lang'];
            $subject = $service->renderTemplate($template['subject'], $invoice, $case, $level, $lang);
            $body = $service->renderTemplate($template['body'], $invoice, $case, $level, $lang);
            $attachInvoice = ((string) ($template['joinfiles'] ?? '') === '1');
            $sent = $service->sendNotice($invoice, $case, $level, $decision['recipient'], $subject, $body, $attachInvoice, $user, 'manual',
                isset($template['email_from']) ? $template['email_from'] : '', isset($template['lang']) ? $template['lang'] : $lang, '', '',
                !empty($template['source_id']) ? (int) $template['source_id'] : 0);
            if ($sent === false) {
                $result['skipped'][$ref] = $service->error;
                continue;
            }
            $result['sent']++;
        }
        return $result;
    }

    /**
     * The letters of the selected cases whose customer has no email address,
     * as one PDF to print, and recorded as sent by post (#39).
     *
     * Every letter goes through the same reservation as an email, so a stage
     * is completed once, its fee is booked once, and the history of the
     * invoice says it went out by post.
     *
     * @param int[] $invoiceIds Invoices from the list
     * @param DunningNoticeService $service Notice service
     * @param User $user Acting user
     * @return array|false path of the batch, count, skipped
     */
    public function buildLetterBatch($invoiceIds, $service, $user)
    {
        global $conf, $langs;
        require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
        $result = array('path' => '', 'letters' => 0, 'skipped' => array());
        $pages = array();
        foreach ($this->rowsForInvoices($invoiceIds) as $row) {
            $ref = (string) $row['invoice_ref'];
            if (!$this->canSeeCustomer($user, (int) $row['socid'])) {
                $result['skipped'][$ref] = $langs->trans('MahnwesenBulkSkippedScope');
                continue;
            }
            $invoice = new Facture($this->db);
            if ($invoice->fetch((int) $row['invoice_id']) <= 0) {
                $result['skipped'][$ref] = $langs->trans('MahnwesenDryRunDetail_invoice_load_failed');
                continue;
            }
            $invoice->fetch_thirdparty();
            if (!empty($invoice->thirdparty->email) || $service->getRecipientOptions($invoice)) {
                // A customer that can be reached by email is not printed (#39).
                $result['skipped'][$ref] = $langs->trans('MahnwesenBulkSkippedHasEmail');
                continue;
            }
            $workflow = $this->getWorkflowState((int) $invoice->id);
            $case = $workflow ? $workflow['case'] : false;
            $level = $workflow ? (int) $workflow['next_required_level'] : 0;
            if (!$workflow || empty($workflow['actionable']) || !$case || $level <= 0) {
                $result['skipped'][$ref] = $langs->trans('MahnwesenDryRunDetail_not_due');
                continue;
            }
            $letter = $service->sendPostalNotice($invoice, $case, $level, $user);
            if ($letter === false) {
                $result['skipped'][$ref] = $service->error;
                continue;
            }
            $pages[] = $letter['fullpath'];
            $result['letters']++;
        }
        if (empty($pages)) {
            return $result;
        }
        $folder = DOL_DATA_ROOT.'/mahnwesen/batch';
        if (!is_dir($folder) && dol_mkdir($folder) < 0) {
            $this->error = 'The folder for the letter batch could not be created.';
            return false;
        }
        $target = $folder.'/mahnwesen-'.dol_print_date(dol_now(), '%Y%m%d-%H%M%S', 'tzserver').'.pdf';
        if (!$this->mergePdfFiles($pages, $target)) {
            return false;
        }
        $result['path'] = $target;
        return $result;
    }

    /**
     * Put several PDF files into one, with Dolibarr's own PDF library.
     *
     * @param string[] $paths Files to join
     * @param string $target File to write
     * @return bool
     */
    protected function mergePdfFiles($paths, $target)
    {
        require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
        $pdf = pdf_getInstance();
        $pdf->SetCreator('Dolibarr Mahnwesen');
        $pdf->SetAutoPageBreak(false);
        foreach ($paths as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $count = $pdf->setSourceFile($path);
            for ($page = 1; $page <= $count; $page++) {
                $imported = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($imported);
                // TCPDI names the size w and h, FPDI width and height.
                $width = (float) (isset($size['width']) ? $size['width'] : $size['w']);
                $height = (float) (isset($size['height']) ? $size['height'] : $size['h']);
                $pdf->AddPage($width > $height ? 'L' : 'P', array($width, $height));
                $pdf->useTemplate($imported);
            }
        }
        if ($pdf->PageNo() <= 0) {
            $this->error = 'The letter batch holds no page.';
            return false;
        }
        $pdf->Output($target, 'F');
        return is_readable($target);
    }

    /**
     * The scan rows of the given invoices, in the order of the list.
     *
     * @param int[] $invoiceIds Invoices
     * @return array<int,array>
     */
    protected function rowsForInvoices($invoiceIds)
    {
        $wanted = array();
        foreach ((array) $invoiceIds as $invoiceId) {
            if ((int) $invoiceId > 0) {
                $wanted[(int) $invoiceId] = (int) $invoiceId;
            }
        }
        if (empty($wanted)) {
            return array();
        }
        $rows = array();
        foreach ($wanted as $invoiceId) {
            $evaluation = $this->evaluateInvoice($invoiceId);
            if ($evaluation === false) {
                continue;
            }
            $rows[] = $evaluation['row'];
        }
        return $rows;
    }
}
