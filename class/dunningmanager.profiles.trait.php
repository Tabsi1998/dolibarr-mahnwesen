<?php
/* Dunning profiles: stage settings per kind of claim, and which profile applies to an invoice (#32). */
trait DunningManagerProfiles
{
    /** @return array<string,string> What a profile names as the step after its last stage, with its language key */
    public function getFinalSteps()
    {
        return array('none' => 'MahnwesenFinalStepNone', 'collection' => 'MahnwesenFinalStepCollection', 'membership_review' => 'MahnwesenFinalStepMembership');
    }

    /** @return array<string,array> Profiles the setup offers to add, switched off, without amounts */
    public function getProfilePresets()
    {
        return array(
            'company_at' => array('label' => 'MahnwesenPresetCompanyAt', 'auto_allowed' => 1, 'final_step' => 'collection', 'customer_type' => 'company'),
            'club' => array('label' => 'MahnwesenPresetClub', 'auto_allowed' => 0, 'final_step' => 'membership_review', 'customer_type' => ''),
            'membership' => array('label' => 'MahnwesenPresetMembership', 'auto_allowed' => 0, 'final_step' => 'membership_review', 'customer_type' => '', 'membership' => 1),
        );
    }

    /**
     * The profiles of the active entity by id, the default one first.
     *
     * @param bool $refresh Read again
     * @return array<int,array>
     */
    public function getProfiles($refresh = false)
    {
        global $conf;
        if (!$refresh && is_array($this->profilesCache)) {
            return $this->profilesCache;
        }
        $profiles = array();
        $sql = 'SELECT rowid, code, label, is_default, auto_allowed, final_step, active, interest_mode, interest_rate FROM '.MAIN_DB_PREFIX.'mahnwesen_profile';
        $sql .= ' WHERE entity = '.((int) $conf->entity).' ORDER BY is_default DESC, label ASC, rowid ASC';
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            return $profiles;
        }
        while ($o = $this->db->fetch_object($res)) {
            $profiles[(int) $o->rowid] = array('id' => (int) $o->rowid, 'code' => (string) $o->code, 'label' => (string) $o->label,
                'is_default' => (int) $o->is_default, 'auto_allowed' => (int) $o->auto_allowed, 'final_step' => (string) $o->final_step,
                'active' => (int) $o->active, 'interest_mode' => (string) $o->interest_mode, 'interest_rate' => (float) $o->interest_rate);
        }
        $this->db->free($res);
        $this->profilesCache = $profiles;
        return $profiles;
    }

    /**
     * Id of the default profile. It is created when missing, and the stage rows
     * of versions before profiles become its stages (#32).
     *
     * @param User|null $user Acting user
     * @return int 0 when it cannot be created
     */
    public function getDefaultProfileId($user = null)
    {
        global $conf, $langs;
        foreach ($this->getProfiles() as $id => $profile) {
            if (!empty($profile['is_default'])) {
                return (int) $id;
            }
        }
        $label = 'Standard';
        if (is_object($langs)) {
            $langs->load('mahnwesen@mahnwesen');
            $label = $langs->transnoentitiesnoconv('MahnwesenProfileDefaultLabel');
        }
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_profile (entity, code, label, is_default, auto_allowed, final_step, active, date_creation, fk_user_create, fk_user_modif)';
        $sql .= ' VALUES ('.((int) $conf->entity).", 'default', '".$this->db->escape($label)."', 1, 1, 'none', 1, '".$this->db->idate(dol_now())."', ".$uid.', '.$uid.')';
        // A parallel request may have created it meanwhile; the unique code keeps it single.
        $created = $this->db->query($sql);
        foreach ($this->getProfiles(true) as $id => $profile) {
            if (!empty($profile['is_default'])) {
                if ($created && !$this->db->query('UPDATE '.MAIN_DB_PREFIX.'mahnwesen_rule SET fk_profile = '.((int) $id).' WHERE entity = '.((int) $conf->entity).' AND fk_profile = 0')) {
                    $this->error = $this->db->lasterror();
                    return 0;
                }
                $this->rulesCache = null;
                return (int) $id;
            }
        }
        $this->error = $this->db->lasterror();
        return 0;
    }

    /**
     * Every category and customer type that leads to a profile.
     *
     * @param bool $refresh Read again
     * @return array{product_category:array<int,int>,customer_category:array<int,int>,customer_type:array<string,int>,membership:int}|false
     */
    public function getProfileMatches($refresh = false)
    {
        global $conf;
        if (!$refresh && is_array($this->profileMatchesCache)) {
            return $this->profileMatchesCache;
        }
        $matches = array('product_category' => array(), 'customer_category' => array(), 'customer_type' => array(), 'membership' => 0);
        $res = $this->db->query('SELECT fk_profile, kind, fk_categorie, customer_type FROM '.MAIN_DB_PREFIX.'mahnwesen_profile_match WHERE entity = '.((int) $conf->entity));
        if (!$res) {
            $this->error = $this->db->lasterror();
            return false;
        }
        while ($o = $this->db->fetch_object($res)) {
            if ($o->kind === 'membership') {
                $matches['membership'] = (int) $o->fk_profile;
            } elseif ($o->kind === 'customer_type') {
                $matches['customer_type'][(string) $o->customer_type] = (int) $o->fk_profile;
            } elseif (isset($matches[$o->kind])) {
                $matches[$o->kind][(int) $o->fk_categorie] = (int) $o->fk_profile;
            }
        }
        $this->db->free($res);
        $this->profileMatchesCache = $matches;
        return $matches;
    }

    /**
     * The categories and the customer type that lead to one profile.
     *
     * @param int $profileId Profile
     * @return array{product_category:int[],customer_category:int[],customer_type:string}
     */
    public function getMatchesOfProfile($profileId)
    {
        $out = array('product_category' => array(), 'customer_category' => array(), 'customer_type' => '', 'membership' => 0);
        foreach ((array) $this->getProfileMatches() as $kind => $map) {
            if ($kind === 'membership') {
                $out['membership'] = ((int) $map === (int) $profileId) ? 1 : 0;
                continue;
            }
            foreach ($map as $key => $owner) {
                if ((int) $owner !== (int) $profileId) {
                    continue;
                }
                if ($kind === 'customer_type') {
                    $out['customer_type'] = (string) $key;
                } else {
                    $out[$kind][] = (int) $key;
                }
            }
        }
        return $out;
    }

    /**
     * The membership an invoice belongs to, through Dolibarr's own link
     * between a subscription and the invoice (#58).
     *
     * Only that documented link counts. A customer category, a note or the
     * same email is no proof, so a sale to a member stays an ordinary sale.
     *
     * @param int $invoiceId Invoice
     * @return array{subscription:int,member:int,name:string}|null
     */
    public function getMembershipOfInvoice($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        if (isset($this->membershipCache[$invoiceId])) {
            return $this->membershipCache[$invoiceId];
        }
        $sql = 'SELECT s.rowid as subscription, a.rowid as member, a.firstname, a.lastname, a.societe';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'element_element as e';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'subscription as s ON s.rowid = e.fk_source';
        $sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'adherent as a ON a.rowid = s.fk_adherent';
        $sql .= " WHERE e.sourcetype = 'subscription' AND e.targettype = 'facture' AND e.fk_target = ".$invoiceId;
        $sql .= ' ORDER BY s.rowid ASC'.$this->db->plimit(1);
        $res = $this->db->query($sql);
        if (!$res) {
            // Without the members module the tables can be missing; that is no membership.
            $this->membershipCache[$invoiceId] = null;
            return null;
        }
        $o = $this->db->fetch_object($res);
        $this->db->free($res);
        $membership = $o ? array('subscription' => (int) $o->subscription, 'member' => (int) $o->member,
            'name' => trim(trim((string) $o->firstname.' '.(string) $o->lastname).' '.(string) $o->societe)) : null;
        $this->membershipCache[$invoiceId] = $membership;
        return $membership;
    }

    /**
     * Dolibarr's categories of one type: 0 products and services, 2 customers.
     *
     * @param int $type Category type
     * @return array<int,array{parent:int,label:string}>
     */
    public function getCategoryTree($type)
    {
        $type = (int) $type;
        if (isset($this->categoryTreeCache[$type])) {
            return $this->categoryTreeCache[$type];
        }
        $tree = array();
        $res = $this->db->query('SELECT rowid, fk_parent, label FROM '.MAIN_DB_PREFIX.'categorie WHERE type = '.$type.' AND entity IN ('.getEntity('category').')');
        while ($res && ($o = $this->db->fetch_object($res))) {
            $tree[(int) $o->rowid] = array('parent' => (int) $o->fk_parent, 'label' => (string) $o->label);
        }
        if ($res) {
            $this->db->free($res);
        }
        $this->categoryTreeCache[$type] = $tree;
        return $tree;
    }

    /**
     * Categories of one type with their full path, for choosing them in the setup.
     *
     * @param int $type Category type
     * @return array<int,string>
     */
    public function getCategoryChoices($type)
    {
        $tree = $this->getCategoryTree($type);
        $choices = array();
        foreach ($tree as $id => $category) {
            $path = array($category['label']);
            $seen = array($id => true);
            for ($parent = $category['parent']; $parent > 0 && isset($tree[$parent]) && !isset($seen[$parent]); $parent = $tree[$parent]['parent']) {
                $seen[$parent] = true;
                array_unshift($path, $tree[$parent]['label']);
            }
            $choices[$id] = implode(' >> ', $path);
        }
        asort($choices);
        return $choices;
    }

    /**
     * The profiles a set of categories leads to. Each category counts through
     * its nearest parent that an active profile names.
     *
     * @param array<int,int> $mapped Category => profile
     * @param int[] $categoryIds Categories of the invoice or the customer
     * @param int $type Category type
     * @param array<int,array> $active Active profiles
     * @return array<int,string[]> Profile => names of the categories that led to it
     */
    protected function profilesForCategories($mapped, $categoryIds, $type, $active)
    {
        $found = array();
        if (empty($mapped) || empty($categoryIds)) {
            return $found;
        }
        $tree = $this->getCategoryTree($type);
        foreach ($categoryIds as $categoryId) {
            $seen = array();
            for ($id = (int) $categoryId; $id > 0 && !isset($seen[$id]); $id = isset($tree[$id]) ? (int) $tree[$id]['parent'] : 0) {
                $seen[$id] = true;
                if (isset($mapped[$id]) && isset($active[$mapped[$id]])) {
                    $found[$mapped[$id]][] = isset($tree[$id]) ? $tree[$id]['label'] : '#'.$id;
                    break;
                }
            }
        }
        return $found;
    }

    /** @return int[] Categories of the products and services on an invoice */
    protected function getInvoiceProductCategories($invoiceId)
    {
        $ids = array();
        $sql = 'SELECT DISTINCT cp.fk_categorie FROM '.MAIN_DB_PREFIX.'facturedet as d';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'categorie_product as cp ON cp.fk_product = d.fk_product WHERE d.fk_facture = '.((int) $invoiceId);
        $res = $this->db->query($sql);
        while ($res && ($o = $this->db->fetch_object($res))) {
            $ids[] = (int) $o->fk_categorie;
        }
        if ($res) {
            $this->db->free($res);
        }
        return $ids;
    }

    /** @return int[] Customer categories of a third party */
    protected function getCustomerCategories($socid)
    {
        $ids = array();
        $res = $this->db->query('SELECT fk_categorie FROM '.MAIN_DB_PREFIX.'categorie_societe WHERE fk_soc = '.((int) $socid));
        while ($res && ($o = $this->db->fetch_object($res))) {
            $ids[] = (int) $o->fk_categorie;
        }
        if ($res) {
            $this->db->free($res);
        }
        return $ids;
    }

    /**
     * The profile that applies to an invoice, and why (#32).
     *
     * The first rule that finds an active profile decides: the choice on the
     * invoice, the categories of its products and services, the categories of
     * the customer, the customer's type, then the default profile. When one
     * rule finds several profiles, the most careful one applies. When the
     * assignment cannot be read, the most careful of all profiles applies.
     *
     * @param int $invoiceId Invoice
     * @return array{profile_id:int,profile:array,reason:string,names:string[],candidates:int[]}
     */
    public function resolveProfile($invoiceId)
    {
        global $langs;
        $invoiceId = (int) $invoiceId;
        if (isset($this->profileResolutionCache[$invoiceId])) {
            return $this->profileResolutionCache[$invoiceId];
        }
        $defaultId = $this->getDefaultProfileId();
        $profiles = $this->getProfiles();
        $active = array();
        foreach ($profiles as $id => $profile) {
            if (!empty($profile['active']) || !empty($profile['is_default'])) {
                $active[$id] = $profile;
            }
        }
        $found = array();
        $reason = 'default';
        $sql = 'SELECT f.fk_soc, fe.mahnwesen_profile FROM '.MAIN_DB_PREFIX.'facture as f';
        $sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'facture_extrafields as fe ON fe.fk_object = f.rowid WHERE f.rowid = '.$invoiceId;
        $res = $this->db->query($sql);
        $obj = $res ? $this->db->fetch_object($res) : null;
        if ($res) {
            $this->db->free($res);
        }
        $matches = $res ? $this->getProfileMatches() : false;
        if (!$res || $matches === false) {
            $reason = 'unreadable';
            $this->errors[] = 'Unable to read the dunning profile of invoice '.$invoiceId.': '.$this->db->lasterror();
            foreach (array_keys($active) as $id) {
                $found[$id] = array();
            }
        } elseif ($obj) {
            $socid = (int) $obj->fk_soc;
            $choice = (int) $obj->mahnwesen_profile;
            if ($choice > 0 && isset($active[$choice])) {
                $found[$choice] = array();
                $reason = 'invoice_choice';
            }
            // A membership fee is proven by the link to its subscription (#58).
            // On a mixed invoice the categories count as well, and the most
            // careful profile of them all applies.
            $categories = $this->profilesForCategories($matches['product_category'], $this->getInvoiceProductCategories($invoiceId), 0, $active);
            if (!$found && !empty($matches['membership']) && isset($active[$matches['membership']]) && $this->getMembershipOfInvoice($invoiceId)) {
                $found = $categories;
                $found[$matches['membership']] = array();
                $reason = 'membership';
            }
            if (!$found) {
                $found = $categories;
                $reason = 'product_category';
            }
            if (!$found) {
                $found = $this->profilesForCategories($matches['customer_category'], $this->getCustomerCategories($socid), 2, $active);
                $reason = 'customer_category';
            }
            if (!$found) {
                $class = $this->classifyThirdparty((object) array('id' => $socid));
                $type = $class['class'] === 'consumer' ? 'private' : ($class['class'] === 'business' ? 'company' : '');
                if ($type !== '' && isset($matches['customer_type'][$type]) && isset($active[$matches['customer_type'][$type]])) {
                    $found[$matches['customer_type'][$type]] = array($langs->transnoentitiesnoconv($type === 'private' ? 'MahnwesenCustomerTypePrivate' : 'MahnwesenCustomerTypeCompany'));
                    $reason = 'customer_type';
                }
            }
        }
        if (!$found) {
            $found[$defaultId] = array();
            $reason = 'default';
        }
        $profileId = count($found) > 1 ? $this->mostCarefulProfile(array_keys($found)) : (int) key($found);
        $names = array();
        foreach ($found as $list) {
            foreach ($list as $name) {
                $names[$name] = $name;
            }
        }
        $resolution = array(
            'profile_id' => $profileId,
            'profile' => isset($profiles[$profileId]) ? $profiles[$profileId]
                : array('id' => 0, 'code' => '', 'label' => '', 'is_default' => 1, 'auto_allowed' => 0, 'final_step' => 'none', 'active' => 1,
                    'interest_mode' => 'none', 'interest_rate' => 0.0),
            'reason' => $reason,
            'names' => array_values($names),
            'candidates' => array_map('intval', array_keys($found)),
        );
        $this->profileResolutionCache[$invoiceId] = $resolution;
        return $resolution;
    }

    /**
     * The most careful of several profiles: one without automatic sending
     * before one with it, then the lowest fees, then the oldest.
     *
     * @param int[] $profileIds Profiles
     * @return int
     */
    public function mostCarefulProfile($profileIds)
    {
        $profiles = $this->getProfiles();
        $best = 0;
        $bestKey = null;
        foreach ($profileIds as $id) {
            $key = array(empty($profiles[$id]['auto_allowed']) ? 0 : 1, round($this->getProfileFeeTotal($id), 2), (int) $id);
            if ($bestKey === null || $key < $bestKey) {
                $best = (int) $id;
                $bestKey = $key;
            }
        }
        return $best;
    }

    /** Sum of the fees of the switched-on stages of a profile. */
    public function getProfileFeeTotal($profileId)
    {
        $total = 0.0;
        foreach ($this->getRules(false, $profileId) as $rule) {
            if (!empty($rule['enabled'])) {
                $total += (float) $rule['fee_amount'];
            }
        }
        return $total;
    }

    /**
     * The profile of an invoice and why, in one phrase. HTML, names escaped;
     * they stay out of trans(), which would encode them a second time.
     *
     * @param array $resolution From resolveProfile()
     * @return string
     */
    public function describeProfile($resolution)
    {
        global $langs;
        $reasons = array(
            'invoice_choice' => 'MahnwesenProfileReasonChoice',
            'product_category' => 'MahnwesenProfileReasonProductCategory',
            'customer_category' => 'MahnwesenProfileReasonCustomerCategory',
            'customer_type' => 'MahnwesenProfileReasonCustomerType',
            'membership' => 'MahnwesenProfileReasonMembership',
            'unreadable' => 'MahnwesenProfileReasonUnreadable',
            'default' => 'MahnwesenProfileReasonDefault',
        );
        $html = '<strong>'.dol_escape_htmltag($resolution['profile']['label']).'</strong> - '.$langs->trans($reasons[$resolution['reason']]);
        if ($resolution['names']) {
            $html .= ' '.dol_escape_htmltag(implode(', ', $resolution['names']));
        }
        if (count($resolution['candidates']) > 1) {
            $profiles = $this->getProfiles();
            $labels = array();
            foreach ($resolution['candidates'] as $id) {
                $labels[] = isset($profiles[$id]) ? $profiles[$id]['label'] : '#'.$id;
            }
            $html .= '. '.$langs->trans('MahnwesenProfileSeveral').' '.dol_escape_htmltag(implode(', ', $labels)).'. '.$langs->trans('MahnwesenProfileMostCareful');
        }
        $steps = $this->getFinalSteps();
        $step = (string) $resolution['profile']['final_step'];
        if ($step !== 'none' && isset($steps[$step])) {
            $html .= '<br><span class="opacitymedium">'.$langs->trans('MahnwesenFinalStepAfter', $langs->transnoentitiesnoconv($steps[$step])).'</span>';
        }
        return $html;
    }

    /**
     * Add a profile. Its stages start as those of the default profile, without
     * fees. A preset adds its name, automatic sending, final step and customer
     * type, switched off (#32).
     *
     * @param string $label Name
     * @param User $user Acting user
     * @param string $preset Key of getProfilePresets(), or ''
     * @param string $code Code, '' for a generated one
     * @return int Profile id, 0 on error
     */
    public function createProfile($label, $user, $preset = '', $code = '')
    {
        global $conf, $langs;
        $presets = $this->getProfilePresets();
        $settings = array('auto_allowed' => 1, 'final_step' => 'none', 'customer_type' => '', 'active' => 1);
        if ($preset !== '') {
            if (!isset($presets[$preset])) {
                $this->error = 'Unknown profile preset';
                return 0;
            }
            $settings = array_merge($presets[$preset], array('active' => 0));
            $label = $langs->transnoentitiesnoconv($presets[$preset]['label']);
            $code = $preset;
        }
        $label = trim((string) $label);
        if ($label === '') {
            $this->error = $langs->trans('MahnwesenProfileLabelRequired');
            return 0;
        }
        if ($code === '') {
            $code = 'p'.substr(md5(uniqid((string) mt_rand(), true)), 0, 12);
        }
        foreach ($this->getProfiles(true) as $profile) {
            if ($profile['code'] === $code) {
                $this->error = $langs->trans('MahnwesenProfilePresetExists').' '.dol_escape_htmltag($profile['label']);
                return 0;
            }
        }
        $defaultId = $this->getDefaultProfileId($user);
        if ($defaultId <= 0 || !$this->ensureRuleRows($user, $defaultId)) {
            return 0;
        }
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $now = "'".$this->db->idate(dol_now())."'";
        $this->db->begin();
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_profile (entity, code, label, is_default, auto_allowed, final_step, active, date_creation, fk_user_create, fk_user_modif)';
        $sql .= ' VALUES ('.((int) $conf->entity).", '".$this->db->escape($code)."', '".$this->db->escape($label)."', 0, ".((int) $settings['auto_allowed']).", '".$this->db->escape($settings['final_step'])."', ".((int) $settings['active']).', '.$now.', '.$uid.', '.$uid.')';
        $ok = (bool) $this->db->query($sql);
        $profileId = $ok ? (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'mahnwesen_profile') : 0;
        if ($ok) {
            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_rule (entity, fk_profile, code, label, level, days_after_due, fee_amount, payment_days, interest_rate, send_email, email_template, enabled, date_creation, fk_user_create, fk_user_modif)';
            $sql .= ' SELECT entity, '.$profileId.', code, label, level, days_after_due, 0, payment_days, 0, send_email, email_template, enabled, '.$now.', '.$uid.', '.$uid;
            $sql .= ' FROM '.MAIN_DB_PREFIX.'mahnwesen_rule WHERE entity = '.((int) $conf->entity).' AND fk_profile = '.((int) $defaultId);
            $ok = (bool) $this->db->query($sql);
        }
        $matches = $ok ? $this->getProfileMatches(true) : false;
        if ($ok && $settings['customer_type'] !== '' && is_array($matches) && !isset($matches['customer_type'][$settings['customer_type']])) {
            $ok = $this->addProfileMatch($profileId, 'customer_type', 0, $settings['customer_type'], $uid);
        }
        if ($ok && !empty($settings['membership']) && is_array($matches) && empty($matches['membership'])) {
            $ok = $this->addProfileMatch($profileId, 'membership', 0, '', $uid);
        }
        if (!$ok) {
            $this->error = $this->error ?: $this->db->lasterror();
            $this->db->rollback();
            return 0;
        }
        $this->db->commit();
        $this->forgetProfiles();
        return $profileId;
    }

    /**
     * Save a profile: name, switched on, automatic sending, final step, and the
     * categories and customer type that lead to it. A category or a customer
     * type leads to one profile only. The default profile is always on and
     * applies when no other profile does, so it has none (#32).
     *
     * @return bool
     */
    public function saveProfile($profileId, $label, $active, $autoAllowed, $finalStep, $productCategories, $customerCategories, $customerType, $user, $interestMode = 'none', $interestRate = 0.0, $membership = 0)
    {
        global $conf, $langs;
        $profiles = $this->getProfiles(true);
        $profileId = (int) $profileId;
        if (!isset($profiles[$profileId])) {
            $this->error = 'Unknown profile';
            return false;
        }
        $label = trim((string) $label);
        if ($label === '') {
            $this->error = $langs->trans('MahnwesenProfileLabelRequired');
            return false;
        }
        $steps = $this->getFinalSteps();
        $finalStep = isset($steps[$finalStep]) ? (string) $finalStep : 'none';
        $isDefault = !empty($profiles[$profileId]['is_default']);
        $wanted = array();
        if (!$isDefault) {
            foreach (array('product_category' => $productCategories, 'customer_category' => $customerCategories) as $kind => $ids) {
                foreach (array_unique(array_map('intval', (array) $ids)) as $id) {
                    if ($id > 0) {
                        $wanted[] = array($kind, $id, '');
                    }
                }
            }
            if (in_array($customerType, array('private', 'company'), true)) {
                $wanted[] = array('customer_type', 0, $customerType);
            }
            if (!empty($membership)) {
                $wanted[] = array('membership', 0, '');
            }
        }
        $matches = $this->getProfileMatches(true);
        if ($matches === false) {
            return false;
        }
        $taken = array();
        foreach ($wanted as $match) {
            if ($match[0] === 'membership') {
                $owner = (int) $matches['membership'];
            } else {
                $owner = (int) ($matches[$match[0]][$match[0] === 'customer_type' ? $match[2] : $match[1]] ?? 0);
            }
            if ($owner > 0 && $owner !== $profileId) {
                $taken[$owner] = isset($profiles[$owner]) ? $profiles[$owner]['label'] : '#'.$owner;
            }
        }
        if ($taken) {
            $this->error = $langs->trans('MahnwesenProfileMatchTaken').' '.dol_escape_htmltag(implode(', ', $taken));
            return false;
        }
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $this->db->begin();
        $modes = $this->getInterestModes();
        $interestMode = isset($modes[$interestMode]) ? (string) $interestMode : 'none';
        $interestRate = max(0.0, (float) price2num($interestRate));
        $sql = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_profile SET label = '".$this->db->escape($label)."', active = ".($isDefault || $active ? 1 : 0);
        $sql .= ', auto_allowed = '.($autoAllowed ? 1 : 0).", final_step = '".$this->db->escape($finalStep)."'";
        $sql .= ", interest_mode = '".$this->db->escape($interestMode)."', interest_rate = ".$interestRate.', fk_user_modif = '.$uid;
        $sql .= ' WHERE rowid = '.$profileId.' AND entity = '.((int) $conf->entity);
        $ok = $this->db->query($sql) && $this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'mahnwesen_profile_match WHERE fk_profile = '.$profileId.' AND entity = '.((int) $conf->entity));
        foreach ($wanted as $match) {
            $ok = $ok && $this->addProfileMatch($profileId, $match[0], $match[1], $match[2], $uid);
        }
        if (!$ok) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return false;
        }
        $this->db->commit();
        $this->forgetProfiles();
        return true;
    }

    /** Remove a profile with its stages and assignments; never the default one. */
    public function deleteProfile($profileId)
    {
        global $conf;
        $profiles = $this->getProfiles(true);
        if (!isset($profiles[(int) $profileId]) || !empty($profiles[(int) $profileId]['is_default'])) {
            $this->error = 'The default profile cannot be removed';
            return false;
        }
        $where = ' WHERE entity = '.((int) $conf->entity);
        $this->db->begin();
        $ok = $this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'mahnwesen_profile_match'.$where.' AND fk_profile = '.((int) $profileId))
            && $this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'mahnwesen_rule'.$where.' AND fk_profile = '.((int) $profileId))
            && $this->db->query('DELETE FROM '.MAIN_DB_PREFIX.'mahnwesen_profile'.$where.' AND rowid = '.((int) $profileId));
        if (!$ok) {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return false;
        }
        $this->db->commit();
        $this->forgetProfiles();
        return true;
    }

    /** One category or customer type that leads to a profile. */
    protected function addProfileMatch($profileId, $kind, $categoryId, $customerType, $uid)
    {
        global $conf;
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_profile_match (entity, fk_profile, kind, fk_categorie, customer_type, date_creation, fk_user_create) VALUES (';
        $sql .= ((int) $conf->entity).', '.((int) $profileId).", '".$this->db->escape($kind)."', ".((int) $categoryId).", '".$this->db->escape($customerType)."', '".$this->db->idate(dol_now())."', ".((int) $uid).')';
        return (bool) $this->db->query($sql);
    }

    /** Forget what this request read about profiles and stages. */
    public function forgetProfiles()
    {
        $this->membershipCache = array();
        $this->profilesCache = null;
        $this->profileMatchesCache = null;
        $this->profileResolutionCache = array();
        $this->rulesCache = null;
    }

    /**
     * Remove the fee settings of versions before profiles. Nothing reads them
     * any more, so they go on every activation, not only on the first (#32).
     *
     * @return bool
     */
    protected function deleteLegacyFeeSettings()
    {
        global $conf;
        foreach (array('MAHNWESEN_PRIVATE_FEES_ALLOWED', 'MAHNWESEN_UNKNOWN_FEES_ALLOWED', 'MAHNWESEN_PRIVATE_FEE_1',
            'MAHNWESEN_PRIVATE_FEE_2', 'MAHNWESEN_PRIVATE_FEE_3', 'MAHNWESEN_PRIVATE_FEE_4') as $name) {
            if (getDolGlobalString($name) === '' && !isset($conf->global->$name)) {
                continue;
            }
            if (dolibarr_del_const($this->db, $name, $conf->entity) < 0) {
                $this->error = $this->db->lasterror();
                return false;
            }
        }
        return true;
    }

    /**
     * Turn the fee settings of versions before profiles into profiles, once (#32).
     *
     * Before, a stage had a fee for companies and one for private persons. The
     * private one applied only with MAHNWESEN_PRIVATE_FEES_ALLOWED, and
     * customers of unclear type paid it only with MAHNWESEN_UNKNOWN_FEES_ALLOWED.
     * The default profile takes what customers of unclear type paid; profiles
     * for companies and private persons follow where their fees differ. So
     * every customer pays what they paid before.
     *
     * @param User|null $user Acting user
     * @return bool
     */
    public function migrateProfiles($user = null)
    {
        global $conf, $langs;
        if (getDolGlobalInt('MAHNWESEN_PROFILES_MIGRATED')) {
            return $this->deleteLegacyFeeSettings();
        }
        $defaultId = $this->getDefaultProfileId($user);
        if ($defaultId <= 0 || !$this->ensureRuleRows($user, $defaultId)) {
            $this->error = $this->error ?: 'No default profile';
            return false;
        }
        // The private fee lived in MAHNWESEN_PRIVATE_FEE_<n> up to 1.1.3, then in the column fee_private.
        $describe = $this->db->DDLDescTable(MAIN_DB_PREFIX.'mahnwesen_rule', 'fee_private');
        $hasColumn = $describe && $this->db->num_rows($describe) > 0;
        $res = $this->db->query('SELECT level, fee_amount'.($hasColumn ? ', fee_private' : '').' FROM '.MAIN_DB_PREFIX.'mahnwesen_rule WHERE entity = '.((int) $conf->entity).' AND fk_profile = '.$defaultId.' AND level BETWEEN 1 AND 4');
        if (!$res) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $privateAllowed = getDolGlobalInt('MAHNWESEN_PRIVATE_FEES_ALLOWED', 0) > 0;
        $unknownAllowed = getDolGlobalInt('MAHNWESEN_UNKNOWN_FEES_ALLOWED', 0) > 0;
        $fees = array('default' => array(), 'company' => array(), 'private' => array());
        while ($o = $this->db->fetch_object($res)) {
            $level = (int) $o->level;
            $constant = getDolGlobalString('MAHNWESEN_PRIVATE_FEE_'.$level);
            $private = $constant !== '' ? max(0.0, (float) price2num($constant)) : ($hasColumn ? (float) $o->fee_private : 0.0);
            $fees['company'][$level] = (float) $o->fee_amount;
            $fees['private'][$level] = $privateAllowed ? $private : 0.0;
            $fees['default'][$level] = $unknownAllowed ? $private : 0.0;
        }
        $this->db->free($res);
        $langs->load('mahnwesen@mahnwesen');
        $this->db->begin();
        $ok = true;
        foreach ($fees['default'] as $level => $fee) {
            $ok = $ok && $this->db->query('UPDATE '.MAIN_DB_PREFIX.'mahnwesen_rule SET fee_amount = '.((float) $fee).' WHERE entity = '.((int) $conf->entity).' AND fk_profile = '.$defaultId.' AND level = '.((int) $level));
        }
        foreach (array('company' => 'MahnwesenProfileCompanies', 'private' => 'MahnwesenProfilePrivatePersons') as $type => $labelKey) {
            if (!$ok || $fees[$type] == $fees['default']) {
                continue;
            }
            $profileId = $this->createProfile($langs->transnoentitiesnoconv($labelKey), $user, '', $type);
            $ok = $profileId > 0 && $this->addProfileMatch($profileId, 'customer_type', 0, $type, (is_object($user) && isset($user->id)) ? (int) $user->id : 0);
            foreach ($fees[$type] as $level => $fee) {
                $ok = $ok && $this->db->query('UPDATE '.MAIN_DB_PREFIX.'mahnwesen_rule SET fee_amount = '.((float) $fee).' WHERE entity = '.((int) $conf->entity).' AND fk_profile = '.$profileId.' AND level = '.((int) $level));
            }
        }
        $ok = $ok && $this->deleteLegacyFeeSettings();
        $ok = $ok && dolibarr_set_const($this->db, 'MAHNWESEN_PROFILES_MIGRATED', '1', 'chaine', 0, 'Fee settings moved into dunning profiles', $conf->entity) > 0;
        if (!$ok) {
            $this->error = $this->error ?: $this->db->lasterror();
            $this->db->rollback();
            return false;
        }
        $this->db->commit();
        $this->forgetProfiles();
        // Nothing reads the column any more; a table change ends the transaction, so it comes last.
        if ($hasColumn && $this->db->DDLDropField(MAIN_DB_PREFIX.'mahnwesen_rule', 'fee_private') < 0) {
            dol_syslog(__METHOD__.' Unable to remove the column fee_private: '.$this->db->lasterror(), LOG_WARNING);
        }
        return true;
    }
}
