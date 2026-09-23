<?php
/* Integration events: what changed on a dunning case, recorded with the change and delivered after it (#59). */
trait DunningManagerEvents
{
    /** The version of the contract every event carries. */
    public function getEventContractVersion()
    {
        return '1';
    }

    /**
     * The event types the module publishes, and the history actions they follow.
     *
     * @return array<string,string> History action => event type
     */
    public function getEventTypes()
    {
        return array(
            'notice_sent' => 'MAHNWESEN_NOTICE_SENT',
            'case_closed' => 'MAHNWESEN_CASE_CLOSED',
            'invoice_paid_fee_open' => 'MAHNWESEN_CASE_CLOSED',
            'case_reopened' => 'MAHNWESEN_CASE_REOPENED',
            'paused' => 'MAHNWESEN_CASE_PAUSED',
            'resumed' => 'MAHNWESEN_CASE_RESUMED',
            'auto_resumed' => 'MAHNWESEN_CASE_RESUMED',
        );
    }

    /**
     * Note one change of a dunning case, in the caller's transaction (#59).
     *
     * The note is written with the change itself, so there is no event without
     * the change and no change without its event. Delivery happens afterwards,
     * from dispatchEvents(). The note holds references and states only: no
     * email text, no recipient, no bank data, no internal reason.
     *
     * @param int $entity Entity
     * @param int $caseId Case
     * @param int $invoiceId Invoice
     * @param string $type Event type
     * @param int $level Dunning stage the change belongs to
     * @param User|null $user Acting user
     * @return bool
     */
    public function recordEvent($entity, $caseId, $invoiceId, $type, $level, $user = null)
    {
        if ((int) $caseId <= 0 || (string) $type === '') {
            return true;
        }
        $revision = $this->bumpCaseRevision((int) $caseId);
        if ($revision === false) {
            return false;
        }
        $profileCode = '';
        if ((int) $invoiceId > 0) {
            $resolution = $this->resolveProfile((int) $invoiceId);
            $profileCode = (string) $resolution['profile']['code'];
        }
        $uid = (is_object($user) && isset($user->id)) ? (int) $user->id : 0;
        $nowSql = $this->db->idate(dol_now());
        // One id per business transition: a receiver can recognise a repeated delivery.
        $eventId = 'mw-'.((int) $entity).'-'.((int) $caseId).'-'.((int) $revision).'-'.strtolower(str_replace('MAHNWESEN_', '', (string) $type));
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mahnwesen_event (entity, event_id, event_type, fk_case, fk_facture, level, profile_code, case_revision, contract_version, status, attempts, occurred_at, date_creation, fk_user_create) VALUES (';
        $sql .= ((int) $entity).", '".$this->db->escape($eventId)."', '".$this->db->escape((string) $type)."', ".((int) $caseId).', '.((int) $invoiceId).', '.((int) $level);
        $sql .= ", '".$this->db->escape($profileCode)."', ".((int) $revision).", '".$this->db->escape($this->getEventContractVersion())."', 'pending', 0, '".$this->db->escape($nowSql)."', '".$this->db->escape($nowSql)."', ".$uid.')';
        if (!$this->db->query($sql)) {
            // The same transition twice is no second event.
            if ($this->db->errno() === 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                return true;
            }
            $this->error = $this->db->lasterror();
            return false;
        }
        return true;
    }

    /**
     * Count one more change of a case and return the new revision.
     *
     * @param int $caseId Case
     * @return int|false
     */
    protected function bumpCaseRevision($caseId)
    {
        if (!$this->db->query('UPDATE '.MAIN_DB_PREFIX.'mahnwesen_case SET revision = revision + 1 WHERE rowid = '.((int) $caseId))) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $res = $this->db->query('SELECT revision FROM '.MAIN_DB_PREFIX.'mahnwesen_case WHERE rowid = '.((int) $caseId));
        $obj = $res ? $this->db->fetch_object($res) : false;
        if ($res) {
            $this->db->free($res);
        }
        if (!$obj) {
            $this->error = $this->error ?: 'Unable to read the revision of case '.((int) $caseId);
            return false;
        }
        return (int) $obj->revision;
    }

    /**
     * Deliver the noted events through Dolibarr's own trigger mechanism (#59).
     *
     * Only after the change itself is committed. A receiver that fails leaves
     * the event in the backlog with its attempt counted; nothing is sent twice
     * because of that, and no delivery ever rolls back a change that happened.
     *
     * @param User|null $user Acting user
     * @param int $limit How many at most
     * @return int|false Number of events delivered
     */
    public function dispatchEvents($user = null, $limit = 50)
    {
        global $conf, $langs;
        $max = max(1, min(500, (int) $limit));
        $retries = max(1, getDolGlobalInt('MAHNWESEN_EVENT_RETRY_MAX', 5));
        $sql = 'SELECT rowid, event_id, event_type, fk_case, fk_facture, level, profile_code, case_revision, contract_version, occurred_at, attempts';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'mahnwesen_event WHERE entity = '.((int) $conf->entity)." AND status = 'pending' AND attempts < ".$retries;
        $sql .= ' ORDER BY rowid ASC'.$this->db->plimit($max);
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            return false;
        }
        $events = array();
        while ($o = $this->db->fetch_object($res)) {
            $events[] = (array) $o;
        }
        $this->db->free($res);
        if (empty($events)) {
            return 0;
        }
        require_once DOL_DOCUMENT_ROOT.'/core/class/interfaces.class.php';
        $interfaces = new Interfaces($this->db);
        $actor = is_object($user) ? $user : (isset($GLOBALS['user']) && is_object($GLOBALS['user']) ? $GLOBALS['user'] : new User($this->db));
        $delivered = 0;
        foreach ($events as $event) {
            $carrier = $this->eventObject($event);
            $nowSql = $this->db->escape($this->db->idate(dol_now()));
            $result = $interfaces->run_triggers((string) $event['event_type'], $carrier, $actor, $langs, $conf);
            if ($result < 0) {
                $message = implode(' | ', (array) $interfaces->errors);
                $sql = 'UPDATE '.MAIN_DB_PREFIX.'mahnwesen_event SET attempts = attempts + 1, last_error = \''.$this->db->escape(dol_trunc($message, 250)).'\'';
                $sql .= ' WHERE rowid = '.((int) $event['rowid']);
                $this->db->query($sql);
                $this->errors[] = 'Event '.$event['event_id'].' was refused by a receiver: '.$message;
                continue;
            }
            $sql = 'UPDATE '.MAIN_DB_PREFIX."mahnwesen_event SET status = 'delivered', attempts = attempts + 1, delivered_at = '".$nowSql."', last_error = ''";
            $sql .= ' WHERE rowid = '.((int) $event['rowid'])." AND status = 'pending'";
            if (!$this->db->query($sql)) {
                $this->errors[] = 'Unable to record the delivery of event '.$event['event_id'].': '.$this->db->lasterror();
                continue;
            }
            $delivered++;
        }
        return $delivered;
    }

    /**
     * The object a receiver gets: the contract, and nothing else (#59).
     *
     * @param array $event Stored event
     * @return stdClass
     */
    protected function eventObject($event)
    {
        $carrier = new stdClass();
        $carrier->element = 'mahnwesen_event';
        $carrier->id = (int) $event['fk_case'];
        $carrier->entity = isset($event['entity']) ? (int) $event['entity'] : 0;
        $carrier->event_id = (string) $event['event_id'];
        $carrier->event_type = (string) $event['event_type'];
        $carrier->contract_version = (string) $event['contract_version'];
        $carrier->case_id = (int) $event['fk_case'];
        $carrier->invoice_id = (int) $event['fk_facture'];
        $carrier->level = (int) $event['level'];
        $carrier->profile_code = (string) $event['profile_code'];
        $carrier->case_revision = (int) $event['case_revision'];
        $carrier->occurred_at = (string) $event['occurred_at'];
        return $carrier;
    }

    /**
     * The events of the active entity, newest first, for the backlog on the page.
     *
     * @param int $limit How many
     * @return array<int,array>
     */
    public function getEvents($limit = 50)
    {
        global $conf;
        $rows = array();
        $sql = 'SELECT rowid, event_id, event_type, fk_case, fk_facture, level, profile_code, case_revision, status, attempts, last_error, occurred_at, delivered_at';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'mahnwesen_event WHERE entity = '.((int) $conf->entity).' ORDER BY rowid DESC'.$this->db->plimit(max(1, min(500, (int) $limit)));
        $res = $this->db->query($sql);
        if (!$res) {
            $this->error = $this->db->lasterror();
            return $rows;
        }
        while ($o = $this->db->fetch_object($res)) {
            $rows[] = (array) $o;
        }
        $this->db->free($res);
        return $rows;
    }

    /** How many events still wait for delivery. */
    public function countPendingEvents()
    {
        global $conf;
        $res = $this->db->query('SELECT COUNT(*) as pending FROM '.MAIN_DB_PREFIX.'mahnwesen_event WHERE entity = '.((int) $conf->entity)." AND status = 'pending'");
        $obj = $res ? $this->db->fetch_object($res) : false;
        if ($res) {
            $this->db->free($res);
        }
        return $obj ? (int) $obj->pending : 0;
    }
}
