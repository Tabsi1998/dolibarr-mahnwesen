<?php
/* Auto-split method trait for maintainable source files. */
trait DunningNoticeServiceMethods4
{

    /**
     * Whether a failed send certainly delivered nothing (#14).
     *
     * True when Dolibarr's mail is switched off, when the mailer could not even
     * be built, or when Dolibarr's own SMTP client (send mode smtps) never got
     * the server's 354 go-ahead for the message data: connection, TLS, login,
     * sender or all recipients failed. The client records every server reply
     * in its log. Other send modes and failures after the go-ahead may have
     * reached the customer, so they stay ambiguous.
     *
     * @param CMailFile|null $mail Mailer of the failed send
     * @return bool
     */
    public function failedBeforeMessageData($mail)
    {
        if (getDolGlobalString('MAIN_DISABLE_ALL_MAILS')) {
            return true;
        }
        if (!is_object($mail)) {
            return true;
        }
        if ((string) ($mail->sendmode ?? '') !== 'smtps' || empty($mail->smtps) || !is_object($mail->smtps) || !isset($mail->smtps->log)) {
            return false;
        }
        return !preg_match('/^354[ -]/m', (string) $mail->smtps->log);
    }

    /**
     * Generate a dunning PDF. A preview stays in the module's notices folder.
     * The PDF of a delivery attempt (context attempt_id) goes into that
     * attempt's evidence folder of the module. Any other final PDF goes into
     * the invoice documents as REF_<stage>.pdf, replacing the earlier one of
     * that stage. The invoice record itself is never modified and
     * last_main_doc is not changed.
     *
     * @param Facture $invoice Invoice
     * @param array $case Stored case
     * @param int $level Stage
     * @param string $body Rendered HTML/body
     * @param bool $preview Replaceable preview or final invoice-linked copy
     * @param string $lang Output language
     * @param array $context Exact send context (attempt_id, contact_id)
     * @return array|false
     */
    public function generatePdf($invoice, $case, $level, $body, $preview = true, $lang = '', $context = array())
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
            $location = $this->getPreviewPdfLocation($invoice, (int) $level);
            $dir = $location['dir'];
            $filename = $location['filename'];
            $relative = $location['relative'];
        } elseif (!empty($context['attempt_id'])) {
            $modulepart = 'mahnwesen';
            $dir = $this->getAttemptEvidenceDir((int) $context['attempt_id']);
            $filename = $this->getFinalPdfFilename($invoice, (int) $level);
            $relative = 'attempts/'.((int) $context['attempt_id']).'/'.$filename;
        } else {
            $root = $this->getInvoiceDocumentRoot($invoice);
            if ($root === '') { $this->error = 'Invoice document output directory is unavailable'; return false; }
            // The invoice's own document folder, so Dolibarr lists the PDF with
            // the invoice's other files; custom document paths are respected.
            $invoicePdfPath = $this->getInvoicePdfPath($invoice);
            $dir = ($invoicePdfPath !== '') ? dirname($invoicePdfPath) : rtrim($root, '/').'/'.$safeRef;
            $filename = $this->getFinalPdfFilename($invoice, (int) $level);
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

            $bodyStartY = $this->drawSpongeReminderHeader($pdf, $invoice, $level, $outputlangs, $pageWidth, $pageHeight, $marginLeft, $marginRight, $marginTop, !empty($context['contact_id']) ? (int) $context['contact_id'] : 0);
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
            // The same deadline as __MAHNWESEN_PAYMENT_DEADLINE__ in the email (#64).
            $deadline = $this->manager->getPaymentDeadline((int) $level);
            if ($deadline) {
                $y += $rowH;
                $pdf->SetFont('', '', $defaultFontSize);
                $pdf->SetXY($marginLeft + $descW - 45, $y + 1.4);
                $pdf->Cell(43, 4, $outputlangs->transnoentities('MahnwesenPaymentDeadline'), 0, 0, 'R');
                $pdf->SetXY($marginLeft + $descW + 1.5, $y + 1.4);
                $pdf->Cell($amountW - 3, 4, dol_print_date($deadline, 'day', 'tzserver', $outputlangs), 0, 0, 'R');
            }

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
    public function sendNotice($invoice, $case, $level, $recipient, $subject, $body, $attachInvoice, $user, $mode = 'manual', $templateFrom = '', $lang = '', $cc = '', $bcc = '', $templateId = 0, $extraAttachments = array(), $deliveryReceipt = false)
    {
        $this->error = '';
        $this->errors = array();
        $mode = ($mode === 'automatic') ? 'automatic' : 'manual';
        $subject = trim(dol_string_nohtmltag((string) $subject));

        if ($mode === 'automatic' && !$this->isAutomaticSendEnabled()) {
            $this->error = 'Automatic dunning email sending is disabled.';
            return false;
        }
        if ($mode === 'manual' && !$this->isManualSendEnabled()) {
            $this->error = 'Manual dunning email sending is disabled in module settings.';
            return false;
        }
        if (empty($case) || $case['status'] !== 'open' || !empty($case['paused'])) {
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
        $recipientOption = $this->getRecipientOptionByEmail($invoice, $recipient);
        if ($recipientOption === false) {
            $this->error = $this->recipientLookupFailed ? 'Recipient lookup failed; delivery was blocked.' : 'Recipient is no longer an active BILLING contact or the customer email.';
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
        $bodyHtml = (string) $this->asHtml($body);
        if (preg_match('/[\r\n]/', (string) $subject) || strlen((string) $subject) > 255) {
            $this->error = 'The email subject is invalid or longer than 255 bytes.';
            return false;
        }
        if (strlen($bodyHtml) > 60000) {
            $this->error = 'The email message is too large for the immutable delivery snapshot.';
            return false;
        }
        if (!is_array($extraAttachments)) { $extraAttachments = array(); }
        $maxExtraFiles = max(0, min(20, getDolGlobalInt('MAHNWESEN_MAX_EXTRA_ATTACHMENTS', 5)));
        $maxExtraBytes = max(1, min(100, getDolGlobalInt('MAHNWESEN_MAX_EXTRA_ATTACHMENT_MB', 10))) * 1024 * 1024;
        if (count($extraAttachments) > $maxExtraFiles) { $this->error = 'Too many additional attachments.'; return false; }
        $checkedExtraAttachments = array();
        foreach ($extraAttachments as $extra) {
            $path = isset($extra['path']) ? (string) $extra['path'] : '';
            $name = dol_sanitizeFileName(isset($extra['name']) ? (string) $extra['name'] : basename($path));
            $size = ($path !== '' && is_file($path)) ? filesize($path) : false;
            if ($path === '' || $name === '' || !is_readable($path) || $size === false || $size <= 0 || $size > $maxExtraBytes) { $this->error = 'An additional attachment is unavailable or exceeds the configured size limit.'; return false; }
            $mime = isset($extra['mime']) ? trim((string) $extra['mime']) : '';
            if (!preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#i', $mime)) { $mime = dol_mimetype($name); }
            if ($mime === '') { $mime = 'application/octet-stream'; }
            $checkedExtraAttachments[] = array('path' => $path, 'name' => $name, 'mime' => $mime);
        }

        $breakdown = $this->manager->getAmountBreakdown($invoice, $case, $level);
        $historyMessage = 'Subject: '.$subject."\nFrom: ".$from;
        if ($cc !== '') { $historyMessage .= "\nCC: ".$cc; }
        if ($bcc !== '') { $historyMessage .= "\nBCC: ".$bcc; }
        $historyMessage .= "\nInvoice amount: ".number_format($breakdown['invoice'], 2, '.', '').' '.$GLOBALS['conf']->currency;
        $historyMessage .= "\nDunning fee: ".number_format($breakdown['fee'], 2, '.', '').' '.$GLOBALS['conf']->currency;
        $historyMessage .= "\nTotal: ".number_format($breakdown['total'], 2, '.', '').' '.$GLOBALS['conf']->currency;
        $deadline = $this->manager->getPaymentDeadline((int) $level);
        if ($deadline) { $historyMessage .= "\nPayment deadline: ".dol_print_date($deadline, '%Y-%m-%d', 'tzserver'); }

        // Reserve before generating or touching an attachment. The manager
        // revalidates current amount, fee, stage, cooldown, pause and entity
        // under a row lock and returns the authoritative snapshot.
        $reservation = $this->manager->reserveNoticeAttempt(
            $case,
            $recipient,
            $level,
            (float) $breakdown['invoice'],
            $historyMessage,
            $user,
            $mode,
            array(
                'contact_id' => (int) $recipientOption['contact_id'],
                'sender' => $from,
                'cc' => $cc,
                'bcc' => $bcc,
                'subject' => (string) $subject,
                'body_html' => $bodyHtml,
                'fee' => (float) $breakdown['fee'],
                'total' => (float) $breakdown['total'],
                'template_id' => (int) $templateId,
                'template_lang' => (string) $lang,
            )
        );
        if ($reservation === false) {
            $this->error = $this->manager->error ?: 'Unable to reserve dunning notice send';
            return false;
        }
        $attemptId = (int) $reservation['id'];
        $case = $reservation['case'];
        $breakdown = $reservation['breakdown'];

        // Reload the exact invoice object after reservation so PDF metadata and
        // attachments cannot come from a stale object passed by the caller.
        $freshInvoice = new Facture($this->db);
        if ($freshInvoice->fetch((int) $case['invoice_id']) <= 0) {
            $this->error = 'Unable to reload invoice after send reservation.';
            $this->manager->finalizeNoticeAttempt($attemptId, false, $this->error, $case, $user, false);
            return false;
        }
        $freshInvoice->fetch_thirdparty();
        $pdfInfo = $this->generatePdf($freshInvoice, $case, $level, $body, false, $lang, array('attempt_id' => $attemptId, 'contact_id' => (int) $recipientOption['contact_id']));
        if ($pdfInfo === false) {
            $pdfError = $this->error ?: 'Dunning PDF generation failed.';
            $this->manager->finalizeNoticeAttempt($attemptId, false, $pdfError, $case, $user, false);
            $this->error = $pdfError;
            return false;
        }

        $files = array($pdfInfo['fullpath']);
        $mimes = array('application/pdf');
        $names = array($pdfInfo['filename']);
        if (!$this->manager->addNoticeAttemptFile($attemptId, 'dunning', $pdfInfo['filename'], $pdfInfo['fullpath'], 'application/pdf')) {
            $this->error = 'Unable to persist the dunning attachment audit: '.$this->manager->error;
            $this->manager->finalizeNoticeAttempt($attemptId, false, $this->error, $case, $user, false);
            return false;
        }
        $invoicePdf = '';
        if ($attachInvoice) {
            $sourceInvoicePdf = $this->getInvoicePdfPath($freshInvoice);
            if ($sourceInvoicePdf === '') {
                $this->error = 'The original invoice PDF was requested but is unavailable.';
                $this->manager->finalizeNoticeAttempt($attemptId, false, $this->error, $case, $user, false);
                return false;
            }
            // Attach a copy kept with the attempt. Dolibarr may regenerate its
            // main invoice PDF later; the copied bytes and stored hash must
            // continue to identify exactly what this email contained.
            $invoicePdf = dirname($pdfInfo['fullpath']).'/'.basename($sourceInvoicePdf);
            if (!@copy($sourceInvoicePdf, $invoicePdf) || !is_readable($invoicePdf) || filesize($invoicePdf) <= 0) {
                $this->error = 'Unable to create an immutable snapshot of the original invoice PDF.';
                $this->manager->finalizeNoticeAttempt($attemptId, false, $this->error, $case, $user, false);
                return false;
            }
            $files[] = $invoicePdf;
            $mimes[] = 'application/pdf';
            $names[] = basename($sourceInvoicePdf);
            if (!$this->manager->addNoticeAttemptFile($attemptId, 'invoice', basename($sourceInvoicePdf), $invoicePdf, 'application/pdf')) {
                $this->error = 'Unable to persist the invoice attachment audit: '.$this->manager->error;
                $this->manager->finalizeNoticeAttempt($attemptId, false, $this->error, $case, $user, false);
                return false;
            }
        }
        foreach ($checkedExtraAttachments as $index => $extra) {
            // Numbered, so two uploads with the same name cannot overwrite each other.
            $snapshot = dirname($pdfInfo['fullpath']).'/extra-'.($index + 1).'-'.$extra['name'];
            if (!@copy($extra['path'], $snapshot) || !is_readable($snapshot) || filesize($snapshot) <= 0) {
                $this->error = 'Unable to create an immutable snapshot of an additional attachment.';
                $this->manager->finalizeNoticeAttempt($attemptId, false, $this->error, $case, $user, false);
                return false;
            }
            $files[] = $snapshot;
            $mimes[] = $extra['mime'];
            $names[] = $extra['name'];
            if (!$this->manager->addNoticeAttemptFile($attemptId, 'additional', $extra['name'], $snapshot, $extra['mime'])) {
                $this->error = 'Unable to persist an additional attachment audit: '.$this->manager->error;
                $this->manager->finalizeNoticeAttempt($attemptId, false, $this->error, $case, $user, false);
                return false;
            }
        }
        if (!$this->manager->updateNoticeAttemptArtifacts($attemptId, $pdfInfo, $invoicePdf)) {
            $this->error = 'Unable to persist attachment hashes: '.$this->manager->error;
            $this->manager->finalizeNoticeAttempt($attemptId, false, $this->error, $case, $user, false);
            return false;
        }
        // Payments are maintained in separate Dolibarr tables and cannot be
        // held behind the module case lock. Re-read once more immediately
        // before entering the SMTP ambiguity window.
        $this->manager->refreshWorkflowCaches((int) $case['id']);
        $lastEvaluation = $this->manager->evaluateInvoice((int) $freshInvoice->id);
        $lastRequiredLevel = ($lastEvaluation !== false && !empty($lastEvaluation['eligible'])) ? $this->manager->getNextRequiredLevel((int) $case['id'], (int) $lastEvaluation['row']['stage']) : 0;
        $lastRequiredAt = $lastRequiredLevel > 0 ? $this->manager->calculateWorkflowStageDueAt((int) $case['id'], (string) $lastEvaluation['row']['due_ymd'], $lastRequiredLevel) : null;
        $lastBreakdown = $this->manager->getAmountBreakdown($freshInvoice, array('remaining_amount' => $lastEvaluation !== false ? (float) $lastEvaluation['remain_to_pay'] : 0.0), $level);
        $lastRecipientOption = $this->getRecipientOptionByEmail($freshInvoice, $recipient);
        if ($lastEvaluation === false || empty($lastEvaluation['eligible']) || $lastRequiredLevel !== (int) $level || ($lastRequiredAt && (int) $this->db->jdate($lastRequiredAt) > dol_now()) || abs((float) $lastEvaluation['remain_to_pay'] - (float) $breakdown['invoice']) > 0.000001 || abs((float) $lastBreakdown['fee'] - (float) $breakdown['fee']) > 0.000001 || $lastRecipientOption === false || (int) $lastRecipientOption['contact_id'] !== (int) $recipientOption['contact_id']) {
            $this->error = 'Invoice state changed while the final document was generated. Delivery was cancelled.';
            $this->manager->finalizeNoticeAttempt($attemptId, false, $this->error, $case, $user, false);
            return false;
        }
        $historyMessage .= "\nPDF: ".$pdfInfo['relative']."\nPDF SHA-256: ".hash_file('sha256', $pdfInfo['fullpath']);
        if ($invoicePdf !== '') { $historyMessage .= "\nInvoice PDF: ".basename($invoicePdf)."\nInvoice PDF SHA-256: ".hash_file('sha256', $invoicePdf); }
        $historyMessage .= "\nAdditional attachments: ".count($checkedExtraAttachments)."\nDelivery receipt: ".($deliveryReceipt ? 'requested' : 'not requested');
        if (!$this->manager->markNoticeAttemptSending($attemptId)) {
            $this->error = 'Unable to mark the attempt as sending: '.$this->manager->error;
            $this->manager->finalizeNoticeAttempt($attemptId, false, $this->error, $case, $user, false);
            return false;
        }

        $mail = null;
        try {
            $mail = new CMailFile(
                (string) $subject,
                (string) $recipient,
                $from,
                $bodyHtml,
                $files,
                $mimes,
                $names,
                $cc,
                $bcc,
                $deliveryReceipt ? 1 : 0,
                1,
                '',
                '',
                'mahnwesen'.$freshInvoice->id.'-attempt'.$attemptId,
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
            // Only a failure that certainly delivered nothing counts as failed
            // and may be retried; anything else stays ambiguous (#14).
            $ambiguous = !$this->failedBeforeMessageData($mail);
            $failedMessage = $historyMessage."\nError: ".$this->error;
            if (!$this->manager->finalizeNoticeAttempt($attemptId, false, $failedMessage, $case, $user, $ambiguous)) {
                $this->errors[] = 'Delivery failed and audit finalization also failed: '.$this->manager->error;
            }
            $this->error .= $ambiguous
                ? ' The SMTP outcome is treated as ambiguous; an administrator must resolve the attempt before retrying.'
                : ' The mail server never received the message, so nothing was delivered; the notice can be sent again.';
            return false;
        }

        $messageId = '';
        if (is_object($mail)) {
            if (!empty($mail->message_id)) { $messageId = (string) $mail->message_id; }
            elseif (!empty($mail->msgid)) { $messageId = (string) $mail->msgid; }
        }
        if (!$this->manager->finalizeNoticeAttempt($attemptId, true, $historyMessage, $case, $user, false, $messageId)) {
            $this->error = 'The mailer reported success, but audit finalization failed. Do not retry; the attempt remains blocked. '.$this->manager->error;
            return false;
        }

        // The invoice documents show the dunning PDF of each stage under its
        // plain name; the attempt folder keeps the exact evidence.
        if ($this->publishToInvoiceDocuments($freshInvoice, $level, $pdfInfo['fullpath']) === '') {
            $this->errors[] = 'The email was sent, but the dunning PDF could not be copied to the invoice documents.';
        }

        // Advance only to the next sequentially allowed stage. A failure here
        // must not turn a successfully delivered email into a retryable send.
        $syncAfterSend = $this->manager->syncInvoiceCase((int) $freshInvoice->id, $user);
        if ($syncAfterSend === false) {
            $this->errors[] = 'E-Mail wurde versendet, aber der Mahnfall konnte danach nicht synchronisiert werden: '.$this->manager->error;
        }
        $this->manager->syncHistoryToAgenda((int) $freshInvoice->id, $user);

        return array(
            'recipient' => $recipient,
            'subject' => $subject,
            'pdf' => $pdfInfo,
            'invoice_pdf' => $invoicePdf,
            'mode' => $mode,
            'cc' => $cc,
            'bcc' => $bcc,
            'attempt_id' => $attemptId,
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
