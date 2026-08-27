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
        $this->recipientLookupFailed = false;
        $options = array();
        $seen = array();

        $sql = 'SELECT sp.rowid, sp.firstname, sp.lastname, sp.email';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'element_contact as ec';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'c_type_contact as tc ON tc.rowid = ec.fk_c_type_contact';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'socpeople as sp ON sp.rowid = ec.fk_socpeople';
        $sql .= ' WHERE ec.element_id = '.((int) $invoice->id);
        $sql .= " AND tc.element = 'facture' AND tc.source = 'external' AND tc.code = 'BILLING'";
        $sql .= " AND sp.email IS NOT NULL AND sp.email <> ''";
        $sql .= ' AND sp.statut = 1';
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
                    'contact_id' => (int) $obj->rowid,
                );
                $seen[$key] = 1;
            }
            $this->db->free($resql);
        } else {
            $this->recipientLookupFailed = true;
            $this->error = 'Recipient contact lookup failed.';
            $this->errors[] = $this->error;
            dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
            // A technical lookup failure is not equivalent to there being no
            // billing contact. Fail closed and never use the company fallback.
            return array();
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
                    'contact_id' => 0,
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
        $option = $this->getAutomaticRecipientOption($invoice, $policy);
        return $option ? (string) $option['email'] : '';
    }

    /** Return the complete selected recipient snapshot for automatic delivery. */
    public function getAutomaticRecipientOption($invoice, $policy = 'single_billing')
    {
        $options = $this->getRecipientOptions($invoice);
        $billing = array();
        $fallback = false;
        foreach ($options as $option) {
            if ($option['source'] === 'billing_contact') {
                $billing[] = $option;
            } elseif ($option['source'] === 'thirdparty' && $fallback === false) {
                $fallback = $option;
            }
        }
        if ($policy === 'first_billing') {
            return !empty($billing) ? $billing[0] : $fallback;
        }
        if (count($billing) === 1) {
            return $billing[0];
        }
        if (count($billing) > 1) {
            return false;
        }
        return $fallback;
    }

    /** Resolve a posted address back to the validated BILLING/company option. */
    public function getRecipientOptionByEmail($invoice, $email)
    {
        foreach ($this->getRecipientOptions($invoice) as $option) {
            if (strcasecmp((string) $option['email'], trim((string) $email)) === 0) { return $option; }
        }
        return false;
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
        $sql .= ' WHERE active = 1 AND entity IN ('.getEntity('c_email_senderprofile').')';
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
     * Resolve a sender selector produced by Dolibarr's native FormMail widget.
     * Every database-backed choice is revalidated for entity, activity and
     * private ownership before the address reaches the send reservation.
     */
    public function resolveNativeSender($fromType, $templateId, $user, $fallback = '')
    {
        global $mysoc;
        $fromType = trim((string) $fromType);
        if ($fromType === '') { return $this->getFromEmail($fallback); }
        if ($fromType === 'user') {
            return (is_object($user) && !empty($user->email) && filter_var($user->email, FILTER_VALIDATE_EMAIL)) ? (string) $user->email : '';
        }
        if ($fromType === 'company') {
            $email = is_object($mysoc) ? trim((string) $mysoc->email) : '';
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        }
        if ($fromType === 'robot' || $fromType === 'main_from') {
            $email = trim(getDolGlobalString('MAIN_MAIL_EMAIL_FROM'));
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        }
        $matches = array();
        if (preg_match('/^user_aliases_(\d+)$/', $fromType, $matches)) {
            $aliases = is_object($user) ? explode(',', (string) $user->email_aliases) : array();
            $email = isset($aliases[((int) $matches[1]) - 1]) ? trim($aliases[((int) $matches[1]) - 1]) : '';
            if (preg_match('/<([^<>]+)>/', $email, $m)) { $email = trim($m[1]); }
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        }
        if (preg_match('/^global_aliases_(\d+)$/', $fromType, $matches)) {
            $aliases = explode(',', getDolGlobalString('MAIN_INFO_SOCIETE_MAIL_ALIASES'));
            $email = isset($aliases[((int) $matches[1]) - 1]) ? trim($aliases[((int) $matches[1]) - 1]) : '';
            if (preg_match('/<([^<>]+)>/', $email, $m)) { $email = trim($m[1]); }
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        }
        if (preg_match('/^senderprofile_(\d+)(?:_\d+)?$/', $fromType, $matches)) {
            $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
            $sql = 'SELECT email FROM '.MAIN_DB_PREFIX.'c_email_senderprofile WHERE rowid = '.((int) $matches[1]).' AND active = 1 AND entity IN ('.getEntity('c_email_senderprofile').') AND (private = 0'.($uid > 0 ? ' OR private = '.$uid : '').')'.$this->db->plimit(1);
            $res = $this->db->query($sql); $o = $res ? $this->db->fetch_object($res) : false; if ($res) { $this->db->free($res); }
            $email = $o ? trim((string) $o->email) : '';
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
        }
        if (preg_match('/^from_template_(\d+)$/', $fromType, $matches) && (int) $matches[1] === (int) $templateId) {
            $template = $this->getNativeTemplateById((int) $templateId, 0, $user);
            return $template !== false ? $this->getFromEmail((string) $template['email_from']) : '';
        }
        if ($fromType === 'special') { return $this->getFromEmail($fallback); }
        return '';
    }

    /** Pick the native FormMail sender key for the configured address. */
    public function getNativeSenderType($email, $user)
    {
        global $mysoc;
        $email = strtolower(trim((string) $email));
        if ($email === '') { return 'company'; }
        if (is_object($user) && strtolower((string) $user->email) === $email) { return 'user'; }
        if (is_object($mysoc) && strtolower((string) $mysoc->email) === $email) { return 'company'; }
        if (strtolower(getDolGlobalString('MAIN_MAIL_EMAIL_FROM')) === $email) { return 'robot'; }
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $sql = 'SELECT rowid, email FROM '.MAIN_DB_PREFIX.'c_email_senderprofile WHERE active = 1 AND entity IN ('.getEntity('c_email_senderprofile').') AND (private = 0'.($uid > 0 ? ' OR private = '.$uid : '').') ORDER BY position, rowid';
        $res = $this->db->query($sql);
        if ($res) {
            while ($o = $this->db->fetch_object($res)) {
                if (strtolower(trim((string) $o->email)) === $email) { $this->db->free($res); return 'senderprofile_'.((int) $o->rowid).'_1'; }
            }
            $this->db->free($res);
        }
        return 'special';
    }

    /** Resolve one FormMail receiver value to the module's validated option. */
    public function getRecipientOptionByFormValue($invoice, $value)
    {
        $value = (string) $value;
        foreach ($this->getRecipientOptions($invoice) as $option) {
            if (($option['source'] === 'thirdparty' && $value === 'thirdparty') || ($option['source'] === 'billing_contact' && (int) $value === (int) $option['contact_id'])) { return $option; }
        }
        return false;
    }

    /** Build the exact substitution set used by native FormMail and rendering. */
    public function getTemplateSubstitutions($invoice, $case, $level, $lang = '')
    {
        global $conf, $langs, $mysoc;
        if (empty($invoice->thirdparty)) { $invoice->fetch_thirdparty(); }
        if ($lang === '') { $lang = is_object($langs) ? $langs->defaultlang : 'de_DE'; }
        $outputlangs = new Translate('', $conf);
        $outputlangs->setDefaultLang($lang);
        $outputlangs->loadLangs(array('main', 'bills', 'companies', 'mahnwesen@mahnwesen'));
        $breakdown = $this->manager->getAmountBreakdown($invoice, $case, $level);
        $openAmount = $this->formatMoney($breakdown['invoice'], $outputlangs);
        $feeAmount = $this->formatMoney($breakdown['fee'], $outputlangs);
        $totalAmount = $this->formatMoney($breakdown['total'], $outputlangs);
        $classLabel = $outputlangs->trans($this->manager->getCustomerClassLabelKey($breakdown['classification']['class']));
        $stageLabel = $outputlangs->trans($this->manager->getStageLabelKey((int) $level));
        $next = '';
        $nextLevel = $this->manager->getNextFutureLevel((int) $level);
        $dueYmd = !empty($invoice->date_lim_reglement) ? dol_print_date($invoice->date_lim_reglement, '%Y-%m-%d', 'tzserver') : '';
        if ($nextLevel > 0 && $dueYmd !== '') {
            $nextAt = $this->manager->calculateWorkflowStageDueAt(!empty($case['id']) ? (int) $case['id'] : 0, $dueYmd, $nextLevel);
            if ($nextAt) { $next = dol_print_date($this->db->jdate($nextAt), 'day', 'tzserver', $outputlangs); }
        }
        $feeParagraph = '';
        if ($breakdown['fee'] > 0.000001) {
            $feeParagraph = stripos($lang, 'de') === 0 ? 'Zusätzlich werden Mahn-/Betreibungskosten in Höhe von <strong>'.$feeAmount.'</strong> berücksichtigt.' : 'In addition, dunning/collection costs of <strong>'.$feeAmount.'</strong> are included.';
        }
        $formmail = new FormMail($this->db);
        $formmail->setSubstitFromObject($invoice, $outputlangs);
        $custom = array(
            '__MAHNWESEN_STAGE__' => $stageLabel, '__MAHNWESEN_OPEN_AMOUNT__' => $openAmount, '__MAHNWESEN_FEE__' => $feeAmount,
            '__MAHNWESEN_TOTAL__' => $totalAmount, '__MAHNWESEN_CUSTOMER_CLASS__' => $classLabel, '__MAHNWESEN_NEXT_STAGE_DATE__' => $next,
            '__MAHNWESEN_FEE_PARAGRAPH__' => $feeParagraph, '{INVOICE_REF}' => (string) $invoice->ref,
            '{CUSTOMER_NAME}' => !empty($invoice->thirdparty) ? (string) $invoice->thirdparty->name : '',
            '{INVOICE_DATE}' => dol_print_date($invoice->date, 'day', 'tzserver', $outputlangs), '{DUE_DATE}' => dol_print_date($invoice->date_lim_reglement, 'day', 'tzserver', $outputlangs),
            '{OPEN_AMOUNT}' => $openAmount, '{DUNNING_FEE}' => $feeAmount, '{DUNNING_TOTAL}' => $totalAmount, '{CUSTOMER_CLASS}' => $classLabel,
            '{DUNNING_STAGE}' => $stageLabel, '{TODAY}' => dol_print_date(dol_now(), 'day', 'tzserver', $outputlangs),
            '{COMPANY_NAME}' => is_object($mysoc) ? (string) $mysoc->name : '', '{NEXT_STAGE_DATE}' => $next, '{FEE_PARAGRAPH}' => $feeParagraph,
        );
        return array('substitutions' => array_merge((array) $formmail->substit, $custom), 'outputlangs' => $outputlangs);
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
        $context = $this->getTemplateSubstitutions($invoice, $case, $level, $lang);
        return make_substitutions((string) $text, $context['substitutions'], $context['outputlangs']);
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
        global $conf;
        $root = $this->getInvoiceDocumentRoot($invoice);
        if ($root === '') { return false; }
        $sql = 'SELECT pdf_path FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt WHERE entity = '.((int) $conf->entity).' AND fk_facture = '.((int) $invoice->id).' AND level = '.((int) $level)." AND status = 'sent' AND pdf_path IS NOT NULL AND pdf_path <> '' ORDER BY rowid DESC".$this->db->plimit(1);
        $res = $this->db->query($sql);
        if (!$res) { return false; }
        $o = $this->db->fetch_object($res); $this->db->free($res);
        if (!$o) { return false; }
        $relative = ltrim((string) $o->pdf_path, '/');
        $fullpath = rtrim($root, '/').'/'.$relative;
        if (!is_file($fullpath) || !is_readable($fullpath) || filesize($fullpath) <= 0) { return false; }
        return array('fullpath'=>$fullpath, 'relative'=>$relative, 'filename'=>basename($fullpath), 'modulepart'=>'invoice', 'preview'=>0);
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
        return dol_sanitizeFileName($invoice->ref).'_'.$this->getStageFilenamePart((int) $level).'_A<id>.pdf';
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
