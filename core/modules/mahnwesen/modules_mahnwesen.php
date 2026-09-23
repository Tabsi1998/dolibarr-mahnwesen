<?php
/*
 * Mahnwesen - Dolibarr custom module
 * Copyright (C) 2026 Module contributors
 * GPL-3.0-or-later
 */

/**
 * \file    core/modules/mahnwesen/modules_mahnwesen.php
 * \ingroup mahnwesen
 * \brief   Layouts of the dunning letter (#76)
 */

/**
 * A layout of the dunning letter. The frame - letterhead, addresses, the text
 * of the template and Dolibarr's footer - is the same for every layout; a
 * layout decides how the amounts are shown. A new layout is a new file
 * pdf_mahnwesen_<name>.modules.php in the folder doc next to this one.
 */
abstract class ModelePDFMahnwesen
{
    /** @var string Name of the layout, as the setup stores it */
    public $name = '';

    /** @var string Language key of the label shown in the setup */
    public $labelKey = '';

    /** @var string Language key of the description shown in the setup */
    public $descriptionKey = '';

    /**
     * Draw the amounts between the addresses and the text of the letter.
     *
     * @param TCPDF $pdf PDF
     * @param DunningNoticeService $service Notice service
     * @param Facture $invoice Invoice
     * @param int $level Stage
     * @param array $breakdown From DunningManager::getAmountBreakdown()
     * @param Translate $outputlangs Language of the customer
     * @param array $geometry left, right, width, content, font, top
     * @return float Where the text of the letter starts
     */
    abstract public function drawAmounts($pdf, $service, $invoice, $level, $breakdown, $outputlangs, $geometry);

    /**
     * The layouts that exist, by name.
     *
     * @return array<string,ModelePDFMahnwesen>
     */
    public static function getModels()
    {
        $models = array();
        foreach ((array) glob(__DIR__.'/doc/pdf_mahnwesen_*.modules.php') as $file) {
            if (!preg_match('/pdf_mahnwesen_([a-z0-9]+)\.modules\.php$/', (string) $file, $match)) {
                continue;
            }
            $model = self::load($match[1]);
            if ($model !== null) {
                $models[$match[1]] = $model;
            }
        }
        ksort($models);
        return $models;
    }

    /**
     * One layout by name, or null when there is none of that name.
     *
     * @param string $name Name of the layout
     * @return ModelePDFMahnwesen|null
     */
    public static function load($name)
    {
        if (!preg_match('/^[a-z0-9]+$/', (string) $name)) {
            return null;
        }
        $file = __DIR__.'/doc/pdf_mahnwesen_'.$name.'.modules.php';
        if (!is_file($file)) {
            return null;
        }
        require_once $file;
        $class = 'pdf_mahnwesen_'.$name;
        if (!class_exists($class)) {
            return null;
        }
        $model = new $class();
        return ($model instanceof ModelePDFMahnwesen) ? $model : null;
    }

    /**
     * The layout the setup chose, the standard one when none or an unknown one is set.
     *
     * @return ModelePDFMahnwesen
     */
    public static function selected()
    {
        $model = self::load(getDolGlobalString('MAHNWESEN_ADDON_PDF', 'standard'));
        if ($model === null) {
            $model = self::load('standard');
        }
        return $model;
    }
}
