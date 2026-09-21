<?php
/* Dolibarr email templates for the stages, starter templates, and rendering them for an invoice. */
trait DunningNoticeServiceTemplates
{
    /**
     * Built-in HTML fallback template for one stage.
     *
     * @param int $level 1..4
     * @param string $lang Language code
     * @return array{subject:string,body:string}
     */
    public function getDefaultTemplate($level, $lang = 'de_DE')
    {
        $level = max(1, min(4, (int) $level));
        $isGerman = (stripos((string) $lang, 'de') === 0);

        if ($isGerman) {
            $subjects = array(
                1 => 'Zahlungserinnerung zur Rechnung {INVOICE_REF}',
                2 => '1. Mahnung zur Rechnung {INVOICE_REF}',
                3 => '2. Mahnung zur Rechnung {INVOICE_REF}',
                4 => '3. Mahnung zur Rechnung {INVOICE_REF}',
            );
            $bodies = array(
                1 => '<p>Sehr geehrte Damen und Herren,</p><p>bei unserer Prüfung haben wir festgestellt, dass die Rechnung <strong>{INVOICE_REF}</strong> vom {INVOICE_DATE} mit Fälligkeit {DUE_DATE} noch einen offenen Betrag von <strong>{OPEN_AMOUNT}</strong> aufweist.</p><p>Bitte prüfen Sie den Vorgang und überweisen Sie den offenen Betrag, sofern die Zahlung nicht bereits erfolgt ist.</p><p>Mit freundlichen Grüßen<br>{COMPANY_NAME}</p>',
                2 => '<p>Sehr geehrte Damen und Herren,</p><p>für die Rechnung <strong>{INVOICE_REF}</strong> konnten wir bislang keinen vollständigen Zahlungseingang feststellen. Der offene Rechnungsbetrag beträgt <strong>{OPEN_AMOUNT}</strong>; die Rechnung war am {DUE_DATE} fällig.</p><p>{FEE_PARAGRAPH}</p><p>Bitte begleichen Sie den Gesamtbetrag von <strong>{DUNNING_TOTAL}</strong> zeitnah. Sollte die Zahlung bereits erfolgt sein, betrachten Sie dieses Schreiben bitte als gegenstandslos.</p><p>Mit freundlichen Grüßen<br>{COMPANY_NAME}</p>',
                3 => '<p>Sehr geehrte Damen und Herren,</p><p>trotz unserer bisherigen Erinnerung weist die Rechnung <strong>{INVOICE_REF}</strong> weiterhin einen offenen Rechnungsbetrag von <strong>{OPEN_AMOUNT}</strong> auf. Die Fälligkeit war am {DUE_DATE}.</p><p>{FEE_PARAGRAPH}</p><p>Bitte begleichen Sie den Gesamtbetrag von <strong>{DUNNING_TOTAL}</strong> ohne weitere Verzögerung.</p><p>Mit freundlichen Grüßen<br>{COMPANY_NAME}</p>',
                4 => '<p>Sehr geehrte Damen und Herren,</p><p>dies ist unsere 3. Mahnung zur Rechnung <strong>{INVOICE_REF}</strong>. Der offene Rechnungsbetrag von <strong>{OPEN_AMOUNT}</strong> ist seit dem {DUE_DATE} fällig.</p><p>{FEE_PARAGRAPH}</p><p>Bitte veranlassen Sie die Zahlung des Gesamtbetrags von <strong>{DUNNING_TOTAL}</strong> umgehend oder setzen Sie sich zur Klärung mit uns in Verbindung.</p><p>Mit freundlichen Grüßen<br>{COMPANY_NAME}</p>',
            );
        } else {
            $subjects = array(
                1 => 'Payment reminder for invoice {INVOICE_REF}',
                2 => 'First reminder for invoice {INVOICE_REF}',
                3 => 'Second reminder for invoice {INVOICE_REF}',
                4 => 'Third reminder for invoice {INVOICE_REF}',
            );
            $bodies = array(
                1 => '<p>Dear Sir or Madam,</p><p>our records show that invoice <strong>{INVOICE_REF}</strong> dated {INVOICE_DATE}, due on {DUE_DATE}, still has an outstanding balance of <strong>{OPEN_AMOUNT}</strong>.</p><p>Please check the matter and arrange payment if it has not already been made.</p><p>Kind regards<br>{COMPANY_NAME}</p>',
                2 => '<p>Dear Sir or Madam,</p><p>we have not yet received full payment for invoice <strong>{INVOICE_REF}</strong>. The outstanding invoice amount is <strong>{OPEN_AMOUNT}</strong>; it was due on {DUE_DATE}.</p><p>{FEE_PARAGRAPH}</p><p>Please arrange payment of the total amount of <strong>{DUNNING_TOTAL}</strong> promptly. If payment has already been made, please disregard this reminder.</p><p>Kind regards<br>{COMPANY_NAME}</p>',
                3 => '<p>Dear Sir or Madam,</p><p>despite our previous reminder, invoice <strong>{INVOICE_REF}</strong> still shows an outstanding invoice amount of <strong>{OPEN_AMOUNT}</strong>. It has been overdue since {DUE_DATE}.</p><p>{FEE_PARAGRAPH}</p><p>Please settle the total amount of <strong>{DUNNING_TOTAL}</strong> without further delay.</p><p>Kind regards<br>{COMPANY_NAME}</p>',
                4 => '<p>Dear Sir or Madam,</p><p>this is our third reminder regarding invoice <strong>{INVOICE_REF}</strong>. The outstanding invoice amount of <strong>{OPEN_AMOUNT}</strong> has been overdue since {DUE_DATE}.</p><p>{FEE_PARAGRAPH}</p><p>Please arrange payment of the total amount of <strong>{DUNNING_TOTAL}</strong> immediately or contact us to clarify the matter.</p><p>Kind regards<br>{COMPANY_NAME}</p>',
            );
        }

        return array('subject' => $subjects[$level], 'body' => $bodies[$level]);
    }

    /**
     * Return the dedicated Dolibarr email-template type for one dunning level.
     *
     * @param int $level Level 1..4
     * @return string
     */
    public function getTemplateTypeForLevel($level)
    {
        $types = array(
            1 => 'mahnwesen_reminder',
            2 => 'mahnwesen_dunning1',
            3 => 'mahnwesen_dunning2',
            4 => 'mahnwesen_dunning3',
        );
        $level = max(1, min(4, (int) $level));
        return $types[$level];
    }

    /** @return array<int,string> */
    public function getTemplateTypes()
    {
        return array(
            1 => 'mahnwesen_reminder',
            2 => 'mahnwesen_dunning1',
            3 => 'mahnwesen_dunning2',
            4 => 'mahnwesen_dunning3',
        );
    }

    /**
     * Return public and current-user private native Mahnwesen templates. When
     * a level is given, only that Dolibarr template type is returned.
     *
     * @param int $level 0=all, 1..4=one stage
     * @return array<int,array>
     */
    public function getNativeTemplates($level = 0, $user = null)
    {
        global $conf;
        $rows = array();
        $types = $this->getTemplateTypes();
        $wanted = array_values($types);
        if ((int) $level >= 1 && (int) $level <= 4) {
            $wanted = array($this->getTemplateTypeForLevel((int) $level));
        }
        $quoted = array();
        foreach ($wanted as $type) {
            $quoted[] = "'".$this->db->escape($type)."'";
        }

        $sql = 'SELECT rowid, entity, module, type_template, lang, private, fk_user, label, position, defaultfortype, enabled, active, email_from, topic, joinfiles, content';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'c_email_templates';
        $sql .= ' WHERE entity IN ('.getEntity('c_email_templates').')';
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $sql .= ' AND type_template IN ('.implode(',', $quoted).') AND active = 1 AND (private = 0'.($uid > 0 ? ' OR fk_user = '.$uid : '').')';
        $sql .= ' ORDER BY defaultfortype DESC, position ASC, label ASC, lang ASC, rowid ASC';
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            return $rows;
        }
        while ($o = $this->db->fetch_object($res)) {
            $rows[(int) $o->rowid] = array(
                'id' => (int) $o->rowid,
                'entity' => (int) $o->entity,
                'module' => (string) $o->module,
                'type_template' => (string) $o->type_template,
                'lang' => (string) $o->lang,
                'private' => (int) $o->private,
                'fk_user' => isset($o->fk_user) ? (int) $o->fk_user : 0,
                'label' => (string) $o->label,
                'position' => (int) $o->position,
                'defaultfortype' => (int) $o->defaultfortype,
                'email_from' => (string) $o->email_from,
                'topic' => (string) $o->topic,
                'joinfiles' => (string) $o->joinfiles,
                'content' => (string) $o->content,
            );
        }
        $this->db->free($res);
        return $rows;
    }

    /**
     * Load an accessible active native Mahnwesen email template by id and
     * optionally require it to belong to the requested dunning level.
     *
     * @param int $id Template id
     * @param int $level 0=any Mahnwesen type, 1..4=required stage type
     * @return array|false
     */
    public function getNativeTemplateById($id, $level = 0, $user = null)
    {
        $templates = $this->getNativeTemplates((int) $level, $user);
        $id = (int) $id;
        if (!isset($templates[$id])) {
            $this->error = 'Selected Dolibarr email template is unavailable, inactive, private or not valid for this dunning stage.';
            return false;
        }
        return $templates[$id];
    }

    /** Return one native row in the normalized structure used for delivery. */
    public function getTemplateById($id, $level, $lang, $user = null)
    {
        $native = $this->getNativeTemplateById($id, $level, $user);
        if ($native === false) { return false; }
        return array(
            'subject' => (string) $native['topic'], 'body' => (string) $native['content'], 'source' => 'native',
            'source_ref' => 'native:'.$native['id'], 'source_id' => (int) $native['id'], 'label' => (string) $native['label'],
            'lang' => $native['lang'] !== '' ? (string) $native['lang'] : (string) $lang, 'email_from' => (string) $native['email_from'],
            'joinfiles' => (string) $native['joinfiles'], 'type_template' => (string) $native['type_template'],
        );
    }

    /**
     * Pick the template for a level automatically.
     *
     * A template the administrator wrote beats the module's starter templates,
     * as long as its language fits the customer: the same language, the same
     * language family, or no language at all. Within that, the closer language
     * wins, then "default for type", then Dolibarr's order. A template in
     * another language is used only with the explicit cross-language fallback.
     *
     * @param int $level Level 1..4
     * @param string $lang Customer language
     * @return array|false
     */
    public function getDefaultNativeTemplateForLevel($level, $lang = 'de_DE', $user = null)
    {
        $templates = $this->getNativeTemplates($level, $user);
        if (empty($templates)) {
            $this->error = 'No public active Dolibarr email template exists for type '.$this->getTemplateTypeForLevel($level).'.';
            return false;
        }
        $lang = (string) $lang;
        $langFamily = strtolower((string) preg_replace('/[_-].*$/', '', $lang));
        $best = false;
        $bestRank = null;
        $index = 0;
        foreach ($templates as $tpl) {
            $index++;
            $tplLang = (string) $tpl['lang'];
            $tplFamily = strtolower((string) preg_replace('/[_-].*$/', '', $tplLang));
            if ($tplLang === $lang) { $match = 1; }
            elseif ($langFamily !== '' && $tplFamily === $langFamily) { $match = 2; }
            elseif ($tplLang === '') { $match = 3; }
            elseif (getDolGlobalInt('MAHNWESEN_ALLOW_LANGUAGE_FALLBACK', 0) && !empty($tpl['defaultfortype'])) { $match = 4; }
            else { continue; }
            $starter = ((string) ($tpl['module'] ?? '') === 'mahnwesen') ? 1 : 0;
            // The fallback to another language comes last, whoever wrote it.
            $rank = array($match === 4 ? 1 : 0, $starter, $match, empty($tpl['defaultfortype']) ? 1 : 0, $index);
            if ($bestRank === null || $rank < $bestRank) {
                $best = $tpl;
                $bestRank = $rank;
            }
        }
        if ($best !== false) {
            return $best;
        }
        $this->error = 'No active dunning template matches customer language '.$lang.'.';
        return false;
    }

    /**
     * Languages the module writes starter templates for: German and English,
     * as far as the company or a customer uses them. German when neither does.
     *
     * @return string[]
     */
    public function getStarterLanguages()
    {
        $families = array();
        $default = strtolower(getDolGlobalString('MAIN_LANG_DEFAULT'));
        foreach (array('de' => 'de_DE', 'en' => 'en_US') as $family => $language) {
            if (strpos($default, $family) === 0) {
                $families[$language] = true;
                continue;
            }
            $sql = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'societe WHERE entity IN ('.getEntity('societe').")";
            $sql .= " AND client > 0 AND default_lang LIKE '".$this->db->escape($family)."%'";
            $res = $this->db->query($sql);
            if ($res) {
                $row = $this->db->fetch_object($res);
                $this->db->free($res);
                if ($row && (int) $row->nb > 0) {
                    $families[$language] = true;
                }
            }
        }
        return empty($families) ? array('de_DE') : array_keys($families);
    }

    /**
     * Create one starter template per native type. This remains available as a
     * helper, but the normal workflow is to create/edit templates directly in
     * Dolibarr under E-Mail-Einstellungen -> E-Mail-Vorlagen.
     *
     * @param User $user Acting administrator
     * @return array{created:int,existing:int}|false
     */
    public function createDefaultNativeTemplates($user)
    {
        global $conf;
        $types = $this->getTemplateTypes();
        $labels = array(
            1 => 'Mahnwesen - Zahlungserinnerung',
            2 => 'Mahnwesen - 1. Mahnung',
            3 => 'Mahnwesen - 2. Mahnung',
            4 => 'Mahnwesen - 3. Mahnung',
        );
        // Dolibarr's template list translates a label's part in parentheses,
        // which turned "Mahnwesen - 2. Mahnung (English)" into "English".
        foreach ($labels as $label) {
            $sql = 'UPDATE '.MAIN_DB_PREFIX."c_email_templates SET label = '".$this->db->escape($label.' - English')."'";
            $sql .= ' WHERE entity = '.((int) $conf->entity)." AND module = 'mahnwesen' AND label = '".$this->db->escape($label.' (English)')."'";
            if (!$this->db->query($sql)) {
                $this->error = $this->db->lasterror();
                return false;
            }
        }
        $created = 0;
        $existing = 0;
        foreach ($this->getStarterLanguages() as $starterLang) {
          $family = substr($starterLang, 0, 2);
          for ($level = 1; $level <= 4; $level++) {
            $type = $types[$level];
            // Any template of the stage that already serves the language - its
            // own, one of the family, or one without language - makes a starter
            // unnecessary, active or not.
            $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_email_templates WHERE entity = '.((int) $conf->entity);
            $sql .= " AND type_template = '".$this->db->escape($type)."'";
            $sql .= " AND (lang IS NULL OR lang = '' OR lang LIKE '".$this->db->escape($family)."%')".$this->db->plimit(1);
            $res = $this->db->query($sql);
            if (!$res) {
                $this->error = $this->db->lasterror();
                return false;
            }
            $exists = $this->db->fetch_object($res);
            $this->db->free($res);
            if ($exists) {
                $existing++;
                continue;
            }
            $tpl = $this->getDefaultTemplate($level, $starterLang);
            $starterLabel = $labels[$level].($starterLang === 'en_US' ? ' - English' : '');
            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'c_email_templates (entity, module, type_template, lang, private, fk_user, datec, label, position, defaultfortype, enabled, active, email_from, topic, joinfiles, content) VALUES (';
            $sql .= ((int) $conf->entity).", 'mahnwesen', '".$this->db->escape($type)."', '".$this->db->escape($starterLang)."', 0, NULL, '".$this->db->escape($this->db->idate(dol_now()))."', '".$this->db->escape($starterLabel)."', ".($level * 10).", 1, '1', 1, '', '".$this->db->escape($tpl['subject'])."', '1', '".$this->db->escape($tpl['body'])."')";
            if (!$this->db->query($sql)) {
                $this->error = $this->db->lasterror();
                return false;
            }
            $created++;
          }
        }
        return array('created' => $created, 'existing' => $existing);
    }

    /**
     * Remove only byte-identical starter duplicates that are owned by this
     * module. This is intentionally conservative: templates with different
     * content, labels, language or sender settings are preserved.
     *
     * @return int Number of duplicate rows removed, -1 on SQL error
     */
    public function cleanupExactDuplicateModuleTemplates()
    {
        global $conf;
        $types = array_values($this->getTemplateTypes());
        $quoted = array();
        foreach ($types as $type) { $quoted[] = "'".$this->db->escape($type)."'"; }
        $sql = 'SELECT rowid, type_template, lang, private, label, email_from, topic, joinfiles, content';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'c_email_templates';
        $sql .= ' WHERE entity = '.((int) $conf->entity)." AND module = 'mahnwesen'";
        $sql .= ' AND type_template IN ('.implode(',', $quoted).')';
        $sql .= ' ORDER BY rowid ASC';
        $res = $this->db->query($sql);
        if (!$res) { $this->error = $this->db->lasterror(); return -1; }

        $seen = array();
        $deleteIds = array();
        while ($o = $this->db->fetch_object($res)) {
            $fingerprint = hash('sha256', implode("\x1f", array(
                (string) $o->type_template,
                (string) $o->lang,
                (string) $o->private,
                (string) $o->label,
                (string) $o->email_from,
                (string) $o->topic,
                (string) $o->joinfiles,
                (string) $o->content,
            )));
            if (isset($seen[$fingerprint])) { $deleteIds[] = (int) $o->rowid; }
            else { $seen[$fingerprint] = (int) $o->rowid; }
        }
        $this->db->free($res);

        $removed = 0;
        foreach ($deleteIds as $rowid) {
            if (!$this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'c_email_templates WHERE rowid = '.((int) $rowid)." AND module = 'mahnwesen'")) {
                $this->error = $this->db->lasterror();
                return -1;
            }
            $removed++;
        }
        return $removed;
    }

    /**
     * Return the Dolibarr-native template for a stage. A legacy explicit
     * native:<id> selection is still honored when it belongs to the correct
     * stage type. Otherwise the default public template for that stage type is
     * selected automatically.
     *
     * @param int $level Level 1..4
     * @param string $lang Customer language
     * @param User|null $user User (kept for API compatibility)
     * @return array|false
     */
    public function getTemplate($level, $lang = 'de_DE', $user = null)
    {
        $level = max(1, min(4, (int) $level));
        $rule = $this->manager->getRuleByLevel($level);
        $source = isset($rule['email_template']) ? trim((string) $rule['email_template']) : 'native:auto';

        $native = false;
        if (strpos($source, 'native:') === 0 && $source !== 'native:auto') {
            $id = (int) substr($source, 7);
            if ($id > 0) {
                $native = $this->getNativeTemplateById($id, $level, $user);
            }
        }
        if ($native === false) {
            $native = $this->getDefaultNativeTemplateForLevel($level, $lang, $user);
        }
        if ($native === false) {
            return false;
        }

        return array(
            'subject' => (string) $native['topic'],
            'body' => (string) $native['content'],
            'source' => 'native',
            'source_ref' => 'native:'.$native['id'],
            'source_id' => (int) $native['id'],
            'label' => (string) $native['label'],
            'lang' => $native['lang'] !== '' ? (string) $native['lang'] : (string) $lang,
            'email_from' => (string) $native['email_from'],
            'joinfiles' => (string) $native['joinfiles'],
            'type_template' => (string) $native['type_template'],
        );
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
        $nextTs = 0;
        $nextLevel = $this->manager->getNextFutureLevel((int) $level);
        $dueYmd = !empty($invoice->date_lim_reglement) ? dol_print_date($invoice->date_lim_reglement, '%Y-%m-%d', 'tzserver') : '';
        if ($nextLevel > 0 && $dueYmd !== '') {
            $nextAt = $this->manager->calculateWorkflowStageDueAt(!empty($case['id']) ? (int) $case['id'] : 0, $dueYmd, $nextLevel);
            if ($nextAt) { $nextTs = (int) $this->db->jdate($nextAt); }
        }
        $deadline = $this->manager->getPaymentDeadline((int) $level);
        $paymentDeadline = $deadline ? dol_print_date($deadline, 'day', 'tzserver', $outputlangs) : '';
        $paymentDays = $deadline ? (string) $this->manager->getPaymentDaysForLevel((int) $level) : '';
        if (!empty($nextTs)) {
            // The next stage waits until the day after this notice's deadline (#64).
            if ($deadline && dol_print_date($nextTs, '%Y-%m-%d', 'tzserver') <= dol_print_date($deadline, '%Y-%m-%d', 'tzserver')) { $nextTs = (int) strtotime('+1 day', $deadline); }
            $next = dol_print_date($nextTs, 'day', 'tzserver', $outputlangs);
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
            '__MAHNWESEN_FEE_PARAGRAPH__' => $feeParagraph, '__MAHNWESEN_PAYMENT_DEADLINE__' => $paymentDeadline,
            '__MAHNWESEN_PAYMENT_DAYS__' => $paymentDays, '{INVOICE_REF}' => (string) $invoice->ref,
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
}
