<?php
/* Drawing the dunning PDF: the frame here, the amounts in the layout chosen in the setup (#76). */
require_once dirname(__DIR__).'/core/modules/mahnwesen/modules_mahnwesen.php';

trait DunningNoticeServicePdf
{
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
            list($pdf, $frame) = $this->startLetter($invoice, $outputlangs, $outputlangs->trans($this->manager->getStageLabelKey((int) $level)).' '.$invoice->ref);
            $marginLeft = $frame['left'];
            $marginRight = $frame['right'];
            $pageWidth = $frame['width'];
            $pageHeight = $frame['height'];
            $bodyStartY = $this->drawSpongeReminderHeader($pdf, $invoice, $level, $outputlangs, $pageWidth, $pageHeight, $marginLeft, $marginRight, $frame['top'], !empty($context['contact_id']) ? (int) $context['contact_id'] : 0);
            $defaultFontSize = $frame['font'];
            $contentWidth = $frame['content'];

            // The layout chosen in the setup draws the amounts; the frame is the
            // same for every layout (#76).
            $layout = ModelePDFMahnwesen::selected();
            $bodyY = $layout->drawAmounts($pdf, $this, $invoice, (int) $level, $breakdown, $outputlangs, array(
                'left' => $marginLeft, 'right' => $marginRight, 'width' => $pageWidth,
                'content' => $contentWidth, 'font' => $defaultFontSize, 'top' => $bodyStartY,
            ));
            // Printed letter body. The dedicated PDF cleanup removes email-only
            // signature graphics and supports an explicit PDF end marker.
            $this->finishLetter($pdf, $invoice, $outputlangs, $frame, $bodyY, $this->prepareBodyForPdf($body), $fullpath);
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
     * Start a letter: page format, margins, the font of the language and the
     * letterhead of Dolibarr's PDF setup (#25, #38).
     *
     * @param Facture $invoice Invoice the letterhead belongs to
     * @param Translate $outputlangs Language of the customer
     * @param string $title Title of the PDF document
     * @return array{0:TCPDF,1:array} The PDF and its frame
     */
    protected function startLetter($invoice, $outputlangs, $title)
    {
        global $mysoc;
        $format = pdf_getFormat($outputlangs);
        $pageWidth = (float) $format['width'];
        $pageHeight = (float) $format['height'];
        $pdf = pdf_getInstance(array($pageWidth, $pageHeight), $format['unit'], 'P');
        $pdf->SetCreator('Dolibarr Mahnwesen');
        $pdf->SetAuthor(is_object($mysoc) ? (string) $mysoc->name : 'Dolibarr');
        $pdf->SetTitle($title);
        $frame = array(
            'left' => getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10),
            'right' => getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10),
            'top' => getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10),
            'bottom' => getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10),
            'width' => $pageWidth,
            'height' => $pageHeight,
            'font' => pdf_getPDFFontSize($outputlangs),
        );
        $frame['content'] = $pageWidth - $frame['left'] - $frame['right'];
        $pdf->SetMargins($frame['left'], $frame['top'], $frame['right']);
        // Reserve room for pdf_pagefoot just like standard Dolibarr models.
        $pdf->SetAutoPageBreak(true, max(24, $frame['bottom'] + 14));
        if (method_exists($pdf, 'setPrintHeader')) { $pdf->setPrintHeader(false); $pdf->setPrintFooter(false); }
        // The font Dolibarr's own models use for the output language (#25).
        $pdf->SetFont(pdf_getPDFFont($outputlangs));
        $pdf->AddPage();
        $this->addLetterhead($pdf, $invoice);
        return array($pdf, $frame);
    }

    /**
     * Finish a letter: its text, the payment terms of the invoice and
     * Dolibarr's footer on every page, then write the file (#25, #38).
     *
     * @param TCPDF $pdf PDF
     * @param Facture $invoice Invoice the payment terms and footer belong to
     * @param Translate $outputlangs Language of the customer
     * @param array $frame From startLetter()
     * @param float $bodyY Where the text starts
     * @param string $body Text of the letter, HTML
     * @param string $fullpath File to write
     * @return void
     */
    protected function finishLetter($pdf, $invoice, $outputlangs, $frame, $bodyY, $body, $fullpath)
    {
        global $mysoc;
        // Printed letter body. The dedicated PDF cleanup removes email-only
        // signature graphics and supports an explicit PDF end marker.
        $pdf->SetY($bodyY);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('', '', max(7, $frame['font'] - 1));
        $pdf->writeHTML('<div style="font-size:9pt; line-height:1.20;">'.$this->asHtml($body).'</div>', true, false, true, false, '');
        // Keep payment terms/bank information in the same visual family as the
        // invoice when enough room remains. If content flows, TCPDF can add a
        // page; each page still receives the standard Dolibarr footer.
        $paymentY = $pdf->GetY() + 5;
        if ($paymentY < $frame['height'] - 55) {
            $this->drawSpongePaymentInfo($pdf, $invoice, $outputlangs, $paymentY, $frame['width'], $frame['left'], $frame['right']);
        }
        // Standard Dolibarr invoice footer: same free-text area/page number
        // handling as Sponge. Footer helpers write into the reserved bottom
        // area; the automatic page break is off while they draw, otherwise a
        // short free-text footer can create an almost empty second page.
        $showDetails = getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', 0);
        $pdf->SetAutoPageBreak(false, 0);
        $numPages = $pdf->getNumPages();
        for ($page = 1; $page <= $numPages; $page++) {
            $pdf->setPage($page);
            pdf_pagefoot($pdf, $outputlangs, 'INVOICE_FREE_TEXT', $mysoc, $frame['bottom'], $frame['left'], $frame['height'], $invoice, $showDetails, 0, $frame['width'], '');
        }
        $pdf->Output($fullpath, 'F');
    }

    /**
     * One letter for several invoices of the same customer, like a statement
     * of account (#38).
     *
     * Every invoice keeps its own stage, fee and interest; the letter lists
     * them and names the highest stage in its title.
     *
     * @param array $items Each invoice, case, level and breakdown
     * @param string $lang Language of the customer
     * @param string $fullpath File to write
     * @param int $contactId Recipient contact, 0 for the customer
     * @return bool
     */
    public function generateCollectivePdf($items, $lang, $fullpath, $contactId = 0)
    {
        global $conf;
        $this->error = '';
        $outputlangs = new Translate('', $conf);
        $outputlangs->setDefaultLang($lang);
        $outputlangs->loadLangs(array('main', 'bills', 'companies', 'compta', 'mahnwesen@mahnwesen'));
        $first = $items[0];
        $highest = 0;
        foreach ($items as $item) {
            $highest = max($highest, (int) $item['level']);
        }
        try {
            list($pdf, $frame) = $this->startLetter($first['invoice'], $outputlangs, $outputlangs->transnoentities('MahnwesenCollectiveTitle',
                $outputlangs->transnoentities($this->manager->getStageLabelKey($highest))));
            $y = $this->drawSpongeReminderHeader($pdf, $first['invoice'], $highest, $outputlangs, $frame['width'], $frame['height'],
                $frame['left'], $frame['right'], $frame['top'], (int) $contactId, count($items));
            // The statement: one row per invoice, with its own stage and claims.
            $widths = array(34, 24, 38, 30, 30);
            $widths[] = $frame['content'] - array_sum($widths);
            $heads = array('Invoice', 'DateDue', 'DunningStage', 'MahnwesenPartInvoice', 'MahnwesenPartFee', 'MahnwesenPartInterest');
            $pdf->SetDrawColor(128, 128, 128);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('', 'B', max(6, $frame['font'] - 2));
            $x = $frame['left'];
            foreach ($heads as $index => $head) {
                $pdf->SetXY($x, $y);
                $pdf->Cell($widths[$index], 6, $outputlangs->transnoentities($head), 1, 0, $index >= 3 ? 'R' : 'L');
                $x += $widths[$index];
            }
            $pdf->SetFont('', '', max(6, $frame['font'] - 2));
            $total = 0.0;
            foreach ($items as $item) {
                $y += 6;
                $breakdown = $item['breakdown'];
                $cells = array((string) $item['invoice']->ref, dol_print_date($item['invoice']->date_lim_reglement, 'day', 'tzserver', $outputlangs),
                    $outputlangs->transnoentities($this->manager->getStageLabelKey((int) $item['level'])),
                    $this->formatMoney($breakdown['invoice'], $outputlangs), $this->formatMoney($breakdown['fee'], $outputlangs),
                    $this->formatMoney($breakdown['interest'], $outputlangs));
                $x = $frame['left'];
                foreach ($cells as $index => $cell) {
                    $pdf->SetXY($x, $y);
                    $pdf->Cell($widths[$index], 6, $cell, 1, 0, $index >= 3 ? 'R' : 'L');
                    $x += $widths[$index];
                }
                $total += (float) $breakdown['total'];
            }
            $y += 8;
            $pdf->SetFont('', 'B', $frame['font']);
            $pdf->SetXY($frame['left'], $y);
            $pdf->Cell($frame['content'], 5, $outputlangs->transnoentities('MahnwesenLetterTotal').' '.$this->formatMoney($total, $outputlangs), 0, 0, 'R');
            $deadline = $this->manager->getPaymentDeadline($highest, null, (int) $first['breakdown']['profile_id']);
            $text = '<p>'.dol_escape_htmltag($outputlangs->transnoentities('MahnwesenCollectiveText')).'</p>';
            if ($deadline) {
                $text .= '<p>'.dol_escape_htmltag($outputlangs->transnoentities('MahnwesenLetterDeadline', dol_print_date($deadline, 'day', 'tzserver', $outputlangs))).'</p>';
            }
            $this->finishLetter($pdf, $first['invoice'], $outputlangs, $frame, $y + 12, $text, $fullpath);
        } catch (Throwable $e) {
            $this->error = get_class($e).': '.$e->getMessage();
            dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
            return false;
        }
        if (!is_file($fullpath) || filesize($fullpath) <= 0) {
            $this->error = 'Generated collective PDF is missing or empty';
            return false;
        }
        return true;
    }

    /**
     * Make an email template suitable for a printed Sponge-style letter.
     * Email-only logos/signatures should not turn a one-page reminder into a
     * multi-page PDF. Authors can explicitly end the PDF body with the marker
     * <!--MAHNWESEN_PDF_END-->.
     *
     * @param string $body Rendered email HTML
     * @return string
     */
    /**
     * Draw the letterhead of Dolibarr's PDF setup (MAIN_ADD_PDF_BACKGROUND) on
     * the page, as Dolibarr's invoice models do (#25).
     *
     * @param TCPDF $pdf PDF
     * @param Facture $invoice Invoice
     * @return bool Whether a letterhead was drawn
     */
    protected function addLetterhead($pdf, $invoice)
    {
        global $conf;
        $background = getDolGlobalString('MAIN_ADD_PDF_BACKGROUND');
        if ($background === '' || !method_exists($pdf, 'setSourceFile')) {
            return false;
        }
        $dir = !empty($conf->mycompany->multidir_output[(int) $invoice->entity]) ? $conf->mycompany->multidir_output[(int) $invoice->entity] : $conf->mycompany->dir_output;
        $file = $dir.'/'.$background;
        if (!is_readable($file)) {
            dol_syslog(__METHOD__.' letterhead '.$file.' is not readable', LOG_WARNING);
            return false;
        }
        try {
            $pdf->setSourceFile($file);
            $pdf->useTemplate($pdf->importPage(1));
            return true;
        } catch (Throwable $e) {
            dol_syslog(__METHOD__.' letterhead '.$file.' failed: '.$e->getMessage(), LOG_WARNING);
            return false;
        }
    }

    protected function prepareBodyForPdf($body)
    {
        $body = (string) $body;
        $marker = '<!--MAHNWESEN_PDF_END-->';
        $markerPos = stripos($body, $marker);
        if ($markerPos !== false) {
            $body = substr($body, 0, $markerPos);
        }

        // Never embed email tracking/signature images in the letter. The
        // company logo already comes from the same Dolibarr company setup as
        // the Sponge invoice model.
        $body = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $body);
        $body = preg_replace('~<img\b[^>]*>~is', '', $body);

        // The letter ends where the template says so, not at a guessed
        // salutation: that guess cut the company name after "Mit freundlichen
        // Grüßen" (#25). Text after <!--MAHNWESEN_PDF_END--> stays in the email.
        return trim($body);
    }

    /**
     * Draw a Sponge-like invoice/reminder header: logo, title and the same
     * sender/recipient frame geometry as Dolibarr's pdf_sponge invoice model.
     *
     * @return float Y position below the address frames
     */
    protected function drawSpongeReminderHeader(&$pdf, $invoice, $level, $outputlangs, $pageWidth, $pageHeight, $marginLeft, $marginRight, $marginTop, $recipientContactId = 0, $collectiveCount = 0)
    {
        global $conf, $mysoc;
        $defaultFontSize = pdf_getPDFFontSize($outputlangs);
        $cornerRadius = getDolGlobalInt('MAIN_PDF_FRAME_CORNER_RADIUS', 0);
        $ltr = ($outputlangs->trans('DIRECTION') === 'rtl') ? 'R' : 'L';

        pdf_pagehead($pdf, $outputlangs, $pageHeight);
        $pdf->SetTextColor(0, 0, 60);
        $pdf->SetFont('', 'B', $defaultFontSize + 3);

        // Company logo: use the same small-logo preference as Sponge.
        $w = 110;
        $posy = $marginTop;
        $posx = $pageWidth - $marginRight - $w;
        $pdf->SetXY($marginLeft, $posy);
        if (!getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO') && is_object($mysoc)) {
            if (!empty($mysoc->logo)) {
                $logodir = !empty($conf->mycompany->multidir_output[$invoice->entity]) ? $conf->mycompany->multidir_output[$invoice->entity] : $conf->mycompany->dir_output;
                $logo = '';
                if (!getDolGlobalInt('MAIN_PDF_USE_LARGE_LOGO') && !empty($mysoc->logo_small)) {
                    $logo = rtrim($logodir, '/').'/logos/thumbs/'.$mysoc->logo_small;
                }
                if ($logo === '' || !is_readable($logo)) {
                    $logo = rtrim($logodir, '/').'/logos/'.$mysoc->logo;
                }
                if (is_readable($logo)) {
                    $pdf->Image($logo, $marginLeft, $posy, 0, pdf_getHeightForLogo($logo));
                } else {
                    $pdf->MultiCell($w, 4, $outputlangs->convToOutputCharset((string) $mysoc->name), 0, $ltr);
                }
            } else {
                $pdf->MultiCell($w, 4, $outputlangs->convToOutputCharset((string) $mysoc->name), 0, $ltr);
            }
        }

        // Right-side document identity follows Sponge: dark-blue title and
        // compact invoice metadata below it.
        $stageTitle = $outputlangs->transnoentities($this->manager->getStageLabelKey((int) $level));
        $pdf->SetXY($posx, $posy);
        $pdf->SetTextColor(0, 0, 60);
        $pdf->SetFont('', 'B', $defaultFontSize + 3);
        if ($collectiveCount > 1) {
            // A letter for several invoices names itself as one, with the highest stage (#38).
            $pdf->MultiCell($w, 3, $outputlangs->transnoentities('MahnwesenCollectiveTitle', $stageTitle), 0, 'R');
            $metaY = $pdf->GetY() + 1;
            $pdf->SetFont('', '', max(6, $defaultFontSize - 2));
            $pdf->SetXY($posx, $metaY);
            $pdf->MultiCell($w, 3, $outputlangs->transnoentities('MahnwesenCollectiveCount', (int) $collectiveCount).' - '.dol_print_date(dol_now(), 'day', false, $outputlangs, true), 0, 'R');
        } else {
            $pdf->MultiCell($w, 3, $stageTitle.' '.$outputlangs->convToOutputCharset((string) $invoice->ref), 0, 'R');
            $metaY = $pdf->GetY() + 1;
            $pdf->SetFont('', '', max(6, $defaultFontSize - 2));
            $pdf->SetXY($posx, $metaY);
            $pdf->MultiCell($w, 3, $outputlangs->transnoentities('DateInvoice').' : '.dol_print_date($invoice->date, 'day', false, $outputlangs, true), 0, 'R');
            $metaY = $pdf->GetY();
            $pdf->SetXY($posx, $metaY);
            $pdf->MultiCell($w, 3, $outputlangs->transnoentities('DateDue').' : '.dol_print_date($invoice->date_lim_reglement, 'day', false, $outputlangs, true), 0, 'R');
        }
        if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_CODE') && !empty($invoice->thirdparty->code_client)) {
            $metaY = $pdf->GetY();
            $pdf->SetXY($posx, $metaY);
            $pdf->MultiCell($w, 3, $outputlangs->transnoentities('CustomerCode').' : '.$outputlangs->convToOutputCharset((string) $invoice->thirdparty->code_client), 0, 'R');
        }

        // Address frames: deliberately use Sponge's geometry and Dolibarr's
        // native address builders instead of a custom card design.
        $frameY = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 40 : 42;
        $frameH = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 38 : 40;
        $senderW = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 82;
        $senderX = $marginLeft;
        if (getDolGlobalString('MAIN_INVERT_SENDER_RECIPIENT')) {
            $senderX = $pageWidth - $marginRight - 80;
        }
        $senderAddress = is_object($mysoc) ? pdf_build_address($outputlangs, $mysoc, $invoice->thirdparty, '', 0, 'source', $invoice) : '';
        if (!getDolGlobalString('MAIN_PDF_NO_SENDER_FRAME')) {
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('', '', $defaultFontSize - 2);
            $pdf->SetXY($senderX, $frameY - 5);
            $pdf->MultiCell($senderW, 5, $outputlangs->transnoentities('BillFrom'), 0, $ltr);
            $pdf->SetFillColor(230, 230, 230);
            $pdf->RoundedRect($senderX, $frameY, $senderW, $frameH, $cornerRadius, '1234', 'F');
        }
        $senderTextY = $frameY + 3;
        if (!getDolGlobalString('MAIN_PDF_HIDE_SENDER_NAME') && is_object($mysoc)) {
            $pdf->SetTextColor(0, 0, 60);
            $pdf->SetFont('', 'B', $defaultFontSize);
            $pdf->SetXY($senderX + 2, $senderTextY);
            $pdf->MultiCell($senderW - 2, 4, $outputlangs->convToOutputCharset((string) $mysoc->name), 0, $ltr);
            $senderTextY = $pdf->GetY();
        }
        $pdf->SetTextColor(0, 0, 60);
        $pdf->SetFont('', '', $defaultFontSize - 1);
        $pdf->SetXY($senderX + 2, $senderTextY);
        $pdf->MultiCell($senderW - 2, 4, $senderAddress, 0, $ltr);

        // Billing contact has the same priority as the Sponge invoice model.
        $useContact = false;
        $ids = array_map('intval', (array) $invoice->getIdContact('external', 'BILLING'));
        $recipientContactId = (int) $recipientContactId;
        if ($recipientContactId > 0 && in_array($recipientContactId, $ids, true)) {
            $useContact = ($invoice->fetch_contact($recipientContactId) > 0);
        }
        $recipientParty = ($useContact && is_object($invoice->contact) && $invoice->contact->socid != $invoice->thirdparty->id && getDolGlobalInt('MAIN_USE_COMPANY_NAME_OF_CONTACT', 1)) ? $invoice->contact : $invoice->thirdparty;
        $recipientName = is_object($recipientParty) ? pdfBuildThirdpartyName($recipientParty, $outputlangs) : '';
        $recipientAddress = pdf_build_address($outputlangs, $mysoc, $invoice->thirdparty, ($useContact ? $invoice->contact : ''), ($useContact ? 1 : 0), 'target', $invoice);
        $recipientW = getDolGlobalString('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 100;
        if ($pageWidth < 210) { $recipientW = 84; }
        $recipientX = $pageWidth - $marginRight - $recipientW;
        if (getDolGlobalString('MAIN_INVERT_SENDER_RECIPIENT')) { $recipientX = $marginLeft; }
        if (!getDolGlobalString('MAIN_PDF_NO_RECIPENT_FRAME')) {
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('', '', $defaultFontSize - 2);
            $pdf->SetXY($recipientX + 2, $frameY - 5);
            $pdf->MultiCell($recipientW - 2, 5, $outputlangs->transnoentities('BillTo'), 0, $ltr);
            $pdf->RoundedRect($recipientX, $frameY, $recipientW, $frameH, $cornerRadius, '1234', 'D');
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('', 'B', $defaultFontSize);
        $pdf->SetXY($recipientX + 2, $frameY + 3);
        $pdf->MultiCell($recipientW - 2, 3, $recipientName, 0, $ltr);
        $recipientTextY = $pdf->GetY();
        $pdf->SetFont('', '', $defaultFontSize - 1);
        $pdf->SetXY($recipientX + 2, $recipientTextY);
        $pdf->MultiCell($recipientW - 2, 4, $recipientAddress, 0, $ltr);
        $pdf->SetTextColor(0, 0, 0);

        return $frameY + $frameH + 8;
    }

    /**
     * Draw compact payment details with the same functions/wording used by
     * Dolibarr invoice PDFs when data is available.
     *
     * @return float Final Y
     */
    protected function drawSpongePaymentInfo(&$pdf, $invoice, $outputlangs, $posy, $pageWidth, $marginLeft, $marginRight)
    {
        $defaultFontSize = pdf_getPDFFontSize($outputlangs);
        $posxval = 52;
        $posxend = min(110, $pageWidth - $marginRight - 70);
        if ($invoice->type != Facture::TYPE_CREDIT_NOTE && (!empty($invoice->cond_reglement_code) || !empty($invoice->cond_reglement))) {
            $pdf->SetFont('', '', max(6, $defaultFontSize - 2));
            $pdf->SetXY($marginLeft, $posy);
            $pdf->MultiCell($posxval - $marginLeft, 4, $outputlangs->transnoentities('PaymentConditions').':', 0, 'L');
            $pdf->SetXY($posxval, $posy);
            $key = 'PaymentCondition'.$invoice->cond_reglement_code;
            $condition = ($outputlangs->transnoentities($key) != $key) ? $outputlangs->transnoentities($key) : $outputlangs->convToOutputCharset(!empty($invoice->cond_reglement_doc) ? $invoice->cond_reglement_doc : $invoice->cond_reglement_label);
            $pdf->MultiCell(max(30, $posxend - $posxval), 4, str_replace('\\n', "\n", $condition), 0, 'L');
            $posy = $pdf->GetY() + 3;
        }
        if (empty($invoice->mode_reglement_code) || $invoice->mode_reglement_code === 'VIR') {
            $bankId = !empty($invoice->fk_account) ? (int) $invoice->fk_account : (!empty($invoice->fk_bank) ? (int) $invoice->fk_bank : getDolGlobalInt('FACTURE_RIB_NUMBER'));
            if ($bankId > 0) {
                $account = new Account($this->db);
                if ($account->fetch($bankId) > 0) {
                    $posy = pdf_bank($pdf, $outputlangs, $marginLeft, $posy, $account, 0, $defaultFontSize);
                }
            }
        }
        return $posy;
    }

    /** @return string */
    public function formatMoney($amount, $outputlangs)
    {
        global $conf;
        $value = price((float) $amount, 0, $outputlangs, 1, -1, -1, $conf->currency);
        return html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8');
    }

    /** @return string */
    public function asHtml($text)
    {
        $text = (string) $text;
        if (preg_match('/<\s*(p|div|table|br|ul|ol|h[1-6]|strong|span|a)\b/i', $text)) {
            return $text;
        }
        return '<p>'.nl2br(dol_escape_htmltag($text), false).'</p>';
    }
}
