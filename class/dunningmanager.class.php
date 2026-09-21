<?php
/*
 * Mahnwesen - Dolibarr custom module
 * Copyright (C) 2026 Module contributors
 * GPL-3.0-or-later
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

require_once __DIR__.'/mahnwesenworkflowpolicy.class.php';
require_once __DIR__.'/dunningmanager.access.trait.php';
require_once __DIR__.'/dunningmanager.scan.trait.php';
require_once __DIR__.'/dunningmanager.stages.trait.php';
require_once __DIR__.'/dunningmanager.workflow.trait.php';
require_once __DIR__.'/dunningmanager.cases.trait.php';
require_once __DIR__.'/dunningmanager.attempts.trait.php';
require_once __DIR__.'/dunningmanager.fees.trait.php';
require_once __DIR__.'/dunningmanager.history.trait.php';
require_once __DIR__.'/dunningmanager.automation.trait.php';

/**
 * Read-only dunning scanner for customer invoices.
 *
 * Keeps its own dunning cases, history, delivery attempts and fees; the
 * responsibilities live in the traits named after them. Invoices themselves
 * remain untouched.
 */
class DunningManager
{
    use DunningManagerAccess, DunningManagerScan, DunningManagerStages, DunningManagerWorkflow, DunningManagerCases, DunningManagerAttempts, DunningManagerFees, DunningManagerHistory, DunningManagerAutomation;

    /** @var DoliDB */
    public $db;

    /** @var string */
    public $error = '';

    /** @var array */
    public $errors = array();

    /** @var string Cron output */
    public $output = '';

    /** @var array Diagnostic counters from last scan */
    public $diagnostics = array();

    /** @var array|null Cached stage rules */
    protected $rulesCache = null;

    /** @var array<int,array<int,bool>> Completed-stage cache for one request. */
    protected $completedLevelsCache = array();

    /**
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }
}
