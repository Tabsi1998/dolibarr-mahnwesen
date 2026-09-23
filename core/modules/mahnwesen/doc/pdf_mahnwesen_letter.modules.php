<?php
/*
 * Mahnwesen - Dolibarr custom module
 * Copyright (C) 2026 Module contributors
 * GPL-3.0-or-later
 */

/**
 * \file    core/modules/mahnwesen/doc/pdf_mahnwesen_letter.modules.php
 * \ingroup mahnwesen
 * \brief   The plain letter: the amounts as sentences, no table, no QR code (#76)
 */

require_once dirname(__DIR__).'/modules_mahnwesen.php';

/**
 * A plain letter for organisations that write rather than tabulate, a club
 * for example: every amount is one line of text, the total is set in bold,
 * and the payment deadline closes the block.
 */
class pdf_mahnwesen_letter extends ModelePDFMahnwesen
{
    public $name = 'letter';
    public $labelKey = 'MahnwesenLayoutLetter';
    public $descriptionKey = 'MahnwesenLayoutLetterHelp';

    /**
     * Draw the amounts as lines of text.
     *
     * @param TCPDF $pdf PDF
     * @param DunningNoticeService $service Notice service
     * @param Facture $invoice Invoice
     * @param int $level Stage
     * @param array $breakdown Amounts
     * @param Translate $outputlangs Language of the customer
     * @param array $geometry left, right, width, content, font, top
     * @return float
     */
    public function drawAmounts($pdf, $service, $invoice, $level, $breakdown, $outputlangs, $geometry)
    {
        $marginLeft = (float) $geometry['left'];
        $contentWidth = (float) $geometry['content'];
        $defaultFontSize = (float) $geometry['font'];
        $manager = $service->manager;
        $lines = array($outputlangs->transnoentities('MahnwesenOpenInvoiceAmount').' '.$invoice->ref.': '.$service->formatMoney($breakdown['invoice'], $outputlangs));
        if ($breakdown['fee'] > 0.000001) {
            $lines[] = $outputlangs->transnoentities('MahnwesenDunningFee').' ('.$outputlangs->transnoentities($manager->getStageLabelKey((int) $level)).'): '
                .$service->formatMoney($breakdown['fee'], $outputlangs);
        }
        if ($breakdown['interest'] > 0.000001) {
            $lines[] = $manager->describeInterest($breakdown['interest_details'], $outputlangs);
        }
        $y = (float) $geometry['top'];
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('', '', $defaultFontSize);
        $pdf->SetXY($marginLeft, $y);
        $pdf->MultiCell($contentWidth, 5, implode("\n", $lines), 0, 'L');
        $pdf->SetFont('', 'B', $defaultFontSize);
        $pdf->SetX($marginLeft);
        $pdf->MultiCell($contentWidth, 5, $outputlangs->transnoentities('MahnwesenLetterTotal').' '.$service->formatMoney($breakdown['total'], $outputlangs), 0, 'L');
        $deadline = $manager->getPaymentDeadline((int) $level, null, (int) $breakdown['profile_id']);
        if ($deadline) {
            $pdf->SetFont('', '', $defaultFontSize);
            $pdf->SetX($marginLeft);
            $pdf->MultiCell($contentWidth, 5, $outputlangs->transnoentities('MahnwesenLetterDeadline', dol_print_date($deadline, 'day', 'tzserver', $outputlangs)), 0, 'L');
        }
        $pdf->SetFont('', '', $defaultFontSize);
        return $pdf->GetY() + 6;
    }
}
