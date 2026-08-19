<?php
/* Auto-split method trait for maintainable source files. */
trait DunningNoticeServiceMethods4
{

    /**
     * Generate a dunning PDF. Preview files stay in the Mahnwesen document
     * area. Non-preview files are stored in the invoice document directory so
     * Dolibarr shows them with the invoice's other linked/generated files.
     * The invoice record itself is never modified and last_main_doc is not
     * changed.
     *
     * @param Facture $invoice Invoice
     * @param array $case Stored case
     * @param int $level Stage
     * @param string $body Rendered HTML/body
     * @param bool $preview Replaceable preview or final invoice-linked copy
     * @param string $lang Output language
     * @return array|false
     */
    public function generatePdf($invoice, $case, $level, $body, $preview = true, $lang = '')
    {
        global $conf, $langs, $mysoc;
        $this->error = '';
        if ($lang === '') { $lang = is_object($langs) ? $langs->defaultlang : 'de_DE'; }
        $outputlangs = new Translate('', $conf);
        $outputlangs->setDefaultLang($lang);
        $outputlangs->loadLangs(array('main', 'bills', 'companies', 'compta', 'mahnwesen@mahnwesen'));

        if (empty($invoice->thirdparty)) { $invoice->fetch_thirdparty(); }
        $safeRef = dol_sanitizeFileName($invoice->ref);
        $stagePart = $this->getStageFilenamePart((int) $level);
        $modulepart = $preview ? 'mahnwesen' : 'invoice';
        if ($preview) {
            $entity = !empty($invoice->entity) ? (int) $invoice->entity : (int) $conf->entity;
            $root = !empty($conf->mahnwesen->multidir_output[$entity]) ? $conf->mahnwesen->multidir_output[$entity] : (!empty($conf->mahnwesen->dir_output) ? $conf->mahnwesen->dir_output : DOL_DATA_ROOT.'/mahnwesen');
            $dir = rtrim($root, '/').'/notices/'.$safeRef;
            $filename = $safeRef.'_'.$stagePart.'_preview.pdf';
            $relative = 'notices/'.$safeRef.'/'.$filename;
        } else {
            $root = $this->getInvoiceDocumentRoot($invoice);
            if ($root === '') { $this->error = 'Invoice document output directory is unavailable'; return false; }
            // Put the dunning PDF into the exact directory used by the invoice
            // documents. This also respects installations using a custom or
            // hierarchical invoice document path and makes the file visible in
            // Dolibarr's regular "Linked files" block.
            $invoicePdfPath = $this->getInvoicePdfPath($invoice);
            $dir = ($invoicePdfPath !== '') ? dirname($invoicePdfPath) : rtrim($root, '/').'/'.$safeRef;
            $filename = $safeRef.'_'.$stagePart.'.pdf';
            $relativeDir = ltrim(str_replace('\\', '/', substr($dir, strlen(rtrim($root, '/')))), '/');
            $relative = ($relativeDir !== '' ? $relativeDir.'/' : '').$filename;
        }
        if (!is_dir($dir) && dol_mkdir($dir) < 0) {
            $this->error = $outputlangs->transnoentities('ErrorCanNotCreateDir', $dir);
            return false;
        }
        $fullpath = $dir.'/'.$filename;

        try {
            $breakdown = $this->manager->getAmountBreakdown($invoice, $case, $level);
            $format = pdf_getFormat($outputlangs);
            $pageWidth = (float) $format['width'];
            $pageHeight = (float) $format['height'];
            $pdf = pdf_getInstance(array($pageWidth, $pageHeight), $format['unit'], 'P');
            $pdf->SetCreator('Dolibarr Mahnwesen');
            $pdf->SetAuthor(is_object($mysoc) ? (string) $mysoc->name : 'Dolibarr');
            $pdf->SetTitle($outputlangs->trans($this->manager->getStageLabelKey((int) $level)).' '.$invoice->ref);
            $marginLeft = getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10);
            $marginRight = getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10);
            $marginTop = getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10);
            $marginBottom = getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10);
            $pdf->SetMargins($marginLeft, $marginTop, $marginRight);
            // Reserve room for pdf_pagefoot just like standard Dolibarr models.
            $pdf->SetAutoPageBreak(true, max(24, $marginBottom + 14));
            if (method_exists($pdf, 'setPrintHeader')) { $pdf->setPrintHeader(false); $pdf->setPrintFooter(false); }
            $pdf->AddPage();

            $bodyStartY = $this->drawSpongeReminderHeader($pdf, $invoice, $level, $outputlangs, $pageWidth, $pageHeight, $marginLeft, $marginRight, $marginTop);
            $defaultFontSize = pdf_getPDFFontSize($outputlangs);
            $contentWidth = $pageWidth - $marginLeft - $marginRight;

            // Sponge-like amount block. Instead of copying an invoice product
            // grid, the reminder uses the same sober black lines and typography
            // for a compact description/amount table.
            $tableY = $bodyStartY;
            $descW = $contentWidth - 42;
            $amountW = 42;
            $rowH = 7;
            $pdf->SetDrawColor(128, 128, 128);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('', '', max(6, $defaultFontSize - 1));
            // Header
            $pdf->Rect($marginLeft, $tableY, $descW, $rowH);
            $pdf->Rect($marginLeft + $descW, $tableY, $amountW, $rowH);
            $pdf->SetXY($marginLeft + 1.5, $tableY + 1.4);
            $pdf->Cell($descW - 3, 4, $outputlangs->transnoentities('Description'), 0, 0, 'L');
            $pdf->SetXY($marginLeft + $descW + 1.5, $tableY + 1.4);
            $pdf->Cell($amountW - 3, 4, $outputlangs->transnoentities('Amount'), 0, 0, 'R');
            // Open invoice amount
            $y = $tableY + $rowH;
            $pdf->Rect($marginLeft, $y, $descW, $rowH);
            $pdf->Rect($marginLeft + $descW, $y, $amountW, $rowH);
            $pdf->SetXY($marginLeft + 1.5, $y + 1.4);
            $pdf->Cell($descW - 3, 4, $outputlangs->transnoentities('MahnwesenOpenInvoiceAmount').' - '.$invoice->ref, 0, 0, 'L');
            $pdf->SetXY($marginLeft + $descW + 1.5, $y + 1.4);
            $pdf->Cell($amountW - 3, 4, $this->formatMoney($breakdown['invoice'], $outputlangs), 0, 0, 'R');
            // Dunning fee only occupies a row when it is non-zero.
            if ($breakdown['fee'] > 0.000001) {
                $y += $rowH;
                $pdf->Rect($marginLeft, $y, $descW, $rowH);
                $pdf->Rect($marginLeft + $descW, $y, $amountW, $rowH);
                $pdf->SetXY($marginLeft + 1.5, $y + 1.4);
                $pdf->Cell($descW - 3, 4, $outputlangs->transnoentities('MahnwesenDunningFee').' - '.$outputlangs->transnoentities($this->manager->getStageLabelKey((int) $level)), 0, 0, 'L');
                $pdf->SetXY($marginLeft + $descW + 1.5, $y + 1.4);
                $pdf->Cell($amountW - 3, 4, $this->formatMoney($breakdown['fee'], $outputlangs), 0, 0, 'R');
            }
            // Total area: keep it visually attached to the amount table, but
            // avoid the previous half-open box where only the right-hand amount
            // cell had borders. Sponge uses a clean subtotal/total treatment, so
            // draw one full-width top rule and keep label + amount borderless.
            $y += $rowH;
            $pdf->SetFont('', 'B', $defaultFontSize);
            $pdf->Line($marginLeft, $y, $pageWidth - $marginRight, $y);
            $pdf->SetXY($marginLeft + $descW - 45, $y + 1.4);
            $pdf->Cell(43, 4, $outputlangs->transnoentities('Total'), 0, 0, 'R');
            $pdf->SetXY($marginLeft + $descW + 1.5, $y + 1.4);
            $pdf->Cell($amountW - 3, 4, $this->formatMoney($breakdown['total'], $outputlangs), 0, 0, 'R');

            // Printed letter body. The dedicated PDF cleanup removes email-only
            // signature graphics and supports an explicit PDF end marker.
            $pdf->SetY($y + $rowH + 8);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('', '', max(7, $defaultFontSize - 1));
            $pdfBody = $this->prepareBodyForPdf($body);
            $pdfHtml = '<div style="font-size:9pt; line-height:1.20;">'.$this->asHtml($pdfBody).'</div>';
            $pdf->writeHTML($pdfHtml, true, false, true, false, '');

            // Keep payment terms/bank information in the same visual family as
            // the invoice when enough room remains. If content flows, TCPDF can
            // add a page; each page still receives the standard Dolibarr footer.
            $paymentY = $pdf->GetY() + 5;
            if ($paymentY < $pageHeight - 55) {
                $this->drawSpongePaymentInfo($pdf, $invoice, $outputlangs, $paymentY, $pageWidth, $marginLeft, $marginRight);
            }

            // Standard Dolibarr invoice footer: same free-text area/page number
            // handling as Sponge, without changing the invoice itself.
            $showDetails = getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', 0);
            // Footer helpers write into the reserved bottom area. Disable TCPDF's
            // automatic page break while drawing them; otherwise a short free-text
            // footer can create an almost empty second page.
            $pdf->SetAutoPageBreak(false, 0);
            $numPages = $pdf->getNumPages();
            for ($page = 1; $page <= $numPages; $page++) {
                $pdf->setPage($page);
                pdf_pagefoot($pdf, $outputlangs, 'INVOICE_FREE_TEXT', $mysoc, $marginBottom, $marginLeft, $pageHeight, $invoice, $showDetails, 0, $pageWidth, '');
            }
            $pdf->Output($fullpath, 'F');
        } catch (Throwable $e) {
            $this->error = get_class($e).': '.$e->getMessage();
            dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
            return false;
        }

        if (!is_file($fullpath) || filesize($fullpath) <= 0) {
            $this->error = 'Generated dunning PDF is missing or empty';
            return false;
        }
        return array('fullpath' => $fullpath, 'relative' => $relative, 'filename' => $filename, 'modulepart' => $modulepart, 'preview' => $preview ? 1 : 0);
    }

    /**
     * Send one notice in manual or automatic mode. Both paths use the same
     * fail-closed send reservation and duplicate protection.
     *
     * @return array|false
     */
    public function sendNotice($invoice, $case, $level, $recipient, $subject, $body, $attachInvoice, $user, $mode = 'manual', $templateFrom = '', $lang = '', $cc = '', $bcc = '')
    {
        $this->error = '';
        $this->errors = array();
        $mode = ($mode === 'automatic') ? 'automatic' : 'manual';

        if ($mode === 'automatic' && !$this->isAutomaticSendEnabled()) {
            $this->error = 'Automatic dunning email sending is disabled.';
            return false;
        }
        if ($mode === 'manual' && !$this->isManualSendEnabled()) {
            $this->error = 'Manual dunning email sending is disabled in module settings.';
            return false;
        }
        if (empty($case) || $case['status'] === 'closed' || !empty($case['paused'])) {
            $this->error = 'Dunning case is closed or paused.';
            return false;
        }
        $evaluation = $this->manager->evaluateInvoice((int) $invoice->id);
        $calculatedLevel = ($evaluation !== false && !empty($evaluation['eligible'])) ? (int) $evaluation['row']['stage'] : 0;
        $requiredLevel = $this->manager->getNextRequiredLevel((int) $case['id'], $calculatedLevel);
        if ((int) $level <= 0 || (int) $requiredLevel !== (int) $level) {
            $this->error = 'Dunning workflow changed. Reload before sending; earlier unsent stages may not be skipped.';
            return false;
        }
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error = 'Invalid recipient email.';
            return false;
        }
        $cc = $this->normalizeEmailList($cc);
        if ($cc === false) {
            $this->error = 'Invalid CC email address.';
            return false;
        }
        $bcc = $this->normalizeEmailList($bcc);
        if ($bcc === false) {
            $this->error = 'Invalid BCC email address.';
            return false;
        }
        $from = $this->getFromEmail($templateFrom);
        if ($from === '') {
            $this->error = 'No valid sender email is configured for Mahnwesen or Dolibarr.';
            return false;
        }
        if (trim((string) $subject) === '' || trim((string) $body) === '') {
            $this->error = 'Subject and message must not be empty.';
            return false;
        }

        // A final dunning document has a deterministic filename. Reuse it when
        // already generated; otherwise create it automatically as part of the
        // send operation. This avoids duplicate PDFs for the same stage.
        $pdfInfo = $this->getExistingFinalPdfInfo($invoice, $level);
        $generatedNow = false;
        if ($pdfInfo === false) {
            $pdfInfo = $this->generatePdf($invoice, $case, $level, $body, false, $lang);
            if ($pdfInfo === false) { return false; }
            $generatedNow = true;
            if (!$this->manager->recordGeneratedDocument((int) $invoice->id, (int) $level, $pdfInfo['relative'], $user, $mode)) {
                $this->errors[] = 'PDF generated, but document audit entry failed: '.$this->manager->error;
            }
        }

        $files = array($pdfInfo['fullpath']);
        $mimes = array('application/pdf');
        $names = array($pdfInfo['filename']);
        $invoicePdf = '';
        if ($attachInvoice) {
            $invoicePdf = $this->getInvoicePdfPath($invoice);
            if ($invoicePdf !== '') {
                $files[] = $invoicePdf;
                $mimes[] = 'application/pdf';
                $names[] = basename($invoicePdf);
            }
        }

        $breakdown = $this->manager->getAmountBreakdown($invoice, $case, $level);
        $historyMessage = 'Betreff: '.$subject."\nVon: ".$from."\nPDF: ".$pdfInfo['relative'];
        if ($cc !== '') { $historyMessage .= "\nCC: ".$cc; }
        if ($bcc !== '') { $historyMessage .= "\nBCC: ".$bcc; }
        $historyMessage .= "\nOffene Rechnung: ".number_format($breakdown['invoice'], 2, '.', '').' '.$GLOBALS['conf']->currency;
        $historyMessage .= "\nMahnspesen: ".number_format($breakdown['fee'], 2, '.', '').' '.$GLOBALS['conf']->currency;
        $historyMessage .= "\nGesamt: ".number_format($breakdown['total'], 2, '.', '').' '.$GLOBALS['conf']->currency;
        if ($attachInvoice) {
            $historyMessage .= "\nRechnung: ".($invoicePdf !== '' ? basename($invoicePdf) : 'nicht verfügbar');
        }

        $reservationId = $this->manager->reserveNoticeAttempt(
            $case,
            $recipient,
            $level,
            (float) $case['remaining_amount'],
            $historyMessage,
            $user,
            $mode
        );
        if ($reservationId === false) {
            $this->error = $this->manager->error ?: 'Unable to reserve dunning notice send';
            return false;
        }

        $mail = null;
        try {
            $mail = new CMailFile(
                (string) $subject,
                (string) $recipient,
                $from,
                (string) $this->asHtml($body),
                $files,
                $mimes,
                $names,
                $cc,
                $bcc,
                0,
                1,
                '',
                '',
                'mahnwesen'.$invoice->id,
                '',
                'standard',
                $from
            );
            $sent = $mail->sendfile();
        } catch (Throwable $e) {
            $sent = 0;
            $this->error = get_class($e).': '.$e->getMessage();
        }

        if (empty($sent)) {
            if ($this->error === '') {
                $this->error = (is_object($mail) && !empty($mail->error)) ? $mail->error : 'CMailFile sendfile failed';
            }
            $failedMessage = $historyMessage."\nFehler: ".$this->error;
            if (!$this->manager->finalizeNoticeAttempt($reservationId, false, $failedMessage, $case, $user)) {
                $this->errors[] = 'Versand fehlgeschlagen; Audit-Finalisierung ebenfalls fehlgeschlagen: '.$this->manager->error;
            }
            return false;
        }

        if (!$this->manager->finalizeNoticeAttempt($reservationId, true, $historyMessage, $case, $user)) {
            $this->error = 'Die E-Mail wurde vom Mailer als versendet gemeldet, aber die Audit-Finalisierung ist fehlgeschlagen. Nicht erneut senden; die ausstehende Reservierung blockiert einen Doppelversand. '.$this->manager->error;
            return false;
        }

        // Advance only to the next sequentially allowed stage. A failure here
        // must not turn a successfully delivered email into a retryable send.
        $syncAfterSend = $this->manager->syncInvoiceCase((int) $invoice->id, $user);
        if ($syncAfterSend === false) {
            $this->errors[] = 'E-Mail wurde versendet, aber der Mahnfall konnte danach nicht synchronisiert werden: '.$this->manager->error;
        }
        $this->manager->syncHistoryToAgenda((int) $invoice->id, $user);

        return array(
            'recipient' => $recipient,
            'subject' => $subject,
            'pdf' => $pdfInfo,
            'invoice_pdf' => $invoicePdf,
            'mode' => $mode,
            'cc' => $cc,
            'bcc' => $bcc,
            'fee' => $breakdown['fee'],
            'total' => $breakdown['total'],
        );
    }

    /** Normalize a comma/semicolon separated list of plain email addresses. */
    protected function normalizeEmailList($value)
    {
        $value = trim((string) $value);
        if ($value === '') { return ''; }
        $parts = preg_split('/[;,]+/', $value);
        $clean = array();
        foreach ($parts as $part) {
            $email = trim($part);
            if ($email === '') { continue; }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return false; }
            $clean[strtolower($email)] = $email;
        }
        return implode(',', array_values($clean));
    }

    /** @return string */
    protected function formatMoney($amount, $outputlangs)
    {
        global $conf;
        $value = price((float) $amount, 0, $outputlangs, 1, -1, -1, $conf->currency);
        return html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8');
    }
}
