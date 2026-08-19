<?php
/* Auto-split method trait for maintainable source files. */
trait DunningNoticeServiceMethods3
{

    /**
     * Draw a Sponge-like invoice/reminder header: logo, title and the same
     * sender/recipient frame geometry as Dolibarr's pdf_sponge invoice model.
     *
     * @return float Y position below the address frames
     */
    protected function drawSpongeReminderHeader(&$pdf, $invoice, $level, $outputlangs, $pageWidth, $pageHeight, $marginLeft, $marginRight, $marginTop)
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
        $pdf->MultiCell($w, 3, $stageTitle.' '.$outputlangs->convToOutputCharset((string) $invoice->ref), 0, 'R');
        $metaY = $pdf->GetY() + 1;
        $pdf->SetFont('', '', max(6, $defaultFontSize - 2));
        $pdf->SetXY($posx, $metaY);
        $pdf->MultiCell($w, 3, $outputlangs->transnoentities('DateInvoice').' : '.dol_print_date($invoice->date, 'day', false, $outputlangs, true), 0, 'R');
        $metaY = $pdf->GetY();
        $pdf->SetXY($posx, $metaY);
        $pdf->MultiCell($w, 3, $outputlangs->transnoentities('DateDue').' : '.dol_print_date($invoice->date_lim_reglement, 'day', false, $outputlangs, true), 0, 'R');
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
        $ids = $invoice->getIdContact('external', 'BILLING');
        if (!empty($ids)) {
            $useContact = ($invoice->fetch_contact($ids[0]) > 0);
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
}
