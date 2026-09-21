<?php
/* Where PDFs live: the invoice PDF, previews, delivery evidence, the invoice documents. */
trait DunningNoticeServiceDocuments
{
    /**
     * Get existing original invoice PDF without generating/modifying it.
     *
     * @param Facture $invoice Invoice
     * @return string
     */
    public function getInvoicePdfPath($invoice)
    {
        global $conf;
        $entity = !empty($invoice->entity) ? (int) $invoice->entity : (int) $conf->entity;
        $root = '';
        if (isset($conf->facture->multidir_output[$entity])) {
            $root = $conf->facture->multidir_output[$entity];
        } elseif (!empty($conf->facture->dir_output)) {
            $root = $conf->facture->dir_output;
        }
        if ($root === '') {
            return '';
        }
        // last_main_doc is relative to DOL_DATA_ROOT (facture/REF/REF.pdf, or
        // 2/facture/... in a second entity), not to the invoice directory.
        if (!empty($invoice->last_main_doc)) {
            $path = $this->fileInsideRoot(DOL_DATA_ROOT.'/'.ltrim((string) $invoice->last_main_doc, '/'), $root);
            if ($path !== '') {
                return $path;
            }
        }
        $ref = dol_sanitizeFileName($invoice->ref);
        return $this->fileInsideRoot(rtrim($root, '/').'/'.$ref.'/'.$ref.'.pdf', $root);
    }

    /**
     * Path of the existing invoice PDF relative to the invoice document root,
     * as document.php expects it for modulepart=invoice.
     *
     * @param Facture $invoice Invoice
     * @return string Empty when there is no invoice PDF
     */
    public function getInvoicePdfRelativePath($invoice)
    {
        $path = $this->getInvoicePdfPath($invoice);
        $root = realpath($this->getInvoiceDocumentRoot($invoice));
        if ($path === '' || $root === false) {
            return '';
        }
        return ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    }

    /**
     * Where the replaceable preview PDF of one stage lives.
     *
     * @param Facture $invoice Invoice
     * @param int $level Stage
     * @return array{dir:string,filename:string,relative:string}
     */
    public function getPreviewPdfLocation($invoice, $level)
    {
        global $conf;
        $entity = !empty($invoice->entity) ? (int) $invoice->entity : (int) $conf->entity;
        $root = !empty($conf->mahnwesen->multidir_output[$entity]) ? $conf->mahnwesen->multidir_output[$entity] : (!empty($conf->mahnwesen->dir_output) ? $conf->mahnwesen->dir_output : DOL_DATA_ROOT.'/mahnwesen');
        $safeRef = dol_sanitizeFileName($invoice->ref);
        $filename = $safeRef.'_'.$this->getStageFilenamePart((int) $level).'_preview.pdf';
        return array('dir' => rtrim($root, '/').'/notices/'.$safeRef, 'filename' => $filename, 'relative' => 'notices/'.$safeRef.'/'.$filename);
    }

    /**
     * The existing preview PDF of one stage, or an empty string.
     *
     * @param Facture $invoice Invoice
     * @param int $level Stage
     * @return string
     */
    public function getPreviewPdfPath($invoice, $level)
    {
        if ((int) $level < 1 || (int) $level > 4) {
            return '';
        }
        $location = $this->getPreviewPdfLocation($invoice, $level);
        return $this->fileInsideRoot($location['dir'].'/'.$location['filename'], $location['dir']);
    }

    /**
     * A readable file whose real path lies inside the given root, or ''.
     *
     * @param string $path Candidate path
     * @param string $root Directory the file must be in
     * @return string
     */
    protected function fileInsideRoot($path, $root)
    {
        $real = realpath((string) $path);
        $realRoot = realpath((string) $root);
        if ($real === false || $realRoot === false || !is_file($real) || !is_readable($real)) {
            return '';
        }
        $real = str_replace('\\', '/', $real);
        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
        return strpos($real, $realRoot.'/') === 0 ? $real : '';
    }

    /**
     * The file name of a stage's dunning PDF, as the customer and the invoice
     * documents see it: IN2607-0052_1.Mahnung.pdf. Dolibarr shows each file's
     * date itself, so the name carries neither a date nor an attempt id.
     *
     * @param Facture $invoice Invoice
     * @param int $level Stage
     * @return string
     */
    public function getFinalPdfFilename($invoice, $level)
    {
        return dol_sanitizeFileName($invoice->ref).'_'.$this->getStageFilenamePart((int) $level, $this->getCustomerLanguage($invoice)).'.pdf';
    }

    /** The customer's language, or the company's when the customer has none. */
    public function getCustomerLanguage($invoice)
    {
        global $langs;
        if (empty($invoice->thirdparty) && method_exists($invoice, 'fetch_thirdparty')) { $invoice->fetch_thirdparty(); }
        if (!empty($invoice->thirdparty) && !empty($invoice->thirdparty->default_lang)) { return (string) $invoice->thirdparty->default_lang; }
        return is_object($langs) ? (string) $langs->defaultlang : 'de_DE';
    }

    /**
     * The module's document directory of the active entity.
     *
     * @return string
     */
    public function getModuleDocumentRoot()
    {
        global $conf;
        $entity = (int) $conf->entity;
        if (!empty($conf->mahnwesen->multidir_output[$entity])) {
            return rtrim((string) $conf->mahnwesen->multidir_output[$entity], '/');
        }
        return !empty($conf->mahnwesen->dir_output) ? rtrim((string) $conf->mahnwesen->dir_output, '/') : DOL_DATA_ROOT.'/mahnwesen';
    }

    /**
     * Where the exact files of one delivery attempt are kept: outside the
     * invoice documents, so nobody deletes the evidence there by accident.
     *
     * @param int $attemptId Attempt id
     * @return string
     */
    public function getAttemptEvidenceDir($attemptId)
    {
        return $this->getModuleDocumentRoot().'/attempts/'.((int) $attemptId);
    }

    /**
     * An evidence file of an attempt, if its recorded path lies in a place the
     * module writes evidence to: its attempt directory, or - for attempts of
     * 1.0.1 and earlier - the invoice documents.
     *
     * @param array $file Row of llx_mahnwesen_attempt_file
     * @param Facture $invoice Invoice of the attempt
     * @return string
     */
    public function getAttemptEvidencePath($file, $invoice)
    {
        $path = (string) ($file['snapshot_path'] ?? '');
        foreach (array($this->getAttemptEvidenceDir((int) ($file['fk_attempt'] ?? 0)), $this->getInvoiceDocumentRoot($invoice)) as $root) {
            if ($root !== '' && ($found = $this->fileInsideRoot($path, $root)) !== '') {
                return $found;
            }
        }
        return '';
    }

    /**
     * Put a dunning PDF into the invoice documents under its stage name,
     * replacing the earlier one of that stage, as Dolibarr does with the
     * invoice PDF.
     *
     * @param Facture $invoice Invoice
     * @param int $level Stage
     * @param string $source Generated PDF
     * @return string Relative path inside the invoice documents, or '' on failure
     */
    public function publishToInvoiceDocuments($invoice, $level, $source)
    {
        $root = $this->getInvoiceDocumentRoot($invoice);
        if ($root === '' || !is_file($source)) {
            return '';
        }
        $invoicePdfPath = $this->getInvoicePdfPath($invoice);
        $dir = ($invoicePdfPath !== '') ? dirname($invoicePdfPath) : $root.'/'.dol_sanitizeFileName($invoice->ref);
        if (!is_dir($dir) && dol_mkdir($dir) < 0) {
            return '';
        }
        $target = $dir.'/'.$this->getFinalPdfFilename($invoice, $level);
        if (realpath($source) !== realpath($target) && !@copy($source, $target)) {
            return '';
        }
        return ltrim(str_replace('\\', '/', substr($target, strlen($root))), '/');
    }

    /**
     * Generate a dunning PDF. The invoice is never modified.
     *
     * @param Facture $invoice Invoice
     * @param array $case Stored case
     * @param int $level Stage
     * @param string $body Rendered HTML/body
     * @param bool $preview Replaceable preview or timestamped final copy
     * @param string $lang Output language
     * @return array|false
     */
    /**
     * Return a stage-safe filename component.
     *
     * @param int $level Dunning level
     * @return string
     */
    protected function getStageFilenamePart($level, $lang = 'de_DE')
    {
        global $conf;
        // In the customer's language; files already written keep their name (#28).
        $outputlangs = new Translate('', $conf);
        $outputlangs->setDefaultLang($lang);
        $outputlangs->load('mahnwesen@mahnwesen');
        $level = max(0, min(4, (int) $level));
        return dol_sanitizeFileName($outputlangs->transnoentities('MahnwesenFileStage'.$level));
    }

    /**
     * Return the invoice document output root for the invoice entity.
     *
     * @param Facture $invoice Invoice
     * @return string
     */
    protected function getInvoiceDocumentRoot($invoice)
    {
        global $conf;
        $entity = !empty($invoice->entity) ? (int) $invoice->entity : (int) $conf->entity;
        if (!empty($conf->facture->multidir_output[$entity])) {
            return rtrim((string) $conf->facture->multidir_output[$entity], '/');
        }
        if (!empty($conf->facture->dir_output)) {
            return rtrim((string) $conf->facture->dir_output, '/');
        }
        return '';
    }
}
