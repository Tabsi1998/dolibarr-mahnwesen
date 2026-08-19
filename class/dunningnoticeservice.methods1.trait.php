<?php
/* Auto-split method trait for maintainable source files. */
trait DunningNoticeServiceMethods1
{

    public function __construct($db, $manager)
    {
        $this->db = $db;
        $this->manager = $manager;
    }

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
     * Return public active native Mahnwesen templates. When a level is given,
     * only the corresponding native Dolibarr template type is returned.
     *
     * @param int $level 0=all, 1..4=one stage
     * @return array<int,array>
     */
    public function getNativeTemplates($level = 0)
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
        $sql .= ' WHERE entity IN (0, '.((int) $conf->entity).')';
        $sql .= ' AND type_template IN ('.implode(',', $quoted).') AND active = 1 AND private = 0';
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
     * Load a public active native Mahnwesen email template by id and optionally
     * require it to belong to the requested dunning level.
     *
     * @param int $id Template id
     * @param int $level 0=any Mahnwesen type, 1..4=required stage type
     * @return array|false
     */
    public function getNativeTemplateById($id, $level = 0)
    {
        $templates = $this->getNativeTemplates((int) $level);
        $id = (int) $id;
        if (!isset($templates[$id])) {
            $this->error = 'Selected Dolibarr email template is unavailable, inactive, private or not valid for this dunning stage.';
            return false;
        }
        return $templates[$id];
    }

    /**
     * Pick the native default template for a level. Preference order is:
     * customer language + default, neutral language + default, any default,
     * customer language, neutral language, then first public active template.
     *
     * @param int $level Level 1..4
     * @param string $lang Customer language
     * @return array|false
     */
    public function getDefaultNativeTemplateForLevel($level, $lang = 'de_DE')
    {
        $templates = $this->getNativeTemplates($level);
        if (empty($templates)) {
            $this->error = 'No public active Dolibarr email template exists for type '.$this->getTemplateTypeForLevel($level).'.';
            return false;
        }
        $lang = (string) $lang;
        $groups = array();
        foreach ($templates as $tpl) {
            if ($tpl['lang'] === $lang && !empty($tpl['defaultfortype'])) { $groups[1][] = $tpl; }
            elseif ($tpl['lang'] === '' && !empty($tpl['defaultfortype'])) { $groups[2][] = $tpl; }
            elseif (!empty($tpl['defaultfortype'])) { $groups[3][] = $tpl; }
            elseif ($tpl['lang'] === $lang) { $groups[4][] = $tpl; }
            elseif ($tpl['lang'] === '') { $groups[5][] = $tpl; }
            else { $groups[6][] = $tpl; }
        }
        for ($i = 1; $i <= 6; $i++) {
            if (!empty($groups[$i])) {
                return $groups[$i][0];
            }
        }
        return reset($templates);
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
        $created = 0;
        $existing = 0;
        for ($level = 1; $level <= 4; $level++) {
            $type = $types[$level];
            $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_email_templates WHERE entity = '.((int) $conf->entity);
            $sql .= " AND type_template = '".$this->db->escape($type)."' AND lang = 'de_DE'".$this->db->plimit(1);
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
            $tpl = $this->getDefaultTemplate($level, 'de_DE');
            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'c_email_templates (entity, module, type_template, lang, private, fk_user, datec, label, position, defaultfortype, enabled, active, email_from, topic, joinfiles, content) VALUES (';
            $sql .= ((int) $conf->entity).", 'mahnwesen', '".$this->db->escape($type)."', 'de_DE', 0, NULL, '".$this->db->escape($this->db->idate(dol_now()))."', '".$this->db->escape($labels[$level])."', ".($level * 10).", 1, '1', 1, '', '".$this->db->escape($tpl['subject'])."', '1', '".$this->db->escape($tpl['body'])."')";
            if (!$this->db->query($sql)) {
                $this->error = $this->db->lasterror();
                return false;
            }
            $created++;
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
                $native = $this->getNativeTemplateById($id, $level);
            }
        }
        if ($native === false) {
            $native = $this->getDefaultNativeTemplateForLevel($level, $lang);
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
}
