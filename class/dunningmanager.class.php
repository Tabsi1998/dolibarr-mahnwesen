<?php
/*
 * Mahnwesen - Dolibarr custom module
 * Copyright (C) 2026 Module contributors
 * GPL-3.0-or-later
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

require_once __DIR__.'/dunningmanager.methods1.trait.php';
require_once __DIR__.'/dunningmanager.methods2.trait.php';
require_once __DIR__.'/dunningmanager.methods3.trait.php';
require_once __DIR__.'/dunningmanager.methods4.trait.php';
require_once __DIR__.'/dunningmanager.methods5.trait.php';
require_once __DIR__.'/dunningmanager.methods6.trait.php';

/**
 * Read-only dunning scanner for customer invoices.
 *
 * v0.4 persists its own dunning cases/history, supports timed pauses, configurable fees and optional controlled automation. Invoices themselves remain untouched.
 */
class DunningManager
{
    use DunningManagerMethods1, DunningManagerMethods2, DunningManagerMethods3, DunningManagerMethods4, DunningManagerMethods5, DunningManagerMethods6;
}
