<?php
/*
 * Mahnwesen - Dolibarr custom module
 * Copyright (C) 2026 Module contributors
 * GPL-3.0-or-later
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);

/*
 * Two views, kept apart on purpose (#57, #69). The operations view, for
 * internal users with the right api/operations, sees the automation and the
 * last run of the whole entity. The customer view, for a technical user with
 * the right api/customer, sees only the customers that user is the sales
 * representative of in Dolibarr; that assignment is the proof, a customer id
 * from the request is checked against it and never trusted by itself.
 *
 * Every call is read-only, amounts are numbers with two decimals in the
 * entity's currency, and docs/API.md holds the whole contract.
 *
 * The comments of the endpoints stay short on purpose, and every helper starts
 * with an underscore: Dolibarr's REST framework parses the comments of every
 * other method and runs out of memory on a longer one.
 */

/**
 * API class for the dunning status
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Mahnwesen extends DolibarrApi
{
    /**
     * @var DunningManager {@type DunningManager}
     */
    private $manager;

    /**
     * Constructor
     */
    public function __construct()
    {
        global $db;
        $this->db = $db;
        $this->manager = new DunningManager($db);
    }

    /**
     * Get the dunning status of a customer invoice
     *
     * @param  int $id Id of the customer invoice
     * @return array Dunning status of the invoice
     *
     * @url GET invoices/{id}
     *
     * @throws RestException 401 Not allowed
     * @throws RestException 404 Not found
     */
    public function getInvoice($id)
    {
        $invoice = new Facture($this->db);
        if ($invoice->fetch((int) $id) <= 0) {
            // An invoice of another entity or none at all reads the same.
            throw new RestException(404, 'Not found');
        }
        $this->_checkCustomerAccess((int) $invoice->socid);
        $case = $this->manager->getCaseByInvoice((int) $invoice->id);
        if (!$case) {
            throw new RestException(404, 'Not found');
        }
        return $this->_caseData($case, $invoice);
    }

    /**
     * Get the dunning status of the invoices of a customer
     *
     * @param  int $id Id of the third party
     * @param  int $page Page number, starting at 0
     * @param  int $limit Rows per page, at most 100
     * @return array Dunning cases of that customer
     *
     * @url GET thirdparties/{id}
     *
     * @throws RestException 401 Not allowed
     * @throws RestException 404 Not found
     */
    public function getThirdparty($id, $page = 0, $limit = 25)
    {
        global $conf;
        $socid = (int) $id;
        $this->_checkCustomerAccess($socid);
        $limit = max(1, min(100, (int) $limit));
        $offset = max(0, (int) $page) * $limit;
        $sql = 'SELECT c.rowid FROM '.MAIN_DB_PREFIX.'mahnwesen_case as c';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'facture as f ON f.rowid = c.fk_facture';
        $sql .= ' WHERE c.entity = '.((int) $conf->entity).' AND f.fk_soc = '.$socid;
        $sql .= ' ORDER BY c.rowid DESC'.$this->db->plimit($limit, $offset);
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RestException(500, 'Unable to read the dunning cases');
        }
        $cases = array();
        while ($row = $this->db->fetch_object($resql)) {
            $case = $this->manager->getCase((int) $row->rowid);
            if (!$case) {
                continue;
            }
            $invoice = new Facture($this->db);
            if ($invoice->fetch((int) $case['invoice_id']) <= 0) {
                continue;
            }
            $cases[] = $this->_caseData($case, $invoice);
        }
        $this->db->free($resql);
        return array('page' => max(0, (int) $page), 'limit' => $limit, 'cases' => $cases);
    }

    /**
     * Get the state of the dunning automation of this entity
     *
     * @return array State of the automation and of the last run
     *
     * @url GET status
     *
     * @throws RestException 401 Not allowed
     */
    public function getStatus()
    {
        global $conf;
        $user = DolibarrApiAccess::$user;
        if (!$this->_mayReadOperations($user)) {
            throw new RestException(401, 'Not allowed');
        }
        $run = array();
        $sql = 'SELECT rowid, mode, status, date_start, date_end, scanned, sent, skipped, failed FROM '.MAIN_DB_PREFIX.'mahnwesen_run';
        $sql .= ' WHERE entity = '.((int) $conf->entity)." AND mode = 'cron' ORDER BY rowid DESC".$this->db->plimit(1);
        $resql = $this->db->query($sql);
        if ($resql && ($row = $this->db->fetch_object($resql))) {
            $run = array('id' => (int) $row->rowid, 'status' => (string) $row->status, 'started_at' => (string) $row->date_start,
                'ended_at' => (string) $row->date_end, 'scanned' => (int) $row->scanned, 'sent' => (int) $row->sent,
                'skipped' => (int) $row->skipped, 'failed' => (int) $row->failed);
        }
        if ($resql) {
            $this->db->free($resql);
        }
        return array(
            'entity' => (int) $conf->entity,
            'currency' => (string) $conf->currency,
            'automatic_sending' => getDolGlobalInt('MAHNWESEN_AUTO_SEND_ENABLED', 0) ? 1 : 0,
            'manual_sending' => getDolGlobalInt('MAHNWESEN_MANUAL_SEND_ENABLED', 0) ? 1 : 0,
            'open_cases' => $this->_countCases("status = 'open'"),
            'cases_with_open_claims' => $this->_countCases("status = 'fee_open'"),
            'events_waiting' => $this->manager->countPendingEvents(),
            'last_run' => $run,
        );
    }

    /**
     * Get the dunning letters really sent for an invoice
     *
     * @param  int $id Id of the customer invoice
     * @param  int $page Page number, starting at 0
     * @param  int $limit Rows per page, at most 100
     * @return array Archived dunning letters of that invoice
     *
     * @url GET invoices/{id}/documents
     *
     * @throws RestException 401 Not allowed
     * @throws RestException 404 Not found
     */
    public function getInvoiceDocuments($id, $page = 0, $limit = 25)
    {
        global $conf;
        $invoice = new Facture($this->db);
        if ($invoice->fetch((int) $id) <= 0) {
            throw new RestException(404, 'Not found');
        }
        $this->_checkCustomerAccess((int) $invoice->socid);
        $limit = max(1, min(100, (int) $limit));
        $offset = max(0, (int) $page) * $limit;
        // Only deliveries that went out; drafts, previews and unclear attempts stay out (#69).
        $sql = 'SELECT f.rowid, f.display_name, f.sha256, f.mime_type, f.size_bytes, a.rowid as attempt_id, a.level, a.sent_at';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt_file as f';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'mahnwesen_attempt as a ON a.rowid = f.fk_attempt';
        $sql .= ' WHERE f.entity = '.((int) $conf->entity).' AND a.fk_facture = '.((int) $invoice->id);
        $sql .= " AND f.file_role = 'dunning' AND a.status = 'sent'";
        $sql .= ' ORDER BY a.rowid DESC, f.rowid DESC'.$this->db->plimit($limit, $offset);
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RestException(500, 'Unable to read the sent dunning letters');
        }
        $documents = array();
        while ($row = $this->db->fetch_object($resql)) {
            $documents[] = array(
                'document_id' => (int) $row->rowid,
                'attempt_id' => (int) $row->attempt_id,
                'invoice_id' => (int) $invoice->id,
                'invoice_ref' => (string) $invoice->ref,
                'stage' => (int) $row->level,
                'sent_at' => $row->sent_at ? dol_print_date($this->db->jdate($row->sent_at), '%Y-%m-%d %H:%M:%S', 'tzserver') : '',
                'filename' => (string) $row->display_name,
                'mime_type' => (string) $row->mime_type,
                'size_bytes' => (int) $row->size_bytes,
                'sha256' => (string) $row->sha256,
            );
        }
        $this->db->free($resql);
        return array('page' => max(0, (int) $page), 'limit' => $limit, 'documents' => $documents);
    }

    /**
     * Get one archived dunning letter, exactly as it went out
     *
     * @param  int $id Id of the document from the list
     * @return array The archived letter, base64 encoded
     *
     * @url GET documents/{id}
     *
     * @throws RestException 401 Not allowed
     * @throws RestException 404 Not found
     * @throws RestException 410 Gone
     * @throws RestException 413 Too large
     */
    public function getDocument($id)
    {
        global $conf;
        $sql = 'SELECT f.rowid, f.display_name, f.snapshot_path, f.sha256, f.mime_type, a.rowid as attempt_id, a.level, a.sent_at, a.fk_facture';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'mahnwesen_attempt_file as f';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'mahnwesen_attempt as a ON a.rowid = f.fk_attempt';
        $sql .= ' WHERE f.entity = '.((int) $conf->entity).' AND f.rowid = '.((int) $id);
        $sql .= " AND f.file_role = 'dunning' AND a.status = 'sent'".$this->db->plimit(1);
        $resql = $this->db->query($sql);
        $row = $resql ? $this->db->fetch_object($resql) : false;
        if ($resql) {
            $this->db->free($resql);
        }
        if (!$row) {
            // A draft, an unclear attempt, another entity or nothing: the same answer.
            throw new RestException(404, 'Not found');
        }
        $invoice = new Facture($this->db);
        if ($invoice->fetch((int) $row->fk_facture) <= 0) {
            throw new RestException(404, 'Not found');
        }
        $this->_checkCustomerAccess((int) $invoice->socid);
        $path = (string) $row->snapshot_path;
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            // The archive is gone; an original is never invented (#69).
            throw new RestException(410, 'The archived document is not available any more');
        }
        $maxBytes = max(1, getDolGlobalInt('MAHNWESEN_API_MAX_DOCUMENT_MB', 20)) * 1024 * 1024;
        if (filesize($path) > $maxBytes) {
            throw new RestException(413, 'The archived document is too large for the API');
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RestException(410, 'The archived document is not available any more');
        }
        return array(
            'document_id' => (int) $row->rowid,
            'attempt_id' => (int) $row->attempt_id,
            'invoice_id' => (int) $invoice->id,
            'invoice_ref' => (string) $invoice->ref,
            'stage' => (int) $row->level,
            'sent_at' => $row->sent_at ? dol_print_date($this->db->jdate($row->sent_at), '%Y-%m-%d %H:%M:%S', 'tzserver') : '',
            'filename' => dol_sanitizeFileName((string) $row->display_name),
            'mime_type' => (string) $row->mime_type,
            'size_bytes' => (int) strlen($content),
            'sha256' => (string) $row->sha256,
            'sha256_now' => hash('sha256', $content),
            'encoding' => 'base64',
            'content' => base64_encode($content),
        );
    }

    /**
     * Build what one case tells the outside
     *
     * @param  array $case Stored case
     * @param  Facture $invoice Its invoice
     * @return array Contract fields of that case
     */
    protected function _caseData($case, $invoice)
    {
        global $conf;
        // Internal notes, email texts, recipients and attachment paths stay out (#57).
        $fees = 0.0;
        $interest = 0.0;
        foreach ($this->manager->getOpenClaims((int) $case['id']) as $claim) {
            if ($claim['kind'] === 'interest') {
                $interest += (float) $claim['amount'];
            } else {
                $fees += (float) $claim['amount'];
            }
        }
        // What went onto an invoice of its own is not counted here again (#34).
        $onOwnInvoice = round($this->manager->getSettledClaimAmount((int) $case['id'], 'fee')
            + $this->manager->getSettledClaimAmount((int) $case['id'], 'interest'), 2);
        $resolution = $this->manager->resolveProfile((int) $invoice->id);
        $remain = $invoice->getRemainToPay(0);
        return array(
            'invoice_id' => (int) $invoice->id,
            'invoice_ref' => (string) $invoice->ref,
            'thirdparty_id' => (int) $invoice->socid,
            'entity' => (int) $case['entity'],
            'case_id' => (int) $case['id'],
            'case_revision' => (int) (isset($case['revision']) ? $case['revision'] : 0),
            'status' => (string) $case['status'],
            'paused' => !empty($case['paused']) ? 1 : 0,
            'stage' => (int) $case['current_level'],
            'profile_code' => (string) $resolution['profile']['code'],
            'currency' => (string) $conf->currency,
            'invoice_open' => round(is_numeric($remain) ? (float) $remain : 0.0, 2),
            'fee_open' => round($fees, 2),
            'interest_open' => round($interest, 2),
            'claims_on_own_invoice' => $onOwnInvoice,
            'due_date' => $invoice->date_lim_reglement ? dol_print_date($invoice->date_lim_reglement, '%Y-%m-%d', 'tzserver') : '',
            'next_action_at' => !empty($case['next_action_at']) ? dol_print_date($this->db->jdate($case['next_action_at']), '%Y-%m-%d', 'tzserver') : '',
            'changed_at' => !empty($case['tms']) ? dol_print_date($this->db->jdate($case['tms']), '%Y-%m-%d %H:%M:%S', 'tzserver') : '',
        );
    }

    /**
     * Check that the caller may see a customer
     *
     * @param  int $socid Id of the third party
     * @return void
     *
     * @throws RestException 401 Not allowed
     * @throws RestException 404 Not found
     */
    protected function _checkCustomerAccess($socid)
    {
        $user = DolibarrApiAccess::$user;
        if ($socid <= 0) {
            throw new RestException(404, 'Not found');
        }
        if ($this->_mayReadOperations($user)) {
            return;
        }
        if (!is_object($user) || !method_exists($user, 'hasRight') || !$user->hasRight('mahnwesen', 'api', 'customer')) {
            throw new RestException(401, 'Not allowed');
        }
        if (!$this->manager->canSeeCustomer($user, $socid)) {
            // Nothing tells whether that customer exists at all.
            throw new RestException(404, 'Not found');
        }
    }

    /**
     * Check whether a user may read the whole entity
     *
     * @param  User $user User of the call
     * @return bool True when the operations right is set
     */
    protected function _mayReadOperations($user)
    {
        return is_object($user) && method_exists($user, 'hasRight') && $user->hasRight('mahnwesen', 'api', 'operations');
    }

    /**
     * Count the cases of the entity in one state
     *
     * @param  string $where Condition on the case table
     * @return int Number of cases
     */
    protected function _countCases($where)
    {
        global $conf;
        $resql = $this->db->query('SELECT COUNT(*) as total FROM '.MAIN_DB_PREFIX.'mahnwesen_case WHERE entity = '.((int) $conf->entity).' AND '.$where);
        $row = $resql ? $this->db->fetch_object($resql) : false;
        if ($resql) {
            $this->db->free($resql);
        }
        return $row ? (int) $row->total : 0;
    }
}
