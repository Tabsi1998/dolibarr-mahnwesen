<?php
/*
 * Mahnwesen - Dolibarr custom module
 * Copyright (C) 2026 Module contributors
 * GPL-3.0-or-later
 */

/**
 * \file    core/modules/mahnwesen/doc/pdf_mahnwesen_standard.modules.php
 * \ingroup mahnwesen
 * \brief   The standard layout: an amount table like Dolibarr's invoices, and the QR code (#76)
 */

require_once dirname(__DIR__).'/modules_mahnwesen.php';

/**
 * The amounts in a table with the same sober lines as the Sponge invoice,
 * the total and the payment deadline below it, and Dolibarr's EPC QR code for
 * the invoice amount with a line that says what it covers (#25, #33, #35).
 */
class pdf_mahnwesen_standard extends ModelePDFMahnwesen
{
    public $name = 'standard';
    public $labelKey = 'MahnwesenLayoutStandard';
    public $descriptionKey = 'MahnwesenLayoutStandardHelp';

    /**
     * Draw the amount table and the QR code.
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
        $marginRight = (float) $geometry['right'];
        $pageWidth = (float) $geometry['width'];
        $defaultFontSize = (float) $geometry['font'];
        $contentWidth = (float) $geometry['content'];
        $manager = $service->manager;

        $tableY = (float) $geometry['top'];
        $descW = $contentWidth - 42;
        $amountW = 42;
        $rowH = 7;
        $pdf->SetDrawColor(128, 128, 128);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('', '', max(6, $defaultFontSize - 1));
        $row = function ($y, $label, $amount) use ($pdf, $marginLeft, $descW, $amountW, $rowH) {
            $pdf->Rect($marginLeft, $y, $descW, $rowH);
            $pdf->Rect($marginLeft + $descW, $y, $amountW, $rowH);
            $pdf->SetXY($marginLeft + 1.5, $y + 1.4);
            $pdf->Cell($descW - 3, 4, $label, 0, 0, 'L');
            $pdf->SetXY($marginLeft + $descW + 1.5, $y + 1.4);
            $pdf->Cell($amountW - 3, 4, $amount, 0, 0, 'R');
        };
        $row($tableY, $outputlangs->transnoentities('Description'), $outputlangs->transnoentities('Amount'));
        $y = $tableY + $rowH;
        $row($y, $outputlangs->transnoentities('MahnwesenOpenInvoiceAmount').' - '.$invoice->ref, $service->formatMoney($breakdown['invoice'], $outputlangs));
        // A fee or interest only takes a row when there is one.
        if ($breakdown['fee'] > 0.000001) {
            $y += $rowH;
            $row($y, $outputlangs->transnoentities('MahnwesenDunningFee').' - '.$outputlangs->transnoentities($manager->getStageLabelKey((int) $level)),
                $service->formatMoney($breakdown['fee'], $outputlangs));
        }
        if ($breakdown['interest'] > 0.000001) {
            $y += $rowH;
            $row($y, $manager->describeInterest($breakdown['interest_details'], $outputlangs), $service->formatMoney($breakdown['interest'], $outputlangs));
        }
        // The total under one full-width rule, as Sponge does it.
        $y += $rowH;
        $pdf->SetFont('', 'B', $defaultFontSize);
        $pdf->Line($marginLeft, $y, $pageWidth - $marginRight, $y);
        $pdf->SetXY($marginLeft + $descW - 45, $y + 1.4);
        $pdf->Cell(43, 4, $outputlangs->transnoentities('Total'), 0, 0, 'R');
        $pdf->SetXY($marginLeft + $descW + 1.5, $y + 1.4);
        $pdf->Cell($amountW - 3, 4, $service->formatMoney($breakdown['total'], $outputlangs), 0, 0, 'R');
        // The same deadline as __MAHNWESEN_PAYMENT_DEADLINE__ in the email (#64).
        $deadline = $manager->getPaymentDeadline((int) $level, null, (int) $breakdown['profile_id']);
        if ($deadline) {
            $y += $rowH;
            $pdf->SetFont('', '', $defaultFontSize);
            $pdf->SetXY($marginLeft + $descW - 45, $y + 1.4);
            $pdf->Cell(43, 4, $outputlangs->transnoentities('MahnwesenPaymentDeadline'), 0, 0, 'R');
            $pdf->SetXY($marginLeft + $descW + 1.5, $y + 1.4);
            $pdf->Cell($amountW - 3, 4, dol_print_date($deadline, 'day', 'tzserver', $outputlangs), 0, 0, 'R');
        }
        // Dolibarr's EPC QR code, only when it asks for exactly the amount named (#35).
        $qrPayload = $manager->getInvoiceQrPayload($invoice);
        if ($qrPayload !== '' && abs($manager->getQrAmount($qrPayload) - (float) $breakdown['invoice']) > 0.005) {
            $qrPayload = '';
        }
        if ($qrPayload !== '') {
            $y += $rowH;
            $pdf->SetFont('', '', $defaultFontSize - 1);
            $pdf->write2DBarcode($qrPayload, 'QRCODE,M', $marginLeft, $y + 2, 22, 22, array(), 'N');
            $pdf->SetXY($marginLeft + 25, $y + 4);
            $pdf->MultiCell($pageWidth - $marginRight - $marginLeft - 27, 4,
                $outputlangs->transnoentities('MahnwesenPaymentQrTitle')."\n".$manager->describeInvoicePaymentScope($breakdown, $outputlangs), 0, 'L');
            $y += 24;
            $pdf->SetFont('', '', $defaultFontSize);
        }
        return $y + $rowH + 8;
    }
}
