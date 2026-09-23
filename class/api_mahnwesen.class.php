<?php
/*
 * Mahnwesen - Dolibarr custom module
 * Copyright (C) 2026 Module contributors
 * GPL-3.0-or-later
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once dol_buildpath('/mahnwesen/class/dunningmanager.class.php', 0);

/**
 * Read the dunning status (#57).
 *
 * Two views, kept apart on purpose:
 *
 * - the operations view, for internal users with the right "Read the dunning
 *   status of the whole entity through the API": automation state and the last
 *   run. A customer portal never needs this.
 * - the customer view, for a technical user with the right "Read the dunning
 *   status of its own customers through the API". Which customers those are is
 *   proven the native Dolibarr way: the technical user is the sales
 *   representative of exactly those third parties. A customer id from the
 *   request is checked against that, never trusted by itself.
 *
 * Every call is read-only: nothing is sent, no stage moves, no fee changes.
 * Amounts are numbers with two decimals in the entity's currency.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Mahnwesen extends DolibarrApi
{
    /** @var DunningManager */
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
     * Dunning status of one customer invoice
     *
     * Returns what is open on that invoice, its dunning stage and what comes
     * next, as far as the caller may see it.
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
        $this->checkCustomerAccess((int) $invoice->socid);
        $case = $this->manager->getCaseByInvoice((int) $invoice->id);
        if (!$case) {
            throw new RestException(404, 'Not found');
        }
        return $this->caseData($case, $invoice);
    }

    /**
     * Dunning status of the invoices of one customer
     *
     * @param  int $id Id of the third party
     * @param  int $page Page, starting at 0
     * @param  int $limit Rows per page, at most 100
     * @return array Cases of that customer
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
        $this->checkCustomerAccess($socid);
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
            $cases[] = $this->caseData($case, $invoice);
        }
        $this->db->free($resql);
        return array('page' => max(0, (int) $page), 'limit' => $limit, 'cases' => $cases);
    }

    /**
     * State of the dunning automation of this entity
     *
     * Only for internal users with the operations right; a customer portal
     * neither needs nor reaches it.
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
        if (!$this->mayReadOperations($user)) {
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
            'open_cases' => $this->countCases("status = 'open'"),
            'cases_with_open_claims' => $this->countCases("status = 'fee_open'"),
            'events_waiting' => $this->manager->countPendingEvents(),
            'last_run' => $run,
        );
    }

    /**
     * The dunning letters really sent for one invoice
     *
     * Only letters of a delivery that went out, or that was confirmed as gone
     * out after a controlled clarification. Drafts, previews and unclear
     * attempts are not in this list. The answer names the archived evidence,
     * not the current state of anything.
     *
     * @param  int $id Id of the customer invoice
     * @param  int $page Page, starting at 0
     * @param  int $limit Rows per page, at most 100
     * @return array The sent dunning letters of that invoice
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
        $this->checkCustomerAccess((int) $invoice->socid);
        $limit = max(1, min(100, (int) $limit));
        $offset = max(0, (int) $page) * $limit;
        $sql = 'SELECT f.rowid, f.display_name, f.sha256, f.mime_type, f.size_bytes, f.date_creation, a.rowid as attempt_id, a.level, a.sent_at';
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
     * One archived dunning letter, exactly as it went out
     *
     * The answer holds the bytes of the archive, base64 encoded, with the hash
     * that was recorded when it was sent. Nothing is generated again, so a
     * changed amount or name never reaches an old letter.
     *
     * @param  int $id Id of the document, from the list
     * @return array The archived letter
     *
     * @url GET documents/{id}
     *
     * @throws RestException 401 Not allowed
     * @throws RestException 404 Not found
     * @throws RestException 410 The archived file is gone
     * @throws RestException 413 The archived file is too large for the API
     */
    public function getDocument($id)
    {
        global $conf;
        $sql = 'SELECT f.rowid, f.display_name, f.snapshot_path, f.sha256, f.mime_type, f.size_bytes, a.rowid as attempt_id, a.level, a.sent_at, a.fk_facture';
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
            // A draft, an unclear attempt, another entity or nothing at all: the same answer.
            throw new RestException(404, 'Not found');
        }
        $invoice = new Facture($this->db);
        if ($invoice->fetch((int) $row->fk_facture) <= 0) {
            throw new RestException(404, 'Not found');
        }
        $this->checkCustomerAccess((int) $invoice->socid);
        $path = (string) $row->snapshot_path;
        if ($path === '' || !is_readable($path) || !is_file($path)) {
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
     * What one case tells the outside: references, states and amounts (#57).
     *
     * Internal notes, email texts, recipients, attachment paths and anything
     * of another customer stay out.
     *
     * @param array $case Stored case
     * @param Facture $invoice Its invoice
     * @return array
     */
    protected function caseData($case, $invoice)
    {
        global $conf;
        $claims = array('fee_open' => 0.0, 'interest_open' => 0.0, 'on_invoice' => 0.0);
        foreach ($this->manager->getOpenClaims((int) $case['id']) as $claim) {
            $claims[$claim['kind'] === 'interest' ? 'interest_open' : 'fee_open'] += (float) $claim['amount'];
        }
        // What went onto an invoice of its own is not counted here again (#34).
        $claims['on_invoice'] = round($this->manager->getSettledClaimAmount((int) $case['id'], 'fee')
            + $this->manager->getSettledClaimAmount((int) $case['id'], 'interest'), 2);
        $resolution = $this->manager->resolveProfile((int) $invoice->id);
        $remain = $invoice->getRemainToPay(0);
        return array(
            'invoice_id' => (int) $invoice->id,
            'invoice_ref' => (string) $invoice->ref,
            'thirdparty_id' => (int) $invoice->socid,
            'entity' => (int) $case['entity'],
            'case_id' => (int) $case['id'],
            'case_revision' => (int) ($case['revision'] ?? 0),
            'status' => (string) $case['status'],
            'paused' => !empty($case['paused']) ? 1 : 0,
            'stage' => (int) $case['current_level'],
            'profile_code' => (string) $resolution['profile']['code'],
            'currency' => (string) $conf->currency,
            'invoice_open' => round(is_numeric($remain) ? (float) $remain : 0.0, 2),
            'fee_open' => round($claims['fee_open'], 2),
            'interest_open' => round($claims['interest_open'], 2),
            'claims_on_own_invoice' => $claims['on_invoice'],
            'due_date' => $invoice->date_lim_reglement ? dol_print_date($invoice->date_lim_reglement, '%Y-%m-%d', 'tzserver') : '',
            'next_action_at' => !empty($case['next_action_at']) ? dol_print_date($this->db->jdate($case['next_action_at']), '%Y-%m-%d', 'tzserver') : '',
            'changed_at' => !empty($case['tms']) ? dol_print_date($this->db->jdate($case['tms']), '%Y-%m-%d %H:%M:%S', 'tzserver') : '',
        );
    }

    /**
     * The trust boundary of the customer view (#57).
     *
     * The caller reaches a customer when it may read the whole entity
     * (operations) or when Dolibarr itself ties it to that customer as its
     * sales representative. A customer id alone proves nothing.
     *
     * @param int $socid Third party
     * @return void
     * @throws RestException 401 Not allowed
     * @throws RestException 404 Not found
     */
    protected function checkCustomerAccess($socid)
    {
        $user = DolibarrApiAccess::$user;
        if ($socid <= 0) {
            throw new RestException(404, 'Not found');
        }
        if ($this->mayReadOperations($user)) {
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

    /** Whether a user may read the dunning state of the whole entity. */
    protected function mayReadOperations($user)
    {
        return is_object($user) && method_exists($user, 'hasRight') && $user->hasRight('mahnwesen', 'api', 'operations');
    }

    /** @return int Cases of the entity in one state */
    protected function countCases($where)
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
