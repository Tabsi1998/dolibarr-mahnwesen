<?php
/* Auto-split method trait for maintainable source files. */
trait DunningNoticeServiceMethods2
{

    /**
     * Resolve eligible recipients. External invoice BILLING contacts are first;
     * the third-party email is the fallback.
     *
     * @param Facture $invoice Invoice
     * @return array<int,array{email:string,label:string,source:string}>
     */
    public function getRecipientOptions($invoice)
    {
        $options = array();
        $seen = array();

        $sql = 'SELECT sp.rowid, sp.firstname, sp.lastname, sp.email';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'element_contact as ec';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'c_type_contact as tc ON tc.rowid = ec.fk_c_type_contact';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'socpeople as sp ON sp.rowid = ec.fk_socpeople';
        $sql .= ' WHERE ec.element_id = '.((int) $invoice->id);
        $sql .= " AND tc.element = 'facture' AND tc.source = 'external' AND tc.code = 'BILLING'";
        $sql .= " AND sp.email IS NOT NULL AND sp.email <> ''";
        $sql .= ' ORDER BY sp.lastname ASC, sp.firstname ASC, sp.rowid ASC';
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $email = trim((string) $obj->email);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $key = strtolower($email);
                if (isset($seen[$key])) {
                    continue;
                }
                $name = trim((string) $obj->firstname.' '.(string) $obj->lastname);
                $options[] = array(
                    'email' => $email,
                    'label' => ($name !== '' ? $name.' <'.$email.'>' : $email),
                    'source' => 'billing_contact',
                );
                $seen[$key] = 1;
            }
            $this->db->free($resql);
        } else {
            $this->errors[] = 'Recipient contact lookup failed: '.$this->db->lasterror();
        }

        if (empty($invoice->thirdparty)) {
            $invoice->fetch_thirdparty();
        }
        if (!empty($invoice->thirdparty) && !empty($invoice->thirdparty->email)) {
            $email = trim((string) $invoice->thirdparty->email);
            $key = strtolower($email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($seen[$key])) {
                $options[] = array(
                    'email' => $email,
                    'label' => (string) $invoice->thirdparty->name.' <'.$email.'>',
                    'source' => 'thirdparty',
                );
            }
        }

        return $options;
    }

    /**
     * Resolve one safe automatic recipient according to configured policy.
     * single_billing: exactly one BILLING contact, or company email when none.
     * Multiple BILLING contacts intentionally block automatic delivery.
     *
     * @param Facture $invoice Invoice
     * @param string $policy single_billing|first_billing
     * @return string Empty if automatic selection is ambiguous/unavailable
     */
    public function getAutomaticRecipient($invoice, $policy = 'single_billing')
    {
        $options = $this->getRecipientOptions($invoice);
        $billing = array();
        $fallback = '';
        foreach ($options as $option) {
            if ($option['source'] === 'billing_contact') {
                $billing[] = $option['email'];
            } elseif ($option['source'] === 'thirdparty' && $fallback === '') {
                $fallback = $option['email'];
            }
        }
        if ($policy === 'first_billing') {
            return !empty($billing) ? (string) $billing[0] : $fallback;
        }
        if (count($billing) === 1) {
            return (string) $billing[0];
        }
        if (count($billing) > 1) {
            return '';
        }
        return $fallback;
    }

    /** @return bool */
    public function isManualSendEnabled()
    {
        return getDolGlobalInt('MAHNWESEN_MANUAL_SEND_ENABLED', 0) > 0;
    }

    /** @return bool */
    public function isAutomaticSendEnabled()
    {
        return getDolGlobalInt('MAHNWESEN_AUTO_SEND_ENABLED', 0) > 0;
    }

    /**
     * Return configured/from-template sender. A native template may optionally
     * define email_from; otherwise the module/global company settings apply.
     *
     * @param string $templateFrom Optional native template sender
     * @return string
     */
    public function getFromEmail($templateFrom = '')
    {
        global $mysoc;
        $email = trim((string) $templateFrom);
        if ($email === '') {
            $email = trim(getDolGlobalString('MAHNWESEN_FROM_EMAIL'));
        }
        if ($email === '') {
            $email = trim(getDolGlobalString('MAIN_MAIL_EMAIL_FROM'));
        }
        if ($email === '' && is_object($mysoc) && !empty($mysoc->email)) {
            $email = trim((string) $mysoc->email);
        }
        if (preg_match('/<([^<>]+)>/', $email, $m)) {
            $email = trim($m[1]);
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }


    /**
     * Return sender addresses available to the current user. The effective
     * Mahnwesen/template sender is always offered first; Dolibarr's active
     * email sender profiles are added afterwards. This mirrors the choice users
     * already know from Dolibarr's standard send form without delegating the
     * irreversible send to core UI code.
     *
     * @param User|null $user Current user
     * @param string $preferred Template-specific sender
     * @return array<int,array{email:string,label:string,source:string}>
     */
    public function getSenderOptions($user = null, $preferred = '')
    {
        $options = array();
        $seen = array();
        $effective = $this->getFromEmail($preferred);
        if ($effective !== '') {
            global $mysoc;
            $effectiveLabel = (is_object($mysoc) && !empty($mysoc->name)) ? ((string) $mysoc->name).' <'.$effective.'>' : $effective;
            $options[] = array('email' => $effective, 'label' => $effectiveLabel, 'source' => 'default');
            $seen[strtolower($effective)] = 0;
        }

        // Email sender profiles exist in the supported Dolibarr 21+ target line.
        // If a non-standard installation lacks the table, simply retain the
        // effective sender instead of turning the composer into a fatal error.
        $sql = 'SELECT rowid, label, email, private FROM '.MAIN_DB_PREFIX.'c_email_senderprofile';
        $sql .= ' WHERE active = 1 AND entity IN ('.getEntity('emailsenderprofile').')';
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $sql .= ' AND (private = 0'.($uid > 0 ? ' OR private = '.$uid : '').')';
        $sql .= ' ORDER BY position ASC, label ASC, rowid ASC';
        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog(__METHOD__.' Unable to load email sender profiles: '.$this->db->lasterror(), LOG_WARNING);
            return $options;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $email = trim((string) $obj->email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }
            $key = strtolower($email);
            $label = trim((string) $obj->label);
            if (isset($seen[$key])) {
                $idx = (int) $seen[$key];
                if ($label !== '' && isset($options[$idx])) {
                    $options[$idx]['label'] = $label.' <'.$email.'>';
                    $options[$idx]['source'] = 'profile';
                }
                continue;
            }
            $options[] = array(
                'email' => $email,
                'label' => ($label !== '' ? $label.' <'.$email.'>' : $email),
                'source' => 'profile',
            );
            $seen[$key] = count($options) - 1;
        }
        $this->db->free($resql);
        return $options;
    }

    /**
     * Apply Dolibarr's standard email substitutions and Mahnwesen tokens.
     * Both native __TOKEN__ syntax and the module's legacy {TOKEN} syntax are
     * supported so existing templates keep working.
     *
     * @param string $text Template text/HTML
     * @param Facture $invoice Invoice
     * @param array $case Stored case
     * @param int $level Stage
     * @param string $lang Output language
     * @return string
     */
    public function renderTemplate($text, $invoice, $case, $level, $lang = '')
    {
        global $conf, $langs, $mysoc;
        if (empty($invoice->thirdparty)) {
            $invoice->fetch_thirdparty();
        }
        if ($lang === '') {
            $lang = (is_object($langs) ? $langs->defaultlang : 'de_DE');
        }
        $outputlangs = new Translate('', $conf);
        $outputlangs->setDefaultLang($lang);
        $outputlangs->loadLangs(array('main', 'bills', 'companies', 'mahnwesen@mahnwesen'));

        $breakdown = $this->manager->getAmountBreakdown($invoice, $case, $level);
        $openAmount = $this->formatMoney($breakdown['invoice'], $outputlangs);
        $feeAmount = $this->formatMoney($breakdown['fee'], $outputlangs);
        $totalAmount = $this->formatMoney($breakdown['total'], $outputlangs);
        $classLabel = $outputlangs->trans($this->manager->getCustomerClassLabelKey($breakdown['classification']['class']));
        $stageLabel = $outputlangs->trans($this->manager->getStageLabelKey((int) $level));
        $next = (!empty($case['next_action_at']) && empty($case['paused'])) ? dol_print_date($this->db->jdate($case['next_action_at']), 'day', 'tzserver', $outputlangs) : '';
        $feeParagraph = '';
        if ($breakdown['fee'] > 0.000001) {
            $feeParagraph = (stripos($lang, 'de') === 0)
                ? 'Zusätzlich werden Mahn-/Betreibungskosten in Höhe von <strong>'.$feeAmount.'</strong> berücksichtigt.'
                : 'In addition, dunning/collection costs of <strong>'.$feeAmount.'</strong> are included.';
        }

        // Native Dolibarr object substitutions such as __REF__, __THIRDPARTY_NAME__, ...
        $formmail = new FormMail($this->db);
        $formmail->setSubstitFromObject($invoice, $outputlangs);
        $formmail->substit['__MAHNWESEN_STAGE__'] = $stageLabel;
        $formmail->substit['__MAHNWESEN_OPEN_AMOUNT__'] = $openAmount;
        $formmail->substit['__MAHNWESEN_FEE__'] = $feeAmount;
        $formmail->substit['__MAHNWESEN_TOTAL__'] = $totalAmount;
        $formmail->substit['__MAHNWESEN_CUSTOMER_CLASS__'] = $classLabel;
        $formmail->substit['__MAHNWESEN_NEXT_STAGE_DATE__'] = $next;
        $formmail->substit['__MAHNWESEN_FEE_PARAGRAPH__'] = $feeParagraph;
        $rendered = make_substitutions((string) $text, $formmail->substit, $outputlangs);

        $replacements = array(
            '{INVOICE_REF}' => (string) $invoice->ref,
            '{CUSTOMER_NAME}' => (!empty($invoice->thirdparty) ? (string) $invoice->thirdparty->name : ''),
            '{INVOICE_DATE}' => dol_print_date($invoice->date, 'day', 'tzserver', $outputlangs),
            '{DUE_DATE}' => dol_print_date($invoice->date_lim_reglement, 'day', 'tzserver', $outputlangs),
            '{OPEN_AMOUNT}' => $openAmount,
            '{DUNNING_FEE}' => $feeAmount,
            '{DUNNING_TOTAL}' => $totalAmount,
            '{CUSTOMER_CLASS}' => $classLabel,
            '{DUNNING_STAGE}' => $stageLabel,
            '{TODAY}' => dol_print_date(dol_now(), 'day', 'tzserver', $outputlangs),
            '{COMPANY_NAME}' => (is_object($mysoc) ? (string) $mysoc->name : ''),
            '{NEXT_STAGE_DATE}' => $next,
            '{FEE_PARAGRAPH}' => $feeParagraph,
        );
        return strtr($rendered, $replacements);
    }

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
        if (!empty($invoice->last_main_doc)) {
            $path = rtrim($root, '/').'/'.ltrim((string) $invoice->last_main_doc, '/');
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }
        $ref = dol_sanitizeFileName($invoice->ref);
        $fallback = rtrim($root, '/').'/'.$ref.'/'.$ref.'.pdf';
        return (is_file($fallback) && is_readable($fallback)) ? $fallback : '';
    }

    /**
     * Return deterministic final dunning PDF information when the document
     * already exists in the invoice document directory.
     *
     * @param Facture $invoice Invoice
     * @param int $level Stage
     * @return array|false
     */
    public function getExistingFinalPdfInfo($invoice, $level)
    {
        $root = $this->getInvoiceDocumentRoot($invoice);
        if ($root === '') { return false; }
        $safeRef = dol_sanitizeFileName($invoice->ref);
        $stagePart = $this->getStageFilenamePart((int) $level);
        $invoicePdfPath = $this->getInvoicePdfPath($invoice);
        $dir = ($invoicePdfPath !== '') ? dirname($invoicePdfPath) : rtrim($root, '/').'/'.$safeRef;
        $filename = $safeRef.'_'.$stagePart.'.pdf';
        $fullpath = $dir.'/'.$filename;
        if (!is_file($fullpath) || !is_readable($fullpath) || filesize($fullpath) <= 0) { return false; }
        $relativeDir = ltrim(str_replace('\\', '/', substr($dir, strlen(rtrim($root, '/')))), '/');
        $relative = ($relativeDir !== '' ? $relativeDir.'/' : '').$filename;
        return array('fullpath'=>$fullpath, 'relative'=>$relative, 'filename'=>$filename, 'modulepart'=>'invoice', 'preview'=>0);
    }

    /**
     * Return the deterministic final filename even before a PDF exists.
     *
     * @param Facture $invoice Invoice
     * @param int $level Stage
     * @return string
     */
    public function getFinalPdfFilename($invoice, $level)
    {
        return dol_sanitizeFileName($invoice->ref).'_'.$this->getStageFilenamePart((int) $level).'.pdf';
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
    protected function getStageFilenamePart($level)
    {
        switch ((int) $level) {
            case 1: return 'Zahlungserinnerung';
            case 2: return '1.Mahnung';
            case 3: return '2.Mahnung';
            case 4: return '3.Mahnung';
            default: return 'Mahnung';
        }
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

    /**
     * Make an email template suitable for a printed Sponge-style letter.
     * Email-only logos/signatures should not turn a one-page reminder into a
     * multi-page PDF. Authors can explicitly end the PDF body with the marker
     * <!--MAHNWESEN_PDF_END-->.
     *
     * @param string $body Rendered email HTML
     * @return string
     */
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

        // Common email signatures often start after the closing salutation.
        // Keep the salutation paragraph, but suppress large HTML signature
        // blocks following it. This mirrors a business letter more closely.
        $closings = array('Mit freundlichen Gr', 'Freundliche Gr', 'Kind regards', 'Best regards');
        foreach ($closings as $closing) {
            $pos = stripos($body, $closing);
            if ($pos === false) { continue; }
            // An email signature can be a table/div after the salutation or a
            // chain of <br> tags. The printed letter already carries company
            // identity in the Sponge header/footer, so keep the salutation but
            // drop the email signature that follows it.
            $candidates = array();
            $endP = stripos($body, '</p>', $pos);
            if ($endP !== false) { $candidates[] = $endP + 4; }
            if (preg_match('~<br\s*/?>~i', substr($body, $pos), $m, PREG_OFFSET_CAPTURE)) {
                $candidates[] = $pos + $m[0][1] + strlen($m[0][0]);
            }
            $lineEnd = strpos($body, "\n", $pos);
            if ($lineEnd !== false) { $candidates[] = $lineEnd; }
            if (!empty($candidates)) {
                $cut = min($candidates);
                $tail = substr($body, $cut);
                if (trim(strip_tags($tail)) !== '' || preg_match('~<(table|div|img|br)\b~i', $tail)) {
                    $body = substr($body, 0, $cut);
                }
            }
            break;
        }
        return trim($body);
    }
}
