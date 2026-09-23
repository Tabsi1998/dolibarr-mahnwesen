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
