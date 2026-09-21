<?php
/* Recipients and sender of a notice. */
trait DunningNoticeServiceRecipients
{
    /**
     * Resolve eligible recipients. External invoice BILLING contacts are first;
     * the third-party email is the fallback.
     *
     * @param Facture $invoice Invoice
     * @return array<int,array{email:string,label:string,source:string,contact_id:int,origin?:string}>
     */
    public function getRecipientOptions($invoice)
    {
        $this->recipientLookupFailed = false;
        $options = array();
        $seen = array();

        // The billing contacts of the invoice; without one, the customer's
        // default billing contact for invoices (#22). Both are BILLING roles.
        $select = 'SELECT sp.rowid, sp.firstname, sp.lastname, sp.email';
        $where = " AND tc.element = 'facture' AND tc.source = 'external' AND tc.code = 'BILLING'";
        $where .= " AND sp.email IS NOT NULL AND sp.email <> '' AND sp.statut = 1";
        $order = ' ORDER BY sp.lastname ASC, sp.firstname ASC, sp.rowid ASC';
        foreach (array('invoice', 'customer') as $origin) {
            if ($origin === 'invoice') {
                $sql = $select.' FROM '.MAIN_DB_PREFIX.'element_contact as ec';
                $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'c_type_contact as tc ON tc.rowid = ec.fk_c_type_contact';
                $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'socpeople as sp ON sp.rowid = ec.fk_socpeople';
                $sql .= ' WHERE ec.element_id = '.((int) $invoice->id).$where.$order;
            } elseif (!empty($options)) {
                break;
            } else {
                $sql = $select.' FROM '.MAIN_DB_PREFIX.'societe_contacts as sc';
                $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'c_type_contact as tc ON tc.rowid = sc.fk_c_type_contact';
                $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'socpeople as sp ON sp.rowid = sc.fk_socpeople';
                $sql .= ' WHERE sc.fk_soc = '.((int) $invoice->socid).' AND sc.entity IN ('.getEntity('societe').')'.$where.$order;
            }
            $resql = $this->db->query($sql);
            if (!$resql) {
                $this->recipientLookupFailed = true;
                $this->error = 'Recipient contact lookup failed.';
                $this->errors[] = $this->error;
                dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
                // A technical lookup failure is not equivalent to there being no
                // billing contact. Fail closed and never use the company fallback.
                return array();
            }
            while ($obj = $this->db->fetch_object($resql)) {
                $email = trim((string) $obj->email);
                $key = strtolower($email);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$key])) {
                    continue;
                }
                $name = trim((string) $obj->firstname.' '.(string) $obj->lastname);
                $options[] = array(
                    'email' => $email,
                    'label' => ($name !== '' ? $name.' <'.$email.'>' : $email),
                    'source' => 'billing_contact',
                    'origin' => $origin,
                    'contact_id' => (int) $obj->rowid,
                );
                $seen[$key] = 1;
            }
            $this->db->free($resql);
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

    /** Resolve one FormMail receiver value to the module's validated option. */
    public function getRecipientOptionByFormValue($invoice, $value)
    {
        $value = (string) $value;
        foreach ($this->getRecipientOptions($invoice) as $option) {
            if (($option['source'] === 'thirdparty' && $value === 'thirdparty') || ($option['source'] === 'billing_contact' && (int) $value === (int) $option['contact_id'])) { return $option; }
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

    /** Normalize a comma/semicolon separated list of plain email addresses. */
    protected function normalizeEmailList($value)
    {
        $value = trim((string) $value);
        if ($value === '') { return ''; }
        $parts = preg_split('/[;,]+/', $value);
        $clean = array();
        foreach ($parts as $part) {
            $email = trim($part);
            if ($email === '') { continue; }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { return false; }
            $clean[strtolower($email)] = $email;
        }
        return implode(',', array_values($clean));
    }
}
