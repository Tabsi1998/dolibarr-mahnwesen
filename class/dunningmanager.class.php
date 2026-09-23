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
require_once __DIR__.'/mahnweseninterestpolicy.class.php';
require_once __DIR__.'/dunningmanager.access.trait.php';
require_once __DIR__.'/dunningmanager.scan.trait.php';
require_once __DIR__.'/dunningmanager.stages.trait.php';
require_once __DIR__.'/dunningmanager.profiles.trait.php';
require_once __DIR__.'/dunningmanager.interest.trait.php';
require_once __DIR__.'/dunningmanager.workflow.trait.php';
require_once __DIR__.'/dunningmanager.cases.trait.php';
require_once __DIR__.'/dunningmanager.attempts.trait.php';
require_once __DIR__.'/dunningmanager.fees.trait.php';
require_once __DIR__.'/dunningmanager.history.trait.php';
require_once __DIR__.'/dunningmanager.events.trait.php';
require_once __DIR__.'/dunningmanager.handover.trait.php';
require_once __DIR__.'/dunningmanager.bulk.trait.php';
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
    use DunningManagerAccess, DunningManagerScan, DunningManagerStages, DunningManagerProfiles, DunningManagerInterest, DunningManagerWorkflow, DunningManagerCases, DunningManagerAttempts, DunningManagerFees, DunningManagerHistory, DunningManagerEvents, DunningManagerHandover, DunningManagerBulk, DunningManagerAutomation;

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

    /** @var array<int,array>|null Stage rules by profile */
    protected $rulesCache = null;

    /** @var array<int,array>|null Profiles of the active entity */
    protected $profilesCache = null;

    /** @var array|null Categories and customer types that lead to profiles */
    protected $profileMatchesCache = null;

    /** @var array<int,array> Profile of each invoice, for one request */
    protected $profileResolutionCache = array();

    /** @var array<int,array> Dolibarr's categories by type, for one request */
    protected $categoryTreeCache = array();

    /** @var array<int,array>|null Base rates for the interest, newest first */
    protected $interestRatesCache = null;

    /** @var array<int,array|null> Membership of each invoice, for one request */
    protected $membershipCache = array();

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
