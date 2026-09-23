<?php
/* Sending one notice: the final checks, the reservation and the mail server. */
trait DunningNoticeServiceDelivery
{
    /**
     * Send one notice in manual or automatic mode. Both paths use the same
     * fail-closed send reservation and duplicate protection.
     *
     * @return array|false
     */
    /**
     * One dunning letter for a customer without an email address, recorded as
     * sent by post (#39).
     *
     * It goes through the same reservation as an email, so the stage is
     * completed once and its fee is booked once. What differs is only the way
     * out: paper instead of SMTP.
     *
     * @param Facture $invoice Invoice
     * @param array $case Stored case
     * @param int $level Stage
     * @param User $user Acting user
     * @return array|false fullpath and relative path of the letter
     */
    public function sendPostalNotice($invoice, $case, $level, $user)
    {
        global $conf, $langs;
        $this->error = '';
        $this->errors = array();
        if (empty($case) || $case['status'] !== 'open' || !empty($case['paused'])) {
            $this->error = 'Dunning case is closed or paused.';
            return false;
        }
        if ($this->manager->getDunningBlock((int) $invoice->id) !== null) {
            $this->error = 'Dunning is blocked on the invoice or its customer (#37).';
            return false;
        }
        $lang = (!empty($invoice->thirdparty) && !empty($invoice->thirdparty->default_lang)) ? (string) $invoice->thirdparty->default_lang : $langs->defaultlang;
        $template = $this->getTemplate($level, $lang, null, (int) $this->manager->resolveProfile((int) $invoice->id)['profile_id']);
        if ($template === false) {
            return false;
        }
        $body = $this->renderTemplate($template['body'], $invoice, $case, $level, $lang);
        $breakdown = $this->manager->getAmountBreakdown($invoice, $case, $level);
        $historyMessage = 'Postal letter, stage '.((int) $level).', '.number_format((float) $breakdown['total'], 2, '.', '').' '.$conf->currency;
        $reservation = $this->manager->reserveNoticeAttempt($case, $langs->transnoentitiesnoconv('MahnwesenPostalRecipient'), $level,
            (float) $breakdown['invoice'], $historyMessage, $user, 'postal', array(
                'subject' => $this->renderTemplate($template['subject'], $invoice, $case, $level, $lang),
                'body_html' => $body,
                'fee' => (float) $breakdown['fee'],
                'interest' => (float) $breakdown['interest'],
                'total' => (float) $breakdown['total'],
                'template_id' => !empty($template['source_id']) ? (int) $template['source_id'] : 0,
                'template_lang' => $lang,
            ));
        if ($reservation === false) {
            $this->error = $this->manager->error ?: 'Unable to reserve the postal notice';
            return false;
        }
        $attemptId = (int) $reservation['id'];
        $pdf = $this->generatePdf($invoice, $case, $level, $body, false, $lang);
        if ($pdf === false) {
            $this->manager->finalizeNoticeAttempt($attemptId, false, 'The letter could not be built: '.$this->error, $case, $user, false);
            return false;
        }
        $this->manager->addNoticeAttemptFile($attemptId, 'dunning', basename($pdf['fullpath']), $pdf['fullpath']);
        if (!$this->manager->finalizeNoticeAttempt($attemptId, true, $historyMessage.' (printed)', $case, $user, false)) {
            $this->error = $this->manager->error ?: 'The postal notice could not be recorded';
            return false;
        }
        $this->publishToInvoiceDocuments($invoice, $level, $pdf['fullpath']);
        $this->manager->syncInvoiceCase((int) $invoice->id, $user);
        return $pdf;
    }

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
        if ($this->manager->getDunningBlock((int) $invoice->id) !== null) {
            $this->error = 'Dunning is blocked on the invoice or its customer (#37).';
            return false;
        }
        $evaluation = $this->manager->evaluateInvoice((int) $invoice->id);
        $calculatedLevel = ($evaluation !== false && !empty($evaluation['eligible'])) ? (int) $evaluation['row']['stage'] : 0;
        $requiredLevel = $this->manager->getNextRequiredLevel((int) $case['id'], $calculatedLevel, $evaluation !== false ? (int) $evaluation['row']['profile_id'] : 0);
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
        // MEDIUMTEXT holds 16 MB; leave room for the other columns of the row (#20).
        if (strlen($bodyHtml) > 15000000) {
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
        if ((float) $breakdown['interest'] > 0.000001) { $historyMessage .= "\nLate-payment interest: ".number_format($breakdown['interest'], 2, '.', '').' '.$GLOBALS['conf']->currency; }
        $historyMessage .= "\nTotal: ".number_format($breakdown['total'], 2, '.', '').' '.$GLOBALS['conf']->currency;
        $deadline = $this->manager->getPaymentDeadline((int) $level, null, (int) $breakdown['profile_id']);
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
                'interest' => (float) $breakdown['interest'],
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
        if ($this->changedSinceReservation($freshInvoice, $case, $level, $breakdown, $recipient, (int) $recipientOption['contact_id'])) {
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
                // Dolibarr's track id of the invoice: its email collector links replies to it (#28).
                'inv'.$freshInvoice->id,
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
            'interest' => $breakdown['interest'],
            'total' => $breakdown['total'],
        );
    }

    /**
     * Whether an invoice changed after its notice was reserved (#14, #38).
     *
     * Payments are maintained in separate Dolibarr tables and cannot be held
     * behind the module case lock, so the invoice is read once more right
     * before the SMTP ambiguity window: stage, amount, fee, interest and the
     * recipient must still be what the letter says.
     *
     * @param Facture $invoice Invoice, freshly loaded
     * @param array $case Case of the reservation
     * @param int $level Stage
     * @param array $breakdown Amounts of the reservation
     * @param string $recipient Email address
     * @param int $contactId Recipient contact, 0 for the customer
     * @return bool
     */
    protected function changedSinceReservation($invoice, $case, $level, $breakdown, $recipient, $contactId)
    {
        $this->manager->refreshWorkflowCaches((int) $case['id']);
        $evaluation = $this->manager->evaluateInvoice((int) $invoice->id);
        if ($evaluation === false || empty($evaluation['eligible'])) {
            return true;
        }
        $profileId = (int) $evaluation['row']['profile_id'];
        $requiredLevel = $this->manager->getNextRequiredLevel((int) $case['id'], (int) $evaluation['row']['stage'], $profileId);
        $requiredAt = $requiredLevel > 0 ? $this->manager->calculateWorkflowStageDueAt((int) $case['id'], (string) $evaluation['row']['due_ymd'], $requiredLevel, $profileId) : null;
        $now = $this->manager->getAmountBreakdown($invoice, array('remaining_amount' => (float) $evaluation['remain_to_pay']), $level);
        $option = $this->getRecipientOptionByEmail($invoice, $recipient);
        return $requiredLevel !== (int) $level || ($requiredAt && (int) $this->db->jdate($requiredAt) > dol_now())
            || abs((float) $evaluation['remain_to_pay'] - (float) $breakdown['invoice']) > 0.000001
            || abs((float) $now['fee'] - (float) $breakdown['fee']) > 0.000001
            || abs((float) $now['interest'] - (float) $breakdown['interest']) > 0.01
            || $option === false || (int) $option['contact_id'] !== (int) $contactId;
    }

    /**
     * Send what decideAutomaticSend() decided for one invoice, with the
     * template of its stage (#38).
     *
     * @param array $decision A send decision
     * @param User $user Acting user
     * @param string $mode manual or automatic
     * @return array|false
     */
    public function sendDecision($decision, $user, $mode)
    {
        $invoice = $decision['invoice'];
        $case = $decision['case'];
        $level = (int) $decision['level'];
        $template = $decision['template'];
        $lang = (string) $decision['lang'];
        $subject = $this->renderTemplate($template['subject'], $invoice, $case, $level, $lang);
        $body = $this->renderTemplate($template['body'], $invoice, $case, $level, $lang);
        // Only native templates exist; their "join files" flag decides.
        $attachInvoice = ((string) ($template['joinfiles'] ?? '') === '1');
        return $this->sendNotice($invoice, $case, $level, (string) $decision['recipient'], $subject, $body, $attachInvoice, $user, $mode,
            isset($template['email_from']) ? $template['email_from'] : '', isset($template['lang']) ? $template['lang'] : $lang, '', '',
            !empty($template['source_id']) ? (int) $template['source_id'] : 0);
    }

    /**
     * One email with one letter for several invoices of the same customer (#38).
     *
     * Every invoice keeps its own attempt: its stage, fee, interest and
     * history are recorded as if it went out alone, and the reservation checks
     * each one, the recipient included. The attempts share the mail, so they
     * share its outcome.
     *
     * @param array $decisions Send decisions of decideAutomaticSend(), one customer and one recipient
     * @param User $user Acting user
     * @param string $mode manual or automatic
     * @return array sent (invoice refs), failed (invoice ref => message)
     */
    public function sendCollectiveNotice($decisions, $user, $mode)
    {
        global $conf;
        $this->error = '';
        $this->errors = array();
        $mode = ($mode === 'automatic') ? 'automatic' : 'manual';
        $result = array('sent' => array(), 'failed' => array());
        $first = $decisions[0];
        $recipient = (string) $first['recipient'];
        $lang = (string) $first['lang'];
        $from = $this->getFromEmail((string) ($first['template']['email_from'] ?? ''));
        $problem = '';
        if (($mode === 'automatic' && !$this->isAutomaticSendEnabled()) || ($mode === 'manual' && !$this->isManualSendEnabled())) {
            $problem = 'Sending dunning emails is disabled in module settings.';
        } elseif ($from === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $problem = 'No valid sender or recipient email.';
        }
        $items = array();
        foreach ($decisions as $decision) {
            $invoice = $decision['invoice'];
            $option = $problem === '' ? $this->getRecipientOptionByEmail($invoice, $recipient) : false;
            if ($problem !== '') {
                $result['failed'][(string) $invoice->ref] = $problem;
            } elseif ($this->manager->getDunningBlock((int) $invoice->id) !== null) {
                $result['failed'][(string) $invoice->ref] = 'Dunning is blocked on the invoice or its customer (#37).';
            } elseif ($option === false || (int) $invoice->socid !== (int) $first['invoice']->socid) {
                $result['failed'][(string) $invoice->ref] = 'Recipient is no longer an active BILLING contact or the customer email.';
            } else {
                $items[] = array('invoice' => $invoice, 'case' => $decision['case'], 'level' => (int) $decision['level'], 'contact_id' => (int) $option['contact_id'],
                    'breakdown' => $this->manager->getAmountBreakdown($invoice, $decision['case'], (int) $decision['level']));
            }
        }
        $outputlangs = new Translate('', $conf);
        $outputlangs->setDefaultLang($lang);
        $outputlangs->loadLangs(array('main', 'bills', 'mahnwesen@mahnwesen'));
        list($subject, $bodyHtml) = $this->collectiveMessage($items, $outputlangs);
        // Each invoice is reserved alone, under its own lock and checks.
        $reserved = array();
        foreach ($items as $item) {
            $reservation = $this->manager->reserveNoticeAttempt($item['case'], $recipient, $item['level'], (float) $item['breakdown']['invoice'],
                'Collective letter', $user, $mode, array(
                    'contact_id' => $item['contact_id'], 'sender' => $from, 'subject' => $subject, 'body_html' => $bodyHtml,
                    'fee' => (float) $item['breakdown']['fee'], 'interest' => (float) $item['breakdown']['interest'],
                    'total' => (float) $item['breakdown']['total'], 'template_lang' => $lang,
                ));
            if ($reservation === false) {
                $result['failed'][(string) $item['invoice']->ref] = $this->manager->error ?: 'Unable to reserve dunning notice send';
                continue;
            }
            $fresh = new Facture($this->db);
            if ($fresh->fetch((int) $reservation['case']['invoice_id']) <= 0) {
                $this->manager->finalizeNoticeAttempt((int) $reservation['id'], false, 'Unable to reload invoice after send reservation.', $reservation['case'], $user, false);
                $result['failed'][(string) $item['invoice']->ref] = 'Unable to reload invoice after send reservation.';
                continue;
            }
            $fresh->fetch_thirdparty();
            $reserved[] = array_merge($item, array('invoice' => $fresh, 'attempt_id' => (int) $reservation['id'], 'case' => $reservation['case'], 'breakdown' => $reservation['breakdown']));
        }
        if (empty($reserved)) {
            return $result;
        }
        // The letter names exactly the invoices that were reserved.
        list($subject, $bodyHtml) = $this->collectiveMessage($reserved, $outputlangs);
        $refs = array();
        foreach ($reserved as $item) {
            $refs[] = (string) $item['invoice']->ref;
        }
        $historyMessage = 'Collective letter for '.implode(', ', $refs)."
Subject: ".$subject."
From: ".$from;
        $cancel = function ($message, $ambiguous = false) use (&$result, $reserved, $user, $historyMessage) {
            foreach ($reserved as $item) {
                $this->manager->finalizeNoticeAttempt($item['attempt_id'], false, $historyMessage."
Error: ".$message, $item['case'], $user, $ambiguous);
                $result['failed'][(string) $item['invoice']->ref] = $message;
            }
            return $result;
        };
        $filename = dol_sanitizeFileName($outputlangs->transnoentities('MahnwesenCollectiveFile').'_'.dol_print_date(dol_now(), 'dayrfc').'.pdf');
        $dir = $this->getAttemptEvidenceDir($reserved[0]['attempt_id']);
        if (!is_dir($dir) && dol_mkdir($dir) < 0) {
            return $cancel('Unable to create the evidence directory.');
        }
        $pdfPath = $dir.'/'.$filename;
        if (!$this->generateCollectivePdf($reserved, $lang, $pdfPath, $reserved[0]['contact_id'])) {
            return $cancel($this->error ?: 'Collective PDF generation failed.');
        }
        // Every attempt keeps its own copy as evidence.
        foreach ($reserved as $index => $item) {
            $copy = $this->getAttemptEvidenceDir($item['attempt_id']).'/'.$filename;
            if ($copy !== $pdfPath && ((!is_dir(dirname($copy)) && dol_mkdir(dirname($copy)) < 0) || !@copy($pdfPath, $copy))) {
                return $cancel('Unable to keep a copy of the collective letter.');
            }
            $reserved[$index]['pdf'] = $copy;
            if (!$this->manager->addNoticeAttemptFile($item['attempt_id'], 'dunning', $filename, $copy, 'application/pdf')
                || !$this->manager->updateNoticeAttemptArtifacts($item['attempt_id'], array('fullpath' => $copy, 'relative' => 'attempts/'.$item['attempt_id'].'/'.$filename))
                || !$this->manager->updateNoticeAttemptMessage($item['attempt_id'], $subject, $bodyHtml)) {
                return $cancel('Unable to persist the attachment audit: '.$this->manager->error);
            }
        }
        foreach ($reserved as $item) {
            if ($this->changedSinceReservation($item['invoice'], $item['case'], $item['level'], $item['breakdown'], $recipient, $item['contact_id'])) {
                return $cancel('Invoice '.$item['invoice']->ref.' changed while the letter was generated. Delivery was cancelled.');
            }
        }
        $historyMessage .= "
PDF SHA-256: ".hash_file('sha256', $pdfPath);
        foreach ($reserved as $item) {
            if (!$this->manager->markNoticeAttemptSending($item['attempt_id'])) {
                return $cancel('Unable to mark the attempt as sending: '.$this->manager->error);
            }
        }
        $mail = null;
        $error = '';
        try {
            // Dolibarr's track id of the customer: its email collector links replies to it (#28).
            $mail = new CMailFile($subject, $recipient, $from, $bodyHtml, array($pdfPath), array('application/pdf'), array($filename),
                '', '', 0, 1, '', '', 'thi'.((int) $first['invoice']->socid), '', 'standard', $from);
            $sent = $mail->sendfile();
        } catch (Throwable $e) {
            $sent = 0;
            $error = get_class($e).': '.$e->getMessage();
        }
        if (empty($sent)) {
            if ($error === '') {
                $error = (is_object($mail) && !empty($mail->error)) ? $mail->error : 'CMailFile sendfile failed';
            }
            // Only a failure that certainly delivered nothing may be retried (#14).
            return $cancel($error, !$this->failedBeforeMessageData($mail));
        }
        $messageId = is_object($mail) ? (string) ($mail->message_id ?? ($mail->msgid ?? '')) : '';
        foreach ($reserved as $item) {
            $ref = (string) $item['invoice']->ref;
            if (!$this->manager->finalizeNoticeAttempt($item['attempt_id'], true, $historyMessage, $item['case'], $user, false, $messageId)) {
                $result['failed'][$ref] = 'The mailer reported success, but audit finalization failed. Do not retry; the attempt remains blocked. '.$this->manager->error;
                continue;
            }
            $result['sent'][] = $ref;
            if ($this->publishToInvoiceDocuments($item['invoice'], $item['level'], $item['pdf']) === '') {
                $this->errors[] = 'The email was sent, but the dunning PDF could not be copied to the documents of '.$ref.'.';
            }
            if ($this->manager->syncInvoiceCase((int) $item['invoice']->id, $user) === false) {
                $this->errors[] = 'The email was sent, but the case of '.$ref.' could not be synchronised: '.$this->manager->error;
            }
            $this->manager->syncHistoryToAgenda((int) $item['invoice']->id, $user);
        }
        return $result;
    }

    /**
     * Subject and text of a collective email: the invoices with their stage
     * and amount, the details in the attached letter (#38).
     *
     * @param array $items Invoice, level and breakdown each
     * @param Translate $outputlangs Language of the customer
     * @return array{0:string,1:string} Subject and HTML
     */
    protected function collectiveMessage($items, $outputlangs)
    {
        global $mysoc;
        $highest = 0;
        $refs = array();
        $lines = '';
        foreach ($items as $item) {
            $highest = max($highest, (int) $item['level']);
            $refs[] = (string) $item['invoice']->ref;
            $lines .= '<li>'.dol_escape_htmltag($item['invoice']->ref.' - '.$outputlangs->transnoentities($this->manager->getStageLabelKey((int) $item['level']))
                .' - '.$this->formatMoney($item['breakdown']['total'], $outputlangs)).'</li>';
        }
        $subject = dol_trunc($outputlangs->transnoentities('MahnwesenCollectiveTitle', $outputlangs->transnoentities($this->manager->getStageLabelKey($highest)))
            .': '.implode(', ', $refs), 250, 'right', 'UTF-8', 1);
        $body = '<p>'.dol_escape_htmltag($outputlangs->transnoentities('MahnwesenCollectiveMailIntro')).'</p><ul>'.$lines.'</ul>'
            .'<p>'.dol_escape_htmltag($outputlangs->transnoentities('MahnwesenCollectiveMailOutro')).'</p>'
            .'<p>'.dol_escape_htmltag(is_object($mysoc) ? (string) $mysoc->name : '').'</p>';
        return array($subject, $body);
    }

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
}
